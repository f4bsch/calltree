<?php


namespace WPHookProfiler;


class CommonScripts {

	static function js() {
		$serverInfo = [
			'wpV'         => $GLOBALS['wp_version'],
			'phpV'        => phpversion(),
			'opcacheLv'   => function_exists( 'opcache_get_status' ) && opcache_get_status()['opcache_enabled'] && @ini_get( 'opcache.enable' ) ? @ini_get( 'opcache.optimization_level' ) : 'OFF',
			'actPlugHash' => sprintf( "%xd", HookProfiler::getActivePluginsHash() ),
			'actPlugTag'  => ProfileOutputHTML::getFriendlyPluginsHash(),
			'objCache'    => + wp_using_ext_object_cache(),
			'dbCache'     => + file_exists( WP_CONTENT_DIR . '/db.php' ),
			'srv'         => $_SERVER['SERVER_SOFTWARE'],
			't'           => date( 'c' ),
			'url' => site_url()
		];

		?>
        <script>
            (function (dom, hprof) {
                hprof.settings = <?php echo json_encode(ProfilerSettings::$default); ?>;
                hprof.pluginUrl = '<?php echo trim(esc_js( \HookProfilerPlugin::url()),'/' ); ?>';
                hprof.time =  new Date();
                hprof.serverInfo =  <?php echo json_encode( $serverInfo ); ?>;
                hprof.ajaxurl =  '<?php echo esc_js( admin_url( 'admin-ajax.php', 'relative' ) ); ?>';
                hprof.slug = 'calltree';
            })(document, window.hprof || (window.hprof = {}));
        </script>

        <script>
            if ('undefined' === typeof jQuery)
                hprof.loadScript('https://code.jquery.com/jquery-3.2.1.min.js');
        </script>

        <script src="<?php echo esc_attr( \HookProfilerPlugin::url( 'js/common.js' ) ); ?>"></script>
		<?php
	}
}