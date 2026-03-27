<?php
/**
 * Vista: Programar backups
 *
 * @package WP_AMBackup
 * @var array $schedule
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$days_of_week = array(
	1 => __( 'Lunes',     'wp-ambackup' ),
	2 => __( 'Martes',    'wp-ambackup' ),
	3 => __( 'Miércoles', 'wp-ambackup' ),
	4 => __( 'Jueves',    'wp-ambackup' ),
	5 => __( 'Viernes',   'wp-ambackup' ),
	6 => __( 'Sábado',    'wp-ambackup' ),
	7 => __( 'Domingo',   'wp-ambackup' ),
);
?>
<div class="wrap wpamb-wrap">
	<h1 class="wpamb-title">
		<span class="dashicons dashicons-calendar-alt"></span>
		<?php _e( 'Programar Backups', 'wp-ambackup' ); ?>
	</h1>

	<div id="wpamb-schedule-result" class="wpamb-notice" style="display:none;"></div>

	<div class="wpamb-box">
		<form id="wpamb-schedule-form">

			<!-- Activar programación -->
			<div class="wpamb-field wpamb-field--toggle">
				<label class="wpamb-toggle">
					<input type="checkbox" name="schedule_enabled" id="schedule_enabled" value="1"
						<?php checked( $schedule['enabled'] ); ?>>
					<span class="wpamb-toggle__slider"></span>
					<span class="wpamb-toggle__label"><?php _e( 'Activar backups programados', 'wp-ambackup' ); ?></span>
				</label>
			</div>

			<div id="wpamb-schedule-options" class="<?php echo $schedule['enabled'] ? '' : 'wpamb-hidden'; ?>">

				<!-- Tipo de programación -->
				<div class="wpamb-field">
					<label class="wpamb-label"><?php _e( 'Tipo de programación', 'wp-ambackup' ); ?></label>
					<div class="wpamb-radio-group">
						<?php
						$types = array(
							'daily'   => __( 'Diario',       'wp-ambackup' ),
							'weekly'  => __( 'Semanal',      'wp-ambackup' ),
							'monthly' => __( 'Mensual',      'wp-ambackup' ),
							'custom'  => __( 'Personalizado','wp-ambackup' ),
						);
						foreach ( $types as $value => $label ) :
						?>
						<label class="wpamb-radio">
							<input type="radio" name="schedule_type" value="<?php echo esc_attr( $value ); ?>"
								<?php checked( $schedule['type'], $value ); ?>>
							<span><?php echo esc_html( $label ); ?></span>
						</label>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Hora de ejecución -->
				<div class="wpamb-field">
					<label class="wpamb-label" for="schedule_time">
						<?php _e( 'Hora de ejecución', 'wp-ambackup' ); ?>
					</label>
					<input type="time" name="schedule_time" id="schedule_time"
						   value="<?php echo esc_attr( $schedule['time'] ); ?>"
						   class="wpamb-input">
					<p class="wpamb-hint">
						<?php printf( __( 'Zona horaria del servidor: %s', 'wp-ambackup' ), esc_html( wp_timezone_string() ) ); ?>
					</p>
				</div>

				<!-- Día de la semana (solo para weekly) -->
				<div class="wpamb-field wpamb-schedule-weekly <?php echo 'weekly' !== $schedule['type'] ? 'wpamb-hidden' : ''; ?>">
					<label class="wpamb-label" for="schedule_day_weekly">
						<?php _e( 'Día de la semana', 'wp-ambackup' ); ?>
					</label>
					<select name="schedule_day" id="schedule_day_weekly" class="wpamb-select">
						<?php foreach ( $days_of_week as $num => $day_name ) : ?>
						<option value="<?php echo esc_attr( $num ); ?>"
							<?php selected( $schedule['day'], $num ); ?>>
							<?php echo esc_html( $day_name ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Día del mes (solo para monthly) -->
				<div class="wpamb-field wpamb-schedule-monthly <?php echo 'monthly' !== $schedule['type'] ? 'wpamb-hidden' : ''; ?>">
					<label class="wpamb-label" for="schedule_day_monthly">
						<?php _e( 'Día del mes', 'wp-ambackup' ); ?>
					</label>
					<select name="schedule_day" id="schedule_day_monthly" class="wpamb-select">
						<?php for ( $d = 1; $d <= 28; $d++ ) : ?>
						<option value="<?php echo esc_attr( $d ); ?>"
							<?php selected( $schedule['day'], $d ); ?>>
							<?php echo esc_html( $d ); ?>
						</option>
						<?php endfor; ?>
					</select>
					<p class="wpamb-hint"><?php _e( 'Máximo día 28 para compatibilidad con todos los meses.', 'wp-ambackup' ); ?></p>
				</div>

				<!-- Personalizado (custom) -->
				<div class="wpamb-field wpamb-schedule-custom <?php echo 'custom' !== $schedule['type'] ? 'wpamb-hidden' : ''; ?>">
					<label class="wpamb-label"><?php _e( 'Intervalo personalizado', 'wp-ambackup' ); ?></label>
					<div class="wpamb-input-group">
						<span><?php _e( 'Cada', 'wp-ambackup' ); ?></span>
						<input type="number" name="custom_interval" id="custom_interval"
							   value="<?php echo esc_attr( $schedule['custom']['interval'] ?? 1 ); ?>"
							   min="1" max="365" class="wpamb-input wpamb-input--sm">
						<select name="custom_unit" id="custom_unit" class="wpamb-select">
							<option value="days"   <?php selected( $schedule['custom']['unit'] ?? 'days', 'days' ); ?>>
								<?php _e( 'días', 'wp-ambackup' ); ?>
							</option>
							<option value="weeks"  <?php selected( $schedule['custom']['unit'] ?? '', 'weeks' ); ?>>
								<?php _e( 'semanas', 'wp-ambackup' ); ?>
							</option>
							<option value="months" <?php selected( $schedule['custom']['unit'] ?? '', 'months' ); ?>>
								<?php _e( 'meses', 'wp-ambackup' ); ?>
							</option>
						</select>
					</div>
				</div>

			</div><!-- /schedule-options -->

			<!-- Próximo backup -->
			<div class="wpamb-field wpamb-next-run">
				<span class="dashicons dashicons-info"></span>
				<?php _e( 'Próximo backup programado:', 'wp-ambackup' ); ?>
				<strong id="wpamb-next-run"><?php echo esc_html( $schedule['next_run'] ); ?></strong>
			</div>

			<button type="submit" class="wpamb-btn wpamb-btn--primary">
				<span class="dashicons dashicons-saved"></span>
				<?php _e( 'Guardar programación', 'wp-ambackup' ); ?>
			</button>
		</form>
	</div>
</div>
