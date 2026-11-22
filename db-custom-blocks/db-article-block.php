<?php
/**
 * Blog and news article layout (single posts).
 *
 * Follows the pattern the leading SEO publishers use for long-form posts:
 *   - an open reading column (no boxed card), 68 to 72 characters wide;
 *   - a header with the category, the title, a one-line summary, the author,
 *     the published and updated dates and the reading time;
 *   - a table of contents built from the H2 headings: a sticky sidebar on wide
 *     screens, a collapsible box above the article on phones;
 *   - one call to action in the sidebar, one mid-article and one at the end;
 *   - an author box and share links after the article.
 * Every heading gets a stable id so the table of contents and shared links can
 * jump to it.
 *
 * Rank Math: this table of contents is registered with Rank Math's
 * content analysis ("Table of contents" test).
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_article_anchor' ) ) {
	/** URL-safe id for a heading, unique within the page. */
	function db_article_anchor( $text, array &$used ) {
		$id   = sanitize_title( wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
		$id   = '' === $id ? 'section' : substr( $id, 0, 60 );
		$base = $id;
		$n    = 2;
		while ( isset( $used[ $id ] ) ) {
			$id = $base . '-' . $n++;
		}
		$used[ $id ] = true;
		return $id;
	}
}

if ( ! function_exists( 'db_article_prepare' ) ) {
	/**
	 * Adds ids to the H2 and H3 headings of the article body and returns the
	 * table of contents (H2 only, the ones a reader scans).
	 *
	 * @param string $body Entry content HTML.
	 * @return array{0:string,1:array<int,array{0:string,1:string}>} Body and TOC entries.
	 */
	function db_article_prepare( $body ) {
		$used = array();
		$toc  = array();
		// Headings that title a box (takeaways, call to action, related reading)
		// are not sections of the article: mark them so they stay out of the TOC.
		$body = preg_replace( '#(class="[^"]*\\bdb-(?:takeaways|cta|related)\\b[^"]*"[^>]*>(?:(?!<h2).)*?<h2\\b)#s', '$1 data-notoc="1"', $body );
		$body = preg_replace_callback( '#<h([23])\b([^>]*)>(.*?)</h\1>#s', function ( $m ) use ( &$used, &$toc ) {
			$attrs = $m[2];
			$text  = trim( wp_strip_all_tags( $m[3] ) );
			// Headings inside the takeaway, CTA and related boxes are not sections.
			if ( false !== strpos( $attrs, 'data-notoc' ) ) {
				return $m[0];
			}
			if ( '' === $text || preg_match( '#\bid="#', $attrs ) ) {
				if ( preg_match( '#\bid="([^"]+)"#', $attrs, $id ) && '2' === $m[1] ) {
					$toc[] = array( $id[1], $text );
				}
				return $m[0];
			}
			$id = db_article_anchor( $text, $used );
			if ( '2' === $m[1] ) {
				$toc[] = array( $id, $text );
			}
			return '<h' . $m[1] . ' id="' . esc_attr( $id ) . '"' . $attrs . '>' . $m[3] . '</h' . $m[1] . '>';
		}, $body );
		return array( $body, $toc );
	}
}

if ( ! function_exists( 'db_article_cta' ) ) {
	/**
	 * Calls to action for a post, by topic.
	 *
	 * @param WP_Post $post Post.
	 * @return array{title:string,text:string,primary:array,secondary:array}
	 */
	function db_article_cta( $post ) {
		// Posts about selling or valuing a name invite readers to sell; every other post to buy.
		// Each call to action carries the other as its secondary link.
		$topic = strtolower( get_the_title( $post ) . ' ' . implode( ' ', wp_list_pluck( (array) get_the_category( $post->ID ), 'name' ) ) );
		$sell  = function_exists( 'db_seller_enabled' ) && db_seller_enabled() && preg_match( '/\b(sell|selling|seller|sold|valu(e|ation|ing)|apprais|worth|flip|portfolio|park(ing|ed))/', $topic );
		$buy   = array( 'Browse premium domains', home_url( '/all-domains/' ) );
		$list  = array( 'Sell your domains', home_url( '/sell-your-domains/' ) );
		if ( $sell ) {
			return array(
				'title'     => 'Own a domain you no longer use?',
				'text'      => 'List it on Domain Brothers for free. Our brokers handle every buyer, and you pay a commission only when it sells.',
				'primary'   => $list,
				'secondary' => $buy,
			);
		}
		return array(
			'title'     => 'Ready to buy a premium domain?',
			'text'      => 'Premium domains with clear prices, flexible payment terms and offers answered within one business day.',
			'primary'   => array( 'Buy a premium domain now', home_url( '/all-domains/' ) ),
			'secondary' => function_exists( 'db_seller_enabled' ) && db_seller_enabled() ? $list : array( 'Acquire a specific domain', home_url( '/acquire-premium-domain/' ) ),
		);
	}
}

