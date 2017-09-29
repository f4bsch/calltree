<?php

namespace WPHookProfiler;


class PluginInspector {

	static $compMapInspectionUrl = array();

	static function getPluginMainFileByComponent( $component ) {
		if ( strncmp( $component, 'plugin/', 7 ) !== 0 ) {
			return false;
		}

		$component = substr( $component, 7 );

		foreach ( HookProfiler::getActivePlugins() as $plugin ) {
			if ( dirname( $plugin ) === $component ) {
				return $plugin;
			}
		}

		return false;
	}

	static function getInspectionLink( $component ) {
		if ( isset( self::$compMapInspectionUrl[ $component ] ) ) {
			return self::$compMapInspectionUrl[ $component ];
		}


		$mainFile = self::getPluginMainFileByComponent( $component );

		if ( ! $mainFile ) {
			self::$compMapInspectionUrl[ $component ] = '';

			return '';
		}

		static $hashAll = false;
		static $active = null;
		if ( $hashAll === false ) {
			$hashAll = HookProfiler::getActivePluginsHash();
			$active  = HookProfiler::getActivePlugins();
		}

		$hashWithout = HookProfiler::getPluginsHash( array_diff( $active, [ $mainFile ] ) );
		$hashOnly    = HookProfiler::getPluginsHash( [ $mainFile ] );

		if ( $hashAll == $hashWithout || $hashWithout === $hashOnly || $hashAll == $hashOnly ) {
			self::$compMapInspectionUrl[ $component ] = '';

			return '';
		}

		$url = '#inspect-plugin=' . $component . "&hash-with=$hashAll&hash-without=$hashWithout&hash-only=$hashOnly";

		self::$compMapInspectionUrl[ $component ] = $url;

		return $url;
	}

	static function printHtmlDeps( $requestGroup ) {
		?>

        <style>
            #hprof-plugin-inspector-modal-tpl button {
                display: block;
                margin: 0 auto 0.5em auto;
                width: 16em;
            }
        </style>


        <div id="hprof-plugin-inspector-modal-tpl" style="display: none;">
            <h3></h3>

        <div class="benchmark-menu">
            <button data-hprof-plugin-inspector-action="excluding-reload">
                Reload page without this plugin
            </button>

            <button data-hprof-plugin-inspector-action="exclusive-reload">
                Reload page with only this plugin
            </button>

            <!---->
        </div>




            <div id="hprof-bench-out" style="float: right;">---</div>

            <svg id="prof-chart" style="width: calc(95vw - 4em); height: calc(92vmin - 12em);">
            </svg>

        </div>


