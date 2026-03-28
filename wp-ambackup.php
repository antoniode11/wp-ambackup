<?php
/**
 * Plugin Name: WP AMBackup
 * Plugin URI:  https://github.com/antoniode11/wp-ambackup
 * Description: Plugin completo de backup para WordPress. Crea, programa, importa y exporta backups de tu sitio web. Similar a All-in-One WP Migration.
 * Version:     1.1.2
 * Author:      Tu Nombre
 * Author URI:  https://tu-sitio.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ambackup
 * Domain Path: /languages
 *
 * @package WP_AMBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constantes del plugin
define( 'WPAMB_VERSION',     '1.1.2' );
define( 'WPAMB_PLUGIN_FILE', __FILE__ );
define( 'WPAMB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WPAMB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WPAMB_PLUGIN_BASE', plugin_basename( __FILE__ ) );

// Directorio de backups (fuera del webroot si es posible, sino dentro de uploads)
if ( ! defined( 'WPAMB_BACKUP_DIR' ) ) {
	$upload_dir = wp_upload_dir();
	define( 'WPAMB_BACKUP_DIR', $upload_dir['basedir'] . '/wp-ambackup/' );
	define( 'WPAMB_BACKUP_URL', $upload_dir['baseurl'] . '/wp-ambackup/' );
}

define( 'WPAMB_GITHUB_USER', 'antoniode11' );
define( 'WPAMB_GITHUB_REPO', 'wp-ambackup' );

/**
 * Clase principal del plugin.
 */
final class WP_AMBackup {

	/** @var WP_AMBackup|null */
	private static $instance = null;

	/** @var WPAMB_Admin */
	public $admin;

	/** @var WPAMB_Backup_Manager */
	public $backup_manager;

	/** @var WPAMB_Scheduler */
	public $scheduler;

	/** @var WPAMB_Exporter */
	public $exporter;

	/** @var WPAMB_Importer */
	public $importer;

	/**
	 * Singleton.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Carga los archivos necesarios.
	 */
	private function includes() {
		require_once WPAMB_PLUGIN_DIR . 'includes/class-backup-manager.php';
		require_once WPAMB_PLUGIN_DIR . 'includes/class-scheduler.php';
		require_once WPAMB_PLUGIN_DIR . 'includes/class-exporter.php';
		require_once WPAMB_PLUGIN_DIR . 'includes/class-importer.php';
		require_once WPAMB_PLUGIN_DIR . 'includes/class-github-updater.php';
		if ( is_admin() ) {
			require_once WPAMB_PLUGIN_DIR . 'includes/class-admin.php';
		}
	}

	/**
	 * Registra hooks de WordPress.
	 */
	private function init_hooks() {
		register_activation_hook( WPAMB_PLUGIN_FILE,   array( $this, 'activate' ) );
		register_deactivation_hook( WPAMB_PLUGIN_FILE, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Inicialización diferida (después de cargar plugins).
	 */
	public function init() {
		load_plugin_textdomain( 'wp-ambackup', false, dirname( WPAMB_PLUGIN_BASE ) . '/languages' );

		$this->backup_manager = new WPAMB_Backup_Manager();
		$this->scheduler      = new WPAMB_Scheduler();
		$this->exporter       = new WPAMB_Exporter();
		$this->importer       = new WPAMB_Importer();

		// Inicializar actualizador desde GitHub
		new WPAMB_GitHub_Updater(
			WPAMB_PLUGIN_FILE,
			WPAMB_GITHUB_USER,
			WPAMB_GITHUB_REPO
		);

		if ( is_admin() ) {
			$this->admin = new WPAMB_Admin();
		}

		// Hook AJAX (admin y frontend por seguridad)
		$this->register_ajax_hooks();
	}

	/**
	 * Registra todos los hooks AJAX.
	 */
	private function register_ajax_hooks() {
		$ajax_actions = array(
			'wpamb_create_backup',
			'wpamb_scan_files',
			'wpamb_backup_chunk',
			'wpamb_delete_backup',
			'wpamb_import_backup',
			'wpamb_save_schedule',
			'wpamb_get_progress',
			'wpamb_cancel_backup',
		);
		foreach ( $ajax_actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'handle_ajax' ) );
		}
	}

