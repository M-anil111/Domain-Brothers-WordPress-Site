<?php
/**
 * Service pages: illustration, icon strip and an inline enquiry form.
 *
 * Works on the output of db_pages_service_layout() (db-pages-block.php):
 *   - the hero's right column gets an illustration for the service (inline
 *     SVG, so no extra request and sharp at any size); the "at a glance"
 *     points move under the hero as a row of icon cards;
 *   - the closing "Contact us" band becomes a two-column enquiry section with
 *     the site's contact form (Contact Form 7 form DB_SERVICE_FORM_ID), the
 *     "I'm interested in" choice set to this service.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DB_SERVICE_FORM_ID' ) ) {
	define( 'DB_SERVICE_FORM_ID', 1958 );
}

if ( ! function_exists( 'db_svc_icon_paths' ) ) {
	/** Line icons, 24x24 grid. */
	function db_svc_icon_paths() {
		return array(
			'chat'    => '<path d="M4 6h16v11H9l-5 4z"/><path d="M8 10h8M8 13h5"/>',
			'chart'   => '<path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>',
			'shield'  => '<path d="M12 3l8 3v6c0 5-3.400 8-8 9.500C7.400 20 4 17 4 12V6z"/><path d="M9 12l2.200 2.200L15 10"/>',
			'search'  => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.600-3.600"/>',
			'handshake' => '<path d="M3 12l4-4 4 3 3-2 3 3 4-1"/><path d="M7 8l-4 4 5 5 2-1 2 2 3-2 2 1 3-4"/>',
			'swap'    => '<path d="M4 9h15M15 5l4 4-4 4M20 15H5M9 11l-4 4 4 4"/>',
			'lock'    => '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/>',
			'doc'     => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
			'at'      => '<circle cx="12" cy="12" r="4"/><path d="M16 12v1.500a2.500 2.500 0 005 0V12a9 9 0 10-3.500 7.100"/>',
			'horn'    => '<path d="M4 10v4h3l8 5V5L7 10z"/><path d="M18 9a4 4 0 010 6"/>',
			'code'    => '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M13.500 5l-3 14"/>',
			'phone'   => '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>',
			'server'  => '<rect x="4" y="4" width="16" height="6" rx="1.500"/><rect x="4" y="14" width="16" height="6" rx="1.500"/><path d="M8 7h.01M8 17h.01"/>',
			'monitor' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
			'globe'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.200 3 14.800 0 18M12 3c-3 3.200-3 14.800 0 18"/>',
			'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'users'   => '<circle cx="9" cy="9" r="3.500"/><path d="M2.500 20c.600-3.600 3.200-5.500 6.500-5.500s5.900 1.900 6.500 5.500M16 5.700a3.500 3.500 0 010 6.600M18 14.800c2 .700 3.200 2.500 3.500 5.200"/>',
			'spark'   => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.500 2.500M15.500 15.500L18 18M6 18l2.500-2.500M15.500 8.500L18 6"/>',
			'check'   => '<circle cx="12" cy="12" r="9"/><path d="M8 12.500l2.800 2.800L16 9.500"/>',
			'tag'     => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.500" cy="8.500" r="1.200"/>',
			'trend'   => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
		);
	}
}

if ( ! function_exists( 'db_svc_icon' ) ) {
	function db_svc_icon( $key, $size = 24 ) {
		$p = db_svc_icon_paths();
		$d = isset( $p[ $key ] ) ? $p[ $key ] : $p['check'];
		return '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="none" stroke="currentColor" stroke-width="1.700" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
	}
}

