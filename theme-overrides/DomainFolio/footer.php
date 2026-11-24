<?php 
/** 
	* The template for displaying the footer. 
	* * * Contains the closing of the #content div and all content after. 
	* * * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials 
	* * * @package domainfolio  
*/ 
?>
</div>	
</div>
<?php
if (is_single()) {
		$Class = "domain_footer";
} else {
		$Class = "";
}
if (get_the_ID() == '21862' || get_the_ID() == '21865' || get_the_ID() == '22812' || get_the_ID() == '22806') {
		$Payment_Class = "payment_page";
		$Payment_Class1 = "footer-payment";
} else {
		$Payment_Class = "";
		$Payment_Class1 = "";
}
?>
<?php $db_ft_id = get_queried_object_id(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&family=Plus+Jakarta+Sans:wght@800&display=swap">
<style id="db-ft-css">
html body #colophon.db-ft{position:relative!important;isolation:isolate!important;color:rgb(255, 255, 255)!important;background-color:rgb(6, 22, 49)!important;background-image:linear-gradient(rgba(4, 16, 38, 0.18) 0px, rgba(4, 16, 38, 0.05) 45%, rgba(4, 16, 38, 0.35) 100%), url("<?php echo esc_url( get_site_url() ); ?>/wp-content/uploads/2026/11/footer-network-bg.webp")!important;background-repeat:no-repeat!important;background-size:cover!important;background-position-x:center!important;background-position-y:bottom!important;font-family:Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif!important;-webkit-font-smoothing:antialiased!important;overflow-x:hidden!important;overflow-y:hidden!important;margin-top:0px!important;margin-right:0px!important;margin-bottom:0px!important;margin-left:0px!important;padding-top:0px!important;padding-right:0px!important;padding-bottom:0px!important;padding-left:0px!important;border-top-width:0px!important;border-right-width:0px!important;border-bottom-width:0px!important;border-left-width:0px!important;border-top-style:none!important;border-right-style:none!important;border-bottom-style:none!important;border-left-style:none!important;border-top-color:currentcolor!important;border-right-color:currentcolor!important;border-bottom-color:currentcolor!important;border-left-color:currentcolor!important;border-image-source:none!important;border-image-slice:100%!important;border-image-width:1!important;border-image-outset:0!important;border-image-repeat:stretch!important}
html body #colophon.db-ft *,html body #colophon.db-ft ::before,html body #colophon.db-ft ::after{box-sizing:border-box!important}
html body #colophon.db-ft :is(h2,p,ul,li){margin-top:0px!important;margin-right:0px!important;margin-bottom:0px!important;margin-left:0px!important;padding-top:0px!important;padding-right:0px!important;padding-bottom:0px!important;padding-left:0px!important;list-style-position:initial!important;list-style-image:initial!important;list-style-type:none!important;background-image:none!important;background-position-x:initial!important;background-position-y:initial!important;background-size:initial!important;background-repeat:initial!important;background-attachment:initial!important;background-origin:initial!important;background-clip:initial!important;background-color:initial!important;border-top-width:0px!important;border-right-width:0px!important;border-bottom-width:0px!important;border-left-width:0px!important;border-top-style:none!important;border-right-style:none!important;border-bottom-style:none!important;border-left-style:none!important;border-top-color:currentcolor!important;border-right-color:currentcolor!important;border-bottom-color:currentcolor!important;border-left-color:currentcolor!important;border-image-source:none!important;border-image-slice:100%!important;border-image-width:1!important;border-image-outset:0!important;border-image-repeat:stretch!important}
html body #colophon.db-ft li::before,html body #colophon.db-ft li::marker{content:none!important;display:none!important}
html body #colophon.db-ft a{color:rgb(255, 255, 255)!important;text-decoration-line:none!important;text-decoration-thickness:initial!important;text-decoration-style:initial!important;text-decoration-color:initial!important;transition-behavior:normal, normal, normal, normal!important;transition-duration:0.2s, 0.2s, 0.2s, 0.2s!important;transition-timing-function:ease, ease, ease, ease!important;transition-delay:0s, 0s, 0s, 0s!important;transition-property:color, background-color, border-color, transform!important}
html body #colophon.db-ft a:focus-visible{outline-color:rgb(198, 226, 27)!important;outline-style:solid!important;outline-width:2px!important;outline-offset:3px!important;border-top-left-radius:4px!important;border-top-right-radius:4px!important;border-bottom-right-radius:4px!important;border-bottom-left-radius:4px!important}
html body #colophon.db-ft .db-ft__in{width:100%!important;max-width:1440px!important;margin-top:0px!important;margin-right:auto!important;margin-bottom:0px!important;margin-left:auto!important;padding-top:0px!important;padding-right:64px!important;padding-bottom:0px!important;padding-left:64px!important}
html body #colophon.db-ft .db-ft__top{display:grid!important;grid-template-columns:minmax(0px, 1.62fr) minmax(0px, 1fr) minmax(0px, 1fr) minmax(0px, 0.72fr)!important;row-gap:40px!important;column-gap:40px!important;padding-top:66px!important;padding-right:0px!important;padding-bottom:40px!important;padding-left:0px!important}
html body #colophon.db-ft .db-ft__logo{display:inline-block!important;margin-bottom:28px!important;line-height:0!important}
html body #colophon.db-ft .db-ft__logo img{display:block!important;width:255px!important;max-width:100%!important;height:auto!important}
html body #colophon.db-ft .db-ft__tag{font-size:clamp(17px, 1.5vw, 21.5px)!important;line-height:1.38!important;color:rgb(255, 255, 255)!important;max-width:380px!important;margin-bottom:28px!important}
html body #colophon.db-ft .db-ft__social{display:flex!important;row-gap:24px!important;column-gap:24px!important}
html body #colophon.db-ft .db-ft__social a{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:46px!important;height:46px!important;border-top-width:2px!important;border-right-width:2px!important;border-bottom-width:2px!important;border-left-width:2px!important;border-top-style:solid!important;border-right-style:solid!important;border-bottom-style:solid!important;border-left-style:solid!important;border-top-color:rgb(255, 255, 255)!important;border-right-color:rgb(255, 255, 255)!important;border-bottom-color:rgb(255, 255, 255)!important;border-left-color:rgb(255, 255, 255)!important;border-image-source:none!important;border-image-slice:100%!important;border-image-width:1!important;border-image-outset:0!important;border-image-repeat:stretch!important;border-top-left-radius:50%!important;border-top-right-radius:50%!important;border-bottom-right-radius:50%!important;border-bottom-left-radius:50%!important;color:rgb(255, 255, 255)!important}
html body #colophon.db-ft .db-ft__social svg{width:21px!important;height:21px!important;display:block!important}
html body #colophon.db-ft .db-ft__social a:hover{border-top-color:rgb(198, 226, 27)!important;border-right-color:rgb(198, 226, 27)!important;border-bottom-color:rgb(198, 226, 27)!important;border-left-color:rgb(198, 226, 27)!important;color:rgb(198, 226, 27)!important;transform:translateY(-2px)!important}
html body #colophon.db-ft .db-ft__col h2{font-family:"Plus Jakarta Sans", Inter, ui-sans-serif, system-ui, sans-serif!important;font-size:clamp(21px, 2vw, 29px)!important;line-height:1.15!important;font-weight:800!important;letter-spacing:-0.01em!important;color:rgb(255, 255, 255)!important;margin-top:0px!important;margin-right:0px!important;margin-bottom:22px!important;margin-left:0px!important}
html body #colophon.db-ft .db-ft__col ul{display:flex!important;flex-direction:column!important;row-gap:14px!important;column-gap:14px!important}
html body #colophon.db-ft .db-ft__col a{display:inline-block!important;font-size:clamp(16px, 1.4vw, 21px)!important;line-height:1.35!important;color:rgb(242, 245, 250)!important}
html body #colophon.db-ft .db-ft__col a:hover,html body #colophon.db-ft .db-ft__col a[aria-current]{color:rgb(198, 226, 27)!important}
html body #colophon.db-ft .db-ft__bottom{display:flex!important;align-items:center!important;row-gap:24px!important;column-gap:24px!important;border-top-width:1px!important;border-top-style:solid!important;border-top-color:rgba(255, 255, 255, 0.55)!important;padding-top:30px!important;padding-right:0px!important;padding-bottom:40px!important;padding-left:0px!important}
html body #colophon.db-ft .db-ft__copy{flex-grow:1!important;flex-shrink:1!important;flex-basis:auto!important;min-width:0px!important}
html body #colophon.db-ft .db-ft__copy p{font-size:clamp(15px, 1.3vw, 19.5px)!important;line-height:1.4!important;font-weight:700!important;color:rgb(255, 255, 255)!important}
html body #colophon.db-ft .db-ft__copy p + p{font-weight:400!important;color:rgb(193, 201, 214)!important;margin-top:4px!important}
html body #colophon.db-ft .db-ft__legal{display:flex!important;flex-wrap:wrap!important;align-items:center!important}
html body #colophon.db-ft .db-ft__legal li{display:flex!important;align-items:center!important}
html body #colophon.db-ft .db-ft__legal li + li::before{margin-top:0px!important;margin-right:22px!important;margin-bottom:0px!important;margin-left:22px!important;color:rgb(255, 255, 255)!important;font-size:18px!important;content:"|"!important;display:inline-block!important}
html body #colophon.db-ft .db-ft__legal a{font-size:clamp(14.5px, 1.25vw, 18.5px)!important;color:rgb(255, 255, 255)!important}
html body #colophon.db-ft .db-ft__legal a:hover,html body #colophon.db-ft .db-ft__legal a[aria-current]{color:rgb(198, 226, 27)!important}
html body #colophon.db-ft .db-ft__totop{flex-grow:0!important;flex-shrink:0!important;flex-basis:auto!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;width:50px!important;height:50px!important;margin-left:16px!important;border-top-left-radius:7px!important;border-top-right-radius:7px!important;border-bottom-right-radius:7px!important;border-bottom-left-radius:7px!important;background-image:initial!important;background-position-x:initial!important;background-position-y:initial!important;background-size:initial!important;background-repeat:initial!important;background-attachment:initial!important;background-origin:initial!important;background-clip:initial!important;background-color:rgb(198, 226, 27)!important;color:rgb(11, 26, 51)!important}
html body #colophon.db-ft .db-ft__totop svg{width:24px!important;height:24px!important;display:block!important}
html body #colophon.db-ft .db-ft__totop:hover{background-image:initial!important;background-position-x:initial!important;background-position-y:initial!important;background-size:initial!important;background-repeat:initial!important;background-attachment:initial!important;background-origin:initial!important;background-clip:initial!important;background-color:rgb(214, 239, 74)!important;color:rgb(11, 26, 51)!important;transform:translateY(-2px)!important}
@media (max-width: 1199px){html body #colophon.db-ft .db-ft__in{padding-top:0px!important;padding-right:40px!important;padding-bottom:0px!important;padding-left:40px!important}
html body #colophon.db-ft .db-ft__top{row-gap:28px!important;column-gap:28px!important}
html body #colophon.db-ft .db-ft__logo img{width:225px!important}}
@media (max-width: 991px){html body #colophon.db-ft{background-position-x:70%!important;background-position-y:bottom!important}
html body #colophon.db-ft .db-ft__top{grid-template-columns:repeat(3, minmax(0px, 1fr))!important;row-gap:36px!important;column-gap:28px!important;padding-top:52px!important;padding-right:0px!important;padding-bottom:34px!important;padding-left:0px!important}
html body #colophon.db-ft .db-ft__brand{grid-column-start:1!important;grid-column-end:-1!important}
html body #colophon.db-ft .db-ft__tag{max-width:440px!important}
html body #colophon.db-ft .db-ft__bottom{flex-wrap:wrap!important}
html body #colophon.db-ft .db-ft__copy{flex-basis:100%!important}
html body #colophon.db-ft .db-ft__legal{flex-grow:1!important;flex-shrink:1!important;flex-basis:auto!important}}
@media (max-width: 767px){html body #colophon.db-ft{background-image:linear-gradient(rgba(4, 16, 38, 0.25), rgba(4, 16, 38, 0.45)), url("<?php echo esc_url( get_site_url() ); ?>/wp-content/uploads/2026/11/footer-network-bg-960.webp")!important;background-position-x:75%!important;background-position-y:bottom!important}
html body #colophon.db-ft .db-ft__in{padding-top:0px!important;padding-right:20px!important;padding-bottom:0px!important;padding-left:20px!important}
html body #colophon.db-ft .db-ft__top{grid-template-columns:repeat(2, minmax(0px, 1fr))!important;row-gap:32px!important;column-gap:20px!important;padding-top:44px!important;padding-right:0px!important;padding-bottom:28px!important;padding-left:0px!important}
html body #colophon.db-ft .db-ft__logo{margin-bottom:20px!important}
html body #colophon.db-ft .db-ft__logo img{width:200px!important}
html body #colophon.db-ft .db-ft__tag{margin-bottom:22px!important}
html body #colophon.db-ft .db-ft__social{row-gap:16px!important;column-gap:16px!important}
html body #colophon.db-ft .db-ft__col h2{margin-bottom:14px!important}
html body #colophon.db-ft .db-ft__col ul{row-gap:10px!important;column-gap:10px!important}
html body #colophon.db-ft .db-ft__bottom{position:relative!important;padding-top:24px!important;padding-right:64px!important;padding-bottom:32px!important;padding-left:0px!important;row-gap:14px!important;column-gap:14px!important}
html body #colophon.db-ft .db-ft__legal li + li::before{margin-top:0px!important;margin-right:12px!important;margin-bottom:0px!important;margin-left:12px!important;font-size:15px!important}
html body #colophon.db-ft .db-ft__totop{position:absolute!important;right:0px!important;top:24px!important;width:46px!important;height:46px!important;margin-top:0px!important;margin-right:0px!important;margin-bottom:0px!important;margin-left:0px!important}}
@media (max-width: 340px){html body #colophon.db-ft .db-ft__top{grid-template-columns:1fr!important}
html body #colophon.db-ft .db-ft__legal li + li::before{margin-top:0px!important;margin-right:8px!important;margin-bottom:0px!important;margin-left:8px!important}}
@media (prefers-reduced-motion: reduce){html body #colophon.db-ft a{transition-behavior:normal!important;transition-duration:0s!important;transition-timing-function:ease!important;transition-delay:0s!important;transition-property:none!important}
html body #colophon.db-ft a:hover{transform:none!important}}
#page:focus{outline-color:initial!important;outline-style:none!important;outline-width:initial!important}
html body #colophon.db-ft .db-ft__col h2{text-transform:none!important;letter-spacing:-.01em!important}
html body #colophon.db-ft .db-ft__social a.db-ft__fb svg{width:25px!important;height:25px!important}
html body #colophon.db-ft .db-ft__col a,html body #colophon.db-ft .db-ft__legal a{padding:0!important;min-height:0!important}
@media (max-width:767px){html body #colophon.db-ft .db-ft__bottom{padding:24px 0 32px!important}html body #colophon.db-ft .db-ft__copy{padding-right:62px!important}html body #colophon.db-ft .db-ft__legal{flex-basis:100%!important}html body #colophon.db-ft .db-ft__legal a{font-size:13.5px!important}html body #colophon.db-ft .db-ft__legal li + li::before{margin:0 10px!important}}
/* type scale: text 15px, headings 20px */
html body #colophon.db-ft .db-ft__tag,html body #colophon.db-ft .db-ft__col a,html body #colophon.db-ft .db-ft__copy p,html body #colophon.db-ft .db-ft__legal a{font-size:15px!important;line-height:1.5!important}
html body #colophon.db-ft .db-ft__col h2{font-size:20px!important;line-height:1.2!important;margin-bottom:16px!important}
html body #colophon.db-ft .db-ft__col ul{gap:10px!important}
html body #colophon.db-ft .db-ft__legal li + li::before{font-size:15px!important;margin:0 14px!important}
html body #colophon.db-ft .db-ft__tag{max-width:340px!important}
@media (max-width:480px){html body #colophon.db-ft .db-ft__legal{gap:8px 22px!important}html body #colophon.db-ft .db-ft__legal li + li::before{content:none!important;display:none!important}}
</style>
<footer id="colophon" class="site-footer db-ft <?php echo esc_attr( $Class ); ?> <?php echo esc_attr( $Payment_Class ); ?>">
	<div class="db-ft__in">
		<div class="db-ft__top">
			<div class="db-ft__brand">
				<a class="db-ft__logo" href="<?php echo esc_url( get_site_url() ); ?>/" aria-label="Domain Brothers home"><img src="<?php echo esc_url( get_site_url() ); ?>/wp-content/uploads/2017/03/Domain-Brothers.png" width="200" height="54" alt="Domain Brothers" loading="lazy" decoding="async"></a>
				<p class="db-ft__tag">Helping you find the right domain for your next big idea.</p>
				<ul class="db-ft__social" aria-label="Domain Brothers on social media">
					<li><a href="https://www.facebook.com/Domain-Brothers-886942828108073/" target="_blank" rel="noopener noreferrer" class="db-ft__fb" aria-label="Facebook (opens in a new tab)"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M13.5 21v-7.6h2.6l.4-3h-3V8.5c0-.9.3-1.5 1.5-1.5h1.6V4.3c-.3 0-1.2-.1-2.3-.1-2.3 0-3.9 1.4-3.9 4v2.2H7.8v3h2.6V21h3.1Z"/></svg></a></li>
					<li><a href="https://twitter.com/DomainBrothers" target="_blank" rel="noopener noreferrer" aria-label="X, formerly Twitter (opens in a new tab)"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M17.8 3h3l-6.6 7.6L22 21h-6.1l-4.8-6.3L5.6 21h-3l7.1-8.1L2.2 3h6.2l4.3 5.7L17.8 3Zm-1.1 16.2h1.7L7.4 4.7H5.6l11.1 14.5Z"/></svg></a></li>
					<li><a href="https://www.instagram.com/domainbrothers/" target="_blank" rel="noopener noreferrer" aria-label="Instagram (opens in a new tab)"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3.5" y="3.5" width="17" height="17" rx="4.8" fill="none" stroke="currentColor" stroke-width="1.9"/><circle cx="12" cy="12" r="3.9" fill="none" stroke="currentColor" stroke-width="1.9"/><circle cx="17.2" cy="6.8" r="1.15" fill="currentColor"/></svg></a></li>
				</ul>
			</div>
			<nav class="db-ft__col" aria-labelledby="db-ft-explore">
				<h2 id="db-ft-explore">Explore</h2>
				<ul>
					<li><a href="<?php echo esc_url( get_site_url() ); ?>/all-domains/">Browse Domains</a></li>
					<li><a href="<?php echo esc_url( get_site_url() ); ?>/faqs/">Payment Plans</a></li>
					<li><a href="<?php echo esc_url( get_site_url() ); ?>/our-services/">Services</a></li>
					<li><a href="<?php echo esc_url( get_site_url() ); ?>/my-wishlist/">Wishlist</a></li>
				</ul>
			</nav>
			<nav class="db-ft__col" aria-labelledby="db-ft-company">
				<h2 id="db-ft-company">Company</h2>
				<ul>
					<li><a href="<?php echo esc_url( get_permalink( 1880 ) ); ?>"<?php echo ( $db_ft_id == 1880 ) ? ' aria-current="page"' : ''; ?>>About Us</a></li>
					<li><a href="<?php echo esc_url( get_permalink( 38489 ) ); ?>"<?php echo ( $db_ft_id == 38489 ) ? ' aria-current="page"' : ''; ?>>Our Team</a></li>
					<li><a href="https://blog.domainbrothers.com/" target="_blank" rel="noopener">Blog</a></li>
					<li><a href="<?php echo esc_url( get_permalink( 5244 ) ); ?>"<?php echo ( $db_ft_id == 5244 ) ? ' aria-current="page"' : ''; ?>>News</a></li>
				</ul>
			</nav>
			<nav class="db-ft__col" aria-labelledby="db-ft-support">
				<h2 id="db-ft-support">Support</h2>
				<ul>
					<li><a href="<?php echo esc_url( get_site_url() ); ?>/contact-us/">Contact Us</a></li>
					<li><a href="<?php echo esc_url( get_permalink( 1884 ) ); ?>"<?php echo ( $db_ft_id == 1884 ) ? ' aria-current="page"' : ''; ?>>FAQs</a></li>
					<li><a href="<?php echo esc_url( get_permalink( 1959 ) ); ?>"<?php echo ( $db_ft_id == 1959 ) ? ' aria-current="page"' : ''; ?>>Sitemap</a></li>
				</ul>
			</nav>
		</div>
		<div class="db-ft__bottom">
			<div class="db-ft__copy">
				<p>&copy; <?php echo esc_html( date( 'Y' ) ); ?> Domain Brothers. All rights reserved.</p>
				<p>A Mindshare Consulting Inc. company.</p>
			</div>
			<ul class="db-ft__legal" aria-label="Legal">
				<li><a href="<?php echo esc_url( get_permalink( 1893 ) ); ?>"<?php echo ( $db_ft_id == 1893 ) ? ' aria-current="page"' : ''; ?>>Privacy Policy</a></li>
				<li><a href="<?php echo esc_url( get_permalink( 1887 ) ); ?>"<?php echo ( $db_ft_id == 1887 ) ? ' aria-current="page"' : ''; ?>>Refund Policy</a></li>
				<li><a href="<?php echo esc_url( get_permalink( 1890 ) ); ?>"<?php echo ( $db_ft_id == 1890 ) ? ' aria-current="page"' : ''; ?>>Terms of Use</a></li>
			</ul>
			<a class="db-ft__totop" href="#page" aria-label="Back to top"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 19V5M5.5 11.5 12 5l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
		</div>
	</div>
</footer>
<script>(function(){var b=document.querySelector('#colophon .db-ft__totop');if(!b)return;b.addEventListener('click',function(e){e.preventDefault();var r=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;window.scrollTo({top:0,behavior:r?'auto':'smooth'});var t=document.getElementById('page')||document.body;if(t){t.setAttribute('tabindex','-1');t.focus({preventScroll:true});}});})();</script>


<script>
		document.addEventListener('wpcf7mailsent', function (event) {
				jQuery('.wpcf7-mail-sent-ok').hide();
				if ('5432' == event.detail.contactFormId) {
						window.location = "https://www.domainbrothers.com/thank-you/";
				} else if ('1878' == event.detail.contactFormId) {
						window.location = "https://www.domainbrothers.com/thank-you/";
				}
		}, false);
</script>
<?php wp_footer(); ?>
</body>

<?php if (get_the_ID() == '21865') { ?>
		<link rel="stylesheet" href="<?php echo get_site_url(); ?>/wp-content/themes/DomainFolio/assets/css/bootstrap-select.css" />
		<script>
				jQuery('#country_data').attr('data-live-search', true);

		</script>
<?php } ?>
<script type="text/javascript">
		jQuery(".show-more").click(function () {
				var abs = jQuery(".domain_category").height();
				jQuery(".show-more").toggleClass("minus-sign");
				if (abs < 881)
				{
						jQuery(".domain_category").css("height", "auto");
						jQuery("#More").hide();
						jQuery("#Less").show();
				}
		});
		jQuery(".less-more").click(function () {
				var abs = jQuery(".domain_category").height();
				jQuery(".less-more").toggleClass("minus-sign");
				jQuery(".domain_category").css("height", "880px", "height", "100%");
				jQuery("#Less").hide();
				jQuery("#More").show();
		});
		jQuery("#contact-phone").keypress(function () {
				return KeycheckOnlyPhonenumber(event);
		});
		jQuery("#offer-phone").keypress(function () {
				return KeycheckOnlyPhonenumber(event);
		});
		jQuery("#phone").keypress(function () {
				return KeycheckOnlyPhonenumber(event);
		});
		jQuery("#your-phone").keypress(function () {
				return KeycheckOnlyPhonenumber(event);
		});
		jQuery("#card_number").keypress(function () {
				return KeycheckOnlyPhonenumber(event);
		});
		jQuery("#security_code").keypress(function () {
				return KeycheckOnlyPhonenumber(event);
		});
		jQuery('#contact-phone').bind("cut copy paste", function (e) {
				e.preventDefault();
		});
		jQuery('#phone').bind("cut copy paste", function (e) {
				e.preventDefault();
		});
		jQuery('#your-phone').bind("cut copy paste", function (e) {
				e.preventDefault();
		});
<?php if (wp_is_mobile()) { ?>
				jQuery("h3#device-phone").html("");
				jQuery("h3#device-phone").append("<a href='tel:+1-647-455-5451'>+1-647-455-5451</a>");
<?php } ?>
		function KeycheckOnlyPhonenumber(e)
		{
				var _dom = 0;
				_dom = document.all ? 3 : (document.getElementById ? 1 : (document.layers ? 2 : 0));
				if (document.all)
						e = window.event; // for IE
				var ch = '';
				var KeyID = '';
				if (_dom == 2) { // for NN4
						if (e.which > 0)
								ch = '(' + String.fromCharCode(e.which) + ')';
						KeyID = e.which;
				} else
				{
						if (_dom == 3) { // for IE
								KeyID = (window.event) ? event.keyCode : e.which;
						} else { // for Mozilla
								if (e.charCode > 0)
										ch = '(' + String.fromCharCode(e.charCode) + ')';
								KeyID = e.charCode;
						}
				}
				if ((KeyID >= 65 && KeyID <= 90) || (KeyID >= 97 && KeyID <= 122) || (KeyID >= 33 && KeyID <= 39) || (KeyID >= 42 && KeyID <= 42) || (KeyID == 44) || (KeyID >= 46 && KeyID <= 47) || (KeyID >= 58 && KeyID <= 64) || (KeyID >= 91 && KeyID <= 96) || (KeyID >= 123 && KeyID <= 126))
				{
						return false;
				}
				return true;
		}
</script>
<script type="text/javascript">
		jQuery(document).ready(function () {
				jQuery("#domain-categories-2").removeClass("widget");
				jQuery("#domain-categories-2 h2").hide();
<?php
$Page_Id = get_the_ID();
if ($Page_Id == 22) {
		?>
						jQuery('.test').removeAttr('id');
<?php } ?>
		});

<?php
if ($Page_Id == '1959') {
		$Xml_Link = get_site_url() . "/xmlsitemap";
		$News_Link = get_site_url() . "/news-sitemap";
		?>
				jQuery(".page-list").append("<li class='page_item'><a href='https://blog.domainbrothers.com' target='_blank'>Blog</a></li>");
				jQuery(".page-list").append("<li class='page_item'><a href='<?php echo $Xml_Link; ?>' target='_blank'>XML Sitemap</a></li>");
				jQuery(".page-list").append("<li class='page_item'><a href='<?php echo $News_Link; ?>' target='_blank'>News Sitemap</a></li>");
		<?php
}
if (isset($Post_type) && $Post_type != 'domain' && !is_single()) {
		?>
				jQuery("#sfsi-widget-2").css("display", "none");
<?php } ?>
		jQuery(".byline").hide();
</script>
<?php
if (is_user_logged_in()) {
		$Cuid = get_current_user_id();
		$user_info = get_userdata($Cuid);
		$name = $user_info->user_login;
		$usr = explode("-", $name);
		if (trim($usr[0]) == 'Facebook') {
				$Usr_login = $user_info->display_name;
				$Usr_Email = $user_info->user_email;
				?>
				<style type="text/css">#wpadminbar { display:none; }</style>
				<?php
		}
		?>
		<script type="text/javascript">
			<?php if(isset($Usr_login)){ ?>
				jQuery("#your-name").val('<?php echo trim($Usr_login); ?>');
			<?php } ?>
			<?php if(isset($Usr_Email)){ ?>	
				jQuery("#your-email").val('<?php echo $Usr_Email; ?>');
			<?php } ?>
		</script>
		<?php } ?>
<?php
$Post_type = get_post_type();
if ($Post_type == 'domain') {
		$ID = get_the_ID();
//$Domain_Price = get_post_meta($post->ID, 'domain_price', true);
		$Domain_Price1 = get_post_meta($post->ID, 'domain_price', true);
		$IncressPrice = ($Domain_Price1 * WrongPriceOne / 100);
		$Domain_Price = round($Domain_Price1 + $IncressPrice);
//echo $Domain_Price."asdsadadasdad";exit;
		if ($Domain_Price == '-' || $Domain_Price == '0' || $Domain_Price == '') {
				$Domain_Price = '';
		} else {
				$Domain_Price = $Domain_Price;
		}
		$Domain_title = get_the_title();
		?>
		<script type="text/javascript">
		<?php
		if (is_single()) {
				?>
						jQuery("#menu-item-23").addClass('current-menu-item');
				<?php
		}


		$rw = $wpdb->get_row($wpdb->prepare("select ID from Dmb_posts where post_title='%s' and post_status='publish'",$Domain_title));
		if(isset($rw->ID) && !empty($rw->ID)){
			$Domain_Owner_Date = get_post_meta($rw->ID, 'Domain_owner', true);	
		}else{
			$Domain_Owner_Date = "";
		}

		if ($Domain_Owner_Date != '') {
				$Domain_Owner_EmailID = $wpdb->get_row($wpdb->prepare("SELECT varEmail, varName FROM Dmb_Client_DomainInfo_New WHERE varName LIKE %s",'%' . $wpdb->esc_like($Domain_Owner_Date) . '%'));
				$Client_Email_Form = $Domain_Owner_EmailID->varEmail;
				$Client_Email_Form_Name = $Domain_Owner_EmailID->varName;
		} else {
				$Domain_Owner_EmailID = $wpdb->get_row($wpdb->prepare("select varEmail,varName from Dmb_Client_DomainInfo_New where varName LIKE %s",'Domain Brothers'));
				$Client_Email_Form = $Domain_Owner_EmailID->varEmail;
				$Client_Email_Form_Name = $Domain_Owner_EmailID->varName;
		}
		
		$DomainDataAllMonth = $wpdb->get_row($wpdb->prepare("SELECT sum(intPageHits) as Totalhits, sum(intInquiry) as TotalInquiry, max(intHighInquiry) as Maxinquiry FROM Dmb_hitDomain_final WHERE intPostID = %d",$rw->ID));
		$Detail_hits = $wpdb->get_row($wpdb->prepare("SELECT sum(intPageHits) as D_Hits 
         FROM Dmb_hitDomain_final  
         WHERE chrFlag = 'D' AND intPostID = %d 
         GROUP BY intPostID",
         $rw->ID));
		$Landing_hits = $wpdb->get_row($wpdb->prepare("SELECT sum(intPageHits) as L_Hits 
         FROM Dmb_hitDomain_final 
         WHERE chrFlag = 'L' AND intPostID = %d 
         GROUP BY intPostID",
        $rw->ID));
		$start_date = date('Y-m-d');

		if ((isset($_REQUEST["r"]) && $_REQUEST["r"] == 'c81e728d9d4c2f636f067f89cc14862c') && $Domain_Price != '') {
				$Rate_Price = $Domain_Price * WrongPriceOne / 100;
				$Month_Price = $Domain_Price + $Rate_Price;

				if ($Domain_Price <= 2000) {
						$Month = 6;
				} else if ($Domain_Price >= 2000 && $Domain_Price <= 5000) {
						$Month = 8;
				} else if ($Domain_Price >= 5000 && $Domain_Price <= 8000) {
						$Month = 10;
				} else if ($Domain_Price >= 8000) {
						$Month = 12;
				}
				$DMB_PRC = round($Domain_Price / $Month);
				?>
						jQuery("#number-99").val('$<?php echo $Domain_Price; ?> (<?php echo $Month; ?> Months Installment)');
						jQuery("#Offer_Price").html('Your Offer: $<?php echo $Domain_Price; ?> with <?php echo $Month; ?> Monthly Payments ($<?php echo $DMB_PRC; ?> Per Month)');
						jQuery('#number-99').attr('readonly', 'true');
						jQuery('#domain_owner').val('<?php echo $Client_Email_Form_Name; ?>');
						jQuery('#domain_owner_EmailID').val('<?php echo $Client_Email_Form; ?>');
						jQuery('#domain_Post_Date').val('<?php echo the_date(); ?>');
						jQuery('#domain_Hits_AllMonth_User').val('<?php echo $DomainDataAllMonth->Totalhits; ?>');
						jQuery('#domain_Hits_AllMonth').val('<?php echo $DomainDataAllMonth->Totalhits . " (Landing: " . $Landing_hits->L_Hits . ", Detail: " . $Detail_hits->D_Hits . ")"; ?>');
						jQuery('#domain_Hits_TotalInq').val('<?php echo $DomainDataAllMonth->TotalInquiry; ?>');
						jQuery('#domain_Hits_MaxInq').val('<?php echo $DomainDataAllMonth->Maxinquiry; ?>');
		<?php } else if ((isset($_REQUEST["r"]) && $_REQUEST["r"] == 'c4ca4238a0b923820dcc509a6f75849b') && $Domain_Price != '') {
				?>
						jQuery("#number-99").val('Buy Now Price: $<?php echo $Domain_Price1; ?>');
						jQuery('#number-99').attr('readonly', 'true'); // mark it as read only
						jQuery('#domain_owner').val('<?php echo $Client_Email_Form_Name; ?>');
						jQuery('#domain_owner_EmailID').val('<?php echo $Client_Email_Form; ?>');
						jQuery('#domain_Post_Date').val('<?php echo the_date(); ?>');
						jQuery('#domain_Hits_AllMonth_User').val('<?php echo $DomainDataAllMonth->Totalhits; ?>');
						jQuery('#domain_Hits_AllMonth').val('<?php echo $DomainDataAllMonth->Totalhits . " (Landing: " . @$Landing_hits->L_Hits . ", Detail: " . @$Detail_hits->D_Hits . ")"; ?>');
						jQuery('#domain_Hits_TotalInq').val('<?php echo $DomainDataAllMonth->TotalInquiry; ?>');
						jQuery('#domain_Hits_MaxInq').val('<?php echo $DomainDataAllMonth->Maxinquiry; ?>');
		<?php } else if (isset($_REQUEST["c"]) && $_REQUEST["c"] == 'eccbc87e4b5ce2fe28308fd9f2a7baf3') { ?>
						jQuery("#number-99").val('');
						jQuery(".wpcf7-submit").val('Make an Offer');
						jQuery('#domain_owner').val('<?php echo $Client_Email_Form_Name; ?>');
						jQuery('#domain_owner_EmailID').val('<?php echo $Client_Email_Form; ?>');
						jQuery('#domain_Post_Date').val('<?php echo the_date(); ?>');
						jQuery('#domain_Hits_AllMonth_User').val('<?php echo $DomainDataAllMonth->Totalhits; ?>');
						jQuery('#domain_Hits_AllMonth').val('<?php echo $DomainDataAllMonth->Totalhits . " (Landing: " . (isset($Landing_hits->L_Hits) ? $Landing_hits->L_Hits:"0") . ", Detail: " . $Detail_hits->D_Hits . ")"; ?>');
						jQuery('#domain_Hits_TotalInq').val('<?php echo $DomainDataAllMonth->TotalInquiry; ?>');
						jQuery('#domain_Hits_MaxInq').val('<?php echo $DomainDataAllMonth->Maxinquiry; ?>');
				<?php
		} else {
				?>
						jQuery("#number-99").keyup(function () {
								var offer_val = jQuery("#number-99").val();
								if (offer_val <= 0) {
										alert("Please enter correct offer price.");
										jQuery("#number-99").val('');
										jQuery("#number-99").val('$<?php echo $Domain_Price; ?>');
										//                            jQuery( "#number-99" ).focus();
										return false;
								}
						});
						jQuery("#number-99").val('$<?php echo $Domain_Price; ?>');
				<?php
		}
		if (is_single()) {
				?>
						//            alert("dasda");
						jQuery(".single-domain").addClass("white-bg");
						var name = jQuery("#your-name").val();
						var Email = jQuery("#your-email").val();
						var Offer = jQuery("#number-99").val();
						if (name != '' && Email != '' && Offer != '') {
								jQuery(".auto-fill-btn").hide();
						}
						jQuery("#custom-background_inner").removeClass("custom-background");

						jQuery("#hide_domainname").val('<?php echo $Domain_title; ?>');
		<?php }
		?>

				//        jQuery("#your-message").val('I am interested in <?php echo $Domain_title; ?> and my offer is listed above. Feel free to call or email me to discuss this further.');
		</script>
<?php } ?>

<script>
//    jQuery("#cat-more").click(function () {
//        jQuery(".more-ctg").addClass("show-all-ctg");
//    });
//    jQuery(".less-ctg").click(function () {
//        jQuery(".more-ctg").removeClass("show-all-ctg");
//    });

		jQuery("LINK[href*='https://gmpg.org/xfn/11']").remove();
		jQuery("LINK[href='https://gmpg.org/xfn/11']").remove();

//    jQuery(".optinHeadline").empty();


</script>
<script>
		function showcategory(id) {
				jQuery("#more-ctg-" + id).addClass("show-all-ctg");
		}
		function lesscategory(id) {
				jQuery("#more-ctg-" + id).removeClass("show-all-ctg");
		}
</script>
<?php $DomainName = (isset($_REQUEST['dmb'])) ? base64_decode($_REQUEST['dmb']) : ""; ?>
<script type="text/javascript">
<?php if ($DomainName == '') { ?>
				jQuery('#offer-doamin-name').val("<?php echo 'N/A'; ?>");
<?php } else { ?>
				jQuery('#offer-doamin-name').val("<?php echo $DomainName; ?>");
<?php } ?>
</script>
<?php if ($Page_Id == 16989 && $DomainName != '') { ?>
		<script type="text/javascript">
				jQuery('.entry-title').html("Make a custom offer for <?php echo ucfirst($DomainName); ?>");
		</script>
<?php } ?>
<?php if ($Page_Id == 11) { ?>
	<script>
			jQuery(document).ready(function () {
					jQuery('.home-slider.owl-carousel').owlCarousel({
							loop: false,
							dots: false,
							nav: true,
							autoplay: false,
							margin: 10,
							responsiveClass: true,
							responsive: {
									0: {
											items: 2
									},
									480: {
											items: 3
									},
									1200: {
											items: 4
									},
									1366: {
											items: 5
									}
							}
					})
					jQuery('.owl-prev').html('<i class="fa fa-arrow-left" aria-hidden="true" title="Previous"></i>');
					jQuery('.owl-next').html('<i class="fa fa-arrow-right" aria-hidden="true" title="Next"></i>');
			})
	</script>
<?php } ?>
<?php

if (!function_exists('mround')) {
	function mround($val, $f = 2, $d = 6) {
			return sprintf("%" . $d . "." . $f . "f", $val);
	}
}

if (!function_exists('remove_space')) {
	function remove_space($value) {
			return str_replace(' ', '', $value);
	}
}

$DomainNamePayment = (isset($_REQUEST['d'])) ? base64_decode($_REQUEST['d']) : NULL;
$DomainMonthPrice = (isset($_REQUEST['m'])) ? base64_decode($_REQUEST['m']) : 0;
$DomainInstallmentMonth = (isset($_REQUEST['i'])) ? base64_decode($_REQUEST['i']) : NULL;
if(isset($_REQUEST['p'])){
	$temp_request_price = str_replace("$", "", base64_decode($_REQUEST['p']));
	$GSTPrice = $temp_request_price * GST_Rate / 100;	
}else{
	$GSTPrice = 0;
}


if (@$_REQUEST["m"] != '' && @$_REQUEST["i"] != '') {
		$paymentType = $DomainMonthPrice . "Monthly Installments";
		$ReferenceNumber = base64_decode($_REQUEST['rn']);
		$GetGST = base64_decode($_REQUEST['p']);
		$RemoveDollerGST = explode("$", $GetGST);
		$DomainPrice = $RemoveDollerGST[1];
		$GSTPrice = $RemoveDollerGST[1] * GST_Rate / 100;
		if ($DomainMonthPrice == 9) {
				$InstRate = FIVE;
				$InstrestRateParDomain = $RemoveDollerGST[1] * $InstRate / 100;
				$ShowRate = "$" . remove_space(mround($InstrestRateParDomain)) . "";
				$InstrestRateParDomain1 = $InstrestRateParDomain;
		} elseif ($DomainMonthPrice == 12) {
				$InstRate = TEN;
				$InstrestRateParDomain = $RemoveDollerGST[1] * $InstRate / 100;
				$ShowRate = "$" . remove_space(mround($InstrestRateParDomain)) . "";
				$InstrestRateParDomain1 = $InstrestRateParDomain;
		} else {
				$InstRate = '(0%)';
				$ShowRate = '0%';
				$InstrestRateParDomain1 = 0;
		}
		if ($ShowRate == '0%') {
				$IntTextShow = "";
		} else {
				$IntTextShow = "<div class=\"gst_main\"><span class=\"gst_name\">Interest:</span><span class=\"interest_price\"> " . $ShowRate . " <span class=\"interest_small\"> (" . $InstRate . "%)</span></span></div>";
		}
		$TotalDomainsOfInt = $GSTPrice + $InstrestRateParDomain1 + $DomainPrice;
		$DomainFianlPayment = $TotalDomainsOfInt / $DomainMonthPrice;
		$DueAmountFinal = $TotalDomainsOfInt - $DomainFianlPayment;
		echo "<script>jQuery( '.DomainPrice' ).after( '<div class=\"gst_main\"><span class=\"gst_name\"> GST/HST:</span><span class=\"gst_price\"> $" . remove_space(mround($GSTPrice)) . " <span class=\"interest_small\">(" . GST_Rate . "%) </span></span></div>"
		. $IntTextShow
		. "<div class=\"gst_main\"><span class=\"gst_name\"><img src=\"" . get_site_url() . "/wp-content/themes/DomainFolio/assets/images/price_ic.png\"></span><span class=\"inc_price\"> $" . remove_space(mround($TotalDomainsOfInt)) . "<span class=\"incall\"> (Inc. All)</span></span></div>"
		. "<hr><div class=\"gst_month_details\"><i class=\"fa fa-long-arrow-right\"></i>Number of Payments: " . $DomainMonthPrice . "</div>"
		. "<div class=\"gst_month_details\"><i class=\"fa fa-long-arrow-right\"></i>First Payment: $" . remove_space(mround($DomainFianlPayment)) . "</div>"
		. "<div class=\"gst_month_details\"><i class=\"fa fa-long-arrow-right\"></i>Remaining Payment: $" . remove_space(mround($DueAmountFinal)) . "</div> ' );</script>";
		$GSTRate = $DomainPrice;
} else {
		$paymentType = "Buy Now";
		$DomainPrice = (isset($_REQUEST['p'])) ? str_replace("$", "", base64_decode($_REQUEST['p'])) : 0;
		$ReferenceNumber = (isset($_REQUEST['rn'])) ? base64_decode($_REQUEST['rn']) : "";
		$GSTRate = $DomainPrice + $GSTPrice;
}
$CheckOut = (isset($_REQUEST['c'])) ? base64_decode($_REQUEST['c']) : ""; 
?>
<?php if ($CheckOut == '') {?>
	<script type="text/javascript">
		jQuery(document).ready(function() {
			setTimeout(function() {
    	<?php if(isset($DomainNamePayment) && $DomainNamePayment!=""){ ?>
				jQuery('#DomainName').val("<?php echo ucfirst($DomainNamePayment); ?>");
			<?php } ?>
			
			    jQuery('#DomainPrice').val("$<?php echo remove_space(mround($DomainPrice)); ?>");
				jQuery('#DomainMonthPrice').val("<?php echo $DomainMonthPrice; ?>");
				jQuery('#DomainInstallmentsMonth').val("<?php echo $DomainInstallmentMonth; ?>");
				jQuery('#payment-type').val("<?php echo $paymentType; ?>");
				jQuery('#reference-number').val("<?php echo $ReferenceNumber; ?>");
				<?php if(isset($DomainNamePayment) && $DomainNamePayment!=""){ ?>
				jQuery('#hidden_domain_name').html("<?php echo ucfirst($DomainNamePayment); ?>");
				<?php } ?>
		<?php if ((isset($_REQUEST["m"]) && $_REQUEST["m"] != '') && (isset($_REQUEST["i"]) && $_REQUEST["i"] != '')) { ?>
						jQuery('#step2_text').text('This is a first payment of <?php echo ucfirst($DomainNamePayment); ?>. Immediate access to domain is available once payment is approved.');
						jQuery('#hidden_domain_name').prepend('<span class=\"fiest_inst\">First payment of </span>');
						jQuery('#hidden_domain_price').html("<?php echo "$" . remove_space(mround($DomainFianlPayment)); ?> <span class='incall'>(Inc. All)</span>");
						jQuery('#Total_Price_GST').html("<?php echo "$" . remove_space(mround($DomainFianlPayment)); ?>");
						jQuery(".hideinst").css("display", "none");
						jQuery(".excl_gst").css("display", "none");
						jQuery(".DomainPrice").addClass("hide_img");
						jQuery("#hidden_domain_name").addClass("intro-left");
						jQuery("#hidden_domain_price").addClass("intro-right");
						jQuery(".hide_img").prepend("<span class=\"bin_name_price\">BIN Price:</span> ");
						jQuery(".paypal_type").hide();
		<?php } else { ?>
						jQuery('#step2_text').text('One time payment. Immediate access to domain(s) is available once payment is approved.');
						jQuery('#hidden_domain_price').html("<?php echo "$" . remove_space(mround($DomainPrice)); ?>");
						jQuery('#Total_Price_GST').html("<?php echo "$" . remove_space(mround($GSTRate)); ?>");
		<?php } ?>
				jQuery('#hidden_GST_Rate').html("<?php echo "$" . remove_space(mround($GSTPrice)); ?>");

		<?php
		if ($Page_Id == '21865') {
				?>
						jQuery('#hd_price').val("<?php echo $GSTRate; ?>");
						jQuery('#hd_domain').val("<?php echo ucfirst($DomainNamePayment); ?>");
		<?php } ?>
	  	 }, 2000);
	  });
	</script>
<?php } ?>

<?php if ($CheckOut != '') {
		$DollerPrice = (isset($_REQUEST['p'])) ? base64_decode($_REQUEST['p']):"";
		$WithOutPrice = explode("$", $DollerPrice);
		if(isset($WithOutPrice[1])){
			$GSTPrice = $WithOutPrice[1] * GST_Rate / 100;
			$GSTRate = $WithOutPrice[1] + $GSTPrice;
		}else{
			$GSTPrice = 0;
			$GSTRate = 0;
		}
		
		$DomainName = (isset($_REQUEST['d'])) ? base64_decode($_REQUEST['d']):"";
		$DomainPrice = (isset($_REQUEST['p'])) ? base64_decode($_REQUEST['p']):"";
		$DomainID = (isset($_REQUEST['o'])) ? base64_decode($_REQUEST['o']):"";
		$SelectDomainBuy = $wpdb->get_row($wpdb->prepare("SELECT * FROM Dmb_payment_leads 
				WHERE VarDomain = %s AND VarDomainPrice = %s AND VarPaymentStatus = 'Pending' AND ID = %d",
        $DomainName,
        $DomainPrice,
        $DomainID));
		if(isset($SelectDomainBuy->VarPhone)){
			$PhoneNo = explode(" ", $SelectDomainBuy->VarPhone);	
		}else{
			$PhoneNo = "";
		}
		
		$DomainPrice1 = (isset($_REQUEST['p'])) ? base64_decode($_REQUEST['p']):"";
		?>
		<script>
				jQuery('#DomainName').val("<?php if(isset($DomainName) && !empty($DomainName)){ echo ucfirst($DomainName); } ?>");
				jQuery('#FullName').val("<?php if(isset($SelectDomainBuy->VarName)){echo $SelectDomainBuy->VarName;} ?>");
				jQuery('#EmailAddress').val("<?php if(isset($SelectDomainBuy->VarEmail)){echo $SelectDomainBuy->VarEmail;} ?>");
				jQuery('#contact-phone').val("<?php if(isset($PhoneNo[1])){echo $PhoneNo[1];} ?>");
				jQuery('#DomainPrice').val("<?php if(isset($SelectDomainBuy->VarDomainPrice)){echo $SelectDomainBuy->VarDomainPrice;} ?>");
				jQuery('#Total_Price_GST').html("<?php echo "$" . $GSTRate; ?>");
				jQuery('#hidden_domain_name').html("<?php if(isset($DomainNamePayment) && !empty($DomainNamePayment)){ echo ucfirst($DomainNamePayment);} ?>");
				jQuery('#hidden_GST_Rate').html("<?php echo "$" . $GSTPrice; ?>");
				jQuery('#hidden_domain_price').html("<?php echo $DomainPrice1; ?>");
		</script>
		<?php if (@$_REQUEST["m"] != '' && @$_REQUEST["i"] != '') { ?>
				<script>
						jQuery('#step2_text').text('This is a first payment of <?php if(isset($DomainNamePayment) && !empty($DomainNamePayment)){ echo ucfirst($DomainNamePayment);} ?>. Immediate access to domain is available once payment is approved.');
						jQuery('#hidden_domain_name').prepend('<span class=\"fiest_inst\">First payment of </span>');
						jQuery('#hidden_domain_price').html("<?php echo "$" . remove_space(mround($DomainFianlPayment)); ?> <span class='incall'>(Inc. All)</span>");
						jQuery('#Total_Price_GST').html("<?php echo "$" . remove_space(mround($DomainFianlPayment)); ?>");
						jQuery(".hideinst").css("display", "none");
						jQuery(".excl_gst").css("display", "none");
						jQuery(".DomainPrice").addClass("hide_img");
						jQuery("#hidden_domain_name").addClass("intro-left");
						jQuery("#hidden_domain_price").addClass("intro-right");
						jQuery(".hide_img").prepend("<span class=\"bin_name_price\">BIN Price:</span> ");
						jQuery(".paypal_type").hide();
				</script>
		<?php } else { ?>
				<script>
						jQuery('#step2_text').text('One time payment. Immediate access to domain(s) is available once payment is approved.');
						jQuery('#hidden_domain_price').html("<?php echo "$" . remove_space(mround($DomainPrice)); ?>");
						jQuery('#Total_Price_GST').html("<?php echo "$" . remove_space(mround($GSTRate)); ?>");
				</script>
				<?php
		}
}
?>

<?php
$DomainTransfer = isset($_REQUEST['dt']) && $_REQUEST['dt'] !== "" ? base64_decode($_REQUEST['dt']) : "";

if ($DomainTransfer !== '') {
	$DomainNameTransfer = base64_decode($_REQUEST['d']);
	?>
	<script>
		jQuery(document).ready(function() {
			const domainName = "<?php echo $DomainNameTransfer; ?>";
			const eppDomainNameField = jQuery('#EPPDomainName');

			// Set the domain name on initial load
			eppDomainNameField.val(domainName);

			// Use MutationObserver to detect if the value changes and reset it
			const observer = new MutationObserver(function() {
				if (eppDomainNameField.val() !== domainName) {
					eppDomainNameField.val(domainName);
				}
			});

			// Observe the input field for changes
			observer.observe(eppDomainNameField[0], { attributes: true, childList: true, subtree: true, characterData: true });
		});
	</script>
	<?php
}
?>

<?php
if ($Page_Id == '21720') {
//    $DomainCode = base64_decode($_REQUEST["d"]);
//    $result1 = $wpdb->get_row("SELECT * FROM Dmb_payment_leads WHERE VarDomain='$DomainCode'");
		?>
														<!--    <script>
																		jQuery('#Buyer_EmailID').val("<?php //echo $result1->VarEmail; ?>");
																		jQuery('#BuyerReceiptNo').val("<?php //echo $result1->varReceiptNo; ?>");
																		jQuery('#DomainEPPCode').val("<?php //echo $result1->varEPPCode; ?>");
																		jQuery('#BuyerName').val("<?php //echo $result1->VarName; ?>");
																		jQuery('#EPPDomainPrice').val("<?php //echo $result1->VarDomainPrice; ?>");
																</script>-->
		<?php
}
if ($Page_Id == '21865') {
		if (@$_REQUEST["i"] != '' && @$_REQUEST["m"] != '') {
				$decode_month = base64_decode($_REQUEST["m"]);
				$decode_price = base64_decode($_REQUEST["p"]);
				$gsttotal = remove_space(mround($GSTPrice / $decode_month));
				$intresttotal = remove_space(mround($InstrestRateParDomain1 / $decode_month));
				$Domain_total = remove_space(mround($DomainPrice / $decode_month));
				if ($decode_month == "9") {
						$intresttotal = remove_space(mround($DomainPrice * 5 / 100));
				} else if ($decode_month == "12") {
						$intresttotal = $DomainPrice * 10 / 100;
				} else {
						$intresttotal = "0";
				}
				$Final_rate_total = remove_space(mround($intresttotal / $decode_month));
				$Custom_Value = $DomainNamePayment . ":" . $Domain_total . ":recur:y:" . $gsttotal . ":" . $Final_rate_total;

				$Monthly_Payment = $Domain_total + $Final_rate_total + $gsttotal;
		} else {
				$Custom_Value = $DomainNamePayment;
		}
		?>
		<script type="text/javascript">
				jQuery(window).load(function () {
						jQuery('#pnp_monthly_payment').val('<?php if(isset($Monthly_Payment)){ echo $Monthly_Payment;}else{echo "0.00";} ?>');
						jQuery('#pnp_monthly_gst').val('<?php if(isset($gsttotal)){ echo $gsttotal;}else{echo "0.00";} ?>');
						jQuery('#pnp_monthly_rate').val('<?php if(isset($Final_rate_total)){echo $Final_rate_total;}else{echo "0.00";} ?>');
						jQuery('input[name=notify_url]').val('<?php echo get_site_url(); ?>/paypal-ipn/'); // need to upload and
				});
				//            jQuery(".paypal-button").prepend('<input name="custom" value="<?php echo $Custom_Value; ?>" type="hidden">');
		</script>
<?php } ?>

<?php
$DomainTransfer = (isset($_REQUEST['dt']) && $_REQUEST['dt'] !="") ? base64_decode($_REQUEST['dt']) : "";

if ($DomainTransfer != '') {
		$DomainNameTransfer = base64_decode($_REQUEST['d']);
		?>
		<script>
				jQuery('#EPPDomainName').val("<?php echo ucfirst($DomainNameTransfer); ?>");
		</script>
		<?php
}
if ($Page_Id == '21865') {
		?>
		<script type="text/javascript">


				//        jQuery(document).keydown(function(event) {
				//            if (event.keyCode == 123) { // Prevent F12
				//                return false;
				//            } else if (event.ctrlKey && event.shiftKey && event.keyCode == 73) { // Prevent Ctrl+Shift+I        
				//                return false;
				//            }
				//        });
				//        jQuery(document).on("contextmenu", function(e) {
				//            e.preventDefault();
				//        });


				for (var i = 1; i <= 12; i++) {
						if (i == 1) {
								monthSelected = "selected=selected";
						} else {
								monthSelected = "";
						}
						if (i <= 9) {
								var optionvalue = "0" + [i];
						} else {
								optionvalue = [i];
						}

						jQuery("#month-exp").append('<option value=' + optionvalue + ' ' + [monthSelected] + '>' + [i] + '</option>');
				}

				var year = (new Date).getFullYear();
				var yearone = year + 1;
				var plus = year + 10;
				var yearSelected = '';
				for (y = year; y <= plus; y++) {
						if (yearone == y) {
								yearSelected = "selected=selected";
						} else {
								yearSelected = "";
						}
						jQuery("#year-exp").append('<option value=' + [y] + ' ' + [yearSelected] + '>' + [y] + '</option>');
				}
		</script>

		<?php
}
$EPPDomainName = (isset($_REQUEST['epp'])) ? base64_decode($_REQUEST['epp']) : "";
$EPPCodeDomainName = (isset($_REQUEST['cd'])) ? base64_decode($_REQUEST['cd']) : "";
if ($EPPDomainName != '') {
		?>
		<script>
				jQuery('#EPPSupport').val('<?php echo ucfirst($EPPDomainName); ?>');
				jQuery('#EPPSupportCode').val('<?php echo $EPPCodeDomainName; ?>');
		</script>    
<?php } else { ?>
		<script>
				jQuery('#EPPSupport').val('N/A');
				jQuery('#EPPSupportCode').val('N/A');
		</script>    

<?php } ?>
</html>		