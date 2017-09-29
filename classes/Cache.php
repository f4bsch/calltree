<?php

namespace WPHookProfiler;

use WPHookProfiler\Cache\Shm;
use WPHookProfiler\Cache\WPTransient;

class Cache {
	static $cacheInstance = null;

	static function get() {
		if(!self::$cacheInstance) {
			try {
				self::$cacheInstance = new Shm();
			} catch (\Exception $e) {
				self::$cacheInstance = new WPTransient();
			}
		}
		return self::$cacheInstance;
	}

}