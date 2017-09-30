<?php

namespace WPHookProfiler\SystemTest;


class FunctionNotFoundException extends SystemTestException {
 function __construct( $msg ) {
	 parent::__construct( $msg );
 }
}