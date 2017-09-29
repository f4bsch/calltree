<?php

namespace WPHookProfiler;


class Issue {
	const CategoryPluginLoading = 10;
	const CategoryInit = 20;
	const CategoryPluginFilters = 30;
	const CategoryCache = 40;
	const CategoryDashboard = 100;
	const CategoryPluginUpdates = 200;
	const CategoryDb = 300;
	const CategoryMisc = 999;


	private $category;
	private $component;
	private $tag;
	private $requestGroup;

	private $desc;
	private $howToSolve;
	private $slowDown;
	private $devNote;

	private $timeIsAggregated = false;

	function __construct( $component, $tag, $category, $requestGroup = '' ) {
		$this->category  = $category;
		$this->component = $component;
		$this->tag       = $tag;

		if ( empty( $requestGroup ) ) {
			$requestGroup = HookProfiler::getCurrentRequestGroup();
		}
		$this->requestGroup = $requestGroup;
	}

	function setTimeIsAggregated() {
		$this->timeIsAggregated = true;
	}

	function getTimeIsAggregated() {
		return $this->timeIsAggregated;
	}

	function setDescription( $desc ) {
		$this->desc = $desc;
	}

	function setHowToSolve( $howTo ) {
		$this->howToSolve = $howTo;
	}

	function setSlowDownPerRequest( $ms ) {
		$this->slowDown = ( $ms >= 10 ) ? round( $ms ) : round( $ms, 1 );
	}

	function setDevNote( $devNote ) {
		$this->devNote = $devNote;
	}

	function getComponent() {
		return $this->component;
	}

	function getSubject() {
		return $this->tag;
	}

	static function parseText( $str ) {
		return preg_replace( '/`([^`]+)`/', '<code>$1</code>', $str );
	}


	function getDescription() {
		return self::parseText( $this->desc );
	}

	function getHowToSolve() {
		return self::parseText( $this->howToSolve );
	}

	function getSlowDownPerRequest() {
		return $this->slowDown;
	}

	function getDevNote() {
		return self::parseText( $this->devNote );
	}

	function getCategoryName() {
		switch ( $this->category ) {
			case self::CategoryPluginLoading:
				return 'Loading';
			case self::CategoryCache:
				return 'Cache';
			case self::CategoryPluginFilters:
				return 'Filters';
			case self::CategoryMisc:
				return 'Misc';
			case self::CategoryPluginUpdates:
				return 'Updates';
			case self::CategoryDashboard:
				return 'Dashboard';
			case self::CategoryInit:
				return 'Init';
			case self::CategoryDb:
				return 'Database';
		}

		return '?' . $this->category;
	}


}