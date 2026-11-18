<?php
/**
 * Focus-keyword sections.
 *
 * Each indexable page targets one researched search term (SE Ranking, US
 * database: the term with the best mix of monthly searches and low difficulty
 * that the page can honestly answer). This block gives the page what a searcher
 * of that term expects to find, and what Rank Math measures:
 *
 *   - a guide section whose heading and copy use the term,
 *   - a four-step strip with illustrations (alt text names the term),
 *   - one outbound link to an authoritative source on the subject,
 *   - optionally a one-line introduction under the page title.
 *
 * The copy lives in focus/*.php (one array per group of pages), keyed by page
 * slug; '_front' is the home page. Nothing is stored in the database, so the
 * sections travel with the plugin and cannot be lost by a content import.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_focus_defs' ) ) {
	/**
	 * All focus definitions, keyed by page slug.
	 *
	 * @return array<string,array>
	 */
	function db_focus_defs() {
		static $defs = null;
		if ( null === $defs ) {
			$defs = array();
			foreach ( array( 'listings', 'domain-services', 'digital-services', 'company' ) as $group ) {
				$file = __DIR__ . '/focus/' . $group . '.php';
				if ( is_readable( $file ) ) {
					$part = include $file;
					if ( is_array( $part ) ) {
						$defs += $part;
					}
				}
			}
			// Extra copy appended to existing sections, and short stand-alone sections.
			foreach ( (array) glob( __DIR__ . '/focus/more*.php' ) as $more_file ) {
				$more = include $more_file;
				if ( ! is_array( $more ) ) {
					continue;
				}
				foreach ( (array) ( $more['append'] ?? array() ) as $slug => $blocks ) {
					if ( isset( $defs[ $slug ]['body'] ) && is_array( $defs[ $slug ]['body'] ) ) {
						$defs[ $slug ]['body'] = array_merge( $defs[ $slug ]['body'], (array) $blocks );
					}
				}
				$defs += (array) ( $more['defs'] ?? array() );
			}
			foreach ( $defs as $slug => $def ) {
				$defs[ $slug ]['_slug'] = $slug;
			}
			$defs = (array) apply_filters( 'db_focus_defs', $defs );
		}
		return $defs;
	}
}

if ( ! function_exists( 'db_focus_steps' ) ) {
	/**
	 * The four steps shown in the strip, by variant.
	 *
	 * @param string $variant buy|broker|digital|company.
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	function db_focus_steps( $variant ) {
		$sets = array(
			'buy'     => array(
				array( 'choose', 'Choose a name', 'Open any listing to see its price, its history and its payment plans.' ),
				array( 'agree', 'Agree the price', 'Buy at the listed price or send an offer. A broker replies within one business day.' ),
				array( 'secure', 'Pay securely', 'Card payments run through Stripe, in full or on a monthly plan. Every payment is confirmed by email.' ),
				array( 'transfer', 'Receive the transfer', 'We move the name to your registrar account and stay with you until it resolves.' ),
			),
			'broker'  => array(
				array( 'choose', 'Tell us the goal', 'The name you want, or the name you own, and what a good outcome looks like.' ),
				array( 'agree', 'We do the research', 'Ownership, comparable sales and a price range, written down before anyone is contacted.' ),
				array( 'secure', 'We negotiate', 'One broker speaks for you, in confidence, and reports every reply.' ),
				array( 'transfer', 'Payment and transfer', 'Payment is confirmed first. Then the name moves to the buyer&rsquo;s registrar account, with each stage reported.' ),
			),
			'digital' => array(
				array( 'choose', 'Discovery call', 'Thirty minutes on the goal, the audience and what already exists.' ),
				array( 'agree', 'Plan and fixed quote', 'A written scope with milestones, so the cost is known before work starts.' ),
				array( 'secure', 'Build and review', 'Work is shown in short cycles. You see progress every week, not at the end.' ),
				array( 'transfer', 'Launch and support', 'We launch, measure and stay on call. The code and accounts are yours.' ),
			),
			'company' => array(
				array( 'choose', 'Ask your question', 'By form, email or phone. Say which name or service it concerns.' ),
				array( 'agree', 'Get a straight answer', 'A person replies within one business day, with a price where one applies.' ),
				array( 'secure', 'Agree the next step', 'Nothing starts and nothing is charged until you have said yes in writing.' ),
				array( 'transfer', 'We follow through', 'One contact from first message to finished transfer or delivered project.' ),
			),
		);
		return isset( $sets[ $variant ] ) ? $sets[ $variant ] : $sets['company'];
	}
}

if ( ! function_exists( 'db_focus_current' ) ) {
	/**
	 * The focus definition for the current request, or null.
	 *
	 * @return array|null
	 */
	function db_focus_current() {
		if ( is_admin() || is_feed() || is_404() ) {
			return null;
		}
		$defs = db_focus_defs();
		if ( is_front_page() ) {
			return isset( $defs['_front'] ) ? $defs['_front'] : null;
		}
		if ( is_home() && ! is_paged() ) {
			// The posts page (News) is a page, but is_page() is false on it.
			$posts_page = (int) get_option( 'page_for_posts' );
			$post       = $posts_page ? get_post( $posts_page ) : null;
			if ( $post instanceof WP_Post && isset( $defs[ $post->post_name ] ) ) {
				return $defs[ $post->post_name ];
			}
			return null;
		}
		if ( is_page() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post && isset( $defs[ $post->post_name ] ) ) {
				return $defs[ $post->post_name ];
			}
		}
		return null;
	}
}

