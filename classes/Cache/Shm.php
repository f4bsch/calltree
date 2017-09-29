<?php

namespace WPHookProfiler\Cache;


class Shm {
	private $shm;

	public function __construct() {
		if ( ! function_exists( "shm_attach" ) ) {
			throw new \RuntimeException( 'shm_attach not found' );
		}

		$shmKey = ftok( __FILE__, 'c' );
		$this->shm = shm_attach( $shmKey, 1024 * 256 );

		if ( ! is_resource( $this->shm ) ) {
			throw new \RuntimeException( 'shm_attach failed' );
		}
	}

	function __destruct() {
		shm_detach( $this->shm );
	}

	private static function intHash( $str ) {
		$key = unpack( 'q', hash( 'md4', $str, true ) );
		return abs( $key[1] % PHP_INT_MAX );
	}

	public function put( $key, $value ) {
		return shm_put_var( $this->shm, self::intHash( $key ), $value );
	}

	public function has($key) {
		return shm_has_var( $this->shm, self::intHash( $key ));
	}

	public function get( $key, &$found = null ) {
		$ki =  self::intHash( $key );
		$found = shm_has_var( $this->shm,$ki);
		if(!$found)
			return false;
		return shm_get_var( $this->shm, $ki );
	}

	public function del( $key ) {
		return shm_remove_var($this->shm, self::intHash($key));
	}
}