if ( ! function_exists( 'db_svc_config' ) ) {
	/**
	 * Per-service illustration and form settings.
	 *
	 * @return array<string,array{icon:string,chips:array,interest:string,alt:string}>
	 */
	function db_svc_config() {
		return array(
			'domain-brokerage-services'           => array( 'icon' => 'handshake', 'chips' => array( array( 'search', 'Owner found' ), array( 'chat', 'Offer accepted' ) ), 'interest' => 'Domain brokerage', 'alt' => 'A handshake between buyer and seller, with an accepted offer' ),
			'domain-appraisal-services'           => array( 'icon' => 'chart', 'chips' => array( array( 'trend', 'Comparable sales' ), array( 'doc', 'Written report' ) ), 'interest' => 'Domain appraisal', 'alt' => 'A rising value chart beside a written appraisal report' ),
			'domain-management-services'          => array( 'icon' => 'shield', 'chips' => array( array( 'clock', 'Auto-renewed' ), array( 'lock', 'Transfer locked' ) ), 'interest' => 'Domain management', 'alt' => 'A shield protecting a portfolio of domain names' ),
			'digital-marketing-services'          => array( 'icon' => 'horn', 'chips' => array( array( 'trend', '+ Qualified leads' ), array( 'search', 'Search visibility' ) ), 'interest' => 'Digital marketing', 'alt' => 'A campaign megaphone with growing traffic and search visibility' ),
			'comprehensive-digital-solutions'     => array( 'icon' => 'spark', 'chips' => array( array( 'monitor', 'Website' ), array( 'horn', 'Marketing' ) ), 'interest' => 'Website design and development', 'alt' => 'Website, software and marketing pieces fitting together' ),
			'web-hosting-maintenance-services'    => array( 'icon' => 'server', 'chips' => array( array( 'check', '99.9% uptime' ), array( 'shield', 'Backed up daily' ) ), 'interest' => 'Web hosting and maintenance', 'alt' => 'A server rack with uptime and backup status' ),
			'mobile-app-development-services'     => array( 'icon' => 'phone', 'chips' => array( array( 'check', 'iOS and Android' ), array( 'spark', 'Store ready' ) ), 'interest' => 'Mobile app development', 'alt' => 'A mobile phone showing an app ready for the stores' ),
			'custom-software-development-services' => array( 'icon' => 'code', 'chips' => array( array( 'check', 'Weekly demos' ), array( 'lock', 'You own the code' ) ), 'interest' => 'Software development', 'alt' => 'Code brackets with weekly delivery milestones' ),
			'sell-social-media-handles'           => array( 'icon' => 'at', 'chips' => array( array( 'users', 'Verified buyer' ), array( 'shield', 'Safe handover' ) ), 'interest' => 'Social media handles', 'alt' => 'A social media username being handed over safely to a verified buyer' ),
			'acquire-social-media-handles'        => array( 'icon' => 'at', 'chips' => array( array( 'search', 'Holder found' ), array( 'check', 'Handle secured' ) ), 'interest' => 'Social media handles', 'alt' => 'A social media username secured for a brand' ),
			'about-domain-brothers'               => array( 'icon' => 'globe', 'chips' => array( array( 'users', '27+ years' ), array( 'tag', '5,000+ names sold' ) ), 'interest' => 'Buying a domain', 'alt' => 'A globe of domain names with the team behind Domain Brothers' ),
		);
	}
}

if ( ! function_exists( 'db_svc_art' ) ) {
	/**
	 * Hero illustration: a large icon on layered discs with two status cards.
	 *
	 * @param array $c Config row.
	 * @return string
	 */
	function db_svc_art( array $c ) {
		$paths = db_svc_icon_paths();
		$main  = isset( $paths[ $c['icon'] ] ) ? $paths[ $c['icon'] ] : $paths['check'];
		$chip  = function ( $x, $y, $icon, $text ) use ( $paths ) {
			$w = 64 + (int) round( strlen( $text ) * 8.2 );
			return '<g transform="translate(' . (int) $x . ' ' . (int) $y . ')"><rect width="' . $w . '" height="52" rx="26" fill="#fff" stroke="#cfe3e1" stroke-width="2"/>'
				. '<circle cx="26" cy="26" r="15" fill="#e8f4f3"/><g transform="translate(16 16) scale(.84)" fill="none" stroke="#0b7a75" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ( isset( $paths[ $icon ] ) ? $paths[ $icon ] : '' ) . '</g>'
				. '<text x="50" y="32" font-family="Inter, system-ui, sans-serif" font-size="15" font-weight="700" fill="#111827">' . esc_html( $text ) . '</text></g>';
		};
		return '<figure class="dbsv-art"><svg viewBox="0 0 440 360" role="img" aria-label="' . esc_attr( $c['alt'] ) . '">'
			. '<defs><linearGradient id="dbsv-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#19b3a6"/><stop offset="1" stop-color="#0b5f5b"/></linearGradient>'
			. '<linearGradient id="dbsv-g2" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e8f4f3"/><stop offset="1" stop-color="#d6ebe9"/></linearGradient></defs>'
			. '<circle cx="220" cy="180" r="160" fill="url(#dbsv-g2)"/><circle cx="220" cy="180" r="116" fill="#cde6e3"/>'
			. '<circle cx="220" cy="180" r="78" fill="url(#dbsv-g)"/>'
			. '<g transform="translate(176 136) scale(3.700)" fill="none" stroke="#fff" stroke-width="1.500" stroke-linecap="round" stroke-linejoin="round">' . $main . '</g>'
			. '<circle cx="356" cy="76" r="9" fill="#fbbf24"/><circle cx="84" cy="300" r="6" fill="#0b7a75"/><circle cx="380" cy="282" r="5" fill="#19b3a6"/>'
			. $chip( 18, 54, $c['chips'][0][0], $c['chips'][0][1] )
			. $chip( 222, 286, $c['chips'][1][0], $c['chips'][1][1] )
			. '</svg></figure>';
	}
}

