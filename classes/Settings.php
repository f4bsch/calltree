<?php

namespace WPHookProfiler;

class Settings {

	const AJAX_ACTION = 'hprof_settings';

	static function registerAjax() {
		add_action( "wp_ajax_" . self::AJAX_ACTION, array( 'WPHookProfiler\Settings', 'ajax' ) );
		add_action( "wp_ajax_nopriv_" . self::AJAX_ACTION,  array( 'WPHookProfiler\Settings', 'ajax' ) );
	}

	static function model() {
		// test sleep functions
		// pofile db
		// profile cache

		// render default row count
	}

	static function ajax() {
	    if(!\HookProfilerPlugin::curUserCanProfile())
	        wp_die();

		header( 'Content-Type: application/json' );
		ProfilerSettings::loadDefault();
		if ( ! empty( $_POST['set'] ) ) {
			ProfilerSettings::$default->update( $_POST['set'] );
		}
		echo json_encode( ProfilerSettings::$default );
		wp_die();
	}

	static function printGui() {
		?>
        <script type="text/javascript">
            (function () {
                var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
                var action = '<?php echo esc_js( self::AJAX_ACTION ); ?>';
                var settings = {};

                var debounce = false;


                var saving = 0;
                var saveSettings = function () {
                    if(debounce !== false) {
                        clearTimeout(debounce);
                    }
                    debounce = setTimeout(function() {
                        var wrap = document.getElementById('hook-prof-settings-wrap');
                        if (saving === 0)
                            wrap.classList.add('wait');
                        saving++;
                        jQuery.post(ajaxurl, {'action': action, 'set': settings}, function () {
                            saving--;
                            if (saving === 0)
                                wrap.classList.remove('wait');
                        });
                        debounce = false;
                    }, 400);
                };

                hprof.loadSettingsGUI = function() {
                    jQuery.post(ajaxurl, {
                        'action': action,
                        'sets': settings
                    }, function (response) {
                        settings = response;

                        var wrap = document.getElementById('hook-prof-settings-wrap');
                        for (var key in settings) {
                            var inp = wrap.querySelectorAll("input[name='" + key + "']")[0] || false; //createInp(key, settings[key]);
                            if (inp && inp.type === 'checkbox') {
                                if (!inp.dataset.setup) {
                                    inp.dataset.setup = 1;
                                    inp.addEventListener('change', function (e) {
                                        settings[this.name] = this.checked;
                                        saveSettings();
                                    });
                                    if (inp.dataset.dependson) {
                                        var depKey = inp.dataset.dependson;
                                        inp.disabled = !settings[depKey];
                                        var dependsOn = wrap.querySelectorAll("input[name='" + depKey + "']")[0];
                                        dependsOn.addEventListener('change', (function (inp, e) {
                                            inp.disabled = !settings[this.name] || this.disabled;
                                            //inp.checked = inp.checked &&  !inp.disabled;
                                            inp.dispatchEvent(new Event('change'));
                                            //inp.checked = !inp.checked;
                                            //inp.value = !inp.value;
                                        }).bind(dependsOn, inp));
                                    }
                                }
                                inp.checked =settings[key];
                                //settings[key] ? inp.setAttribute('checked', 'checked') : inp.removeAttribute('checked');
                                //inp.dispatchEvent(new Event('change'));
                            }
                        }
                    });
                };

                hprof.loadSettingsGUI();
            })(hprof);

        </script>

        <style>
            #hook-prof-settings-wrap .saving {
                visibility: hidden;
            }

