<?php
/**
 * Exportación de backups (alias del backup manager para exportar backups existentes).
 *
 * @package WP_AMBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAMB_Exporter {

	/**
	 * Devuelve la información de un backup para su exportación.
	 *
	 * @param string $filename Nombre del archivo de backup.
	 * @return array|WP_Error
	 */
	public function get_export_info( $filename ) {
		$filename = sanitize_file_name( $filename );
		$path     = WPAMB_BACKUP_DIR . $filename;

		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'not_found', __( 'Archivo de backup no encontrado.', 'wp-ambackup' ) );
		}

		// Leer el manifiesto interno
		$manifest = $this->read_manifest( $path );

		return array(
			'filename'   => $filename,
			'path'       => $path,
			'size'       => filesize( $path ),
			'size_human' => size_format( filesize( $path ) ),
			'manifest'   => $manifest,
			'download_url' => wpamb()->backup_manager->get_download_url( $filename ),
		);
	}

	/**
	 * Lee el manifiesto de un archivo ZIP de backup.
	 *
	 * @param string $zip_path Ruta al ZIP.
	 * @return array
	 */
	public function read_manifest( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array();
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::RDONLY ) ) {
			return array();
		}

		$manifest_raw = $zip->getFromName( 'manifest.json' );
		$zip->close();

		if ( false === $manifest_raw ) {
			return array();
		}

		$manifest = json_decode( $manifest_raw, true );
		return is_array( $manifest ) ? $manifest : array();
	}

	/**
	 * Genera un archivo de exportación parcial (solo BD o solo archivos).
	 * Extrae del ZIP completo la parte solicitada.
	 *
	 * @param string $filename    Backup de origen.
	 * @param string $export_type 'db' | 'files'
	 * @return array|WP_Error
	 */
	public function export_partial( $filename, $export_type ) {
		$filename = sanitize_file_name( $filename );
		$path     = WPAMB_BACKUP_DIR . $filename;

		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'not_found', __( 'Backup no encontrado.', 'wp-ambackup' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'ZipArchive no disponible.', 'wp-ambackup' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'zip_error', __( 'No se pudo abrir el ZIP.', 'wp-ambackup' ) );
		}

		$export_filename = '';
		$export_path     = '';

		if ( 'db' === $export_type ) {
			$sql_content = $zip->getFromName( 'database.sql' );
			$zip->close();
			if ( false === $sql_content ) {
				return new WP_Error( 'no_db', __( 'El backup no contiene base de datos.', 'wp-ambackup' ) );
			}
			$export_filename = str_replace( '.zip', '_database.sql', $filename );
			$export_path     = WPAMB_BACKUP_DIR . $export_filename;
			file_put_contents( $export_path, $sql_content );
		} elseif ( 'files' === $export_type ) {
			$zip->close();
			// El ZIP de archivos está embebido como site-files.zip
			$inner_zip_content = $zip->getFromName( 'site-files.zip' );
			if ( false === $inner_zip_content ) {
				return new WP_Error( 'no_files', __( 'El backup no contiene archivos.', 'wp-ambackup' ) );
			}
			$export_filename = str_replace( '.zip', '_files.zip', $filename );
			$export_path     = WPAMB_BACKUP_DIR . $export_filename;
			file_put_contents( $export_path, $inner_zip_content );
		} else {
			$zip->close();
			return new WP_Error( 'invalid_type', __( 'Tipo de exportación inválido.', 'wp-ambackup' ) );
		}

		return array(
			'filename'     => $export_filename,
			'path'         => $export_path,
			'size'         => filesize( $export_path ),
			'size_human'   => size_format( filesize( $export_path ) ),
			'download_url' => wpamb()->backup_manager->get_download_url( $export_filename ),
		);
	}
}
