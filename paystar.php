<?php if(!defined('ABSPATH'))exit;

/*
Plugin Name: paystar-payment-for-rcp
Plugin URI: https://paystar.ir
Description: paystar-payment-for-rcp-desc
Version: 1.0
Author: ماژول بانک
Author URI: https://www.modulebank.ir
Text Domain: paystar-payment-for-rcp
Domain Path: /languages
 */


function rcp_paystar_init()
{
	load_plugin_textdomain('paystar-payment-for-rcp', false, basename(dirname(__FILE__)) . '/languages');
	if ( !class_exists( 'RCP_Payment_Gateway' ) ) return;
	__('paystar-payment-for-rcp', 'paystar-payment-for-rcp');
	__('paystar-payment-for-rcp-desc', 'paystar-payment-for-rcp');
	function show_rcp_paystar_verify_msg($content)
	{
		if (!isset($_GET['rcp_paystar_verify_msg'])) return $content;
		$data = get_option('paystar_rcp_' . sanitize_text_field($_GET['rcp_paystar_verify_msg']));
		if (!$data) return $content;
		delete_option('paystar_rcp_' . sanitize_text_field($_GET['rcp_paystar_verify_msg']));
		return $content.$data;
		return '<br /><div class="box info-box">'.$data.'</div><br />'.$content;
	}
	add_action('the_content', 'show_rcp_paystar_verify_msg');
	class RCP_Payment_Gateway_PayStar extends RCP_Payment_Gateway
	{
		function process_signup()
		{
			global $rcp_options;
			$time = time();
			require_once(dirname(__FILE__) . '/paystar_payment_helper.class.php');
			echo '<div class="paystar-wc-wait" style="display:none; position:fixed; width:100%; height:100%; left:0; top:0; z-index:9999; opacity:0.90; -moz-opacity:0.90; filter:alpha(opacity=90); background-color:#fff;">
					<img src="' . esc_url(plugin_dir_url(__FILE__)) . 'images/wait.gif" style="position:fixed; left:50%; top:50%; width:466px; height:368px; margin:-184px 0 0 -233px;" />
				</div><script>jQuery(".paystar-wc-wait").fadeIn("fast");</script>';
			$p = new PayStar_Payment_Helper($rcp_options['paystar_terminal']);
			$r = $p->paymentRequest(array(
					'amount'      => intval(ceil($this->initial_amount)) * ($this->currency == 'IRT' ? 10 : 1),
					'order_id'    => $time,
					'description' => $this->subscription_name,
					'callback'    => add_query_arg(array('listener' => 'paystar-rcp', 'key' => $this->subscription_key, 'custom' => $this->user_id, 'sname' => urlencode($this->subscription_name)), home_url('index.php'))
				));
			if ($r)
			{
				$data = array('key' => $this->subscription_key, 'amount' => $this->initial_amount, 'currency' => $this->currency, 'user_id' => $this->user_id, 'sname' => $this->subscription_name);
				add_option('paystar_rcp_' . $time, $data);
				update_option('paystar_rcp_' . $time, $data);
				session_write_close();
				echo '<form name="frmPayStarPayment" method="post" action="https://core.paystar.ir/api/pardakht/payment"><input type="hidden" name="token" value="'.esc_html($p->data->token).'" />';
				echo '<input class="paystar_btn btn button" type="submit" value="'.__('Pay', 'paystar-payment-for-rcp').'" /></form>';
				echo '<script>document.frmPayStarPayment.submit();</script>';
			}
			else
			{
				echo esc_html($p->error) . '<script>jQuery(".paystar-wc-wait").fadeOut("fast");</script>';
			}
			exit;
		}

		public function process_webhooks()
		{
			if (isset($_GET['listener'],$_POST['status'],$_POST['order_id'],$_POST['ref_num']) && sanitize_text_field($_GET['listener']) == 'paystar-rcp')
			{
				global $rcp_options, $wpdb, $rcp_payments_db_name;
				$post_status = sanitize_text_field($_POST['status']);
				$post_order_id = sanitize_text_field($_POST['order_id']);
				$post_ref_num = sanitize_text_field($_POST['ref_num']);
				$post_tracking_code = sanitize_text_field($_POST['tracking_code']);
				$data = get_option('paystar_rcp_' . $post_order_id);
				delete_option('paystar_rcp_' . $post_order_id);
				$user_id = intval($data['user_id']);
				$member = new RCP_Member($user_id);
				if(!($member && $member->ID > 0)) die( __('no member found', 'paystar-payment-for-rcp') );
				$subscription_id = $member->get_pending_subscription_id();
				if( empty( $subscription_id ) ) $subscription_id = $member->get_subscription_id();
				if( ! $subscription_id ) die( __('no subscription for member found', 'paystar-payment-for-rcp') );
				if( ! $x = rcp_get_subscription_details( $subscription_id ) ) die( __('no subscription level found', 'paystar-payment-for-rcp') );
				$pending_amount = get_user_meta( $member->ID, 'rcp_pending_subscription_amount', true );
				$amount = intval(ceil($data['amount'])) * ($data['currency'] == 'IRT' ? 10 : 1);
				require_once(dirname(__FILE__) . '/paystar_payment_helper.class.php');
				$p = new PayStar_Payment_Helper($rcp_options['paystar_terminal']);
				$r = $p->paymentVerify($x = array(
						'status' => $post_status,
						'order_id' => $post_order_id,
						'ref_num' => $post_ref_num,
						'tracking_code' => $post_tracking_code,
						'amount' => $amount
					));
				if ($r)
				{
					$has_trial            = false;
					$payment_status       = 'completed';
					$subscription_key     = $data['key'];
					$currency_code        = $this->currency?:rcp_get_currency();
					$pending_amount       = number_format( (float) $pending_amount, 2, '.', '' );
					$pending_payment_id   = $member->get_pending_payment_id();
					delete_user_meta( $member->ID, 'rcp_pending_subscription_amount' );
					$rcp_payments = new RCP_Payments();
					if($rcp_payments->payment_exists($p->txn_id)) die( __('duplicate Payment detected', 'paystar-payment-for-rcp') );
					$payment_data = array(
							'date'             => date( 'Y-m-d H:i:s', strtotime( 'now', current_time( 'timestamp' ) ) ),
							'subscription'     => $data['sname'],
							'payment_type'     => 'web_accept',
							'subscription_key' => $subscription_key,
							'amount'           => $pending_amount,
							'user_id'          => $user_id,
							'transaction_id'   => $p->txn_id,
							'status'           => 'complete'
						);
					if( $member->just_upgraded() && $member->can_cancel() )
					{
						$cancelled = $member->cancel_payment_profile( false );
						if( $cancelled )
						{
							$member->set_payment_profile_id( '' );
						}
					}
					if ( ! empty( $pending_payment_id ) )
					{
						$member->set_recurring( false );
						$rcp_payments->update( $pending_payment_id, $payment_data );
						$payment_id = $pending_payment_id;
					}
					else
					{
						$member->renew();
						$payment_id = $rcp_payments->insert( $payment_data );
					}
					update_option('paystar_rcp_' . $post_order_id, __('Payment Completed. RefNumber : ', 'paystar-payment-for-rcp') . $p->txn_id);
					header('location: '.add_query_arg(array('rcp_paystar_verify_msg' => $post_order_id), rcp_get_return_url($user_id)));
					exit;
				}
				else
				{
					$message = $p->error;
					update_option('paystar_rcp_' . $post_order_id, $message);
					header('location: '.add_query_arg(array('rcp_paystar_verify_msg' => $post_order_id), rcp_get_return_url()));
					//header('location: '.get_permalink( $rcp_options['registration_page'] ));
					echo esc_html($message);
					exit;
				}
			}
		}
	}
}
add_action('plugins_loaded', 'rcp_paystar_init', 666);

