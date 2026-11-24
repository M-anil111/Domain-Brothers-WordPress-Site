<?php
/**
 * Template Name: Payment Plan Setup
 */
get_header();
$Site_URL = get_site_url();
if (@$_REQUEST['p'] != '') {
		$DomainNamePayment = base64_decode($_REQUEST['d']);
		$DomainPrice1 = base64_decode($_REQUEST['p']);
		$DomainPrice = (explode("$", $DomainPrice1));
		$FivePrice = ($DomainPrice[1] * FIVE / 100);
		$TenPrice = ($DomainPrice[1] * TEN / 100);
		$GSTPrice = ($DomainPrice[1] * GST_Rate / 100);
}
$SelectDomainBuy = $wpdb->get_row($wpdb->prepare("SELECT ID FROM Dmb_posts WHERE post_title = %s AND post_status = %s",
        $DomainNamePayment,
        'publish'));
$Domain_Price1 = get_post_meta($SelectDomainBuy->ID, 'domain_price', true);

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
?>
<script language="javascript">
		jQuery(document).ready(function () {
				radioOnChange();
		});
		function radioOnChange() {
				var term;
				term = 6;
				var price = <?php echo $DomainPrice[1]; ?>;
				var perMonth = price / term;
				perMonth = parseFloat(Math.round(perMonth * 100) / 100).toFixed(2);
				jQuery('#textPerMonth').html(perMonth);
				jQuery('#textPerMonth1').html('$' + perMonth);
				jQuery('#textPerMonth2').html('$' + perMonth);
				jQuery('#textNumMonths').html(term);
				jQuery('#textNumMonths1').html(term);
		}
		jQuery(window).load(function () {
				jQuery(".tabledomain_div tr").click(function () {
						var cid = jQuery(this).attr('id');
						var arr = cid.split('termList');
						var term;
						if (arr[1] == '') {
								term = 6;
						} else {
								term = (arr[1]);
						}
						if (jQuery(".tabledomain_div").find(".active").length > 0)
						{
								jQuery(".tabledomain_div tr input").attr("checked", false);
								jQuery(".tabledomain_div tr").removeClass("active");
						}

						jQuery("#" + cid + " input").attr("checked", "checked");
						jQuery("#" + cid).addClass("active");

						var price = <?php echo $DomainPrice[1]; ?>;
						var perMonth = price / term;
						perMonth = parseFloat(Math.round(perMonth * 100) / 100).toFixed(2);
						jQuery('#textPerMonth').html(perMonth);
						jQuery('#textPerMonth1').html('$' + perMonth);
						jQuery('#textPerMonth2').html('$' + perMonth);
						jQuery('#textNumMonths').html(term);
						jQuery('#textNumMonths1').html(term);

				});
		});
		function FormSubmit() {

				var Month = document.getElementById("textNumMonths").innerHTML;
				var Price = document.getElementById("textPerMonth").innerHTML;
				var Appendurl = '';
<?php if (@$_REQUEST['c'] != '') { ?>
						Appendurl = '&c=<?php echo $_REQUEST['c']; ?>' + '&o=<?php echo $_REQUEST['o']; ?>';
<?php } else { ?>
						Appendurl = '';
<?php } ?>
<?php if (@$_REQUEST['rn'] != '') { ?>
						Appendurl = Appendurl + '&rn=<?php echo $_REQUEST['rn']; ?>';
<?php } else { ?>
						Appendurl = Appendurl;
<?php } ?>

				window.location.href = '<?php echo $Site_URL ?>/buy-now/' + '?d=<?php echo @$_REQUEST['d'] ?>' + '&p=<?php echo $_REQUEST['p']; ?>' + '&m=' + btoa(Month) + '&i=' + btoa(Price) + Appendurl;
		}
		function checkpayment(type) {
				if (type == 'P') {
						jQuery(".payfull_div").addClass("active");
						jQuery(".payless_div").removeClass("active");
				} else if (type == 'I') {
						jQuery(".payless_div").addClass("active");
						jQuery(".payfull_div").removeClass("active");
				}
		}
		function buynow() {
			<?php if (@$_REQUEST['rn'] != '') { ?>
							window.location.href = "<?php echo $Site_URL ?>/buy-now/?d=<?php echo @$_REQUEST['d']; ?>&p=<?php echo base64_encode($Domain_Price1); ?>&rn=<?php echo @$_REQUEST['rn']; ?>";
			<?php } else { ?>
							window.location.href = "<?php echo $Site_URL ?>/buy-now/?d=<?php echo $_REQUEST['d']; ?>&p=<?php echo base64_encode($Domain_Price1); ?>";
			<?php } ?>
		}
