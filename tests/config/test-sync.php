<?php
/**
 * Tests SDI data syncing hook
 *
 * @phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
 * @phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
 */

namespace Automattic\VIP\Config;

use WP_UnitTestCase;

require_once __DIR__ . '/../../config/class-site-details-index.php';
require_once __DIR__ . '/../../config/class-sync.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Sync_Test extends WP_UnitTestCase {

	public function test__vip_site_details_siteurl_update_hook() {
		$this->check_sync_site_details_update_hook( 'siteurl', 'site_url', 'http://change-site-url.com' );
	}

	public function test__vip_site_details_home_update_hook() {
		$this->check_sync_site_details_update_hook( 'home', 'home_url', 'http://change-home-url.com' );
	}

	/**
	 * Internal test function to avoid duplications when testing the update hooks for both home/siteurl.
	 * It checks that the action is active and that the should_sync_site_details flag is set to true
	 * once we call `update_option` with the correct option name.
	 *
	 * @param $option_name
	 * @param $sds_core_field
	 * @param $option_value
	 *
	 * @return void
	 */
	private function check_sync_site_details_update_hook( $option_name, $sds_core_field, $option_value ) {
		$sync_instance = Sync::instance();
		Site_Details_Index::instance( 100 );

		$this->assertIsInt( has_action( "update_option_{$option_name}", array( $sync_instance, 'trigger_sds_sync' ) ) );
		$this->assertIsInt( has_action( 'shutdown', array( $sync_instance, 'maybe_do_sds_sync' ) ) );

		$this->assertFalse( $sync_instance->should_run_sds_sync() );

		update_option( $option_name, $option_value );

		$this->assertTrue( $sync_instance->should_run_sds_sync() );

		$site_details = apply_filters( 'vip_site_details_index_data', array() );
		$this->assertSame( $option_value, $site_details['core'][ $sds_core_field ], "$sds_core_field should be equal to the updated value" );
	}
}
