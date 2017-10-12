<?php


namespace WPHookProfiler;


class Treemap {

	private static function filterCompGroup( $group ) {
		$group = trim( $group );
		if ( $group == 'plugin' || $group == 'theme' || $group == 'muplug' ) {
			$group .= 's';
		}

		return $group;
	}


	private static function filterName( $name, $compName ) {
		// sanitize
		$name = str_replace( '#plugin_main#', '', $name );
		$name = str_replace( '@0->', '/', $name );
		$name = str_replace( '@1->', '/', $name );
		$name = str_replace( '::', '/', $name );
		$name = str_ireplace( $compName . '/', '', $name );
		$name = trim( $name, '/' );

		return $name;
	}


	/**
	 * @param array $data
	 * @param string $fullKey
	 * @param int $value
	 * @param string $name
	 */
	private static function add( &$data, $fullKey, $value, $name = '' ) {
		$path = explode( '/', $fullKey );

		$path[0] = self::filterCompGroup( $path[0] );
		$path[2] = self::filterName( empty( $name ) ? $path[2] : $name, $path[1] );

		$data[ $fullKey ] = [
			'key'       => join( '/', $path ),
			'region'    => $path[0],
			'subregion' => $path[1],
			'name'      => $path[2],
			'value'     => $value
		];
	}

	/**
	 * @param HookProfiler $profiler
	 * @param $componentMapSelfTime
	 *
	 * @return string
	 */
	public static function render( $profiler ) {
		$data2 = array();


		$mem = count( $profiler->hookFuncMapMemSelf ) > 0 && $profiler->profileMem;

		$hookFuncMapTime = $profiler->hookFuncMapFuncTimeSelf;
		arsort( $hookFuncMapTime, SORT_NUMERIC );

		$compMapFuncCount = array();


		foreach ( $hookFuncMapTime as $hookFunc => $timeSelf ) {
			$funcId = HookProfiler::rmTag( $hookFunc );
			$comp   = $profiler->funcMapComponent[ $funcId ];
			list( $compGroup, $compName ) = explode( '/', $comp );
			$compGroup = self::filterCompGroup( $compGroup );

			if ( ! isset( $compMapFuncCount[ $comp ] ) ) {
				$compMapFuncCount[ $comp ] = 1;
			}


			$name = $funcId;
			// agg
			if ( $timeSelf < 0.1 || $compMapFuncCount[ $comp ] >= 32 ) {
				$name = 'misc';
			} else {
				$name = self::filterName( $name, $compName );
			}

			$key = $compGroup . '/' . $compName . '/' . $name;

			if ( isset( $data2[ $key ] ) ) {
				$data2[ $key ]['value'] += $profiler->hookFuncMapFuncTimeSelf[ $hookFunc ];
				$data2[ $key ]['mem']   += ( $mem && isset( $profiler->hookFuncMapMemSelf[ $hookFunc ] ) ) ? $profiler->hookFuncMapMemSelf[ $hookFunc ] : 0;
			} else {
				$data2[ $key ] = [
					'key'       => $key,
					'name'      => $name,
					'region'    => $compGroup,
					'subregion' => $compName,
					'value'     => $profiler->hookFuncMapFuncTimeSelf[ $hookFunc ],
					'mem'       => ( $mem && isset( $profiler->hookFuncMapMemSelf[ $hookFunc ] ) ) ? $profiler->hookFuncMapMemSelf[ $hookFunc ] : 0
				];
			}

			$compMapFuncCount[ $comp ] ++;
		}


		// we can call it preinc because its the time until wp-settings.php/timer_start(),
		// and thats before most of WP is included
		self::add( $data2, 'wpcore/load/early-init', ( $profiler->timestart - $profiler->requestTime ) * 1000 );


		// this measures most of WP includes
		self::add( $data2, 'wpcore/load/includes', ( $profiler->tStart - $profiler->timestart ) * 1000 );

		if ( isset( $profiler->hookMapLastCallEndTime['muplugins_loaded'] ) ) {
			$muplugTime = $profiler->hookMapLastCallEndTime['muplugins_loaded'] - ( ( $profiler->tStart - $profiler->requestTime ) * 1000 );
			//echo "muplug $muplugTime " . $profiler->hookMapLastCallEndTime['muplugins_loaded'] . " ".(($profiler->tStart - $profiler->requestTime));
			self::add( $data2, 'wpcore/load/muplugins', $muplugTime );
		}

		// TODO: test if defined
		if ( defined( 'TEMPLATEPATH' ) && isset( $profiler->hookFirstFiredAt['after_setup_theme'] ) && isset( $profiler->hookMapLastCallEndTime['setup_theme'] ) ) {
			$themeComp = HookProfiler::getComponentByFileName( TEMPLATEPATH . '/functions.php' );
			$setupTime = $profiler->hookFirstFiredAt['after_setup_theme'] - $profiler->hookMapLastCallEndTime['setup_theme'];
			self::add( $data2, $themeComp . '/setup', $setupTime );
		}
		?>
        <style>
            #hprof-treemap2 {
                background: #fff;
                font-family: "Source Code Pro", monospace;
                width: 100%;
                height: 40em;
                max-height: 70vh;
            }