if (!function_exists('rcp_register_irt_currency'))
{
	function rcp_register_irt_currency( $currencies )
	{
		return array_merge(array('IRT' => __('Iranian Toman', 'paystar-payment-for-rcp')), $currencies);
	}
	add_filter('rcp_currencies', 'rcp_register_irt_currency');
	add_filter( 'rcp_is_zero_decimal_currency', function($r){
		if (in_array(strtoupper(rcp_get_currency()), array('IRR','IRT')))
			$r = true;
		return $r;
	}, 666 );
}

function rcp_register_paystar_payment_gateway($gateways)
{
	return array_merge(array('paystar' => array('label' => __('PayStar', 'paystar-payment-for-rcp'), 'admin_label' => __('PayStar', 'paystar-payment-for-rcp'), 'class' => 'RCP_Payment_Gateway_PayStar')),$gateways);
}
add_filter('rcp_payment_gateways', 'rcp_register_paystar_payment_gateway');

function rcp_show_paystar_setting($rcp_options)
{
	?>
	<hr/>
	<table class="form-table">
		<tr valign="top"><th colspan=2><h3> <?php _e('PayStar Gateway Setting', 'paystar-payment-for-rcp'); ?> </h3></th></tr>
		<tr>
			<th><label for="rcp_settings[paystar_terminal]"> <?php _e('PayStar Terminal', 'paystar-payment-for-rcp'); ?> </label></th>
			<td><input class="regular-text" id="rcp_settings[paystar_terminal]" style="width: 300px;" name="rcp_settings[paystar_terminal]" value="<?=@$rcp_options['paystar_terminal']?>"/></td>
		</tr>
	</table>
	<?php
}
add_action('rcp_payments_settings', 'rcp_show_paystar_setting');

?>