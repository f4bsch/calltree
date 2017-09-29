<?php

namespace WPHookProfiler;

class ProfileOutputHTML {

	private static $funcLocations;

	/**
	 * @return Stopwatch
	 */
	private static function sw() {
		static $sw = null;
		if ( ! $sw ) {
			$sw = new Stopwatch();
		}

		return $sw;
	}


	/**
	 * @param HookProfiler $profiler
	 */
	static function dispatch( $profiler ) {

		self::$profiler = $profiler;

		if ( ! \HookProfilerPlugin::curUserCanProfile() ) {
			return;
		}

		$tStartGenerate = microtime( true );
		self::sw();

		self::css();
		CommonScripts::js();
		?>
        <link rel="stylesheet" type="text/css" href="//cdn.datatables.net/1.10.16/css/jquery.dataTables.min.css">
        <script type="text/javascript" src="//cdn.datatables.net/1.10.16/js/jquery.dataTables.min.js"></script>
		<?php

		$desc = 'This report reveals plugins and functions which have performance issues. It scopes the current page load and does <i>not</i> cover AJAX or REST.<br>' .
		        'Units are <b>Milliseconds</b> (ms), Microseconds (µs) and Nanoseconds (ns). Quantities do not have units.<br>' .
		        'We use the word <b>hook</b> for a set of functions plugins can register with `add_action($name, ...)` or `add_filter($name, ...)`, grouped with same `$name` (aka tag).<br>' .
		        'The <b>Time Profile</b> outlines  which components delayed this page load.<br>' .
		        'Underneath you&apos;ll find a <b>list of issues</b>, detected by the profiler. The detector uses known hooks and thresholds, that we put. If a plugin shows up we think that there is potential to optimize.<br>' .
		        'In case something shows up that shouldn&apos;t, please let us know.<br>' .
		        'The Profiler currently has an <b>overhead</b> of 10-40%, depending on coverage. You can adjust it by tapping the &#x2699;. Happy profiling!';

		?>


        <div id="hprof-loading-spacer" style="display: none;">
            <div class="hprof-loader"></div>
        </div>
        <script> (function () {
                let outHeight = localStorage.getItem('hprofContainerHeight');
                if (outHeight > 10) {
                    let ls = document.getElementById('hprof-loading-spacer');
                    ls.style.height = outHeight + 'px';
                    ls.style.display = '';
                }
            })();
        </script>

        <div id="hook-prof-html" style="display: none">

            <a name="hook-prof" id="hook-prof-anchor"></a>
            <h3 class='wp-exclude-emoji'>Calltree 
				<?php echo "<div class='hprof-table-action hprof-show-dialog' data-msg='$desc'>&#x2139;</div> <div class='hprof-table-action hprof-show-dialog' data-settings='1'>&#x2699;</div>"; ?>
            </h3>

            <div class="scope">profile scope: request
				<?php if ( count( $profiler->pluginMapDisabled ) > 0 ) {
					echo "<br><b>disabled plugins</b> for this request: " . implode( ', ', array_keys( $profiler->pluginMapDisabled ) );
				}
				?>
            </div>

            <h4 class='hprof-section'>Report</h4>
            <div class="hprof-flex">
				<?php

				$haveProfile = ( count( $profiler->hookFuncMapCalls ) > 0 );

				if ( ! $haveProfile ) {
					echo "<div style='width: 100%;      text-align: center;    line-height: 10em; color:black;    background: bisque;'>";
					$secretCookie = HookProfiler::getSecret( 'cookie' );
					echo empty( $_COOKIE[ $secretCookie ] ) ? self::cookieText() : "nothing captured, check profiler settings";
					echo "</div>";
				}

				Reports\RequestCommon::render( $profiler );

				$groupedTimes        = $profiler->computeCaptureGroups();
				self::$funcLocations = $groupedTimes['funcLocations'];

				if ( $haveProfile && ProfilerSettings::$default->advancedReport ) {

										$desc = "Accumulated run time of captured hooks.";

					// hookMapFuncTime covers only hooks with callbacks, we want to list them all!
					$allHooks = $profiler->hookMapTimeIncl;
					foreach ( $profiler->hookMapFires as $k => $c ) {
						if ( ! isset( $allHooks[ $k ] ) ) {
							$allHooks[ $k ] = 0;
						}
					}

					self::displayStatsTable( [
						'time_incl'      => $allHooks,
						'fires'          => $profiler->hookMapFires,
						'calls'          => $profiler->hookMapFuncCalls,
						'first fired at' => $profiler->hookFirstFiredAt,
						//'component' => $profiler->funcMapComponent,

					], [ 'sort' => true ], "hooks", $desc );


										if ( ! empty( $profiler->hookLog ) ) {
						self::displayStatsTable( [
							'time'      => $profiler->hookLog,
							'time_post' => $profiler->hookLogPost,
							'time_incl' => $profiler->hookLogTimeIncl
						], [], "hookLog", "" );
					}


					if ( ProfilerSettings::$default->showOutOfStackFrames ) {
						$desc = "Lists intervals between adjacent hook captures. Here you can find further performance bottlenecks, that might not show in the functions table. Watch out for hook pairs relevant to the same plugin";
						self::displayTimingStats( $profiler, $profiler->hookGapMapTime, "out-of-stack frames", false, false, 100, $desc );

						self::displayTimingStats( $profiler, $profiler->componentMapGapTime, "recovered out-of-stack frames per component", false, false, 60 );

						self::displayTimingStats( $profiler, $profiler->hookGapMapUnclassifiedTime, "unclassified out-of-stack frames", false, false, 60 );
					}

					if ( ProfilerSettings::$default->detectRecursion ) {
						//self::displayTimingStats( $profiler, $profiler->hookMapRecursiveCount, "hook recursions old", false, false );
						self::displayStatsTable( [
							'count' => $profiler->hookFuncMapRecursiveCount,
							//'hook'      => $profiler->hookFuncMapHook,
							//'component' => $profiler->funcMapComponent,
						],
							[
								'sort' => true,
								//'resolveComponent' => true,
								//'keyFilters' => [ 'count' => 'component' => [ 'WPHookProfiler\HookProfiler', 'rmTag' ] ],
							], "hook recursions", $desc );
					}

															if ( ProfilerSettings::$default->profileAutoloaders ) {
						$autoloadTimings = $profiler->profileAutoloadFunctions();
						//self::displayTimingStats( $profiler, $autoloadTimings, "autoloaders", true, false, 60, $desc );
						$desc = "Runtimes of functions registered with spl_autoload_register - usually they are negligible.<br>"
						        . "This table <i>might</i> give you an idea about how <i>clumsy</i> developers coded plugins. Please ignore values <3ns.";
						self::displayStatsTable( [
							'runtime'   => $autoloadTimings,
							'component' => $profiler->funcMapComponent
						], [ 'sort' => true ], "autoloaders", $desc );
					}


					if ( ProfilerSettings::$default->profileIncludes ) {
						Reports\Includes::render( $groupedTimes );
					}
				} // have profile

				?>
            </div><!-- hprof-flex -->


			<?php


			if ( $haveProfile ) {
				echo "<div id='hprof-issues' style='margin: auto;     max-width: 60em;'>";
				try {
					$issues = IssueDetector::detectFromRequest( $profiler );
					IssueDisplay::listIssues( $issues, $profiler->getWPIncTime(), $profiler->getTotalRunTime() );
				} catch ( \Exception $e ) {
					echo "<p>Issue detector exception:" . $e->getMessage() . "</p>";
				}
				echo "</div>";

				echo "<div id='hprof-treemap-wrap'  style='margin: auto; width: 100%; max-width: 80em;'>";
				echo "<h4 class='hprof-section'>Time Map</h4>";
				Treemap::render( $profiler, $groupedTimes['plugins'] );
				echo "<p style='text-align: right; margin-top: 0; padding-top: 0;'><i>relative exclusive runtimes per component. tap to zoom in.</i></p>";
				echo "</div>";

				$tReport = microtime( true ) - $tStartGenerate;
				printf( '<p style="float: right;margin: 1em 2em; font-style: italic">Calltree generated this report in %5.1f ms</p>', $tReport * 1000 );
			}
			?>
            <div id="hook-prof-settings-gui" style="display: none;">
				<?php Settings::printGui(); ?>
            </div>

			<?php


			echo "<pre style='width: 100%; overflow: auto; background: #444; color: white;'>";
			//print_r( $profiler->phpErrors );
			$err = error_get_last();
			if ( $err && strpos( $err['message'], 'HookProfiler test notice' ) === false ) {
				echo "There was at least one PHP error during the request:\n";
				echo json_encode( $err, JSON_PRETTY_PRINT );
			}

			if ( Stopwatch::getGlobal() && count( $stats = Stopwatch::getGlobal()->getStats() ) > 0 ) {
				echo "\nGlobal Stopwatch times:\n";
				echo json_encode( $stats, JSON_PRETTY_PRINT );
			}

			//print_r(SystemStats::get());

			echo "</pre>";
			?>

        </div> <!-- hook-prof-html -->


		<?php self::js(); ?>

        <script>
            setTimeout(function () {
                let ls = document.getElementById('hprof-loading-spacer');
                let doc = document.documentElement;
                let top = (window.pageYOffset || doc.scrollTop) - (doc.clientTop || 0);
                ls.children[0].style.top = Math.max(120, top - ls.offsetTop + window.innerHeight / 2) + 'px';
                ls.children[0].style.left = (window.innerWidth / 2) + 'px';
            }, 10);
        </script>


        <div class="hprof-table-wrap" style="display: none !important;" data-why="suppress ide warning">
            <div class="hprof-show-dialog  hprof-experimental"></div>
            <div class="hprof-table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th class="row-head col-head"></th>
                        <td class="fmt-num"><span class="dc"></span></td>
                    </tr>
                    </thead>
                </table>
            </div>
        </div>

        <link rel="import" id="hprof_html_import"
              href="<?php echo esc_attr( add_query_arg( 'hprof_html_import', 1 ) ); ?>">


        <!--
        <link rel="import" id="hprofDashboardImport"
              href="<?php echo esc_attr( \HookProfilerPlugin::url( 'components/site-benchmarks.html' ) ); ?>">
-->

		<?php

		//do_action('hprof_html_deps');
	}

