<?php
/*
Plugin Name: Calltree Profiler
Plugin URI: https://calltr.ee/hook-profiler/
Description: Hooks into the WordPress API and enables a code profiler
Author: Fabian Schlieper
Author URI: https://calltr.ee/
*/


namespace {
	define( 'HOOK_PROFILER_MU', true );
	}


namespace WPHookProfiler {

	function main_mu() {
		ProfilerSettings::loadDefault();


		$secretCookie = HookProfiler::getSecret( 'cookie' );

		if ( isset( $_COOKIE['hprof_disable'] ) || isset( $_GET['hprof_disable'] ) ) {
			setcookie( 'hprof_disable', null, - 1, '/' );
			setcookie( $secretCookie, null, - 1, '/' );
			setcookie( $secretCookie, null, - 1, dirname( $_SERVER['REQUEST_URI'] ) );
			unset( $_COOKIE[ $secretCookie ] );
			$enable_profiling = false;
			ProfilerSettings::$default->disableAll();
			ProfilerSettings::$default->save();
		} else {
			$enable_profiling = ( defined( 'HPROF_ALWAYS_ENABLE' ) && HPROF_ALWAYS_ENABLE ) || ! empty( $_COOKIE[ $secretCookie ] );
			if ( $enable_profiling ) {
				define( 'HOOK_PROFILER_ENABLED', true );
			}
		}


		// set this to true if you installed a modded WP_Hook class
		// reduces profiler overhead, but requires modification to core files!
		$haveModdedWPHookCoreClass = false;

		$inBenchmark = ! empty( $_GET['hprof-bench'] );


		// always instantiate the profiler to show server time, this has almost no overhead
		// use earlyShutdown to register the profiler shutdown within the `wp_footer` hook
		// is is useful for debugging
		$hookProf = new HookProfiler( false );

		if ( $enable_profiling ) {
			if ( ProfilerSettings::$default->opcacheDisable ) {
				$hookProf->disableOPCache();
			}

			// during a benchmark we still profile hooks
			// you can override this by setting $_REQUEST['hprof-disable-hooks']
			if ( $inBenchmark ) {
				ProfilerSettings::$default->disableAll();
				ProfilerSettings::$default->profileHooks = true;

				// self-profiling
				//require_once WP_PLUGIN_DIR . '/hook-prof/classes/Stopwatch.php';
				//Stopwatch::registerSwFunc();

				$hookProf->enableBenchmarkMode();
			}


			if ( ! empty( $_REQUEST['hprof-disable-hooks'] ) ) {
				ProfilerSettings::$default->disableAll();
				ProfilerSettings::$default->profileHooks = false;
			}

			if ( ProfilerSettings::$default->profileHooks ) {
				if ( ! $haveModdedWPHookCoreClass ) {
					// the hookIn method (replace in $wp_filters)
					$hookProf->hookIn();
				} else {
					// direct method (requires a custom WP_Hook version)
					$GLOBALS['_wp_hook_profiler'] = $hookProf;
				}
			}

			if ( ! $inBenchmark && ProfilerSettings::$default->profilePluginMainFileInclude ) {
				$hookProf->replacePluginLoader();
			}

												if ( ProfilerSettings::$default->profileAutoloaders ) {
				$hookProf->enableAutoloadCounter();
			}

						// plugin filters
			if ( ! empty( $_GET['hprof-disable-all-plugins-but'] ) ) {
				$hookProf->disableAllPluginsBut( $_GET['hprof-disable-all-plugins-but'] );
			} elseif ( ! empty( $_GET['hprof-disable-plugins'] ) ) {
				foreach ( (array) $_GET['hprof-disable-plugins'] as $slug ) {
					$hookProf->disablePlugin( $slug );
				}
			}
		}
	}

	function guessIfLoggedIn() {
		if ( ! defined( 'LOGGED_IN_COOKIE' ) ) {
			if ( ! defined( 'COOKIEHASH' ) ) {
				$siteurl = get_site_option( 'siteurl' );
				$hash    = $siteurl ? md5( $siteurl ) : '';
			} else {
				$hash = COOKIEHASH;
			}
			$cookie = 'wordpress_logged_in_' . $hash;
		} else {
			$cookie = LOGGED_IN_COOKIE;
		}

		return isset( $_COOKIE[ $cookie ] );
	}

	class ProfilerSettings {

		// coverage
		public $profileHooks = true;
		public $profilePluginMainFileInclude = false;
		public $profileDb = false;
		public $profileObjectCache = false;
		public $profileShortcodes = false;
		public $profileAutoloaders = false;
		public $profileErrorHandling = false;

		// features
		public $profileMemory = false;
		public $profileIncludes = false;
		public $findDeadIncFiles = false;
		public $detectRecursion = false;

		// report
		public $advancedReport = false;
		public $hookLog = false;
		public $hookLogExcludeCommonFilters = true;
		public $adminBarDisplayTimes = false;


		// debug
		public $showOutOfStackFrames = false;
		public $testSleep = false;
		public $lastDisableAllMsg = '';

		// misc
		public $opcacheDisable = false;

		/**
		 * @var ProfilerSettings
		 */
		public static $default;

		public static function loadDefault() {
			self::$default = new ProfilerSettings();
			$sets          = get_option( 'hook_profiler_settings' );
			if ( ! empty( $sets ) ) {
				self::$default->update( $sets, true );
			}
		}

		public function disableAll( $msg = '' ) {
			if ( ! empty( $msg ) ) {
				$this->lastDisableAllMsg = $msg;
			}

			$this->profileHooks                 = false;
			$this->profilePluginMainFileInclude = false;
			$this->profileDb                    = false;
			$this->profileObjectCache           = false;
			$this->profileShortcodes            = false;
			$this->profileAutoloaders           = false;
			$this->profileErrorHandling         = false;

			$this->profileMemory    = false;
			$this->profileIncludes  = false;
			$this->findDeadIncFiles = false;
			$this->detectRecursion  = false;

			$this->testSleep = false;
			$this->hookLog   = false;
		}

		public function update( $set, $dont_save = false ) {
			if ( ! is_array( $set ) ) {
				return false;
			}

			foreach ( $set as $k => $v ) {
				if ( isset( $this->$k ) ) {
					if ( is_bool( $this->$k ) ) {
						$this->$k = $v && $v !== 'false';
					}
				}
			}

			return $dont_save || $this->save();
		}

		public function save() {
			$res = update_option( 'hook_profiler_settings', (array) $this );
			HookProfiler::logMsg( 'saved settings (result=' . $res . ') @' . $_SERVER['REQUEST_URI'] . json_encode( $_POST ) . ' ' . json_encode( $this ) );

			return $res;
		}
	}


	class HookProfiler {
		public $requestTime; // $_SERVER['REQUEST_TIME_FLOAT']
		public $timestart; // WP's timer_start()
		public $tStart; // profiler start time

		public $detectedStackCorruption = false;
		public $corruptedStack = array();

		/**
		 * @var callable[]
		 */
		public $funcs = array();


		public $funcMapMemIncl = array();
		public $funcMapMemSelf = array();

		public $funcMapIncsSelf = array();
		public $funcMapIncsIncl = array();

		public $funcMapComponent = array();


		public $hookMapFires = array();
		public $hookMapTimeIncl = array(); // TODO rm
		public $hookFirstFiredAt = array();
		public $hookMapLastCallEndTime = array();
		public $hookFuncMapFuncTimeSelf = array();

		public $hookLog = array();
		public $hookLogPost = array();
		public $hookLogTimeIncl = array();

		public $hookMapFuncCalls = array(); // TODO rm
		public $hookMapFuncTime = array();

		public $hookMapRecursiveCount = array(); // how many times a hook fired itself

		public $hookFuncMapCalls = array(); // function calls by hook and func
		public $hookFuncMapTimeIncl = array();

		public $hookFuncMapIncsIncl = array();
		public $hookFuncMapIncsSelf = array();
		public $hookFuncMapMemIncl = array();
		public $hookFuncMapMemSelf = array();


		public $hookGapMapTime = array();
		public $hookGapMapCount = array();

		public $componentMapGapTime = array();
		public $hookGapMapUnclassifiedTime = array();


		private $lastCapturedCallHook = '';

		private $stackEnterTime = 0;
		private $stackExitTime = 0;
		private $stackExitHook = '';
		private $stackExitContextComponent = '';


		public $cacheKeyMapMiss = array();
		public $cacheKeyMapHit = array();
		public $cacheKeyMapMissedDataTime = array();
		public $cacheKeyMapMissedDataComponent = array();


		public $objectMapTime = array();

		public $transientMapUpdateTime = array();
		public $transientMapUpdateCount = array();

		public $componentMapOptionQueryTime = array();
		public $componentMapOptionQueryCount = array();

		public $fileMapTime = array();


		public $autoloaderCalls = 0;
		public $autoloadFuncMapTime = array();


		/**
		 * The total profiling runtime, should be close to total PHP request runtime
		 * @var int
		 */
		private $totalTimeRequest = 0;

		/**
		 * The total time spent in processing hooks (actions and filters)
		 * @var int
		 */
		private $totalInHookTime = 0;

		private $funcFileNameCache = array();

		private $prevErrorHandler = null;


		private $dbPrefixLen;


		private $earlyShutdown = false;

		public function __construct( $earlyShutdown ) {
			$this->earlyShutdown = $earlyShutdown;

			global $timestart;
			$this->requestTime = empty( $_SERVER['REQUEST_TIME_FLOAT'] ) ? $timestart : $_SERVER['REQUEST_TIME_FLOAT'];
			$this->timestart   = $timestart;

			$this->tStart        = microtime( true );
			$this->stackExitTime = $this->tStart;
			$this->stackExitHook = 'profiler_construct';

			global $wpdb;
			$this->dbPrefixLen = strlen( $wpdb->prefix );

			if ( $earlyShutdown ) {
				add_action( 'wp_footer', array( $this, 'end' ) );
			} else {
				register_shutdown_function( array( $this, 'end' ) );
			}


			//require_once WP_PLUGIN_DIR.'/hook-prof/classes/TickProfiler.php';
			//new TickProfiler($this);

					}