if ( ! function_exists( 'db_svc_feature_icon' ) ) {
	/** Icon for an "at a glance" point, from its wording. */
	function db_svc_feature_icon( $label, $fallback ) {
		$l   = strtolower( $label );
		$map = array( 'negotiat' => 'chat', 'secur' => 'shield', 'safe' => 'shield', 'legal' => 'doc', 'support' => 'users', 'search' => 'search', 'value' => 'chart', 'apprais' => 'chart', 'market' => 'horn', 'transfer' => 'swap', 'renew' => 'clock', 'dns' => 'server', 'host' => 'server', 'backup' => 'shield', 'seo' => 'search', 'social' => 'at', 'handle' => 'at', 'app' => 'phone', 'design' => 'monitor', 'web' => 'monitor', 'software' => 'code', 'custom' => 'code', 'report' => 'doc', 'privacy' => 'lock', 'price' => 'tag', 'confiden' => 'lock', 'fast' => 'clock', 'expert' => 'users', 'portfolio' => 'chart', 'brand' => 'spark', 'growth' => 'trend', 'analytic' => 'chart', 'content' => 'doc', 'mainten' => 'check' );
		foreach ( $map as $k => $icon ) {
			if ( false !== strpos( $l, $k ) ) {
				return $icon;
			}
		}
		return $fallback;
	}
}

if ( ! function_exists( 'db_svc_form_section' ) ) {
	/**
	 * The enquiry section: copy and promises on the left, the contact form on
	 * the right with $interest pre-selected.
	 *
	 * @param string $interest Value of the "I'm interested in" option.
	 * @return string Empty when the form is unavailable.
	 */
	function db_svc_form_section( $interest ) {
		$form = function_exists( 'wpcf7_contact_form' ) && wpcf7_contact_form( DB_SERVICE_FORM_ID ) ? do_shortcode( '[contact-form-7 id="' . (int) DB_SERVICE_FORM_ID . '" html_class="dbsv-cf7"]' ) : '';
		if ( '' === $form ) {
			return '';
		}
		// Pre-selected in the markup: inline scripts wait for the first interaction on this site.
		$form   = preg_replace( '#(<select\\b[^>]*name="contact-interest"[^>]*>.*?<option value=")' . preg_quote( esc_attr( $interest ), '#' ) . '"#s', '$1' . esc_attr( $interest ) . '" selected="selected"', $form, 1 );
		$points = '';
		foreach ( array( array( 'clock', 'A reply from a person within one business day' ), array( 'doc', 'A clear, fixed quote before any work starts' ), array( 'lock', 'Your details stay private and are never sold' ) ) as $p ) {
			$points .= '<li><span class="dbsv-ico">' . db_svc_icon( $p[0], 20 ) . '</span>' . esc_html( $p[1] ) . '</li>';
		}
		return '<section class="dbsv-form" id="get-started" aria-labelledby="dbsv-fh" data-interest="' . esc_attr( $interest ) . '">'
			. '<div class="dbsv-form-copy"><p class="dbp-eyebrow">Free consultation</p><h2 id="dbsv-fh">Talk to us about ' . esc_html( strtolower( $interest ) ) . '</h2>'
			. '<p>Tell us what you need. We come back with a plan and a price, not a sales pitch.</p><ul class="dbsv-points">' . $points . '</ul></div>'
			. '<div class="dbsv-form-card">' . $form . '</div></section>';
	}
}