	/**
	 * Dispatcher AJAX central.
	 */
	public function handle_ajax() {
		$action = str_replace( 'wp_ajax_', '', current_action() );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'wp-ambackup' ) ), 403 );
		}

		switch ( $action ) {
			case 'wpamb_create_backup':
				check_ajax_referer( 'wpamb_nonce', 'nonce' );
				$this->backup_manager->create_backup_ajax();
				break;
			case 'wpamb_scan_files':
				check_ajax_referer( 'wpamb_nonce', 'nonce' );
				$this->backup_manager->scan_files_ajax();
				break;
			case 'wpamb_backup_chunk':
				check_ajax_referer( 'wpamb_nonce', 'nonce' );
				$this->backup_manager->process_chunk_ajax();
				break;
			case 'wpamb_delete_backup':
				check_ajax_referer( 'wpamb_nonce', 'nonce' );
				$this->backup_manager->delete_backup_ajax();
				break;
			case 'wpamb_import_backup':
				check_ajax_referer( 'wpamb_nonce', 'nonce' );
				$this->importer->import_ajax();
				break;
			case 'wpamb_save_schedule':
				check_ajax_referer( 'wpamb_nonce', 'nonce' );
				$this->scheduler->save_schedule_ajax();
				break;
			case 'wpamb_get_progress':
				// No nonce check: lectura de progreso en polling
				$this->backup_manager->get_progress_ajax();
				break;
			case 'wpamb_cancel_backup':
				check_ajax_referer( 'wpamb_nonce', 'nonce' );
				$this->backup_manager->cancel_backup_ajax();
				break;
		}
	}

	/**
	 * Activación del plugin.
	 */
	public function activate() {
		// Crear directorio de backups
		if ( ! file_exists( WPAMB_BACKUP_DIR ) ) {
			wp_mkdir_p( WPAMB_BACKUP_DIR );
		}
		// Archivo .htaccess para proteger la carpeta de backups
		$htaccess = WPAMB_BACKUP_DIR . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" );
		}
		// Archivo index.php vacío
		$index = WPAMB_BACKUP_DIR . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '<?php // Silence is golden.' );
		}

		// Opciones por defecto
		$defaults = array(
			'schedule_enabled'  => false,
			'schedule_type'     => 'daily',      // daily, weekly, monthly, custom
			'schedule_custom'   => array(
				'interval' => 1,
				'unit'     => 'days',             // days, weeks, months
			),
			'schedule_time'     => '02:00',
			'schedule_day'      => 1,             // 1=lunes para weekly, 1=día 1 para monthly
			'max_backups'       => 5,
			'include_files'     => true,
			'include_db'        => true,
			'exclude_paths'     => array(),
			'notification_email'=> get_option( 'admin_email' ),
			'notify_on_success' => false,
			'notify_on_failure' => true,
		);
		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( 'wpamb_' . $key ) ) {
				update_option( 'wpamb_' . $key, $value );
			}
		}

		// Tabla para log de backups
		global $wpdb;
		$table      = $wpdb->prefix . 'ambackup_log';
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			filename   VARCHAR(255)        NOT NULL,
			size       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			type       VARCHAR(50)         NOT NULL DEFAULT 'manual',
			status     VARCHAR(20)         NOT NULL DEFAULT 'completed',
			created_at DATETIME            NOT NULL,
			note       TEXT,
			PRIMARY KEY (id)
		) {$charset_collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Programar cron si estaba activo antes de desactivar
		if ( get_option( 'wpamb_schedule_enabled' ) ) {
			WPAMB_Scheduler::schedule_next();
		}
	}

	/**
	 * Desactivación del plugin.
	 */
	public function deactivate() {
		WPAMB_Scheduler::clear_schedule();
	}
}

/**
 * Función de acceso global al plugin.
 */
function wpamb() {
	return WP_AMBackup::instance();
}

// Iniciar el plugin
wpamb();
