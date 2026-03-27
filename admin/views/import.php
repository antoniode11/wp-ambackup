<?php
/**
 * Vista: Importar backup
 *
 * @package WP_AMBackup
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$max_upload = min(
	wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ),
	wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) )
);
?>
<div class="wrap wpamb-wrap">
	<h1 class="wpamb-title">
		<span class="dashicons dashicons-upload"></span>
		<?php _e( 'Importar Backup', 'wp-ambackup' ); ?>
	</h1>

	<div id="wpamb-import-result" class="wpamb-notice" style="display:none;"></div>

	<!-- ZONA DE SUBIDA -->
	<div class="wpamb-box">
		<h2><?php _e( 'Subir archivo de backup', 'wp-ambackup' ); ?></h2>
		<p>
			<?php _e( 'Sube un archivo .zip de backup generado por WP AMBackup para importarlo.', 'wp-ambackup' ); ?>
			<br>
			<?php printf( __( 'Tamaño máximo de subida del servidor: <strong>%s</strong>', 'wp-ambackup' ), esc_html( size_format( $max_upload ) ) ); ?>
		</p>

		<form id="wpamb-import-form" enctype="multipart/form-data">

			<!-- Área de arrastrar y soltar -->
			<div class="wpamb-dropzone" id="wpamb-dropzone">
				<span class="dashicons dashicons-upload wpamb-dropzone__icon"></span>
				<p class="wpamb-dropzone__text">
					<?php _e( 'Arrastra tu archivo aquí o', 'wp-ambackup' ); ?>
					<label for="backup_file" class="wpamb-dropzone__link">
						<?php _e( 'haz clic para seleccionar', 'wp-ambackup' ); ?>
					</label>
				</p>
				<p class="wpamb-dropzone__hint"><?php _e( 'Solo archivos .zip', 'wp-ambackup' ); ?></p>
				<input type="file" id="backup_file" name="backup_file" accept=".zip" class="wpamb-dropzone__input">
				<div class="wpamb-dropzone__file" id="wpamb-selected-file" style="display:none;">
					<span class="dashicons dashicons-media-archive"></span>
					<span id="wpamb-file-name"></span>
					<span id="wpamb-file-size" class="wpamb-hint"></span>
				</div>
			</div>

			<!-- Modo de importación -->
			<div class="wpamb-field" style="margin-top:20px;">
				<label class="wpamb-label"><?php _e( 'Modo de importación', 'wp-ambackup' ); ?></label>
				<div class="wpamb-radio-group">
					<label class="wpamb-radio">
						<input type="radio" name="import_mode" value="register" checked>
						<span>
							<?php _e( 'Solo registrar', 'wp-ambackup' ); ?>
							<small class="wpamb-hint"><?php _e( '(guarda el archivo en el servidor sin restaurar)', 'wp-ambackup' ); ?></small>
						</span>
					</label>
					<label class="wpamb-radio wpamb-radio--danger">
						<input type="radio" name="import_mode" value="restore">
						<span>
							<?php _e( 'Restaurar sitio', 'wp-ambackup' ); ?>
							<small class="wpamb-hint wpamb-hint--danger">
								<?php _e( '⚠ PELIGRO: sobrescribe BD y archivos actuales', 'wp-ambackup' ); ?>
							</small>
						</span>
					</label>
				</div>
			</div>

			<!-- Progreso de subida -->
			<div id="wpamb-upload-progress" class="wpamb-progress-wrap" style="display:none;">
				<div class="wpamb-progress-bar">
					<div class="wpamb-progress-fill" id="wpamb-upload-fill"></div>
				</div>
				<p class="wpamb-progress-msg" id="wpamb-upload-msg">
					<?php _e( 'Subiendo…', 'wp-ambackup' ); ?>
				</p>
			</div>

			<button type="submit" id="wpamb-import-btn" class="wpamb-btn wpamb-btn--primary" disabled>
				<span class="dashicons dashicons-upload"></span>
				<?php _e( 'Importar Backup', 'wp-ambackup' ); ?>
			</button>
		</form>
	</div>

	<!-- INSTRUCCIONES -->
	<div class="wpamb-box wpamb-info-box">
		<h3><?php _e( 'Información importante', 'wp-ambackup' ); ?></h3>
		<ul class="wpamb-info-list">
			<li>
				<span class="dashicons dashicons-info-outline"></span>
				<?php _e( 'Solo se aceptan archivos ZIP generados por WP AMBackup.', 'wp-ambackup' ); ?>
			</li>
			<li>
				<span class="dashicons dashicons-warning"></span>
				<?php _e( 'La opción "Restaurar sitio" es irreversible. Haz un backup previo antes de restaurar.', 'wp-ambackup' ); ?>
			</li>
			<li>
				<span class="dashicons dashicons-info-outline"></span>
				<?php _e( 'Si el archivo supera el límite de tu servidor, considera aumentar upload_max_filesize en php.ini.', 'wp-ambackup' ); ?>
			</li>
			<li>
				<span class="dashicons dashicons-info-outline"></span>
				<?php _e( 'Al restaurar en un dominio distinto, las URLs de la base de datos se actualizan automáticamente.', 'wp-ambackup' ); ?>
			</li>
		</ul>
	</div>
</div>
