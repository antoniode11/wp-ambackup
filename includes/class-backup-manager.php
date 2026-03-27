<?php
/**
 * Gestión de creación, listado y eliminación de backups.
 *
 * @package WP_AMBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAMB_Backup_Manager {

	/** Nombre de la opción que guarda el progreso actual */
	const PROGRESS_OPTION = 'wpamb_current_progress';
	const CANCEL_OPTION   = 'wpamb_cancel_flag';

	public function __construct() {
		// Hook del cron de WP para backups programados
		add_action( 'wpamb_scheduled_backup', array( $this, 'run_scheduled_backup' ) );
	}

	// -------------------------------------------------------------------------
	// CREACIÓN DE BACKUP
	// -------------------------------------------------------------------------

	/**
	 * Crea un backup completo (archivos + BD o solo uno de los dos).
	 *
	 * @param array $args {
	 *   @type bool   $include_files  Incluir archivos. Default true.
	 *   @type bool   $include_db     Incluir base de datos. Default true.
	 *   @type string $type           'manual' | 'scheduled'. Default 'manual'.
	 *   @type array  $exclude_paths  Rutas a excluir.
	 * }
	 * @return array|WP_Error  Array con 'filename', 'path', 'size' o WP_Error.
	 */
	public function create_backup( $args = array() ) {
		// Aumentar límites para hosting compartido
		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 600 );
		@ini_set( 'max_execution_time', '600' );
		wp_raise_memory_limit( 'admin' );

		$defaults = array(
			'include_files' => (bool) get_option( 'wpamb_include_files', true ),
			'include_db'    => (bool) get_option( 'wpamb_include_db',    true ),
			'type'          => 'manual',
			'exclude_paths' => (array) get_option( 'wpamb_exclude_paths', array() ),
		);
		$args = wp_parse_args( $args, $defaults );

		// Limpiar flag de cancelación
		delete_option( self::CANCEL_OPTION );

		$this->set_progress( 0, __( 'Iniciando backup…', 'wp-ambackup' ) );

		// Preparar directorios temporales
		$tmp_dir = WPAMB_BACKUP_DIR . 'tmp_' . uniqid() . '/';
		wp_mkdir_p( $tmp_dir );

		$filename = $this->generate_filename();
		$zip_path = WPAMB_BACKUP_DIR . $filename;

		try {
			// 1. Exportar base de datos
			if ( $args['include_db'] ) {
				$this->set_progress( 5, __( 'Exportando base de datos…', 'wp-ambackup' ) );
				$sql_file = $tmp_dir . 'database.sql';
				$result   = $this->export_database( $sql_file );
				if ( is_wp_error( $result ) ) {
					throw new Exception( $result->get_error_message() );
				}
			}

			// Verificar cancelación
			if ( get_option( self::CANCEL_OPTION ) ) {
				$this->cleanup_tmp( $tmp_dir );
				return new WP_Error( 'cancelled', __( 'Backup cancelado por el usuario.', 'wp-ambackup' ) );
			}

			// 2. Comprimir archivos del sitio
			if ( $args['include_files'] ) {
				$this->set_progress( 20, __( 'Comprimiendo archivos del sitio…', 'wp-ambackup' ) );
				$result = $this->compress_files( $tmp_dir, $args['exclude_paths'] );
				if ( is_wp_error( $result ) ) {
					throw new Exception( $result->get_error_message() );
				}
			}

			// Verificar cancelación
			if ( get_option( self::CANCEL_OPTION ) ) {
				$this->cleanup_tmp( $tmp_dir );
				return new WP_Error( 'cancelled', __( 'Backup cancelado por el usuario.', 'wp-ambackup' ) );
			}

			// 3. Crear manifiesto
			$manifest = array(
				'plugin_version' => WPAMB_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'site_url'       => get_site_url(),
				'created_at'     => current_time( 'mysql' ),
				'include_files'  => $args['include_files'],
				'include_db'     => $args['include_db'],
				'type'           => $args['type'],
				'db_prefix'      => $GLOBALS['wpdb']->prefix,
			);
			file_put_contents( $tmp_dir . 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

			// 4. Empaquetar todo en ZIP final
			$this->set_progress( 75, __( 'Empaquetando backup…', 'wp-ambackup' ) );
			$result = $this->create_zip( $tmp_dir, $zip_path );
			if ( is_wp_error( $result ) ) {
				throw new Exception( $result->get_error_message() );
			}

			// 5. Limpiar tmp
			$this->cleanup_tmp( $tmp_dir );

			$size = filesize( $zip_path );

			// 6. Registrar en BD
			$this->log_backup( $filename, $size, $args['type'], 'completed' );

			// 7. Rotar backups antiguos
			$this->rotate_backups();

			$this->set_progress( 100, __( 'Backup completado.', 'wp-ambackup' ) );

			// Notificación por email
			$this->maybe_notify( 'success', $filename, $size );

			return array(
				'filename' => $filename,
				'path'     => $zip_path,
				'size'     => $size,
				'url'      => WPAMB_BACKUP_URL . $filename,
			);

		} catch ( Exception $e ) {
			$this->cleanup_tmp( $tmp_dir );
			if ( file_exists( $zip_path ) ) {
				@unlink( $zip_path );
			}
			$this->log_backup( $filename, 0, $args['type'], 'failed', $e->getMessage() );
			$this->set_progress( -1, $e->getMessage() );
			$this->maybe_notify( 'failure', $filename, 0, $e->getMessage() );
			return new WP_Error( 'backup_failed', $e->getMessage() );
		}
	}

	/**
	 * Handler AJAX para crear backup.
	 */
	public function create_backup_ajax() {
		// Capturar errores fatales de PHP y devolverlos como JSON
		register_shutdown_function( function () {
			$error = error_get_last();
			if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
				if ( ! headers_sent() ) {
					header( 'Content-Type: application/json' );
				}
				echo wp_json_encode( array(
					'success' => false,
					'data'    => array( 'message' => 'Error PHP: ' . $error['message'] . ' en ' . $error['file'] . ' línea ' . $error['line'] ),
				) );
				exit;
			}
		} );

		$include_files = isset( $_POST['include_files'] ) ? (bool) $_POST['include_files'] : true;
		$include_db    = isset( $_POST['include_db'] )    ? (bool) $_POST['include_db']    : true;

		// Verificar que el directorio de backups es escribible
		if ( ! wp_mkdir_p( WPAMB_BACKUP_DIR ) ) {
			wp_send_json_error( array(
				'message' => __( 'No se puede crear el directorio de backups: ', 'wp-ambackup' ) . WPAMB_BACKUP_DIR .
							 __( ' — Verifica los permisos de la carpeta uploads.', 'wp-ambackup' ),
			) );
		}

		if ( ! is_writable( WPAMB_BACKUP_DIR ) ) {
			wp_send_json_error( array(
				'message' => __( 'El directorio de backups no tiene permisos de escritura: ', 'wp-ambackup' ) . WPAMB_BACKUP_DIR,
			) );
		}

		$result = $this->create_backup( array(
			'include_files' => $include_files,
			'include_db'    => $include_db,
			'type'          => 'manual',
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message'  => __( 'Backup creado correctamente.', 'wp-ambackup' ),
			'filename' => $result['filename'],
			'size'     => size_format( $result['size'] ),
			'url'      => $result['url'],
		) );
	}

	/**
	 * Ejecuta backup programado (llamado por el cron de WP).
	 */
	public function run_scheduled_backup() {
		$result = $this->create_backup( array( 'type' => 'scheduled' ) );
		// Programar el siguiente
		WPAMB_Scheduler::schedule_next();
		return $result;
	}

	// -------------------------------------------------------------------------
	// EXPORTAR BASE DE DATOS
	// -------------------------------------------------------------------------

	/**
	 * Exporta la base de datos a un archivo .sql
	 */
	private function export_database( $output_file ) {
		global $wpdb;

		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( empty( $tables ) ) {
			return new WP_Error( 'no_tables', __( 'No se encontraron tablas en la BD.', 'wp-ambackup' ) );
		}

		$handle = fopen( $output_file, 'w' );
		if ( ! $handle ) {
			return new WP_Error( 'file_error', __( 'No se pudo crear el archivo SQL.', 'wp-ambackup' ) );
		}

		fwrite( $handle, "-- WP AMBackup SQL Dump\n" );
		fwrite( $handle, "-- Version: " . WPAMB_VERSION . "\n" );
		fwrite( $handle, "-- Generado: " . current_time( 'mysql' ) . "\n" );
		fwrite( $handle, "-- Sitio: " . get_site_url() . "\n\n" );
		fwrite( $handle, "SET NAMES utf8mb4;\n" );
		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

		$total  = count( $tables );
		$done   = 0;

		foreach ( $tables as $table ) {
			// Cancelación
			if ( get_option( self::CANCEL_OPTION ) ) {
				fclose( $handle );
				return new WP_Error( 'cancelled', '' );
			}

			// DROP + CREATE TABLE
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( $create ) {
				fwrite( $handle, "\n-- Tabla: `{$table}`\n" );
				fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
				fwrite( $handle, $create[1] . ";\n\n" );
			}

			// Datos en lotes de 500 filas
			$offset    = 0;
			$batch     = 500;
			do {
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch, $offset ),
					ARRAY_A
				);
				if ( $rows ) {
					foreach ( $rows as $row ) {
						$values = array_map( function( $val ) use ( $wpdb ) {
							return null === $val ? 'NULL' : "'" . esc_sql( $val ) . "'";
						}, $row );
						fwrite( $handle, "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n" );
					}
					$offset += $batch;
				}
			} while ( $rows && count( $rows ) === $batch );

			$done++;
			$progress = 5 + intval( ( $done / $total ) * 15 );
			$this->set_progress( $progress, sprintf( __( 'Exportando tabla %s (%d/%d)…', 'wp-ambackup' ), $table, $done, $total ) );
		}

		fwrite( $handle, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $handle );

		return true;
	}

	// -------------------------------------------------------------------------
	// COMPRIMIR ARCHIVOS
	// -------------------------------------------------------------------------

	/**
	 * Copia/comprime los archivos del sitio WordPress en $tmp_dir/files/
	 */
	private function compress_files( $tmp_dir, $exclude_paths = array() ) {
		$files_dir = $tmp_dir . 'files/';
		wp_mkdir_p( $files_dir );

		$abspath = rtrim( ABSPATH, '/' );

		// Rutas a excluir siempre
		$always_exclude = array(
			WPAMB_BACKUP_DIR,
			$abspath . '/wp-content/cache',
			$abspath . '/wp-content/upgrade',
		);
		$exclude_paths = array_merge( $always_exclude, $exclude_paths );

		$zip_file = $tmp_dir . 'site-files.zip';

		if ( class_exists( 'ZipArchive' ) ) {
			$result = $this->zip_with_ziparchive( $abspath, $zip_file, $exclude_paths );
		} else {
			$result = $this->zip_with_pclzip( $abspath, $zip_file, $exclude_paths );
		}

		return $result;
	}

	/**
	 * Crear ZIP usando ZipArchive (PHP nativo).
	 */
	private function zip_with_ziparchive( $source_dir, $zip_file, $exclude_paths ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_error', __( 'No se pudo crear el archivo ZIP.', 'wp-ambackup' ) );
		}

		$source_dir = rtrim( $source_dir, '/\\' );
		$files      = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$total_files = 0;
		$done_files  = 0;

		// Contar archivos primero (aproximado)
		foreach ( $files as $file ) {
			if ( $file->isFile() ) {
				$total_files++;
			}
		}

		// Reiniciar iterador
		$files->rewind();

		foreach ( $files as $file ) {
			// Cancelación
			if ( $done_files % 200 === 0 && get_option( self::CANCEL_OPTION ) ) {
				$zip->close();
				return new WP_Error( 'cancelled', '' );
			}

			$file_path = $file->getRealPath();

			// Verificar exclusiones
			$excluded = false;
			foreach ( $exclude_paths as $ex ) {
				$ex = rtrim( $ex, '/\\' );
				if ( 0 === strpos( $file_path, $ex ) ) {
					$excluded = true;
					break;
				}
			}
			if ( $excluded ) {
				continue;
			}

			$relative = substr( $file_path, strlen( $source_dir ) + 1 );
			$relative = str_replace( '\\', '/', $relative );

			if ( $file->isDir() ) {
				$zip->addEmptyDir( $relative );
			} elseif ( $file->isFile() ) {
				$zip->addFile( $file_path, $relative );
				$done_files++;
				if ( $done_files % 100 === 0 ) {
					$progress = 20 + intval( ( $done_files / max( $total_files, 1 ) ) * 50 );
					$this->set_progress( min( $progress, 70 ), sprintf( __( 'Comprimiendo archivos… %d/%d', 'wp-ambackup' ), $done_files, $total_files ) );
				}
			}
		}

		$zip->close();
		return true;
	}

	/**
	 * Fallback con PclZip (incluido en WordPress).
	 */
	private function zip_with_pclzip( $source_dir, $zip_file, $exclude_paths ) {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$zip = new PclZip( $zip_file );

		$exclude_list = implode( ',', $exclude_paths );
		$result = $zip->create( $source_dir, PCLZIP_OPT_REMOVE_PATH, $source_dir, PCLZIP_OPT_NO_COMPRESSION );

		if ( 0 === $result ) {
			return new WP_Error( 'pclzip_error', $zip->errorInfo( true ) );
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// EMPAQUETAR EN ZIP FINAL
	// -------------------------------------------------------------------------

	/**
	 * Empaqueta el directorio temporal en un único ZIP de backup.
	 */
	private function create_zip( $tmp_dir, $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$zip    = new PclZip( $zip_path );
			$result = $zip->create( $tmp_dir, PCLZIP_OPT_REMOVE_PATH, $tmp_dir );
			if ( 0 === $result ) {
				return new WP_Error( 'pclzip_error', $zip->errorInfo( true ) );
			}
			return true;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_error', __( 'No se pudo crear el ZIP final.', 'wp-ambackup' ) );
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $tmp_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $files as $file ) {
			$relative = substr( $file->getRealPath(), strlen( $tmp_dir ) );
			$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
			if ( $file->isDir() ) {
				$zip->addEmptyDir( $relative );
			} else {
				$zip->addFile( $file->getRealPath(), $relative );
			}
		}
		$zip->close();
		return true;
	}

	// -------------------------------------------------------------------------
	// LISTADO Y ELIMINACIÓN
	// -------------------------------------------------------------------------

	/**
	 * Devuelve la lista de backups disponibles.
	 *
	 * @return array
	 */
	public function get_backups() {
		global $wpdb;
		$table = $wpdb->prefix . 'ambackup_log';
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY created_at DESC",
			ARRAY_A
		);

		$backups = array();
		foreach ( (array) $rows as $row ) {
			$path = WPAMB_BACKUP_DIR . $row['filename'];
			$backups[] = array(
				'id'         => $row['id'],
				'filename'   => $row['filename'],
				'size'       => (int) $row['size'],
				'size_human' => size_format( (int) $row['size'] ),
				'type'       => $row['type'],
				'status'     => $row['status'],
				'created_at' => $row['created_at'],
				'exists'     => file_exists( $path ),
				'download_url' => $this->get_download_url( $row['filename'] ),
			);
		}
		return $backups;
	}

	/**
	 * Elimina un backup por su ID.
	 */
	public function delete_backup( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ambackup_log';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Backup no encontrado.', 'wp-ambackup' ) );
		}

		$path = WPAMB_BACKUP_DIR . $row['filename'];
		if ( file_exists( $path ) ) {
			@unlink( $path );
		}

		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	/**
	 * Handler AJAX para eliminar backup.
	 */
	public function delete_backup_ajax() {
		$id = isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'ID de backup inválido.', 'wp-ambackup' ) ) );
		}
		$result = $this->delete_backup( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Backup eliminado.', 'wp-ambackup' ) ) );
	}

	// -------------------------------------------------------------------------
	// DESCARGA DE BACKUP
	// -------------------------------------------------------------------------

	/**
	 * Devuelve la URL de descarga segura (a través de un handler de WordPress).
	 */
	public function get_download_url( $filename ) {
		return add_query_arg( array(
			'wpamb_download' => rawurlencode( $filename ),
			'nonce'          => wp_create_nonce( 'wpamb_download_' . $filename ),
		), admin_url( 'admin-post.php?action=wpamb_download' ) );
	}

	/**
	 * Registra el handler de descarga.
	 */
	public static function register_download_handler() {
		add_action( 'admin_post_wpamb_download', array( new self(), 'handle_download' ) );
	}

	/**
	 * Sirve el archivo de backup para descarga directa.
	 */
	public function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sin permisos.', 'wp-ambackup' ) );
		}

		$filename = isset( $_GET['wpamb_download'] ) ? sanitize_file_name( rawurldecode( $_GET['wpamb_download'] ) ) : '';
		if ( ! $filename ) {
			wp_die( __( 'Archivo no especificado.', 'wp-ambackup' ) );
		}

		if ( ! wp_verify_nonce( $_GET['nonce'] ?? '', 'wpamb_download_' . $filename ) ) {
			wp_die( __( 'Token de seguridad inválido.', 'wp-ambackup' ) );
		}

		$path = WPAMB_BACKUP_DIR . $filename;
		if ( ! file_exists( $path ) || ! is_file( $path ) ) {
			wp_die( __( 'Archivo no encontrado.', 'wp-ambackup' ) );
		}

		// Cabeceras para descarga
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Streaming del archivo
		$fp = fopen( $path, 'rb' );
		while ( ! feof( $fp ) ) {
			echo fread( $fp, 65536 );
			flush();
		}
		fclose( $fp );
		exit;
	}

	// -------------------------------------------------------------------------
	// PROGRESO Y CANCELACIÓN
	// -------------------------------------------------------------------------

	/**
	 * Actualiza el progreso del backup actual.
	 */
	public function set_progress( $percent, $message = '' ) {
		update_option( self::PROGRESS_OPTION, array(
			'percent' => (int) $percent,
			'message' => $message,
			'time'    => time(),
		), false );
	}

	/**
	 * Devuelve el progreso actual vía AJAX.
	 */
	public function get_progress_ajax() {
		$progress = get_option( self::PROGRESS_OPTION, array( 'percent' => 0, 'message' => '' ) );
		wp_send_json_success( $progress );
	}

	/**
	 * Cancela el backup en curso.
	 */
	public function cancel_backup_ajax() {
		update_option( self::CANCEL_OPTION, true, false );
		wp_send_json_success( array( 'message' => __( 'Señal de cancelación enviada.', 'wp-ambackup' ) ) );
	}

	// -------------------------------------------------------------------------
	// UTILIDADES
	// -------------------------------------------------------------------------

	/**
	 * Genera un nombre único para el archivo de backup.
	 */
	private function generate_filename() {
		$date     = current_time( 'Y-m-d_H-i-s' );
		$site     = sanitize_title( get_bloginfo( 'name' ) );
		$site     = substr( $site, 0, 30 );
		return "backup_{$site}_{$date}.zip";
	}

	/**
	 * Elimina el directorio temporal de trabajo.
	 */
	private function cleanup_tmp( $dir ) {
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

	/**
	 * Registra un backup en la tabla de log.
	 */
	private function log_backup( $filename, $size, $type, $status, $note = '' ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'ambackup_log',
			array(
				'filename'   => $filename,
				'size'       => $size,
				'type'       => $type,
				'status'     => $status,
				'created_at' => current_time( 'mysql' ),
				'note'       => $note,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Elimina los backups más antiguos si se supera el máximo permitido.
	 */
	private function rotate_backups() {
		$max = (int) get_option( 'wpamb_max_backups', 5 );
		if ( $max <= 0 ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ambackup_log';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" );

		if ( $total <= $max ) {
			return;
		}

		$to_delete = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, filename FROM {$table} WHERE status = 'completed' ORDER BY created_at ASC LIMIT %d",
				$total - $max
			),
			ARRAY_A
		);

		foreach ( (array) $to_delete as $row ) {
			$this->delete_backup( $row['id'] );
		}
	}

	/**
	 * Envía notificación por email si está configurado.
	 */
	private function maybe_notify( $event, $filename, $size, $error = '' ) {
		$email = get_option( 'wpamb_notification_email', get_option( 'admin_email' ) );
		if ( ! $email ) {
			return;
		}

		if ( 'success' === $event && ! get_option( 'wpamb_notify_on_success' ) ) {
			return;
		}
		if ( 'failure' === $event && ! get_option( 'wpamb_notify_on_failure' ) ) {
			return;
		}

		$site    = get_bloginfo( 'name' );
		$subject = 'success' === $event
			? sprintf( __( '[%s] Backup completado: %s', 'wp-ambackup' ), $site, $filename )
			: sprintf( __( '[%s] ERROR en backup: %s', 'wp-ambackup' ), $site, $filename );

		$body = 'success' === $event
			? sprintf( __( "El backup se completó correctamente.\n\nArchivo: %s\nTamaño: %s\nFecha: %s", 'wp-ambackup' ), $filename, size_format( $size ), current_time( 'mysql' ) )
			: sprintf( __( "El backup ha fallado.\n\nArchivo: %s\nError: %s\nFecha: %s", 'wp-ambackup' ), $filename, $error, current_time( 'mysql' ) );

		wp_mail( $email, $subject, $body );
	}
}

// Registrar handler de descarga
add_action( 'init', array( 'WPAMB_Backup_Manager', 'register_download_handler' ) );
