<?php

namespace WPHookProfiler\Reports;


use WPHookProfiler\FileStatsCache;
use WPHookProfiler\HookProfiler;
use WPHookProfiler\ProfileOutputHTML;
use WPHookProfiler\ProfilerSettings;

class RequestCommon {
	/**
	 * @param HookProfiler $profiler
	 */
	static function render($profiler) {
		$desc = <<<DESC

General page request info.<br>
`plugins hash` is a unique identifier for the currently active plugins. This helps to identify groups of plugins.<br>
`included files` is the total number of PHP script includes and `inc. files size` is their total size<br>
`opcache_level` states the current optimization level. The default is `0x7FFFBFFF`<br>
`autoloader calls` is the number of dynamically loaded classes<br>
`hook fires` sums up the total number of `apply_filter()` and `do_action()` calls<br>
`func calls` is the total number of captured function calls<br>
`T_total` is approximately the total time php needed to process the request and generate the response<br>
`T_hooks` is the total runtime of all captured hooks. This can be greater then real runtime if there are many nested hooks.<br>
`TTFB` is the Time to first byte reported by the browser's Performance interface. Its the network delay + web server + PHP + WordPress init time<br>"
`T_download` is TTLB - TTFB (Time to last byte)<br>
`T_SRV` is `T_total` + the time to generate this report + maybe some buffer flushing time<br>
DESC;


		$hookTime         = $profiler->getTotalInHookTime(); //sw()->measure('getTotalInHookTime');
		$requestTime      = $profiler->getTotalRunTime(); //sw()->measure('getTotalRunTime');
		$recoveredTime    = $profiler->getTotalRecoverdOutOfStackTime();
		$unclassifiedTime = $profiler->getTotalUnclassifiedOutOfStackTime(); //sw()->measure('getTotalUnclassifiedOutOfStackTime');

		$activePlugins = get_option( 'active_plugins' );
		sort( $activePlugins );
		$includedFiles     = get_included_files();
		$activePluginsHash = HookProfiler::getActivePluginsHash();


		$requestRows = [
			'request group'  => $profiler->getCurrentRequestGroup(),
			'active plugins' => [ count( $activePlugins ), "%7d" ],
			'plugins hash'   => [ $activePluginsHash, "%xd" ],


			'included files'   => [ count( get_included_files() ), "%7d" ],
			'inc. files size'  => [
				array_sum( FileStatsCache::getSizes( $includedFiles ) ) / 1014 / 1024,
				'%7.1f MiB'
			],
			'PHP version'    => strtok(PHP_VERSION, '+'),
			'opcache_level'    => @ini_get( 'opcache.enable' ) ? @ini_get( 'opcache.optimization_level' ) : 'OFF',
			'autoloader calls' => [ $profiler->autoloaderCalls, "%7d" ],
			'hook fires'       => [ $profiler->getNumCapturedFires(), "%7d" ],
			'func calls'       => [ $profiler->getNumCapturedCalls(), "%7d" ],
			'MEM'              => [ memory_get_usage(false) / 1014 / 1024, '%7.1f MiB' ],
			'MEM_peak'         => [ memory_get_peak_usage() / 1014 / 1024, '%7.1f MiB' ],

		];

				if ( ProfilerSettings::$default->profileObjectCache ) {
			$misses                        = array_sum( $profiler->cacheKeyMapMiss );
			$hits                          = array_sum( $profiler->cacheKeyMapHit );
			$requestRows['cache hits']     = [ $hits, "%7d" ];
			$requestRows['cache misses']   = [ $misses, "%7d" ];
			$requestRows['cache hit rate'] = ( $hits == 0 ) ? '-' : [
				$hits / ( $hits + $misses ) * 100,
				"%7.1f %%"
			];
		}

		$requestRows['T_wpIncludes'] =  [ $profiler->getWPIncTime(), "%7.2f ms" ];

		$requestRows += [
			'T_total'        => [ $requestTime, "%7.2f ms" ],
			'T_hooks'      => [ $hookTime, "%7.2f ms" ],
			'T_recovered'    => [ $recoveredTime, "%7.2f ms" ],
			'T_unclassified' => [ $unclassifiedTime, "%7.2f ms" ],
			//'T_request - T_hooks'             => [ $requestTime - $hookTime, "%6.2f ms" ],
			//'T_hooks / T_request'             => [ $hookTime / $requestTime * 100, "%6.1f %%" ],
			'coverage'       => [ ( $hookTime + $recoveredTime ) / $requestTime * 100, "%7.1f %%" ]
			//''                           => [ , "%7.2f ms" ],
		];

		ProfileOutputHTML::createTable( "request", $requestRows, [], $desc );
	}
}