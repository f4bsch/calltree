<?php


namespace WPHookProfiler\SystemTest;


class ConstantNotDefinedException extends Exception {
 function __construct( $constant ) {
	 parent::__construct( "Undefined constant $constant" );
 }
}