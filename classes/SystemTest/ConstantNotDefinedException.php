<?php


namespace WPHookProfiler\SystemTest;


class ConstantNotDefinedException extends SystemTestException {
 function __construct( $constant ) {
	 parent::__construct( "Undefined constant $constant" );
 }
}