<?php
/**
 * Footer: a "Legal" row linking every published policy page, plus "Cookie
 * settings" (reopens the consent banner from db-consent-block.php).
 *
 * The theme's footer is not edited. The row is added to the page output just
 * before the last </footer>. A policy already linked inside the footer is not
 * repeated, and a policy page that is not published (or missing) is left out,
 * so the row never links to a 404.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_footer_links_inject' ) ) {
	/**
	 * Adds the legal row to a page. Pure: tested offline in tests/consent/footer-links-test.php.
	 *
	 * @param string                                 $html     Whole page.
	 * @param array<int, array{0:string, 1:string}> $links    [ label, absolute URL ] in order.
	 * @param bool                                   $settings Add the "Cookie settings" link.
	 */
	function db_footer_links_inject( $html, array $links, $settings ) {
		if ( ! is_string( $html ) || false !== strpos( $html, 'id="db-legal-links"' ) ) {
			return $html;
		}
		$pos = strripos( $html, '</footer>' );
		if ( false === $pos ) {
			return $html;
		}
		$open   = strripos( substr( $html, 0, $pos ), '<footer' );
		$footer = false === $open ? '' : substr( $html, $open, $pos - $open );
		$items  = '';
		foreach ( $links as $l ) {
			$path = (string) wp_parse_url( $l[1], PHP_URL_PATH );
			if ( '' !== $path && '/' !== $path && preg_match( '#href=["\'][^"\']*' . preg_quote( rtrim( $path, '/' ), '#' ) . '/?["\'?\#]#i', $footer ) ) {
				continue;
			}
			$items .= '<li><a href="' . esc_url( $l[1] ) . '">' . esc_html( $l[0] ) . '</a></li>';
		}
		if ( $settings ) {
			// A native button: Enter and Space both open the panel (QA report DB-05).
			$items .= '<li><button type="button" class="db-legal-btn" data-db-consent-open>Cookie settings</button></li>';
		}
		if ( '' === $items ) {
			return $html;
		}
		$row = '<nav id="db-legal-links" class="db-legal-links" aria-label="Legal"><ul>' . $items . '</ul></nav>'
			. '<style>#db-legal-links{clear:both;padding:14px 16px;text-align:center;font-size:14px;line-height:1.6}'
			. '#db-legal-links ul{display:flex;flex-wrap:wrap;justify-content:center;gap:4px 18px;margin:0;padding:0;list-style:none}'
			. '#db-legal-links a{color:inherit;text-decoration:underline;text-underline-offset:2px}'
			. '#db-legal-links a:hover,#db-legal-links a:focus-visible{text-decoration-thickness:2px}'
			. '#db-legal-links .db-legal-btn{margin:0;padding:0;border:0;background:none;font:inherit;color:inherit;text-decoration:underline;text-underline-offset:2px;cursor:pointer}'
			. '#db-legal-links .db-legal-btn:hover,#db-legal-links .db-legal-btn:focus-visible{text-decoration-thickness:2px}</style>';
		return substr( $html, 0, $pos ) . $row . substr( $html, $pos );
	}
}

if ( ! function_exists( 'db_footer_links_list' ) ) {
	/** Published policy pages, in footer order. */
	function db_footer_links_list() {
		$want = array(
			'privacy-policy'                   => 'Privacy Policy',
			'terms-of-use'                     => 'Terms of Use',
			'cookie-policy'                    => 'Cookie Policy',
			'refund-policy'                    => 'Refund Policy',
			'acceptable-use-policy'            => 'Acceptable Use Policy',
			'trademark-and-copyright-notices'  => 'Trademark & Copyright',
			'payment-terms'                    => 'Payment Terms',
		);
		$out = array();
		foreach ( $want as $slug => $label ) {
			$p = get_page_by_path( $slug );
			if ( $p && 'publish' === $p->post_status && '' === (string) $p->post_password ) {
				$out[] = array( $label, get_permalink( $p ) );
			}
		}
		return (array) apply_filters( 'db_footer_links', $out );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_robots() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$links    = db_footer_links_list();
	$settings = function_exists( 'db_consent_active' ) && db_consent_active();
	if ( ! $links && ! $settings ) {
		return;
	}
	ob_start( function ( $html ) use ( $links, $settings ) {
		return db_footer_links_inject( $html, $links, $settings );
	} );
}, 4 );
