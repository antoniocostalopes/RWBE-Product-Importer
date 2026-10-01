<?php
/**
 * Plugin Name: RWBE Product Importer
 * Description: Imports products from Race Winning Brands Europe (RWBE) API to WooCommerce.
 * Version: 1.2.4
 * Author: António Lopes
 * Author URI: https://www.antoniolopes.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rwbe-product-importer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 3.0
 * WC tested up to: 11.0
 * WooCommerce: compatible
 *
 * @package RWBE_Product_Importer
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants (guarded to avoid collisions and allow overrides)
if ( ! defined( 'RWBE_PRODUCT_IMPORTER_VERSION' ) ) {
	define( 'RWBE_PRODUCT_IMPORTER_VERSION', '1.2.4' );
}
if ( ! defined( 'RWBE_PRODUCT_IMPORTER_PLUGIN_DIR' ) ) {
	define( 'RWBE_PRODUCT_IMPORTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'RWBE_PRODUCT_IMPORTER_PLUGIN_URL' ) ) {
	define( 'RWBE_PRODUCT_IMPORTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'RWBE_API_ENDPOINT' ) ) {
	define( 'RWBE_API_ENDPOINT', 'https://portal.racewinningbrandseurope.com/apiv2/products/' );
}
// Additional API endpoints.
if ( ! defined( 'RWBE_API_ENDPOINT_PRODUCT_DETAIL' ) ) {
	define( 'RWBE_API_ENDPOINT_PRODUCT_DETAIL', 'https://portal.racewinningbrandseurope.com/apiv2/products/product' );
}
if ( ! defined( 'RWBE_API_ENDPOINT_STOCK' ) ) {
	define( 'RWBE_API_ENDPOINT_STOCK', 'https://portal.racewinningbrandseurope.com/apiv2/stock' );
}
if ( ! defined( 'RWBE_API_ENDPOINT_YMM' ) ) {
	define( 'RWBE_API_ENDPOINT_YMM', 'https://portal.racewinningbrandseurope.com/apiv2/ymm' );
}
if ( ! defined( 'RWBE_API_ENDPOINT_GROUPS' ) ) {
	define( 'RWBE_API_ENDPOINT_GROUPS', 'https://portal.racewinningbrandseurope.com/apiv2/groups' );
}
if ( ! defined( 'RWBE_API_ENDPOINT_BRANDS' ) ) {
	define( 'RWBE_API_ENDPOINT_BRANDS', 'https://portal.racewinningbrandseurope.com/apiv2/brands' );
}
if ( ! defined( 'RWBE_API_ENDPOINT_BRAND_DETAIL' ) ) {
	define( 'RWBE_API_ENDPOINT_BRAND_DETAIL', 'https://portal.racewinningbrandseurope.com/apiv2/brands/brand' );
}

/**
 * Get the RWBE API token.
 *
 * The token is NEVER hard-coded in the plugin. It must be supplied by the site
 * owner, in one of two places:
 *
 *   1. The 'API Token' field in the plugin settings screen (stored in the
 *      'rwbe_api_token' option).
 *   2. The RWBE_API_AUTH_TOKEN constant defined in wp-config.php, which takes
 *      priority and is useful when the credential is managed outside the DB.
 *
 * Returns an empty string when no token has been configured; callers must treat
 * that as "not configured" and abort instead of calling the API.
 *
 * @return string
 */
function rwbe_get_api_token() {
	if ( defined( 'RWBE_API_AUTH_TOKEN' ) && is_string( RWBE_API_AUTH_TOKEN ) && trim( RWBE_API_AUTH_TOKEN ) !== '' ) {
		return trim( RWBE_API_AUTH_TOKEN );
	}
	if ( function_exists( 'get_option' ) ) {
		$opt = get_option( 'rwbe_api_token', '' );
		if ( is_string( $opt ) && trim( $opt ) !== '' ) {
			return trim( $opt );
		}
	}
	return '';
}

/**
 * Whether an API token has been configured.
 *
 * @return bool
 */
function rwbe_has_api_token() {
	return rwbe_get_api_token() !== '';
}

/**
 * One-time migration: stop autoloading the plugin's large/transient options.
 *
 * Earlier versions created these options with autoload='yes', so they were loaded
 * on every WordPress request (including the storefront). This flips them to
 * autoload='no' once. update_option() only changes autoload when explicitly told,
 * so this stays fixed for existing rows.
 *
 * Why there is a v2: the v1 pass ran once, but the import loop then deleted and
 * re-created 'rwbe_import_progress' with update_option() *without* the autoload
 * argument, so the row came back autoloaded — a multi-kilobyte blob in alloptions
 * on every storefront request, rewritten every ten products during an import. The
 * call sites now pass autoload=false; this pass repairs rows written before that.
 */
