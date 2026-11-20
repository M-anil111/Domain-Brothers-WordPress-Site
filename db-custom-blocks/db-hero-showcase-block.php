<?php
/**
 * Home hero: "Available right now" row of real listings and a marker sweep on the headline.
 *
 * Hooks the filter db_hp_hero_showcase, applied inside db_hp_hero_html() (db-custom-blocks.php).
 * Names come from the cached domain index; one per extension, short names at sensible prices
 * first. CSS-only motion, switched off under reduced motion. Hidden when fewer than two names
 * qualify, so the hero never shows a half-empty row.
 *
 * @package DB_Custom_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_hh_pick' ) ) {
	/**
	 * Up to $n showcase names, at most one per extension. Pure: tested offline.
	 *
	 * Eligible: a plain domain name with a price from $500 to $50,000. Within an extension the best
	 * name is the shortest, then the one nearest $5,000, then alphabetical. Extensions with more
	 * names come first, so the row reflects what the inventory mostly is.
	 *
	 * @param array<int, array{n:string,p:float|int|string,u:string}> $rows Rows from db_domain_index().
	 * @param int                                                       $n    How many to return.
	 * @return array<int, array{n:string,label:string,ext:string,p:float,u:string}>
	 */
	function db_hh_pick( array $rows, $n = 3 ) {
		$by = array();
		foreach ( $rows as $r ) {
			$name  = strtolower( trim( (string) ( $r['n'] ?? '' ) ) );
			$price = (float) ( $r['p'] ?? 0 );
			$url   = (string) ( $r['u'] ?? '' );
			if ( '' === $url || $price < 500 || $price > 50000 || ! preg_match( '/^([a-z0-9][a-z0-9\-]{0,60})(\.[a-z0-9\-]{2,24})$/', $name, $m ) ) {
				continue;
			}
			$by[ $m[2] ][] = array( 'n' => $name, 'label' => $m[1], 'ext' => $m[2], 'p' => $price, 'u' => $url );
		}
		$best = array();
		foreach ( $by as $ext => $list ) {
			usort( $list, function ( $a, $b ) {
				return ( strlen( $a['label'] ) <=> strlen( $b['label'] ) )
					?: ( abs( $a['p'] - 5000 ) <=> abs( $b['p'] - 5000 ) )
					?: strcmp( $a['n'], $b['n'] );
			} );
			$best[] = array( 'count' => count( $list ), 'row' => $list[0] );
		}
		usort( $best, function ( $a, $b ) {
			return ( $b['count'] <=> $a['count'] ) ?: strcmp( $a['row']['n'], $b['row']['n'] );
		} );
		return array_slice( array_column( $best, 'row' ), 0, max( 0, (int) $n ) );
	}
}

if ( ! function_exists( 'db_hh_html' ) ) {
	/**
	 * The row's markup. Pure given its inputs; $monthly turns a price into "$X" or null.
	 *
	 * @param array<int, array{n:string,label:string,ext:string,p:float,u:string}> $pick    From db_hh_pick().
	 * @param callable|null                                                          $monthly Price to monthly figure.
	 */
	function db_hh_html( array $pick, $monthly = null ) {
		if ( count( $pick ) < 2 ) {
			return '';
		}
		$e = function ( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); };
		$h = '<style>.db-hp-h1 .db-hp-mark{background:linear-gradient(transparent 62%,rgba(181,230,29,.65) 62%) no-repeat 0 100%/0 100%;animation:db-hh-sweep .9s cubic-bezier(.2,.7,.2,1) .5s forwards;padding:0 .08em}'
			. '.db-hh{max-width:880px;margin:26px auto 0;text-align:left}.db-hh-title{display:flex;align-items:center;gap:8px;margin:0 0 10px;font-size:13px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#4b5563}'
			. '.db-hh-dot{width:9px;height:9px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 0 rgba(22,163,74,.55);animation:db-hh-pulse 2s infinite}'
			. '.db-hh-grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}'
			. '.db-hh-card{display:flex;flex-direction:column;gap:2px;padding:16px 18px;background:#fff;border:1px solid #dfe5e1;border-radius:16px;text-decoration:none;color:#0a1628;box-shadow:0 6px 20px rgba(10,22,40,.06);animation:db-hh-rise .7s cubic-bezier(.2,.7,.2,1) both;transition:transform .2s,box-shadow .2s,border-color .2s}'
			. '.db-hh-grid li:nth-child(2) .db-hh-card{animation-delay:.1s}.db-hh-grid li:nth-child(3) .db-hh-card{animation-delay:.2s}'
			. '.db-hh-card:hover,.db-hh-card:focus-visible{transform:translateY(-3px);box-shadow:0 12px 28px rgba(10,22,40,.12);border-color:#0a1628}'
			. '.db-hh-name{font-size:22px;font-weight:800;letter-spacing:-.01em;overflow-wrap:anywhere}.db-hh-name em{font-style:normal;color:#0b6b36}'
			. '.db-hh-price{font-size:15px;font-weight:700}.db-hh-mo{font-size:13px;color:#4b5563}'
			. '@media(max-width:560px){.db-hh-grid{grid-template-columns:1fr}.db-hh-card{flex-direction:row;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:2px 12px}.db-hh-name{font-size:19px}}'
			. '@keyframes db-hh-rise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}@keyframes db-hh-sweep{to{background-size:100% 100%}}'
			. '@keyframes db-hh-pulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.5)}70%{box-shadow:0 0 0 9px rgba(22,163,74,0)}100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}}'
			. '@media(prefers-reduced-motion:reduce){.db-hp-h1 .db-hp-mark{animation:none;background-size:100% 100%}.db-hh-card,.db-hh-dot{animation:none!important}.db-hh-card{transition:none}}</style>';
		$h .= '<div class="db-hh" role="region" aria-labelledby="db-hh-h"><p class="db-hh-title" id="db-hh-h"><span class="db-hh-dot" aria-hidden="true"></span>Available right now</p><ul class="db-hh-grid">';
		foreach ( $pick as $r ) {
			$mo  = is_callable( $monthly ) ? $monthly( $r['p'] ) : null;
			$h  .= '<li><a class="db-hh-card" href="' . $e( $r['u'] ) . '"><span class="db-hh-name">' . $e( $r['label'] ) . '<em>' . $e( $r['ext'] ) . '</em></span>'
				. '<span class="db-hh-price">$' . number_format( $r['p'] ) . '</span>'
				. ( $mo ? '<span class="db-hh-mo">or from ' . $e( $mo ) . '/mo</span>' : '' ) . '</a></li>';
		}
		return $h . '</ul></div>';
	}
}

add_filter( 'db_hp_hero_showcase', function ( $html ) {
	$rows = function_exists( 'db_domain_index' ) ? (array) db_domain_index() : array();
	$mo   = function_exists( 'db_dd_monthly' ) && function_exists( 'db_plan_total_cents' ) ? 'db_dd_monthly' : null;
	return $html . db_hh_html( db_hh_pick( $rows, 3 ), $mo );
} );
