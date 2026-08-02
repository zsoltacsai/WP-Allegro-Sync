<?php
/**
 * Plugin Name: WP Allegro Sync
 * Plugin URI:  https://fountainbridge.hu
 * Description: WooCommerce termékek szinkronizálása az Allegro piactérrel (Allegro REST API, OAuth2 Device Code Flow). Kiválasztott termékek automatikus feltöltése/frissítése ajánlatként az Allegro-n.
 * Version:     260801.5
 * Author:      Fountainbridge
 * Author URI:  https://fountainbridge.hu
 * Text Domain: wp-allegro-sync
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verziózás: dátum alapú, YYMMDD formátum (pl. 260801 = 2026.08.01).
 * Minden éles kiadásnál frissítendő a fejlécben és itt egyaránt.
 */
define( 'FBAS_VERSION', '260801.5' );
define( 'FBAS_PLUGIN_FILE', __FILE__ );
define( 'FBAS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FBAS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Betöltési sorrend: a Logger és Settings a legtöbb más osztálytól függ, ezért ők előbb töltődnek be.
require_once FBAS_PLUGIN_DIR . 'includes/class-fbas-logger.php';
require_once FBAS_PLUGIN_DIR . 'includes/class-fbas-settings.php';
require_once FBAS_PLUGIN_DIR . 'includes/class-fbas-api-client.php';
require_once FBAS_PLUGIN_DIR . 'includes/class-fbas-product-mapper.php';
require_once FBAS_PLUGIN_DIR . 'includes/class-fbas-sync.php';
require_once FBAS_PLUGIN_DIR . 'includes/class-fbas-admin.php';

/**
 * Fő plugin osztály - inicializálja a komponenseket.
 */
final class FBAS_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		register_activation_hook( FBAS_PLUGIN_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( FBAS_PLUGIN_FILE, array( __CLASS__, 'deactivate' ) );
	}

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		load_plugin_textdomain( 'wp-allegro-sync', false, dirname( plugin_basename( FBAS_PLUGIN_FILE ) ) . '/languages' );

		FBAS_Admin::instance();
		FBAS_Sync::instance();
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'A WP Allegro Sync pluginhoz a WooCommerce aktiválása szükséges.', 'wp-allegro-sync' ) .
			'</p></div>';
	}

	public static function activate() {
		if ( ! wp_next_scheduled( 'fbas_cron_sync' ) ) {
			wp_schedule_event( time() + 300, 'fbas_sync_interval', 'fbas_cron_sync' );
		}
		if ( false === get_option( 'fbas_settings' ) ) {
			add_option( 'fbas_settings', FBAS_Settings::defaults() );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'fbas_cron_sync' );
	}
}

add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['fbas_sync_interval'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( '15 percenként (Allegro szinkron)', 'wp-allegro-sync' ),
	);
	return $schedules;
} );

FBAS_Plugin::instance();
