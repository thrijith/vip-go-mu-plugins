<?php
/**
 * Plugin Name: VIP AllOptions Safeguard
 * Description: Provides warnings and notifications for wp_options exceeding limits. Attempts autofix by removing big options from alloptions object.
 * Author: Automattic
 * License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

namespace Automattic\VIP\AllOptions;

use Automattic\VIP\Utils\Alerts;

require_once __DIR__ . '/lib/utils/class-alerts.php';

add_action( 'plugins_loaded', __NAMESPACE__ . '\run_alloptions_safeguard' );

define( 'VIP_ALLOPTIONS_ERROR_THRESHOLD', 1000000 );
define( 'VIP_ALLOPTIONS_PRUNED', 'vip_alloptions_pruned' );

/**
 * The purpose of this limit is to safe-guard against a barrage of requests with cache sets for values that are too large.
 * Because WP would keep trying to set the data to Memcached, potentially resulting in Memcached (and site's) performance degradation.
 */
function run_alloptions_safeguard() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}
	//update_option('vlv2', bin2hex(openssl_random_pseudo_bytes(900000)), 'yes');
    //update_option('vlv3', bin2hex(openssl_random_pseudo_bytes(900000)), 'yes');
	// Uncompressed size thresholds.
	// Warn should *always* be =< die
	$alloptions_size_warn = MB_IN_BYTES * 2.5;

	// To avoid performing a potentially expensive calculation of the compressed size we use 4MB uncompressed (which is likely less than 1MB compressed)
	$alloptions_size_die = MB_IN_BYTES * 4;

	$alloptions_size = wp_cache_get( 'alloptions_size' );

	// Cache miss
	if ( false === $alloptions_size ) {
		$alloptions      = maybe_serialize( wp_load_alloptions() );
		$alloptions_size = strlen( $alloptions );

		wp_cache_add( 'alloptions_size', $alloptions_size, '', 60 );
	}

	$warning        = $alloptions_size > $alloptions_size_warn;
	$maybe_blocked  = $alloptions_size > $alloptions_size_die;
	$really_blocked = false; 

	/** Set these 3 to true for testing. REMOVE BEFORE MERGING!!!!!!!!!!!!!!!!!! */
	$warning = true;
	$maybe_blocked = true;
	$really_blocked = true;

	$alloptions_size_compressed = 0;

	if ( ! $warning ) {
		return;
	}

	if ( $maybe_blocked ) {
		// It's likely at this point the site is already experiencing performance degradation.
		// We're using gzdeflate here because pecl-memcache uses Zlib compression for large values.
		// See https://github.com/websupport-sk/pecl-memcache/blob/e014963c1360d764e3678e91fb73d03fc64458f7/src/memcache_pool.c#L303-L354
		$alloptions_size_compressed = wp_cache_get( 'alloptions_size_compressed' );
		if ( ! $alloptions_size_compressed ) {
			$alloptions_size_deflated   = gzdeflate( maybe_serialize( wp_load_alloptions() ) );
			$alloptions_size_compressed = false !== $alloptions_size_deflated ? strlen( $alloptions_size_deflated ) : VIP_ALLOPTIONS_ERROR_THRESHOLD - 1;
			wp_cache_add( 'alloptions_size_compressed', $alloptions_size_compressed, '', 60 );
		}
	}

	if ( $alloptions_size_compressed >= VIP_ALLOPTIONS_ERROR_THRESHOLD ) {
		$really_blocked = true;
	}

	// Attempt autohealing
	if ( $really_blocked ) {
		$alloptions = wp_load_alloptions();
		$alloptions_size_excess = $alloptions_size_compressed - VIP_ALLOPTIONS_ERROR_THRESHOLD;
		$pruned_options = alloptions_prune($alloptions, $alloptions_size_excess);

		$pruned_option_names = array(); //TO-DO
		update_option(VIP_ALLOPTIONS_PRUNED, $pruned_option_names, false);
	}

	// NOTE - This function has built-in rate limiting so it's ok to call on every request
	//alloptions_safeguard_notify( $alloptions_size, $alloptions_size_compressed, $really_blocked );
}

function alloptions_prune( $alloptions, $alloptions_size_excess ) {
	$allowed_options = alloptions_get_allowed_options();
	$options_to_prune = alloptions_get_options_to_prune( $alloptions, $allowed_options, $alloptions_size_excess );
	$pruned_options = array();
	
	foreach($options_to_prune as $key => $option){
		$pruned = alloptions_disable_autoload( $option ); // Force autoload parameter to 'no'
		if( $pruned ){
			$pruned_options[] = $option;
		}
	}
	return $pruned_options;
}

function alloptions_disable_autoload( $option ){
	global $wpdb;
	$update_args = array(
		'option_value' => maybe_serialize( $option->value ),
		'autoload' => 'no',
	);
	$pruned = $wpdb->update( $wpdb->options, $update_args, array( 'option_name' => $option->name ) );

	return $pruned;
}

function alloptions_get_options_to_prune( $alloptions, $allowed_options, $alloptions_size_excess ){	
	$options_to_prune = array();
	$uncompressed_size_excess = $alloptions_size_excess * 4;
	$total_size = 0;
	foreach ( $alloptions as $name => $val ) {
		$size        = mb_strlen( $val );

		// Skip small options, we prune only big options.
		// There is no good way to automatically decide which options to exclude when all of them are small. 
		if ( $size < 500 || in_array( $name, $allowed_options ) ) {
			continue;
		}

		$option = new \stdClass();

		$option->name = $name;
		$option->value = $val;
		$option->size = $size;

		$options_to_prune[] = $option;
		
		$total_size += $size;
		if( $total_size > $uncompressed_size_excess ){
			break;
		}
	}

	// sort by size
	usort( $options_to_prune, function( $arr1, $arr2 ) {
		if ( $arr1->size === $arr2->size ) {
			return 0;
		}

		return ( $arr1->size < $arr2->size ) ? -1 : 1;
	});

	$options_to_prune = array_reverse( $options_to_prune );

	return $options_to_prune;
}