if ( ! function_exists( 'db_focus_section_html' ) ) {
	/**
	 * The guide section for one definition.
	 *
	 * @param array  $def      Focus definition.
	 * @param string $base_url URL of the plugin's assets/steps/ folder.
	 * @return string
	 */
	function db_focus_section_html( array $def, $base_url ) {
		$kw   = isset( $def['kw'] ) ? (string) $def['kw'] : '';
		$html = '<section class="dbx" aria-labelledby="dbx-h"><div class="dbx-wrap">';
		$html .= '<p class="dbx-eb">Guide</p><h2 id="dbx-h">' . esc_html( $def['h2'] ) . '</h2>';
		foreach ( (array) $def['body'] as $block ) {
			if ( is_array( $block ) ) {
				// array( 'h3' => heading ) or a plain list of bullet strings.
				if ( isset( $block['h3'] ) ) {
					$html .= '<h3>' . esc_html( $block['h3'] ) . '</h3>';
					continue;
				}
				$html .= '<ul class="dbx-list">';
				foreach ( $block as $item ) {
					$html .= '<li>' . wp_kses( $item, array( 'strong' => array(), 'em' => array() ) ) . '</li>';
				}
				$html .= '</ul>';
				continue;
			}
			$html .= '<p>' . wp_kses( $block, array( 'strong' => array(), 'em' => array(), 'a' => array( 'href' => array() ) ) ) . '</p>';
		}
		$steps = db_focus_steps( isset( $def['steps'] ) ? $def['steps'] : 'company' );
		$title = isset( $def['steps_title'] ) ? $def['steps_title'] : 'How it works, in four steps';
		$html .= '<h3 class="dbx-steps-h">' . esc_html( $title ) . '</h3><ol class="dbx-steps">';
		foreach ( $steps as $i => $step ) {
			$alt   = ucfirst( $kw ) . ' step ' . ( $i + 1 ) . ': ' . strtolower( $step[1] );
			$html .= '<li><img src="' . esc_url( $base_url . $step[0] . '.svg' ) . '" alt="' . esc_attr( $alt ) . '" width="96" height="96" loading="lazy" decoding="async">'
				. '<strong>' . esc_html( $step[1] ) . '</strong><span>' . wp_kses( $step[2], array() ) . '</span></li>';
		}
		$html .= '</ol>';
		if ( ! empty( $def['link'] ) && 3 === count( $def['link'] ) ) {
			$html .= '<p class="dbx-more">' . esc_html( $def['link'][2] ) . ' <a href="' . esc_url( $def['link'][0] ) . '" target="_blank" rel="noopener">' . esc_html( $def['link'][1] ) . '</a>.</p>';
		}
		return $html . db_focus_read_next( isset( $def['_slug'] ) ? (string) $def['_slug'] : '' ) . '</div></section>';
	}
}

if ( ! function_exists( 'db_focus_read_next' ) ) {
	/**
	 * Cards linking to the blog guides chosen for a page in focus/read-next.php.
	 *
	 * @param string $slug Page slug ('_front' for the home page).
	 * @return string Empty when nothing is mapped or published.
	 */
	function db_focus_read_next( $slug ) {
		static $map = null;
		if ( null === $map ) {
			$file = __DIR__ . '/focus/read-next.php';
			$map  = is_readable( $file ) ? (array) include $file : array();
		}
		if ( '' === $slug || empty( $map[ $slug ] ) ) {
			return '';
		}
		$cards = '';
		foreach ( (array) $map[ $slug ] as $post_slug ) {
			$post = get_page_by_path( (string) $post_slug, OBJECT, 'post' );
			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
				continue;
			}
			$img   = get_the_post_thumbnail( $post, 'medium', array( 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '' ) );
			$title = wp_strip_all_tags( get_the_title( $post ) );
			$cards .= '<li><a href="' . esc_url( get_permalink( $post ) ) . '">' . ( $img ? '<span class="dbx-rn-img">' . $img . '</span>' : '' )
				. '<span class="dbx-rn-t">' . esc_html( $title ) . '</span><span class="dbx-rn-more">Read the guide &rarr;</span></a></li>';
		}
		return '' === $cards ? '' : '<div class="dbx-rn"><h3 class="dbx-rn-h">Read next</h3><ul>' . $cards . '</ul></div>';
	}
}

