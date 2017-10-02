<?php
/**
 * Here we collect times across requests for a system and site evaltuation
 *
 * For the system evaluation, we take `getWPIncTime()`, and compute a histogram and a history of recent samples
 *
 * For the site evaluation, we group each request (admin, frontend, rest, ajax), and then compute histogram
 */

namespace WPHookProfiler;


class SystemStats {
	const CACHE_KEY = 'hprofSystemStats';
	/**
	 * @param HookProfiler $profiler
	 */
	public static function add( $profiler ) {
		if($profiler->isInBenchmarkMode())
			return;

		$cache = Cache::get();

		$systemStats = $cache->get(self::CACHE_KEY);

		if ( !$systemStats ) {
			$systemStats                = new \stdClass();
			$systemStats->wpIncTimeHist = array();
			$systemStats->wpIncTimes    = array();
			$systemStats->wpIncTimesIdx = 0;
		}


		self::addToHist( $systemStats->wpIncTimeHist,
			$systemStats->wpIncTimes,
			$systemStats->wpIncTimesIdx,
			$profiler->getWPIncTime() );

		self::addPageGrouped( $systemStats, $profiler->getTotalRunTime() );

		$cache->put('hprofSystemStats', $systemStats);
	}

	/**
	 * @param $systemStats
	 * @param $totalTimeMs
	 */
	private static function addPageGrouped( $systemStats, $totalTimeMs ) {
		if ( ! isset( $systemStats->totalTimeHistGrouped ) ) {
			$systemStats->totalTimeHistGrouped = array();
			$systemStats->totalTimesGrouped    = array();
			$systemStats->totalTimesIdxGrouped = array();
		}

		$requestGroup = HookProfiler::getCurrentRequestGroup();

		if ( ! isset( $systemStats->totalTimeHistGrouped[ $requestGroup ] ) ) {
			$systemStats->totalTimeHistGrouped[ $requestGroup ] = array();
			$systemStats->totalTimesGrouped[ $requestGroup ]    = array();
			$systemStats->totalTimesIdxGrouped[ $requestGroup ] = 0;
		}

		self::addToHist(
			$systemStats->totalTimeHistGrouped[ $requestGroup ],
			$systemStats->totalTimesGrouped[ $requestGroup ],
			$systemStats->totalTimesIdxGrouped[ $requestGroup ],
			$totalTimeMs );
	}

	private static function addToHist( &$hist, &$buffer, &$bufferIdx, $time ) {
		$time = (int) round( $time );
		$timeBin = ($time > 100) ? (int)(round($time / 10) * 10) : $time;
		isset( $hist[ $timeBin ] ) ? ( ++ $hist[ $timeBin ] ) : ( $hist[ $timeBin ] = 1 ) ;
		$buffer[ $bufferIdx ] = $time;
		$bufferIdx            = ( $bufferIdx + 1 ) % 400;
	}

	public static function get() {
		return Cache::get()->get(self::CACHE_KEY);
	}

	public static function getWpIncTimeMedian( &$outSamples = null ) {
		$stats      = self::get();
		$outSamples = count( $stats->wpIncTimes );

		return Stopwatch::median( $stats->wpIncTimes );
	}
}