<?php
/**
 * Actualizador automático desde GitHub.
 * Permite que WordPress detecte nuevas versiones del plugin en GitHub
 * y las instale desde el panel de administración.
 *
 * @package WP_AMBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAMB_GitHub_Updater {

	/** @var string Ruta al archivo principal del plugin */
	private $plugin_file;

	/** @var string Slug del plugin (carpeta/archivo.php) */
	private $plugin_slug;

	/** @var string Usuario de GitHub */
	private $github_user;

	/** @var string Repositorio de GitHub */
	private $github_repo;

	/** @var string Token de acceso personal de GitHub (opcional, para repos privados) */
	private $github_token;

	/** @var object|null Caché de la respuesta de la API de GitHub */
	private $github_response = null;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file  Ruta al archivo principal del plugin (__FILE__).
	 * @param string $github_user  Usuario/organización de GitHub.
	 * @param string $github_repo  Nombre del repositorio.
	 * @param string $github_token Token opcional para repos privados.
	 */
	public function __construct( $plugin_file, $github_user, $github_repo, $github_token = '' ) {
		$this->plugin_file  = $plugin_file;
		$this->plugin_slug  = plugin_basename( $plugin_file );
		$this->github_user  = $github_user;
		$this->github_repo  = $github_repo;
		$this->github_token = $github_token ?: get_option( 'wpamb_github_token', '' );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install',                 array( $this, 'after_install' ), 10, 3 );
		add_action( 'upgrader_process_complete',             array( $this, 'purge_cache' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// API DE GITHUB
	// -------------------------------------------------------------------------

	/**
	 * Obtiene la información del último release de GitHub.
	 *
	 * @return object|false
	 */
	private function get_latest_release() {
		if ( $this->github_response !== null ) {
			return $this->github_response;
		}

		// Intentar desde caché transitoria (12 horas)
		$cache_key = 'wpamb_github_release_' . md5( $this->github_user . $this->github_repo );
		$cached    = get_transient( $cache_key );
		if ( $cached ) {
			$this->github_response = $cached;
			return $cached;
		}

		$url  = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
		$args = array(
			'timeout'    => 15,
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_site_url(),
			'headers'    => array( 'Accept' => 'application/vnd.github.v3+json' ),
		);

		if ( $this->github_token ) {
			$args['headers']['Authorization'] = 'token ' . $this->github_token;
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! isset( $body->tag_name ) ) {
			return false;
		}

		$this->github_response = $body;
		set_transient( $cache_key, $body, 12 * HOUR_IN_SECONDS );

		return $body;
	}

	/**
	 * Limpia la versión semántica (quita 'v' inicial).
	 *
	 * @param string $tag  Tag de GitHub (ej: "v1.2.3").
	 * @return string      Versión limpia (ej: "1.2.3").
	 */
	private function clean_version( $tag ) {
		return ltrim( $tag, 'vV' );
	}

	// -------------------------------------------------------------------------
	// HOOKS DE ACTUALIZACIÓN
	// -------------------------------------------------------------------------

	/**
	 * Inyecta la información de actualización en el transient de WordPress.
	 *
	 * @param object $transient
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest_version  = $this->clean_version( $release->tag_name );
		$current_version = $this->get_current_version();

		if ( version_compare( $latest_version, $current_version, '>' ) ) {
			$download_url = $this->get_download_url( $release );

			$plugin_info = array(
				'id'          => $this->plugin_slug,
				'slug'        => dirname( $this->plugin_slug ),
				'plugin'      => $this->plugin_slug,
				'new_version' => $latest_version,
				'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
				'package'     => $download_url,
				'icons'       => array(),
				'banners'     => array(),
				'tested'      => '',
				'requires_php'=> '',
			);

			$transient->response[ $this->plugin_slug ] = (object) $plugin_info;
		} else {
			// Indicar que está actualizado
			$transient->checked[ $this->plugin_slug ] = $current_version;
		}

		return $transient;
	}

	/**
	 * Rellena la información del plugin cuando WordPress la solicita (modal "Ver detalles").
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$plugin_data = get_plugin_data( $this->plugin_file );

		return (object) array(
			'name'          => $plugin_data['Name'],
			'slug'          => dirname( $this->plugin_slug ),
			'version'       => $this->clean_version( $release->tag_name ),
			'author'        => $plugin_data['Author'],
			'author_profile'=> $plugin_data['AuthorURI'],
			'homepage'      => "https://github.com/{$this->github_user}/{$this->github_repo}",
			'short_description' => $plugin_data['Description'],
			'sections'      => array(
				'description' => $plugin_data['Description'],
				'changelog'   => isset( $release->body ) ? nl2br( esc_html( $release->body ) ) : '',
			),
			'download_link' => $this->get_download_url( $release ),
			'last_updated'  => $release->published_at ?? '',
			'requires'      => '5.0',
			'tested'        => get_bloginfo( 'version' ),
			'requires_php'  => '7.4',
			'banners'       => array(),
			'icons'         => array(),
		);
	}

	/**
	 * Renombra el directorio del plugin después de la instalación
	 * (GitHub genera un nombre de carpeta con hash).
	 *
	 * @param bool  $response   Si la instalación fue exitosa.
	 * @param array $hook_extra Información extra.
	 * @param array $result     Resultado de la instalación.
	 * @return array
	 */
	public function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
			return $result;
		}

		$install_dir = plugin_dir_path( $this->plugin_file );
		$wp_filesystem->move( $result['destination'], $install_dir );
		$result['destination'] = $install_dir;

		if ( is_plugin_active( $this->plugin_slug ) ) {
			activate_plugin( $this->plugin_slug );
		}

		return $result;
	}

	/**
	 * Limpia la caché del updater después de una actualización.
	 *
	 * @param WP_Upgrader $upgrader
	 * @param array       $options
	 */
	public function purge_cache( $upgrader, $options ) {
		if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			$cache_key = 'wpamb_github_release_' . md5( $this->github_user . $this->github_repo );
			delete_transient( $cache_key );
			$this->github_response = null;
		}
	}

	// -------------------------------------------------------------------------
	// UTILIDADES
	// -------------------------------------------------------------------------

	/**
	 * Obtiene la versión actual del plugin desde sus cabeceras.
	 *
	 * @return string
	 */
	private function get_current_version() {
		$plugin_data = get_plugin_data( $this->plugin_file, false, false );
		return $plugin_data['Version'] ?? WPAMB_VERSION;
	}

	/**
	 * Obtiene la URL de descarga del ZIP del release.
	 * Primero busca un asset llamado plugin.zip; si no, usa el zipball automático de GitHub.
	 *
	 * @param object $release Objeto de release de GitHub.
	 * @return string
	 */
	private function get_download_url( $release ) {
		// Buscar asset específico del plugin
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( isset( $asset->name ) && preg_match( '/\.zip$/i', $asset->name ) ) {
					$url = $asset->browser_download_url;
					if ( $this->github_token ) {
						// Para repos privados usar la API
						$url = $asset->url;
					}
					return $url;
				}
			}
		}

		// Fallback: zipball automático
		return "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/tags/{$release->tag_name}.zip";
	}
}
