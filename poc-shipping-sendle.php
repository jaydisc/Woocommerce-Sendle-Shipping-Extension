<?php
/**
 * Plugin Name: Practice of Code Sendle shipping
 * Plugin URI: http://www.practiceofcode.com/
 * Description: Obtain parcel shipping rates for Sendle service
 * Version: 1.0.0
 * Author: Practice of Code
 * Author URI: http://www.practiceofcode.com/
 * Copyright: 2017 Jason Discount
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Required functions
 */
// if ( ! function_exists( 'woothemes_queue_update' ) ) {
// 	require_once( 'woo-includes/woo-functions.php' );
// }

/**
 * Plugin updates
 */
// woothemes_queue_update( plugin_basename( __FILE__ ), '1dbd4dc6bd91a9cda1bd6b9e7a5e4f43', '18622' );

class WC_Shipping_Sendle_Init {
	/**
	 * Plugin's version.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $version = '1.0.0';

	/** @var object Class Instance */
	private static $instance;

	/**
	 * Get the class instance
	 */
	public static function get_instance() {
		return null === self::$instance ? ( self::$instance = new self ) : self::$instance;
	}

	/**
	 * Initialize the plugin's public actions
	 */
	public function __construct() {
		if ( class_exists( 'WC_Shipping_Method' ) ) {
			add_action( 'admin_init', array( $this, 'maybe_install' ), 5 );
			add_action( 'init', array( $this, 'load_textdomain' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_links' ) );
			add_action( 'woocommerce_shipping_init', array( $this, 'includes' ) );
			add_filter( 'woocommerce_shipping_methods', array( $this, 'add_method' ) );
			add_action( 'admin_notices', array( $this, 'environment_check' ) );
		} else {
			add_action( 'admin_notices', array( $this, 'wc_deactivated' ) );
		}
	}

	/**
	 * environment_check function.
	 *
	 * @access public
	 * @return void
	 */
	public function environment_check() {
		if ( version_compare( WC_VERSION, '2.6.0', '<' ) ) {
			return;
		}

		if ( ! wc_shipping_enabled() ) {
			return;
		}

		$general_tab_link = admin_url( add_query_arg( array(
			'page'    => 'wc-settings',
			'tab'     => 'general',
		), 'admin.php' ) );

		if ( 'AUD' !== get_woocommerce_currency() ) {
			echo '<div class="error">
				<p>' . sprintf( __( 'This plugin requires that the %1$scurrency%2$s is set to Australian Dollars.', 'poc-shipping-sendle' ), '<a href="' . esc_url( $general_tab_link ) . '">', '</a>' ) . '</p>
			</div>';
		}

		if ( 'AU' !== WC()->countries->get_base_country() ) {
			echo '<div class="error">
				<p>' . wp_kses( sprintf( __( 'This plugin requires that the <a href="%s">base country/region</a> is set to Australia.', 'poc-shipping-sendle' ), esc_url( $general_tab_link ) ), array( 'a' => array( 'href' => array() ) ) ) . '</p>
			</div>';
		}
	}

	/**
	 * woocommerce_init_shipping_table_rate function.
	 *
	 * @access public
	 * @since 2.4.0
	 * @version 2.4.0
	 * @return void
	 */
	public function includes() {
		define( 'WC_SHIPPING_OFFICEWORKS_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );

		// if ( version_compare( WC_VERSION, '2.6.0', '<' ) ) {
		// 	include_once( dirname( __FILE__ ) . '/includes/class-wc-shipping-sendle-deprecated.php' );
		// } else {
			include_once( dirname( __FILE__ ) . '/includes/class-wc-shipping-sendle.php' );
		// }
	}

	/**
	 * Add Fedex shipping method to WC
	 *
	 * @access public
	 * @param mixed $methods
	 * @return void
	 */
	public function add_method( $methods ) {
		// if ( version_compare( WC_VERSION, '2.6.0', '<' ) ) {
		// 	$methods[] = 'WC_Shipping_Sendle';
		// } else {
			$methods['sendle'] = 'WC_Shipping_Sendle';
		// }

		return $methods;
	}

	/**
	 * Localisation
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'poc-shipping-sendle', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Plugin page links
	 */
	public function plugin_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=sendle' ) . '">' . __( 'Settings', 'poc-shipping-sendle' ) . '</a>'
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * WooCommerce not installed notice
	 */
	public function wc_deactivated() {
		echo '<div class="error"><p>' . sprintf( __( 'This plugin requires %s to be installed and active.', 'poc-shipping-sendle' ), '<a href="https://woocommerce.com" target="_blank">WooCommerce</a>' ) . '</p></div>';
	}

	/**
	 * Checks the plugin version
	 *
	 * @access public
	 * @since 2.4.0
	 * @version 2.4.0
	 * @return bool
	 */
	public function maybe_install() {
		// only need to do this for versions less than 2.4.0 to migrate
		// settings to shipping zone instance
		$doing_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;
		if ( ! $doing_ajax
		     && ! defined( 'IFRAME_REQUEST' )
		     && version_compare( WC_VERSION, '2.6.0', '>=' )
		     && version_compare( get_option( 'wc_sendle_version' ), '1.0.0', '<' ) ) {

			$this->install();

		}

		return true;
	}

	/**
	 * Update/migration script
	 *
	 * @since 2.4.0
	 * @version 2.4.0
	 * @access public
	 * @return bool
	 */
	public function install() {
		// get all saved settings and cache it
		$sendle_settings = get_option( 'poc_sendle_settings', false );

		// settings exists
		if ( $sendle_settings ) {
			global $wpdb;

			// unset un-needed settings
			unset( $sendle_settings['enabled'] );
			unset( $sendle_settings['availability'] );
			unset( $sendle_settings['countries'] );

			// first add it to the "rest of the world" zone when no Sendle
			// instance.
			if ( ! $this->is_zone_has_sendle( 0 ) ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}woocommerce_shipping_zone_methods ( zone_id, method_id, method_order, is_enabled ) VALUES ( %d, %s, %d, %d )", 0, 'sendle', 1, 1 ) );
				// add settings to the newly created instance to options table
				$instance = $wpdb->insert_id;
				add_option( 'poc_sendle_' . $instance . '_settings', $sendle_settings );
			}
			// update_option( 'poc_sendle_show_upgrade_notice', 'yes' );
		}
		update_option( 'wc_sendle_version', $this->version );
	}
}

add_action( 'plugins_loaded' , array( 'WC_Shipping_Sendle_Init' , 'get_instance' ), 0 );