		function end() {
			static $destructed = false;
			if ( $destructed ) {
				return;
			}
			$destructed = true;

			$caughtError = self::isError( error_get_last() );

			// non-empy stack at shutdown means that something exited in a hook callback
			if ( ! empty( WP_Hook_Profiled::$hookStack ) ) {
				$s     =& WP_Hook_Profiled::$hookStack;
				$group = self::getCurrentRequestGroup();

				// if we shutdown early at wp_footer, the stack is not empty, so clear it
				if ( $this->earlyShutdown && $s == [ 'wp_footer' ] ) {
					$s = [];
				} // ignore ajax
				elseif ( $group == "ajax" || $group == "rest" || strpos( $s[0], '_ajax_' ) !== false || ( count( $s ) == 2 && strpos( $s[1], '_ajax_' ) !== false ) ) {
					$s = [];
				} // clear the stack for known exit points
				elseif ( count( $s ) === 1 && ( $s[0] === 'template_redirect'
				                                || $s[0] === 'option_active_plugins'
				                                || $s[0] === 'plugins_loaded' )
				) {
					$s = [];
				} // custom ajax endpoints
				elseif ( $group == "frontend-non-html" && ! empty( $_GET ) ) {
					$s = [];
				}
			}


			if ( ! is_array( WP_Hook_Profiled::$hookStack ) || count( WP_Hook_Profiled::$hookStack ) !== 0 ) {
				$this->detectedStackCorruption = true;
				$this->corruptedStack          = WP_Hook_Profiled::$hookStack;
			}

			if ( $this->detectedStackCorruption ) {
				if ( $this->itIsSafeToAppendHtml() ) {
					echo "\n<!-- Hook Profiler detected a hook stack corruption and will discard all collected data! The stack was:\n";
					var_dump( $this->corruptedStack );
					echo " -->";
				}

				// auto disable on error
				if ( ! $this->benchmarkMode ) {
					$this->emergencyDisable( "Detected hook stack corruption at shutdown, auto-disabled!" );
				} else {
					$this->logMsg( 'Detected hook stack corruption during benchmark: ' . print_r( $this->corruptedStack, true ), true );
				}

				return;
			}

			if ( $caughtError ) {
				if ( $this->itIsSafeToAppendHtml() ) {
					echo "\n<!-- Hook Profiler caught an error:\n";
					var_dump( $caughtError );
					echo " -->";
				}

				// auto disable on error
				if ( ! $this->benchmarkMode ) {
					$this->emergencyDisable( "Caught error, auto-disabled!" );
				} else {
					$this->logMsg( 'Caught error during benchmark: ' . print_r( $caughtError, true ), true );
				}

				return;
			}

			$this->totalTimeRequest = ( microtime( true ) - $this->requestTime ) * 1000;


			// this for our frame time computations
			do_action( 'hook_prof_end', $this );

			if ( $this->itIsSafeToAppendHtml() ) {

				// yeah we register a shutdown function in a shutdown function. we want to be very last!
				register_shutdown_function( function () {
					register_shutdown_function( function () {
						$err = error_get_last();
						if ( $err ) {
							echo "<!-- ";
							print_r( $err );
							echo " -->";
						}

						// send everything before measuring last byte
						$levels = ob_get_level();
						for ( $i = 0; $i < $levels; $i ++ ) {
							ob_end_flush();
						}
						flush();

						$now = ( microtime( true ) );
						$t   = ( $now - $this->requestTime ) * 1000;

						global $current_user;
						$uid = $current_user ? $current_user->ID : 0;
						echo "\n<script>  window. WP_USER_ID = {$uid}; window. HPROF_SERVER_TTLB = {$t}; window. HPROF_SERVER_TIME = {$now};</script>";
						exit; // we force an exit here, sorry
					} );
				} );


				if ( ! $this->benchmarkMode && empty( $_REQUEST['hprof-no-html-report'] ) ) {
					do_action( 'hook_prof_end_html_request', $this );
				}

				$t        = $this->requestTime;
				$captures = array_sum( $this->hookFuncMapCalls );
				$trt      = round( $this->totalTimeRequest, 1 );
				$ib       = $this->benchmarkMode ? 'in benchmark mode' : '';

				headers_sent( $firsByteFile, $firsByteLine );

				$reqGroup = self::getCurrentRequestGroup();
				echo "\n<!-- Hook Profiler profiled this `$reqGroup` request @${t}s+{$trt}ms $ib with $captures captured function calls. Output started at $firsByteFile:$firsByteLine\nSettings :\n"
				     . json_encode( ProfilerSettings::$default ) . "\n-->\n";


				// for XHR benchmarks
				echo "\n<!-- HHPROF_COMMON_ACTIONS=" . json_encode( $this->getCommonActionFireTimes() ) . "; -->";

				if ( $this->benchmarkMode ) {
					echo "\n<!-- HPROF_ACTIVE_PLUGINS_HASH=" . self::getActivePluginsHash( true ) . "; -->\n";
				}
			}

		}

		public function emergencyDisable( $msg = "", $logBacktrace = false ) {

			$group = self::getCurrentRequestGroup();
			$msg   .= " @request {$_SERVER['REQUEST_URI']},$group.  Hook Stack was: " . print_r( $this->corruptedStack, true );
			if ( $logBacktrace ) {
				$msg .= "Debug Backtrace: " . print_r( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), true );
			}
			$msg .= " Last error: " . print_r( error_get_last(), true );

			ProfilerSettings::$default->disableAll( $msg );
			ProfilerSettings::$default->save();

			$this->logMsg( $msg );
		}

		public static function isError( $err ) {
			return ( is_array( $err ) && isset( $warn['type'] ) && ( $warn['type'] === E_ERROR || $warn['type'] === E_PARSE || $warn['type'] === E_USER_ERROR ) ) ? $err : false;
		}


		
		public function itIsSafeToAppendHtml() {
			$rg = $this->getCurrentRequestGroup();

			return ( in_array( $rg, array( 'frontend', 'admin' ) )
			         && ( did_action( 'wp_print_scripts' ) || did_action( 'wp_print_footer_scripts' ) || did_action( 'admin_head' ) ) );
		}

		public $hookAddedByComponentMapCallback = array(); // TODO not used atm

		/**
		 * Todo: when a hook is registered, we can find out from what component
		 *
		 * @param $tag
		 * @param $funcAdded
		 */
		function hookAdded( $tag, $funcAdded ) {
			$this->getCallPath2( $component );
			$this->hookAddedByComponentMapCallback[ $tag . '#' . $component ] = $funcAdded;
		}

		// @\$([a-z0-9A-Z_>-]+)\[([^\]]+)\]\s*\+\+\s*;
		//self::inc(\$$1,$2);
		//
		static function inc( &$arr, $key ) {
			return isset( $arr[ $key ] ) ? ( ++ $arr[ $key ] ) : ( $arr[ $key ] = 1 );
			//@$arr[ $key ] ++;
		}

		// @\$([a-z0-9A-Z_>-]+)\[([^\]]+)\]\s*\+=([^;]+);
		//self::add(\$$1,$2,$3);
		//
		/**
		 * @param array $arr
		 * @param string $key
		 * @param float $val
		 */
		static function add( &$arr, $key, $val ) {
			isset( $arr[ $key ] ) ? ( $arr[ $key ] += $val ) : ( $arr[ $key ] = $val );
			//@$arr[ $key ] += $val;
		}

		static function max( &$arr, $key, $val ) {
			isset( $arr[ $key ] ) ? ( ( $val > $arr[ $key ] ) ? $arr[ $key ] = $val : 0/*void*/ ) : ( $arr[ $key ] = $val );
		}

		/**
		 * @param callable $func
		 * @param string $hookTag
		 * @param array $profile the profile data
		 * @param bool $recursion
		 */
		public function addHookCall( $func, $hookTag, $profile, $recursion = false ) {
			$funcId = $this->getFuncId( $func );
			//sw()->measure('getFuncId');

			if ( ! isset( $this->funcs[ $funcId ] ) ) {
				$this->funcs[ $funcId ] = $func;
			}


			$tSelf = $profile['tSelf'] * 1000;
			$tIncl = $profile['tIncl'] * 1000;

			$hookFuncId = $hookTag . '#' . $funcId;

			self::inc( $this->hookMapFuncCalls, $hookTag );

			self::inc( $this->hookFuncMapCalls, $hookFuncId );
			self::add( $this->hookFuncMapFuncTimeSelf, $hookFuncId, $tSelf );
			self::add( $this->hookFuncMapTimeIncl, $hookFuncId, $tIncl );

			if ( ! $recursion ) { // recursion refers to hook recursion
				self::add( $this->hookMapFuncTime, $hookTag, $tIncl );
			}

			
			
			// store last hook for out-of-stack recovery
			$this->lastCapturedCallHook = $hookTag;
		}

		static $memReal = false;

		/**
		 * mincost: 1 push
		 *
		 * @param callable $func
		 * @param string $tag
		 * @param float $now
		 *
		 * @return array
		 */
		function profilePreCall( $func, $tag, $now ) {
			if ( ! is_string( $tag ) ) {
				throw new \InvalidArgumentException( "tag must be string" );
			}

			$profile = array(
				'tStart' => $now,
				'tSelf'  => 0,
				'tag'    => $tag,
				'func'   => $func
			);

						if ( ProfilerSettings::$default->profileIncludes ) {
				$profile['incsStart'] = count( get_included_files() );
				$profile['incsSelf']  = 0;
			}

			WP_Hook_Profiled::$profileStack[] = $profile;

			return $profile;
		}

