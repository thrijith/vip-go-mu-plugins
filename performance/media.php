<?php

// Prevent core from doing filename lookups for media search.
// https://core.trac.wordpress.org/ticket/39358
function vip_filter_query_attachment_filenames() {
	remove_filter( 'posts_clauses', '_filter_query_attachment_filenames' );
}

if ( ! defined( 'WP_RUN_CORE_TESTS' ) || ! WP_RUN_CORE_TESTS ) {
	// This breaks query search tests.
	add_action( 'pre_get_posts', 'vip_filter_query_attachment_filenames' );
}
