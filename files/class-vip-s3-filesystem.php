<?php

namespace Automattic\VIP\Files;

use ReflectionMethod;
use WP_Error;

// TODO: Move to own class file
class VipS3StreamWrapper extends \Aws\S3\StreamWrapper {
	/**
	 * This is copied from the SDK
	 * @see https://github.com/aws/aws-sdk-php/blob/6e694b9710d625be8facbaa17aca9c5e3295c7be/src/S3/StreamWrapper.php#L342-L372
	 *
	 * ---
	 *
	 * Support for mkdir().
	 *
	 * @param string $path    Directory which should be created.
	 * @param int    $mode    Permissions. 700-range permissions map to
	 *                        ACL_PUBLIC. 600-range permissions map to
	 *                        ACL_AUTH_READ. All other permissions map to
	 *                        ACL_PRIVATE. Expects octal form.
	 * @param int    $options A bitwise mask of values, such as
	 *                        STREAM_MKDIR_RECURSIVE.
	 *
	 * @return bool
	 * @link http://www.php.net/manual/en/streamwrapper.mkdir.php
	 */
	public function mkdir( $path, $mode, $options ) {
		$initProtocol = new ReflectionMethod( $this, 'initProtocol' );
		$initProtocol->setAccessible( true );
		$initProtocol->invoke( $this, $path );

		$withPath = new ReflectionMethod( $this, 'withPath' );
		$withPath->setAccessible( true );
		$params = $withPath->invoke( $this, $path );

		$clearCacheKey = new ReflectionMethod( $this, 'clearCacheKey' );
		$clearCacheKey->setAccessible( true );
		$clearCacheKey->invoke( $this, $path );

		if ( ! $params['Bucket'] ) {
			return false;
		}

		/**
		 * This is where this method differs from the SDK.
		 * We do not support ACLs, so have to pull them out of the params.
		 */

		// if (!isset($params['ACL'])) {
		//     $params['ACL'] = $this->determineAcl($mode);
		// }

		unset( $params['ACL'] );

		$initProtocol = new ReflectionMethod( $this, 'initProtocol' );
		$initProtocol->setAccessible( true );
		$initProtocol->invoke( $this, $path );

		$createBucket = new ReflectionMethod( $this, 'createBucket' );
		$createBucket->setAccessible( true );

		$createSubfolder = new ReflectionMethod( $this, 'createSubfolder' );
		$createSubfolder->setAccessible( true );


		return empty( $params['Key'] )
			? $createBucket->invoke( $this, $path, $params)
			: $createSubfolder->invoke( $this, $path, $params);
	}

	// touch, chmod, chown, & chgrp are not supported.
	// Just suppress the warnings
	public function stream_metadata( string $path, int $option, mixed $value ): bool {
		return true;
	}
}

class VIP_S3_Filesystem extends VIP_Filesystem {
	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		if ( ! (
			defined( 'AWS_STREAM_WRAPPER_S3_AKI' ) &&
			defined( 'AWS_STREAM_WRAPPER_S3_SAK' ) &&
			defined( 'AWS_STREAM_WRAPPER_BUCKET' )
		) ) {
			return;
		}

		$this->add_filters();

		$credentials = new \Aws\Credentials\Credentials( AWS_STREAM_WRAPPER_S3_AKI, AWS_STREAM_WRAPPER_S3_SAK );

		$aws_client = new \Aws\S3\S3Client( [
			'version' => 'latest',
			'region' => 'us-east-1',
			'credentials' => $credentials,
		] );

		VipS3StreamWrapper::register( $aws_client, self::PROTOCOL );
	}

	/**
	 * Filter the result of `wp_upload_dir` function
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Array of upload directory paths and URLs
	 *
	 * @return array Modified output of `wp_upload_dir`
	 */
	public function filter_upload_dir( $params ) {
		static $cache = [];
		$key = json_encode( $params );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		// prepend the bucket name and any other organizational parts of the key we'll use (orgId, siteId, domain, etc...?)
		$prefix_path = trailingslashit( AWS_STREAM_WRAPPER_BUCKET );

		// Should we keep the `wp-content/uploads` part?

		$pos               = stripos( $params['path'], WP_CONTENT_DIR );
		$params['path']    = substr_replace(
			$params['path'],
			self::PROTOCOL . "://${prefix_path}wp-content",
			$pos,
			strlen( WP_CONTENT_DIR )
		);
		$params['basedir'] = substr_replace(
			$params['basedir'],
			self::PROTOCOL . "://${prefix_path}wp-content",
			$pos,
			strlen( WP_CONTENT_DIR )
		);

		$cache[ $key ] = $params;
		return $params;
	}

	/**
	 * Use the VIP Filesystem API to check for filename uniqueness
	 *
	 * The `unique_filename` API will return an error if file type is not supported
	 * by the VIP Filesystem.
	 *
	 * @since   1.0.0
	 * @access  protected
	 *
	 * @param   string      $file_path   Path starting with /wp-content/uploads
	 *
	 * @return  WP_Error|bool        True if filetype is supported. Else WP_Error.
	 */
	protected function validate_file_type( $file_path ) {
		return true;
	}


}
