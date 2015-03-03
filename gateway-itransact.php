<?php
/*
Plugin Name: WooCommerce iTransact Payment Gateway
Version: 1.0.0
Plugin URI: http://www.adornconsultants.com/
Description: iTransact Payment Gateway Plugin for WooCommerce.
Author: Adorn
Author URI: http://www.adornconsultants.com/
*/

add_action( 'plugins_loaded', 'iTransact_init', 0 );

function iTransact_init()
{
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
	};
	
	DEFINE ('PLUGIN_DIR', plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) . '/' );
	
	class WC_iTransact extends WC_Payment_Gateway 
	{	
		var $notify_url;
		var $gateway_url;
		
		/**
		 * Constructor for the gateway.
		 *
		 * @access public
		 * @return void
		 */
		 public function __construct()
		 {
			global $woocommerce;
	
			$this->id			= 'itransact';
			$this->method_title = __( 'iTransact Bank', 'woocommerce' );
			$this->has_fields	 = false;
			$this->icon		 = apply_filters( 'woocommerce_techprocess_icon', $woocommerce->plugin_url() . '/assets/images/icons/itransact-logo.png' );
			$this->notify_url        = WC()->api_request_url( 'WC_iTransact' );
			$this->gateway_url        = 'https://secure.itransact.com/cgi-bin/mas/split.cgi';
			
			// Load the form fields.
			$this->init_form_fields();
				
			// Load the settings.
			$this->init_settings();
				
			// Define user set variables
			$this->title				 = $this->get_option('title');
			$this->description			 = $this->get_option('description');
			$this->vendor_id			 = $this->get_option('vendor_id');
	
			add_action('woocommerce_api_wc_techprocess', array($this, 'check_response' ) );
			add_action('woocommerce_receipt_techprocess', array(&$this, 'receipt_page'));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			
			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_itransact', array( $this, 'check_response' ) );
	
			if ( !$this->is_valid_for_use() ) $this->enabled = false;
		}
		
		function check_response()
		{			
			@ob_clean();
	
			$_POST = stripslashes_deep($_POST);
			$this->successful_request($_POST);
		}
		
		
		function successful_request( $posted )
		{
			global $woocommerce;
			
			$order_id_key=$posted['customerReference'];
			$order_id_key=explode("-",$order_id_key);
			$order_id=$order_id_key[0];
			$order_key=$order_id_key[1];
			$err=$posted['err'];
			$die=$posted['die'];
			$xid=$posted['xid'];
	
			$order = new WC_Order( $order_id );
		   
			if ( $order->order_key !== $order_key ) :
				echo 'Error: Order Key does not match invoice.';
				exit;
			endif;
	
			if ( $order->get_total() != $posted['total'] ) {
				echo 'Error: Amount not match.';
				$order->update_status( 'on-hold', sprintf( __( 'Validation error: Amounts do not match (%s).', 'woocommerce' ), $posted['amount'] ) );
				exit;
			}
	
			// if TXN is approved
			if($err=="" && $die=="")
			{
				// Payment completed
				$order->add_order_note( __('payment completed', 'woocommerce') );
	
				// Mark order complete
				$order->payment_complete();
	
				  // Empty cart and clear session
				$woocommerce->cart->empty_cart();
	
				// Redirect to thank you URL
				wp_redirect( $this->get_return_url( $order ) );
				exit;
			}
			else // TXN has declined
			{	   
				// Change the status to pending / unpaid
				$order->update_status('pending', __('Payment declined', 'woothemes'));
			   
				// Add a note with the IPG details on it
				$order->add_order_note(__('iTransact Payment Failed - Transaction Reference: ' . $xid . " - ResponseCode: " .$err, 'woocommerce')); // FAILURE NOTE
			   
				// Add error for the customer when we return back to the cart
				$woocommerce->add_error(__('TRANSACTION DECLINED: ', 'woothemes') . $err);
			   
				// Redirect back to the last step in the checkout process
				wp_redirect( $woocommerce->cart->get_checkout_url());
				exit;
			}
	
		}
	
		/**
		 * Check if this gateway is enabled and available in the user's country
		 *
		 * @access public
		 * @return bool
		 */
		function is_valid_for_use()
		{
			if ( ! in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_itransact_supported_currencies', array( 'USD', 'EUR', 'GBP' ) ) ) ) {
				return false;
			}
		
			return true;
		}
	
		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 *
		 * @since 1.0.0
		 */
		public function admin_options() {
			?>
			<h3><?php _e('iTransact', 'woocommerce'); ?></h3>	  
			<p><?php _e( 'Users will be redirected to iTransact to enter their payment information.', 'woocommerce' ); ?></p> 
			<table class="form-table">
			<?php
				if ( $this->is_valid_for_use() ) :
					// Generate the HTML For the settings form
					$this->generate_settings_html();
				else :
					?>
						<div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>: <?php _e( 'iTransact does not support your store currency.', 'woocommerce' ); ?></p></div>
					<?php
				endif;
			?>
			</table><!--/.form-table-->
			<p><?php _e( 'Plugin developed by <a href="http://www.adornconsultants.com/" target="_blank">Adorn</a>.', 'woocommerce' ); ?></p> 
			<?php
		}
	
		/**
		 * Initialise Gateway Settings Form Fields
		 *
		 * @access public
		 * @return void
		 */
		function init_form_fields()
		{
			global $woocommerce;
			$order = new WC_Order( $order_id );
			$this->form_fields = array(
				'enabled' => array
							(
								'title' => __( 'Enable/Disable', 'woocommerce' ),
								'type' => 'checkbox',
								'label' => __( 'Enable iTransact Bank', 'woocommerce' ),
								'default' => 'yes'
							),
				'title' => array
							(
								'title' => __( 'Title', 'woocommerce' ),
								'type' => 'text',
								'description' => __( 'This is the title the customer can see when checking out', 'woocommerce' ),
								'default' => __( 'iTransact Bank', 'woocommerce' )
							),
				'description' => array
							(
								'title' => __( 'Description', 'woocommerce' ),
								'type' => 'text',
								'description' => __( 'This is the description the customer can see when checking out', 'woocommerce' ),
								'default' => __("Pay with Credit Card via iTransact Bank", 'woocommerce')
							),
								
				'vendor_id' => array
							(
								'title' => __( 'Vendor ID', 'woocommerce' ),
								'type' => 'text',
								'description' => __( '<div style="text-align:left;">To obtain this,<br><ol><li>Login in to iTransact Gateway</li><li>Open Control Panel > Account Settings</li><li>Copy \'Order Form UID\' from Advanced Features</li></ol></div>', 'woocommerce' ),
								'default' => '',
								'desc_tip'	=>  true
							)	   
				);
		}
	
		/**
		 * Get techprocess Args
		 *
		 * @access public
		 * @param mixed $order
		 * @return array
		 */
		function get_iTransact_args( $order )
		{
			global $woocommerce;
	
			$order_id = $order->id;
			$data = array();			
			
			$data['passback'] = "customerReference";
			$data['customerReference'] = $order_id.'-'.$order->order_key;
			
			$data['email'] = $order->billing_email;
			$data['first_name'] = $order->billing_first_name;
			$data['last_name'] = $order->billing_last_name;
			$data['address'] = $order->billing_address_1." ".$order->billing_address_2;
			$data['city'] = $order->billing_city;
			$data['state'] = $order->billing_state;
			$data['zip'] = $order->billing_postcode;
			$data['country'] = $order->billing_country;
			$data['phone'] = $order->billing_phone;
			
			$data['sfname'] = $order->shipping_first_name;
			$data['slname'] = $order->shipping_last_name;
			$data['saddr'] = $order->shipping_address_1." ".$order->shipping_address_2;
			$data['scity'] = $order->shipping_city;
			$data['sstate'] = $order->shipping_state;
			$data['szip'] = $order->shipping_postcode;
			$data['sctry'] = $order->shipping_country;
			
			//$data['amount'] = number_format($order->get_total(), 2, '.', '');	
			
			$data['vendor_id'] = $this->vendor_id;
			$data['home_page'] = site_url();
			$data['ret_addr'] = $this->notify_url;
			
			$cnt=0;
			
			if ( sizeof( $order->get_items() ) > 0 ) {
				foreach ( $order->get_items() as $item ) {
					if ( $item['qty'] )
					{
						$cnt++;
						$pdtname=str_replace("<sup>&reg;</sup>","",$item['name']);
						
						$data['item_'.$cnt.'_desc'] = $pdtname;						
						$data['item_'.$cnt.'_qty'] = $item['qty'];
						$data['item_'.$cnt.'_cost'] =  number_format($order->get_item_subtotal( $item, false ), 2, '.', '' );
					}
				}
			}
			
			/*if ( ( $order->get_total_shipping() + $order->get_shipping_tax() ) > 0 )
			{
				$cnt++;
				
				$data['item_'.$cnt.'_desc'] = 'Shipping';						
				$data['item_'.$cnt.'_qty'] = 1;
				$data['item_'.$cnt.'_cost'] = number_format( ($order->get_total_shipping() + $order->get_shipping_tax()), 2, '.', '' );
			}*/
			
			if ( $order->get_total_shipping() > 0 )
			{
				$cnt++;
				
				$data['item_'.$cnt.'_desc'] = 'Shipping';						
				$data['item_'.$cnt.'_qty'] = 1;
				$data['item_'.$cnt.'_cost'] = number_format( $order->get_total_shipping() , 2, '.', '' );
			}
			
			if ( $order->get_total_tax() > 0 )
			{
				$cnt++;
				
				$data['item_'.$cnt.'_desc'] = 'Tax';						
				$data['item_'.$cnt.'_qty'] = 1;
				$data['item_'.$cnt.'_cost'] = number_format( $order->get_total_tax() , 2, '.', '' );
			}
			
			if ( $order->get_total_discount() > 0 )
			{
				$cnt++;
				
				$data['item_'.$cnt.'_desc'] = 'Discount';						
				$data['item_'.$cnt.'_qty'] = 1;
				$data['item_'.$cnt.'_cost'] = "-".number_format($order->get_total_discount, 2, '.', '' );
			}
			
			$data['showaddr'] = "1";
			$data['showcvv'] = "1";
			$data['show_items'] = "1";
			$data['mername'] = "Facial Flex";
			$data['acceptcards'] = "1";
			$data['acceptchecks'] = "0";
			$data['accepteft'] = "0";
			$data['altaddr'] = "0";
			$data['nonum'] = "1";
			$data['ret_mode'] = "post";
			
			$data['post_back_on_error'] = "1";
			$data['lookup'] = "xid";
			$data['lookup'] = "total";
			
			return $data;
		}
	
		/**
		 * Process the payment and return the result
		 *
		 * @access public
		 * @param int $order_id
		 * @return array
		 */
		function process_payment( $order_id )
		{
			$order = new WC_Order( $order_id );
			$iTransact_args = $this->get_iTransact_args( $order );		 
	
			$iTransact_args = http_build_query( $iTransact_args, '', '&' );
	
			$gateway_adr = $this->gateway_url . '?';
	
			return array(
				'result' 	=> 'success',
				'redirect'	=> $gateway_adr . $iTransact_args
			);
		}
	
		function receipt_page( $order )
		{
			echo '<p>'.__('Thank you for your order, click on submit to process iTransact payment.', 'woocommerce').'</p>';
			echo $this->generate_iTransact_form( $order );
		}
	
		function generate_iTransact_form( $order_id )
		{
			global $woocommerce;
			$order = new WC_Order( $order_id );
			$iTransact_args = $this->get_iTransact_args( $order );
			$woocommerce->add_inline_js('
	
			jQuery("body").block({
	
					message: "'.__('Thank you for your order. We are now redirecting you to iTransact to make payment.', 'woocommerce').'",
					overlayCSS:
					{
						background: "#fff",
						opacity: 0.6
					},
	
					css: {
						padding:		20,
						textAlign:	  "center",
						color:		  "#555",
						border:		 "3px solid #aaa",
						backgroundColor:"#fff",
						cursor:		 "wait",
						lineHeight:		"32px"
					}
				});
	
			jQuery("#submit_iTransact_payment_form").click();
		');
	
			$return='<form action="'.esc_url( $this->gateway_url ).'" method="post" id="iTransact_payment_form" target="_top">';
			foreach ($iTransact_args as $key => $value) {				
				$return .= '<input type="hidden" name="'.esc_attr( $key ).'" value="'.esc_attr( $value ).'" />';
			}
			$return .='<input type="submit" id="submit_iTransact_payment_form" value="submit"/></form>';
			return $return;
		}
	}
	
	function woocommerce_iTransact_add_gateway( $methods )
	{
		$methods[] = 'WC_itransact';
		return $methods;

	}

	add_filter( 'woocommerce_payment_gateways', 'woocommerce_iTransact_add_gateway' );
}
?>