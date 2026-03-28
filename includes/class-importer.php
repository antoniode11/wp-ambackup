<?php
/**
 * Importación de backups (restauración).
 *
 * @package WP_AMBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAMB_Importer {

	/**
	 * Handler AJAX para importar/subir un backup.
	 */
	public function import_ajax() {
		if ( ! isset( $_FILES['backup_file'] ) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( array( 'message' => __( 'Error al subir el archivo.', 'wp-ambackup' ) ) );
		}

		$file    = $_FILES['backup_file'];
		$tmpfile = $file['tmp_name'];
		$name    = sanitize_file_name( $file['name'] );

		// Validar extensión
		if ( ! preg_match( '/\.zip$/i', $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Solo se aceptan archivos .zip', 'wp-ambackup' ) ) );
		}

		// Mover a directorio de backups
		$dest = WPAMB_BACKUP_DIR . $name;

		// Evitar colisiones de nombre
		if ( file_exists( $dest ) ) {
			$name = pathinfo( $name, PATHINFO_FILENAME ) . '_' . time() . '.zip';
			$dest = WPAMB_BACKUP_DIR . $name;
		}

		if ( ! move_uploaded_file( $tmpfile, $dest ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo mover el archivo al directorio de backups.', 'wp-ambackup' ) ) );
		}

		// Leer manifiesto para validar que es un backup de WP AMBackup
		$manifest = $this->read_manifest( $dest );

		$mode = sanitize_text_field( $_POST['import_mode'] ?? 'register' );

		if ( 'restore' === $mode ) {
			$result = $this->restore( $dest, $manifest );
			if ( is_wp_error( $result ) ) {
				@unlink( $dest );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success( array(
				'message'  => __( 'Restauración completada. Por favor revisa tu sitio y elimina el archivo de backup si ya no lo necesitas.', 'wp-ambackup' ),
				'filename' => $name,
				'manifest' => $manifest,
			) );
		} else {
			// Solo registrar el archivo subido en el log
			$this->register_imported( $name, filesize( $dest ), $manifest );

			wp_send_json_success( array(
				'message'  => __( 'Backup importado y registrado correctamente.', 'wp-ambackup' ),
				'filename' => $name,
				'size'     => size_format( filesize( $dest ) ),
				'manifest' => $manifest,
			) );
		}
	}

	// -------------------------------------------------------------------------
	// RESTAURACIÓN
	// -------------------------------------------------------------------------

	/**
	 * Restaura un backup completo.
	 * Soporta formato legacy (site-files.zip) y formato multipart_v1 (files_part_NNNN.zip).
	 *
	 * @param string $zip_path Ruta al ZIP de backup.
	 * @param array  $manifest Manifiesto del backup.
	 * @return true|WP_Error
	 */
	public function restore( $zip_path, $manifest = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'La extensión ZipArchive de PHP no está disponible.', 'wp-ambackup' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'zip_error', __( 'No se pudo abrir el archivo ZIP.', 'wp-ambackup' ) );
		}

		$tmp_dir = WPAMB_BACKUP_DIR . 'restore_' . uniqid() . '/';
		wp_mkdir_p( $tmp_dir );

		// Extraer ZIP maestro (solo contiene: database.sql, manifest.json, files_part_NNNN.zip)
		if ( ! $zip->extractTo( $tmp_dir ) ) {
			$zip->close();
			$this->cleanup_dir( $tmp_dir );
			return new WP_Error( 'extract_error', __( 'No se pudo extraer el ZIP.', 'wp-ambackup' ) );
		}
		$zip->close();

		$errors = array();

		// 1. Restaurar base de datos
		$sql_file = $tmp_dir . 'database.sql';
		if ( file_exists( $sql_file ) ) {
			$result = $this->restore_database( $sql_file, $manifest );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			}
		}

		// 2. Restaurar archivos — formato multipart_v1 o legacy
		$is_multipart = isset( $manifest['format'] ) && 'multipart_v1' === $manifest['format'];

		if ( $is_multipart ) {
			// Extraer cada ZIP de parte en orden
			$parts = ! empty( $manifest['parts'] ) ? $manifest['parts'] : array();
			if ( empty( $parts ) ) {
				// Fallback: buscar por glob en caso de manifiesto incompleto
				$found = glob( $tmp_dir . 'files_part_*.zip' );
				if ( $found ) {
					sort( $found );
					$parts = array_map( 'basename', $found );
				}
			}
			foreach ( $parts as $part_name ) {
				$part_path = $tmp_dir . $part_name;
				if ( ! file_exists( $part_path ) ) continue;
				$result = $this->restore_files( $part_path );
				if ( is_wp_error( $result ) ) {
					$errors[] = $part_name . ': ' . $result->get_error_message();
				}
			}
		} else {
			// Formato legacy: site-files.zip dentro del ZIP maestro
			$files_zip = $tmp_dir . 'site-files.zip';
			if ( file_exists( $files_zip ) ) {
				$result = $this->restore_files( $files_zip );
				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				}
			}
		}

		// Limpiar
		$this->cleanup_dir( $tmp_dir );

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'restore_partial', implode( "\n", $errors ) );
		}

		return true;
	}

	/**
	 * Restaura la base de datos desde un archivo SQL.
	 *
	 * @param string $sql_file  Ruta al archivo .sql
	 * @param array  $manifest  Manifiesto (para reemplazar prefijo si es distinto).
	 * @return true|WP_Error
	 */
	private function restore_database( $sql_file, $manifest = array() ) {
		global $wpdb;

		$sql_content = file_get_contents( $sql_file );
		if ( false === $sql_content ) {
			return new WP_Error( 'sql_read', __( 'No se pudo leer el archivo SQL.', 'wp-ambackup' ) );
		}

		// Reemplazar prefijo si es distinto al actual
		$old_prefix = $manifest['db_prefix'] ?? $wpdb->prefix;
		$new_prefix = $wpdb->prefix;
		if ( $old_prefix !== $new_prefix ) {
			$sql_content = str_replace(
				array( "`{$old_prefix}", "'{$old_prefix}", " {$old_prefix}" ),
				array( "`{$new_prefix}", "'{$new_prefix}", " {$new_prefix}" ),
				$sql_content
			);
		}

		// Reemplazar URL del sitio si es distinta
		$old_url = $manifest['site_url'] ?? '';
		$new_url = get_site_url();
		if ( $old_url && $old_url !== $new_url ) {
			$sql_content = str_replace( $old_url, $new_url, $sql_content );
		}

		// Ejecutar SQL en lotes (dividir por punto y coma)
		$wpdb->query( 'START TRANSACTION' );
		try {
			$statements = $this->split_sql( $sql_content );
			foreach ( $statements as $stmt ) {
				$stmt = trim( $stmt );
				if ( empty( $stmt ) || 0 === strpos( $stmt, '--' ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$result = $wpdb->query( $stmt );
				if ( false === $result && ! empty( $wpdb->last_error ) ) {
					// Ignorar errores de tablas no encontradas en DROP IF EXISTS
					if ( false === strpos( $wpdb->last_error, "Unknown table" ) ) {
						throw new Exception( $wpdb->last_error );
					}
				}
			}
			$wpdb->query( 'COMMIT' );
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sql_error', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Divide un volcado SQL en sentencias individuales.
	 * Maneja correctamente strings con punto y coma dentro.
	 *
	 * @param string $sql
	 * @return array
	 */
	private function split_sql( $sql ) {
		$statements = array();
		$buffer     = '';
		$in_string  = false;
		$string_char = '';
		$length     = strlen( $sql );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];

			if ( $in_string ) {
				$buffer .= $char;
				if ( $char === $string_char && ( $i === 0 || $sql[ $i - 1 ] !== '\\' ) ) {
					$in_string = false;
				}
			} elseif ( $char === "'" || $char === '"' || $char === '`' ) {
				$in_string   = true;
				$string_char = $char;
				$buffer     .= $char;
			} elseif ( $char === ';' ) {
				$statements[] = $buffer;
				$buffer       = '';
			} else {
				$buffer .= $char;
			}
		}

		if ( trim( $buffer ) !== '' ) {
			$statements[] = $buffer;
		}

		return $statements;
	}

	/**
	 * Restaura archivos del sitio desde un ZIP.
	 *
	 * @param string $files_zip Ruta al ZIP de archivos.
	 * @return true|WP_Error
	 */
	private function restore_files( $files_zip ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'ZipArchive no disponible.', 'wp-ambackup' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $files_zip, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'zip_error', __( 'No se pudo abrir el ZIP de archivos.', 'wp-ambackup' ) );
		}

		$destination = rtrim( ABSPATH, '/' );
		if ( ! $zip->extractTo( $destination ) ) {
			$zip->close();
			return new WP_Error( 'extract_error', __( 'No se pudieron extraer los archivos.', 'wp-ambackup' ) );
		}
		$zip->close();
		return true;
	}

	// -------------------------------------------------------------------------
	// UTILIDADES
	// -------------------------------------------------------------------------

	/**
	 * Lee el manifiesto de un backup ZIP.
	 */
	private function read_manifest( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array();
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::RDONLY ) ) {
			return array();
		}
		$data = $zip->getFromName( 'manifest.json' );
		$zip->close();
		if ( ! $data ) {
			return array();
		}
		$manifest = json_decode( $data, true );
		return is_array( $manifest ) ? $manifest : array();
	}

	/**
	 * Registra un backup importado externamente en el log.
	 */
	private function register_imported( $filename, $size, $manifest ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'ambackup_log',
			array(
				'filename'   => $filename,
				'size'       => $size,
				'type'       => 'imported',
				'status'     => 'completed',
				'created_at' => $manifest['created_at'] ?? current_time( 'mysql' ),
				'note'       => $manifest['site_url'] ?? '',
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Elimina un directorio de forma recursiva.
	 */
	private function cleanup_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				@rmdir( $file->getRealPath() );
			} else {
				@unlink( $file->getRealPath() );
			}
		}
		@rmdir( $dir );
	}
}