function rwbe_maybe_fix_option_autoload() {
	if ( get_option( 'rwbe_autoload_fixed_v2' ) ) {
		return;
	}

	global $wpdb;
	$keys = array(
		'rwbe_recent_product_logs',
		'rwbe_import_progress',
		'rwbe_import_started_at',
		'rwbe_fitment_backfill_state',
		'rwbe_last_import_time',
		'rwbe_last_cron_import_time',
		'rwbe_last_full_sync_time',
	);
	foreach ( $keys as $key ) {
		// 'no' is excluded from the autoload set on every supported WordPress
		// version (which is not true of the newer 'off' on WP < 6.6).
		$wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $key ) );
	}

	wp_cache_delete( 'alloptions', 'options' );

	// This flag stays autoloaded on purpose: it is a single byte, and the guard
	// above then costs nothing instead of a DB read on every admin request.
	update_option( 'rwbe_autoload_fixed_v2', 1 );
}
add_action( 'admin_init', 'rwbe_maybe_fix_option_autoload' );
// Also run from the import cron: on a site where nobody opens wp-admin, the
// repair would otherwise never happen.
add_action( 'rwbe_product_import_cron', 'rwbe_maybe_fix_option_autoload', 1 );

/**
 * Whether WooCommerce is installed, active and loaded.
 *
 * Registers an admin notice and returns false when it is not, so the caller can
 * simply not boot.
 *
 * @return bool
 */
function rwbe_check_woocommerce_active() {
	// Check if WooCommerce is installed and active.
	if (
		// 'active_plugins' is a WordPress core filter, not one this plugin invents, so
		// the prefix rule does not apply; reading it is the standard way to test whether
		// another plugin is active.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) &&
		! ( is_multisite() && array_key_exists( 'woocommerce/woocommerce.php', get_site_option( 'active_sitewide_plugins', array() ) ) )
	) {
		add_action( 'admin_notices', 'rwbe_woocommerce_missing_notice' );
		RWBE_Debug_Logger::log( 'WooCommerce is not active' );
		return false;
	}

	// Check if WooCommerce classes are available.
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product' ) ) {
		add_action( 'admin_notices', 'rwbe_woocommerce_classes_missing_notice' );
		RWBE_Debug_Logger::log( 'WooCommerce classes are not available' );
		return false;
	}

	RWBE_Debug_Logger::log( 'WooCommerce is active and available' );
	return true;
}

/**
 * Admin notice shown when WooCommerce is not active.
 *
 * @return void
 */
function rwbe_woocommerce_missing_notice() {
	?>
	<div class="error">
		<p><?php esc_html_e( 'RWBE Product Importer requires WooCommerce to be installed and active.', 'rwbe-product-importer' ); ?></p>
	</div>
	<?php
}

/**
 * Admin notice shown when WooCommerce is active but its classes are unavailable.
 *
 * @return void
 */
function rwbe_woocommerce_classes_missing_notice() {
	?>
	<div class="error">
		<p><?php esc_html_e( 'RWBE Product Importer requires WooCommerce classes to be available. Please check if WooCommerce is properly installed.', 'rwbe-product-importer' ); ?></p>
	</div>
	<?php
}

// Include required files.
require_once RWBE_PRODUCT_IMPORTER_PLUGIN_DIR . 'includes/class-rwbe-debug-logger.php';
require_once RWBE_PRODUCT_IMPORTER_PLUGIN_DIR . 'includes/class-rwbe-generic-attribute-helper.php';
require_once RWBE_PRODUCT_IMPORTER_PLUGIN_DIR . 'includes/class-rwbe-product-importer.php';
require_once RWBE_PRODUCT_IMPORTER_PLUGIN_DIR . 'includes/class-rwbe-product-importer-admin.php';
require_once RWBE_PRODUCT_IMPORTER_PLUGIN_DIR . 'includes/class-rwbe-product-importer-live-log.php';
require_once RWBE_PRODUCT_IMPORTER_PLUGIN_DIR . 'includes/class-rwbe-fitment.php';
require_once RWBE_PRODUCT_IMPORTER_PLUGIN_DIR . 'includes/class-rwbe-vehicle-map.php';
require_once RWBE_PRODUCT_IMPORTER_PLUGIN_DIR . 'includes/class-rwbe-product-importer-search.php';
require_once RWBE_PRODUCT_IMPORTER_PLUGIN_DIR . 'includes/class-rwbe-vehicle-filter-widget.php';

