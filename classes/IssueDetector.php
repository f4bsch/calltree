<?php

namespace WPHookProfiler;


class IssueDetector {
	/**
	 * @param null $outWPIncTimeMs
	 *
	 * @return int
	 * @internal param float $timeForWPInitAndIncs
	 *
	 */
	public static function getServerPerformanceIndex( &$outWPIncTimeMs = null ) {
		static $tOpt = 6; //ms
		static $reg = 60; // regularisation

		// 145 no opcache

		$med = SystemStats::getWpIncTimeMedian( $nSamples );
		if ( $nSamples < 100 ) {
			return - 1;
		}

		$outWPIncTimeMs = round( $med );

		return round( ( $tOpt + $reg ) / ( $med + $reg ) * 100 );
	}

	public static function getSitePerformanceIndex( $totalPageTime ) {
		static $tOpt = 50; //ms

		return round( $tOpt / $totalPageTime * 100 );
	}

	/**
	 * @param HookProfiler $profiler
	 *
	 * @return Issue[]
	 */
	public static function detectFromRequest( $profiler ) {
		$issues       = array();
		$requestGroup = HookProfiler::getCurrentRequestGroup();

		// TODO use IssueBuilder

		foreach ( $profiler->transientMapUpdateCount as $transient => $updates ) {
			if ( $updates > 3 ) {
				$component = '[unknown]';
				$issue     = new Issue( $component, $transient, Issue::CategoryCache );
				$issue->setDescription( sprintf( __( 'Component `%s` frequently updates transient `%s`. It might cause wasted Database I/O and unnecessary slowdown', 'hook-prof' ),
					self::compLink( $component ), $transient ) );
				$issue->setHowToSolve( sprintf( __( 'Ask the developer of `%s` to fix this. An object cache plugin with lazy write-back might fix it too.', 'hook-prof' ), self::compLink( $component ) ) );
				$issue->setSlowDownPerRequest( $profiler->transientMapUpdateTime[ $transient ] );
				$issue->setDevNote( 'consider transients like a cache, you should write it only if missing' );

				// my fix: use a lazy cache that flushes writes at the end of request
				// maybe W3 cache?	// frequent transient update -> use cache

				$issues[] = $issue;
			}
		}

		foreach ( $profiler->autoloadFuncMapTime as $func => $time ) {
			if ( $time > 0.0004 ) { // 400ns
				$component = $profiler->getPluginByFunction( $func );
				$issue     = new Issue( $component, $func, Issue::CategoryMisc );
				$issue->setDescription( sprintf( __( 'Autoloader `%s` from component `%s` is slow', 'hook-prof' ),
					$func, self::compLink( $component ) ) );
				$issue->setHowToSolve( sprintf( __( 'Ask the developer of `%s` to fix this', 'hook-prof' ), self::compLink( $component ) ) );
				$issue->setSlowDownPerRequest( $time * $profiler->autoloaderCalls );

				$issue->setDevNote( 'use strncmp() to find your class\' namespace and/or prefix. never use preg_match()' );

				$issues[] = $issue;
			}
		}

		// Component `%s` blocks shutdown hook and probably delays page rendering
		//
		//Jetpack_Sync_Sender@0->do_sync	plugin/jetpack	shutdown	     1	 966.57 ms
		// maybe some ob_cache_flush?!


		// EDD Maybe Start session: php session block

		/* plugins with high load times:
		bp_loaded	plugin/buddypress	plugins_loaded	     1	  34.67 ms
		bp_include	plugin/buddypress	bp_loaded	     1	  24.66 ms
		wpdb@0->update	wpcore/wp-db	set_transient_wc_report_sales_by_date	     8	  19.65 ms
		bbp_map_meta_caps	plugin/bbpress	map_meta_cap	   494	  15.17 ms
		wpcf7	plugin/contact-form-7	plugins_loaded	     1	  10.08 ms
		*/


		/* hooks that can be cached:
			print_footer_scripts
			wp_enqueue_scripts
			admnin_bar_menu
		print_footer_scripts
		*/


		$pluginCondDisabler = ' <br> ' . sprintf( __( 'In the meantime you can use the plugin `%s` to conditionally enable `%s` on certain pages/posts/URLs only.', 'hook-prof' ), 'Plugin Organizer', '%s' );


		// iterate over all functions
		foreach ( $profiler->hookFuncMapTimeIncl as $hookHashtagFunc => $timeMs ) {
			$timeIncl = $timeMs;
			$timeSelf = $profiler->hookFuncMapFuncTimeSelf[$hookHashtagFunc];

			if ( $timeMs > 8 ) {

				// find plugins loaded issues
				list( $hook, $func ) = explode( '#', $hookHashtagFunc );
				if ( $hook == 'plugins_loaded' ) {

					$component = $profiler->getPluginByFunction( $func );
					$issue     = new Issue( $component, $func, Issue::CategoryPluginLoading );
					$issue->setDescription( sprintf( __( 'Component `%s` delays server response during load (function `%s`, action `%s`)', 'hook-prof' ),
						self::compLink( $component ), self::funcLink( $func ), $hook ) );
					$issue->setHowToSolve( sprintf( __( 'Ask the developer of `%s` to fix this', 'hook-prof' ), self::compLink( $component ) ) . sprintf( $pluginCondDisabler, self::compLink( $component ) ) );
					$issue->setSlowDownPerRequest( $timeMs );

					$issue->setDevNote( 'use autoloaders, only load code that you need.' );

					$issues[] = $issue;
				}

			}

			if ( $timeMs > 3 ) {
				list( $hook, $func ) = explode( '#', $hookHashtagFunc );
				// find script enqueue issue
				if ( $hook == 'wp_enqueue_scripts' || $hook == 'admin_enqueue_scripts' || $hook == 'print_footer_scripts' || $hook == 'wp_print_footer_scripts' ) {
					$component = $profiler->getPluginByFunction( $func );
					$issue     = new Issue( $component, $func, Issue::CategoryMisc );
					$issue->setDescription( sprintf( __( 'Component `%s` enqueues scripts  in `%s` and this takes too long', 'hook-prof' ),
						self::compLink( $component ), self::funcLink( $func ) ) );
					$issue->setHowToSolve( sprintf( __( 'Ask the developer of `%s` to fix this', 'hook-prof' ), self::compLink( $component ) ) . sprintf( $pluginCondDisabler, self::compLink( $component ) ) );
					$issue->setSlowDownPerRequest( $timeMs );
					$issue->setDevNote( 'use autoloaders, only load code that you need.' );

					// TODO here is potential to fix!

					$issues[] = $issue;
				}
			}

			// wp_admin_bar_render	wp_footer	wpcore/admin-bar	     1	     6	     0	    106 KiB	     31 KiB	  44.93 ms	   6.12 ms

			if ( $timeMs > 15 ) {
				list( $hook, $func ) = explode( '#', $hookHashtagFunc );
				// find script enqueue issue
				if ( $hook == 'admin_bar_menu' ) {
					$component = $profiler->getPluginByFunction( $func );
					$issue     = new Issue( $component, $func, Issue::CategoryMisc );
					$issue->setDescription( sprintf( __( 'Component `%s` hooks `%s` into the admin bar menu and this takes too long', 'hook-prof' ),
						$component, $func ) );
					$issue->setHowToSolve( sprintf( __( 'Ask the developer of `%s` to fix this', 'hook-prof' ), self::compLink( $component ) ) . sprintf( $pluginCondDisabler, self::compLink( $component ) ) );
					$issue->setSlowDownPerRequest( $timeMs );
					$issue->setDevNote( 'cache what you generate for the admin_bar_menu' );

					// TODO here is potential to fix! just cache it!!

					$issues[] = $issue;
				}

				if ( $hook === 'wp_footer' && $func === 'wp_admin_bar_render' ) {
					$issue = new Issue( '	wpcore/admin-bar', $func, Issue::CategoryMisc );
					$issue->setDescription( sprintf( __( 'Rendering the Toolbar (Admin bar menu) takes too long', 'hook-prof' )
					) );
					$issue->setHowToSolve( sprintf( __( 'Install %s', 'hook-prof' ), '<a href="https://calltr.ee/toolbar-cached/">Toolbar Cached</a>' ) );
					$issue->setSlowDownPerRequest( $timeMs );
					$issue->setDevNote( 'cache what you generate for the admin_bar_menu' );

					// TODO here is potential to fix! just cache it!!

					$issues[] = $issue;
				}
			}


			// find filters, shortodes that are slow
			$called = ( strncmp( $hookHashtagFunc, 'autoload#', 9 ) !== 0 ) ? $profiler->hookFuncMapCalls[ $hookHashtagFunc ] : 0;
			// if the filter was was only fired a couple of times, take the inclusive time
			$filterTime = $called < 10 ? $timeIncl : $timeSelf;
			// ( $called > 10 && $timeMs > 120 ) || ( $called > 200 && $timeSelf > 40 ) || ( $called > 300 && $timeMs > 25 )
			if ( $filterTime > 25 ) {
				list( $hook, $func ) = explode( '#', $hookHashtagFunc );
				$component = $profiler->getPluginByFunction( $func );
				// dont blame our plugin loader!
				if($hook !== 'plugins_loaded' && ($hook !== 'option_active_plugins' || $component != 'muplug/hook-profiler')) {
					$issue     = new Issue( $component, $func, Issue::CategoryPluginFilters );
					$issue->setDescription( sprintf( __( 'Component `%s` applies slow filter on hook `%s`, which was fired %d times', 'hook-prof' ),
						self::compLink( $component ), $hook, $called ) );
					$issue->setHowToSolve( sprintf( __( 'Ask the developer of `%s` to fix this', 'hook-prof' ), self::compLink( $component ) ) . sprintf( $pluginCondDisabler, self::compLink( $component ) ) );
					$issue->setSlowDownPerRequest( $filterTime );

					$issue->setDevNote( 'if you filters depends on a condition, wrap the `add_filter()` with it' );

					$issues[] = $issue;
				}
			}
		}



		// iterate over all plugins
		foreach ( $profiler->componentMapLoadTime as $component => $timeMs ) {
			if ( $timeMs > 10 ) {
				$issue = new Issue( $component, 'main_file', Issue::CategoryPluginLoading );
				$issue->setDescription( sprintf( __( 'Loading the main file of plugin `%s` takes too long', 'hook-prof' ),
					self::compLink( $component ) ) );
				$issue->setHowToSolve( sprintf( __( 'Ask the developer of `%s` to fix this', 'hook-prof' ),
						self::compLink( $component ) ) . sprintf( $pluginCondDisabler, self::compLink( $component ) ) );
				$issue->setSlowDownPerRequest( $timeMs );
				$issue->setDevNote( 'use autoloaders. be spare  with include()s in the global context of the main plugin file. code a minimal main plugin file and use hooks to load more dependencies.' );


				$issues[] = $issue;
			}
		}

		list( $frames ) = $profiler->computeCommonActionFrameTimes();


		if ( $profiler->hookedIn && $frames["03 muplugins_loaded| .. plugins_included .. |plugins_loaded"] > 100 ) {
			$issue = new Issue( "wpcore/wp-settings", "plugins", Issue::CategoryPluginLoading );
			$issue->setDescription( sprintf( __( 'Including all plugin main files takes too long', 'hook-prof' ) ) );
			$issue->setHowToSolve( sprintf( __( 'Disable plugins and try again', 'hook-prof' ) ) );
			$issue->setSlowDownPerRequest( $frames["03 muplugins_loaded| .. plugins_included .. |plugins_loaded"] );
			$issue->setDevNote( 'dont run intense code or include many files in the global scope of the main plugin file. use add_action() autoloaders' );
			$issue->setTimeIsAggregated();

			$issues[] = $issue;
		}


		if ( $profiler->hookedIn && $frames["04 |plugins_loaded .. plugins_loaded .. plugins_loaded|"] > 100 ) {
			$issue = new Issue( "wpcore/wp-settings", "plugins", Issue::CategoryPluginLoading );
			$issue->setDescription( sprintf( __( 'Loading all plugins takes too long (`plugins_loaded` hook)', 'hook-prof' ) ) );
			$issue->setHowToSolve( sprintf( __( 'Disable plugins and try again', 'hook-prof' ) ) );
			$issue->setSlowDownPerRequest( $frames["04 |plugins_loaded .. plugins_loaded .. plugins_loaded|"] );
			$issue->setDevNote( 'dont run intense code or include many files in the global scope of the main plugin file. use add_action() autoloaders' );
			$issue->setTimeIsAggregated();

			$issues[] = $issue;
		}

		// benchmark of _prime_post_caches () in wp-query
		if ( isset( $profiler->hookFirstFiredAt['found_posts'] ) && isset( $profiler->hookFirstFiredAt['pre_handle_404'] ) ) {
			$postCacheDelay = ( $profiler->hookFirstFiredAt['pre_handle_404'] - $profiler->hookFirstFiredAt['found_posts'] );
			if ( $postCacheDelay > 10 ) {
				$issue = new Issue( "wpcore/cache", "add", Issue::CategoryCache );
				$issue->setDescription( sprintf( __( 'Your post object cache writes too slow', 'hook-prof' ) ) );
				$issue->setHowToSolve( sprintf( __( 'Disable object cache or choose another one. Disks caches can be slow.', 'hook-prof' ) ) );
				$issue->setSlowDownPerRequest( 2 + $postCacheDelay * 1.2 ); // this can happen multiple times
				$issue->setDevNote( 'if possible try to lazy write your cache' );

				$issues[] = $issue;
			}
		}


		if ( ! isset( $profiler->hookFuncMapTimeIncl ) ) {
			throw new \RuntimeException( 'hookFuncMapTimeIncl not available!' );
		}

		if ( isset( $profiler->hookFuncMapTimeIncl['wp_object_cache_get'] ) ) {
			$perFire = $profiler->hookFuncMapTimeIncl['wp_object_cache_get'] / $profiler->hookMapFuncCalls['wp_object_cache_get'];
			if ( $perFire > 0.01 ) {
				$issue = new Issue( "wpcore/cache", "get", Issue::CategoryCache );
				$issue->setDescription( sprintf( __( 'Your object cache reads too slow', 'hook-prof' ) ) );
				$issue->setHowToSolve( sprintf( __( 'Disable object cache or choose another one. Disks caches can be slow.', 'hook-prof' ) ) );
				$issue->setSlowDownPerRequest( $profiler->hookFuncMapTimeIncl['wp_object_cache_get'] );
				$issues[] = $issue;
			}

		}

		if ( isset( $profiler->hookFuncMapTimeIncl['wp_object_cache_add'] ) ) {
			$perFire = $profiler->hookFuncMapTimeIncl['wp_object_cache_add'] / $profiler->hookMapFuncCalls['wp_object_cache_add'];

			if ( $perFire > 0.02 ) {
				$issue = new Issue( "wpcore/cache", "add", Issue::CategoryCache );
				$issue->setDescription( sprintf( __( 'Your object cache writes too slow', 'hook-prof' ) ) );
				$issue->setHowToSolve( sprintf( __( 'Disable object cache or choose another one. Disks caches can be slow.', 'hook-prof' ) ) );
				$issue->setSlowDownPerRequest( $profiler->hookFuncMapTimeIncl['wp_object_cache_add'] );
				$issues[] = $issue;
			}

		}
		//$profiler->hookMapTimeIncl['wp_object_cache']

		//ObjectCache_WpObjectCache@0->get		plugin/w3-total-cache	  2598	      0 KiB	      0 KiB	 140.93 ms	 140.93 ms
//ObjectCache_WpObjectCache@0->add	wp_object_cache	plugin/w3-total-cache	    19	      0 KiB	      0 KiB	  83.65 ms	  83.65 ms


		/*
		 *
		 *
		 * wp​_cron	init	wpcore​/cron	     1	     8	     8	     18 KiB	      4 KiB	1010.72 ms	1010.36 ms
		 *
		 * 11 |parse_comment_query .. comments .. loop_end|	  -0.38 ms
12 loop_end| .. footer .. |shutdown	3166.87 ms
13 |shutdown .. shutdown .. |hook_prof_end	   0.21 ms

		long suff in foote




		Rb​_Internal​_Links::shortcode	shortcode​_intlink	plugin​/rb-internal-links	    13	     0	     0	    111 KiB	     73 KiB	1054.28 ms	 457.29 ms
		// solution-> cache shortcodes!


		PluginOrganizer​@0->change​_page​_title	gettext	plugin​/plugin-organizer	  2296	     0	     0	   2414 KiB	   2414 KiB	 118.95 ms	 118.95 ms
		// solution-> cache gettext

		wp​_ob​_end​_flush​_all	shutdown	wpcore​/functions	     1	    17	    17	   1218 KiB	    906 KiB	 170.53 ms	 154.16 ms
		-> disable Minification


		#plugin​_main#wordfence​/wordfence.php	load​_plugins	plugin​/wordfence	     1	    55	    55	   7257 KiB	   7257 KiB	  58.36 ms	  58.36 ms
		-> need a more sophisticated profiler to see whats going on where!


		admin_notices#WPHookProfiler\HookProfiler@0->triggerTestError	admin_notices	muplug/hook-profiler	     1	      0 KiB	      0 KiB	 637.96 ms	 637.96 ms


		wp​_dashboard​_site​_activity	meta​_box​_dashboard​_activity	wpcore​/dashboard	     1	    635 KiB	    608 KiB	2548.73 ms	2547.88 ms
		 */

		if ( $profiler->hookedIn ) {

			if(ProfilerSettings::$default->profileErrorHandling) {
				$triggerHook = ( $requestGroup === 'admin' ) ? 'admin_notices' : 'wp_loaded';
				$triggerTime = $profiler->hookFuncMapTimeIncl[ $triggerHook . '#WPHookProfiler\HookProfiler@0->triggerTestError' ];

				if ( $profiler->hookedIn && ! $triggerTime ) {
					throw new \RuntimeException( "Trigger error time not available, can't detect issue. Should have triggered in hook $triggerHook" );
				}

				if ( $triggerTime > 10 ) { // ms
					$issue = new Issue( "server", "error_handling", Issue::CategoryMisc );
					$issue->setDescription( sprintf( __( 'Triggering an error with output takes too long', 'hook-prof' ) ) );
					$issue->setHowToSolve( sprintf( __( 'Check your server\'s config. Try to the line `set_error_handler(function() {});` to `wp-config.php`', 'hook-prof' ) ) );
					$issue->setSlowDownPerRequest( $triggerTime );
					$issue->setDevNote( 'make sure your code does not trigger any errors. use @ for suppression only if there is no way to avoid error triggering, because it can be costly' );

					$issues[] = $issue;
				}
			}

			if ( $requestGroup === 'admin' || $requestGroup === 'frontend' ) {
				$enqueueScriptsAt     = $profiler->hookFirstFiredAt[ HookProfiler::getSharedHookTag( 'enqueue_scripts' ) ];
				$printedHeadScriptsAt = $profiler->hookMapLastCallEndTime[ HookProfiler::getSharedHookTag( 'enqueue_scripts' ) ];
				$scriptHandlingTime   = $printedHeadScriptsAt - $enqueueScriptsAt;


				if ( $scriptHandlingTime > 10 ) {

					$issue = new Issue( "scripts", "plugins", Issue::CategoryMisc );
					$issue->setDescription( sprintf( __( 'Script handling takes too long', 'hook-prof' ) ) );
					$issue->setHowToSolve( sprintf( __( 'Unknown / under investigation', 'hook-prof' ) ) );
					$issue->setSlowDownPerRequest( $scriptHandlingTime );
					$issue->setDevNote( 'make sure your script enqueues do not make database queries' );

					$issues[] = $issue;
				}
			}
		}


		/*
		 * wp_update_plugins	load-plugins.php	wpcore/update	     1	2370.51 ms	1864.76 ms
_maybe_update_plugins	admin_init	wpcore/update	     1	1813.05 ms	1789.40 ms
WC_Helper_Updater::transient_update_plugins	pre_set_site_transient_update_plugins	plugin/woocommerce	     5	 563.24 ms	 560.18 m
		 */

		$updateTransient = 'pre_set_site_transient_update_plugins#';
		foreach ( $profiler->hookFuncMapTimeIncl as $hookFunc => $timeSelf ) {
			if ( $timeSelf > 120 && strncmp( $updateTransient, $hookFunc, strlen( $updateTransient ) ) === 0 ) {
				list( $hook, $func ) = explode( '#', $hookFunc );
				$component = $profiler->getPluginByFunction( $func );

				$issue = new Issue( $component, $func, Issue::CategoryPluginUpdates );
				$issue->setDescription( sprintf( __( 'The plugin `%s` adds its own update channel which is too slow', 'hook-prof' ), self::compLink( $component ) ) );
				$issue->setHowToSolve( sprintf( __( 'Unknown / under investigation', 'hook-prof' ) ) );
				$issue->setSlowDownPerRequest( $timeSelf );
				$issue->setDevNote( 'make sure your update server is fast, use good caching on your server, and cache your server results for at least 6h in the plugin code' );
				// TODO here is potential top disable this pre_site transient hook and only enable it in crons!

				$issues[] = $issue;
			}

			if ( $timeSelf > 25 && ( strncmp( 'init#', $hookFunc, 5 ) === 0 || strncmp( 'admin_init#', $hookFunc, 11 ) === 0 ) ) {
				$func      = substr( $hookFunc, 5 );
				$component = $profiler->getPluginByFunction( $func );

				$issue = new Issue( $component, $func, Issue::CategoryInit );
				$issue->setDescription( sprintf( __( 'The plugin `%s` hooks into `%s` and takes too long', 'hook-prof' ), self::compLink( $component ), self::funcLink( $hookFunc ) ) );
				$issue->setHowToSolve( sprintf( __( 'Unknown / under investigation', 'hook-prof' ) ) );
				$issue->setSlowDownPerRequest( $timeSelf );
				$issue->setDevNote( 'make sure your `init` hook is minimal and does not block (calls to `session_start`, db queries, etc.)' );

				$issues[] = $issue;
			}

			if ( $timeSelf > 10 && strncmp( 'admin_notices#', $hookFunc, 14 ) === 0 ) {
				$func      = substr( $hookFunc, 14 );
				$component = $profiler->getPluginByFunction( $func );

				$issue = new Issue( $component, $func, Issue::CategoryDashboard );
				$issue->setDescription( sprintf( __( 'The plugin `%s` shows notifications and this slows down your dashboard', 'hook-prof' ), self::compLink( $component ) ) );
				$issue->setHowToSolve( sprintf( __( 'Unknown / under investigation', 'hook-prof' ) ) );
				$issue->setSlowDownPerRequest( $timeSelf );
				$issue->setDevNote( 'cache your notifications' );

				$issues[] = $issue;
			}
		}


		$wpCoreIncTime = ( $profiler->tStart - $profiler->timestart ) * 1000;
		if ( $wpCoreIncTime > 10 ) {
			$issue = new Issue( "server", "performance", Issue::CategoryMisc );
			$issue->setDescription( sprintf( __( 'WordPress core files load too slow', 'hook-prof' ) ) );
			$issue->setHowToSolve( sprintf( __( 'Make sure that OPCache is running and there are no issues with the filesystem or storage hardware' ) ) );
			$issue->setSlowDownPerRequest( $wpCoreIncTime - 5 );
			$issue->setDevNote( 'check `opcache.enable`, `opcache.optimization_level`. `opcache.revalidate_freq` should be greater than 0. try to enable `opcache.fast_shutdown`' );

			$issues[] = $issue;
		}


		if ( $profiler->detectedWrongCacheImpl ) {
			$issue = new Issue( "object-cache", "get", Issue::CategoryCache );
			$issue->setDescription( sprintf( __( 'You current object cache is broken. It <i>might</i> slow down your site.', 'hook-prof' ) ) );
			$issue->setHowToSolve( sprintf( __( 'Disable your object cache. Ask the developer to fix the get and add function interface.', 'hook-prof' ) ) );
			$issue->setSlowDownPerRequest( 100 /*dummy*/ );
			$issue->setDevNote( 'make sure to set reference argument $found' );

			$issues[] = $issue;
		}


		foreach ( $profiler->componentMapOptionQueryCount as $component => $queries ) {
			if ( $queries > 8 || $profiler->componentMapOptionQueryTime[ $component ] > 4 ) {
				$issue = new Issue( $component, "options", Issue::CategoryDb );
				$issue->setDescription( sprintf( __( 'Component `%s` caused too many DB queries (%d) for option retrieval. This adds unnecessary load to the Database server.', 'hook-prof' ), self::compLink( $component ), $queries ) );
				$issue->setHowToSolve( sprintf( __( 'An object cache might help', 'hook-prof' ) ) );
				$issue->setSlowDownPerRequest( $profiler->componentMapOptionQueryTime[ $component ] * 2 );
				$issue->setDevNote( 'make sure to autoload options' );

				$issues[] = $issue;
			}
		}


		if(function_exists('opcache_get_status')) {
			$opcacheStatus = opcache_get_status( false );
			if ( $opcacheStatus['cache_full'] || $opcacheStatus['memory_usage']['free_memory'] < 1024 * 512 ) {
				$issue = new Issue( "server", "performance", Issue::CategoryMisc );
				$issue->setDescription( sprintf( __( 'OPCache is full or almost full. This may cause spikes in server response time.', 'hook-prof' ) ) );
				$issue->setHowToSolve( sprintf( __( 'Increase OPCache memory' ) ) );
				$issue->setSlowDownPerRequest( 100 );
				$issue->setDevNote( 'increase `opcache.memory_consumption` and `opcache.max_accelerated_files` in `php.ini`' );

				$issues[] = $issue;
			}
		}

		//


		/*
		 * TODO
		 * This happened on /wp-admin/edit.php
		 * _maybe_update_core	admin_init	wpcore/update	     1	1119.22 ms	1118.87 ms
		 *
		 */
		/**TODO
		 * wpdb@0->get_row(options)    wpdb_TBL_options    wpcore/wp-db        88          0 KiB          0 KiB      12.09 ms      12.09 ms
		 */


		/* TODO
		 * admin menu clog:
		 *  11 wp_loaded| .. admin_menu .. admin_menu	  44.55 ms
			12 admin_menu .. enqueued_scripts .. admin_enqueue_scripts|	  89.16 ms
		 */

		/* TODO
		edd_show_upgrade_notices	admin_notices	plugin/easy-digital-downloads	     1	  12.06 ms	  12.06 ms
		**/

		//EDD_Session@0->maybe_start_session	init	plugin/easy-digital-downloads	     1	 713.24 ms	 713.23 ms

		/*
		 *  um? these 2 are not calling eeach other do they? watch the self time
		 * wp_update_plugins	load-plugins.php	wpcore/update	     1	2034.61 ms	1654.68 ms
_maybe_update_plugins	admin_init	wpcore/update	     1	1681.68 ms	1652.33 ms
		 */

		/*
		 *
		 * DID you know:
		 *
		 * - Funny facts: the PHP declare(ticks=1)
		 *
		 */

		// TODO opcode issues
		// opcache.fast_shutdown default is 0 should be 1?
		// opcache.revalidate_freq should not be 0

		usort( $issues, function ( $a, $b ) {
			return $a->getSlowDownPerRequest() === $b->getSlowDownPerRequest() ? 0
				: ( ( $a->getSlowDownPerRequest() < $b->getSlowDownPerRequest() ) ? 1 : - 1 );
		} );

		return $issues;
	}


	private static function funcLink( $func ) {
		$func    = HookProfiler::rmArgs( HookProfiler::rmTag( $func ) );
		$funcLoc = ProfileOutputHTML::getFuncLocation( $func );

		return '<a href="' . FunctionInspector::getEditLink( $funcLoc ) . '">' . $func . '</a>';
	}

	private static function compLink( $component ) {
		if ( strncmp( $component, 'plugin/', 7 ) !== 0 ) {
			return $component;
		}

		return '<a href="' . PluginInspector::getInspectionLink( $component ) . '">' . $component . '</a>';
	}
}