            #hprof-treemap2 .title {
                font-weight: bold;
                font-size: 28px;
                text-align: center;
                margin-top: 6px;
                margin-bottom: 6px;
            }

            #hprof-treemap2 text {
                pointer-events: none;
                fill: white;
            }

            #hprof-treemap2 .grandparent text {
                font-weight: bold;
            }

            #hprof-treemap2 rect {
                fill: none;
                stroke: #fff;
            }

            #hprof-treemap2 rect.parent,
            .grandparent rect {
                stroke-width: 2px;
            }

            #hprof-treemap2 rect.parent {
                pointer-events: none;
            }

            #hprof-treemap2 .grandparent rect {
                fill: #444;
            }

            #hprof-treemap2 .grandparent:hover rect {
                fill: #666;
            }

            #hprof-treemap2 .children rect.parent,
            #hprof-treemap2 .grandparent rect {
                cursor: pointer;
            }

            #hprof-treemap2 .children rect.parent {
                fill: #bbb;
                fill-opacity: .8;
            }

            #hprof-treemap2 .children:hover rect.child {
                fill: #bbb;
            }
        </style>


        <div>
			<?php if ( $profiler->profileMem && false ) : ?>
                <form id="hprof-treemap-form">
                    <!-- <label><input type="radio" name="mode" value="calls"> Calls</label> -->
                    <label><input type="radio" name="mode" value="time" checked> Time</label>
                    <label><input type="radio" name="mode" value="mem"> Memory</label>
                </form>
			<?php endif; ?>
            <div id="hprof-treemap2"></div>
        </div>


        <script>
            'use strict';

            hprof.loadD3(3);
            document.addEventListener('hook-prof-html-ready', function () {
                const d3 = window.d3v3;

                const elWidth = document.getElementById('hprof-treemap2').clientWidth;
                const elHeight = document.getElementById('hprof-treemap2').clientHeight;

                console.log(elWidth);

                function main(opts, data) {
                    var root,

                        rname = opts.rootname,
                        margin = opts.margin,
                        theight = 36 + 16;

                    var formatFloat0 = d3.format(".0f");
                    var formatFloat1 = d3.format(".1f");
                    var formatNumber = (f) => (f < 1 ? formatFloat1(f) : formatFloat0(f)) + '%';

                    var width = elWidth - margin.left - margin.right,
                        height = elHeight - margin.bottom - margin.top - 16,
                        transitioning;

                    var color = d3.scale.category10();

                    var x = d3.scale.linear()
                        .domain([0, width])
                        .range([0, width]);

                    var y = d3.scale.linear()
                        .domain([0, height])
                        .range([0, height]);

                    var treemap = d3.layout.treemap()
                        .children(function (d, depth) {
                            return depth ? null : d._children;
                        })
                        .sort(function (a, b) {
                            return a.value - b.value;
                        })
                        .ratio(height / width * 0.5 * (1 + Math.sqrt(5)))
                        .round(false);

                    var svg = d3.select("#hprof-treemap2").append("svg")
                        .attr("width", width + margin.left + margin.right)
                        .attr("height", height + margin.bottom + margin.top)
                        .style("margin-left", -margin.left + "px")
                        .style("margin-right", -margin.right + "px")
                        .append("g")
                        .attr("transform", "translate(" + margin.left + "," + margin.top + ")")
                        .style("shape-rendering", "crispEdges");

                    var grandparent = svg.append("g")
                        .attr("class", "grandparent");

                    grandparent.append("rect")
                        .attr("y", -margin.top)
                        .attr("width", width)
                        .attr("height", margin.top);

                    grandparent.append("text")
                        .attr("x", 6)
                        .attr("y", 6 - margin.top)
                        .attr("dy", "1em");

                    if (data instanceof Array) {
                        root = {key: rname, values: data};
                    } else {
                        root = data;
                    }

                    initialize(root);
                    accumulate(root);
                    layout(root);
                    // console.log(root);
                    display(root);

                    /*
                     document.getElementById('hprof-treemap2').addEventListener('click' , function(e) {
                     e.preventDefault();
                     console.log(e);
                     }); */


                    function initialize(root) {
                        root.x = root.y = 0;
                        root.dx = width;
                        root.dy = height;
                        root.depth = 0;
                    }

                    // Aggregate the values for internal nodes. This is normally done by the
                    // treemap layout, but not here because of our custom implementation.
                    // We also take a snapshot of the original children (_children) to avoid
                    // the children being overwritten when when layout is computed.
                    function accumulate(d) {
                        return (d._children = d.values)
                            ? d.value = d.values.reduce(function (p, v) {
                                return p + accumulate(v);
                            }, 0)
                            : d.value;
                    }

                    // Compute the treemap layout recursively such that each group of siblings
                    // uses the same size (1×1) rather than the dimensions of the parent cell.
                    // This optimizes the layout for the current zoom state. Note that a wrapper
                    // object is created for the parent node for each group of siblings so that
                    // the parent’s dimensions are not discarded as we recurse. Since each group
                    // of sibling was laid out in 1×1, we must rescale to fit using absolute
                    // coordinates. This lets us use a viewport to zoom.
                    function layout(d) {
                        if (d._children) {
                            treemap.nodes({_children: d._children});
                            d._children.forEach(function (c) {
                                c.x = d.x + c.x * d.dx;
                                c.y = d.y + c.y * d.dy;
                                c.dx *= d.dx;
                                c.dy *= d.dy;
                                c.parent = d;
                                layout(c);
                            });
                        }
                    }

                    function display(d) {
                        grandparent
                            .datum(d.parent)
                            .on("click", transition)
                            .select("text")
                            .text(name(d));

                        var g1 = svg.insert("g", ".grandparent")
                            .datum(d)
                            .attr("class", "depth");

                        var g = g1.selectAll("g")
                            .data(d._children)
                            .enter().append("g");

                        g.filter(function (d) {
                            return d._children;
                        })
                            .classed("children", true)
                            .on("click", transition);

                        var children = g.selectAll(".child")
                            .data(function (d) {
                                return d._children || [d];
                            })
                            .enter().append("g");

                        children.append("rect")
                            .attr("class", "child")
                            .call(rect)
                            .append("text")
                            .text(function (d) {
                                return (d.name || d.key) + " (" + formatNumber(d.value) + ")";
                            });
                        children.append("text")
                            .attr("class", "ctext")
                            .text(function (d) {
                                return (d.name || d.key);
                            })
                            .call(text2);

                        g.append("rect")
                            .attr("class", "parent")
                            // .style("fill-opacity", 0)
                            .call(rect)
                        // .transition().duration(468).style("fill-opacity", 0.8)
                        ;

                        var t = g.append("text")
                            .attr("class", "ptext")
                            .attr("dy", "1em")
                            .style("font-size", "26px"); // start with max fontsize

                        //.style("font-size", (d) => Math.min(26, (x(d.x + d.dx) - x(d.x))/d.key.length*1.5)+'px')
                        //   .style("top", (d) => (26, d.dx/200)+'px')
                        ;

                        t.append("tspan")
                            .text(function (d) {
                                return (d.name || d.key);
                            });
                        t.append("tspan")
                            .attr("dy", "1.0em")
                            .text(function (d) {
                                return formatNumber(d.value);
                            });
                        t.call(text);

                        g.selectAll("rect")
                            .style("fill", function (d) {
                                return color(d.key.replace(/s$/, '')); // the colormaping is nicer without the s
                            });

                        function transition(d) {
                            if (transitioning || !d) return;
                            transitioning = true;

                            var g2 = display(d),
                                t1 = g1.transition().duration(468),
                                t2 = g2.transition().duration(468);

                            // Update the domain only after entering new elements.
                            x.domain([d.x, d.x + d.dx]);
                            y.domain([d.y, d.y + d.dy]);

                            // Enable anti-aliasing during the transition.
                            svg.style("shape-rendering", null);

                            // Draw child nodes on top of parent nodes.
                            svg.selectAll(".depth").sort(function (a, b) {
                                return a.depth - b.depth;
                            });

                            // Fade-in entering text.
                            g2.selectAll("text").style("fill-opacity", 0);

                            // t1.selectAll("*", 0.0);

                            // Transition to the new view.
                            t1.selectAll(".ptext").call(text).style("fill-opacity", 0);
                            t1.selectAll(".ctext").call(text2).style("fill-opacity", 0);
                            t2.selectAll(".ptext").call(text).style("fill-opacity", 1);
                            t2.selectAll(".ctext").call(text2).style("fill-opacity", 1);
                            t1.selectAll("rect").call(rect);
                            t2.selectAll("rect").call(rect);

                            //g2.selectAll(".parent").style("fill-opacity", 0.0).transition().duration(468).style("fill-opacity", 0.8);

                            // Remove the old node when the transition is finished.
                            t1.remove().each("end", function () {
                                svg.style("shape-rendering", "crispEdges");
                                transitioning = false;
                                //console.log('end')
                            });
                        }

                        return g;
                    }

                    function text(text) {
                        text.selectAll("tspan")
                            .attr("x", function (d) {
                                return x(d.x) + 6;
                            });
                        text.attr("x", function (d) {
                            return x(d.x) + 6;
                        })
                            .attr("y", function (d) {
                                return y(d.y) + 6;
                            })
                            .style("font-size", function (d) {
                                return Math.max(4, Math.min(26, (x(d.x + d.dx) - x(d.x)) / this.getComputedTextLength() * 4.0)) + 'px';
                            }).select("tspan:last-child").attr("dy", Math.random() / 100 + 0.8 + "em").attr("dx", Math.random() / 100 + 0.1 + "em"); // chrome bug fix
                        //.style("opacity", function (d) {
                        //    return this.getComputedTextLength() < x(d.x + d.dx) - x(d.x) ? 1 : 0;
                        //});
                    }

                    function text2(text) {
                        text.attr("x", function (d) {
                            return x(d.x + d.dx) - this.getComputedTextLength() - 6;
                        })
                            .attr("y", function (d) {
                                return y(d.y + d.dy) - 6;
                            })
                            .style("opacity", function (d) {
                                return this.getComputedTextLength() < x(d.x + d.dx) - x(d.x) ? 1 : 0;
                            });
                    }

                    function rect(rect) {
                        rect.attr("x", function (d) {
                            return x(d.x);
                        })
                            .attr("y", function (d) {
                                return y(d.y);
                            })
                            .attr("width", function (d) {
                                return x(d.x + d.dx) - x(d.x);
                            })
                            .attr("height", function (d) {
                                return y(d.y + d.dy) - y(d.y);
                            })
                            .attr('data-is-rect', "1");
                    }

                    function name(d) {
                        return d.parent
                            ? name(d.parent) + " / " + (d.name || d.key) + "(" + formatNumber(d.value) + ")"
                            : (d.name || d.key) + "(" + formatNumber(d.value) + ")";
                    }
                }


                let data = [];
                //noinspection JSAnnotator
                data = <?php echo json_encode( array_values( $data2 ) ); ?>;

                let sum = 0;
                data.forEach((d) => sum += d.value);
                data.forEach((d) => {
                                        d.value = d.value * 100 / sum;
                });
                console.log('treemap coverage is ', sum, 'ms');

                const ndata = d3.nest().key(function (d) {
                    return d.region;
                }).key(function (d) {
                    return d.subregion;
                }).entries(data);

                setTimeout(function () {
                    main({
                        title: "WP",
                        margin: {top: 28, right: 0, bottom: 0, left: 0},
                        rootname: "WP"
                    }, {key: "WP", name: "WP", values: ndata});
                }, 50);

            });


        </script>
		<?php
	}
}