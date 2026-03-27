<?php
/**
 * Vista: Dashboard principal
 *
 * @package WP_AMBackup
 * @var array  $backups
 * @var string $next_run
 * @var int    $total
 * @var int    $total_size
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap wpamb-wrap">
	<h1 class="wpamb-title">
		<span class="dashicons dashicons-backup"></span>
		<?php _e( 'WP AMBackup', 'wp-ambackup' ); ?>
		<span class="wpamb-version">v<?php echo esc_html( WPAMB_VERSION ); ?></span>
	</h1>

	<!-- TARJETAS DE RESUMEN -->
	<div class="wpamb-cards">
		<div class="wpamb-card wpamb-card--blue">
			<div class="wpamb-card__icon"><span class="dashicons dashicons-archive"></span></div>
			<div class="wpamb-card__content">
				<span class="wpamb-card__number"><?php echo esc_html( $total ); ?></span>
				<span class="wpamb-card__label"><?php _e( 'Backups guardados', 'wp-ambackup' ); ?></span>
			</div>
		</div>
		<div class="wpamb-card wpamb-card--green">
			<div class="wpamb-card__icon"><span class="dashicons dashicons-database"></span></div>
			<div class="wpamb-card__content">
				<span class="wpamb-card__number"><?php echo esc_html( size_format( $total_size ) ); ?></span>
				<span class="wpamb-card__label"><?php _e( 'Espacio utilizado', 'wp-ambackup' ); ?></span>
			</div>
		</div>
		<div class="wpamb-card wpamb-card--orange">
			<div class="wpamb-card__icon"><span class="dashicons dashicons-clock"></span></div>
			<div class="wpamb-card__content">
				<span class="wpamb-card__number wpamb-card__number--sm"><?php echo esc_html( $next_run ); ?></span>
				<span class="wpamb-card__label"><?php _e( 'Próximo backup', 'wp-ambackup' ); ?></span>
			</div>
		</div>
		<div class="wpamb-card wpamb-card--purple">
			<div class="wpamb-card__icon"><span class="dashicons dashicons-yes-alt"></span></div>
			<div class="wpamb-card__content">
				<span class="wpamb-card__number"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
				<span class="wpamb-card__label"><?php _e( 'WordPress', 'wp-ambackup' ); ?></span>
			</div>
		</div>
	</div>

	<!-- CREAR BACKUP MANUAL -->
	<div class="wpamb-box wpamb-create-backup">
		<h2><?php _e( 'Crear Backup Ahora', 'wp-ambackup' ); ?></h2>
		<p><?php _e( 'Crea un backup completo de tu sitio web en este momento.', 'wp-ambackup' ); ?></p>

		<div class="wpamb-create-options">
			<label class="wpamb-checkbox">
				<input type="checkbox" id="wpamb-inc-files" checked>
				<span><?php _e( 'Archivos del sitio', 'wp-ambackup' ); ?></span>
			</label>
			<label class="wpamb-checkbox">
				<input type="checkbox" id="wpamb-inc-db" checked>
				<span><?php _e( 'Base de datos', 'wp-ambackup' ); ?></span>
			</label>
		</div>

		<button id="wpamb-create-btn" class="wpamb-btn wpamb-btn--primary wpamb-btn--lg">
			<span class="dashicons dashicons-backup"></span>
			<?php _e( 'Crear Backup', 'wp-ambackup' ); ?>
		</button>
		<button id="wpamb-cancel-btn" class="wpamb-btn wpamb-btn--danger" style="display:none;">
			<span class="dashicons dashicons-no"></span>
			<?php _e( 'Cancelar', 'wp-ambackup' ); ?>
		</button>

		<!-- Barra de progreso -->
		<div id="wpamb-progress-wrap" class="wpamb-progress-wrap" style="display:none;">
			<div class="wpamb-progress-bar">
				<div class="wpamb-progress-fill" id="wpamb-progress-fill"></div>
			</div>
			<p class="wpamb-progress-msg" id="wpamb-progress-msg"></p>
		</div>

		<!-- Resultado -->
		<div id="wpamb-create-result" class="wpamb-notice" style="display:none;"></div>
	</div>

	<!-- ÚLTIMOS BACKUPS -->
	<?php if ( ! empty( $backups ) ) : ?>
	<div class="wpamb-box">
		<h2><?php _e( 'Últimos Backups', 'wp-ambackup' ); ?></h2>
		<table class="wpamb-table widefat">
			<thead>
				<tr>
					<th><?php _e( 'Archivo', 'wp-ambackup' ); ?></th>
					<th><?php _e( 'Tamaño', 'wp-ambackup' ); ?></th>
					<th><?php _e( 'Tipo', 'wp-ambackup' ); ?></th>
					<th><?php _e( 'Estado', 'wp-ambackup' ); ?></th>
					<th><?php _e( 'Creado', 'wp-ambackup' ); ?></th>
					<th><?php _e( 'Acciones', 'wp-ambackup' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $backups, 0, 5 ) as $backup ) : ?>
				<tr data-id="<?php echo esc_attr( $backup['id'] ); ?>">
					<td class="wpamb-filename">
						<span class="dashicons dashicons-media-archive"></span>
						<?php echo esc_html( $backup['filename'] ); ?>
						<?php if ( ! $backup['exists'] ) : ?>
							<span class="wpamb-badge wpamb-badge--warning"><?php _e( 'Archivo no encontrado', 'wp-ambackup' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $backup['size_human'] ); ?></td>
					<td>
						<span class="wpamb-badge wpamb-badge--<?php echo esc_attr( $backup['type'] ); ?>">
							<?php echo esc_html( ucfirst( $backup['type'] ) ); ?>
						</span>
					</td>
					<td>
						<span class="wpamb-badge wpamb-badge--<?php echo 'completed' === $backup['status'] ? 'success' : 'error'; ?>">
							<?php echo esc_html( ucfirst( $backup['status'] ) ); ?>
						</span>
					</td>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $backup['created_at'] ) ) ); ?></td>
					<td class="wpamb-actions">
						<?php if ( $backup['exists'] ) : ?>
						<a href="<?php echo esc_url( $backup['download_url'] ); ?>" class="wpamb-btn wpamb-btn--sm wpamb-btn--secondary">
							<span class="dashicons dashicons-download"></span>
							<?php _e( 'Descargar', 'wp-ambackup' ); ?>
						</a>
						<?php endif; ?>
						<button class="wpamb-btn wpamb-btn--sm wpamb-btn--danger wpamb-delete-btn" data-id="<?php echo esc_attr( $backup['id'] ); ?>">
							<span class="dashicons dashicons-trash"></span>
							<?php _e( 'Eliminar', 'wp-ambackup' ); ?>
						</button>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $total > 5 ) : ?>
		<p style="margin-top:10px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ambackup-list' ) ); ?>" class="wpamb-btn wpamb-btn--secondary">
				<?php printf( __( 'Ver todos los backups (%d)', 'wp-ambackup' ), $total ); ?>
			</a>
		</p>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ACCESO RÁPIDO -->
	<div class="wpamb-quick-links">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ambackup-schedule' ) ); ?>" class="wpamb-quick-link">
			<span class="dashicons dashicons-calendar-alt"></span>
			<span><?php _e( 'Programar Backups', 'wp-ambackup' ); ?></span>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ambackup-import' ) ); ?>" class="wpamb-quick-link">
			<span class="dashicons dashicons-upload"></span>
			<span><?php _e( 'Importar Backup', 'wp-ambackup' ); ?></span>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ambackup-settings' ) ); ?>" class="wpamb-quick-link">
			<span class="dashicons dashicons-admin-settings"></span>
			<span><?php _e( 'Ajustes', 'wp-ambackup' ); ?></span>
		</a>
	</div>
</div>
