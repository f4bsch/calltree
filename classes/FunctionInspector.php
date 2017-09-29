<?php

namespace WPHookProfiler;

class FunctionInspector {

	public static $locationMapEditLink = array();

	public static function getEditLink( $location ) {
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


			$lineHeight = 17.35;
			$scrollto   = round( $line * $lineHeight );

			$url = $adminUrl . "?file=$file&plugin=$plugin&a=te&scrollto=$scrollto";
			self::$locationMapEditLink[ $location ] = $url;

			return $url;
		}

		self::$locationMapEditLink[ $location ] = '';

		return '';

	}
}