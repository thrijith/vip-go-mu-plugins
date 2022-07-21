<?php

function your_function() {
	echo 'TEST: THIS IS TEST';
}
add_action( 'wp_footer', 'your_function' );
