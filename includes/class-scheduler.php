<?php
/**
 * Gestión de programación de backups.
 *
 * @package WP_AMBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAMB_Scheduler {

	/** Nombre del evento de cron */
	const CRON_HOOK = 'wpamb_scheduled_backup';

	public function __construct() {
		// Filtro para registrar intervalos personalizados de WP-Cron
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );
	}

	// -------------------------------------------------------------------------
	// PROGRAMACIÓN
	// -------------------------------------------------------------------------

	/**
	 * Programa el siguiente backup.
	 * Calcula el timestamp del próximo disparo según la configuración guardada.
	 */
	public static function schedule_next() {
		// Limpiar cualquier evento pendiente anterior
		self::clear_schedule();

		if ( ! get_option( 'wpamb_schedule_enabled' ) ) {
			return;
		}

		$timestamp = self::calculate_next_timestamp();
		if ( $timestamp ) {
			wp_schedule_single_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Cancela el cron programado.
	 */
	public static function clear_schedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Calcula el timestamp del próximo backup según la configuración.
	 *
	 * @return int|false  Timestamp UNIX o false si no se puede calcular.
	 */
	public static function calculate_next_timestamp() {
		$type        = get_option( 'wpamb_schedule_type',   'daily' );
		$time_str    = get_option( 'wpamb_schedule_time',   '02:00' );
		$day_setting = (int) get_option( 'wpamb_schedule_day', 1 );
		$custom      = (array) get_option( 'wpamb_schedule_custom', array( 'interval' => 1, 'unit' => 'days' ) );

		// Obtener hora configurada
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $time_str ) );

		$tz   = new DateTimeZone( wp_timezone_string() );
		$now  = new DateTime( 'now', $tz );
		$next = new DateTime( 'now', $tz );
		$next->setTime( $hour, $minute, 0 );

		switch ( $type ) {

			case 'daily':
				// Si ya pasó la hora hoy, mover al día siguiente
				if ( $next <= $now ) {
					$next->modify( '+1 day' );
				}
				break;

			case 'weekly':
				// $day_setting: 1=lunes … 7=domingo (ISO)
				$current_dow = (int) $now->format( 'N' ); // 1=Mon … 7=Sun
				$diff_days   = $day_setting - $current_dow;
				if ( $diff_days < 0 || ( $diff_days === 0 && $next <= $now ) ) {
					$diff_days += 7;
				}
				if ( $diff_days > 0 ) {
					$next->modify( "+{$diff_days} days" );
				}
				break;

			case 'monthly':
				// $day_setting: día del mes (1-28)
				$target_day = min( $day_setting, (int) $next->format( 't' ) );
				$next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'm' ), $target_day );
				if ( $next <= $now ) {
					$next->modify( '+1 month' );
					$target_day = min( $day_setting, (int) $next->format( 't' ) );
					$next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'm' ), $target_day );
				}
				break;

			case 'custom':
				$interval = max( 1, (int) ( $custom['interval'] ?? 1 ) );
				$unit     = in_array( $custom['unit'] ?? 'days', array( 'days', 'weeks', 'months' ), true )
					? $custom['unit'] : 'days';

				if ( $next <= $now ) {
					$next->modify( "+{$interval} {$unit}" );
				}
				break;

			default:
				return false;
		}

		return $next->getTimestamp();
	}

	// -------------------------------------------------------------------------
	// INTERVALOS DE CRON PERSONALIZADOS
	// -------------------------------------------------------------------------

	/**
	 * Registra intervalos personalizados para el cron de WordPress.
	 * (Solo se usan si se quiere usar wp_schedule_recurring_event en el futuro)
	 */
	public function add_cron_intervals( $schedules ) {
		$schedules['wpamb_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'WP AMBackup - Semanal', 'wp-ambackup' ),
		);
		$schedules['wpamb_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'WP AMBackup - Mensual (30 días)', 'wp-ambackup' ),
		);
		return $schedules;
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	/**
	 * Guarda la configuración de programación y reprograma el cron.
	 */
	public function save_schedule_ajax() {
		$post = $_POST;

		$enabled     = ! empty( $post['schedule_enabled'] );
		$type        = in_array( $post['schedule_type'] ?? '', array( 'daily', 'weekly', 'monthly', 'custom' ), true )
			? $post['schedule_type'] : 'daily';
		$time        = sanitize_text_field( $post['schedule_time'] ?? '02:00' );
		$day         = absint( $post['schedule_day'] ?? 1 );
		$custom_int  = max( 1, absint( $post['custom_interval'] ?? 1 ) );
		$custom_unit = in_array( $post['custom_unit'] ?? 'days', array( 'days', 'weeks', 'months' ), true )
			? $post['custom_unit'] : 'days';

		// Validar formato de hora HH:MM
		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			$time = '02:00';
		}

		update_option( 'wpamb_schedule_enabled', $enabled );
		update_option( 'wpamb_schedule_type',    $type );
		update_option( 'wpamb_schedule_time',    $time );
		update_option( 'wpamb_schedule_day',     $day );
		update_option( 'wpamb_schedule_custom',  array( 'interval' => $custom_int, 'unit' => $custom_unit ) );

		// Reprogramar
		if ( $enabled ) {
			self::schedule_next();
			$next     = wp_next_scheduled( self::CRON_HOOK );
			$next_str = $next ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) : '—';
		} else {
			self::clear_schedule();
			$next_str = '—';
		}

		wp_send_json_success( array(
			'message'  => __( 'Programación guardada correctamente.', 'wp-ambackup' ),
			'next_run' => $next_str,
		) );
	}

	// -------------------------------------------------------------------------
	// INFO
	// -------------------------------------------------------------------------

	/**
	 * Devuelve el próximo backup programado formateado.
	 */
	public static function get_next_run_formatted() {
		if ( ! get_option( 'wpamb_schedule_enabled' ) ) {
			return __( 'Programación desactivada', 'wp-ambackup' );
		}
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( ! $next ) {
			return __( 'No programado', 'wp-ambackup' );
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next );
	}
}