</script>

<div class="header_billing">
		<div class="container">
				<div class="row">
						<div class="col-md-6 col-sm-6">
								<div class="inner_billing_logo">
										<a href="<?php echo $Site_URL; ?>"><img src="<?php echo $Site_URL; ?>/wp-content/uploads/2017/03/Domain-Brothers.png" alt="Domain Brother"/></a>
								</div>
						</div>
						<div class="col-md-6 col-sm-6">
								<div class="ques_title">
										<h1>
												Questions? We're here to help! Email today:
												<a href="mailto:Sales@DomainBrothers.com">Sales@DomainBrothers.com</a>
										</h1>
								</div>
						</div>
				</div>
		</div>
</div>
<div class="billing_info_div">
		<div class="container-fluid">
				<div class="row">
						<div class="billing_info_menu">
								<div class="col-md-12">
										<div id="order-standard_cart" class="instfour">
												<ul class="progressbar">
														<li class="active"><span class="Payment_mobile">Payment</span> Plan</li>
														<li>Billing <span class="Payment_mobile">Information</span></li>
														<li>Order <span class="Payment_mobile">Confirmation</span></li>
														<li>Payment</li>
												</ul>
										</div>
								</div>
						</div>
				</div>
		</div>
		<div class="clearfix"></div>
		<div class="billing_cnt container_all">
				<div class="billing_form_min">
						<div id="primary_info" class="content-area card-img">
								<main id="main" class="site-main">
										<div class="row" id="paypal_section">
												<div class="main-inst">
														<div class="col-sm-5 col-xs-12 pull-right">
																<div class="installment_main">
																		<div class="orderinfo_cnt">
																				<h6>Your Order Summary - 1 item</h6>
																		</div>
																		<div class="dom_title pad_r"><span class="ic_img"><img src="<?php echo $Site_URL ?>/wp-content/themes/DomainFolio/assets/images/12domain_ic.png"></span><span><?php echo ucfirst($DomainNamePayment); ?></span>
																				<?php
																				$DomainName = explode(".", $DomainNamePayment);
																				?>
																				<a class="close_ic" href="<?php echo $Site_URL . '/domains/' . $DomainName[0] . "-" . $DomainName[1] . "/?lis=y" ?>"><span><i class="fa fa-times"></i></span></a>
																		</div>
																		<div class="dom_title"><span class="ic_img"><img src="<?php echo $Site_URL ?>/wp-content/themes/DomainFolio/assets/images/price_ic.png"></span><span class="b_span"><?php echo $DomainPrice1; ?></span> <span class="">(Excl. GST)</span></div>
																</div>
																<div class="benefits_inst">
																		<div>Benefits of buying a domain through a payment plan:</div>
																		<ul>
																				<li><i class="fa fa-long-arrow-right"></i>You will have full control and full access to the domain name</li>
																				<li><i class="fa fa-long-arrow-right"></i>Pay no interest - zero finance charges</li>
																				<li><i class="fa fa-long-arrow-right"></i>No obligation and no contract - cancel anytime for any reason!</li>
																		</ul>
																</div>
														</div>
														<div class="col-sm-7 col-xs-12 pull-left padRight">
																<div class="payment_plansetup_div">
																		<table class="tabledomain_div radiocss main_inst_radio">
																				<div class="main_inst_radio">
																						<div class="payfull_div">
																								<label for="payfull_id">
																										<input type="radio" name="PayFull" value="Full" id="payfull_id" onclick="checkpayment('P');
																														buynow();">
																										<span class="radio_payfull"></span><span class="payin_title">Pay in full </span>
																										<span class="price_d"><?php echo "$" . remove_space(mround($Domain_Price1)); ?></span>
																								</label>
																						</div>
																						<div class="payless_div active">
																								<label for="payless_id">
																										<input type="radio" name="PayFull" value="Less" id="payless_id" checked onclick="checkpayment('I');">
																										<span class="radio_payfull"></span>
																										<div class="payless-tit">
																												<span class="payless_title">Pay less up front</span>
																												<b id="textPerMonth1"></b>
																										</div>
																										<div class="monthly_div">
																												<span>Monthly:</span> <b id="textPerMonth2"></b> (<b id="textNumMonths1"></b> Payments)<span class="now_title">Now</span>
																										</div>
																								</label>
																						</div>
																				</div>
																				<h6>
																						<p>Own this domain for as low as <b class="doller_price" >$</b><b id="textPerMonth"></b> per month for <b id="textNumMonths"></b> Months</p>
																				</h6>
																				<tr>
																						<th colspan="2">Term Length</th>
																						<th style="text-align: center">Interest Rate</th>
																						<th style="text-align: center">Payment</th>
																				</tr>
																				<tr id="termList3">
																						<td>
																								<label for="radio_1" class="radio_label"><input type="radio" name="termLength" id="termLength3" value="3">
																										<span class="radio_payfull"></span>
																								</label>
																						</td>
																						<td>3 Months</td>
																						<td>-</td>
																						<td><?php
																								$Price3 = $DomainPrice[1] / 3;
																								echo "$" . remove_space(mround($Price3));
																								?>
																						</td>
																				</tr>
																				<tr id="termList6" class="active">
																						<td>
																								<label for="radio_1" class="radio_label"><input type="radio" name="termLength" id="termLength6" value="6" checked="checked">
																										<span class="radio_payfull"></span>
																								</label>
																						</td>
																						<td>6 Months</td>
																						<td>-</td>
																						<td><?php
																								$Price6 = $DomainPrice[1] / 6;
																								echo "$" . remove_space(mround($Price6));
																								?>
																						</td>
																				</tr>

																				<tr id="termList9">
																						<td>
																								<label for="radio_1" class="radio_label"><input type="radio" name="termLength" id="termLength9" value="9" >
																										<span class="radio_payfull"></span>
																								</label>
																						</td>
																						<td>9 Months</td>
																						<td><?php echo "$" . remove_space(mround($FivePrice)) . " (" . (FIVE) . "%)" ?></td>
																						<td><?php
																								$Price9 = $DomainPrice[1] / 9;
																								echo "$" . remove_space(mround($Price9));
																								?>
																						</td>
																				</tr>

																				<tr id="termList12" >
																						<td>
																								<label for="radio_1" class="radio_label"><input type="radio" name="termLength" id="termLength12"  value="12" >
																										<span class="radio_payfull"></span>
																								</label>
																						</td>
																						<td>12 Months</td>
																						<td><?php echo "$" . remove_space(mround($TenPrice)) . " (" . (TEN) . "%)" ?></td>
																						<td><?php
																								$Price12 = $DomainPrice[1] / 12;
																								echo "$" . remove_space(mround($Price12));
																								?>
																						</td>
																				</tr>
																		</table>
																		<div class="payinfull_div">
																				<div class="psp_submit_btns">
																						<div class="stp-submit">
																								<label for="pnp_submit"><i class="fa fa-lock"></i></label>

																								<input class="pnp_submits" type="button" value="Buy Domain With The Payment Plan" onclick="FormSubmit();"/>
																						</div>
																				</div>
																		</div>
																		<div class="terms_conditions_radio step">
																				<label>
																						<!--<span class="item_label_img"><img src="<?php echo $Site_URL ?>/wp-content/themes/DomainFolio/assets/images/slid_ic_hover.png"></span>-->
																						<!--<span class="item_label_cnt">We guarantee 100% privacy. Your information will not be shared.</span>-->
																						<span class="item_label_cnt">We 100% guarantee this transaction.</span>
																				</label>
																		</div>
																</div>
														</div>
												</div>
										</div>
								</main>
						</div>
						<?php /* Next steps, support, trust strip + CTA: rendered by db_journey_section_html() in the Domain Brothers Custom Blocks plugin (db-plan-lifecycle-block.php). */ if ( function_exists( 'db_journey_section_html' ) ) { echo db_journey_section_html(); // phpcs:ignore -- static, escaped markup
						} ?>
				</div>
		</div>
</div>
<link href="<?php echo $Site_URL ?>/wp-content/themes/DomainFolio/assets/css/intlTelInput.css" rel="stylesheet">
<script src="<?php echo $Site_URL ?>/wp-content/themes/DomainFolio/assets/js/intlTelInput.js"></script>
<?php
get_footer();
/*wp_reset_query();
/*get_footer();
/* Restore original Post Data */
/*wp_reset_postdata();
/*get_footer();
*
*/