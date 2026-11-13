<?php
/**
 * The Our Team page (/our-team/), laid out after the production design.
 *
 *   Banner      breadcrumb (Home / Our Team), "THE PEOPLE BEHIND DOMAIN
 *               BROTHERS", "Real people. Clear guidance. Better domains.",
 *               intro line and the chain-and-.com illustration
 *               (assets/team-hero.webp).
 *   Team        "Meet our team" with a lime rule, then one card per person
 *               (photo, name, role pill, short bio), two per row.
 *   CTA band    "The right domain. The right team." with Contact Our Team.
 *
 * The theme's header and footer are kept (get_header() / get_footer() in
 * db-team-template.php). Every rule in assets/team.css is scoped to #db-team.
 * The people live in db_team_members() (filterable with db_team_members).
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_team_is_page' ) ) {
	/** True on the Our Team page. */
	function db_team_is_page() {
		return is_page( array( 'our-team', 'team' ) );
	}
}

if ( ! function_exists( 'db_team_members' ) ) {
	/**
	 * The team, in display order. Photos are the ones already in the media
	 * library (the same files the live site uses), loaded from this site's own
	 * uploads folder.
	 *
	 * @return array<int, array{name:string, role:string, bio:string, photo:string}>
	 */
	function db_team_members() {
		$up = trailingslashit( (string) wp_upload_dir( null, false )['baseurl'] );
		$members = array(
			array(
				'name'  => 'Kartik Mehta',
				'role'  => 'Co-Founder & CEO',
				'bio'   => 'Kartik brings over 15 years of experience in online marketing and the domain industry, guiding Domain Brothers with strategic vision.',
				'photo' => $up . '2024/04/kartikmehta.jpg',
			),
			array(
				'name'  => 'Jay Mehta',
				'role'  => 'Co-Founder & COO',
				'bio'   => 'Jay combines 15 years of domain and online marketing experience with a focus on smooth, effective operations.',
				'photo' => $up . '2024/04/jaymehta.jpg',
			),
			array(
				'name'  => 'Ashish Kalal',
				'role'  => 'Project Owner',
				'bio'   => 'Ashish leads projects from inception to completion, keeping every detail aligned with client goals.',
				'photo' => $up . '2024/04/ashishkalal.jpg',
			),
			array(
				'name'  => 'Harsh Raval',
				'role'  => 'Product Manager',
				'bio'   => 'Harsh turns customer needs into thoughtful products and guides each stage of development.',
				'photo' => $up . '2024/04/harshraval.jpg',
			),
			array(
				'name'  => 'Anil Chaudhary',
				'role'  => 'Senior Developer',
				'bio'   => 'Anil works across many programming languages and frameworks, turning designs into functional, user-friendly websites.',
				'photo' => $up . '2021/12/Anil_Chaudhary_NC0058_white_bg.png',
			),
			array(
				'name'  => 'Sujit Shukla',
				'role'  => 'Marketing Manager',
				'bio'   => 'Sujit shapes our marketing strategy, from SEO to social media, keeping Domain Brothers ahead in the digital marketplace.',
				'photo' => $up . '2024/04/sujitshukla.jpg',
			),
		);
		return (array) apply_filters( 'db_team_members', $members );
	}
}

