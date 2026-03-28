<?php
/**
 * Gestión de creación, listado y eliminación de backups.
 * Soporta backup por chunks para hosting compartido con timeout de 30s.
 *
 * @package WP_AMBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAMB_Backup_Manager {

	const PROGRESS_OPTION      = 'wpamb_current_progress';
	const CANCEL_OPTION        = 'wpamb_cancel_flag';
	const CHUNK_STATE_OPTION   = 'wpamb_chunk_state';

	/** Archivos por petición AJAX. Ajustable según el servidor. */
	const CHUNK_SIZE = 500;

	public function __construct() {
		add_action( 'wpamb_scheduled_backup', array( $this, 'run_scheduled_backup' ) );
	}

	// =========================================================================
	// AJAX HANDLERS — BACKUP POR CHUNKS
	// =========================================================================

	/**
	 * PASO 1: Inicia el backup.
	 * - Valida el directorio
	 * - Exporta la BD
	 * - Escanea todos los archivos y guarda la lista
	 * - Devuelve el total de archivos y chunks necesarios
	 */
	public function create_backup_ajax() {
		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 300 );
		wp_raise_memory_limit( 'admin' );

		if ( ! wp_mkdir_p( WPAMB_BACKUP_DIR ) || ! is_writable( WPAMB_BACKUP_DIR ) ) {
			wp_send_json_error( array( 'message' => __( 'El directorio de backups no es escribible: ', 'wp-ambackup' ) . WPAMB_BACKUP_DIR ) );
		}

		delete_option( self::CANCEL_OPTION );

		$include_files = ! empty( $_POST['include_files'] );
		$include_db    = ! empty( $_POST['include_db'] );
		$filename      = $this->generate_filename();
		$zip_path      = WPAMB_BACKUP_DIR . $filename;
		$tmp_dir       = WPAMB_BACKUP_DIR . 'tmp_' . sanitize_key( $filename ) . '/';

		wp_mkdir_p( $tmp_dir );

		// --- Exportar base de datos ---
		if ( $include_db ) {
			$this->set_progress( 5, __( 'Exportando base de datos…', 'wp-ambackup' ) );
			$sql_file = $tmp_dir . 'database.sql';
			$result   = $this->export_database( $sql_file );
			if ( is_wp_error( $result ) ) {
				$this->cleanup_tmp( $tmp_dir );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			// Añadir SQL al ZIP
			$result = $this->zip_add_file( $zip_path, $sql_file, 'database.sql', true );
			if ( is_wp_error( $result ) ) {
				$this->cleanup_tmp( $tmp_dir );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			@unlink( $sql_file );
		}

		// --- Si no hay archivos, finalizar aquí ---
		if ( ! $include_files ) {
			$this->finalize_backup( $zip_path, $tmp_dir, $filename, $include_files, $include_db, 'manual' );
			wp_send_json_success( array(
				'done'     => true,
				'filename' => $filename,
				'size'     => size_format( filesize( $zip_path ) ),
				'url'      => $this->get_download_url( $filename ),
			) );
		}

		// --- Escanear archivos ---
		$this->set_progress( 15, __( 'Escaneando archivos del sitio…', 'wp-ambackup' ) );
		$exclude_paths = $this->get_exclude_paths();
		$file_list     = $this->scan_files( ABSPATH, $exclude_paths );
		$total         = count( $file_list );

		// Guardar lista en archivo temporal (evita saturar la tabla de opciones)
		$list_file = $tmp_dir . 'filelist.json';
		file_put_contents( $list_file, wp_json_encode( $file_list ) );

		// Guardar estado del chunk
		update_option( self::CHUNK_STATE_OPTION, array(
			'filename'      => $filename,
			'zip_path'      => $zip_path,
			'tmp_dir'       => $tmp_dir,
			'list_file'     => $list_file,
			'offset'        => 0,
			'total'         => $total,
			'include_files' => $include_files,
			'include_db'    => $include_db,
			'type'          => 'manual',
		), false );

		$chunks = ceil( $total / self::CHUNK_SIZE );
		$this->set_progress( 20, sprintf( __( 'Preparado: %d archivos en %d lotes.', 'wp-ambackup' ), $total, $chunks ) );

		wp_send_json_success( array(
			'done'        => false,
			'chunking'    => true,
			'total_files' => $total,
			'total_chunks'=> $chunks,
			'chunk_size'  => self::CHUNK_SIZE,
			'filename'    => $filename,
		) );
	}

	/**
	 * PASO 2: Procesa un chunk de archivos.
	 * Se llama repetidamente desde JS hasta que offset >= total.
	 */
	public function process_chunk_ajax() {
		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 300 );

		// Capturar errores fatales PHP y devolverlos como JSON
		register_shutdown_function( function () {
			$error = error_get_last();
			if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
				if ( ! headers_sent() ) {
					header( 'Content-Type: application/json' );
				}
				echo wp_json_encode( array(
					'success' => false,
					'data'    => array( 'message' => 'Error PHP en chunk: ' . $error['message'] . ' (línea ' . $error['line'] . ')' ),
				) );
				exit;
			}
		} );

		$state = get_option( self::CHUNK_STATE_OPTION );
		if ( ! $state || empty( $state['zip_path'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Estado de backup no encontrado. Inicia el backup de nuevo.', 'wp-ambackup' ) ) );
		}

		// Cancelación
		if ( get_option( self::CANCEL_OPTION ) ) {
			$this->abort_chunk_backup( $state );
			wp_send_json_error( array( 'message' => __( 'Backup cancelado.', 'wp-ambackup' ) ) );
		}

		$offset    = (int) $state['offset'];
		$total     = (int) $state['total'];
		$zip_path  = $state['zip_path'];
		$list_file = $state['list_file'];
		$tmp_dir   = $state['tmp_dir'];

		// Leer lista de archivos
		$file_list = json_decode( file_get_contents( $list_file ), true );
		if ( ! is_array( $file_list ) ) {
			$this->abort_chunk_backup( $state );
			wp_send_json_error( array( 'message' => __( 'Lista de archivos corrupta.', 'wp-ambackup' ) ) );
		}

		$chunk = array_slice( $file_list, $offset, self::CHUNK_SIZE );

		// Añadir archivos del chunk al ZIP
		$zip = new ZipArchive();
		$mode = file_exists( $zip_path ) ? ZipArchive::CREATE : ( ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $zip->open( $zip_path, $mode ) ) {
			$this->abort_chunk_backup( $state );
			wp_send_json_error( array( 'message' => __( 'No se pudo abrir el ZIP para escritura.', 'wp-ambackup' ) ) );
		}

		$abspath = rtrim( ABSPATH, '/\\' );
		foreach ( $chunk as $file_path ) {
			if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
				continue;
			}
			$relative = ltrim( str_replace( '\\', '/', substr( $file_path, strlen( $abspath ) ) ), '/' );
			$zip->addFile( $file_path, 'files/' . $relative );
		}
		$zip->close();

		$new_offset = $offset + count( $chunk );
		$percent    = 20 + intval( ( $new_offset / max( $total, 1 ) ) * 70 );

		$this->set_progress(
			min( $percent, 90 ),
			sprintf( __( 'Comprimiendo archivos… %d/%d', 'wp-ambackup' ), $new_offset, $total )
		);

		// Actualizar offset en el estado
		$state['offset'] = $new_offset;
		update_option( self::CHUNK_STATE_OPTION, $state, false );

		if ( $new_offset >= $total ) {
			// Todos los chunks procesados → finalizar
			$this->finalize_backup(
				$zip_path,
				$tmp_dir,
				$state['filename'],
				$state['include_files'],
				$state['include_db'],
				$state['type']
			);

			wp_send_json_success( array(
				'done'     => true,
				'filename' => $state['filename'],
				'size'     => size_format( filesize( $zip_path ) ),
				'url'      => $this->get_download_url( $state['filename'] ),
			) );
		}

		wp_send_json_success( array(
			'done'       => false,
			'offset'     => $new_offset,
			'total'      => $total,
			'percent'    => min( $percent, 90 ),
		) );
	}

	/**
	 * Finaliza el backup: añade manifiesto, registra en BD, rota y notifica.
	 */
	private function finalize_backup( $zip_path, $tmp_dir, $filename, $include_files, $include_db, $type ) {
		$this->set_progress( 95, __( 'Finalizando backup…', 'wp-ambackup' ) );

		// Añadir manifiesto al ZIP
		$manifest = array(
			'plugin_version' => WPAMB_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'site_url'       => get_site_url(),
			'created_at'     => current_time( 'mysql' ),
			'include_files'  => $include_files,
			'include_db'     => $include_db,
			'type'           => $type,
			'db_prefix'      => $GLOBALS['wpdb']->prefix,
		);
		$manifest_file = $tmp_dir . 'manifest.json';
		file_put_contents( $manifest_file, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
		$this->zip_add_file( $zip_path, $manifest_file, 'manifest.json' );

		// Limpiar tmp y estado
		$this->cleanup_tmp( $tmp_dir );
		delete_option( self::CHUNK_STATE_OPTION );

		$size = file_exists( $zip_path ) ? filesize( $zip_path ) : 0;
		$this->log_backup( $filename, $size, $type, 'completed' );
		$this->rotate_backups();
		$this->maybe_notify( 'success', $filename, $size );
		$this->set_progress( 100, __( 'Backup completado.', 'wp-ambackup' ) );
	}

	/**
	 * Aborta un backup por chunks en curso.
	 */
	private function abort_chunk_backup( $state ) {
		if ( ! empty( $state['tmp_dir'] ) ) {
			$this->cleanup_tmp( $state['tmp_dir'] );
		}
		if ( ! empty( $state['zip_path'] ) && file_exists( $state['zip_path'] ) ) {
			@unlink( $state['zip_path'] );
		}
		delete_option( self::CHUNK_STATE_OPTION );
		$this->set_progress( 0, '' );
	}

	// =========================================================================
	// BACKUP PROGRAMADO (WP-Cron — sin límite de tiempo AJAX)
	// =========================================================================

	public function run_scheduled_backup() {
		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 0 );

		$filename  = $this->generate_filename();
		$zip_path  = WPAMB_BACKUP_DIR . $filename;
		$tmp_dir   = WPAMB_BACKUP_DIR . 'tmp_cron_' . uniqid() . '/';
		wp_mkdir_p( $tmp_dir );

		$include_db    = (bool) get_option( 'wpamb_include_db', true );
		$include_files = (bool) get_option( 'wpamb_include_files', true );
		$exclude_paths = $this->get_exclude_paths();

		try {
			if ( $include_db ) {
				$sql_file = $tmp_dir . 'database.sql';
				$result   = $this->export_database( $sql_file );
				if ( is_wp_error( $result ) ) throw new Exception( $result->get_error_message() );
				$this->zip_add_file( $zip_path, $sql_file, 'database.sql', true );
				@unlink( $sql_file );
			}

			if ( $include_files ) {
				$file_list = $this->scan_files( ABSPATH, $exclude_paths );
				$abspath   = rtrim( ABSPATH, '/\\' );
				$chunks    = array_chunk( $file_list, self::CHUNK_SIZE );
				foreach ( $chunks as $chunk ) {
					$zip = new ZipArchive();
					$zip->open( $zip_path, ZipArchive::CREATE );
					foreach ( $chunk as $file_path ) {
						if ( ! file_exists( $file_path ) ) continue;
						$relative = ltrim( str_replace( '\\', '/', substr( $file_path, strlen( $abspath ) ) ), '/' );
						$zip->addFile( $file_path, 'files/' . $relative );
					}
					$zip->close();
				}
			}

			$this->finalize_backup( $zip_path, $tmp_dir, $filename, $include_files, $include_db, 'scheduled' );

		} catch ( Exception $e ) {
			$this->cleanup_tmp( $tmp_dir );
			if ( file_exists( $zip_path ) ) @unlink( $zip_path );
			$this->log_backup( $filename, 0, 'scheduled', 'failed', $e->getMessage() );
			$this->maybe_notify( 'failure', $filename, 0, $e->getMessage() );
		}

		WPAMB_Scheduler::schedule_next();
	}

	// =========================================================================
	// EXPORTAR BASE DE DATOS
	// =========================================================================

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

		$total = count( $tables );
		$done  = 0;

		foreach ( $tables as $table ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( $create ) {
				fwrite( $handle, "\n-- Tabla: `{$table}`\n" );
				fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
				fwrite( $handle, $create[1] . ";\n\n" );
			}

			$offset = 0;
			$batch  = 500;
			do {
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch, $offset ),
					ARRAY_A
				);
				if ( $rows ) {
					foreach ( $rows as $row ) {
						$values = array_map( function( $val ) {
							return null === $val ? 'NULL' : "'" . addslashes( $val ) . "'";
						}, $row );
						fwrite( $handle, "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n" );
					}
					$offset += $batch;
				}
			} while ( $rows && count( $rows ) === $batch );

			$done++;
			$this->set_progress(
				5 + intval( ( $done / $total ) * 10 ),
				sprintf( __( 'Exportando tabla %s (%d/%d)…', 'wp-ambackup' ), $table, $done, $total )
			);
		}

		fwrite( $handle, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $handle );
		return true;
	}

	// =========================================================================
	// ESCANEO DE ARCHIVOS
	// =========================================================================

	/**
	 * Devuelve un array plano con las rutas absolutas de todos los archivos.
	 */
	private function scan_files( $source_dir, $exclude_paths = array() ) {
		$source_dir = rtrim( $source_dir, '/\\' );
		$file_list  = array();

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) continue;

				$file_path = $file->getRealPath();
				if ( ! $file_path ) continue;

				$excluded = false;
				foreach ( $exclude_paths as $ex ) {
					if ( 0 === strpos( $file_path, rtrim( $ex, '/\\' ) ) ) {
						$excluded = true;
						break;
					}
				}
				if ( ! $excluded ) {
					$file_list[] = $file_path;
				}
			}
		} catch ( Exception $e ) {
			// Continuar aunque algún directorio no sea accesible
		}

		return $file_list;
	}

	/**
	 * Construye el array de rutas a excluir siempre.
	 */
	private function get_exclude_paths() {
		$abspath        = rtrim( ABSPATH, '/\\' );
		$always_exclude = array(
			rtrim( WPAMB_BACKUP_DIR, '/\\' ),
			$abspath . '/wp-content/cache',
			$abspath . '/wp-content/upgrade',
			$abspath . '/wp-content/wflogs',
		);
		$user_exclude = (array) get_option( 'wpamb_exclude_paths', array() );
		return array_filter( array_merge( $always_exclude, $user_exclude ) );
	}

	// =========================================================================
	// ZIP HELPERS
	// =========================================================================

	/**
	 * Abre el ZIP (o lo crea) y añade un único archivo.
	 *
	 * @param string $zip_path   Ruta al ZIP de destino.
	 * @param string $file_path  Ruta al archivo a añadir.
	 * @param string $entry_name Nombre dentro del ZIP.
	 * @param bool   $overwrite  Si true, crea el ZIP desde cero.
	 * @return true|WP_Error
	 */
	private function zip_add_file( $zip_path, $file_path, $entry_name, $overwrite = false ) {
		$zip  = new ZipArchive();
		$mode = $overwrite ? ( ZipArchive::CREATE | ZipArchive::OVERWRITE ) : ZipArchive::CREATE;

		if ( true !== $zip->open( $zip_path, $mode ) ) {
			return new WP_Error( 'zip_open', __( 'No se pudo abrir el ZIP.', 'wp-ambackup' ) );
		}
		$zip->addFile( $file_path, $entry_name );
		$zip->close();
		return true;
	}

	// =========================================================================
	// LISTADO Y ELIMINACIÓN
	// =========================================================================

	public function get_backups() {
		global $wpdb;
		$table = $wpdb->prefix . 'ambackup_log';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );

		$backups = array();
		foreach ( (array) $rows as $row ) {
			$path      = WPAMB_BACKUP_DIR . $row['filename'];
			$backups[] = array(
				'id'           => $row['id'],
				'filename'     => $row['filename'],
				'size'         => (int) $row['size'],
				'size_human'   => size_format( (int) $row['size'] ),
				'type'         => $row['type'],
				'status'       => $row['status'],
				'created_at'   => $row['created_at'],
				'exists'       => file_exists( $path ),
				'download_url' => $this->get_download_url( $row['filename'] ),
			);
		}
		return $backups;
	}

	public function delete_backup( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ambackup_log';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Backup no encontrado.', 'wp-ambackup' ) );
		}
		$path = WPAMB_BACKUP_DIR . $row['filename'];
		if ( file_exists( $path ) ) @unlink( $path );
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		return true;
	}

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

	// =========================================================================
	// DESCARGA
	// =========================================================================

	public function get_download_url( $filename ) {
		return add_query_arg( array(
			'wpamb_download' => rawurlencode( $filename ),
			'nonce'          => wp_create_nonce( 'wpamb_download_' . $filename ),
		), admin_url( 'admin-post.php?action=wpamb_download' ) );
	}

	public static function register_download_handler() {
		add_action( 'admin_post_wpamb_download', array( new self(), 'handle_download' ) );
	}

	public function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sin permisos.', 'wp-ambackup' ) );
		}
		$filename = isset( $_GET['wpamb_download'] ) ? sanitize_file_name( rawurldecode( $_GET['wpamb_download'] ) ) : '';
		if ( ! $filename || ! wp_verify_nonce( $_GET['nonce'] ?? '', 'wpamb_download_' . $filename ) ) {
			wp_die( __( 'Solicitud inválida.', 'wp-ambackup' ) );
		}
		$path = WPAMB_BACKUP_DIR . $filename;
		if ( ! file_exists( $path ) || ! is_file( $path ) ) {
			wp_die( __( 'Archivo no encontrado.', 'wp-ambackup' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Transfer-Encoding: binary' );
		$fp = fopen( $path, 'rb' );
		while ( ! feof( $fp ) ) {
			echo fread( $fp, 65536 );
			flush();
		}
		fclose( $fp );
		exit;
	}

	// =========================================================================
	// PROGRESO Y CANCELACIÓN
	// =========================================================================

	public function set_progress( $percent, $message = '' ) {
		update_option( self::PROGRESS_OPTION, array(
			'percent' => (int) $percent,
			'message' => $message,
			'time'    => time(),
		), false );
	}

	public function get_progress_ajax() {
		$progress = get_option( self::PROGRESS_OPTION, array( 'percent' => 0, 'message' => '' ) );
		wp_send_json_success( $progress );
	}

	public function cancel_backup_ajax() {
		update_option( self::CANCEL_OPTION, true, false );
		$state = get_option( self::CHUNK_STATE_OPTION );
		if ( $state ) {
			$this->abort_chunk_backup( $state );
		}
		wp_send_json_success( array( 'message' => __( 'Backup cancelado.', 'wp-ambackup' ) ) );
	}

	// =========================================================================
	// UTILIDADES PRIVADAS
	// =========================================================================

	private function generate_filename() {
		$date = current_time( 'Y-m-d_H-i-s' );
		$site = substr( sanitize_title( get_bloginfo( 'name' ) ), 0, 30 );
		return "backup_{$site}_{$date}.zip";
	}

	private function cleanup_tmp( $dir ) {
		if ( ! is_dir( $dir ) ) return;
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			$file->isDir() ? @rmdir( $file->getRealPath() ) : @unlink( $file->getRealPath() );
		}
		@rmdir( $dir );
	}

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

	private function rotate_backups() {
		$max = (int) get_option( 'wpamb_max_backups', 5 );
		if ( $max <= 0 ) return;
		global $wpdb;
		$table = $wpdb->prefix . 'ambackup_log';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" );
		if ( $total <= $max ) return;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'completed' ORDER BY created_at ASC LIMIT %d", $total - $max ),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$this->delete_backup( $row['id'] );
		}
	}

	private function maybe_notify( $event, $filename, $size, $error = '' ) {
		$email = get_option( 'wpamb_notification_email', get_option( 'admin_email' ) );
		if ( ! $email ) return;
		if ( 'success' === $event && ! get_option( 'wpamb_notify_on_success' ) ) return;
		if ( 'failure' === $event && ! get_option( 'wpamb_notify_on_failure' ) ) return;
		$site    = get_bloginfo( 'name' );
		$subject = 'success' === $event
			? sprintf( '[%s] Backup completado: %s', $site, $filename )
			: sprintf( '[%s] ERROR en backup: %s', $site, $filename );
		$body = 'success' === $event
			? sprintf( "Backup completado.\n\nArchivo: %s\nTamaño: %s\nFecha: %s", $filename, size_format( $size ), current_time( 'mysql' ) )
			: sprintf( "El backup ha fallado.\n\nArchivo: %s\nError: %s\nFecha: %s", $filename, $error, current_time( 'mysql' ) );
		wp_mail( $email, $subject, $body );
	}
}

add_action( 'init', array( 'WPAMB_Backup_Manager', 'register_download_handler' ) );