            #hook-prof-settings-wrap.wait .saving {
                visibility: visible;
            }

            #hook-prof-settings-wrap.wait button {
                visibility: hidden;
            }

            #hook-prof-settings-wrap.wait, #hook-prof-settings-wrap.wait * {
                /*cursor: wait;
                opacity: 0.8;
                transition: opacity 0.1s ease-in;*/
            }

            #hook-prof-settings-wrap p label {
                font-weight: normal;
                line-height: 1em;
                font-size: 13px;
            }

            #hook-prof-settings-wrap {
                max-width: 80em;
                width: calc(96vw - 4em);
            }

            #hook-prof-settings-wrap > div.flex {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-around;
            }

            #hook-prof-settings-wrap > div.flex > div {
                width: 20em;
                margin-bottom: 1em;
            }

            #hook-prof-settings-wrap > div:last-child {
                clear: both;
                width: 100%;
                margin: auto;
                text-align: center;
            }
        </style>


        <div id="hook-prof-settings-wrap">
            <h4>Calltree Settings</h4>
            <div class="flex">
                <div>
                    <p>Coverage</p>
                    <p class="hprof-experimental"><label><input type="checkbox" name="profileInjected" value="1"> Injected Profiler</label></p>

                    <p><label><input type="checkbox" name="profileHooks" value="1"> Profile hooks</label></p>

                    <p><label><input type="checkbox" name="profilePluginMainFileInclude" value="1"> Profile plugin main
                            files</label>

                    <p class="hprof-experimental"><label><input type="checkbox" name="profileShortcodes" value="1">
                            Profile shortcodes</label>

                    <p class="hprof-experimental"><label><input type="checkbox" name="profileDb" value="1"> Profile
                            calls on database
                            object</label>
                    </p>

                    <p class="hprof-experimental"><label><input type="checkbox" name="profileObjectCache" value="1">
                            Profile object cache</label>
                    </p>

                    <p class="hprof-experimental"><label><input type="checkbox" name="profileAutoloaders" value="1">
                            Profile autoloader
                            functions</label>
                    </p>
                </div>

                <div class="hprof-experimental">
                    <p>Features</p>
                    <p><label><input type="checkbox" name="profileMemory" value="1" data-dependsOn="profileHooks">
                            Profile memory
                        </label></p>
                    <p><label><input type="checkbox" name="profileIncludes" value="1" data-dependsOn="profileHooks">
                            Profile includes
                        </label></p>
                    <p><label><input type="checkbox" name="detectRecursion" value="1" data-dependsOn="profileHooks">
                            Detect recursion
                        </label></p>
                    <p><label><input type="checkbox" name="findDeadIncFiles" value="1" data-dependsOn="profileHooks">
                            Find dead inc files</label></p>
                </div>

                <div>
                    <p>Report</p>

                    <p><label><input type="checkbox" name="advancedReport" value="1" data-dependsOn="profileHooks">
                            Show advanced report
                        </label>
                    </p>

                    <p><label><input type="checkbox" name="hookLog" value="1" data-dependsOn="advancedReport"> Log all
                            hook
                            fires</label>
                    </p>
                    <p><label><input type="checkbox" name="hookLogExcludeCommonFilters" value="1"
                                     data-dependsOn="hookLog">
                            Exclude common filters from log (gettext, esc_html, map_meta_cap ...)</label></p>
                </div>


                <div>
                    <p>Misc</p>
                    <p><label><input type="checkbox" name="adminBarDisplayTimes" value="1">
                            Display TTFB, TSRV, TTLB in Admin Bar
                        </label>
                    </p>


                </div>


                <div>
                    <p>Debug</p>
                    <p class="hprof-experimental"><label><input type="checkbox" name="showOutOfStackFrames" value="1"
                                                                data-dependsOn="advancedReport"> Show
                            out-of-stack frames</label>
                    </p>
                    <p><label><input type="checkbox" name="profileErrorHandling" value="1" data-dependsOn="profileHooks"> Trigger test error</label>
                    </p>
                    <p><label><input type="checkbox" name="testSleep" value="1" data-dependsOn="profileHooks"> Add test
                            sleeps</label>
                    </p>


                    <p><label><input type="checkbox" name="opcacheDisable" value="1">
                            Disable PHP OPCache
                        </label>
                    </p>

                    <p><label><input type="checkbox" name="objectCacheFlush" value="1">
                            Disable Object Cache persistence (if any)
                        </label>
                    </p>
                </div>
            </div>

            <div>
                <p class="saving">saving...</p>
                <button onclick="window.location.reload()">Reload Page to generate report with new settings</button>
            </div>
        </div>

        <!--
				<div id="hook-prof-settings-tpl">
					<p><label><input type="checkbox" name="profileDb" value="1">Profile calls on database object</label></p>
				</div>
				-->
		<?php
	}
}