/**
 * Show error page and exit
 */
function alloptions_safeguard_die() {

	// 503 Service Unavailable - prevent caching, indexing, etc and alert Varnish of the problem
	http_response_code( 503 );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- no need to escape the premade HTML file
	echo file_get_contents( __DIR__ . '/errors/alloptions-limit.html' );

	exit;
}

/**
 * Send notification
 *
 * @param int $size            Uncompressed sized of alloptions, in bytes
 * @param int $size_compressed Compressed size of alloption, in bytes.
 *                             HOWEVER, this is only set if $size meets a threshold.
 *                             @see run_alloptions_safeguard()
 * @param bool $really_blocked True if the options size is large enough to cause site to be blocked from loading.
 */
function alloptions_safeguard_notify( $size, $size_compressed = 0, $really_blocked = true ) {
	global $wpdb;

	$throttle_was_set = wp_cache_add( 'alloptions', 1, 'throttle', 30 * MINUTE_IN_SECONDS );

	// If adding to cache failed, we're already throttled (unless the operation actually failed),
	// so return without doing anything.
	if ( false === $throttle_was_set ) {
		return;
	}

	/**
	 * Fires under alloptions warning conditions
	 *
	 * @param bool $really_blocked False if alloptions size is large. True if site loading is being blocked.
	 */
	do_action( 'vip_alloptions_safeguard_notify', $really_blocked );

	$is_vip_env  = ( defined( 'WPCOM_IS_VIP_ENV' ) && true === WPCOM_IS_VIP_ENV );
	$environment = ( ( defined( 'VIP_GO_ENV' ) && VIP_GO_ENV ) ? VIP_GO_ENV : 'unknown' );
	$site_id     = defined( 'FILES_CLIENT_SITE_ID' ) ? FILES_CLIENT_SITE_ID : false;

	// Send notices to VIP staff if this is happening on VIP-hosted sites
	if (
		! $is_vip_env ||
		! $site_id ||
		! defined( 'ALERT_SERVICE_ADDRESS' ) ||
		! ALERT_SERVICE_ADDRESS ||
		'production' !== $environment
	) {
		return;
	}

	$subject = 'ALLOPTIONS: %1$s (%2$s VIP Go site ID: %3$s';

	if ( 0 !== $wpdb->blogid ) {
		$subject .= ", blog ID {$wpdb->blogid}";
	}

	$subject .= ') options is up to %4$s';

	$subject = sprintf(
		$subject,
		esc_url( home_url() ),
		esc_html( $environment ),
		(int) $site_id,
		size_format( $size )
	);

	if ( $really_blocked ) {
		$priority    = 'P2';
		$description = sprintf( 'The size of AllOptions has breached %s bytes', VIP_ALLOPTIONS_ERROR_THRESHOLD );
	} elseif ( $size_compressed > 0 ) {
		$priority    = 'P3';
		$description = sprintf( 'The size of AllOptions is at %1$s bytes (compressed), %2$s bytes (uncompressed)', $size_compressed, $size );
	} else {
		$priority    = 'P5';
		$description = sprintf( 'The size of AllOptions is at %1$s bytes (uncompressed)', $size );
	}

	// Send to OpsGenie
	$alerts = Alerts::instance();
	$alerts->opsgenie(
		$subject,
		array(
			'alias'       => 'alloptions/' . $site_id,
			'description' => $description,
			'entity'      => (string) $site_id,
			'priority'    => $priority,
			'source'      => 'sites/alloptions-size',
		),
		'alloptions-size-alert',
		'10'
	);

}

function alloptions_get_allowed_options(){
	$alloptions_default_allowed_options = array(
		'active_sitewide_plugins',
		'rewrite_rules',
		'wp_user_roles',
		'cron',
		'widget_block',
		'redirection_options',
		'active_plugins',
		'siteurl',
		'home',
		'blogname',
		'blogdescription',
		'gmt_offset',
		'date_format',
		'time_format',
		'start_of_week',
		'timezone_string',
		'WPLANG',
		'new_admin_email',
		'default_pingback_flag',
		'default_ping_status',
		'default_comment_status',
		'comments_notify',
		'moderation_notify',
		'comment_moderation',
		'require_name_email',
		'comment_previously_approved',
		'comment_max_links',
		'moderation_keys',
		'disallowed_keys',
		'show_avatars',
		'avatar_rating',
		'avatar_default',
		'close_comments_for_old_posts',
		'close_comments_days_old',
		'thread_comments',
		'thread_comments_depth',
		'page_comments',
		'comments_per_page',
		'default_comments_page',
		'comment_order',
		'comment_registration',
		'show_comments_cookies_opt_in',
		'thumbnail_size_w',
		'thumbnail_size_h',
		'thumbnail_crop',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
		'image_default_size',
		'image_default_align',
		'image_default_link_type',
		'posts_per_page',
		'posts_per_rss',
		'rss_use_excerpt',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'blog_public',
		'default_category',
		'default_email_category',
		'default_link_category',
		'default_post_format',
	);

	return apply_filters('vip_alloptions_default_allowed_options', $alloptions_default_allowed_options);
}
