<?php

namespace WPHookProfiler;

class Test {
	static function addSleepTest() {
		if ( did_action( 'wp_head' ) ) {
			trigger_error( 'addSleepTest must be called before wp_head!', E_USER_WARNING );

			return;
		}

		add_action( 'hprof_sleep_20ms', array( 'WPHookProfiler\Test', 'sleepFor20ms' ) );
		add_action( 'hprof_sleep_20ms_2nd', array( 'WPHookProfiler\Test', 'sleepFor20ms' ) );

		add_action( 'hprof_sleep_recursive', array( 'WPHookProfiler\Test', 'recursiveSleep5ms' ), 1, 2 );

		$action = (HookProfiler::getCurrentRequestGroup(true) === 'admin' ? 'admin_head' : 'wp_head');
		add_action( $action, array( 'WPHookProfiler\Test', 'sleepFor2ms' ) );
		add_action( $action, array( 'WPHookProfiler\Test', 'sleepFor8ms' ) );
		add_action( $action, array( 'WPHookProfiler\Test', 'sleepFor30ms' ) );
		add_action( $action, array( 'WPHookProfiler\Test', 'sleepFor60ms' ) );
		add_action( $action, array( 'WPHookProfiler\Test', 'nestedSleep20ms' ) );


		add_filter('gettext',array( 'WPHookProfiler\Test', 'gettextFilterNop' ) , 10, 3);
		add_filter('gettext',array( 'WPHookProfiler\Test', 'gettextFilterNopOnly1Arg' ) , 10, 1);

		// 2+8+30+60+100+100 = 300
		// sleep 300 ms in wp_head

		// add a shutdown sleep to see how it influences the page load
		add_action( 'shutdown', array( 'WPHookProfiler\Test', 'sleepFor10s' ) );

		// sleep for (maxLevel+1) * 5ms
		$maxLevel = 19;
		do_action( 'hprof_sleep_recursive', $maxLevel ); // => 100ms

		// call the same funtion but with another hook name
		do_action( 'hprof_sleep_20ms' ); // => 100ms
		do_action( 'hprof_sleep_20ms_2nd' ); // => 100ms

		$to1 = new TestObj();
		$to2 = new TestObj();

		add_action( 'hprof_object_test', array( $to1, 'sleep25ms' ) );
		add_action( 'hprof_object_test', array( $to2, 'sleep25ms' ) );
		do_action( 'hprof_object_test' ); // => 100 ms

		// in this function we slept    400 ms
		// in wp-header we sleep        300 ms
		// in total:                    700 ms
	}

	static function gettextFilterNop($translation, $text, $domain) {
		return $translation;
	}

	static function gettextFilterNopOnly1Arg($translation) {
		return $translation;
	}

	static function sleepFor2ms() {
		usleep( 2000 );
	}

	static function sleepFor4ms() {
		usleep( 4000 );
	}

	static function sleepFor8ms() {
		usleep( 8000 );
	}

	static function sleepFor10ms() {
		usleep( 10000 );
	}

	static function sleepFor20ms() {
		usleep( 20000 );
	}

	static function sleepFor30ms() {
		usleep( 30000 );
	}

	static function sleepFor60ms() {
		usleep( 60000 );
	}

	static function sleepFor100ms() {
		usleep( 100000 );
	}

	static function sleepFor400ms() {
		usleep( 400000 );
	}

	static function sleepFor10s() {
		//sleep( 10 );
	}

	static function recursiveSleep5ms( $max_level, $level = 0 ) {
		usleep( 5000 );
		if ( $level >= $max_level ) {
			return;
		}
		do_action( 'hprof_sleep_recursive', $max_level, $level + 1 );
	}

	static function nestedSleep20ms() {
		do_action( 'hprof_sleep_20ms' );
	}
}

class TestObj {
	function sleep25ms() {
		usleep( 25000 );
	}
}