if ( ! function_exists( 'db_focus_inject' ) ) {
	/**
	 * Place the guide section (and the optional introduction line) in a page.
	 *
	 * @param string $html     Full page HTML.
	 * @param array  $def      Focus definition.
	 * @param string $base_url URL of assets/steps/.
	 * @return string
	 */
	function db_focus_inject( $html, array $def, $base_url ) {
		if ( false !== strpos( $html, 'class="dbx"' ) ) {
			return $html;
		}
		$main = stripos( $html, '<main' );
		$end  = strripos( $html, '</main>' );
		if ( false === $main || false === $end || $end < $main ) {
			return $html;
		}
		$section = db_focus_section_html( $def, $base_url );
		// Above the closing call-to-action band where the page has one.
		// Above the enquiry form on service pages: content after a form can be
		// parsed into it, and the guide reads better before the form anyway.
		$cta = strrpos( substr( $html, 0, $end ), '<section class="dbsv-form"' );
		if ( false === $cta ) {
			$cta = strrpos( substr( $html, 0, $end ), '<section class="dbp-cta"' );
		}
		$at  = ( false !== $cta && $cta > $main ) ? $cta : $end;
		$html = substr( $html, 0, $at ) . $section . substr( $html, $at );

		if ( ! empty( $def['lede'] ) && ! empty( $def['lede_replace'] ) && false !== strpos( $html, $def['lede_replace'], $main ) ) {
			// The page already has an introduction line: the keyword sentence takes its place.
			$pos  = strpos( $html, $def['lede_replace'], $main );
			$html = substr_replace( $html, esc_html( $def['lede'] ), $pos, strlen( $def['lede_replace'] ) );
		} elseif ( ! empty( $def['lede'] ) ) {
			$h1 = stripos( $html, '</h1>', $main );
			if ( false !== $h1 && $h1 < strripos( $html, '</main>' ) ) {
				$h1  += 5;
				$html = substr( $html, 0, $h1 ) . '<p class="dbx-lede">' . esc_html( $def['lede'] ) . '</p>' . substr( $html, $h1 );
			}
		}
		return $html;
	}
}

add_action( 'template_redirect', function () {
	$def = db_focus_current();
	if ( null === $def || empty( $def['h2'] ) || empty( $def['body'] ) ) {
		return;
	}
	$base = plugins_url( 'assets/steps/', __FILE__ );
	ob_start( function ( $html ) use ( $def, $base ) {
		return db_focus_inject( $html, $def, $base );
	} );
}, 3 );

