<?php

namespace WPHookProfiler;

class Setup {
	static function onPluginActivation() {
		self::updateMUP();
	}

	static function onPluginDeactivation() {
		@unlink( WPMU_PLUGIN_DIR . '/hook-profiler.php' );
	}

	static function logMsg($msg) {
		Log::msg($msg);
	}

	static function updateMUP() {
		$src = realpath(dirname( __FILE__ ) . '/../mu-plugins/hook-profiler.php');
		$dst = (WPMU_PLUGIN_DIR . '/hook-profiler.php');
		if ( ! file_exists( $dst ) || filemtime( $src ) > filemtime( $dst ) || filesize($dst) === 0 ) {
			self::logMsg( "hprof: updating mu-plugin '$src' -> '$dst' ..." );

			is_dir( WPMU_PLUGIN_DIR ) || mkdir( WPMU_PLUGIN_DIR ) || self::logMsg( 'Creating ' . WPMU_PLUGIN_DIR . ' failed!' );

			@unlink($dst);

			if ( @symlink ( $src , $dst ) || @copy( $src, $dst ) ) {
				self::logMsg( "install $src -> $dst ok!" );
				$ok = @touch( $dst, filemtime( $src ));
				$ok ? self::logMsg( "touch($dst) ok!" ) : self::logMsg( "Error: touch($dst) FAILED!" );

				return $ok;
			} else {
				self::logMsg( "Error: copy($src, $dst) FAILED!" );
			}

			return false;
		}

		return true;
	}
}