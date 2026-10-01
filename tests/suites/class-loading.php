<?php
// Minimal harness: load every plugin class with WordPress stubbed out, to catch
// structural errors (duplicate methods, bad signatures) that a lint pass misses.
require_once __DIR__ . '/../helpers.php';

define( 'WPINC', 'wp-includes' );
define( 'ABSPATH', '/tmp/fake-wp/' );
define( 'RWBE_PRODUCT_IMPORTER_VERSION', '1.2.4' );
define( 'RWBE_PRODUCT_IMPORTER_PLUGIN_DIR', '/tmp/' );
define( 'RWBE_PRODUCT_IMPORTER_PLUGIN_URL', 'http://example.test/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

if ( ! isset( $GLOBALS['filters'] ) ) {
	$GLOBALS['filters'] = [];
}

/**
 * Stubbed filter dispatch. WordPress with nothing hooked returns the value it was
 * given, and a stub that returns false instead silently changes what the code under
 * test computes.
 */
function apply_filters( $hook, $value = null ) {
	return $GLOBALS['filters'][ $hook ] ?? $value;
}

$fns = [
	'add_action',
	'add_filter',
	'remove_filter',
	'add_shortcode',
	'do_action',
	'get_option',
	'update_option',
	'delete_option',
	'add_option',
	'get_transient',
	'set_transient',
	'delete_transient',
	'wp_cache_delete',
	'wp_upload_dir',
	'wp_get_upload_dir',
	'wp_mkdir_p',
	'trailingslashit',
	'untrailingslashit',
	'sanitize_title',
	'sanitize_text_field',
	'sanitize_key',
	'sanitize_hex_color',
	'esc_html',
	'esc_attr',
	'esc_url',
	'esc_html__',
	'esc_attr__',
	'esc_html_e',
	'esc_attr_e',
	'__',
	'_e',
	'_x',
	'wp_unslash',
	'wp_slash',
	'get_post_meta',
	'update_post_meta',
	'delete_post_meta',
	'metadata_exists',
	'get_post_status',
	'wp_update_post',
	'wp_insert_post',
	'get_post',
	'get_post_type',
	'get_posts',
	'taxonomy_exists',
	'get_terms',
	'wp_count_terms',
	'get_term_by',
	'wp_insert_term',
	'wp_set_object_terms',
	'wp_remove_object_terms',
	'register_taxonomy',
	'wc_get_product',
	'wc_get_product_id_by_sku',
	'wc_delete_product_transients',
	'wc_format_decimal',
	'wc_stock_amount',
	'clean_post_cache',
	'wp_defer_term_counting',
	'wp_remote_get',
	'wp_remote_retrieve_body',
	'wp_remote_retrieve_response_code',
	'is_wp_error',
	'wp_schedule_event',
	'wp_schedule_single_event',
	'wp_next_scheduled',
	'wp_clear_scheduled_hook',
	'spawn_cron',
	'admin_url',
	'home_url',
	'add_query_arg',
	'wp_nonce_url',
	'wp_create_nonce',
	'check_ajax_referer',
	'check_admin_referer',
	'current_user_can',
	'wp_send_json_success',
	'wp_send_json_error',
	'is_admin',
	'is_singular',
	'is_shop',
	'is_product_taxonomy',
	'has_shortcode',
	'shortcode_atts',
	'wp_register_script',
	'wp_register_style',
	'wp_enqueue_script',
	'wp_enqueue_style',
	'wp_localize_script',
	'register_block_type',
	'is_active_widget',
	'add_submenu_page',
	'register_setting',
	'date_i18n',
	'current_time',
	'selected',
	'disabled',
	'wc_get_page_permalink',
	'set_post_thumbnail',
	'has_post_thumbnail',
	'download_url',
	'media_handle_sideload',
	'wp_list_pluck',
	'register_shutdown_function_stub',
	'dbDelta',
	'load_plugin_textdomain',
	'plugin_dir_path',
	'plugin_dir_url',
	'plugin_basename',
	'is_multisite',
	'get_site_option',
	'register_widget',
	'nl2br_stub',
];
foreach ( $fns as $f ) {
	if ( ! function_exists( $f ) ) {
		eval( "function {$f}() { return false; }" );
	}
}
function wp_upload_dir_real() {}
class WP_Error {
	public function __construct( ...$a ) {} public function get_error_message() {
		return ''; }
}
class WP_Widget {
	public function __construct( ...$a ) {}
}

$dir   = RWBE_TEST_PLUGIN_DIR . '/includes/';
$files = [
	'class-rwbe-debug-logger.php',
	'class-rwbe-generic-attribute-helper.php',
	'class-rwbe-fitment.php',
	'class-rwbe-vehicle-map.php',
	'class-rwbe-product-importer.php',
	'class-rwbe-product-importer-admin.php',
	'class-rwbe-product-importer-live-log.php',
	'class-rwbe-product-importer-search.php',
	'class-rwbe-vehicle-filter-widget.php',
	'class-rwbe-api-tester.php',
];
foreach ( $files as $f ) {
	require_once $dir . $f;
	echo "loaded: {$f}\n";
}

// Spot-check the signatures the refactor introduced or changed.
$checks = [
	[ 'RWBE_Fitment', 'get_model_map', 1 ],
	[ 'RWBE_Fitment', 'get_year_map', 2 ],
	[ 'RWBE_Fitment', 'where_clause', 3 ],
	[ 'RWBE_Fitment', 'query_join', 0 ],
	[ 'RWBE_Product_Importer', 'prefetch_stock', 2 ],
	[ 'RWBE_Product_Importer', 'update_meta_if_changed', 3 ],
	[ 'RWBE_Product_Importer', 'collect_api_ids', 1 ],
	[ 'RWBE_Product_Importer', 'cache_buster', 0 ],
	[ 'RWBE_Product_Importer_Search', 'inject_fitment_clauses', 2 ],
	[ 'RWBE_Product_Importer_Live_Log', 'flush_product_logs', 0 ],
	[ 'RWBE_Debug_Logger', 'flush', 0 ],
];
foreach ( $checks as [$class, $method, $argc] ) {
	$r = new ReflectionMethod( $class, $method );
	$n = $r->getNumberOfParameters();
	printf( "%-34s %-26s params=%d (expected %d) %s\n", $class, $method, $n, $argc, $n === $argc ? 'OK' : 'MISMATCH' );
}
echo "\nALL CLASSES LOADED CLEANLY\n";
