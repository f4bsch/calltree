<?php
namespace WPHookProfiler;

class Setup {
	static function onPluginActivation()
	{
		self::updateMUP();
	}

	static function onPluginDeactivation() {
		@unlink(WPMU_PLUGIN_DIR.'/hook-profiler.php');
	}

	static function updateMUP() {
		$src = dirname(__FILE__).'/../mu-plugins/hook-profiler.php';
		$dst = WPMU_PLUGIN_DIR.'/hook-profiler.php';
		if(!is_file($dst) || filemtime($src) > filemtime($dst)) {
			is_dir(WPMU_PLUGIN_DIR) || mkdir(WPMU_PLUGIN_DIR);
			return copy( $src, $dst ) && @touch( $dst, filemtime( $src ) );
		}
		return true;
	}
}