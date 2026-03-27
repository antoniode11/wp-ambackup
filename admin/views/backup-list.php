<?php
/**
 * Vista: Lista completa de backups
 *
 * @package WP_AMBackup
 * @var array $backups
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap wpamb-wrap">
	<h1 class="wpamb-title">
		<span class="dashicons dashicons-archive"></span>
		<?php _e( 'Mis Backups', 'wp-ambackup' ); ?>
	</h1>

	<div class="wpamb-toolbar">
		<button id="wpamb-create-btn" class="wpamb-btn wpamb-btn--primary">
			<span class="dashicons dashicons-backup"></span>
			<?php _e( 'Crear Backup Ahora', 'wp-ambackup' ); ?>
		</button>
		<label class="wpamb-checkbox wpamb-toolbar__check">
			<input type="checkbox" id="wpamb-inc-files" checked>
			<span><?php _e( 'Archivos', 'wp-ambackup' ); ?></span>
		</label>
		<label class="wpamb-checkbox wpamb-toolbar__check">
			<input type="checkbox" id="wpamb-inc-db" checked>
			<span><?php _e( 'Base de datos', 'wp-ambackup' ); ?></span>
		</label>
	</div>

	<!-- Progreso -->
	<div id="wpamb-progress-wrap" class="wpamb-progress-wrap" style="display:none;">
		<div class="wpamb-progress-bar">
			<div class="wpamb-progress-fill" id="wpamb-progress-fill"></div>
		</div>
		<p class="wpamb-progress-msg" id="wpamb-progress-msg"></p>
		<button id="wpamb-cancel-btn" class="wpamb-btn wpamb-btn--danger wpamb-btn--sm">
			<?php _e( 'Cancelar', 'wp-ambackup' ); ?>
		</button>
	</div>
	<div id="wpamb-create-result" class="wpamb-notice" style="display:none;"></div>

	<div class="wpamb-box">
		<?php if ( empty( $backups ) ) : ?>
			<div class="wpamb-empty">
				<span class="dashicons dashicons-archive wpamb-empty__icon"></span>
				<p><?php _e( 'No hay backups todavía. ¡Crea tu primer backup!', 'wp-ambackup' ); ?></p>
			</div>
		<?php else : ?>
		<table class="wpamb-table widefat striped">
			<thead>
				<tr>
					<th><input type="checkbox" id="wpamb-select-all"></th>
					<th><?php _e( 'Archivo', 'wp-ambackup' ); ?></th>
					<th><?php _e( 'Tamaño', 'wp-ambackup' ); ?></th>
					<th><?php _e( 'Tipo', 'wp-ambackup' ); ?></th>
					<th><?php _e( 'Estado', 'wp-ambackup' ); ?></th>
					<th><?php _e( 'Creado', 'wp-ambackup' ); ?></th>
					<th><?php _e( 'Acciones', 'wp-ambackup' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpamb-backup-tbody">
				<?php foreach ( $backups as $backup ) : ?>
				<tr data-id="<?php echo esc_attr( $backup['id'] ); ?>">
					<td><input type="checkbox" class="wpamb-row-check" value="<?php echo esc_attr( $backup['id'] ); ?>"></td>
					<td class="wpamb-filename">
						<span class="dashicons dashicons-media-archive"></span>
						<span class="wpamb-filename__text" title="<?php echo esc_attr( $backup['filename'] ); ?>">
							<?php echo esc_html( $backup['filename'] ); ?>
						</span>
						<?php if ( ! $backup['exists'] ) : ?>
							<span class="wpamb-badge wpamb-badge--warning"><?php _e( 'Archivo no encontrado', 'wp-ambackup' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $backup['size_human'] ); ?></td>
					<td>
						<span class="wpamb-badge wpamb-badge--<?php echo esc_attr( $backup['type'] ); ?>">
							<?php
							$type_labels = array(
								'manual'    => __( 'Manual', 'wp-ambackup' ),
								'scheduled' => __( 'Programado', 'wp-ambackup' ),
								'imported'  => __( 'Importado', 'wp-ambackup' ),
							);
							echo esc_html( $type_labels[ $backup['type'] ] ?? ucfirst( $backup['type'] ) );
							?>
						</span>
					</td>
					<td>
						<span class="wpamb-badge wpamb-badge--<?php echo 'completed' === $backup['status'] ? 'success' : 'error'; ?>">
							<?php echo 'completed' === $backup['status'] ? __( 'Completado', 'wp-ambackup' ) : __( 'Error', 'wp-ambackup' ); ?>
						</span>
					</td>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $backup['created_at'] ) ) ); ?></td>
					<td class="wpamb-actions">
						<?php if ( $backup['exists'] ) : ?>
						<a href="<?php echo esc_url( $backup['download_url'] ); ?>"
						   class="wpamb-btn wpamb-btn--sm wpamb-btn--secondary"
						   title="<?php esc_attr_e( 'Descargar backup', 'wp-ambackup' ); ?>">
							<span class="dashicons dashicons-download"></span>
						</a>
						<?php endif; ?>
						<button class="wpamb-btn wpamb-btn--sm wpamb-btn--danger wpamb-delete-btn"
								data-id="<?php echo esc_attr( $backup['id'] ); ?>"
								title="<?php esc_attr_e( 'Eliminar backup', 'wp-ambackup' ); ?>">
							<span class="dashicons dashicons-trash"></span>
						</button>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!-- Acciones en lote -->
		<div class="wpamb-bulk-actions" style="margin-top:10px; display:none;" id="wpamb-bulk-bar">
			<button id="wpamb-bulk-delete" class="wpamb-btn wpamb-btn--danger wpamb-btn--sm">
				<span class="dashicons dashicons-trash"></span>
				<?php _e( 'Eliminar seleccionados', 'wp-ambackup' ); ?>
			</button>
		</div>
		<?php endif; ?>
	</div>
</div>
