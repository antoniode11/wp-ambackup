<?php
/**
 * Gestión de backups por partes (ZIP de ZIPs).
 *
 * Flujo AJAX:
 *   1. wpamb_create_backup → exporta BD, guarda estado
 *   2. wpamb_scan_files    → escanea archivos y guarda lista
 *   3. wpamb_backup_chunk  → cada chunk crea su propio ZIP de parte (pequeño → close() rápido)
 *   Finalize               → ZIP maestro que contiene BD + manifest + todos los ZIPs de partes
 *
 * Por qué ZIP de ZIPs:
 *   ZipArchive::close() reescribe el directorio central completo.
 *   Con 40k entradas ese proceso supera 30s. Con ZIPs de parte independientes
 *   cada close() es sobre pocos cientos de archivos → instantáneo.
 *   El ZIP maestro final solo tiene ~50 entradas → cierra en < 1s.
 *
 * @package WP_AMBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAMB_Backup_Manager {

	const PROGRESS_OPTION    = 'wpamb_current_progress';
	const CANCEL_OPTION      = 'wpamb_cancel_flag';
	const CHUNK_STATE_OPTION = 'wpamb_chunk_state';

	const CHUNK_SIZE_INITIAL = 1000;  // Archivos por parte — ajuste dinámico posterior
	const CHUNK_TIME_TARGET  = 15;    // Segundos objetivo por chunk
	const CHUNK_SIZE_MIN     = 50;
	const CHUNK_SIZE_MAX     = 5000;

	public function __construct() {
		add_action( 'wpamb_scheduled_backup', array( $this, 'run_scheduled_backup' ) );
	}

	// =========================================================================
	// PASO 1 — Exportar BD y preparar estado
	// =========================================================================

	public function create_backup_ajax() {
		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 300 );
		wp_raise_memory_limit( 'admin' );

		if ( ! wp_mkdir_p( WPAMB_BACKUP_DIR ) || ! is_writable( WPAMB_BACKUP_DIR ) ) {
			wp_send_json_error( array( 'message' => 'El directorio de backups no es escribible: ' . WPAMB_BACKUP_DIR ) );
		}

		delete_option( self::CANCEL_OPTION );

		$include_files = ! empty( $_POST['include_files'] );
		$include_db    = ! empty( $_POST['include_db'] );
		$filename      = $this->generate_filename();
		$tmp_dir       = WPAMB_BACKUP_DIR . 'tmp_' . uniqid() . '/';
		wp_mkdir_p( $tmp_dir );

		// --- Exportar base de datos ---
		if ( $include_db ) {
			$this->set_progress( 5, 'Exportando base de datos…' );
			$sql_file = $tmp_dir . 'database.sql';
			$result   = $this->export_database( $sql_file );
			if ( is_wp_error( $result ) ) {
				$this->cleanup_tmp( $tmp_dir );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
		}

		// --- Solo BD: ensamblar ZIP maestro directamente ---
		if ( ! $include_files ) {
			$zip_path = WPAMB_BACKUP_DIR . $filename;
			$this->assemble_master_zip( $zip_path, $tmp_dir, array(), $include_db );
			$this->finalize_backup( $zip_path, $tmp_dir, $filename, false, $include_db, 'manual' );
			wp_send_json_success( array(
				'done'     => true,
				'filename' => $filename,
				'size'     => size_format( filesize( $zip_path ) ),
				'url'      => $this->get_download_url( $filename ),
			) );
		}

		// --- Guardar estado inicial ---
		update_option( self::CHUNK_STATE_OPTION, array(
			'filename'      => $filename,
			'tmp_dir'       => $tmp_dir,
			'list_file'     => $tmp_dir . 'filelist.json',
			'offset'        => 0,
			'total'         => 0,
			'part_num'      => 0,
			'scanned'       => false,
			'include_files' => true,
			'include_db'    => $include_db,
			'type'          => 'manual',
		), false );

		$this->set_progress( 12, 'Base de datos lista. Iniciando escaneo de archivos…' );

		wp_send_json_success( array(
			'done'      => false,
			'need_scan' => true,
			'filename'  => $filename,
		) );
	}

	// =========================================================================
	// PASO 2 — Escanear archivos (petición separada)
	// =========================================================================

	public function scan_files_ajax() {
		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 300 );
		$this->register_fatal_handler( 'escaneo' );

		$state = get_option( self::CHUNK_STATE_OPTION );
		if ( ! $state ) {
			wp_send_json_error( array( 'message' => 'Estado no encontrado. Reinicia el backup.' ) );
		}
		if ( get_option( self::CANCEL_OPTION ) ) {
			$this->abort_backup( $state );
			wp_send_json_error( array( 'message' => 'Backup cancelado.' ) );
		}

		$this->set_progress( 15, 'Escaneando archivos del sitio…' );

		$file_list = $this->scan_files( ABSPATH, $this->get_exclude_paths() );
		$total     = count( $file_list );

		file_put_contents( $state['list_file'], wp_json_encode( $file_list ) );

		$state['total']   = $total;
		$state['scanned'] = true;
		update_option( self::CHUNK_STATE_OPTION, $state, false );

		$this->set_progress( 20, sprintf( 'Escaneados %d archivos. Iniciando compresión por partes…', $total ) );

		wp_send_json_success( array(
			'done'        => false,
			'chunking'    => true,
			'total_files' => $total,
			'chunk_size'  => self::CHUNK_SIZE_INITIAL,
			'filename'    => $state['filename'],
		) );
	}

	// =========================================================================
	// PASO 3 — Comprimir lote → cada chunk = un ZIP de parte independiente
	// =========================================================================

	public function process_chunk_ajax() {
		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 300 );
		$this->register_fatal_handler( 'compresión' );

		$state = get_option( self::CHUNK_STATE_OPTION );
		if ( ! $state ) {
			wp_send_json_error( array( 'message' => 'Estado no encontrado. Reinicia el backup.' ) );
		}
		if ( get_option( self::CANCEL_OPTION ) ) {
			$this->abort_backup( $state );
			wp_send_json_error( array( 'message' => 'Backup cancelado.' ) );
		}

		// ── Fase de ensamblaje: un ZIP de parte por petición ──
		if ( ! empty( $state['phase'] ) && 'assembling' === $state['phase'] ) {
			$this->do_assembly_step( $state );
			return;
		}

		$offset     = (int) $state['offset'];
		$total      = (int) $state['total'];
		$part_num   = (int) $state['part_num'] + 1;
		$chunk_size = isset( $_POST['chunk_size'] )
			? max( self::CHUNK_SIZE_MIN, min( self::CHUNK_SIZE_MAX, (int) $_POST['chunk_size'] ) )
			: self::CHUNK_SIZE_INITIAL;

		// Leer lista de archivos
		$file_list = json_decode( file_get_contents( $state['list_file'] ), true );
		if ( ! is_array( $file_list ) ) {
			$this->abort_backup( $state );
			wp_send_json_error( array( 'message' => 'Lista de archivos corrupta.' ) );
		}

		$chunk = array_slice( $file_list, $offset, $chunk_size );

		// ── Crear ZIP de parte independiente (SIEMPRE desde cero → close() rápido) ──
		$part_zip_path = $state['tmp_dir'] . sprintf( 'files_part_%04d.zip', $part_num );
		$zip           = new ZipArchive();

		if ( true !== $zip->open( $part_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->abort_backup( $state );
			wp_send_json_error( array( 'message' => 'No se pudo crear el ZIP de parte ' . $part_num ) );
		}

		$abspath    = rtrim( ABSPATH, '/\\' );
		$processed  = 0;
		$time_start = microtime( true );

		foreach ( $chunk as $file_path ) {
			if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) continue;
			if ( @filesize( $file_path ) > 500 * 1024 * 1024 ) continue;
			try {
				$relative = ltrim( str_replace( '\\', '/', substr( $file_path, strlen( $abspath ) ) ), '/' );
				if ( preg_match( '/[\x00-\x1F\x7F]/', $relative ) ) continue;
				$zip->addFile( $file_path, $relative );
				// CM_STORE: sin compresión → close() es puro I/O, sin CPU → nunca timeout
				// Los ZIPs de parte ya irán STORE en el ZIP maestro igualmente.
				$zip->setCompressionName( $relative, ZipArchive::CM_STORE );
				$processed++;
			} catch ( Exception $e ) {
				continue;
			}
		}

		// close() es rápido: solo $processed entradas en este ZIP de parte
		$zip->close();
		$time_total = microtime( true ) - $time_start;

		// Si el ZIP de parte quedó vacío, borrarlo
		if ( $processed === 0 && file_exists( $part_zip_path ) ) {
			@unlink( $part_zip_path );
			$part_num--; // no contar esta parte
		}

		// ── Calcular chunk_size óptimo para el siguiente lote ──
		if ( $time_total > 0 && $processed > 0 ) {
			$ratio      = self::CHUNK_TIME_TARGET / max( $time_total, 0.5 );
			$next_chunk = (int) round( $chunk_size * $ratio );
			$next_chunk = max( self::CHUNK_SIZE_MIN, min( self::CHUNK_SIZE_MAX, $next_chunk ) );
			$next_chunk = max( (int)( $chunk_size / 2 ), min( $chunk_size * 2, $next_chunk ) );
		} else {
			$next_chunk = $chunk_size;
		}

		$new_offset = $offset + count( $chunk );
		$percent    = 20 + intval( ( $new_offset / max( $total, 1 ) ) * 72 );

		$this->set_progress(
			min( $percent, 92 ),
			sprintf( 'Comprimiendo… %d/%d | Parte %d | Lote: %d arch | %.1fs', $new_offset, $total, $part_num, $processed, $time_total )
		);

		$state['offset']   = $new_offset;
		$state['part_num'] = $part_num;
		update_option( self::CHUNK_STATE_OPTION, $state, false );

		// ── ¿Terminamos todos los archivos? → Pasar a fase de ensamblaje por pasos ──
		if ( $new_offset >= $total ) {
			$parts_found = glob( $state['tmp_dir'] . 'files_part_*.zip' ) ?: array();
			sort( $parts_found );
			$parts_list = array_values( array_map( 'basename', $parts_found ) );

			$state['phase']          = 'assembling';
			$state['assembly_index'] = 0;
			$state['parts_list']     = $parts_list;
			update_option( self::CHUNK_STATE_OPTION, $state, false );

			$this->set_progress( 93, sprintf( 'Todos los archivos comprimidos en %d partes. Iniciando ensamblaje…', count( $parts_list ) ) );

			wp_send_json_success( array(
				'done'           => false,
				'assembling'     => true,
				'assembly_index' => 0,
				'total_parts'    => count( $parts_list ),
				'percent'        => 93,
			) );
		}

		wp_send_json_success( array(
			'done'            => false,
			'offset'          => $new_offset,
			'total'           => $total,
			'percent'         => min( $percent, 92 ),
			'next_chunk_size' => $next_chunk,
			'time_taken'      => round( $time_total, 2 ),
			'part_num'        => $part_num,
		) );
	}

	// =========================================================================
	// ENSAMBLAJE POR PASOS (un ZIP de parte por petición AJAX)
	// =========================================================================

	/**
	 * Añade UNA parte al ZIP maestro por petición.
	 * - Llamada 0  : crea el ZIP maestro + database.sql + manifest.json + files_part_0001.zip
	 * - Llamadas 1…N : abre el ZIP maestro existente y añade el siguiente files_part_NNNN.zip
	 * - Cuando idx >= total_parts : finaliza el backup
	 *
	 * Cada close() procesa exactamente UN fichero grande → nunca supera 30s.
	 */
	private function do_assembly_step( $state ) {
		$parts_list  = $state['parts_list'];
		$idx         = (int) $state['assembly_index'];
		$total_parts = count( $parts_list );
		$zip_path    = WPAMB_BACKUP_DIR . $state['filename'];

		$time_start = microtime( true );

		// Primera llamada: crear ZIP maestro desde cero
		$mode = ( 0 === $idx )
			? ( ZipArchive::CREATE | ZipArchive::OVERWRITE )
			: ZipArchive::CREATE;   // CREATE sin OVERWRITE = abrir existente para añadir

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, $mode ) ) {
			$this->abort_backup( $state );
			wp_send_json_error( array( 'message' => 'No se pudo abrir el ZIP maestro en ensamblaje (parte ' . $idx . ').' ) );
		}

		// En la primera llamada: añadir database.sql + manifest.json
		if ( 0 === $idx ) {
			$sql_file = $state['tmp_dir'] . 'database.sql';
			if ( $state['include_db'] && file_exists( $sql_file ) ) {
				$zip->addFile( $sql_file, 'database.sql' );
				$zip->setCompressionName( 'database.sql', ZipArchive::CM_STORE );
			}

			$manifest = array(
				'plugin_version' => WPAMB_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'site_url'       => get_site_url(),
				'created_at'     => current_time( 'mysql' ),
				'include_files'  => $total_parts > 0,
				'include_db'     => $state['include_db'],
				'db_prefix'      => $GLOBALS['wpdb']->prefix,
				'parts'          => $parts_list,
				'format'         => 'multipart_v1',
			);
			$manifest_json = $state['tmp_dir'] . 'manifest.json';
			file_put_contents( $manifest_json, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
			$zip->addFile( $manifest_json, 'manifest.json' );
		}

		// Añadir la parte actual (si existe)
		if ( $idx < $total_parts ) {
			$part_basename = $parts_list[ $idx ];
			$part_path     = $state['tmp_dir'] . $part_basename;
			if ( file_exists( $part_path ) ) {
				$zip->addFile( $part_path, $part_basename );
				$zip->setCompressionName( $part_basename, ZipArchive::CM_STORE );
			}
		}

		// close() solo procesa UN fichero grande → rápido
		$zip->close();
		$time_total = microtime( true ) - $time_start;

		$new_idx              = $idx + 1;
		$state['assembly_index'] = $new_idx;
		update_option( self::CHUNK_STATE_OPTION, $state, false );

		$pct = 93 + (int) ( ( $new_idx / max( $total_parts, 1 ) ) * 6 );
		$this->set_progress(
			min( $pct, 99 ),
			sprintf( 'Ensamblando… %d/%d partes (%.1fs)', $new_idx, $total_parts, $time_total )
		);

		// ¿Terminamos todas las partes? (o no había partes: solo BD+manifest)
		if ( $new_idx >= $total_parts ) {
			$this->finalize_backup(
				$zip_path,
				$state['tmp_dir'],
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
			'done'           => false,
			'assembling'     => true,
			'assembly_index' => $new_idx,
			'total_parts'    => $total_parts,
			'percent'        => min( $pct, 99 ),
			'time_taken'     => round( $time_total, 2 ),
		) );
	}

	// =========================================================================
	// ENSAMBLAR ZIP MAESTRO (usado por backup programado vía cron)
	// =========================================================================

	/**
	 * Crea el ZIP maestro final que contiene:
	 *   - database.sql (si include_db)
	 *   - manifest.json
	 *   - files_part_0001.zip … files_part_NNNN.zip
	 *
	 * Este ZIP solo tiene N_partes + 2 entradas → close() instantáneo.
	 */
	private function assemble_master_zip( $zip_path, $tmp_dir, $parts, $include_db ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_master', 'No se pudo crear el ZIP maestro.' );
		}

		// Base de datos — STORE sin recomprimir para que close() sea rápido
		$sql_file = $tmp_dir . 'database.sql';
		if ( $include_db && file_exists( $sql_file ) ) {
			$zip->addFile( $sql_file, 'database.sql' );
			$zip->setCompressionName( 'database.sql', ZipArchive::CM_STORE );
		}

		// Partes de archivos — STORE: ya son ZIPs comprimidos, recomprimirlos
		// haría que close() lea y procese cientos de MB → timeout garantizado.
		foreach ( $parts as $part_path ) {
			$entry = basename( $part_path );
			$zip->addFile( $part_path, $entry );
			$zip->setCompressionName( $entry, ZipArchive::CM_STORE );
		}

		// Manifiesto
		$manifest = array(
			'plugin_version' => WPAMB_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'site_url'       => get_site_url(),
			'created_at'     => current_time( 'mysql' ),
			'include_files'  => ! empty( $parts ),
			'include_db'     => $include_db,
			'db_prefix'      => $GLOBALS['wpdb']->prefix,
			'parts'          => array_map( 'basename', $parts ),
			'format'         => 'multipart_v1',
		);
		$manifest_json = $tmp_dir . 'manifest.json';
		file_put_contents( $manifest_json, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
		$zip->addFile( $manifest_json, 'manifest.json' );

		// close() ahora solo copia bytes en crudo (sin decompresión/recompresión)
		// → velocidad limitada por I/O de disco, no por CPU. Típicamente < 5s.
		$zip->close();
		return true;
	}

	// =========================================================================
	// FINALIZAR
	// =========================================================================

	private function finalize_backup( $zip_path, $tmp_dir, $filename, $include_files, $include_db, $type ) {
		$this->cleanup_tmp( $tmp_dir );
		delete_option( self::CHUNK_STATE_OPTION );

		$size = file_exists( $zip_path ) ? filesize( $zip_path ) : 0;
		$this->log_backup( $filename, $size, $type, 'completed' );
		$this->rotate_backups();
		$this->maybe_notify( 'success', $filename, $size );
		$this->set_progress( 100, 'Backup completado.' );
	}

	private function abort_backup( $state ) {
		if ( ! empty( $state['tmp_dir'] ) ) $this->cleanup_tmp( $state['tmp_dir'] );
		// Eliminar ZIP maestro si existe
		if ( ! empty( $state['filename'] ) ) {
			$zip_path = WPAMB_BACKUP_DIR . $state['filename'];
			if ( file_exists( $zip_path ) ) @unlink( $zip_path );
		}
		delete_option( self::CHUNK_STATE_OPTION );
		$this->set_progress( 0, '' );
	}

	// =========================================================================
	// BACKUP PROGRAMADO (WP-Cron)
	// =========================================================================

	public function run_scheduled_backup() {
		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 0 );

		$filename = $this->generate_filename();
		$zip_path = WPAMB_BACKUP_DIR . $filename;
		$tmp_dir  = WPAMB_BACKUP_DIR . 'tmp_cron_' . uniqid() . '/';
		wp_mkdir_p( $tmp_dir );

		$include_db    = (bool) get_option( 'wpamb_include_db',    true );
		$include_files = (bool) get_option( 'wpamb_include_files', true );

		try {
			if ( $include_db ) {
				$sql_file = $tmp_dir . 'database.sql';
				$r = $this->export_database( $sql_file );
				if ( is_wp_error( $r ) ) throw new Exception( $r->get_error_message() );
			}

			$parts = array();
			if ( $include_files ) {
				$file_list = $this->scan_files( ABSPATH, $this->get_exclude_paths() );
				$abspath   = rtrim( ABSPATH, '/\\' );
				$chunks    = array_chunk( $file_list, 1000 );
				foreach ( $chunks as $i => $chunk ) {
					$part_path = $tmp_dir . sprintf( 'files_part_%04d.zip', $i + 1 );
					$zip       = new ZipArchive();
					$zip->open( $part_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
					foreach ( $chunk as $fp ) {
						if ( ! file_exists( $fp ) ) continue;
						$rel = ltrim( str_replace( '\\', '/', substr( $fp, strlen( $abspath ) ) ), '/' );
						$zip->addFile( $fp, $rel );
						$zip->setCompressionName( $rel, ZipArchive::CM_STORE );
					}
					$zip->close();
					$parts[] = $part_path;
				}
			}

			$this->assemble_master_zip( $zip_path, $tmp_dir, $parts, $include_db );
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
	// BASE DE DATOS
	// =========================================================================

	private function export_database( $output_file ) {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( empty( $tables ) ) return new WP_Error( 'no_tables', 'No se encontraron tablas.' );

		$handle = fopen( $output_file, 'w' );
		if ( ! $handle ) return new WP_Error( 'file_error', 'No se pudo crear el archivo SQL.' );

		fwrite( $handle, "-- WP AMBackup SQL Dump\n-- Version: " . WPAMB_VERSION . "\n-- " . current_time( 'mysql' ) . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" );

		$total = count( $tables );
		$done  = 0;
		foreach ( $tables as $table ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( $create ) {
				fwrite( $handle, "\nDROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n" );
			}
			$offset = 0;
			do {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT 500 OFFSET %d", $offset ), ARRAY_A );
				if ( $rows ) {
					foreach ( $rows as $row ) {
						$vals = array_map( function( $v ) { return null === $v ? 'NULL' : "'" . addslashes( $v ) . "'"; }, $row );
						fwrite( $handle, "INSERT INTO `{$table}` VALUES (" . implode( ',', $vals ) . ");\n" );
					}
					$offset += 500;
				}
			} while ( $rows && count( $rows ) === 500 );
			$done++;
			$this->set_progress( 5 + intval( $done / $total * 7 ), "Exportando tabla {$table} ({$done}/{$total})…" );
		}
		fwrite( $handle, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $handle );
		return true;
	}

	// =========================================================================
	// ESCANEO DE ARCHIVOS
	// =========================================================================

	private function scan_files( $source_dir, $exclude_paths = array() ) {
		$source_dir = rtrim( $source_dir, '/\\' );
		$file_list  = array();
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $it as $file ) {
				if ( ! $file->isFile() ) continue;
				$fp = $file->getRealPath();
				if ( ! $fp ) continue;
				$excluded = false;
				foreach ( $exclude_paths as $ex ) {
					if ( 0 === strpos( $fp, rtrim( $ex, '/\\' ) ) ) { $excluded = true; break; }
				}
				if ( ! $excluded ) $file_list[] = $fp;
			}
		} catch ( Exception $e ) {}
		return $file_list;
	}

	private function get_exclude_paths() {
		$abspath = rtrim( ABSPATH, '/\\' );
		return array_filter( array_merge(
			array(
				rtrim( WPAMB_BACKUP_DIR, '/\\' ),
				$abspath . '/wp-content/cache',
				$abspath . '/wp-content/upgrade',
				$abspath . '/wp-content/wflogs',
			),
			(array) get_option( 'wpamb_exclude_paths', array() )
		) );
	}

	// =========================================================================
	// LISTADO Y ELIMINACIÓN
	// =========================================================================

	public function get_backups() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ambackup_log ORDER BY created_at DESC", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$path  = WPAMB_BACKUP_DIR . $row['filename'];
			$out[] = array(
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
		return $out;
	}

	public function delete_backup( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ambackup_log WHERE id=%d", $id ), ARRAY_A );
		if ( ! $row ) return new WP_Error( 'not_found', 'Backup no encontrado.' );
		$path = WPAMB_BACKUP_DIR . $row['filename'];
		if ( file_exists( $path ) ) @unlink( $path );
		$wpdb->delete( $wpdb->prefix . 'ambackup_log', array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	public function delete_backup_ajax() {
		$id = absint( $_POST['backup_id'] ?? 0 );
		if ( ! $id ) wp_send_json_error( array( 'message' => 'ID inválido.' ) );
		$result = $this->delete_backup( $id );
		is_wp_error( $result )
			? wp_send_json_error( array( 'message' => $result->get_error_message() ) )
			: wp_send_json_success( array( 'message' => 'Backup eliminado.' ) );
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
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos.' );
		$filename = sanitize_file_name( rawurldecode( $_GET['wpamb_download'] ?? '' ) );
		if ( ! $filename || ! wp_verify_nonce( $_GET['nonce'] ?? '', 'wpamb_download_' . $filename ) ) wp_die( 'Solicitud inválida.' );
		$path = WPAMB_BACKUP_DIR . $filename;
		if ( ! file_exists( $path ) ) wp_die( 'Archivo no encontrado.' );
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		$fp = fopen( $path, 'rb' );
		while ( ! feof( $fp ) ) { echo fread( $fp, 65536 ); flush(); }
		fclose( $fp );
		exit;
	}

	// =========================================================================
	// PROGRESO Y CANCELACIÓN
	// =========================================================================

	public function set_progress( $percent, $message = '' ) {
		update_option( self::PROGRESS_OPTION, array( 'percent' => (int) $percent, 'message' => $message, 'time' => time() ), false );
	}

	public function get_progress_ajax() {
		wp_send_json_success( get_option( self::PROGRESS_OPTION, array( 'percent' => 0, 'message' => '' ) ) );
	}

	public function cancel_backup_ajax() {
		update_option( self::CANCEL_OPTION, true, false );
		$state = get_option( self::CHUNK_STATE_OPTION );
		if ( $state ) $this->abort_backup( $state );
		wp_send_json_success( array( 'message' => 'Backup cancelado.' ) );
	}

	// =========================================================================
	// UTILIDADES
	// =========================================================================

	private function register_fatal_handler( $step = '' ) {
		register_shutdown_function( function () use ( $step ) {
			$error = error_get_last();
			if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
				if ( ! headers_sent() ) header( 'Content-Type: application/json' );
				echo wp_json_encode( array(
					'success' => false,
					'data'    => array( 'message' => 'Error PHP en ' . $step . ': ' . $error['message'] . ' (línea ' . $error['line'] . ')' ),
				) );
				exit;
			}
		} );
	}

	private function generate_filename() {
		return 'backup_' . substr( sanitize_title( get_bloginfo( 'name' ) ), 0, 30 ) . '_' . current_time( 'Y-m-d_H-i-s' ) . '.zip';
	}

	private function cleanup_tmp( $dir ) {
		if ( ! is_dir( $dir ) ) return;
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getRealPath() ) : @unlink( $f->getRealPath() ); }
		@rmdir( $dir );
	}

	private function log_backup( $filename, $size, $type, $status, $note = '' ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'ambackup_log',
			array( 'filename' => $filename, 'size' => $size, 'type' => $type, 'status' => $status, 'created_at' => current_time( 'mysql' ), 'note' => $note ),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	private function rotate_backups() {
		$max = (int) get_option( 'wpamb_max_backups', 5 );
		if ( $max <= 0 ) return;
		global $wpdb;
		$table = $wpdb->prefix . 'ambackup_log';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='completed'" );
		if ( $total <= $max ) return;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE status='completed' ORDER BY created_at ASC LIMIT %d", $total - $max ), ARRAY_A );
		foreach ( (array) $rows as $r ) $this->delete_backup( $r['id'] );
	}

	private function maybe_notify( $event, $filename, $size, $error = '' ) {
		$email = get_option( 'wpamb_notification_email', get_option( 'admin_email' ) );
		if ( ! $email ) return;
		if ( 'success' === $event && ! get_option( 'wpamb_notify_on_success' ) ) return;
		if ( 'failure' === $event && ! get_option( 'wpamb_notify_on_failure' ) ) return;
		$site = get_bloginfo( 'name' );
		$subj = 'success' === $event ? "[{$site}] Backup completado: {$filename}" : "[{$site}] ERROR en backup: {$filename}";
		$body = 'success' === $event
			? "Backup completado.\nArchivo: {$filename}\nTamaño: " . size_format( $size ) . "\nFecha: " . current_time( 'mysql' )
			: "Backup fallido.\nArchivo: {$filename}\nError: {$error}\nFecha: " . current_time( 'mysql' );
		wp_mail( $email, $subj, $body );
	}
}

add_action( 'init', array( 'WPAMB_Backup_Manager', 'register_download_handler' ) );