/*
 * The hand-built service pages (their own design, images and icons) get the
 * same enquiry section at the end.
 */
if ( ! function_exists( 'db_svc_built_pages' ) ) {
	function db_svc_built_pages() {
		return array(
			'website-design-development' => 'Website design and development',
			'digital-marketing'          => 'Digital marketing',
			'software-development'       => 'Software development',
			'mobile-app-development'     => 'Mobile app development',
			'other-services'             => 'Web hosting and maintenance',
			'buy-domains-service'        => 'Buying a domain',
			'our-services'               => 'Domain brokerage',
		);
	}
}

add_filter( 'the_content', function ( $html ) {
	if ( ! is_page() || ! in_the_loop() || ! is_main_query() || false !== strpos( $html, 'dbsv-form' ) ) {
		return $html;
	}
	$post  = get_queried_object();
	$pages = db_svc_built_pages();
	if ( ! $post instanceof WP_Post || ! isset( $pages[ $post->post_name ] ) ) {
		return $html;
	}
	$section = db_svc_form_section( $pages[ $post->post_name ] );
	return '' === $section ? $html : $html . '<div class="dbp dbsv-built">' . $section . '</div>';
}, 30 );

if ( ! function_exists( 'db_svc_enhance' ) ) {
	/**
	 * @param string $html  Output of db_pages_service_layout().
	 * @param string $slug  Page slug.
	 * @param string $title Page title.
	 * @return string
	 */
	function db_svc_enhance( $html, $slug, $title ) {
		$cfg = db_svc_config();
		if ( ! isset( $cfg[ $slug ] ) || false === strpos( $html, '<section class="dbp-hero">' ) || false !== strpos( $html, 'dbsv-art' ) ) {
			return $html;
		}
		$c = $cfg[ $slug ];

		// 1. Glance list out of the hero, illustration in.
		$features = '';
		if ( preg_match( '#<aside class="dbp-glance"[^>]*>.*?</aside>#s', $html, $g ) ) {
			preg_match_all( '#<li>(.*?)</li>#s', $g[0], $li );
			$html = str_replace( $g[0], '', $html );
			if ( ! empty( $li[1] ) ) {
				$features = '<section class="dbsv-feat" aria-label="What is included"><ul>';
				foreach ( $li[1] as $item ) {
					$label     = trim( wp_strip_all_tags( html_entity_decode( $item, ENT_QUOTES, 'UTF-8' ) ) );
					$features .= '<li><span class="dbsv-ico">' . db_svc_icon( db_svc_feature_icon( $label, $c['icon'] ), 22 ) . '</span><span>' . esc_html( $label ) . '</span></li>';
				}
				$features .= '</ul></section>';
			}
		}
		$html = preg_replace( '#(<section class="dbp-hero">.*?)(</section>)#s', '$1' . str_replace( '$', '\\$', db_svc_art( $c ) ) . '$2' . str_replace( '$', '\\$', $features ), $html, 1 );

		// 2. Enquiry form instead of the closing band.
		$section = db_svc_form_section( $c['interest'] );
		if ( '' !== $section ) {
			$html = preg_replace( '#<section class="dbp-cta">.*?</section>#s', str_replace( '$', '\\$', $section ), $html, 1 );
			// Hero buttons go to the form on this page.
			$html = str_replace( 'class="dbp-btn dbp-btn--primary" href="' . esc_url( home_url( '/contact-us/' ) ) . '">Talk to us</a>', 'class="dbp-btn dbp-btn--primary" href="#get-started">Get a free consultation</a>', $html );
		}
		return $html;
	}
}

add_filter( 'the_content', function ( $html ) {
	if ( ! function_exists( 'db_pages_kind' ) || 'service' !== db_pages_kind() || ! in_the_loop() || ! is_main_query() ) {
		return $html;
	}
	$post = get_queried_object();
	return $post instanceof WP_Post ? db_svc_enhance( $html, $post->post_name, get_the_title( $post ) ) : $html;
}, 25 );

