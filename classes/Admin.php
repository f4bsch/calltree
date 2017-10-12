<?php
namespace WPHookProfiler;

class Admin {
	static function noticeMissingMUP() {
		if ( defined( 'HOOK_PROFILER_MU' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$muPath = WPMU_PLUGIN_DIR;
		?>
        <div class="updated error notice-error is-dismissible">
            <p><?php printf( __( 'WordPress did not load the Calltree Profiler must-use plugin! Please make sure %s exist and is writable, then disable and re-enable the Hook Profiler Plugin.', 'hook-prof' ), $muPath ); ?></p>
        </div>
		<?php
	}

	static function barMenu() {
		global $wp_admin_bar;
		$wp_admin_bar->add_menu( array(
			'id'    => 'hprof',
			'title' => '&#x1F333; Calltree', // x231A
			'href'  => '#hook-prof',
			'meta'  => array( 'onclick' => 'hprof.scrollIntoView(event)' )
		) );

			}

	static function footer() {
		?>
        <style>
            #message.error iframe {
                height: 80vh;
            }
        </style>
		<?php
	}

}

