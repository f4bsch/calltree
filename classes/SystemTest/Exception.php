<?php

namespace WPHookProfiler\SystemTest;


class Exception extends \RuntimeException {
	function __construct( $msg ) {
		parent::__construct( $msg );
	}
}