		public $safeMode = false;

		public $memPeak = 0;
		public $memPeakFunc = null;
		public $memPeakComp = '';

		/**
		 * @param array $profile
		 * @param bool $recursion
		 *
		 * @return float
		 */
		function profilePostCall( $profile, $recursion = false ) {
			$profileFromStack = array_pop( WP_Hook_Profiled::$profileStack );
			//sw()->measure('postCallPop');

			if ( $this->safeMode && $profileFromStack['tStart'] !== $profile['tStart'] ) {
				debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
				die( 'profiler stack corrupted!' );
			}

			//sw()->measure('valStack1');

			$profile = $profileFromStack;


			$caller = count( WP_Hook_Profiled::$profileStack ) - 1;

			// time profiling
			/** @var float $tNow */
			$tNow             = microtime( true );
			$tIncl            = $tNow - $profile['tStart'];
			$profile['tIncl'] = $tIncl;
			$profile['tSelf'] += $tIncl;
			if ( $caller >= 0 ) {
				WP_Hook_Profiled::$profileStack[ $caller ]['tSelf'] -= $tIncl;
			}

			//sw()->measure('prof_t');

			
			
			// must use tag from pofile here (not $this->tag) because we can call this from all hook
			$this->addHookCall( $profile['func'], $profile['tag'], $profile, $recursion );

			return $tNow;
		}


		public static function optionNameIsTransient( $optionName ) {
			return strncmp( $optionName, '_transient_', 11 ) === 0
			       || strncmp( $optionName, '_site_transient', 15 ) === 0;
		}


		public $dbFuncTagMapTime = array();
		public $dbFuncTagMapQueries = array();

				public $cacheFuncTagMapTime = array();
		public $cacheFuncTagMapCalls = array();
		public $cacheFuncTagMapTimeMax = array();

				/**
		 * @param string $objectTag
		 * @param callable $func
		 * @param array $args
		 * @param float $timeElapsed
		 * @param bool $recursion
		 */
		public function addObjectCall( $objectTag, $func, $args, $timeElapsed, $recursion = false ) {
			if ( ! isset( $this->objectMapTime[ $objectTag ] ) ) {
				$this->objectMapTime[ $objectTag ] = 0;
			}
			$this->objectMapTime[ $objectTag ] += $timeElapsed;

												$this->addHookCall( $func, $objectTag, array(
				'tIncl'    => $timeElapsed,
				'tSelf'    => $timeElapsed,
											), $recursion );
		}


		private $lastCacheMissKey = '';
		private $lastCacheMissTime = 0;

				private $findDeadIncFiles = false; // TODO

		/**
		 * MinCost: 1inc,  1first
		 *
		 * @param $tag
		 * @param int $numCallbacks_unused
		 * @param string $callPath
		 * @param float $now now
		 *
		 * @return int the number of times this hook has been fired before + 1
		 */
		public function preHookCallbacksCall(
			$tag, /** @noinspection PhpUnusedParameterInspection */
			$numCallbacks_unused = 1, $callPath = null, $now
		) {
						$fired = self::inc( $this->hookMapFires, $tag );

			// measure from beginning of the request
			$tMs = ( $now - $this->requestTime ) * 1000;

			if ( ! isset( $this->hookFirstFiredAt[ $tag ] ) ) {
				$this->hookFirstFiredAt[ $tag ] = $tMs;
			}

			//opts
			//$this->hookMapLastCallEndTime[$tag] = $tMs;

			if ( ProfilerSettings::$default->hookLog ) {
				if ( ! ProfilerSettings::$default->hookLogExcludeCommonFilters || ! $this->isCommonFilter( $tag ) ) {
					$key = $tag . "#" . $fired;
					if ( isset( $this->hookLogPost[ $key ] ) ) {
						die( "hook $key already in log" );
					}
					$this->hookLog[ $key ] = $tMs;
				}
			}

			return $fired;
		}

		/**
		 * @param $tag
		 * @param float $now
		 * @param $hookSeq
		 * @param float $hookStartTime
		 */
		public function postHookCallbacksCall( $tag, $now, $hookSeq, $hookStartTime ) {
			$tMs      = ( $now - $this->requestTime ) * 1000;
			$inclTime = ( $now - $hookStartTime ) * 1000;

			$this->hookMapLastCallEndTime[ $tag ] = $tMs;
			self::add( $this->hookMapTimeIncl, $tag, $inclTime );

			if ( ProfilerSettings::$default->hookLog ) {
				if ( ! ProfilerSettings::$default->hookLogExcludeCommonFilters || ! $this->isCommonFilter( $tag ) ) {
					$key = $tag . "#" . $hookSeq;
					if ( isset( $this->hookLogPost[ $key ] ) ) {
						die( "hook $key already in postlog" );
					}
					$this->hookLogPost[ $key ]     = ( $now - $this->requestTime ) * 1000;
					$this->hookLogTimeIncl[ $key ] = $inclTime;
				}
			}

		}


		public function preCallObjectMethod( $objectTag ) {
			// no need to getCallPath2, we do it in addObjectCall()
		}


		private $stackEnterHook = '';

		
		/**
		 * @param string $component
		 * @param int $skipStack
		 *
		 * @param callable $componentFunc
		 *
		 * @return string
		 */
		public function getCallPath2( &$component = null, $skipStack = 3, &$componentFunc = null ) {
			$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // 0.00190 ms/call
			//sw()->measure('debug_backtrace');

			$callPath       = '';
			$inc            = array( 'require' => 1, 'require_once' => 1, 'include' => 1, 'include_once' => 1 );
			$n              = count( $backtrace );
			$pathComplete   = false; // continue our loop without building the path, but registering files!
			$component      = '';
			$componentFunc  = null;
			$wentThroughInc = false;


			// iterate up the call stack (from deepest level)
			for ( $i = $skipStack; $i < $n; $i ++ ) {
				$f = $backtrace[ $i ]['function'];
				if ( isset( $inc[ $f ] ) ) {
					$wentThroughInc = true;
					$fn             = $backtrace[ $i ]['args'][0];
					// while we are building our call path result, capture the files too!
					self::add( $this->fileMapTime, $fn, 0 );
					if ( ! $pathComplete && ! $component ) {
						// capture the first $component we can find via inc file check!
						$component = self::getComponentByFileName( $fn );
						$callPath  = $component . "/$callPath";
					}
					//$pathComplete = true;
					//break;
				} else {
					// file can be unspecified for shutdown_action_hook and __destruct
					// __destruct: actually can reconstruct the file via class name and reflection (TODO)

					if ( ! empty( $backtrace[ $i ]['file'] ) ) {
						$fn = $backtrace[ $i ]['file'];
						self::add( $this->fileMapTime, $fn, 0 ); // touch the file
						// capture the first plugin!
						$func = $backtrace[ $i ]['function'];
						if ( ! $component && self::isPluginFile( $fn ) && $func !== 'apply_filters' && ( ! is_array( $func ) || $func[1] !== 'apply_filters' ) ) {
							$component     = self::getComponentByFileName( $fn );
							$componentFunc = $backtrace[ $i ]['function'];

						}
					}

					if ( ! $pathComplete ) {
						$callPath = "$f/$callPath";
					}
				}
			}


						return $callPath;
		}


		public $hookFuncMapRecursiveCount = array();

		
		public $hookedIn = false;

		function hookIn() {
			if ( $this->hookedIn ) {
				throw new \RuntimeException( 'already hooked in' );
			}

			$this->hookedIn = true;
			WP_Hook_Profiled::setProfiler( $this );

			add_action( 'all', function ( $tag ) {
				global $wp_filter;

				$noHook = ! isset( $wp_filter[ $tag ] ) || is_null( $wp_filter[ $tag ] );

				if ( $noHook ) {
					$wp_filter[ $tag ] = new WP_Hook_Profiled( $tag, null );
				} elseif ( ! ( $wp_filter[ $tag ] instanceof WP_Hook_Profiled ) ) {
					$wp_filter[ $tag ] = new WP_Hook_Profiled( $tag, $wp_filter[ $tag ] );
				}

			} );

			/*
			add_action( ( self::getCurrentRequestGroup( true ) === 'admin' ) ? 'admin_notices' : 'wp_loaded', array(
				$this,
				'triggerTestError'
			) );
			*/

			// make muplugins_loaded appear in the stats. thats the first hook we can reliably capture
			add_action( 'muplugins_loaded', function () {
			} );
		}

		public function triggerTestError() {

			$de = ini_get( 'display_errors' );
			$er = error_reporting();
			@ini_set( 'display_errors', 1 );
			@error_reporting( E_ALL );

			$last = error_get_last();

			ob_start();
			for ( $i = 0; $i < 1; $i ++ ) {
				trigger_error( "HookProfiler test notice $i, if you see this within a page, something is wrong with your PHP config", E_USER_NOTICE );
			}

			if ( $last ) {
				$typeMap = [
					E_NOTICE     => E_USER_NOTICE,
					E_WARNING    => E_USER_WARNING,
					E_DEPRECATED => E_USER_DEPRECATED,
					E_ERROR      => E_USER_ERROR
				];
				trigger_error( "Before the profiler triggered a test error there was this error:\n" . json_encode( $last ), $typeMap[ $last['type'] ] );
			}

			ob_end_clean();

			@ini_set( 'display_errors', $de );
			@error_reporting( $er );

		}

				static function getSecret( $salt ) {
			return hash( 'sha256', $salt . '|' . get_site_option( 'secret_key' ) . ( defined( 'NONCE_SALT' ) ? NONCE_SALT : filemtime( __FILE__ ) ) . dirname( __FILE__ ) );
		}