if ( ! function_exists( 'db_team_html' ) ) {
	/** The page body. Strings only (no output buffering). */
	function db_team_html() {
		$contact = esc_url( home_url( '/contact-us/' ) );
		$hero    = plugins_url( 'assets/team-hero.webp', __FILE__ );
		$arrow   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';

		$h  = '<div id="db-team" class="db-team">';

		// ---- Banner ----
		$h .= '<section class="dbt-hero" aria-labelledby="dbt-title"><div class="dbt-wrap dbt-hero-in">';
		$h .= '<div class="dbt-hero-text">';
		$h .= '<nav class="db-breadcrumbs dbt-crumbs" aria-label="Breadcrumb"><ol>';
		$h .= '<li><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li>';
		$h .= '<li><span aria-current="page">Our Team</span></li>';
		$h .= '</ol></nav>';
		$h .= '<p class="dbt-eyebrow">The people behind Domain Brothers</p>';
		$h .= '<h1 id="dbt-title" class="dbt-title">Real people. Clear guidance. Better domains.</h1>';
		$h .= '<p class="dbt-lead">Meet the team helping businesses buy, sell and build with confidence.</p>';
		$h .= '</div>';
		$h .= '<div class="dbt-hero-art"><img src="' . esc_url( $hero ) . '" width="708" height="584" alt="Navy and lime green chain links joined to a white .com tile" fetchpriority="high" decoding="async"></div>';
		$h .= '</div></section>';

		// ---- Team ----
		$h .= '<section class="dbt-team" aria-labelledby="dbt-team-h"><div class="dbt-wrap">';
		$h .= '<header class="dbt-team-head"><h2 id="dbt-team-h">Meet our team</h2><p>Experience, strategy and care at every step.</p><span class="dbt-rule" aria-hidden="true"></span></header>';
		$h .= '<ul class="dbt-grid">';
		foreach ( db_team_members() as $m ) {
			$png = (bool) preg_match( '/\.png($|\?)/i', $m['photo'] );
			$h  .= '<li class="dbt-card">';
			$h  .= '<div class="dbt-photo' . ( $png ? ' dbt-photo-cut' : '' ) . '"><img src="' . esc_url( $m['photo'] ) . '" width="300" height="300" alt="' . esc_attr( $m['name'] . ', ' . $m['role'] . ', Domain Brothers' ) . '" loading="lazy" decoding="async"></div>';
			$h  .= '<div class="dbt-info">';
			$h  .= '<h3 class="dbt-name">' . esc_html( $m['name'] ) . '</h3>';
			$h  .= '<p class="dbt-role">' . esc_html( $m['role'] ) . '</p>';
			$h  .= '<p class="dbt-bio">' . esc_html( $m['bio'] ) . '</p>';
			$h  .= '</div></li>';
		}
		$h .= '</ul>';
		$h .= '</div></section>';

		// ---- CTA band ----
		$h .= '<div class="dbt-wrap"><section class="dbt-cta" aria-labelledby="dbt-cta-h">';
		$h .= '<div class="dbt-cta-text"><h2 id="dbt-cta-h">The right domain. The right team.</h2><p>Let&rsquo;s talk about your next move.</p></div>';
		$h .= '<a class="dbt-btn" href="' . $contact . '">Contact Our Team ' . $arrow . '</a>';
		$h .= '</section></div>';

		$h .= '</div>';
		return $h;
	}
}

/* ---- Page wiring --------------------------------------------------------- */

add_filter( 'template_include', function ( $template ) {
	$file = __DIR__ . '/db-team-template.php';
	if ( db_team_is_page() && ! post_password_required() && is_readable( $file ) ) {
		return $file;
	}
	return $template;
}, 99 );

add_filter( 'body_class', function ( $classes ) {
	if ( db_team_is_page() ) {
		$classes[] = 'db-team-page';
	}
	return $classes;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() || ! db_team_is_page() ) {
		return;
	}
	wp_enqueue_style( 'db-team-font', 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	$file = __DIR__ . '/assets/team.css';
	$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	if ( '' === $css ) {
		return;
	}
	if ( ! function_exists( 'db_external_style' ) || ! db_external_style( 'team', $css ) ) {
		add_action( 'wp_head', function () use ( $css ) {
			echo '<style id="db-team-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}, 130 );
	}
}, 130 );

add_action( 'wp_head', function () {
	if ( is_admin() || ! db_team_is_page() ) {
		return;
	}
	echo '<link rel="preload" as="image" href="' . esc_url( plugins_url( 'assets/team-hero.webp', __FILE__ ) ) . '" fetchpriority="high">' . "\n";
}, 2 );