        <script>
            hprofPluginInspector = new (function () {
                hprof.loadD3(4);
                hprof.loadScript('js/plot.js');
                hprof.loadScript('js/benchmark.js');

                this.commonActions = <?php echo json_encode( array_keys( array_filter( HookProfiler::getNamedRequestFramesTriggers( $requestGroup ) ) ) ); ?>;
                const hashNone = '<?php echo HookProfiler::getPluginsHash( [] ) ?>';


                let thiz = this;

                let curPlugin = '';
                let modalTpl = null;
                let curHashes = {};

                /**
                 *
                 * @type ResponseTimeBenchmark
                 */
                let curBench = null;

                let setupModal = function (slug) {
                    modalTpl.getElementsByTagName("h3")[0].innerText = slug;
                };

                let queryToObj = function queryToObj(str) {
                    return JSON.parse('{"' + decodeURI(str).replace(/"/g, '\\"').replace(/&/g, '","').replace(/=/g, '":"') + '"}')
                };

                let initModalTpl = function (modalTpl) {
                    const p = document.createElement("p");
                    p.innerText = JSON.stringify(hprof.serverInfo);
                    p.style.fontSize = '9px';
                    modalTpl.append(p);
                };

                let inspectPlugin = function (slugAndHashes) {
                    curHashes = queryToObj("slug=" + slugAndHashes);
                    const slug = curHashes.slug;

                    if (!modalTpl) {
                        modalTpl = hprof.imported.getElementById('hprof-plugin-inspector-modal-tpl');
                        initModalTpl(modalTpl);
                    }

                    curPlugin = slug;

                    let modal = picoModal({
                        content: "",
                        overlayStyles: {
                            backgroundColor: "#222",
                            opacity: 0.3
                        }
                    }).afterCreate(function (modal) {
                        let tpl = modalTpl;
                        setupModal(slug);
                        modal.modalElem().append(tpl);
                        tpl.style.display = '';
                    }).show();
                };

                /**
                 * Get the current page url with any `hprof-*` query vars removed and appends a ? or &
                 */
                function getUrl() {
                    let url = window.location.href;
                    if (window.location.hash) url = url.replace(window.location.hash, "");
                    let qp = url.indexOf('?');
                    if (qp !== -1) {
                        const q = url.substr(qp + 1).split('&').filter((qv) => qv.length > 0 && !qv.startsWith('hprof-')).join('&');
                        url =  url.substr(0, qp) + '?' + ((q.length > 0) ? (q + '&') : '');
                    } else {
                        url += '?';
                    }
                    return url;
                }

                function computeDeltaTimes(yv) {
                    let b = yv.length / 2;
                    let dy = new Array(yv.length);
                    dy[0] = 0;
                    dy[b] = yv[b] - yv[0];

                    let t = dy[b];
                    for (i = 1; i < b; i++) {
                        let pluginExtraTime = (yv[b + i] - yv[b + i - 1]) - ( yv[i] - yv[i - 1]);
                        dy[i] = t;
                        dy[i + b] = t + pluginExtraTime;
                        t += pluginExtraTime;
                    }
                    return dy;
                }

                function rmFromArray(arr, v) {
                    if (arr.constructor !== Array)
                        throw "rmFromArray from no array!";
                    let p = arr.indexOf(v);
                    if (p === -1)
                        return false;
                    arr.splice(p, 1);
                    return true;
                }

                function shouldDropBenchmarkProgress(p) {
                    // drop early spikes (due o CPU, files), the plugin doest not influence this
                    return (p["muplugins"].cur > (p["muplugins"].med * 3));
                }

                let actions = {
                    "excluding-reload": function () {
                        window.location.href = getUrl() + "hprof-disable-plugins[]=" + curPlugin;
                    },
                    "exclusive-reload": function () {
                        window.location.href = getUrl() + "hprof-disable-all-plugins-but[]="+hprof.slug+"&hprof-disable-all-plugins-but[]=" + curPlugin;
                    },

                                        // compare all plugins to all except this
                    "bench-excluding" : function() {
                        if (curBench && curBench.isRunning) {
                            curBench.cancel();
                            return;
                        }

                        let url = getUrl() + 'hprof-disable-hooks=1&hprof-bench=1';
                        let urlExcluding = url + "&hprof-disable-plugins[]=" + curPlugin;

                        startBench([urlExcluding, url],  ['WITHOUT ' + curPlugin, 'all plugins'], [curHashes['hash-without'], curHashes['hash-with']]);
                    },

                    // compare this plugin to none
                    "bench-exclusive" : function() {
                        if (curBench && curBench.isRunning) {
                            curBench.cancel();
                            return;
                        }

                        let url = getUrl() + 'hprof-disable-hooks=1&hprof-bench=1&';
                        let urlAllDisable = url + "hprof-disable-all-plugins-but=_none";
                        let urlExclusive = url + "hprof-disable-all-plugins-but=" + curPlugin;

                        startBench([urlAllDisable, urlExclusive], ['NO plugins', 'only ' + curPlugin], [hashNone, curHashes['hash-only']]);
                    }
                };

                const startBench = function (urls, labels, hashes) {
                    if (!hashes[0] || !hashes[1] || hashes[0] === hashes[1]) {
                        alert('invalid hashes');
                        return;
                    }

                    curBench = new ResponseTimeBenchmark(urls);
                    let bench = curBench;

                    bench.setContentNeedle('<!-- HHPROF_COMMON_ACTIONS=');
                    bench.addCapture('server', /\sHPROF_SERVER_TTLB\s*=\s+([0-9.]+);/);
                    bench.addFixture('pluginsHash', [
                        new RegExp('\\sHPROF_ACTIVE_PLUGINS_HASH\\s*=\\s*' + hashes[0] + '\\s*;'),
                        new RegExp('\\sHPROF_ACTIVE_PLUGINS_HASH\\s*=\\s*' + hashes[1] + '\\s*;')
                    ]);


                    let plot = new HprofPlot(modalTpl.getElementsByTagName("svg")[0], labels, true);

                    const resPerUrl = new Array(2);
                    bench.setProgressCallback(function (p) {
                        resPerUrl[p.urlIndex] = p;
                        if (p.urlIndex === 1) {
                            let y = [resPerUrl[0].server.med, resPerUrl[1].server.med];
                            let ym = [resPerUrl[0].server.med, resPerUrl[1].server.med];
                            plot.appendVal(y, ym);
                        }
                    });

                    // up to 500 requests, 30 minutes
                    bench.run(500, 1000 * 60 * 30).then(function (finalResult) {
                        console.log("Yay! ", finalResult);
                    });
                };


                                document.body.addEventListener('click', function (e) {
                    if (e.target.href && e.target.attributes["href"].value.startsWith('#inspect-plugin=')) {
                        e.preventDefault();
                        inspectPlugin(e.target.attributes["href"].value.substr('#inspect-plugin='.length + 'plugin/'.length));
                    } else if (e.target.dataset.hprofPluginInspectorAction) {
                        e.preventDefault();
                        let act = e.target.dataset.hprofPluginInspectorAction;

                        actions[act](curPlugin);
                    }
                });

                return this;
            })
            ();
        </script>
		<?php
	}
}