		public static function getUploadBaseDir() {
			$uploads = wp_upload_dir();
			$dir     = empty( $uploads['basedir'] ) || ! is_dir( $uploads['basedir'] ) ? dirname( dirname( __FILE__ ) ) : $uploads['basedir'];
			if ( ! path_is_absolute( $dir ) ) {
				$dir = path_join( ABSPATH, $dir );
			}

			return $dir;
		}


		static function logMsg( $str, $passToDefaultLog = false ) {
			static $logFile = '';
			if ( ! $logFile ) {
				$suffix  = self::getSecret( 'log' );
				$logFile = path_join( self::getUploadBaseDir(), "._hprof-$suffix.log" );
			}

			if ( ! is_string( $str ) ) {
				$str = json_encode( $str );
			}

			$msg = date( 'c' ) . ': ' . $str . "\n";
			error_log( $msg, 3, $logFile );
			if ( $passToDefaultLog ) {
				error_log( $msg );
			}
		}

		private $replacedPluginLoader = false;

		function replacePluginLoader() {
			$this->replacedPluginLoader = true;

			$pluginsFilterMs = null;

			// multisite
			add_filter( 'site_option_active_sitewide_plugins', $pluginsFilterMs = function ( $active_plugins ) use ( &$pluginsFilterMs ) {

				// single call guard
				static $filtered = false;
				if ( $filtered ) {
					return $active_plugins;
				}
				$filtered = true;

				if ( ! remove_filter( 'site_option_active_sitewide_plugins', $pluginsFilterMs, 1e9 ) ) {
					var_dump( $pluginsFilterMs );
					die( 'failed to remove option_active_plugins' );
				}


				$start = microtime( true );

				// the following was taken from ms-load.php, wp_get_active_network_plugins()
				if ( empty( $active_plugins ) ) {
					return array();
				}

				$plugins        = array();
				$active_plugins = array_keys( $active_plugins );
				sort( $active_plugins );

				foreach ( $active_plugins as $plugin ) {
					if ( ! validate_file( $plugin ) // $plugin must validate as file
					     && '.php' == substr( $plugin, - 4 ) // $plugin must end with '.php'
					     && file_exists( WP_PLUGIN_DIR . '/' . $plugin ) // $plugin must exist
					) {
						$plugins[] = WP_PLUGIN_DIR . '/' . $plugin;
					}
				}


				$now = microtime( true );

				// the loop was taken from wp-settings.php
				foreach ( $plugins as $network_plugin ) {
					$now = $this->loadPluginMainProfiled( $network_plugin, $now );
				}


				// dont blame the option hook
				self::add( $this->hookMapTimeIncl, 'site_option_active_sitewide_plugins', - ( microtime( true ) - $start ) * 1000 );

				//@$this->hookFuncMapTimeInclMapTimeIncl['site_option_active_sitewide_plugins'] -= ( microtime( true ) - $start ) * 1000;


				// tell WP not to load anything
				return array();
			}, 1e9, 2 );


			$pluginsFilter = null;
			add_filter( 'option_active_plugins', $pluginsFilter = function (
				$active_plugins
			) use ( &$pluginsFilter ) {
				// single call guard
				static $filtered = false;
				if ( $filtered ) {
					return $active_plugins;
				}
				$filtered = true;

				// this should be triggered by wp_get_active_and_valid_plugins() in wp-settings.php:301
				// and after `muplugins_loaded` action

				if ( ! remove_filter( 'option_active_plugins', $pluginsFilter, 1e9 ) ) {
					var_dump( $pluginsFilter );
					die( 'failed to remove option_active_plugins' );
				}


				$start = microtime( true );

				// the following was taken from load.php, wp_get_active_plugins()
				$plugins = array();

				// the following was taken from
				if ( empty( $active_plugins ) || wp_installing() ) {
					return $plugins;
				}

				$network_plugins = is_multisite() ? wp_get_active_network_plugins() : false;

				foreach ( $active_plugins as $plugin ) {
					if ( ! validate_file( $plugin ) // $plugin must validate as file
					     && '.php' == substr( $plugin, - 4 ) // $plugin must end with '.php'
					     && file_exists( WP_PLUGIN_DIR . '/' . $plugin ) // $plugin must exist
					     // not already included as a network plugin
					     && ( ! $network_plugins || ! in_array( WP_PLUGIN_DIR . '/' . $plugin, $network_plugins ) )
					) {
						$plugins[] = WP_PLUGIN_DIR . '/' . $plugin;
					}
				}

				$now = microtime( true );

				// the loop was taken from wp-settings.php
				foreach ( $plugins as $plugin ) {
					$now = $this->loadPluginMainProfiled( $plugin, $now );
				}

				// dont blame the option hook
				self::add( $this->hookMapTimeIncl, 'option_active_plugins', - ( microtime( true ) - $start ) * 1000 );

				// tell WP not to load anything
				return $active_plugins;
			}, 1e9, 2 );
		}


		static function findGlobalsInPHPFile( $phpFile ) {

			// TODO: need a fast cache for this, transients are too slow
			// note that a 4k line file takes about 2ms to scan, with minimal memory only!

			$allGlobals = array();

			$fh = fopen( $phpFile, "r" );
			if ( ! $fh ) {
				return $allGlobals;
			}

			while ( ( $line = fgets( $fh ) ) !== false ) {
				$p = strpos( $line, 'global' );
				if ( $p !== false && preg_match( '/^\s*global\s+\$/', $line ) ) {
					$globalsStr = '';
					while ( true ) {
						$globalsStr .= $line;
						if ( ( $p2 = strpos( $line, ';' ) ) !== false ) {
							break;
						}
						if ( ( $line = fgets( $fh ) ) === false ) {
							$globalsStr .= ';';
							break;
						}
					}
					$globalsStr = substr( preg_replace( '/\s+/', '', $globalsStr ), strlen( 'global' ), - 1 );

					foreach ( explode( ',', $globalsStr ) as $g ) {
						$g = substr( $g, 1 );
						if ( strpos( $g, '{' ) === false ) {
							$allGlobals[ $g ] = 1;
						}
					}
				}
			}
			fclose( $fh );

			// these globals are not available during plugin load
			unset( $allGlobals['post'], $allGlobals['wp_current_filter'], $allGlobals['post_ID'], $allGlobals['wp_query'], $allGlobals['error'] );

			$allGlobals = array_keys( $allGlobals );

			return $allGlobals;
		}


		public $componentMapLoadTime = array();

		function loadPluginMainProfiled( $__plugin, /** @noinspection PhpUnusedParameterInspection */$_now ) {
			wp_register_plugin_realpath( $__plugin );

			// A priori globals registration: if a plugin set a global during include inside a function,
			// this global has to appear in the symbol table in outer scope of the main plugin files
			// (WP includes plugins in global context, we not!)
			// we just scan the main file for `globabl $..., $...;`. this is not ideal, because it does not cover
			// globals in included files. we should either do a test-inclusion (in a test-inclusion request), and capture
			// the globals registered by the plugin. or we could just put the plugin loader in the global context
			//sw()->start();
			foreach ( self::findGlobalsInPHPFile( $__plugin ) as $name ) {
				if ( ! isset( $GLOBALS[ $name ] ) ) {
					$GLOBALS[ $name ] = null;
				}
			}
			//sw()->measure($__plugin);

			// import globals to local context as references
			foreach ( $GLOBALS as $name => &$value ) {
				$$name =& $value;
			}
			unset( $name, $value );

			$plugin = $__plugin;

			//echo "loading $plugin...\n";

			$_t       = microtime( true );
			$_profile = self::profilePreCall( "#plugin_main_$plugin", "load_plugins", $_t );
			/** @noinspection PhpIncludeInspection */
			include_once( $plugin );
			$_now = self::profilePostCall( $_profile );

			// export locals to globals
			$vars = get_defined_vars();
			unset( $vars['plugin'], $vars['this'], $vars['_profile'], $vars['_now'], $vars['_t'], $vars['__plugin'] );
			foreach ( array_keys( $vars ) as $name ) {
				$GLOBALS[ $name ] = $$name;
			}

			//echo self::getComponentByFileName( $__plugin ) . (( microtime( true ) - $_t ) * 1000)."\n";

			$this->componentMapLoadTime[ self::getComponentByFileName( $__plugin ) ] = ( microtime( true ) - $_t ) * 1000;

			return $_now;
		}

		private $benchmarkMode = false;

		public function enableBenchmarkMode() {
			$this->benchmarkMode = true;
		}

		public function isInBenchmarkMode() {
			return $this->benchmarkMode;
		}

				public $disabledOPCache = false;

		public function disableOPCache() {
			if ( ! function_exists( 'opcache_reset' ) ) {
				return false;
			}

			if ( $this->disabledOPCache ) {
				return true;
			}

			@ini_set( 'opcache.enable', 'Off' );
			@ini_set( 'opcache.optimization_level', '0x0' );

			register_shutdown_function( function () {
				register_shutdown_function( function () {
					opcache_reset();
				} );
			} );

			$this->disabledOPCache = opcache_reset();

			$this->disabledOPCache = true;

			return $this->disabledOPCache;
		}

		public $detectedWrongCacheImpl = false;

				public $profileMem = false;

		
		function _autoloadCounter() {
			$this->autoloaderCalls ++;
		}

		function enableAutoloadCounter() {
			// its important to append!
			spl_autoload_register( array( $this, '_autoloadCounter' ), true, true );
		}

