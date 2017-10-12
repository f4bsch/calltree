<?php
namespace WPHookProfiler;

define('WPHookProfiler_path', dirname(__FILE__).'/classes/');
define('Calltree_path', dirname(__FILE__).'/');

function autoload( $class ) {
	$len = 15; //strlen("WPHookProfiler\\");
	if ( strncmp( "WPHookProfiler\\", $class, $len ) === 0 ) {
		if ( $class === "WPHookProfiler\\ProfilerSettings" ) {
			echo "Please re-install the MU plugin!";
			debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
			exit;
		}
		require_once WPHookProfiler_path . str_replace('\\','/',substr( $class, $len )) . ".php";
	} elseif ( strncmp( "Calltree\\", $class, 9 ) === 0 ) {
		//$fn =Calltree_path . str_replace('\\','/',$class) . ".php";
		//var_dump($fn, is_file($fn));
		//echo $class;
		require Calltree_path . str_replace('\\','/',$class) . ".php";
		//var_dump(class_exists($class));
	}
}

spl_autoload_register( '\WPHookProfiler\autoload' );