	static function css() { ?>
        <!--suppress CssInvalidPropertyValue -->
        <style>
            #hook-prof-html {
                position: absolute;
                margin: 1em;
                padding-top: 0.5em;
                max-width: 98vw;
                background-color: white;
                padding-bottom: 100px;
                box-shadow: 0 2px 2px 0 rgba(0, 0, 0, 0.14), 0 1px 5px 0 rgba(0, 0, 0, 0.12), 0 3px 1px -2px rgba(0, 0, 0, 0.2);
                color: black;
            }

            #hook-prof-html h3 {
                font-family: "Source Code Pro", monospace;
                margin: 2em auto 1em auto;
                text-align: center;
            }

            #hook-prof-html h3 div {
                margin-right: 2em;
            }

            #hook-prof-html h4 {
                font-weight: normal;
                padding-left: 1em;
                font-size: 18px;
            }

            #hook-prof-html h4.hprof-section {
                margin: 0;
                padding-top: 2em;
                padding-bottom: 0.5em;
            }

            #hook-prof-html div.scope {
                font-size: 14px;
                font-style: italic;
                min-width: 14em;

                padding: 0.5em;
                /* display: none; */
                /* float: right; */
                background-color: #444;
                color: white;
                /* border: 1px solid red; */
                /* opacity: 0.8; */
                margin: 0;
                text-align: center;
            }

            #hook-prof-html div.hprof-table-wrap {
                /*
				width: 31em;

				display: inline-block;
				vertical-align: text-top;
				*/
                /* width: 35em; */
                /*max-width: 90vw; */
                max-width: -moz-available;
                max-width: -webkit-fill-available;

                flex-grow: 1;
                flex-shrink: 1;

                margin: 1em;
                padding: 1em;
                background-color: aliceblue;
            }

            #hook-prof-html div.hprof-table-wrap h4 {
                font-size: 16px;
                margin: 0.5em;
            }

            #hook-prof-html div.hprof-flex {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                align-items: flex-start;
                align-content: flex-start;
                clear: both;
            }

            #hook-prof-html table {
                margin: auto;
            }

            #hook-prof-html .hprof-table-wrap table th, #hook-prof-html table td {
                padding: 1px 3px;
                font-size: 12px;
            }

            #hook-prof-html .hprof-table-wrap table thead tr {
                background-color: lavender !important;
            }

            #hook-prof-html .hprof-table-wrap table th {
                font-family: "Source Code Pro", monospace;
                text-align: left;

                /* width: 85%; */
                overflow: hidden;
                /*word-break: break-word;*/ /*break-all;*/
                padding-right: 1em;
            }

            #hook-prof-html .hprof-table-wrap table th.col-head {
                cursor: pointer;
            }

            #hook-prof-html .hprof-table-wrap table th.row-head {
                min-width: 16em;
                text-align: left;
            }

            #hook-prof-html a {
                display: inline !important;
            }

            #hook-prof-html .hprof-table-wrap table td {
                min-width: 5.5em;
                padding-left: 4px;
                font-family: "Source Code Pro", monospace;
            }

            #hook-prof-html .hprof-table-wrap table td.fmt-num {
                /* text-align: right; */
                white-space: pre;

                font-family: "Source Code Pro", monospace;
                padding-right: 3px;
                padding-left: 1px;
            }

            #hook-prof-html .hprof-table-wrap table tbody tr:nth-child(even) {
                background: white;
            }

            #hook-prof-html .hprof-table-wrap table tbody tr:nth-child(odd) {
                background: none;
            }

            #hook-prof-html .hprof-table-wrap table tr:hover {
                /* background: white; */
            }

            /*#hook-prof-html table tr:nth-child(odd) {background: #FFF} */

            #hook-prof-html div.hprof-table-scroll {
                max-height: 60vh;
                overflow-y: auto;
                width: -moz-fit-content;
                width: fit-content;
                margin: auto;
                overflow-x: auto;
                max-width: 100%;
                max-width: -moz-available;
                max-width: -webkit-fill-available;
            }

            #hook-prof-html .hprof-table-action {
                cursor: pointer;

                display: inline-block;
                width: 1.6em;
                height: 1.6em;
                text-align: center;
                line-height: 1.4em;
                font-size: 16px;
                vertical-align: top;
                float: right;
                opacity: 0.8;
            }

            #hook-prof-html .hprof-show-dialog {

                /*
				border: 1px solid #ddd;
			   border-radius: 50%;
			   font-weight: bold;
			   background-color: #fff;
			   font-family: serif;

			   color: #bbb;*/
            }

            /* detected component highlight */

            #hook-prof-html table span.dc {
                background-color: antiquewhite;
            }

            #hook-prof-html table tbody tr a:not([href=""]) {
                text-decoration: underline !important;
            }

            #hook-prof-html table tbody tr a[href=""] {
                text-decoration: none !important;
                color: inherit;
                cursor: default;
            }

            #hook-prof-html .hprof-table-wrap input.col-filter {
                width: 7em;
                font-size: 12px;
            }

            #hook-prof-html .hprof-table-wrap .hprof-table-scroll {
                /* transition: height 0.4s ease-in-out; */
                transition: max-height 0.4s ease-in-out, max-width 0.4s ease-in-out;
            }

            #hook-prof-html .hprof-table-wrap.collapsed .hprof-table-scroll {
                overflow: hidden;
                max-height: 2em;
                max-width: 30em;
            }

            #hook-prof-html .hprof-table-wrap.collapsed .table-export,
            #hook-prof-html .hprof-table-wrap.collapsed thead,
            #hook-prof-html .hprof-table-wrap.collapsed td,
            #hook-prof-html .hprof-table-wrap.collapsed tr {
                display: none;
            }

            #hook-prof-html .hprof-table-wrap.collapsed td:last-child {
                display: table-cell;
                vertical-align: top;
            }

            #hook-prof-html .hprof-table-wrap.collapsed tr:nth-child(-n+10) {
                display: table-row;
            }

            #hook-prof-html .hprof-table-wrap.collapsed th:first-child {
                max-width: 20em;
            }

            #hprof-loading-spacer .hprof-loader {
                position: relative;
                top: 140px;
                opacity: 0.8;

                border-top: 16px solid #1f77b4;
                border-right: 16px solid #ff7f0e;
                border-bottom: 16px solid #2ca02c;
                border-left: 16px solid #d62728;

                border-radius: 50%;
                width: 120px;
                height: 120px;
                margin-left: -60px;
                margin-top: -60px;
                animation: hprof-spin 2s linear infinite;
            }

            @keyframes hprof-spin {
                0% {
                    transform: rotate(0deg);
                }
                100% {
                    transform: rotate(360deg);
                }
            }

            @media screen and (max-width: 480px) {
                #hook-prof-html table th, #hook-prof-html table td {
                    font-size: 10px;
                    padding: 0 !important;
                    max-width: 50vw !important;
                    overflow:;
                }

                #hook-prof-html div.hprof-table-wrap {
                    max-width: calc(100vw - 1.0em);
                    margin: 0.4em;
                    padding: 0.4em;
                }

                #hook-prof-html table td {
                    min-width: 0 !important;
                    /*white-space: normal;*/
                }

                #hook-prof-html .hprof-table-wrap input.col-filter {
                    width: 5em;
                    margin: 0 !important;
                    padding: 1px !important;
                    font-size: 10px;
                }
            }

            <?php
                            {echo '.hprof-experimental {              display: none !important;          }';}
                ?>
        </style>
		<?php
	}

	public static function js_warn_suppress_never_call() {
		?>
        <script>
            hprofTTLBBenchmark();
            hprofScrollIntoView();
            hprofCsv();
            hprofMakeItADataTable();
        </script><?php

	}


	static function js() {

		?>

        <div style="display:  none;">
            <svg id="hprof-ttlb-bench-plot" style="width: 90vw; height: calc(90vh - 8em);"></svg>
        </div>


        <script>
            function hprofDownload(filename, text) {
                filename = prompt("How do you want to name the report?", filename);
                if (!filename) return;
                if (filename.indexOf('.csv') === -1) filename += '.csv';
                let element = document.createElement('a');
                element.setAttribute('href', 'data:text/csv;charset=utf-8,' + encodeURIComponent(text));
                element.setAttribute('download', filename);
                element.style.display = 'none';
                document.body.appendChild(element);
                element.click();
                document.body.removeChild(element);
            }


            function hprofCsv(event, key) {
                function whatDecimalSeparator() {
                    let n = 1.1;
                    n = n.toLocaleString().substring(1, 2);
                    return n;
                }

                let decSep = whatDecimalSeparator();

                function toFloatStr(match, p1) {
                    void(match);
                    return ('' + parseFloat(p1) + '').replace('.', decSep);
                }

                event.preventDefault();
                let table = document.getElementById('hprof-table-' + key);
                let rows = table.getElementsByTagName('tr');


                let csv = '';
                for (let i = 0; i < rows.length; i++) {
                    let cells = rows[i].childNodes;
                    let line = [];
                    for (let j = 0; j < cells.length; j++) {
                        // remove 0sp u200B
                        let t = cells[j].innerText.replace(/\u200B/g, '').replace(/\s*([0-9.]+)\s\s?\S{1,3}\s*/, toFloatStr);
                        line.push('"' + t + '"');
                    }
                    csv += line.join(',') + '\n';
                }

                hprofDownload("hprof-" + key + "-" + hprof.time.toISOString() + '.csv', csv);
            }

            function hprofMakeItADataTable(key, pages) {
                let t = jQuery('#hprof-table-' + key);
                if (t.hasClass('dt')) return;
                t.addClass('dt');

                if (pages)
                    t.parents('.hprof-table-scroll').css('maxHeight', 'none');

                let inputRow = jQuery('<tr></tr>');
                let searchCells = [];
                // Setup - add a text input to each footer cell
                t.find('th[scope="col"]').each(function () {
                    let title = jQuery(this).text();
                    let searchCell = jQuery('<th><input type="text" class="col-filter" placeholder="filter ' + title + '" /></th>');
                    inputRow.append(searchCell);
                    searchCells.push(searchCell);
                });

                t.children('thead').prepend(inputRow);


                t = t.DataTable(dt = {
                    paging: !!pages,
                    pageLength: 25,
                    aoColumns: hprofDtColTypes[key],
                    //dom: '<"top"iflp>rt<"bottom"><"clear">'
                });
                console.log(dt);


                // Apply the search
                t.columns().every(function (i) {
                    let that = this;
                    jQuery('input', searchCells[i]).on('keyup change', function () {
                        if (that.search() !== this.value) {
                            that
                                .search(this.value, true, false)
                                .draw();
                        }
                    });
                });

                //t.adjust().draw();
            }

            jQuery.fn.dataTableExt.oSort['string-asc'] = function (x, y) {
                if (x [0] >= "0" && x[0] <= "9") {
                    x = parseFloat(x);
                    y = parseFloat(y);
                }
                return ((x < y) ? -1 : ((x > y) ? 1 : 0));
            };

            jQuery.fn.dataTableExt.oSort['string-desc'] = function (x, y) {
                if (x [0] >= "0" && x[0] <= "9") {
                    x = parseFloat(x);
                    y = parseFloat(y);
                }
                return ((x < y) ? 1 : ((x > y) ? -1 : 0));
            };


            (function () {
                console.info('hprof html report main');

                let outDiv = document.getElementById('hook-prof-html');
                let wpfooter = null;
                let parent = null;


                // collapse tables first
                let wraps = outDiv.getElementsByClassName('hprof-table-wrap');
                for (let i = 0; i < wraps.length; i++) {
                    let key = wraps[i].dataset.key;
                    let state = localStorage.getItem('hprofWrapState' + key);
                    if (state === 'collapsed') {
                        wraps[i].classList.add('collapsed');
                        wraps[i].style.order = 99; // move to the end
                    }
                }


                let updateOutDiv = function () {
                    //console.log(wpfooter, parent);
                    if (wpfooter && parent === wpfooter.parentNode) {
                        let footerStyle = window.getComputedStyle(wpfooter);
                        let left = (footerStyle.display === 'none') ? 0 : (parseInt(footerStyle.left) + parseInt(footerStyle.paddingLeft) + parseInt(footerStyle.marginLeft));
                        //outDiv.style.position = footerStyle.position;
                        outDiv.style.marginLeft = left + "px";
                        outDiv.style.width = "calc(98vw - " + left + "px)";
                        console.log('outDiv.style.width', (window.innerWidth * 0.98 - left));
                    } else {
                        outDiv.style.width = "calc(98vw - 2em)";
                    }
                    // finally render it!
                    outDiv.style.display = '';

                };

                let modal;

                let showDialog = function (content) {
                    modal = picoModal({
                        content: "",
                        overlayStyles: {
                            backgroundColor: "#222",
                            opacity: 0.3
                        }
                    }).afterCreate(function (modal) {
                        if ('function' === typeof content) {
                            content(modal.modalElem());
                        } else {
                            modal.modalElem().innerHTML = "<div style='min-width: 20em; max-width: 40em;'><p style='font-size: 16px;'>" + content + "<p><p><button class='hprof-dialog-ok' style='font-size: 16px;'>OK</button></p></div>";
                        }
                    }).show();
                    return modal;
                };

                document.body.addEventListener('click', function (e) {
                        if (e.target.className === 'hprof-dialog-ok') {
                            modal.close();
                            e.preventDefault();
                            return false;
                        }

                        if (e.target.classList.contains('col-head')) {
                            let table = e.target;
                            while (table.tagName !== 'TABLE') table = table.parentNode;
                            let wrap = table;
                            while (!wrap.dataset.key) wrap = wrap.parentNode;
                            // dont sort datatables & big tables (our sort is sloooow)
                            if (!table.classList.contains('dt')) {
                                if (table.getElementsByTagName('tr').length < 200)
                                    hprof.sortTable(table, +e.target.dataset.col);
                                else {
                                    hprofMakeItADataTable(wrap.dataset.key, true);
                                    setTimeout(function () {
                                        e.target.click();
                                    }, 100);
                                }
                            }

                        }
                    }
                );

                document.addEventListener('copy', function (e) {
                    if (e.target.classList.contains('row-head')) {
                        e.preventDefault();
                        e.clipboardData.setData('text/plain', window.getSelection().toString().replace(/\u200B/g, ''));
                    }
                });

                outDiv.addEventListener('click', function (e) {
                    let t = e.target;
                    if (t.classList.contains('hprof-table-action') || ((t = e.target.parentElement) && t.classList.contains('hprof-table-action'))) {
                        e.preventDefault();

                        if (t.classList.contains('hprof-show-dialog')) {
                            if (t.dataset.settings) {
                                hprof.loadSettingsGUI();
                                showDialog(function (el) {
                                    let sets = document.getElementById('hook-prof-settings-gui');
                                    el.appendChild(sets);
                                    sets.style.display = '';
                                });

                            } else {
                                showDialog(t.dataset.msg);
                            }
                        }

                        if (t.classList.contains('hprof-collapse')) {
                            let wrap = t.parentNode.parentNode;
                            let key = wrap.dataset.key;
                            let show = wrap.classList.contains('collapsed');
                            localStorage.setItem('hprofWrapState' + key, show ? 'normal' : 'collapsed');
                            wrap.classList[show ? 'remove' : 'add']('collapsed');
                        }

                    }
                });

                window.addEventListener('resize', updateOutDiv);


                let displayTTFB = function () {
                    if (!performance || !performance.timing)
                        return;

                    let t = performance.timing;
                    let reqStart = t.requestStart;
                    let ttfb = t.responseStart - reqStart;
                    let ttlb = t.responseEnd - reqStart;

                    let table = document.getElementById('hprof-table-request');
                    let tbody = table.getElementsByTagName('tbody')[0] || table;
                    let trs = tbody.getElementsByTagName('tr');

                    let row = trs[trs.length - 1].cloneNode(true);
                    row.childNodes[0].innerText = 'TTFB';
                    row.childNodes[1].innerText = ttfb + ' ms';

                    tbody.appendChild(row);

                    row = row.cloneNode(true);
                    row.childNodes[0].innerText = 'T_download';
                    row.childNodes[1].innerText = (ttlb - ttfb) + ' ms';
                    tbody.appendChild(row);

                    if (HPROF_SERVER_TTLB) {
                        row = row.cloneNode(true);
                        row.childNodes[0].innerText = 'TSRV';
                        row.childNodes[1].innerText = Math.round(HPROF_SERVER_TTLB) + ' ms';
                        tbody.appendChild(row);
                    }


                    // add TTFB to admin_menu_bar
                    if (hprof.settings.adminBarDisplayTimes) {
                        let admBarEl = document.getElementById('wp-admin-bar-hprof');
                        if (admBarEl) {
                            let ttlbServer = ('undefined' !== typeof HPROF_SERVER_TTLB) ? Math.round(HPROF_SERVER_TTLB) : '?';
                            admBarEl.childNodes[0].innerHTML += ' [TTFB ' + ttfb + 'ms, TSRV ' + ttlbServer + 'ms, TTLB ' + ttlb + 'ms]';
                        }
                    }
                };

                function insertAfter(newNode, referenceNode) {
                    referenceNode.parentNode.insertBefore(newNode, referenceNode.nextSibling);
                }

                document.addEventListener('readystatechange', function () {
                    if (document.readyState === "complete") {

                        setTimeout(function () {

                            wpfooter = document.getElementById('wpfooter');
                            let footers = document.getElementsByTagName('footer');
                            parent = (wpfooter && wpfooter.parentNode)
                                || (footers.length && footers.item(footers.length - 1))
                                || document.body;

                            parent.appendChild(outDiv);


                            let h = outDiv.getElementsByTagName('h3')[0];
                            h.innerHTML = '&#x1F333; ' + h.innerHTML;
                            // &#x231A

                            updateOutDiv();
                            document.getElementById('hprof-loading-spacer').style.display = 'none';

                            let issueWrap = document.getElementById('hprof-issues');
                            if (issueWrap)
                                insertAfter(issueWrap, outDiv.getElementsByClassName('scope')[0]);

                            let treemapWrap = document.getElementById('hprof-treemap-wrap');
                            if (treemapWrap)
                                insertAfter(treemapWrap, outDiv.getElementsByClassName('scope')[0]);


                            console.log('hook-prof-html-ready', outDiv.clientHeight);
                            document.dispatchEvent(new CustomEvent("hook-prof-html-ready", {"detail": "ready"}));
                            localStorage.setItem('hprofContainerHeight', outDiv.clientHeight);
                        }, 10);

                        displayTTFB();
                    }

                });
            })();
        </script>

		<?php
	}

	static function isSorted( &$arr ) {
		$prev    = 0;
		$diffMin = INF;
		$diffMax = - INF;

		foreach ( $arr as $v ) {
			$d = ( $v - $prev );
			if ( $d < $diffMin ) {
				$diffMin = $d;
			}
			if ( $d > $diffMax ) {
				$diffMax = $d;
			}

			if ( ( $diffMin > - 1e-6 ) !== ( $diffMax > - 1e-6 ) ) {
				return false;
			}

			$prev = $v;
		}

		return true;
	}

	static function detectKeyType( &$keys ) {
		$funcTok    = array( '::', '->', '\\', '##' ); // these cant occur in hook tags, '##' is for virtual funcs
		$knownHooks = array(
			'init',
			'after_setup_theme',
			'admin_bar_menu',
			'plugins_loaded',
			'map_meta_cap'
		); // these are never function names

		foreach ( $keys as $k ) {
			if ( strpos( $k, '..' ) !== false ) {
				return 'hookGap';
			}

			if ( strncmp( $k, 'plugin/', 7 ) === 0 || strncmp( $k, 'wpcore/', 7 ) === 0 || strncmp( $k, 'theme /', 7 ) === 0 ) {
				return 'component';
			}

			foreach ( $funcTok as $ft ) {
				if ( strpos( $k, $ft ) !== false ) {
					return 'func';
				}
			}

			if ( in_array( $k, $knownHooks ) ) {
				return 'hook';
			}


			if ( substr( $k, - 4 ) === ".php" && strpos( $k, '##' ) === false ) {
				return 'file';
			}

			if ( strlen( $k ) > 4 && substr( $k, - 1 ) === ')' && preg_match( '/^[a-z0-9_]+\([^\)]*\)$/i', $k ) ) {
				return 'method';
			}
		}

		// fallback
		foreach ( $keys as $k ) {
			if ( ( $p = strpos( $k, '#' ) ) > 3 && in_array( substr( $k, 0, $p ), $knownHooks ) ) {
				return 'hook#func';
			}
		}

		return 'unknown';
	}

	/**
	 * @var HookProfiler
	 */
	static $profiler;

	static function getFuncLocation( $funcId ) {
		if ( ! isset( self::$funcLocations[ $funcId ] ) ) {
			$funcIdNT                         = HookProfiler::rmTag( $funcId );
			$fn                               = self::$profiler->getFunctionFileName( self::$profiler->funcs[ $funcIdNT ], $funcIdNT, $line );
			self::$funcLocations[ $funcIdNT ] = "$fn:$line";
			if ( $funcIdNT !== $funcId ) {
				self::$funcLocations[ $funcId ] = self::$funcLocations[ $funcIdNT ];
			}
		}

		return self::$funcLocations[ $funcId ];
	}

	static function maybeAddActionLink( $from, $type ) {
		$from0sp = self::add0Sp( $from );
		if ( $type === 'func' ) {
			$plug = self::$profiler->getPluginByFunction( $from );
			self::sw()->measure( 'maybeAddActionLink/getPluginByFunction' );
			if ( strncmp( $plug, 'plugin/', 7 ) === 0 ) {
				$funcLoc = self::getFuncLocation( $from );
				self::sw()->measure( 'maybeAddActionLink/func/plug/funcLoc' );
				$to = '<a data-k="' . esc_attr( $from ) . '" href="' . FunctionInspector::getEditLink( $funcLoc ) . '">' . self::add0Sp( HookProfiler::rmTag( $from ) ) . '</a>';
				self::sw()->measure( 'maybeAddActionLink/func/plug' );
			} else {
				$to = '<a data-k="' . esc_attr( $from ) . '" href="" onclick="return false">' . self::add0Sp( HookProfiler::rmTag( $from ) ) . '</a>';
				self::sw()->measure( 'maybeAddActionLink/func/noplug' );
			}
		} elseif ( $type === 'component' ) {
			if ( strncmp( $from, 'plugin/', 7 ) === 0 ) {
				$to = '<a data-k="' . esc_attr( $from ) . '" href="' . PluginInspector::getInspectionLink( $from ) . '">' . $from0sp . '</a>';
				self::sw()->measure( 'maybeAddActionLink/getPluginInstpectLink' . $from );
			} else {
				$to = '<a data-k="' . esc_attr( $from ) . '" href="" onclick="return false">' . $from0sp . '</a>';
			}
		} elseif ( $type === 'file' || $type === 'hook' ) {
			$to = $from0sp;
		} else {
			$to = $from;
		}

		self::sw()->measure( 'maybeAddActionLink' . $type );

		return $to;
	}

	static function displayStatsTable( $data, $opts, $name, $desc ) {
		$firstCol = reset( $data );
		if ( empty( $firstCol ) ) {
			// require first col!
			return self::createTable( $name, [], [], $desc );
		}

		$data = array_filter( $data ); // filter nulls and empty arrays

		$rowKeys = array_keys( $firstCol );
		$keyType = self::detectKeyType( $rowKeys );

		self::sw()->measure( $name . '_detectKeyType' );

		if ( ! empty( $opts['resolveComponents'] ) ) {
			$data['component'] = [];
			foreach ( $rowKeys as $key ) {
				$data['component'][ $key ] = ( $keyType !== 'func' ) ? HookProfiler::getComponentByFileName( $key ) : self::$profiler->getPluginByFunction( $key );
			}
		}

		$colNames = array_keys( $data );

		//print_r($colNames);

		if ( ! empty( $opts['sort'] ) ) {
			arsort( $data[ $colNames[0] ], SORT_NUMERIC );
			$firstCol = reset( $data );
			$rowKeys  = array_keys( $firstCol );
			//if ( count( $timings ) < 4 || ! self::isSorted( $timings ) ) {
			//    arsort( $timings, SORT_NUMERIC );
			//}

		}


		$rows     = [];
		$firstCol =& $data[ $colNames[0] ];


		$nCols = count( $colNames );


		$nRows = count( $rowKeys );


		$keyRemap = array_combine( $rowKeys, $rowKeys );

		$colDtTypes = array( [ "sType" => 'string' ] ); // key (first col) is always string

		$keyFilter = isset( $opts['keyFilters'][ $colNames[0] ] ) ? $opts['keyFilters'][ $colNames[0] ] : null;

		foreach ( $keyRemap as $from => &$to ) {
			$to = self::maybeAddActionLink( $keyFilter ? call_user_func( $keyFilter, $from ) : $from, $keyType );
		}

		self::sw()->measure( $name . '_keyRemap' );

		// add supporting columns
		for ( $j = 1; $j < $nCols; $j ++ ) {
			$colName = $colNames[ $j ];
			$ts      = self::getTypeAndScale( $colName, $data[ $colName ] );

			self::sw()->measure( $name . '_getTypeAndScale' );

			//print_r($ts );
			//echo "TTTS" . print_r( $ts );

			$keyFilter = isset( $opts['keyFilters'][ $colName ] ) ? $opts['keyFilters'][ $colName ] : null;


			for ( $i = 0; $i < $nRows; $i ++ ) {
				$rowKey                                 = $rowKeys[ $i ];
				$k                                      = $keyFilter ? call_user_func( $keyFilter, $rowKey ) : $rowKey;
				$d                                      = isset( $data[ $colName ][ $k ] ) ? $data[ $colName ][ $k ] : '';
				$rows[ $keyRemap[ $rowKey ] ][ $j - 1 ] = self::formatByType( self::maybeAddActionLink( $d, $colName ), $ts );
			}

			self::sw()->measure( $name . '_rowGen' );

			$colDtTypes[] = [ "sType" => $ts[0] == 'string' ? 'string' : 'numeric' ];
		}

		self::sw()->measure( $name . '_supportRows' );

		// add first data column to the end!
		$colName = $colNames[0];
		$ts      = self::getTypeAndScale( $colName, $data[ $colName ] );
		//echo "ncols = $nCols colName = $colName, fmt =";

		for ( $i = 0; $i < $nRows; $i ++ ) {
			$rowKey                                     = $rowKeys[ $i ];
			$k                                          = $firstCol[ $rowKey ];
			$rows[ $keyRemap[ $rowKey ] ][ $nCols - 1 ] = self::formatByType( $k, $ts );
		}

		// last col is always num
		$colDtTypes[] = [ "sType" => 'numeric' ];

		//print_r($rows);

		// our first col key is the type hint, not the header!
		// we had to guess the header with keyType
		$colNames[]  = $colNames[0]; //$ts[0];
		$colNames[0] = $keyType;

		//echo "colNames";
		//print_r($colNames);


		$key = self::createTable( $name, $rows, $colNames, $desc );

		echo '<script> (typeof(hprofDtColTypes) !== "undefined")||(hprofDtColTypes={}); hprofDtColTypes["' . $key . '"] = ' . json_encode( $colDtTypes ) . ';</script>';
	}

	static function getTypeAndScale( $colTag, &$rows ) {

		$first = reset( $rows );
		if ( is_string( $first ) ) {
			return [ 'string', 0 ];
		}

		$guesses = [
			'mem_'    => 'mem',
			'memory'  => 'mem',
			'count'   => 'count',
			'time'    => 'time',
			'size'    => 'size',
			'incs'    => 'count',
			'calls'   => 'count',
			'called'  => 'count',
			'fires'   => 'count',
			'queries' => 'count',
			'cnt'     => 'count',
		];


		$colTypeGuessedFromName = '';
		foreach ( $guesses as $needle => $type ) {
			if ( stripos( $colTag, $needle ) !== false ) {
				$colTypeGuessedFromName = $type;
				break;
			}
		}


		// default is time
		$type  = "time";
		$scale = 1;

		switch ( $colTypeGuessedFromName ) {
			case 'count':
				$type = 'count';
				break;

			case 'time':
				$tMax = max( $rows );
				if ( $tMax < 2e-5 ) {
					$type  .= '_ns';
					$scale = 1e6;
				} elseif ( $tMax < 2e-2 ) {
					$type  .= '_us';
					$scale = 1e3;
				}
				break;

			case 'mem':
			case 'size':
				$type  = 'size';
				$scale = 1 / 1024;
				break;
		}

		return [ $type, $scale ];
	}

	static $formatting = array(
		'string'  => '%s',
		'time'    => "%7.2f ms",
		'time_ms' => "%7.2f ms",
		'time_us' => "%7.2f &mu;s",
		'time_ns' => "%7.2f <span style='font-weight: lighter;'>ns</span>",
		'size'    => "%7.0f KiB",
		'count'   => '%6d'
	);


	static function add0Sp( $str ) {
		return $str; // this only causes trouble during copy, search
		//return str_replace( [ '_', '/', '\\', '@' ], [ '&#8203;_', '&#8203;/', '&#8203;\\', '&#8203;@' ], $str );
	}

	static function rm0Sp( $str ) {
		return str_replace( '&#8203;', '', $str );
	}

	static function formatByType( $data, $typeAndScale ) {

		$res = ( $typeAndScale[0] == 'string' )
			? [ $data, "%s" ] // add zero space to allow line break
			: [ $data * $typeAndScale[1], self::$formatting[ $typeAndScale[0] ] ];

		self::sw()->measure( 'formatByType' );

		return $res;
	}

	/**
	 * @param HookProfiler $profiler
	 * @param $timings
	 * @param $name
	 * @param bool $resolvePlugin
	 * @param bool $splitTagFunc
	 * @param int $maxRows
	 * @param string $desc
	 */
	static function displayTimingStats( $profiler, $timings, $name, $resolvePlugin = false, $splitTagFunc = false, $maxRows = 100, $desc = "" ) {
		// only sort if not pre-sorted in any direction
		if ( count( $timings ) < 4 || ! self::isSorted( $timings ) ) {
			arsort( $timings, SORT_NUMERIC );
		}

		$maxTiming = empty( $timings ) ? 0 : max( $timings );

		$keys        = array_keys( $timings );
		$n           = count( $keys );
		$displayRows = min( $n, $maxRows );


		$keyType = self::detectKeyType( $keys ); // TODO this is slow
		$isCount = array_sum( array_map( 'is_float', $timings ) ) < ( $n / 2 );

		$countField = ( ! $isCount && in_array( $keyType, array(
				'func',
				'hook',
				'hookGap'
			) ) ) ? ( $keyType . "MapCount" ) : false;

		if ( $splitTagFunc && $countField ) {
			$countField = 'hookFuncMapCalls';
		}


		//rint_r(array_map('is_float', $timings));
		// detect counts

		if ( $isCount ) {
			$tFmt   = "%6d";
			$tScale = 1;
		} elseif ( $maxTiming < 2e-5 ) {
			$tFmt   = "%7.2f <span style='font-weight: lighter;'>ns</span>";
			$tScale = 1e6;
		} elseif ( $maxTiming < 2e-2 ) {
			$tFmt   = "%7.2f &mu;s";
			$tScale = 1e3;
		} else {
			$tFmt   = "%7.2f ms";
			$tScale = 1;
		}

		$isSize = false;
		if ( ( $keyType == 'file' && $isCount ) || strpos( $name, 'memory' ) !== false ) {
			$tFmt   = "%7.2f KiB";
			$tScale = 1 / 1024;
			$isSize = true;
		}

		echo "<!-- keytype for $name is $keyType -->";

		$rows = [];


		for ( $i = 0; $i < $displayRows; $i ++ ) {
			$k  = $keys[ $i ];
			$ko = $k;

			if ( $splitTagFunc ) {
				$kSplit = explode( '#', $k );
				$k      = $kSplit[1];

				// avoid overrides
				if ( isset( $rows[ $k ] ) ) {
					$k .= ' ';
				}
			}

			$k = self::add0Sp( $k );


			if ( $keyType == 'func' ) {
				if ( strncmp( $profiler->getPluginByFunction( $ko ), 'plugin/', 7 ) === 0 ) {
					$k = '<a data-k="' . esc_attr( $k ) . '" href="' . FunctionInspector::getEditLink( self::$funcLocations[ self::rm0Sp( $k ) ] ) . '">' . $k . '</a>';
				}
			}

			if ( $keyType == 'hookGap' ) {
				$k = str_replace( '..', '..<br>', $k );
				$k = str_replace( [ '((', '))' ], [
					'<span class="dc">((',
					'))</span>'
				], $k ); //highlight detected component
			}

			if ( $keyType === 'file' ) {
				$k = str_replace( ABSPATH, '', $k );
			}


			$rows[ $k ] = [];

			if ( $resolvePlugin ) {
				$rows[ $k ][] = ( $keyType === 'file' ) ? HookProfiler::getComponentByFileName( $ko ) : $profiler->getPluginByFunction( $ko );
			}

			if ( $splitTagFunc ) {
				/** @noinspection PhpUndefinedVariableInspection */
				$rows[ $k ][] = self::add0Sp( $kSplit[0] );
			}

			if ( $keyType == 'hook' ) {
				$rows[ $k ][] = [ @$profiler->hookFirstFiredAt[ $ko ], "%7.2f ms" ];
			}

			if ( $countField ) {
				$rows[ $k ][] = [ $profiler->$countField[ $ko ], "%6d" ];
			}

			$rows[ $k ][] = [ $timings[ $keys[ $i ] ] * $tScale, $tFmt ];

		}


		$restTiming = 0;
		$restCount  = 0;
		$more       = ( $n - $i );

		if ( $more > 0 ) {
			for ( ; $i < $n; $i ++ ) {
				$restTiming += $timings[ $keys[ $i ] ];
				if ( $countField ) {
					$restCount += $profiler->$countField[ $keys[ $i ] ];
				}
			}

			$moreRow = [];

			if ( $resolvePlugin ) {
				$moreRow[] = '';
			}

			if ( $splitTagFunc ) {
				$moreRow[] = '';
			}

			if ( $keyType == 'hook' ) {
				$moreRow[] = '';
			}

			if ( $countField ) {
				$moreRow[] = [ $restCount, "%4d" ];
			}


			$moreRow[] = [ $restTiming * $tScale, $tFmt ];

			$rows[" ... $more more ..."] = $moreRow;
		}


		//$head = [ trim( rtrim( $name, 's' ), '#' ) ];
		$head = [ $keyType ];

		if ( $resolvePlugin ) {
			$head[] = 'component';
		}

		if ( $splitTagFunc ) {
			$head[] = 'caller hook';
		}

		if ( $keyType == 'hook' ) {
			$head[] = 'first capture at';
		}

		if ( $countField ) {
			$head[] = ( $keyType == 'hook' ) ? '#fires' : ( $keyType == 'func' ? '#calls' : '#' );
		}

		if ( $splitTagFunc || $resolvePlugin || $isCount ) {
			$head[] = $isSize ? 'size' : ( $isCount ? 'count' : ( strtolower( substr( $name, - 2 ) ) == 'at' ? 'time' : 'runtime' ) );
		}

		self::createTable( $name, $rows, $head, $desc );
	}

	/**
	 * @param string $title
	 * @param array $rows
	 * @param array $head
	 * @param string $desc
	 *
	 * @return string
	 */
	static function createTable( $title, $rows, $head = [], $desc = "" ) {

		self::sw()->measure( $title . "_pre_generate" );

		$key = trim( sanitize_key( str_replace( ' ', '-', $title ) ), '-' );

		$desc = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $desc );

		$desc = str_replace( "'", "&#39;", $desc );
		echo "\n\n<div class='hprof-table-wrap' data-key='$key'>";
		echo "<h4>$title";
		echo "<div class='hprof-table-action hprof-collapse' data-msg='$desc'>&#x1F441;</div>";
		echo "<div class='hprof-table-action hprof-show-dialog' data-msg='$desc'>&#x2139;</div>";
		echo "</h4>";
		echo "<div class='hprof-table-scroll'>";
		echo "<table id='hprof-table-$key'>\n";

		$is_multiCol = is_array( reset( $rows ) ) && array_sum( array_map( 'is_array', reset( $rows ) ) ) > 0;

		//echo "multi $is_multiCol col";
		if ( count( $head ) > 1 ) {
			echo "<thead><tr>";
			$i = 0;
			foreach ( $head as $h ) {
				echo "<th scope='col' class='col-head col-$i' data-col='$i'>$h</th>";
				$i ++;
			}
			echo "</tr></thead>";
		}

		echo "<tbody>";
		if ( count( $rows ) == 0 ) {
			echo "<tr><td><i>no captures!</i></td></tr>";
		}
		foreach ( $rows as $name => $row ) {
			echo "<tr><th scope='row' class='row-head'>$name</th>";

			if ( ! $is_multiCol ) {
				$row = [ $row ];
			}
			foreach ( $row as $val ) {
				if ( is_array( $val ) ) {
					echo "<td class='" . ( ( $val[1] !== '%s' ) ? "fmt-num" : "" ) . "'>";
					printf( $val[1], $val[0] );
					echo "</td>";
				} else {
					echo "<td>", $val, "</td>";
				}
			}

			echo "</tr>\n";
		}
		echo "</tbody>";

		echo "</table></div>";

		if ( count( $rows ) > 2 ) {

			echo "<p class='table-export' style='    margin: 0.4em 0.4em 0 0;       opacity: 0.5; float: right; font-size: 14px;'>";
			if ( count( $head ) > 1 ) {
				echo "<a href='javascript:hprofMakeItADataTable(&#39;{$key}&#39;);'>DataTable</a> / <a href='javascript:hprofMakeItADataTable(&#39;{$key}&#39;,true);'>with pages</a> &nbsp; ";
			}
			if ( count( $rows ) > 20 ) {
				echo count( $rows ) . " rows &nbsp; ";
			}
			$onc = "onclick='hprofCsv(event, &#39;{$key}&#39;)'"; // put this in var to suppress IDE warning
			echo "<a style='padding: 0.2em;  border-radius: 2px;' href='' $onc >&#x1F4D1; CSV</a></p>";
		}


		echo "</div>\n\n";

		self::sw()->measure( $title . 'createTable' );

		return $key;
	}


	static function getFriendlyPluginsHash( $activePlugins = null ) {
		if ( $activePlugins === null ) {
			$activePlugins = get_option( 'active_plugins' );
		}


		$activePluginPrefixes = array();
		foreach ( $activePlugins as $pl ) {
			$pl = trim( dirname( $pl ), '-_ ' );
			if ( strncmp( $pl, 'wp', 2 ) == 0 ) {
				$pl = substr( $pl, 2 );
			}
			$pl = str_replace( '_', '-', $pl );
			$pl = trim( $pl, '-' );

			$plt = explode( '-', $pl );
			if ( count( $plt ) == 2 ) {
				$pl = $plt[0]{0} . $plt[1]{0} . ( strlen( $plt[1] ) > 1 ? $plt[1]{1} : '' );
			}
			if ( count( $plt ) > 2 ) {
				$pl = implode( '', array_map( function ( $s ) {
					return $s{0};
				}, $plt ) );
			}

			$pl = substr( $pl, 0, 3 );

			if ( ! isset( $activePluginPrefixes[ $pl ] ) ) {
				$activePluginPrefixes[ $pl ] = 0;
			}
			$activePluginPrefixes[ $pl ] ++;
		}


		$friendlyHash = '';
		foreach ( $activePluginPrefixes as $p => $c ) {
			$friendlyHash .= ( $c > 1 ? $c : '' ) . $p . '-';
		}
		$friendlyHash = trim( $friendlyHash, '-' );

		return count( $activePlugins ) . '-' . $friendlyHash;
	}


	static function cookieText() {
		?>
        Calltree loaded the profiler but it did not capture anything. Due to high intrusion into the WordPress Plugin API, it is disabled by default. Before using it please consider:
        * The profiler in its current state has an overhead of 10 to 40 % (depending on the active plugins), so it can slow down your site. Relative times are still
        * The profiler completely overrides the Plugin API, and in some rare situations this can cause trouble. To prevent data loss, we built in a safe mode that permanently
        but not active
		<?php
	}
}