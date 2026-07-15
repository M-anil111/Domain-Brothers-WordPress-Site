<?php
/**
 * DB Analytics Block — Domain Brothers CRM analytics dashboard
 *
 * Adds "Analytics" under the Domain Brothers admin menu:
 *   - Stat tiles: total leads, new this week, pipeline value, won value
 *   - Leads-over-time area chart (last 30 days, SVG, no JS libraries)
 *   - Pipeline funnel by status (horizontal bars, ordinal ramp)
 *   - Lead sources breakdown
 *
 * Charts are server-rendered SVG with a lightweight hover layer.
 * Requires the wp_db_leads table created by db-crm-block.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ─── Data queries ─────────────────────────────────────────────────────────── */

if ( ! function_exists( 'db_analytics_get_stats' ) ) {
	/**
	 * One round trip per dataset; everything the dashboard needs.
	 *
	 * @return array{
	 *   total:int, new_week:int, pipeline:float, won:float,
	 *   by_status:array<string,int>, by_source:array<string,int>,
	 *   daily:array<string,int>
	 * }
	 */
	function db_analytics_get_stats() {
		global $wpdb;
		$table = $wpdb->prefix . 'db_leads';

		// Single GROUP BY per dimension instead of per-status COUNT queries.
		$by_status = array( 'new' => 0, 'contacted' => 0, 'negotiating' => 0, 'won' => 0, 'lost' => 0 );
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n, SUM(offer_amount) AS amt FROM {$table} GROUP BY status" );
		$pipeline = 0.0;
		$won      = 0.0;
		$total    = 0;
		foreach ( (array) $rows as $r ) {
			$by_status[ $r->status ] = (int) $r->n;
			$total                  += (int) $r->n;
			if ( 'won' === $r->status ) {
				$won += (float) $r->amt;
			} elseif ( 'lost' !== $r->status ) {
				$pipeline += (float) $r->amt;
			}
		}

		$new_week = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
		);

		$by_source = array();
		$rows = $wpdb->get_results( "SELECT source, COUNT(*) AS n FROM {$table} GROUP BY source ORDER BY n DESC" );
		foreach ( (array) $rows as $r ) {
			$key = $r->source ? $r->source : 'unknown';
			$by_source[ $key ] = (int) $r->n;
		}

		// Last 30 days, zero-filled so the line doesn't skip quiet days.
		$daily = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$daily[ gmdate( 'Y-m-d', strtotime( "-{$i} days" ) ) ] = 0;
		}
		$rows = $wpdb->get_results(
			"SELECT DATE(created_at) AS d, COUNT(*) AS n FROM {$table}
			 WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
			 GROUP BY DATE(created_at)"
		);
		foreach ( (array) $rows as $r ) {
			if ( isset( $daily[ $r->d ] ) ) {
				$daily[ $r->d ] = (int) $r->n;
			}
		}

		return compact( 'total', 'new_week', 'pipeline', 'won', 'by_status', 'by_source', 'daily' );
	}
}

/* ─── Menu registration ────────────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
	add_submenu_page(
		'db-crm-leads',
		'Analytics',
		'Analytics',
		'manage_options',
		'db-crm-analytics',
		'db_analytics_render_page'
	);
}, 20 );

/* ─── Page renderer ────────────────────────────────────────────────────────── */