add_action( 'wp_enqueue_scripts', function () {
	$built = is_page( array_keys( db_svc_built_pages() ) );
	if ( ! $built && ( ! function_exists( 'db_pages_kind' ) || 'service' !== db_pages_kind() ) ) {
		return;
	}
	$css = '.dbsv-art{margin:0;display:flex;justify-content:center}.dbsv-art svg{width:100%;max-width:400px;height:auto;display:block}'
		. '.dbsv-feat{margin:-6px 0 clamp(32px,5vw,56px)}.dbsv-feat ul{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:0;padding:0;list-style:none}'
		. 'html body .dbsv-feat li{display:flex;align-items:center;gap:14px;margin:0;padding:16px 18px;border:1px solid var(--m-line,#e5e7eb);border-radius:18px;background:#fff;font-size:16px!important;font-weight:700;line-height:1.35;color:var(--m-ink,#111827)}'
		. '.dbsv-ico{flex:none;display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;background:#e8f4f3;color:var(--m-accent,#0b7a75)}'
		. '.dbsv-built{max-width:1180px;margin:0 auto;padding:0 var(--m-gutter,20px)}.dbsv-form{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);gap:clamp(24px,4vw,56px);align-items:start;margin:clamp(40px,6vw,72px) 0 12px;padding:clamp(26px,4vw,52px);border-radius:28px;color:#fff;background:radial-gradient(900px 420px at 0% 0%,rgba(25,179,166,.38) 0%,rgba(25,179,166,0) 60%),#0c1424;scroll-margin-top:96px}'
		. 'html body .dbsv-form h2{margin:0 0 12px;font-size:clamp(26px,3.2vw,38px)!important;line-height:1.1;letter-spacing:-.025em;font-weight:800;color:#fff!important;text-align:left}'
		. 'html body .dbsv-form .dbp-eyebrow{color:#b6f0ea!important;margin:0 0 12px}html body .dbsv-form-copy>p{margin:0 0 20px;font-size:17px!important;line-height:1.6;color:rgba(255,255,255,.82)!important}'
		. '.dbsv-points{margin:0;padding:0;list-style:none;display:grid;gap:12px}html body .dbsv-points li{display:flex;align-items:center;gap:12px;margin:0;font-size:15.5px!important;color:rgba(255,255,255,.9)!important}'
		. '.dbsv-points .dbsv-ico{width:38px;height:38px;background:rgba(255,255,255,.1);color:#34d5c5}'
		. '.dbsv-form-card{padding:clamp(20px,3vw,30px);border-radius:22px;background:#fff;color:var(--m-text,#374151)}'
		. 'html body .dbsv-form-card label,html body .dbsv-form-card .dbc-field label{display:block;margin:0 0 6px;font-size:14.5px!important;font-weight:700;color:var(--m-ink,#111827)!important}'
		. 'html body .dbsv-form-card input[type=text],html body .dbsv-form-card input[type=email],html body .dbsv-form-card input[type=tel],html body .dbsv-form-card select,html body .dbsv-form-card textarea{width:100%!important;min-height:48px;padding:10px 14px!important;border:1.5px solid #c3c9d2!important;border-radius:12px!important;font-size:16px!important;background:#fff!important;color:var(--m-ink,#111827)!important}'
		. 'html body .dbsv-form-card textarea{min-height:110px;max-height:220px}'
		. 'html body .dbsv-form-card input:focus,html body .dbsv-form-card select:focus,html body .dbsv-form-card textarea:focus{border-color:var(--m-ink,#111827)!important;box-shadow:0 0 0 3px rgba(11,122,117,.22)!important;outline:0}'
		. 'html body .dbsv-form-card input[type=submit]{width:100%;min-height:52px;border:0!important;border-radius:999px!important;background:#111827!important;color:#fff!important;font-size:16px!important;font-weight:700;cursor:pointer}'
		. 'html body .dbsv-form-card input[type=submit]:hover{background:var(--m-accent,#0b7a75)!important}'
		. 'html body .dbsv-form-card p{margin:0 0 14px}'
		. '@media (max-width:900px){.dbsv-form{grid-template-columns:minmax(0,1fr)}.dbsv-art svg{max-width:300px}}';
	if ( function_exists( 'db_external_style' ) && db_external_style( 'service-art', $css ) ) {
		return;
	}
	wp_register_style( 'db-service-art', false, array(), DB_BLOCKS_LOADED );
	wp_enqueue_style( 'db-service-art' );
	wp_add_inline_style( 'db-service-art', $css );
}, 115 );