add_action( 'wp_enqueue_scripts', function () {
	if ( null === db_focus_current() ) {
		return;
	}
	$css = '.dbx{padding:clamp(28px,5vw,64px) var(--m-gutter,20px);clear:both}'
		. '.dbx-wrap{max-width:1040px;margin:0 auto;padding:clamp(26px,5vw,60px);border:1px solid #e6ebee;border-radius:28px;background:linear-gradient(180deg,#f6f9fa 0%,#fff 420px)}'
		. 'body.db-ui-active .dbx-eb{display:inline-flex;align-items:center;gap:8px;margin:0 0 12px!important;padding:5px 12px;border-radius:999px;background:#e3f4f2;color:#0b6f6a!important;font-size:12.5px!important;font-weight:800;letter-spacing:.12em;text-transform:uppercase;line-height:1.4!important}'
		. 'body.db-ui-active .dbx h2{margin:0 0 18px;font-size:clamp(26px,3.3vw,38px);line-height:1.12;letter-spacing:-.025em;font-weight:800;color:var(--m-ink,#111827);text-wrap:balance;text-align:left;max-width:26ch}'
		. 'body.db-ui-active .dbx h3{margin:34px 0 12px;padding-left:14px;border-left:3px solid var(--m-accent,#0b7a75);font-size:clamp(19px,2.2vw,22px);line-height:1.25;font-weight:700;color:var(--m-ink,#111827);text-align:left}'
		. 'body.db-ui-active .dbx p,body.db-ui-active .dbx li{font-size:17px;line-height:1.7;color:var(--m-text,#374151);text-align:left}'
		. 'body.db-ui-active .dbx p{margin:0 0 16px;max-width:72ch}'
		. '.dbx-list{margin:4px 0 20px;padding:0;max-width:72ch;list-style:none}'
		. 'body.db-ui-active .dbx-list li{position:relative;margin:0 0 10px;padding:0 0 0 32px;list-style:none;background:none}'
		. '.dbx-list li::before{content:"";position:absolute;left:0;top:.32em;width:20px;height:20px;border-radius:50%;background:#e3f4f2 url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27%230b7a75%27 stroke-width=%273%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27%3E%3Cpath d=%27M5 12.5l4.5 4.5L19 7.5%27/%3E%3C/svg%3E") center/12px no-repeat}'
		. '.dbx-rn{margin:30px 0 0;padding-top:26px;border-top:1px solid var(--m-line,#e5e7eb)}'
		. 'body.db-ui-active .dbx .dbx-rn-h{margin:0 0 16px;padding:0;border:0;font-size:20px}'
		. '.dbx-rn ul{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin:0;padding:0;list-style:none}'
		. 'body.db-ui-active .dbx-rn li{margin:0;padding:0}.dbx-rn li::before{display:none}'
		. 'body.db-ui-active .dbx .dbx-rn a{display:flex;flex-direction:column;height:100%;border:1px solid var(--m-line,#e5e7eb);border-radius:18px;background:#fff;overflow:hidden;text-decoration:none!important;transition:box-shadow .2s,transform .2s}'
		. 'body.db-ui-active .dbx .dbx-rn a:hover{box-shadow:0 14px 30px -18px rgba(12,20,36,.45);transform:translateY(-2px)}'
		. '.dbx-rn-img{display:block;aspect-ratio:1200/630;background:#0b1424;overflow:hidden}.dbx-rn-img img{display:block;width:100%;height:100%;object-fit:cover}'
		. 'body.db-ui-active .dbx-rn-t{display:block;padding:14px 16px 6px;font-size:16px;line-height:1.35;font-weight:700;color:var(--m-ink,#111827)}'
		. 'body.db-ui-active .dbx-rn-more{display:block;margin-top:auto;padding:0 16px 16px;font-size:14px;font-weight:700;color:var(--m-accent,#0b7a75)}'
		. '@media (prefers-reduced-motion:reduce){body.db-ui-active .dbx .dbx-rn a{transition:none}}'
		. '@media (max-width:900px){.dbx-rn ul{grid-template-columns:minmax(0,1fr)}.dbx-rn a{flex-direction:row}.dbx-rn-img{width:38%;flex:none;aspect-ratio:auto}}'
		. '.dbx-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:16px 0 26px;padding:0;list-style:none;counter-reset:dbx}'
		. '.dbx-steps li{counter-increment:dbx;display:flex;flex-direction:column;gap:6px;padding:20px;border:1px solid var(--m-line,#e5e7eb);border-radius:18px;background:#fff}'
		. '.dbx-steps img{width:56px;height:56px;margin:0 0 8px;border-radius:14px}'
		. 'body.db-ui-active .dbx-steps strong{font-size:17px;line-height:1.3;color:var(--m-ink,#111827)}.dbx-steps strong::before{content:counter(dbx) ". ";color:var(--m-accent,#0b7a75)}'
		. 'body.db-ui-active .dbx-steps span{font-size:15px;line-height:1.55;color:var(--m-text,#374151)}'
		. 'body.db-ui-active .dbx-more{margin:0;padding-top:18px;border-top:1px solid var(--m-line,#e5e7eb);font-size:16px}'
		. 'body.db-ui-active .dbx a{color:var(--m-accent,#0b7a75);text-decoration:underline;text-underline-offset:3px}'
		. '.dbx-lede{margin:10px 0 0;max-width:62ch;font-size:clamp(16px,1.6vw,18px);line-height:1.6;color:inherit;opacity:.9}'
		// On the dark page headers the introduction line must be light.
		. 'html body .dbl-head .dbx-lede,html body .dbx-tr-hero .dbx-lede,html body .dbs2-hero .dbx-lede,html body .dbh-dark .dbx-lede{color:rgba(255,255,255,.8)!important;opacity:1}'
		. '@media (max-width:900px){.dbx-steps{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:480px){.dbx-steps{grid-template-columns:minmax(0,1fr)}}';
	if ( function_exists( 'db_external_style' ) ) {
		db_external_style( 'focus', $css );
	} else {
		wp_register_style( 'db-focus', false, array(), DB_BLOCKS_LOADED );
		wp_enqueue_style( 'db-focus' );
		wp_add_inline_style( 'db-focus', $css );
	}
}, 112 );

// Rank Math: the Content AI test needs paid credits and its module is off here,
// so it is not counted against a page. Every other test stays as shipped.
add_filter( 'rank_math/researches/tests', function ( $tests ) {
	if ( is_array( $tests ) ) {
		unset( $tests['hasContentAI'] );
	}
	return $tests;
}, 20 );