// Initialize the debug logger.
RWBE_Debug_Logger::init();

// Background rebuild of the static make/model/year map used by the front-end
// filters. Scheduled whenever the files are missing or an import finishes, so the
// work never happens inside a visitor's request.
add_action( 'rwbe_vehicle_map_rebuild', array( 'RWBE_Vehicle_Map', 'rebuild' ) );

// After an import finishes, refresh the map immediately (priority 100 runs after
// the transient flushers at 99) so the filters come back warm instead of cold.
// The fitment coverage cache is dropped first, at 98, so the map builder sees the
// rows the import just wrote.
add_action( 'rwbe_product_import_cron', array( 'RWBE_Fitment', 'flush_coverage_cache' ), 98 );
add_action( 'rwbe_product_import_cron', array( 'RWBE_Vehicle_Map', 'rebuild_after_import' ), 100 );

// Make sure the fitment table exists (creates it once, then a single option read).
// Also checked at the head of the import cron, which does not run through admin_init.
add_action( 'admin_init', array( 'RWBE_Fitment', 'maybe_install' ) );
add_action( 'rwbe_product_import_cron', array( 'RWBE_Fitment', 'maybe_install' ), 1 );

// Keep fitment rows in step with the catalogue.
add_action(
	'before_delete_post',
	function ( $post_id ) {
		if ( get_post_type( $post_id ) === 'product' ) {
			RWBE_Fitment::delete_for_product( $post_id );
		}
	}
);

/**
 * Register the plugin's custom cron recurrences.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function rwbe_add_cron_schedules( $schedules ) {
	// Adicionar intervalo de 5 minutos.
	$schedules['rwbe_five_minutes'] = array(
		'interval'            => 300,
		// 5 minutos em segundos
					'display' => __( 'Every 5 minutes', 'rwbe-product-importer' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'rwbe_add_cron_schedules' );

/**
 * Declare HPOS compatibility
 */
function rwbe_declare_hpos_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'rwbe_declare_hpos_compatibility' );

/**
 * Register a public taxonomy for brands (product_brand) if it doesn't already exist.
 * This allows themes and other plugins to use a common brand taxonomy without conflicts.
 */
function rwbe_register_product_brand_taxonomy() {
	if ( taxonomy_exists( 'product_brand' ) ) {
		return;
	}

	$labels = array(
		'name'              => _x( 'Brands', 'taxonomy general name', 'rwbe-product-importer' ),
		'singular_name'     => _x( 'Brand', 'taxonomy singular name', 'rwbe-product-importer' ),
		'search_items'      => __( 'Search Brands', 'rwbe-product-importer' ),
		'all_items'         => __( 'All Brands', 'rwbe-product-importer' ),
		'parent_item'       => __( 'Parent Brand', 'rwbe-product-importer' ),
		'parent_item_colon' => __( 'Parent Brand:', 'rwbe-product-importer' ),
		'edit_item'         => __( 'Edit Brand', 'rwbe-product-importer' ),
		'update_item'       => __( 'Update Brand', 'rwbe-product-importer' ),
		'add_new_item'      => __( 'Add New Brand', 'rwbe-product-importer' ),
		'new_item_name'     => __( 'New Brand Name', 'rwbe-product-importer' ),
		'menu_name'         => __( 'Brands', 'rwbe-product-importer' ),
	);

	$args = array(
		'hierarchical'      => true,
		'labels'            => $labels,
		'show_ui'           => true,
		'show_admin_column' => true,
		'query_var'         => true,
		'rewrite'           => array( 'slug' => 'brand' ),
		'show_in_rest'      => true,
		'public'            => true,
	);

	register_taxonomy( 'product_brand', array( 'product' ), $args );
}
add_action( 'init', 'rwbe_register_product_brand_taxonomy', 5 );

/**
 * Load the plugin translations from /languages.
 *
 * The plugin is not hosted on wordpress.org, so WordPress does not load the
 * translations automatically — the text domain has to be registered here.
 */
