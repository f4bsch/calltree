<?php

namespace WPHookProfiler;


class IssueDisplay {

	/**
	 * @param Issue[] $issues
	 */
	static function listIssuesAsTable( $issues ) {
		?>
        <style>
            table.hprof-issues {

            }

            table.hprof-issues thead th {

            }

            table.hprof-issues tbody tr {
                background-color: yellow;
                padding-bottom: 1em;
            }
        </style>

        <table class="hprof-issues">
            <thead>
            <th>Component</th>
            <th>Subject</th>
            <th>Description</th>
            <th>How to solve?</th>
            <th>Dev notice</th>
            </thead>
            <tbody>
			<?php
			foreach ( $issues as $issue ) {
				?>
				<?php
				echo "<tr><td>{$issue->getComponent()}</td><td>{$issue->getSubject()}</td>" .
				     "<td>{$issue->getDescription()}</td><td>{$issue->getHowToSolve()}</td>" .
				     "<td>{$issue->getDevNote()}</td></tr>";
			}
			?>
            </tbody>
        </table>

		<?php
	}

	static function css() {
	    ?>
        <style>

            div.hprof-issues {

            }

            div.hprof-issues div.issue {
                background-color: papayawhip;
                padding: 0.5em 2em 1em 2em;
                max-width: 60em;
                margin: 1.5em auto;
                color: black;
            }

            div.hprof-issues div.issue a {
                text-decoration: underline !important;
            }

            div.hprof-issues h4 {
                font-size: 14px;
                padding-top: 0.5em;
                margin-top: 0;
            }

            div.hprof-issues h4 .exc {
                font-size: 20px;
            }

            div.hprof-issues h4 .subj {
                font-family: "Source Code Pro", monospace;
            }

            div.hprof-issues h4 .slowdown {
                float: right;
                margin: 1em;
                clear: right;
            }

            div.hprof-issues p {
                clear: both;
            }

            div.hprof-issues p b {

            }

            div.hprof-issues code {
                background-color: rgba(255, 255, 255, 0.5);
                padding: 0 0.2em;
            }

            p.hprof-perf-indices {
                text-align: center;
            }

            p.hprof-perf-indices  span {
                font-size: 120%;
                font-weight: 600;
            }


        </style><?php
    }

	/**
	 * @param Issue[] $issues
	 */
	static function listIssues( $issues, $wpIncTime, $totalPageTime ) {

	    self::css();

		self::perfIndex();

		echo "<h4 class='hprof-section'>Issues</h4>";

		// sort all issues
		usort( $issues, function ( $a, $b ) {
			return $a->getSlowDownPerRequest() === $b->getSlowDownPerRequest() ? 0
				: ( ( $a->getSlowDownPerRequest() < $b->getSlowDownPerRequest() ) ? 1 : - 1 );
		} );

		$grouped        = [];
		$groupedHasAgg  = [];

		$groupSDSum     = [];
		$issuesFiltered = [];

		$sdTotal = 0;
		$sdDrop  = 0;

		foreach ( $issues as $issue ) {
			$cat = $issue->getCategoryName();
			if ( ! isset( $grouped[ $cat ] ) ) {
				$grouped[ $cat ]     = [];
				$groupSDSum [ $cat ] = 0;
			}
			if ( count( $grouped[ $cat ] ) < 5 && $issue->getSlowDownPerRequest() > ( $groupSDSum [ $cat ] / 10 )
			     && $issue->getSlowDownPerRequest() > ( $totalPageTime * 0.02 ) /* filter 2% issues*/
			) {
			    // dont add agg issues to non-empty groups
			    if($issue->getTimeIsAggregated() && count($grouped[$cat]) > 0)
			        continue;

				$grouped[ $cat ][]   = $issue;
				$groupSDSum [ $cat ] += $issue->getSlowDownPerRequest();
				$issuesFiltered[]    = $issue;
				$sdTotal             += $issue->getSlowDownPerRequest() - 1;

				// remove any agg issue
				if(isset($groupedHasAgg[$cat]) && $issuesFiltered[$groupedHasAgg[$cat]]->getTimeIsAggregated()) {
					$agg = $issuesFiltered[$groupedHasAgg[$cat]];
					$groupSDSum [ $cat ] -= $agg->getSlowDownPerRequest();
					$sdTotal -= $agg->getSlowDownPerRequest();
					unset($issuesFiltered[$groupedHasAgg[$cat]]);
					unset($groupedHasAgg[$cat]);
                }

                // if its agg, store the index to maybe remove it later!
                if($issue->getTimeIsAggregated()) {
				    end($issuesFiltered);
	                $groupedHasAgg[$cat] = key($issuesFiltered);
                }

			} else {
				$sdDrop += $issue->getSlowDownPerRequest() - 1;
			}

			if( count($issuesFiltered) >= 4)
			    break;
		}


		$n     = count( $issuesFiltered );
		$nDrop = count( $issues ) - $n;

		if($sdTotal > $totalPageTime)
		    $sdTotal = $totalPageTime;

		$perc = ( round( $sdTotal / $totalPageTime * 20 ) * 5 ) . '%';

		$sdTotal = round( $sdTotal / 1000, 1 );
		$sdDrop  = round( $sdDrop / 1000, 1 );

		if ( $n == 0 || $sdTotal < 0.1) {
			echo "<h4 style='    padding: 1em 1em 0em 1em; text-align: center'>&#9989; Calltree did not find any performance issues during this page load </h4>";



			return;
		}

		echo "<h4 style='    padding: 1em 1em 0em 1em;'>" . ( $n == 1 ? " This issue slows" : "These  $n  issues slow" ) . " down by $sdTotal seconds ($perc):</h4>";
		?>
        <div class="hprof-issues">

			<?php
			foreach ( $issuesFiltered as $issue ) {
				?>
				<?php
				$subj = $issue->getComponent(); // . '/' . $issue->getSubject();
				$sd   = ( $issue->getSlowDownPerRequest() < 10 ? $issue->getSlowDownPerRequest() : floor( $issue->getSlowDownPerRequest() / 10 ) * 10 ) . ' milliseconds';
				echo "<div class='issue'>" .
				     "<div style='float: right;'>{$issue->getCategoryName()} &bull; {$issue->getSubject()}</div>" .
				     "<h4><span class='exc'>❗</span> Subject: <span class='subj'>$subj</span>" .
				     " <span class='slowdown'>{$sd} slowdown</span></h4>" .
				     "<p>{$issue->getDescription()}</p><p><b>How to solve?</b> {$issue->getHowToSolve()}</p>" .
				     "<p>Note for the dev: <i>{$issue->getDevNote()}</i></p>" .

				     "</div>";
			}
			?>

        </div>


		<?php

		echo( $nDrop > 0 && $sdDrop > 0.2 ? "<p style='text-align: right'><i>there might be $nDrop more with $sdDrop seconds slowdown which can show up after you fixed the superior</i></p>" : "" );

	}

	static function  perfIndex() {
		$srvPerfI = IssueDetector::getServerPerformanceIndex($outMs);
		//$sitePerfI = IssueDetector::getSitePerformanceIndex($totalPageTime);

        echo "<h4 class='hprof-section'>Performance</h4>";
		if($srvPerfI > 0)
			echo "<p class='hprof-perf-indices'>Your Server Performance Index is <span>$srvPerfI / 100</span> (median WP includes time is $outMs ms)</p>";
		else
			echo "<p class='hprof-perf-indices'>The profiler collects data for a general Server Performance Index. Calibration takes about 100 requests, please check later for results.</p>";
		//, your Site Performance Index is <span>$sitePerfI</span></p>";
	}
}