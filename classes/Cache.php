<?php

namespace WPHookProfiler;

use WPHookProfiler\Cache\Shm;
use WPHookProfiler\Cache\WPTransient;

class Cache {
	static $cacheInstance = null;

	static function get() {
		if(!self::$cacheInstance) {
			if(wp_using_ext_object_cache()) {
				self::$cacheInstance = new WPTransient();
			}
			else {
				try {
					self::$cacheInstance = new Shm();
				} catch ( \Exception $e ) {
					self::$cacheInstance = new WPTransient();
				}
			}
		}
		return self::$cacheInstance;
	}


	public static function intHash( $str, $raw=false ) {
		$key = unpack( 'q', hash( 'md4', $str, true ) );
		return abs( $key[1] % PHP_INT_MAX );
	}

	public static function binHash( $str, $lenBytes=8 ) {
		return substr(hash( 'md4', $str, true ), 0, $lenBytes);
	}

}