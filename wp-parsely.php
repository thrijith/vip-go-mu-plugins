<?php
/*
 * Plugin Name: VIP Parse.ly Integration
 * Plugin URI: https://parse.ly
 * Description: Content analytics made easy. Parse.ly gives creators, marketers and developers the tools to understand content performance, prove content value, and deliver tailored content experiences that drive meaningful results.
 * Author: Automattic
 * Version: 1.0
 * Author URI: https://wpvip.com/
 * License: GPL2+
 * Text Domain: wp-parsely
 * Domain Path: /languages/
 */

namespace Automattic\VIP\WP_Parsely_Integration;

// The default version is the first entry in the SUPPORTED_VERSIONS list.
const SUPPORTED_VERSIONS = [
	'3.8',
	'3.7',
	'3.6',
	'3.5',
	'3.3',
	'3.2',
	'3.1',
];

/**
 * Keep track of Parse.ly loading info in one place.
 */
final class Parsely_Loader_Info {
	// Describes how the parse.ly plugin is integrated/loaded.
	const INTEGRATION_TYPE_MUPLUGINS         = 'MUPLUGINS';
	const INTEGRATION_TYPE_MUPLUGINS_SILENT  = 'MUPLUGINS_SILENT';
	const INTEGRATION_TYPE_SELF_MANAGED      = 'SELF_MANAGED';
	const INTEGRATION_TYPE_NONE              = 'NONE';
	const INTEGRATION_TYPE_DISABLED_CONSTANT = 'MUPLUGINS_DISABLED_CONSTANT';

	// Defaults for when detection was not possible.
	const VERSION_UNKNOWN = 'UNKNOWN';

	private static bool $active;
	private static string $integration_type;
	private static string $service_type;
	private static string $version;
	private static array $parsely_options;

	public static function is_active(): bool {
		return isset( self::$active ) ? self::$active : false;
	}

	public static function set_active( bool $active ): void {
		self::$active = $active;
	}

	public static function get_integration_type(): string {
		return isset( self::$integration_type ) ? self::$integration_type : self::INTEGRATION_TYPE_NONE;
	}

	public static function set_integration_type( string $integration_type ): void {
		self::$integration_type = $integration_type;
	}

	public static function get_version(): string {
		return isset( self::$version ) ? self::$version : self::VERSION_UNKNOWN;
	}

	public static function set_version( string $version ): void {
		self::$version = $version;
	}

	public static function get_parsely_options(): array {
		if ( ! isset( self::$parsely_options ) ) {
			self::$parsely_options = get_option( 'parsely', [] );
		}

		return is_array( self::$parsely_options ) ? self::$parsely_options : [];
	}
}

/**
 * Annotate the `parsely` option with `'meta_type' => 'repeated_metas'`.
 * When this filter is applied thusly, this prints parsely meta as multiple `<meta />` tags
 * vs. a single structured ld+json schema.
 * This is desirable since many of our sites already have curated schema setups & this could interfere.
 *
 * @param mixed $parsely_options The value of the `parsely` option from the database. This materializes as an array (but is false when not yet set).
 * @return array The annotated array.
 */
function alter_option_use_repeated_metas( $parsely_options = [] ) {
	$parsely_options['meta_type'] = 'repeated_metas';
	return $parsely_options;
}

/**
 * Detects if the user is attempting to activate wp-parsely in wp-admin.
 * The Parse.ly plugin will not be in active_plugins, but is pending activation.
 */
function is_queued_for_activation() {
	if ( ! is_admin() ) {
		return false;
	}

	// phpcs:disable
	if ( isset( $_GET['action'] ) && isset( $_GET['plugin'] ) ) {
		if ( 'activate' === $_GET['action'] && false !== strpos( wp_unslash( $_GET['plugin'] ), 'wp-parsely.php' ) ) {
			return true;
		}
	}

	// Bulk activation will activate via form submission
	$isBulkActivation1 = isset( $_POST['action'] ) && 'activate-selected' === wp_unslash( $_POST['action'] );
	$isBulkActivation2 = isset( $_POST['action2'] ) && 'activate-selected' === wp_unslash( $_POST['action2'] );
	if ( ( $isBulkActivation1 || $isBulkActivation2 ) && isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ) {
		$plugins_being_activated = wp_unslash( $_POST['checked'] );

		foreach ( $plugins_being_activated as $plugin ) {
			if ( false !== strpos( $plugin, 'wp-parsely.php' ) ) {
				return true;
			}
		}
	}
	// phpcs:enable

	return false;
}

/**
 * Sourcing the wp-parsely plugin via mu-plugins is generally opt-in.
 * To enable it on your site, add this line:
 *
 * add_filter( 'wpvip_parsely_load_mu', '__return_true' );
 *
 * We enable it for some sites via the `_wpvip_parsely_mu` blog option.
 * To prevent it from loading even when this condition is met, add this line:
 *
 * add_filter( 'wpvip_parsely_load_mu', '__return_false' );
 */
