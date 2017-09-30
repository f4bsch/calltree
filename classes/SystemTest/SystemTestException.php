<?php

namespace WPHookProfiler\SystemTest;


class SystemTestException extends \RuntimeException {
	function __construct( $msg ) {
		parent::__construct( $msg );
	}
}