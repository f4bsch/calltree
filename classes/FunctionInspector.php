<?php

namespace WPHookProfiler;

class FunctionInspector {

	public static $locationMapEditLink = array();

	public static function getEditLink( $location ) {

		if(strncmp($location, ABSPATH, strlen(ABSPATH)) !== 0) {
			$location = ABSPATH.$location;
		}

		if ( isset( self::$locationMapEditLink[ $location ] ) ) {
			return self::$locationMapEditLink[ $location ];
		}


		list( $file, $line ) = explode( ':', $location );

		if ( HookProfiler::isPluginFile( $file ) ) {

			static $adminUrl = '';
			if ( ! $adminUrl ) {
				$adminUrl = admin_url( "plugin-editor.php" );
			}
			$pdl    = strlen( WP_PLUGIN_DIR );
			$file   = substr( $file, $pdl );
			$plugin = $file;

			$file   = rawurlencode( $file );
			//$plugin = rawurlencode( $plugin );


			$line = intval($line);
			$lineHeight = 17.35;
			$scrollto   = round( $line * $lineHeight );

			$url = $adminUrl . "?file=$file&plugin=$plugin&scrollto=$scrollto";
			self::$locationMapEditLink[ $location ] = $url;

			return $url;
		}

		self::$locationMapEditLink[ $location ] = '';

		return '';

	}
}