if ( ! function_exists( 'db_analytics_render_page' ) ) {
	function db_analytics_render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		$s = db_analytics_get_stats();

		$fmt_money = function ( $v ) {
			return '$' . number_format( (float) $v, 0 );
		};

		/* Leads-over-time: build SVG path points. */
		$daily  = $s['daily'];
		$vals   = array_values( $daily );
		$days   = array_keys( $daily );
		$max    = max( 1, max( $vals ) );
		$w      = 920;
		$h      = 220;
		$pad_l  = 34;
		$pad_b  = 26;
		$pad_t  = 12;
		$plot_w = $w - $pad_l - 10;
		$plot_h = $h - $pad_t - $pad_b;
		$step   = $plot_w / max( 1, count( $vals ) - 1 );

		$pts = array();
		foreach ( $vals as $i => $v ) {
			$x     = $pad_l + $i * $step;
			$y     = $pad_t + $plot_h - ( $v / $max ) * $plot_h;
			$pts[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}
		$line_path = implode( ' ', $pts );
		$area_path = $pad_l . ',' . ( $pad_t + $plot_h ) . ' ' . $line_path . ' ' . ( $pad_l + ( count( $vals ) - 1 ) * $step ) . ',' . ( $pad_t + $plot_h );

		/* Funnel: ordinal blue ramp for stages; won/lost use status colors. */
		$stage_colors = array(
			'new'         => '#86b6ef',
			'contacted'   => '#5598e7',
			'negotiating' => '#2a78d6',
			'won'         => '#0ca30c',
			'lost'        => '#c3c2b7',
		);
		$stage_labels = array(
			'new'         => 'New',
			'contacted'   => 'Contacted',
			'negotiating' => 'Negotiating',
			'won'         => 'Won',
			'lost'        => 'Lost',
		);
		$funnel_max = max( 1, max( $s['by_status'] ) );

		$source_labels = array(
			'offer_form' => 'Offer form',
			'direct'     => 'Added manually',
			'unknown'    => 'Unknown',
		);
		$source_max = max( 1, $s['by_source'] ? max( $s['by_source'] ) : 1 );
		?>
		<div class="wrap db-anx">
			<h1 class="db-anx-title">Domain Brothers — Analytics</h1>

			<div class="db-anx-tiles">
				<div class="db-anx-tile">
					<span class="db-anx-tile-label">Total leads</span>
					<span class="db-anx-tile-value"><?php echo (int) $s['total']; ?></span>
				</div>
				<div class="db-anx-tile">
					<span class="db-anx-tile-label">New this week</span>
					<span class="db-anx-tile-value"><?php echo (int) $s['new_week']; ?></span>
				</div>
				<div class="db-anx-tile">
					<span class="db-anx-tile-label">Pipeline value</span>
					<span class="db-anx-tile-value"><?php echo esc_html( $fmt_money( $s['pipeline'] ) ); ?></span>
					<span class="db-anx-tile-sub">open offers (not won/lost)</span>
				</div>
				<div class="db-anx-tile db-anx-tile--won">
					<span class="db-anx-tile-label">Won value</span>
					<span class="db-anx-tile-value"><?php echo esc_html( $fmt_money( $s['won'] ) ); ?></span>
				</div>
			</div>

			<div class="db-anx-card">
				<h2 class="db-anx-card-title">Leads — last 30 days</h2>
				<?php if ( 0 === array_sum( $vals ) ) : ?>
					<p class="db-anx-empty">No leads in the last 30 days yet. New offer-form submissions will appear here.</p>
				<?php else : ?>
				<div class="db-anx-chart-wrap">
					<svg class="db-anx-line" viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" role="img" aria-label="Leads per day, last 30 days">
						<?php
						/* horizontal gridlines at 0 / half / max */
						foreach ( array( 0, 0.5, 1 ) as $g ) :
							$gy = $pad_t + $plot_h - $g * $plot_h;
							$gv = round( $g * $max );
							?>
							<line x1="<?php echo (int) $pad_l; ?>" y1="<?php echo esc_attr( round( $gy, 1 ) ); ?>" x2="<?php echo (int) ( $pad_l + $plot_w ); ?>" y2="<?php echo esc_attr( round( $gy, 1 ) ); ?>" class="db-anx-grid"/>
							<text x="<?php echo (int) ( $pad_l - 8 ); ?>" y="<?php echo esc_attr( round( $gy + 4, 1 ) ); ?>" class="db-anx-tick" text-anchor="end"><?php echo (int) $gv; ?></text>
						<?php endforeach; ?>

						<polygon points="<?php echo esc_attr( $area_path ); ?>" class="db-anx-area"/>
						<polyline points="<?php echo esc_attr( $line_path ); ?>" class="db-anx-stroke"/>

						<?php
						/* endpoint emphasis + hover targets */
						foreach ( $vals as $i => $v ) :
							$x = $pad_l + $i * $step;
							$y = $pad_t + $plot_h - ( $v / $max ) * $plot_h;
							$d = date_i18n( 'M j', strtotime( $days[ $i ] ) );
							?>
							<g class="db-anx-pt">
								<rect x="<?php echo esc_attr( round( $x - $step / 2, 1 ) ); ?>" y="0" width="<?php echo esc_attr( round( $step, 1 ) ); ?>" height="<?php echo (int) $h; ?>" fill="transparent">
									<title><?php echo esc_html( $d . ': ' . $v . ( 1 === $v ? ' lead' : ' leads' ) ); ?></title>
								</rect>
								<circle cx="<?php echo esc_attr( round( $x, 1 ) ); ?>" cy="<?php echo esc_attr( round( $y, 1 ) ); ?>" r="<?php echo ( count( $vals ) - 1 === $i ) ? 4 : 3; ?>" class="db-anx-dot<?php echo ( count( $vals ) - 1 === $i ) ? ' db-anx-dot--end' : ''; ?>"/>
							</g>
						<?php endforeach; ?>

						<?php
						/* x labels: first, middle, last */
						foreach ( array( 0, 14, 29 ) as $i ) :
							if ( ! isset( $days[ $i ] ) ) { continue; }
							$x = $pad_l + $i * $step;
							?>
							<text x="<?php echo esc_attr( round( $x, 1 ) ); ?>" y="<?php echo (int) ( $h - 6 ); ?>" class="db-anx-tick" text-anchor="middle"><?php echo esc_html( date_i18n( 'M j', strtotime( $days[ $i ] ) ) ); ?></text>
						<?php endforeach; ?>
					</svg>
				</div>
				<?php endif; ?>
			</div>

			<div class="db-anx-row">
				<div class="db-anx-card">
					<h2 class="db-anx-card-title">Pipeline by stage</h2>
					<div class="db-anx-bars">
						<?php foreach ( $stage_labels as $key => $label ) :
							$n   = isset( $s['by_status'][ $key ] ) ? (int) $s['by_status'][ $key ] : 0;
							$pct = round( ( $n / $funnel_max ) * 100 );
							?>
							<div class="db-anx-bar-row" title="<?php echo esc_attr( $label . ': ' . $n ); ?>">
								<span class="db-anx-bar-label"><?php echo esc_html( $label ); ?></span>
								<span class="db-anx-bar-track">
									<span class="db-anx-bar-fill" style="width:<?php echo (int) max( $pct, $n > 0 ? 2 : 0 ); ?>%;background:<?php echo esc_attr( $stage_colors[ $key ] ); ?>"></span>
								</span>
								<span class="db-anx-bar-n"><?php echo (int) $n; ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="db-anx-card">
					<h2 class="db-anx-card-title">Lead sources</h2>
					<?php if ( empty( $s['by_source'] ) ) : ?>
						<p class="db-anx-empty">No leads yet.</p>
					<?php else : ?>
					<div class="db-anx-bars">
						<?php foreach ( $s['by_source'] as $key => $n ) :
							$label = isset( $source_labels[ $key ] ) ? $source_labels[ $key ] : ucfirst( str_replace( '_', ' ', $key ) );
							$pct   = round( ( $n / $source_max ) * 100 );
							?>
							<div class="db-anx-bar-row" title="<?php echo esc_attr( $label . ': ' . $n ); ?>">
								<span class="db-anx-bar-label"><?php echo esc_html( $label ); ?></span>
								<span class="db-anx-bar-track">
									<span class="db-anx-bar-fill" style="width:<?php echo (int) max( $pct, 2 ); ?>%;background:#1baf7a"></span>
								</span>
								<span class="db-anx-bar-n"><?php echo (int) $n; ?></span>
							</div>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<style>
		.db-anx { max-width: 1000px; }
		.db-anx-title { font-size: 23px; font-weight: 600; margin-bottom: 18px; }

		.db-anx-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 18px; }
		.db-anx-tile { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px 18px; display: flex; flex-direction: column; gap: 2px; }
		.db-anx-tile-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #787c82; }
		.db-anx-tile-value { font-size: 30px; font-weight: 700; color: #1d2327; line-height: 1.2; }
		.db-anx-tile-sub { font-size: 11px; color: #898781; }
		.db-anx-tile--won .db-anx-tile-value { color: #006300; }

		.db-anx-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 18px 20px; margin-bottom: 18px; }
		.db-anx-card-title { font-size: 14px; font-weight: 600; color: #1d2327; margin: 0 0 14px; }
		.db-anx-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
		@media (max-width: 900px) { .db-anx-row { grid-template-columns: 1fr; } }

		.db-anx-chart-wrap { overflow-x: auto; }
		.db-anx-line { width: 100%; height: auto; display: block; }
		.db-anx-grid { stroke: #e1e0d9; stroke-width: 1; }
		.db-anx-tick { font: 11px system-ui, -apple-system, "Segoe UI", sans-serif; fill: #898781; }
		.db-anx-area { fill: #2a78d6; opacity: .12; }
		.db-anx-stroke { fill: none; stroke: #2a78d6; stroke-width: 2; stroke-linejoin: round; }
		.db-anx-dot { fill: #fff; stroke: #2a78d6; stroke-width: 2; opacity: 0; transition: opacity .12s; }
		.db-anx-dot--end { opacity: 1; fill: #2a78d6; }
		.db-anx-pt:hover .db-anx-dot { opacity: 1; }
		@media (prefers-reduced-motion: reduce) { .db-anx-dot { transition: none; } }

		.db-anx-bars { display: flex; flex-direction: column; gap: 10px; }
		.db-anx-bar-row { display: grid; grid-template-columns: 96px 1fr 36px; align-items: center; gap: 10px; }
		.db-anx-bar-label { font-size: 12.5px; color: #52514e; }
		.db-anx-bar-track { background: #f0efec; border-radius: 4px; height: 22px; overflow: hidden; }
		.db-anx-bar-fill { display: block; height: 100%; border-radius: 4px 0 0 4px; min-width: 0; }
		.db-anx-bar-n { font-size: 12.5px; font-weight: 600; color: #1d2327; text-align: right; font-variant-numeric: tabular-nums; }
		.db-anx-empty { color: #787c82; font-size: 13px; margin: 4px 0; }
		</style>
		<?php
	}
}