function maybe_load_plugin() {
	// If the user is activating the plugin in this request, do not try to load wp-parsely via mu.
	if ( is_queued_for_activation() ) {
		return;
	}

	// Self-managed integration: The plugin exists on the site and is being loaded already.
	$plugin_class_exists = class_exists( 'Parsely' ) || class_exists( 'Parsely\Parsely' );
	if ( $plugin_class_exists ) {
		Parsely_Loader_Info::set_active( true );
		Parsely_Loader_Info::set_integration_type( Parsely_Loader_Info::INTEGRATION_TYPE_SELF_MANAGED );

		$parsely_options = Parsely_Loader_Info::get_parsely_options();
		if ( array_key_exists( 'plugin_version', $parsely_options ) ) {
			Parsely_Loader_Info::set_version( $parsely_options['plugin_version'] );
		}

		return;
	}

	// Allow opting out via the VIP_PARSELY_SKIP_LOAD constant.
	if ( defined( 'VIP_PARSELY_SKIP_LOAD' ) && true === VIP_PARSELY_SKIP_LOAD ) {
		Parsely_Loader_Info::set_active( false );
		Parsely_Loader_Info::set_integration_type( Parsely_Loader_Info::INTEGRATION_TYPE_DISABLED_CONSTANT );

		return;
	}

	$option_load_status   = get_option( '_wpvip_parsely_mu', null );
	$filtered_load_status = apply_filters( 'wpvip_parsely_load_mu', null );

	$should_load            = true === $filtered_load_status || '1' === $option_load_status;
	$should_prevent_loading = false === $filtered_load_status || '0' === $option_load_status;

	// No integration: The site has not enabled parsely.
	if ( ! $should_load || $should_prevent_loading ) {
		Parsely_Loader_Info::set_active( false );
		Parsely_Loader_Info::set_integration_type( Parsely_Loader_Info::INTEGRATION_TYPE_NONE );

		return;
	}

	// Enqueuing the disabling of Parse.ly features when the plugin is loaded (after the `plugins_loaded` hook)
	// We need priority 0, so it's executed before `widgets_init`
	add_action( 'init', __NAMESPACE__ . '\maybe_disable_some_features', 0 );

	$versions_to_try = SUPPORTED_VERSIONS;

	/**
	 * Allows specifying a major version of the plugin per-site.
	 * If the version is invalid, the default version will be used.
	 */
	$specified_version = apply_filters( 'wpvip_parsely_version', false );

	if ( $specified_version ) {
		if ( in_array( $specified_version, SUPPORTED_VERSIONS ) ) {
			array_unshift( $versions_to_try, $specified_version );
			$versions_to_try = array_unique( $versions_to_try );
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			trigger_error(
				sprintf( 'Invalid value configured via wpvip_parsely_version filter: %s', esc_html( $specified_version ) ),
				E_USER_WARNING
			);
		}
	}

	$versions_exist = false;
	foreach ( $versions_to_try as $version ) {
		$entry_file = __DIR__ . '/wp-parsely-' . $version . '/wp-parsely.php';
		if ( is_readable( $entry_file ) ) {
			$versions_exist = true;
			break;
		}
	}

	if ( ! $versions_exist ) {
		// Attempt to load the submodule
		$entry_file = __DIR__ . '/wp-parsely/wp-parsely.php';
	}

	$integration_type = Parsely_Loader_Info::INTEGRATION_TYPE_MUPLUGINS;
	if ( '1' === $option_load_status && true !== $filtered_load_status ) {
		$integration_type = Parsely_Loader_Info::INTEGRATION_TYPE_MUPLUGINS_SILENT;
	}

	// Require the actual wp-parsely plugin.
	if ( ! is_readable( $entry_file ) ) {
		return;
	}
	require_once $entry_file;
	Parsely_Loader_Info::set_active( true );
	Parsely_Loader_Info::set_integration_type( $integration_type );
	Parsely_Loader_Info::set_version( $version );

	// Require VIP's customizations over wp-parsely.
	$vip_parsely_plugin = __DIR__ . '/vip-parsely/vip-parsely.php';
	if ( is_readable( $vip_parsely_plugin ) ) {
		require_once $vip_parsely_plugin;
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\maybe_load_plugin', 1 );

function maybe_disable_some_features() {
	if ( ! isset( $GLOBALS['parsely'] ) || ! is_a( $GLOBALS['parsely'], 'Parsely\Parsely' ) ) {
		return;
	}

	$filtered_load_status    = apply_filters( 'wpvip_parsely_load_mu', null );
	$should_disable_features = apply_filters( 'wpvip_parsely_hide_ui_for_mu', true !== $filtered_load_status );

	// If the plugin was not loaded via the filter, hide the UI by default.
	if ( $should_disable_features ) {
		remove_action( 'init', 'Parsely\parsely_wp_admin_early_register' );
		remove_action( 'init', 'Parsely\init_recommendations_block' );
		remove_action( 'enqueue_block_editor_assets', 'Parsely\init_content_helper' );
		remove_action( 'admin_init', 'Parsely\parsely_admin_init_register' );
		remove_action( 'widgets_init', 'Parsely\parsely_recommended_widget_register' );

		// Don't show the row action links.
		add_filter( 'wp_parsely_enable_row_action_links', '__return_false' );
		add_filter( 'wp_parsely_enable_rest_api_support', '__return_false' );
		add_filter( 'wp_parsely_enable_related_api_proxy', '__return_false' );

		// Default to "repeated metas".
		add_filter( 'option_parsely', __NAMESPACE__ . '\alter_option_use_repeated_metas' );

		// Remove the Parse.ly Recommended Widget.
		unregister_widget( 'Parsely_Recommended_Widget' );
	}
}