		public static function getCurrentRequestGroup( $ignoreContentType = false ) {
			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				return "cron";
			}
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return "rest";
			}
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				return "ajax";
			}

			$isHtml = $ignoreContentType;
			foreach ( headers_list() as $head ) {
				if ( strncasecmp( "content-type: text/html", $head, 23 ) == 0 ) {
					$isHtml = true;
				}
			}

			if ( is_admin() ) {
				return $isHtml ? "admin" : "admin-non-html";
			}

			return $isHtml ? "frontend" : "frontend-non-html";
		}


		public function profileAutoloadFunctions( $iterations = 1000 ) {
			$ac                        = $this->autoloaderCalls;
			$this->autoloadFuncMapTime = array();
			$timings                   = array();
			$cn                        = array_map( 'uniqid', array_fill( 0, min( 200, $iterations ), "WP_NonExisting_" ) );
			foreach ( spl_autoload_functions() as $func ) {
				$t = microtime( true );
				for ( $i = 0; $i < $iterations; $i ++ ) {
					call_user_func( $func, $cn[ $i % 200 ] );
				}
				$t             = microtime( true ) - $t;
				$k             = $this->getFuncId( $func );
				$timings[ $k ] = $t / $iterations;

				$this->hookFuncMapFuncTimeSelf["autoload#$k"] = $t / $iterations; // also add these to our func capture
				$this->hookFuncMapTimeIncl["autoload#$k"]     = $t / $iterations; // also add these to our func capture
				// @$this->funcMapCalls[$k] = 1; // dont mess up our stats, so just pretend it was 1 call
				$this->funcs[ $k ]            = $func;
				$this->funcMapComponent[ $k ] = self::getComponentByFileName( self::getFunctionFileName( $func ) );
			}

			// restore original (just in case)
			$this->autoloaderCalls = $ac;

			// store these timings
			$this->autoloadFuncMapTime = $timings;

			return $timings;
		}

		public $pluginMapDisabled = array();

		function disableAllPluginsBut( $slugs ) {
			if ( ! is_array( $slugs ) ) {
				$slugs = array( $slugs );
			}

			if ( did_action( 'plugins_loaded' ) ) {
				throw new \RuntimeException( "cant disable plugins after plugins_loaded!" );
			}

			add_filter( "option_active_plugins", function ( $plugins ) use ( $slugs ) {
				foreach ( $plugins as $i => $pl ) {
					$dis = true;
					foreach ( $slugs as $slug ) {
						if ( strncmp( $slug, $pl, strlen( $slug ) ) === 0 ) {
							$dis = false;
							break;
						}
					}

					if ( $dis ) {
						$this->pluginMapDisabled[ $plugins[ $i ] ] = 1;
						unset( $plugins[ $i ] );
					}
				}

				return $plugins;
			} );

			add_action( 'hook_prof_end', function () {
				if ( $this->itIsSafeToAppendHtml() ) {
					echo "\n<!-- hprof-disable-all-plugins-but:\n";
					print_r( get_option( 'active_plugins' ) );
					echo "\n-->\n";
				}
			} );
		}

		function disablePlugin( $slug ) {
			if ( did_action( 'plugins_loaded' ) ) {
				throw new \RuntimeException( "cant disable plugin $slug after plugins_loaded!" );
			}

			add_filter( "option_active_plugins", function ( $plugins ) use ( $slug ) {
				foreach ( $plugins as $i => $pl ) {
					if ( strncmp( $slug, $pl, strlen( $slug ) ) == 0 ) {
						$this->pluginMapDisabled[ $plugins[ $i ] ] = 1;
						unset( $plugins[ $i ] );
					}
				}

				return $plugins;
			} );
		}


		public
		static function intHash(
			$str
		) {
			$key = unpack( 'q', hash( 'md4', $str, true ) );

			return abs( $key[1] % PHP_INT_MAX );
		}

		private
			$classObjectMapId = array();

		function getFuncId( $function ) {

			// check for virtual funcs (such as plugin main files)
			if ( is_string( $function ) && $function{0} == '#' ) {
				if ( strncmp( '#plugin_main_', $function, 13 ) === 0 ) {
					$slug = trim( substr( $function, 13 + strlen( WP_PLUGIN_DIR ) ), '/\\' );

					return '#plugin_main#' . $slug;
				}
			}

			if ( is_string( $function ) ) {
				return $function;
			}

			if ( is_object( $function ) ) {
				// Closures
				$fn = self::getFunctionFileName( $function, '', $line );

				return "Closure@" . basename( $fn ) . ":" . $line;
			} else {
				$function = (array) $function;
			}

			if ( is_object( $function[0] ) ) {
				$cl = get_class( $function[0] );
				// Object Class Calling

				$oh = spl_object_hash( $function[0] );
				// count objects by class
				if ( ! isset( $this->classObjectMapId[ $cl ][ $oh ] ) ) {
					$this->classObjectMapId[ $cl ][ $oh ] = isset( $this->classObjectMapId[ $cl ] ) ? count( $this->classObjectMapId[ $cl ] ) : 0;
				}
				$oi = $this->classObjectMapId[ $cl ][ $oh ];

				return self::stripNamespace( $cl ) . "@$oi->" . $function[1];

			} elseif ( is_string( $function[0] ) ) {
				// Static Calling
				return $function[0] . '::' . $function[1];
			}

			return false;
		}


		/**
		 * @param callable $func
		 *
		 * @param string $funcId
		 *
		 * @param int $line
		 *
		 * @return string
		 */
		function getFunctionFileName( $func, $funcId = '', &$line = null ) {
			if ( $funcId && isset( $this->funcFileNameCache[ $funcId ] ) ) {
				$fl   = $this->funcFileNameCache[ $funcId ];
				$line = $fl[1];

				return $fl[0];
			}

			// check for virtual funcs (such as plugin main files)
			if ( is_string( $func ) && $func{0} == '#' ) {
				if ( strncmp( '#plugin_main', $func, 12 ) === 0 ) {
					$line = 0;
					$slug = substr( $func, 13 );

					return $slug;
				}
			}

			/** @var \Reflector $rf */
			$rf = null;

			if ( is_string( $func ) && strpos( $func, '::' ) !== false ) {
				$func = explode( "::", $func );
			}

			if ( is_array( $func ) ) {
				$p = strpos( $func[1], '(' ); // rm args()
				if ( $p !== false ) {
					$func[1] = substr( $func[1], 0, $p );
				}

				$class = is_object( $func[0] ) ? get_class( $func[0] ) : $func[0];

				try {
					$rf = new \ReflectionMethod ( $class, $func[1] );
				} catch ( \Exception $e ) {
					try {
						// may fail due to virtual method (`__call`)
						$rf = new \ReflectionMethod ( $class, '__call' );
					} catch ( \Exception $e2 ) {
						// fallback to class declaration
						$rf = new \ReflectionClass( $class );
					}
				}
			} else {
				try {
					$rf = new \ReflectionFunction( $func );
				} catch ( \Exception $e ) {
					$line = 0;

					return '';
				}
			}

			$fn   = str_replace( '\\', '/', $rf->getFileName() );
			$line = $rf->getStartLine();

			if ( $funcId ) {
				$this->funcFileNameCache[ $funcId ] = [ $fn, $line ];
			}

			return $fn;
		}


		public
		function computeCaptureGroups() {
			$pluginMapTime    = array();
			$funcFiles        = array();
			$pluginMapMem     = array();
			$pluginMapIncs    = array();
			$pluginMapIncSize = array();

			$incFiles      = get_included_files();
			$incFilesSizes = FileStatsCache::getSizes( $incFiles );

			foreach ( array_keys( $this->hookFuncMapCalls ) as $hookFunc ) {
				$funcId = HookProfiler::rmTag( $hookFunc );

				$fn = $this->getFunctionFileName( $this->funcs[ $funcId ], $funcId, $line );

				$funcFiles[ $funcId ] = $fn . ":" . $line;

				$slug = self::getComponentByFileName( $fn );

				$t = $this->hookFuncMapFuncTimeSelf[ $hookFunc ];

				self::add( $pluginMapTime, $slug, $t );
				self::add( $this->fileMapTime, $fn, $t );
				$this->funcMapComponent[ $funcId ] = $slug;

								//self::add($pluginMapIncs, $slug , $this->funcMapIncsSelf[ $k ]);
			}

						return array(
				'plugins'          => $pluginMapTime,
				'files'            => &$this->fileMapTime,
				'funcLocations'    => $funcFiles,
				'componentMapMem'  => $pluginMapMem,
				'pluginMapIncs'    => $pluginMapIncs,
				'pluginMapIncSize' => $pluginMapIncSize
			);
		}

		static function isPluginFile( $fn ) {
			$pdl = strlen( WP_PLUGIN_DIR );

			return strncmp( $fn, WP_PLUGIN_DIR, $pdl ) === 0;
		}

		static function getComponentByFileName( $fn ) {
			static $cache = array();

			if ( isset( $cache[ $fn ] ) ) {
				return $cache[ $fn ];
			}

			global $wp_theme_directories;
			$pdl = strlen( WP_PLUGIN_DIR );

			if ( strncmp( $fn, WP_PLUGIN_DIR, $pdl ) === 0 ) {
				return 'plugin/' . substr( $fn, $pdl + 1, strpos( $fn, '/', $pdl + 1 ) - $pdl - 1 );
			}

			if ( $wp_theme_directories ) {
				foreach ( $wp_theme_directories as $dir ) {
					$l = strlen( $dir );
					if ( strncmp( $fn, $dir, $l ) === 0 ) {
						return 'theme /' . substr( $fn, $l + 1, strpos( $fn, '/', $l + 1 ) - $l - 1 );
					}
				}
			}

			$pdl = strlen( WPMU_PLUGIN_DIR );
			if ( strncmp( $fn, WPMU_PLUGIN_DIR, $pdl ) === 0 ) {
				return 'muplug/' . substr( $fn, $pdl + 1, strpos( $fn, '.', $pdl + 1 ) - $pdl - 1 );
			}


			return ( $cache[ $fn ] = ( 'wpcore/' . substr( basename( $fn ), 0, - 4 ) ) );
		}

		public static function getActivePlugins( $validateInclusion = false ) {
			// note that active_sitewide_plugins is assoc, active_plugins is numeric indexed
			$activePlugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$activePlugins = array_filter( array_merge( $activePlugins, (array) get_option( 'active_plugins', array() ) ) );

			if ( $validateInclusion ) {
				$incFiles = get_included_files();
				$incFiles = array_combine( $incFiles, $incFiles );
				foreach ( $activePlugins as $mainFile ) {
					$mainFile = WP_PLUGIN_DIR . '/' . $mainFile;
					if ( ! isset( $incFiles[ $mainFile ] ) ) {
						return false;
					}
				}
			}

			return $activePlugins;
		}

		public static function getActivePluginsHash( $validateInclusion = false ) {
			$activePlugins = self::getActivePlugins( $validateInclusion );

			return self::getPluginsHash( $activePlugins );
		}

		public static function getPluginsHash( $plugins ) {
			sort( $plugins );

			return self::intHash( implode( '|', $plugins ) );
		}

		/**
		 * Get everything AFTER the hashtag (if any)
		 *
		 * @param $key
		 *
		 * @return bool|string
		 */
		public static function rmTag( $key ) {
			$p = strpos( $key, '#' );
			if ( $p !== false && $p !== 0 ) {
				return substr( $key, $p + 1 );
			}

			return $key;
		}

		public static function rmArgs( $funcId ) {
			$p = strpos( $funcId, '(' ); // rm args()
			if ( $p !== false ) {
				$funcId = substr( $funcId, 0, $p );
			}

			return $funcId;
		}


		public static function rmFunc( $hookFunc ) {
			$p = strpos( $hookFunc, '#' );
			if ( $p !== false && $p !== 0 ) {
				return substr( $hookFunc, 0, $p );
			}

			return $hookFunc;
		}


		public
		static function getCommonRequestActions() {
			return array(
				'muplugins_loaded',
				'setup_theme',
				'init',
				'wp_loaded',
				'wp',
				'wp_head',
				'wp_print_scripts',
				'loop_start',
				'parse_comment_query',
				'loop_end',
				'get_footer'
			);
		}


		public
		static function getUsuallyTimeIntenseActions() {
			return array(
				'plugins_loaded',
				'init',
				'wp_head',
				'wp_footer',
				'admin_bar_menu', // adm
				'wp_enqueue_scripts',
				'widgets_init',
				'after_setup_theme',
				'template_redirect',
				'body_class',
				'wp_loaded',
				'wp_print_scripts',
				'set_current_user',
				'the_content'

			);
		}

		public function isCommonFilter( $hook, $orOptionFilter = true ) {
			// site_url
			// admin_url
			// get​_user​_metadata
			// clean​_url
			//wp​_parse​_str

			//

			// gettext
			//esc​_html
			//user​_has​_cap
			static $commonFilters = [
				'gettext'                 => 1,
				'esc_html'                => 1,
				'map_meta_cap'            => 1,
				'user_has_cap'            => 1,
				'attribute_escape'        => 1,
				'set_url_scheme'          => 1,
				'option_siteurl'          => 1,
				'pre_option_siteurl'      => 1,
				'site_url'                => 1,
				'admin_url'               => 1,
				'gettext_with_context'    => 1,
				'sanitize_key'            => 1,
				'clean_url'               => 1,
				'plugins_url'             => 1,
				'wp_parse_str'            => 1,
				'no_texturize_tags'       => 1,
				'no_texturize_shortcodes' => 1
			];

			static $optPrefixes = [
				'default​_site​_option​_',
				'default_option_',
				'pre​_site​_option​_',
				'option_',
				'pre_option'
			];


			if ( isset( $commonFilters[ $hook ] ) ) {
				return true;
			}

			if ( $orOptionFilter ) {
				foreach ( $optPrefixes as $pr ) {
					if ( strncmp( $hook, $pr, strlen( $pr ) ) === 0 ) {
						return true;
					}
				}
			}

			return false;
		}

		public static function getSharedHookTag( $sharedTag, $requestGroup = '' ) {
			if ( empty( $requestGroup ) ) {
				$requestGroup = self::getCurrentRequestGroup();
			}
			$forAdmin = ( $requestGroup == 'admin' ) || ( $requestGroup == 'admin-non-html' );
			$tags     = array(
				'enqueue_scripts' => $forAdmin ? 'admin_enqueue_scripts' : 'wp_enqueue_scripts'
			);

			if ( ! isset( $tags[ $sharedTag ] ) ) {
				throw new \RuntimeException( "unknown shared tag name $sharedTag" );
			}

			return $tags[ $sharedTag ];
		}


		public
		static function getNamedRequestFramesTriggers(
			$requestGroup = ''
		) {
			if ( empty( $requestGroup ) ) {
				$requestGroup = self::getCurrentRequestGroup();
			}

			$forAdmin = ( $requestGroup == 'admin' ) || ( $requestGroup == 'admin-non-html' );

			return array(
				'muplugins'         => 'muplugins_loaded|', // wp-setttings
				'plugins_included'  => '|plugins_loaded',
				'plugins_loaded'    => 'plugins_loaded|',// wp-setttings
				'registrations'     => '|setup_theme',// wp-setttings
				'setup_theme'       => '|after_setup_theme',
				'after_setup_theme' => 'after_setup_theme|',
				//'after_setup_theme__locales' => 'after_setup_theme|',// wp-setttings
				'get_current_user'  => '|init',// wp-setttings (get_current_user aka wp->init())
				'init_after'        => 'init|',// single

				'wp_loaded_after' => 'wp_loaded|',

				// after init comes wp_loaded, there should be nothing in between

				// do_parse_request
				//'wp_loaded__widgets,sidebar' => 'send_headers|',// single

				// then ther is do_parse_request, but front end only!

				// this was all in wp-settings.php
				//'posts'           => '|pre_get_posts',
				//'wp__parse_query' => '|wp',// single
				//'header'          => [ 'wp_print_scripts', '|loop_start' ],


				// dont include parse query, on frontend its usually pre-header
				// in admin it does not occur, or after header
				'pre_parse_query' => $forAdmin ? null : '|parse_query',
				'parse_query'     => $forAdmin ? null : '|pre_get_posts',

				'admin_menu' => ! $forAdmin ? null : 'admin_menu',

				'found_posts' => '|found_posts', // can happen multiple times, catch the first!
				'posts_cache' => '|pre_handle_404',

				'wp_pre'               => '|wp',
				'wp'                   => 'wp|',
				// wp
				'template_redirec_pre' => $forAdmin ? null : '|template_redirect',
				'template_redirect'    => $forAdmin ? null : 'template_redirect|',

				'wp_head_before' => $forAdmin ? null : '|wp_head',// single

				'enqueued_scripts'  => $forAdmin ? 'admin_enqueue_scripts|' : '|wp_enqueue_scripts', // single?
				'enqueued_scripts2' => 'wp_enqueue_scripts|', // single?
				'enqueued_scripts3' => '|print_head_scripts|', // single?
				'wp_print_scripts'  => 'print_head_scripts|', // single!
				'wp_head_after'     => $forAdmin ? null : 'wp_head|',// single


				'body_class' => $forAdmin ? 'admin_body_class|' : 'body_class|',

				'adminmenu_after_output' => ( $requestGroup == 'admin' ) ? 'adminmenu|' : null,

				'admin_bar_menu_pre' => ! $forAdmin ? null : '|admin_bar_menu',
				'admin_bar_menu'     => ! $forAdmin ? null : 'admin_bar_menu|',
				'admin_notices'      => ! $forAdmin ? null : 'admin_notices|',

				// 10ms, this is the theme # get_template_part_template-parts/header/header#1
				'loop_start_first'   => $forAdmin ? null : '|loop_start',
				//'pre_the_content'    => $forAdmin ? null : '|the_content', // this breaks
				'the_content'        => $forAdmin ? null : '|loop_end',
				'loop_end_last'      => $forAdmin ? null : 'loop_end|',

				'admin_content' => ! $forAdmin ? null : '|in_admin_footer',


				//'content' => ( $requestGroup === 'frontend' ) ? '|parse_comment_query' : null, // this breaks

				'content_post' => ( $requestGroup === 'frontend' ) ? '|wp_footer' : null,

				'print_footer_scripts' => $forAdmin ? null : 'print_footer_scripts|',

				'admin_bar_menu_front_pre' => $forAdmin ? null : '|admin_bar_menu',
				'admin_bar_menu_front'     => $forAdmin ? null : 'admin_bar_menu|',

				'wp_footer' => 'wp_footer|',


				//'sidebar'  => [ '|get_footer' ],
				'footer'    => [ '|shutdown' ],
				'shutdown'  => '|hook_prof_end', // this is our own action from the desctructor


			);
		}

		public
		function computeCommonActionFrameTimes(
			$requestGroup = ''
		) {
			if ( empty( $requestGroup ) ) {
				$requestGroup = self::getCurrentRequestGroup();
			}

			$frames = self::getNamedRequestFramesTriggers( $requestGroup );

			$frameTimes           = array();
			$frameTimesCumulative = array();

			$frameTimes['00 request_start .. wpload .. preinit']           = ( $this->timestart - $this->requestTime ) * 1000;
			$frameTimesCumulative['00 request_start .. wpload .. preinit'] = ( $this->timestart - $this->requestTime ) * 1000;

			$frameTimes['01 preinit .. wp-settings .. profiler_start']                        = ( $this->tStart - $this->timestart ) * 1000;
			$frameTimesCumulative[ $prevKey = '01 preinit .. wp-settings .. profiler_start' ] = ( $this->tStart - $this->requestTime ) * 1000;


			$prevTrigger = 'profiler_start';
			$tPrev       = ( $this->tStart - $this->requestTime ) * 1000; // we measure relative to requestTime

			$i = 1;
			foreach ( $frames as $name => $endTriggers ) {
				$endTriggers = (array) $endTriggers;

				// just find the first matching action
				foreach ( $endTriggers as $trigger ) {
					$first = ( $trigger{0} == '|' );
					$trig  = trim( $trigger, '|' );
					if ( $first ? isset( $this->hookFirstFiredAt[ $trig ] ) : isset( $this->hookMapLastCallEndTime[ $trig ] ) ) {
						$t                             = $first ? $this->hookFirstFiredAt[ $trig ] : $this->hookMapLastCallEndTime[ $trig ];
						$dt                            = $t - $tPrev;
						$key                           = sprintf( '%02d %s .. %s .. %s', ++ $i, $prevTrigger, $name, $trigger );
						$frameTimes[ $key ]            = $dt;
						$frameTimesCumulative [ $key ] = $frameTimesCumulative[ $prevKey ] + $dt;
						$tPrev                         = $t;
						$prevTrigger                   = $trigger;
						$prevKey                       = $key;
						break;
					}
				}

			}

			return [ $frameTimes, $frameTimesCumulative ];
		}


		public
		function getCommonActionFireTimes() {
			$frames = self::getNamedRequestFramesTriggers();

			$firedAt = array();

			$firedAt['timestart']      = ( $this->timestart - $this->requestTime ) * 1000;
			$firedAt['profiler_start'] = ( $this->tStart - $this->requestTime ) * 1000;


			// all following times in hookFirstFiredAt, hookMapLastCallEndTime are ms and relative to tStart
			foreach ( $frames as $name => $endTriggers ) {
				$endTriggers = (array) $endTriggers;

				// just find the first matching action
				foreach ( $endTriggers as $trigger ) {
					$first = ( $trigger{0} == '|' );
					$trig  = trim( $trigger, '|' );
					if ( $first ? isset( $this->hookFirstFiredAt[ $trig ] ) : isset( $this->hookMapLastCallEndTime[ $trig ] ) ) {
						$t                = $first ? $this->hookFirstFiredAt[ $trig ] : $this->hookMapLastCallEndTime[ $trig ];
						$firedAt[ $name ] = $t + $firedAt['profiler_start']; // we want time rel to requestTime
					}
				}

			}

			return $firedAt;
		}


		public
		function getNumCapturedCalls() {
			return array_sum( $this->hookFuncMapCalls );
		}

		public
		function getNumCapturedFires() {
			return array_sum( $this->hookMapFires );
		}

		public
		function getTotalRecoverdOutOfStackTime() {
			return array_sum( $this->componentMapGapTime );
		}

		public
		function getTotalUnclassifiedOutOfStackTime() {
			return array_sum( $this->hookGapMapUnclassifiedTime );
		}

		public
		function getTotalRunTime() {
			return $this->totalTimeRequest;
		}

		public function getWPIncTime() {
			return ( $this->tStart - $this->requestTime ) * 1000;
		}

		function getTotalInHookTime() {
			return array_sum( $this->hookFuncMapFuncTimeSelf );
		}

		public
		function getPluginByFunction(
			$key
		) {
			if ( ! isset( $this->funcMapComponent[ $key ] ) ) {
				$knt                            = self::rmTag( $key );
				$this->funcMapComponent[ $knt ] = isset( $this->funcs[ $knt ] ) ?
					self::getComponentByFileName( $this->getFunctionFileName( $this->funcs[ $knt ] ) )
					: '';
				if ( $key !== $knt ) {
					// redundant cache storage, this time with the tag
					$this->funcMapComponent[ $key ] = $this->funcMapComponent[ $knt ];
				}
			}

			return $this->funcMapComponent[ $key ];
		}

		static private function stripNamespace( $className ) {
			$p = strrpos( $className, '\\' );
			if ( $p === false ) {
				return $className;
			}
			$cn = substr( $className, $p + 1 );

			// might keep namespace if not strong
			return ( strlen( $cn ) > 6 && strpos( $cn, '_' ) !== false )
				? $cn : $className;
		}
	}


	final class WP_Hook_Profiled implements \Iterator, \ArrayAccess {
		public $callbacks = array();
		private $iterations = array();
		private $current_priority = array();
		private $nesting_level = 0;
		private $doing_action = false;

		private $tag;


		/**
		 * WP_Hook_Profiled constructor.
		 *
		 * @param $tag
		 * @param \WP_Hook $originalHook
		 */
		function __construct( $tag, $originalHook ) {
			if ( ! is_string( $tag ) || empty( $tag ) ) {
				die( 'invalid tag' );
			}
			$this->tag = $tag;

			if ( $originalHook !== null ) {
				if ( ! ( $originalHook instanceof \WP_Hook ) ) {
					die( 'invalid originalHook' );
				}
				$this->callbacks = $originalHook->callbacks;
			}
		}

		public function add_filter( $tag, $function_to_add, $priority, $accepted_args ) {
			if ( $this->tag !== $tag ) {
				die( 'cant add_filter, tag mismatch!' );
			}


			$idx              = _wp_filter_build_unique_id( $tag, $function_to_add, $priority );
			$priority_existed = isset( $this->callbacks[ $priority ] );

			$this->callbacks[ $priority ][ $idx ] = array(
				'function'      => $function_to_add,
				'accepted_args' => $accepted_args
			);

			// if we're adding a new priority to the list, put them back in sorted order
			if ( ! $priority_existed && count( $this->callbacks ) > 1 ) {
				ksort( $this->callbacks, SORT_NUMERIC );
			}

			if ( $this->nesting_level > 0 ) {
				$this->resort_active_iterations( $priority, $priority_existed );
			}

			// todo currently not needed, but we can use it to find hook "owners"
			//self::$profiler->hookAdded( $tag, $function_to_add );
		}

		private function resort_active_iterations( $new_priority = false, $priority_existed = false ) {
			$new_priorities = array_keys( $this->callbacks );

			// If there are no remaining hooks, clear out all running iterations.
			if ( ! $new_priorities ) {
				foreach ( $this->iterations as $index => $iteration ) {
					$this->iterations[ $index ] = $new_priorities;
				}

				return;
			}

			$min = min( $new_priorities );
			foreach ( $this->iterations as $index => &$iteration ) {
				$current = current( $iteration );
				// If we're already at the end of this iteration, just leave the array pointer where it is.
				if ( false === $current ) {
					continue;
				}

				$iteration = $new_priorities;

				if ( $current < $min ) {
					array_unshift( $iteration, $current );
					continue;
				}

				while ( current( $iteration ) < $current ) {
					if ( false === next( $iteration ) ) {
						break;
					}
				}

				// If we have a new priority that didn't exist, but ::apply_filters() or ::do_action() thinks it's the current priority...
				if ( $new_priority === $this->current_priority[ $index ] && ! $priority_existed ) {
					/*
					 * ... and the new priority is the same as what $this->iterations thinks is the previous
					 * priority, we need to move back to it.
					 */

					if ( false === current( $iteration ) ) {
						// If we've already moved off the end of the array, go back to the last element.
						$prev = end( $iteration );
					} else {
						// Otherwise, just go back to the previous element.
						$prev = prev( $iteration );
					}
					if ( false === $prev ) {
						// Start of the array. Reset, and go about our day.
						reset( $iteration );
					} elseif ( $new_priority !== $prev ) {
						// Previous wasn't the same. Move forward again.
						next( $iteration );
					}
				}
			}
			unset( $iteration );
		}

		public function remove_filter( $tag, $function_to_remove, $priority ) {
			$function_key = _wp_filter_build_unique_id( $tag, $function_to_remove, $priority );

			$exists = isset( $this->callbacks[ $priority ][ $function_key ] );
			if ( $exists ) {
				unset( $this->callbacks[ $priority ][ $function_key ] );
				if ( ! $this->callbacks[ $priority ] ) {
					unset( $this->callbacks[ $priority ] );
					if ( $this->nesting_level > 0 ) {
						$this->resort_active_iterations();
					}
				}
			}

			return $exists;
		}


		/**
		 * @param string $tag
		 * @param callable $function_to_check
		 *
		 * @return bool|int|string
		 */
		public function has_filter( $tag = '', $function_to_check = null ) {
			if ( false === $function_to_check || null === $function_to_check ) {
				return $this->has_filters();
			}

			$function_key = _wp_filter_build_unique_id( $tag, $function_to_check, false );
			if ( ! $function_key ) {
				return false;
			}

			foreach ( $this->callbacks as $priority => $callbacks ) {
				if ( isset( $callbacks[ $function_key ] ) ) {
					return $priority;
				}
			}

			return false;
		}

		public function has_filters() {
			foreach ( $this->callbacks as $callbacks ) {
				if ( $callbacks ) {
					return true;
				}
			}

			return false;
		}

		public function remove_all_filters( $priority = false ) {
			if ( ! $this->callbacks ) {
				return;
			}

			if ( false === $priority ) {
				$this->callbacks = array();
			} else if ( isset( $this->callbacks[ $priority ] ) ) {
				unset( $this->callbacks[ $priority ] );
			}

			if ( $this->nesting_level > 0 ) {
				$this->resort_active_iterations();
			}
		}

		/**
		 * This is the global hook stack
		 *
		 * @var array
		 */
		public static $hookStack = array();


		/**
		 * Processes the functions hooked into the 'all' hook.
		 *
		 * @since 4.7.0
		 * @access public
		 *
		 * @param array $args Arguments to pass to the hook callbacks. Passed by reference.
		 */

		// TODO: we do not currently profiler the all hook
		public function do_all_hook( &$args ) {
			$nesting_level                      = $this->nesting_level ++;
			$this->iterations[ $nesting_level ] = array_keys( $this->callbacks );

			do {
				$priority = current( $this->iterations[ $nesting_level ] );
				foreach ( $this->callbacks[ $priority ] as $the_ ) {
					@call_user_func_array( $the_['function'], $args );
				}
			} while ( false !== next( $this->iterations[ $nesting_level ] ) );

			unset( $this->iterations[ $nesting_level ] );
			$this->nesting_level --;
		}


		/**
		 * @var HookProfiler
		 */
		static private $profiler;

		static private $outOfStackRecovery = false;
		static private $detectRecursion = false;

		/**
		 * MinCost: 1 microtime, 1 preHookCallbacksCall, 1 push
		 *
		 * @param $value
		 * @param $args
		 *
		 * @return mixed
		 */
		public function apply_filters( $value, $args ) {


			// optimization:
			if ( ! $this->callbacks  ) {
				return $value;
			}


			//sw()->measure('apply_filters_pre');
			$enteredEmptyStack = self::$outOfStackRecovery && ( count( self::$hookStack ) == 0 );

			// here we only need the callPath if we enter our hook stack
			$component = '';
			$callPath  = $enteredEmptyStack ? self::$profiler->getCallPath2( $component, 2 ) : null;
			//sw()->measure('apply_filters_callpath');
			/** @var float $now */
			$now           = microtime( true );
			$hookStartTime = $now;

			// file touch, hook fire capture and hook log
			$hookSeq = self::$profiler->preHookCallbacksCall( $this->tag, count( $this->callbacks ), $callPath, $now );
			//sw()->measure('apply_filters_preHookCallbacksCall');

			
						self::$hookStack[] = $this->tag;


			// standard WP stuff
			$nesting_level                      = $this->nesting_level ++;
			$this->iterations[ $nesting_level ] = array_keys( $this->callbacks );
			$num_args                           = count( $args );


			do {
				$this->current_priority[ $nesting_level ] = $priority = current( $this->iterations[ $nesting_level ] );

				foreach ( $this->callbacks[ $priority ] as $the_ ) {
					if ( ! $this->doing_action ) {
						$args[0] = $value;
					}

					/*
					 * Always suppress warnings here. PHP has a bug?, where references to $this turn into values when
					 * passed in function calls across namespaces. WordPress core uses `array( &$this )`, although
					 * this A) is not necessary and B) if we call references *aliases* should be not a valid expression,
					 * Consider this code (in an object context):
					 *
					 * $a = 1;
					 * $b =& $a;
					 * $b = 2; //sets $a = 2
					 *
					 * $c =& $this;
					 * $c = null; // sets $this = null !?! NOOO!
					 *
					 */

					// this captures current stats and initializes the profile struct
					// also adds the profile to the profileStack (for incl/self)
					// actually the return value is not needed, profile_function_end just uses it for validation
					$profile = self::$profiler->profilePreCall( $the_['function'], $this->tag, $now );
					//sw()->measure('apply_filters_profilePreCall');
					// Avoid the array_slice if possible.
					if ( $the_['accepted_args'] == 0 ) {
						$value = @call_user_func_array( $the_['function'], array() );
					} elseif ( $the_['accepted_args'] >= $num_args ) {
						$value = @call_user_func_array( $the_['function'], $args );
					} else {
						$ar    = array_slice( $args, 0, (int) $the_['accepted_args'] );
						$value = @call_user_func_array( $the_['function'], $ar );
					}

					//sw()->measure('apply_filters_CALL');

					// pops from profileStack, validates the input $profile (must be the same of course)
					// finally calls addHookCall() to capture the call
					/** @noinspection PhpUndefinedVariableInspection */
					$now = self::$profiler->profilePostCall( $profile  );

					//sw()->measure('apply_filters_profilePostCall');
				}
			} while ( false !== next( $this->iterations[ $nesting_level ] ) );

			unset( $this->iterations[ $nesting_level ] );
			unset( $this->current_priority[ $nesting_level ] );

			$this->nesting_level --;

			// pop&validate the stack
			$tagFromStack = array_pop( self::$hookStack );

			if ( self::$profiler->safeMode ) {
				if ( $tagFromStack !== $this->tag ) { // safemode
					die( "hook stack corrupted, expected {$this->tag}, but on stack was $tagFromStack !" );
				}
				if ( $enteredEmptyStack != ( count( self::$hookStack ) == 0 ) ) { // safemode
					echo "hook stack corrupted. detected in finalization of hook `this->tag` enteredEmptyStack=$enteredEmptyStack\n";
					echo "stack is:\n";
					var_dump( self::$hookStack );
					debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
					throw  new \RuntimeException( "profiler error" );
				}
				if ( ! is_float( $now ) || ! $now ) {// safemode
					die( 'now invalid!' );
				}
			}

						self::$profiler->postHookCallbacksCall( $this->tag, $now, $hookSeq, $hookStartTime );

			//sw()->measure('apply_filters_postHookCallbacksCall');

			return $value;
		}

		public function do_action( $args ) {
			$this->doing_action = true;
			$this->apply_filters( '', $args );

			// If there are recursive calls to the current action, we haven't finished it until we get to the last one.
			if ( ! $this->nesting_level ) {
				$this->doing_action = false;
			}
		}


		static $numIncFiles = 0;

		static $profileStack = array();


		public function current_priority() {
			if ( false === current( $this->iterations ) ) {
				return false;
			}

			return current( current( $this->iterations ) );
		}


		/**
		 * @param HookProfiler $profiler
		 */
		public static function setProfiler( $profiler ) {
			self::$profiler           = $profiler;
			self::$outOfStackRecovery = true;
			self::$detectRecursion    = ProfilerSettings::$default->detectRecursion;
			self::$outOfStackRecovery = ProfilerSettings::$default->showOutOfStackFrames;
		}

		public static function makeSureHookIsProfiled( $tag, $hook ) {
			if ( is_null( $hook ) || ! ( $hook instanceof WP_Hook_Profiled ) ) {
				return new WP_Hook_Profiled( $tag, $hook );
			} elseif ( $hook->tag !== $tag ) {
				die( 'hook tag mismatch!' );
			}

			return $hook;
		}

		public static function build_preinitialized_hooks( $filters ) {
			/** @var \WP_Hook[] $normalized */
			$normalized = array();

			foreach ( $filters as $tag => $callback_groups ) {
				if ( is_object( $callback_groups ) && ( $callback_groups instanceof \WP_Hook || $callback_groups instanceof WP_Hook_Profiled ) ) {
					$callback_groups    = self::makeSureHookIsProfiled( $tag, $callback_groups );
					$normalized[ $tag ] = $callback_groups;
					continue;
				}
				$hook = new WP_Hook_Profiled( $tag, null );

				// Loop through callback groups.
				foreach ( $callback_groups as $priority => $callbacks ) {

					// Loop through callbacks.
					foreach ( $callbacks as $cb ) {
						$hook->add_filter( $tag, $cb['function'], $priority, $cb['accepted_args'] );
					}
				}
				$normalized[ $tag ] = $hook;
			}

			return $normalized;
		}

		public function offsetExists( $offset ) {
			return isset( $this->callbacks[ $offset ] );
		}

		public function offsetGet( $offset ) {
			return isset( $this->callbacks[ $offset ] ) ? $this->callbacks[ $offset ] : null;
		}

		public function offsetSet( $offset, $value ) {
			if ( is_null( $offset ) ) {
				$this->callbacks[] = $value;
			} else {
				$this->callbacks[ $offset ] = $value;
			}
		}

		public function offsetUnset( $offset ) {
			unset( $this->callbacks[ $offset ] );
		}

		public function current() {
			return current( $this->callbacks );
		}

		public function next() {
			return next( $this->callbacks );
		}

		public function key() {
			return key( $this->callbacks );
		}

		public function valid() {
			return key( $this->callbacks ) !== null;
		}

		public function rewind() {
			reset( $this->callbacks );
		}

	}


	class ObjectMethodProfiler {
		/**
		 * @var object
		 */
		private $obj;

		/**
		 * @var string
		 */
		private $tag;


		/**
		 * @var HookProfiler
		 */
		private $profileCollector;

		public function __get( $name ) {
			return $this->obj->$name;
		}

		public function __set( $name, $value ) {
			return $this->obj->$name = $value;
		}

		public function __isset( $name ) {
			return isset( $this->obj->$name );
		}

		public function __unset( $name ) {
			unset( $this->obj->$name );
		}


		// pass all calls to cache object

		/**
		 * @param string $name
		 * @param array $arguments
		 *
		 * @return mixed
		 */
		public function __call( $name, $arguments ) {
			$this->profileCollector->preCallObjectMethod( $this->tag );

			$func = array( $this->obj, $name );

			$t   = microtime( true );
			$res = @call_user_func_array( $func, $arguments );

			if ( HookProfiler::isError( error_get_last() ) ) {
				$this->profileCollector->emergencyDisable( "Error at ObjectMethodProfiler", true );
			}


			$t = microtime( true ) - $t;

			$this->profileCollector->addObjectCall( $this->tag, $func, $arguments, $t );

			return $res;
		}


		public function __construct( $obj, $tag, $profileCollector ) {
			if ( is_null( $obj ) || ! is_object( $obj ) ) {
				throw new \RuntimeException( "tried to profile invalid object!" );
			}

			if ( $obj instanceof ObjectMethodProfiler ) {
				throw new \RuntimeException( "tried to profile an ObjectMethodProfiler instance!" );
			}


			$this->obj              = $obj;
			$this->tag              = $tag;
			$this->profileCollector = $profileCollector;
		}

		public function __destruct() {
		}

			}

	main_mu();
}



