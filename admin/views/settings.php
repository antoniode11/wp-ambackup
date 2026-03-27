<?php
/**
 * Vista: Ajustes del plugin
 *
 * @package WP_AMBackup
 * @var array $settings
 */
if ( ! defined( 'ABSPATH' ) ) exit;

settings_errors( 'wpamb' );
?>
<div class="wrap wpamb-wrap">
	<h1 class="wpamb-title">
		<span class="dashicons dashicons-admin-settings"></span>
		<?php _e( 'Ajustes', 'wp-ambackup' ); ?>
	</h1>

	<form method="post" id="wpamb-settings-form">
		<?php wp_nonce_field( 'wpamb_settings', 'wpamb_settings_nonce' ); ?>

		<!-- BACKUP POR DEFECTO -->
		<div class="wpamb-box">
			<h2><?php _e( 'Contenido del Backup', 'wp-ambackup' ); ?></h2>

			<div class="wpamb-field">
				<label class="wpamb-label"><?php _e( 'Incluir en el backup', 'wp-ambackup' ); ?></label>
				<label class="wpamb-checkbox">
					<input type="checkbox" name="include_files" value="1"
						<?php checked( $settings['include_files'] ); ?>>
					<span><?php _e( 'Archivos del sitio (wp-content, themes, plugins, uploads…)', 'wp-ambackup' ); ?></span>
				</label>
				<label class="wpamb-checkbox">
					<input type="checkbox" name="include_db" value="1"
						<?php checked( $settings['include_db'] ); ?>>
					<span><?php _e( 'Base de datos completa', 'wp-ambackup' ); ?></span>
				</label>
			</div>

			<div class="wpamb-field">
				<label class="wpamb-label" for="exclude_paths">
					<?php _e( 'Rutas a excluir (una por línea)', 'wp-ambackup' ); ?>
				</label>
				<textarea name="exclude_paths" id="exclude_paths" rows="5" class="wpamb-textarea large-text"
						  placeholder="/ruta/absoluta/a/excluir"><?php echo esc_textarea( $settings['exclude_paths'] ); ?></textarea>
				<p class="wpamb-hint">
					<?php _e( 'Usa rutas absolutas del servidor. El directorio de backups y la caché se excluyen automáticamente.', 'wp-ambackup' ); ?>
				</p>
			</div>
		</div>

		<!-- RETENCIÓN -->
		<div class="wpamb-box">
			<h2><?php _e( 'Retención de Backups', 'wp-ambackup' ); ?></h2>

			<div class="wpamb-field">
				<label class="wpamb-label" for="max_backups">
					<?php _e( 'Número máximo de backups a conservar', 'wp-ambackup' ); ?>
				</label>
				<input type="number" name="max_backups" id="max_backups"
					   value="<?php echo esc_attr( $settings['max_backups'] ); ?>"
					   min="1" max="100" class="wpamb-input wpamb-input--sm">
				<p class="wpamb-hint">
					<?php _e( 'Los backups más antiguos se eliminan automáticamente al superar este límite. 0 = sin límite.', 'wp-ambackup' ); ?>
				</p>
			</div>
		</div>

		<!-- NOTIFICACIONES -->
		<div class="wpamb-box">
			<h2><?php _e( 'Notificaciones por Email', 'wp-ambackup' ); ?></h2>

			<div class="wpamb-field">
				<label class="wpamb-label" for="notification_email">
					<?php _e( 'Email de notificaciones', 'wp-ambackup' ); ?>
				</label>
				<input type="email" name="notification_email" id="notification_email"
					   value="<?php echo esc_attr( $settings['notification_email'] ); ?>"
					   class="wpamb-input regular-text">
			</div>

			<div class="wpamb-field">
				<label class="wpamb-label"><?php _e( 'Enviar notificación cuando', 'wp-ambackup' ); ?></label>
				<label class="wpamb-checkbox">
					<input type="checkbox" name="notify_on_success" value="1"
						<?php checked( $settings['notify_on_success'] ); ?>>
					<span><?php _e( 'El backup se completa con éxito', 'wp-ambackup' ); ?></span>
				</label>
				<label class="wpamb-checkbox">
					<input type="checkbox" name="notify_on_failure" value="1"
						<?php checked( $settings['notify_on_failure'] ); ?>>
					<span><?php _e( 'El backup falla', 'wp-ambackup' ); ?></span>
				</label>
			</div>
		</div>

		<!-- ACTUALIZACIONES DESDE GITHUB -->
		<div class="wpamb-box">
			<h2><?php _e( 'Actualizaciones desde GitHub', 'wp-ambackup' ); ?></h2>
			<p>
				<?php
				printf(
					__( 'El plugin se actualiza automáticamente desde el repositorio <a href="%s" target="_blank">%s/%s</a> en GitHub.', 'wp-ambackup' ),
					esc_url( 'https://github.com/' . WPAMB_GITHUB_USER . '/' . WPAMB_GITHUB_REPO ),
					esc_html( WPAMB_GITHUB_USER ),
					esc_html( WPAMB_GITHUB_REPO )
				);
				?>
			</p>
			<div class="wpamb-field">
				<label class="wpamb-label" for="github_token">
					<?php _e( 'Token de acceso personal de GitHub (solo para repos privados)', 'wp-ambackup' ); ?>
				</label>
				<input type="password" name="github_token" id="github_token"
					   value="<?php echo esc_attr( $settings['github_token'] ); ?>"
					   class="wpamb-input regular-text"
					   autocomplete="new-password">
				<p class="wpamb-hint">
					<?php _e( 'Deja vacío si el repositorio es público.', 'wp-ambackup' ); ?>
				</p>
			</div>
		</div>

		<!-- HERRAMIENTAS -->
		<div class="wpamb-box">
			<h2><?php _e( 'Herramientas', 'wp-ambackup' ); ?></h2>
			<div class="wpamb-tools">
				<div>
					<strong><?php _e( 'Directorio de backups:', 'wp-ambackup' ); ?></strong>
					<code><?php echo esc_html( WPAMB_BACKUP_DIR ); ?></code>
				</div>
				<div style="margin-top:10px;">
					<strong><?php _e( 'Espacio libre en disco:', 'wp-ambackup' ); ?></strong>
					<?php
					$free = disk_free_space( WPAMB_BACKUP_DIR );
					echo $free !== false ? esc_html( size_format( $free ) ) : __( 'No disponible', 'wp-ambackup' );
					?>
				</div>
				<div style="margin-top:10px;">
					<strong><?php _e( 'ZipArchive (PHP):', 'wp-ambackup' ); ?></strong>
					<?php echo class_exists( 'ZipArchive' )
						? '<span style="color:#46b450;">✓ ' . __( 'Disponible', 'wp-ambackup' ) . '</span>'
						: '<span style="color:#dc3232;">✗ ' . __( 'No disponible (se usará PclZip)', 'wp-ambackup' ) . '</span>';
					?>
				</div>
				<div style="margin-top:10px;">
					<strong><?php _e( 'Límite de memoria PHP:', 'wp-ambackup' ); ?></strong>
					<?php echo esc_html( ini_get( 'memory_limit' ) ); ?>
				</div>
				<div style="margin-top:10px;">
					<strong><?php _e( 'Tiempo máximo de ejecución PHP:', 'wp-ambackup' ); ?></strong>
					<?php echo esc_html( ini_get( 'max_execution_time' ) ); ?>s
				</div>
			</div>
		</div>

		<button type="submit" class="wpamb-btn wpamb-btn--primary wpamb-btn--lg">
			<span class="dashicons dashicons-saved"></span>
			<?php _e( 'Guardar ajustes', 'wp-ambackup' ); ?>
		</button>
	</form>
</div>
