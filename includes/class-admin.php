<?php
/**
 * Interfaz de administración del plugin.
 *
 * @package WP_AMBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAMB_Admin {

	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . WPAMB_PLUGIN_BASE, array( $this, 'plugin_action_links' ) );
	}

	// -------------------------------------------------------------------------
	// MENÚ
	// -------------------------------------------------------------------------

	public function register_menu() {
		$capability = 'manage_options';
		$icon       = 'dashicons-backup';

		add_menu_page(
			__( 'WP AMBackup', 'wp-ambackup' ),
			__( 'WP AMBackup', 'wp-ambackup' ),
			$capability,
			'wp-ambackup',
			array( $this, 'page_dashboard' ),
			$icon,
			80
		);

		add_submenu_page(
			'wp-ambackup',
			__( 'Dashboard', 'wp-ambackup' ),
			__( 'Dashboard', 'wp-ambackup' ),
			$capability,
			'wp-ambackup',
			array( $this, 'page_dashboard' )
		);

		add_submenu_page(
			'wp-ambackup',
			__( 'Backups', 'wp-ambackup' ),
			__( 'Backups', 'wp-ambackup' ),
			$capability,
			'wp-ambackup-list',
			array( $this, 'page_backups' )
		);

		add_submenu_page(
			'wp-ambackup',
			__( 'Programar', 'wp-ambackup' ),
			__( 'Programar', 'wp-ambackup' ),
			$capability,
			'wp-ambackup-schedule',
			array( $this, 'page_schedule' )
		);

		add_submenu_page(
			'wp-ambackup',
			__( 'Importar', 'wp-ambackup' ),
			__( 'Importar', 'wp-ambackup' ),
			$capability,
			'wp-ambackup-import',
			array( $this, 'page_import' )
		);

		add_submenu_page(
			'wp-ambackup',
			__( 'Ajustes', 'wp-ambackup' ),
			__( 'Ajustes', 'wp-ambackup' ),
			$capability,
			'wp-ambackup-settings',
			array( $this, 'page_settings' )
		);
	}

	// -------------------------------------------------------------------------
	// ASSETS
	// -------------------------------------------------------------------------

	public function enqueue_assets( $hook ) {
		$pages = array(
			'toplevel_page_wp-ambackup',
			'wp-ambackup_page_wp-ambackup-list',
			'wp-ambackup_page_wp-ambackup-schedule',
			'wp-ambackup_page_wp-ambackup-import',
			'wp-ambackup_page_wp-ambackup-settings',
		);

		if ( ! in_array( $hook, $pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'wpamb-admin',
			WPAMB_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			WPAMB_VERSION
		);

		wp_enqueue_script(
			'wpamb-admin',
			WPAMB_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			WPAMB_VERSION,
			true
		);

		wp_localize_script( 'wpamb-admin', 'wpamb', array(
			'ajax_url'        => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'wpamb_nonce' ),
			'confirm_delete'  => __( '¿Estás seguro de que deseas eliminar este backup? Esta acción no se puede deshacer.', 'wp-ambackup' ),
			'confirm_restore' => __( '¡ATENCIÓN! Esto sobrescribirá tu base de datos y/o archivos actuales. ¿Deseas continuar?', 'wp-ambackup' ),
			'creating'        => __( 'Creando backup…', 'wp-ambackup' ),
			'uploading'       => __( 'Subiendo archivo…', 'wp-ambackup' ),
			'please_wait'     => __( 'Por favor espera…', 'wp-ambackup' ),
		) );
	}

	// -------------------------------------------------------------------------
	// PÁGINAS
	// -------------------------------------------------------------------------

	public function page_dashboard() {
		$backups   = wpamb()->backup_manager->get_backups();
		$next_run  = WPAMB_Scheduler::get_next_run_formatted();
		$total     = count( $backups );
		$total_size = array_sum( array_column( $backups, 'size' ) );

		include WPAMB_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	public function page_backups() {
		$backups = wpamb()->backup_manager->get_backups();
		include WPAMB_PLUGIN_DIR . 'admin/views/backup-list.php';
	}

	public function page_schedule() {
		$schedule = array(
			'enabled'        => get_option( 'wpamb_schedule_enabled', false ),
			'type'           => get_option( 'wpamb_schedule_type',    'daily' ),
			'time'           => get_option( 'wpamb_schedule_time',    '02:00' ),
			'day'            => get_option( 'wpamb_schedule_day',     1 ),
			'custom'         => get_option( 'wpamb_schedule_custom',  array( 'interval' => 1, 'unit' => 'days' ) ),
			'next_run'       => WPAMB_Scheduler::get_next_run_formatted(),
		);
		include WPAMB_PLUGIN_DIR . 'admin/views/schedule.php';
	}

	public function page_import() {
		include WPAMB_PLUGIN_DIR . 'admin/views/import.php';
	}

	public function page_settings() {
		// Procesar guardado
		if ( isset( $_POST['wpamb_settings_nonce'] ) && wp_verify_nonce( $_POST['wpamb_settings_nonce'], 'wpamb_settings' ) ) {
			$this->save_settings();
			add_settings_error( 'wpamb', 'saved', __( 'Ajustes guardados.', 'wp-ambackup' ), 'updated' );
		}
		$settings = $this->get_settings();
		include WPAMB_PLUGIN_DIR . 'admin/views/settings.php';
	}

	// -------------------------------------------------------------------------
	// AJUSTES
	// -------------------------------------------------------------------------

	private function save_settings() {
		$post = $_POST;

		update_option( 'wpamb_include_files',     ! empty( $post['include_files'] ) );
		update_option( 'wpamb_include_db',        ! empty( $post['include_db'] ) );
		update_option( 'wpamb_max_backups',       absint( $post['max_backups'] ?? 5 ) );
		update_option( 'wpamb_notification_email',sanitize_email( $post['notification_email'] ?? '' ) );
		update_option( 'wpamb_notify_on_success', ! empty( $post['notify_on_success'] ) );
		update_option( 'wpamb_notify_on_failure', ! empty( $post['notify_on_failure'] ) );
		update_option( 'wpamb_github_token',      sanitize_text_field( $post['github_token'] ?? '' ) );

		// Rutas a excluir
		$raw_paths = sanitize_textarea_field( $post['exclude_paths'] ?? '' );
		$paths     = array_filter( array_map( 'trim', explode( "\n", $raw_paths ) ) );
		update_option( 'wpamb_exclude_paths', $paths );
	}

	private function get_settings() {
		return array(
			'include_files'      => get_option( 'wpamb_include_files',      true ),
			'include_db'         => get_option( 'wpamb_include_db',         true ),
			'max_backups'        => get_option( 'wpamb_max_backups',         5 ),
			'notification_email' => get_option( 'wpamb_notification_email', get_option( 'admin_email' ) ),
			'notify_on_success'  => get_option( 'wpamb_notify_on_success',  false ),
			'notify_on_failure'  => get_option( 'wpamb_notify_on_failure',  true ),
			'exclude_paths'      => implode( "\n", (array) get_option( 'wpamb_exclude_paths', array() ) ),
			'github_token'       => get_option( 'wpamb_github_token', '' ),
		);
	}

	// -------------------------------------------------------------------------
	// ENLACES DEL PLUGIN
	// -------------------------------------------------------------------------

	public function plugin_action_links( $links ) {
		$custom = array(
			'<a href="' . admin_url( 'admin.php?page=wp-ambackup' ) . '">' . __( 'Dashboard', 'wp-ambackup' ) . '</a>',
			'<a href="' . admin_url( 'admin.php?page=wp-ambackup-settings' ) . '">' . __( 'Ajustes', 'wp-ambackup' ) . '</a>',
		);
		return array_merge( $custom, $links );
	}
}
