<?php

namespace WPHookProfiler\SystemTest;


class FunctionNotFoundException extends Exception {
 function __construct( $msg ) {
	 parent::__construct( $msg );
 }
}