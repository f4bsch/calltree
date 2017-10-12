<?php
/*
Plugin Name: Calltree
Plugin URI: https://calltr.ee/
Description: Advanced Hook and Function Monitor
Version: 0.1.0
Author: Fabian Schlieper
Author URI: https://fabi.me/
GitHub Plugin URI: f4bsch/calltree
*/

defined( 'ABSPATH' ) or exit;

class HookProfilerPlugin {
	/**
	 * With trailing slash!
	 * @var
	 */
	static $path;

	static function main() {
		self::$path = dirname( __FILE__ ) . '/';
		require_once self::$path . 'autoload.php';

		register_activation_hook( __FILE__, array( 'WPHookProfiler\Setup', 'onPluginActivation' ) );
		register_deactivation_hook( __FILE__, array( 'WPHookProfiler\Setup', 'onPluginDeactivation' ) );

		if ( ! defined( 'HOOK_PROFILER_MU' ) && ! WPHookProfiler\Setup::updateMUP() ) {
			add_action( 'admin_notices', array( 'WPHookProfiler\Admin', 'noticeMissingMUP' ) );
		}


		add_action( 'init', array( __CLASS__, 'init' ) );
		add_action( 'admin_footer', array( 'WPHookProfiler\Admin', 'footer' ) );

		if ( ! empty( $_GET['hprof_html_import'] ) ) {
			// TODO caching headers
			header( 'Content-Type: text/html' );
			$requestGroup = \WPHookProfiler\HookProfiler::getCurrentRequestGroup();
			\WPHookProfiler\PluginInspector::printHtmlDeps( $requestGroup );
			//add_action( 'hprof_html_deps', array( 'WPHookProfiler\PluginInspector', 'printHtmlDeps' ) );
			echo "<script>hprof._imported(document.currentScript.ownerDocument, true);</script>";
			exit;
		}

		//add_action('hook_prof_end_html_request', array( 'WPHookProfiler\ProfileOutputHTMLComment', 'dispatch' ));

		if ( defined( 'HOOK_PROFILER_MU' ) && \WPHookProfiler\ProfilerSettings::$default->testSleep ) {
			usleep( 10000 );
		}
	}

	static function init() {
		if ( defined( 'HPROF_EXPERIMENTAL' ) && is_user_logged_in() ) {
			\WPHookProfiler\Setup::updateMUP();
		}

		add_action( 'hook_prof_end', array( 'WPHookProfiler\SystemStats', 'add' ) );

		if ( self::curUserCanProfile( $demo ) ) {
			if ( $demo ) {
				add_filter( 'show_admin_bar', '__return_true' );
			}

			// we now set this with JS
			/*
			if(defined( 'HOOK_PROFILER_MU' )) {
				// set the secret cookie if missing
				$secretCookie =  \WPHookProfiler\HookProfiler::getSecret( 'cookie' );
				if ( empty( $_COOKIE[ $secretCookie ] ) ) {
					//setcookie( $secretCookie, 1 );
				}
			}
			*/

			if ( ! empty( $_GET['hprof-sleep-test'] ) || ( defined( 'HOOK_PROFILER_MU' ) && \WPHookProfiler\ProfilerSettings::$default->testSleep ) ) {
				WPHookProfiler\Test::addSleepTest();
			}

			add_action( 'hook_prof_end_html_request', array( 'WPHookProfiler\ProfileOutputHTML', 'dispatch' ) );

			add_action( 'admin_bar_menu', array( 'WPHookProfiler\Admin', 'barMenu' ), 1e9 );

			add_action( 'wp_ajax_hprofGetPostUrls', array( 'WPHookProfiler\SiteBenchmarks', 'ajaxGetPostUrls' ) );

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				WPHookProfiler\Settings::registerAjax();
				add_action( "wp_ajax_hprof_fix", array( 'WPHookProfiler\PluginFixer', 'ajax' ) );
			}
		}
	}


	static function url( $sub = '' ) {
		return plugin_dir_url( __FILE__ ) . '/' . $sub;
	}

	static function curUserCanProfile( &$demo = null ) {
		$demo = ( defined( 'HPROF_ALWAYS_ENABLE' ) && HPROF_ALWAYS_ENABLE );

		return $demo || ( defined( 'HPROF_HAVE_SECRET_COOKIE' ) && HPROF_HAVE_SECRET_COOKIE ) || current_user_can( 'activate_plugins' );
	}
}

HookProfilerPlugin::main();