function rwbe_load_textdomain() {
	load_plugin_textdomain(
		'rwbe-product-importer',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'rwbe_load_textdomain' );

/**
 * Boot the plugin once WordPress and WooCommerce are loaded.
 *
 * @return void
 */
function rwbe_product_importer_init() {
	if ( rwbe_check_woocommerce_active() ) {
		// Initialize main plugin class.
		$rwbe_importer = new RWBE_Product_Importer();
		$rwbe_importer->init();

		// Initialize front-end vehicle search (shortcode + admin sub-menu).
		// Registered outside the is_admin() block so the shortcode works on the storefront.
		if ( class_exists( 'RWBE_Product_Importer_Search' ) ) {
			$rwbe_search = new RWBE_Product_Importer_Search();
			$rwbe_search->init();
		}

		// Initialize the sidebar vehicle filter widget (Make + Year).
		// Registered outside is_admin() so the widget renders on the storefront.
		if ( class_exists( 'RWBE_Vehicle_Filter' ) ) {
			$rwbe_vehicle_filter = new RWBE_Vehicle_Filter();
			$rwbe_vehicle_filter->init();
		}

		// Initialize admin class if in admin area.
		if ( is_admin() ) {
			$rwbe_admin = new RWBE_Product_Importer_Admin();
			$rwbe_admin->init();

			// Initialize Live Log page/assets only in admin.
			if ( class_exists( 'RWBE_Product_Importer_Live_Log' ) ) {
				$rwbe_live_log = new RWBE_Product_Importer_Live_Log();
				$rwbe_live_log->init();
			}
		}
	}//end if
}
add_action( 'plugins_loaded', 'rwbe_product_importer_init' );

// Activation hook.
register_activation_hook( __FILE__, 'rwbe_product_importer_activate' );

/**
 * Schedule the cron events, create the fitment table and pre-sync the taxonomies.
 *
 * @return void
 */
function rwbe_product_importer_activate() {
	// Garantir que o intervalo personalizado 'rwbe_two_minutes' está disponível
	// ANTES de agendar o evento. Na ativação o init() ainda não correu (o seu
	// filtro cron_schedules ainda não está ligado), e o WP recusa agendar um
	// intervalo desconhecido — o evento ficaria por agendar.
	if ( class_exists( 'RWBE_Product_Importer' ) ) {
		add_filter( 'cron_schedules', array( new RWBE_Product_Importer(), 'add_cron_interval' ) );
	}

	// Schedule the cron job for automatic import.
	if ( ! wp_next_scheduled( 'rwbe_product_import_cron' ) ) {
		wp_schedule_event( time(), 'twicedaily', 'rwbe_product_import_cron' );
	}

	// Schedule the cron job for checking interrupted imports
	// Usar 'rwbe_two_minutes' (definido em RWBE_Product_Importer::add_cron_interval).
	// O intervalo 'rwbe_five_minutes' nunca foi definido em lado nenhum, pelo que o
	// evento ficava agendado com uma recorrência desconhecida e não se reagendava.
	if ( ! wp_next_scheduled( 'rwbe_check_interrupted_imports' ) ) {
		wp_schedule_event( time(), 'rwbe_two_minutes', 'rwbe_check_interrupted_imports' );
	}

	// Create the vehicle fitment table (make + model + year range per product).
	if ( class_exists( 'RWBE_Fitment' ) ) {
		RWBE_Fitment::install();
	}

	// Ensure attributes exist and sync taxonomies on activation.
	if ( class_exists( 'RWBE_Product_Importer' ) ) {
		try {
			$importer = new RWBE_Product_Importer();
			$importer->init();
			$importer->ensure_all_attributes_exist();
			// Sync brands and groups before any product processing.
			if ( method_exists( $importer, 'maybe_sync_taxonomies' ) ) {
				$importer->maybe_sync_taxonomies();
			}
		} catch ( Exception $e ) {
			if ( class_exists( 'RWBE_Debug_Logger' ) ) {
				RWBE_Debug_Logger::log( 'Activation pre-sync error', [ 'error' => $e->getMessage() ] );
			}
		}
	}
}

// Deactivation hook.
register_deactivation_hook( __FILE__, 'rwbe_product_importer_deactivate' );

/**
 * Unschedule the plugin's cron events. Data is left alone; see uninstall.php.
 *
 * @return void
 */
function rwbe_product_importer_deactivate() {
	// Clear the scheduled cron jobs.
	wp_clear_scheduled_hook( 'rwbe_product_import_cron' );
	wp_clear_scheduled_hook( 'rwbe_check_interrupted_imports' );
}
