<?php


namespace WPHookProfiler;


class FileStatsCache {

	static function _getSizes__( $fileNames ) {
		$cache = Cache::get()->get( 'hook_prof_file_sizes' );


		$miss = false;


		// check if we have at least one cache miss
		foreach ( $fileNames as $fn ) {
			if ( ! isset( $cache[ $fn ] ) ) {
				$cache[ $fn ] = 0;
				$miss         = true;
			}
		}

		if ( $miss ) {
			// rebuild whole cache
			foreach ( $cache as $fn => $s ) {
				if ( ! is_file( $fn ) ) {
					// keep non existing files that are currently queried to avoid many cache misses
					if ( ! in_array( $fn, $fileNames ) ) {
						unset( $cache[ $fn ] );
					}
				} else {
					$cache[ $fn ] = filesize( $fn );
				}
			}

			Cache::get()->put( 'hook_prof_file_sizes', $cache );
		}


		$sizes = array();
		foreach ( $fileNames as $k => $fn ) {
			$sizes[ $k ] = $cache[ $fn ];
		}

		return $sizes;
	}

	private static $cache = null;

	static function packPath( $path ) {
		return Cache::binHash( str_replace( ABSPATH, '@', realpath($path) ) );
	}

	static function getSizes( $fileNames ) {
		if ( ! self::$cache ) {
			self::$cache = Cache::get()->get( 'hook_prof_file_sizes' );
			if ( ! is_array( self::$cache ) ) {
				self::$cache = array();
			}
		}

		$miss = false;

		// check if we have at least one cache miss
		foreach ( $fileNames as $fn ) {
			$fnp = self::packPath( $fn );
			if ( ! isset( self::$cache[ $fnp ] ) ) {
				self::$cache[ $fnp ] = filesize( $fn );
				$miss                = true;
			}
		}


		if ( $miss ) {
			Cache::get()->put( 'hook_prof_file_sizes', self::$cache );
		}

		$sizes = array();
		foreach ( $fileNames as $k => $fn ) {
			$fnp         = self::packPath( $fn );
			$sizes[ $k ] = self::$cache[ $fnp ];
		}

		return $sizes;
	}

	}