if ( ! function_exists( 'db_article_layout' ) ) {
	/**
	 * Rebuilds the theme's <article> for a single post.
	 *
	 * @param string  $html Full page HTML.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	function db_article_layout( $html, $post ) {
		if ( false !== strpos( $html, 'class="dbb"' ) || ! preg_match( '#<div class="news-cnt">\s*(<article\b[^>]*>)(.*?)</article>\s*</div>#s', $html, $art, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$inner = $art[2][0];
		if ( ! preg_match( '#<div class="entry-content">(.*)</div>\s*(?:<footer class="entry-footer">(.*?)</footer>)?\s*</div>\s*$#s', $inner, $c ) ) {
			return $html;
		}
		$body   = $c[1];
		$footer = isset( $c[2] ) ? $c[2] : '';
		list( $body, $toc ) = db_article_prepare( $body );

		$title    = get_the_title( $post );
		$cats     = get_the_category( $post->ID );
		$cat      = $cats ? $cats[0] : null;
		$words    = str_word_count( wp_strip_all_tags( $body ) );
		$minutes  = max( 1, (int) round( $words / 230 ) );
		$pub      = get_the_date( '', $post );
		$mod      = get_the_modified_date( '', $post );
		$dek      = has_excerpt( $post ) ? wp_strip_all_tags( get_the_excerpt( $post ) ) : '';
		$cta      = db_article_cta( $post );
		$url      = get_permalink( $post );
		$author   = 'Domain Brothers team';
		$about    = home_url( '/about-us/' );

		$tocs = '';
		if ( count( $toc ) >= 3 ) {
			$items = '';
			foreach ( $toc as $t ) {
				$items .= '<li><a href="#' . esc_attr( $t[0] ) . '">' . esc_html( $t[1] ) . '</a></li>';
			}
			$tocs = '<nav class="dbb-toc" aria-label="Table of contents"><details open><summary>In this article</summary><ol>' . $items . '</ol></details></nav>';
		}

		$share = '';
		foreach ( array(
			'X'        => 'https://twitter.com/intent/tweet?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title ),
			'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $url ),
			'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ),
			'Email'    => 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . rawurlencode( $url ),
		) as $label => $href ) {
			$share .= '<a href="' . esc_url( $href ) . '"' . ( 'Email' === $label ? '' : ' target="_blank" rel="noopener"' ) . '>' . esc_html( $label ) . '</a>';
		}

		$tags = '';
		foreach ( (array) get_the_tags( $post->ID ) as $tag ) {
			if ( $tag instanceof WP_Term ) {
				$tags .= '<span>' . esc_html( $tag->name ) . '</span>';
			}
		}

		$out = '<div class="dbb">'
			. '<header class="dbb-head">'
			. ( $cat ? '<a class="dbb-cat" href="' . esc_url( get_category_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a>' : '' )
			. '<h1 class="dbb-title">' . esc_html( $title ) . '</h1>'
			. ( '' !== $dek ? '<p class="dbb-dek">' . esc_html( $dek ) . '</p>' : '' )
			. '<div class="dbb-meta"><span class="dbb-avatar" aria-hidden="true">DB</span><span><a href="' . esc_url( $about ) . '">' . esc_html( $author ) . '</a><br><time datetime="' . esc_attr( get_the_date( 'c', $post ) ) . '">' . esc_html( $pub ) . '</time>'
			. ( $mod !== $pub ? ' &middot; Updated <time datetime="' . esc_attr( get_the_modified_date( 'c', $post ) ) . '">' . esc_html( $mod ) . '</time>' : '' )
			. ' &middot; ' . (int) $minutes . ' min read</span></div></header>'
			. '<div class="dbb-grid"><div class="dbb-main">' . ( '' !== $tocs ? '<div class="dbb-toc-m">' . str_replace( '<details open>', '<details>', $tocs ) . '</div>' : '' )
			. '<article class="' . esc_attr( implode( ' ', get_post_class( 'dbb-body', $post ) ) ) . '">' . $body . '</article>'
			. '<section class="dbb-end"><div class="dbb-share"><span>Share</span>' . $share . '</div>' . ( '' !== $tags ? '<div class="dbb-tags">' . $tags . '</div>' : '' )
			. '<div class="dbb-author"><span class="dbb-avatar dbb-avatar--lg" aria-hidden="true">DB</span><div><p class="dbb-author-n">Written by the <a href="' . esc_url( home_url( '/our-team/' ) ) . '">Domain Brothers team</a></p><p>Domain brokers and marketers with 27+ years of combined experience and 5,000+ domain sales. We write about what we see in real deals.</p></div></div>'
			. '<div class="dbb-final"><div><p class="dbb-final-t">' . esc_html( $cta['title'] ) . '</p><p>' . esc_html( $cta['text'] ) . '</p></div><p class="dbb-final-a"><a class="dbb-btn dbb-btn--light" href="' . esc_url( $cta['primary'][1] ) . '">' . esc_html( $cta['primary'][0] ) . '</a></p></div></section></div>'
			. '<aside class="dbb-side">' . $tocs
			. '<div class="dbb-sidecta"><p class="dbb-sidecta-t">' . esc_html( $cta['title'] ) . '</p><p>' . esc_html( $cta['text'] ) . '</p><a class="dbb-btn dbb-btn--light" href="' . esc_url( $cta['primary'][1] ) . '">' . esc_html( $cta['primary'][0] ) . '</a>'
			. ( '' !== $cta['secondary'][0] ? '<a class="dbb-sidecta-2" href="' . esc_url( $cta['secondary'][1] ) . '">' . esc_html( $cta['secondary'][0] ) . ' &rarr;</a>' : '' ) . '</div>'
			. '</aside></div>'
			. ( function_exists( 'db_link_hub_html' ) ? db_link_hub_html( (string) wp_parse_url( $url, PHP_URL_PATH ) ) : '' )
			. '</div>';
		unset( $footer );
		return substr_replace( $html, $out, $art[0][1], strlen( $art[0][0] ) );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_singular( 'post' ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	ob_start( function ( $html ) use ( $post ) {
		return db_article_layout( $html, $post );
	} );
}, 22 );

// Rank Math's "Table of contents" test: recognise this plugin's table of contents.
add_filter( 'rank_math/researches/toc_plugins', function ( $plugins ) {
	$plugins['db-custom-blocks/db-custom-blocks.php'] = 'Domain Brothers table of contents';
	return $plugins;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$css = <<<'CSS'
html body.single-post.db-ui-active #main{max-width:1200px!important;margin:0 auto!important;padding:28px var(--m-gutter,20px) 16px!important}
.dbb{color:var(--m-text,#374151)}
.dbb-head{max-width:820px;margin:0 0 30px}
html body.db-ui-active .dbb-cat{display:inline-block;margin:0 0 14px;padding:6px 12px;border-radius:999px;background:#e8f4f3;color:var(--m-accent,#0b7a75)!important;font-size:13px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;text-decoration:none!important}
html body.db-ui-active .dbb-title{margin:0 0 14px;font-size:clamp(32px,4.6vw,52px)!important;line-height:1.08;letter-spacing:-.03em;font-weight:800;color:var(--m-ink,#111827)!important;text-align:left;text-wrap:balance}
html body.db-ui-active .dbb-dek{margin:0 0 20px;font-size:clamp(18px,1.8vw,21px);line-height:1.5;color:var(--m-text,#374151)}
.dbb-meta{display:flex;align-items:center;gap:12px;font-size:14.5px;line-height:1.45;color:var(--m-muted,#5b6472)}
html body.db-ui-active .dbb-meta a{color:var(--m-ink,#111827)!important;font-weight:700;text-decoration:none!important}
.dbb-avatar{flex:none;display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:50%;background:#0c1424;color:#c5f05a;font-size:13px;font-weight:800;letter-spacing:.02em}
.dbb-avatar--lg{width:56px;height:56px;font-size:16px}
.dbb-grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:clamp(28px,4vw,56px);align-items:start}
.dbb-main{min-width:0}
html body.db-ui-active .dbb-body{margin:0!important;padding:0!important;border:0!important;box-shadow:none!important;background:none!important;max-width:720px}
html body.db-ui-active .dbb-body>p,html body.db-ui-active .dbb-body>ul>li,html body.db-ui-active .dbb-body>ol>li{font-size:18px!important;line-height:1.75!important;color:#2b3340}
html body.db-ui-active .dbb-body>h2{margin:44px 0 14px!important;font-size:clamp(25px,2.6vw,31px)!important;line-height:1.2;letter-spacing:-.02em;font-weight:800;color:var(--m-ink,#111827);scroll-margin-top:96px}
html body.db-ui-active .dbb-body>h3{margin:30px 0 10px!important;font-size:21px!important;line-height:1.3;font-weight:700;color:var(--m-ink,#111827);scroll-margin-top:96px}
html body.db-ui-active .dbb-body>p a,html body.db-ui-active .dbb-body>ul a,html body.db-ui-active .dbb-body>ol a{color:var(--m-accent,#0b7a75);text-decoration:underline;text-underline-offset:3px}
html body.db-ui-active .dbb-body img{border-radius:18px!important;height:auto}
html body.db-ui-active .dbb-body figure{margin:28px 0}
html body.db-ui-active .dbb-body figure.feature-image{margin:0 0 30px}
html body.db-ui-active .dbb-body figcaption{font-size:14px;color:var(--m-muted,#5b6472);text-align:center}
html body.db-ui-active .dbb-body blockquote{margin:28px 0;padding:6px 0 6px 22px;border-left:4px solid var(--m-accent,#0b7a75);font-size:19px}
.dbb-side{position:sticky;top:96px;display:grid;gap:18px}
.dbb-toc{border:1px solid var(--m-line,#e5e7eb);border-radius:18px;background:#fff;padding:18px 18px 14px}
html body.db-ui-active .dbb-toc summary{cursor:pointer;list-style:none;margin:0 0 10px;font-size:13px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--m-muted,#5b6472)}
.dbb-toc summary::-webkit-details-marker{display:none}
.dbb-toc ol{margin:0;padding:0;list-style:none;counter-reset:dbb;display:grid;gap:2px;max-height:52vh;overflow:auto}
.dbb-toc li{counter-increment:dbb;margin:0}
html body.db-ui-active .dbb-toc a{display:flex;gap:10px;padding:7px 8px;border-radius:10px;font-size:14.5px;line-height:1.4;color:var(--m-text,#374151)!important;text-decoration:none!important}
.dbb-toc a::before{content:counter(dbb);flex:none;width:20px;color:var(--m-accent,#0b7a75);font-weight:800}
html body.db-ui-active .dbb-toc a:hover,html body.db-ui-active .dbb-toc a.is-on{background:#f1f8f7;color:var(--m-ink,#111827)!important}
.dbb-toc-m{display:none;margin:0 0 26px}
/* The theme sets overflow-x:hidden on <body>, which makes it a scroll container and stops position:sticky. clip hides horizontal overflow without that. */
html body.single-post,html body.single-post #page,html body.single-post #content{overflow-x:clip!important;overflow-y:visible!important}
.dbb-side{max-height:calc(100vh - 120px)}
.dbb-side .dbb-toc{display:flex;flex-direction:column;max-height:calc(100vh - 120px)}
.dbb-side .dbb-toc details{display:flex;flex-direction:column;min-height:0}
.dbb-side .dbb-toc ol{max-height:none;min-height:0;overflow:auto;overscroll-behavior:contain}
.dbb-sidecta{padding:22px;border-radius:20px;color:#fff;background:radial-gradient(420px 240px at 100% 0%,rgba(25,179,166,.5) 0%,rgba(25,179,166,0) 65%),#0c1424}
html body.db-ui-active .dbb-sidecta-t,html body.db-ui-active .dbb-final-t,html body.db-ui-active .dbb-mid-t{margin:0 0 8px;font-size:19px;line-height:1.25;font-weight:800;letter-spacing:-.015em;color:inherit}
html body.db-ui-active .dbb-sidecta p{margin:0 0 16px;font-size:14.5px;line-height:1.55;color:rgba(255,255,255,.82)}
html body.db-ui-active .dbb-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 20px;border-radius:999px;background:#fff;color:#0c1424!important;font-size:15px;font-weight:700;text-decoration:none!important;white-space:nowrap}
html body.db-ui-active .dbb-sidecta .dbb-btn{width:100%}
html body.db-ui-active .dbb-sidecta-2{display:block;margin:12px 0 0;text-align:center;font-size:14px;font-weight:700;color:#b6f0ea!important;text-decoration:none!important}
.dbb-mid{display:flex;align-items:center;justify-content:space-between;gap:18px;margin:36px 0;padding:22px 24px;border-radius:20px;background:#f1f8f7;border:1px solid #cfe7e4}
html body.db-ui-active .dbb-mid p{margin:0;font-size:15.5px!important;line-height:1.5!important;color:var(--m-text,#374151)}
html body.db-ui-active .dbb-mid .dbb-mid-t{color:var(--m-ink,#111827);margin:0 0 4px}
html body.db-ui-active .dbb-mid .dbb-btn{background:#111827;color:#fff!important;flex:none}
.dbb-end{max-width:720px;margin:44px 0 0;display:grid;gap:22px}
.dbb-share{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:18px 0;border-top:1px solid var(--m-line,#e5e7eb);border-bottom:1px solid var(--m-line,#e5e7eb)}
.dbb-share span{margin-right:6px;font-size:13px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--m-muted,#5b6472)}
html body.db-ui-active .dbb-share a{display:inline-flex;align-items:center;min-height:38px;padding:0 14px;border:1px solid var(--m-line,#e5e7eb);border-radius:999px;font-size:14px;font-weight:700;color:var(--m-ink,#111827)!important;text-decoration:none!important}
html body.db-ui-active .dbb-share a:hover{border-color:var(--m-ink,#111827)}
.dbb-tags{display:flex;flex-wrap:wrap;gap:8px}.dbb-tags span{padding:5px 12px;border-radius:999px;background:var(--m-soft,#f6f7f9);font-size:13.5px;font-weight:600;color:var(--m-text,#374151)}
.dbb-author{display:flex;gap:16px;align-items:flex-start;padding:22px;border-radius:20px;background:var(--m-soft,#f6f7f9)}
html body.db-ui-active .dbb-author p{margin:0;font-size:15px;line-height:1.6}
html body.db-ui-active .dbb-author .dbb-author-n{margin:0 0 4px;font-size:16px;font-weight:800;color:var(--m-ink,#111827)}
html body.db-ui-active .dbb-author a{color:var(--m-accent,#0b7a75)}
.dbb-final{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:18px;padding:clamp(22px,3vw,32px);border-radius:24px;color:#fff;background:radial-gradient(700px 320px at 0% 0%,rgba(25,179,166,.4) 0%,rgba(25,179,166,0) 60%),#0c1424}
html body.db-ui-active .dbb-final p{margin:0;font-size:15.5px;line-height:1.55;color:rgba(255,255,255,.82)}
html body.db-ui-active .dbb-final .dbb-final-t{font-size:22px;color:#fff;margin:0 0 6px}
.dbb-final-a{display:flex;flex-wrap:wrap;gap:10px}
html body.db-ui-active .dbb-btn--ghost{background:transparent;border:1.5px solid rgba(255,255,255,.45);color:#fff!important}
html body.single-post.db-ui-active .post-navigation{max-width:720px;margin:30px 0 0}
@media (max-width:1020px){.dbb-grid{grid-template-columns:minmax(0,1fr)}.dbb-side{display:none}.dbb-toc-m{display:block;position:sticky;top:64px;z-index:30;margin:0 0 22px}.dbb-toc-m .dbb-toc{padding:10px 14px;box-shadow:0 8px 22px -14px rgba(12,20,36,.45)}.dbb-toc-m .dbb-toc summary{display:flex;align-items:center;justify-content:space-between;margin:0;padding:6px 0}.dbb-toc-m .dbb-toc summary::after{content:"\25BE";font-size:14px}.dbb-toc-m .dbb-toc details[open] summary{margin-bottom:8px}.dbb-toc-m .dbb-toc ol{max-height:55vh}html body.db-ui-active .dbb-body,.dbb-end{max-width:none}}
@media (max-width:560px){.dbb-mid{flex-direction:column;align-items:flex-start}html body.db-ui-active .dbb-body>p,html body.db-ui-active .dbb-body>ul>li,html body.db-ui-active .dbb-body>ol>li{font-size:17px!important}}
CSS;
	if ( function_exists( 'db_external_style' ) && db_external_style( 'article', $css ) ) {
		return;
	}
	wp_register_style( 'db-article', false, array(), DB_BLOCKS_LOADED );
	wp_enqueue_style( 'db-article' );
	wp_add_inline_style( 'db-article', $css );
}, 116 );

// Highlight the section being read in the sidebar table of contents.
add_action( 'wp_footer', function () {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	echo '<script>(function(){var l=[].slice.call(document.querySelectorAll(".dbb-side .dbb-toc a"));if(!l.length||!("IntersectionObserver" in window)){return;}var m={};l.forEach(function(a){m[a.getAttribute("href").slice(1)]=a;});var o=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting&&m[e.target.id]){l.forEach(function(a){a.classList.remove("is-on");});m[e.target.id].classList.add("is-on");}});},{rootMargin:"-20% 0px -70% 0px"});Object.keys(m).forEach(function(id){var h=document.getElementById(id);if(h){o.observe(h);}});})();</script>';
}, 99 );
