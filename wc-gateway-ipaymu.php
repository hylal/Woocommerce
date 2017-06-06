<?php
/**
 * Plugin Name: WooCommerce iPaymu Payment Gateway
 * Plugin URI: http://masedi.net/wordpress/plugins/woocommerce-ipaymu-gateway.html
 * Description: WooCommerce iPaymu Payment Gateway is a fully-working Indonesia Payment Gateway (iPaymu) for WooCommerce Wordpress store. Tested on WordPress 3.5.1 and WooCommerce 2.0.3.
 * Author: MasEDI
 * Author URI: http://masedi.net/
 * Version: 2.0.0
 * License: GPLv2 or later
 * Text Domain: wcipaymu
 * Domain Path: /languages/
 **/

/**
 * Prevent from direct access.
 **/
if (! defined('ABSPATH')) exit('No direct script access allowed.');

/**
 * WooCommerce fallback notice.
 **/
function wc_gateway_ipaymu_fallback_notice() {
	$message = '<div class="error"><p>' . __( '<strong><a href="https://my.ipaymu.com?rid=masedi">WooCommerce Gateway iPaymu</a></strong> plugin depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!' , 'wcipaymu' ) . '</p></div>';
	echo $message;
}

/**
 * WooCommerce Gateway iPaymu initialize
 * Init gateway functions when plugin loaded.
 **/
function wc_gateway_ipaymu_init() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'wc_gateway_ipaymu_fallback_notice' );
		return;
	}
	
	/**
	 * Localization.
	 **/
	load_plugin_textdomain( 'wcipaymu', false, dirname(plugin_basename(__FILE__)) . '/languages/' );
	
	/**
	 * Installation.
	 */
	register_activation_hook( __FILE__, 'install_wcipaymu' );
	function install_wcipaymu() {
		// Do required, eg. database table creation, etc.
	}
	
	/**
	 * WC iPaymu Gateway Class.
	 *
	 * Built the iPaymu Gateway method.
	 **/
	class WC_Gateway_Ipaymu extends WC_Payment_Gateway 
	{
		/** @var bool Whether or not logging is enabled */
		public static $log_enabled = false;
	
		/** @var WC_Logger Logger instance */
		public static $log = false;
	
		/**
		* Gateway's Constructor.
		*
		* @return void
		**/
		public function __construct() {
			global $woocommerce;

			// Define gateway setting variables.
			$this->id				= 'wcipaymu';
			$this->icon				= plugins_url( 'images/ipaymu_button_co.png', __FILE__ );
			$this->has_fields		= false;
			$this->method_title		= __( 'iPaymu Checkout', 'wcipaymu' );
			
			// Load the form fields.
			$this->init_form_fields();

			// Load the settings.
			$this->init_settings();

			// Initialize user settings.
			$this->init();

			// Add actions.
			add_action( 'wc_gateway_ipaymu_valid_request', array( &$this, 'successful_request' ) );
			add_action( 'woocommerce_receipt_wcipaymu', array( &$this, 'receipt_page' ) );
			
			// For backward compatibility with WC v1.6.x
			if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '<' ) ) {
				// Save settings
				if ( is_admin() ) {
					add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
				}
				
				// iPaymu listener API hooks prior to WC v1.6.x
				add_action( 'init', array( &$this, 'check_gateway_response' ) );
			
			} // WC 2.0.x
			else {
				// Save settings
				if ( is_admin() ) {
					add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
				}
				
				// iPaymu listener API hooks since WC v2.0.x
				add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_gateway_response' ) );
			}
			
			add_action( 'woocommerce_after_checkout_form', array( &$this, 'masedi_credits_link' ) );
		}
		
		function init() {
			// Define user setting variables.
			$this->enabled			= $this->settings['enabled'];
			$this->title			= $this->settings['title'];
			$this->description		= $this->settings['description'];
			$this->ipaymu_username	= $this->settings['ipaymu_username'];
			$this->ipaymu_apikey	= $this->settings['ipaymu_apikey'];
			$this->paypal_enabled	= $this->settings['paypal_enabled'];
			$this->paypal_email		= $this->settings['paypal_email'];
			$this->invoice_prefix	= $this->settings['invoice_prefix'];
			$this->currency_rate	= $this->settings['currency_rate'];
			$this->credit_link		= $this->settings['credit_link'];
			$this->debug			= $this->settings['debug'];
			
			// This setting may not be changed.
			$this->gateway_url		= 'https://my.ipaymu.com/payment.htm';
			$this->gateway_trx_url	= 'https://my.ipaymu.com/api/CekTransaksi.php';
			$this->bca_kurs_url		= 'http://www.bca.co.id/E-Rate';
			$this->ipaymu_fee		= 0.01; // 1% of iPaymu transaction fee <!--do not change-->
			
			self::$log_enabled		= $this->debug;
			
			// Check if valid for use.
			if ( ! $this->is_valid_for_use() || empty( $this->ipaymu_apikey ) ) {
				$this->enabled = 'no';
			}
			
			// Check if api_key is not empty.
			if ( $this->ipaymu_apikey == '' ) {
				add_action( 'admin_notices', array( &$this, 'api_key_missing_message' ) );
			}
			
			// Check if SSL is disabled and notify the user.
			if ( get_option( 'woocommerce_force_ssl_checkout' ) == 'no' && $this->enabled == 'yes' ) {
				add_action( 'admin_notices', array( &$this, 'forcessl_missing_message' ) );
			}
			
			// Check if iPaymu PayPal module is enabled.
			if ( $this->paypal_enabled == 'yes' && $this->paypal_email == '' ) {
				add_action( 'admin_notices', array( &$this, 'paypal_email_missing_message' ) );
			}
			
		}
		
		/**
		 * Checking if this gateway is enabled and available in the user's country.
		 *
		 * @access public
		 * @return bool
		 **/
		function is_valid_for_use() {
			// add another supported currency here.
			$supported_currencies = array( 'IDR', 'USD' );
			
			if ( in_array( get_woocommerce_currency(), $supported_currencies ) ) {
				return true;
			}
		}
		
		/**
		 * Adds error message when not configured the api_key.
		 *
		 * @access public
		 * @return string
		 **/
		function api_key_missing_message() {
			$message = '<div class="error fade"><p>' . sprintf( __( '<strong>WooCommerce Gateway iPaymu</strong> is almost ready. Please enter <a href="%s">your iPaymu API Key</a> for it to work.' , 'wcipaymu' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wcipaymu#woocommerce_wcipaymu_ipaymu_apikey' ) ) . '</p></div>';
			echo $message;
		}

		/**
		 * Adds warning message when Force SSL is not configured.
		 *
		 * @access public
		 * @return string
		 **/
		function forcessl_missing_message() {
			$message = '<div class="notice notice-warning"><p>' .sprintf( __( '<strong>WooCommerce Gateway iPaymu</strong> is enabled and the <em><a href="%s">force SSL option</a></em> is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.', 'wcipaymu' ), admin_url( 'admin.php?page=wc-settings&tab=checkout#woocommerce_force_ssl_checkout' ) ) . '</p></div>';
			echo $message;
		}
		
		/**
		 * Adds error message when paypal module enabled but paypal email not set.
		 *
		 * @access public
		 * @return string
		 **/
		function paypal_email_missing_message() {
			$message = '<div class="error fade"><p>' . sprintf( __( '<strong>WooCommerce Gateway iPaymu</strong> PayPal module is enabled. Please enter <a href="%s">your PayPal email</a> for it to work.' , 'wcipaymu' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wcipaymu#woocommerce_wcipaymu_paypal_email' ) ) . '</p></div>';
			echo $message;
		}
		
		/**
		 * Add credit links to iPaymu Payment Gateway
		 * Thanks for supporting the development of this plugin by placing the credit links on yout shopping cart 
		 **/
		function masedi_credits_link() {
			//$wcipaymu_settings = get_option('woocommerce_' . $this->id . '_settings');
			if ( $this->credit_link == 'yes' ) {
				$credits = '<div align="center">' . get_bloginfo( 'name' ) . ' uses <strong><a href="https://masedi.net/product/woocommerce-extension-wc-gateway-ipaymu/" target="_blank" title="Download iPaymu Payment Gateway for WooCommerce">WooCommerce Gateway iPaymu plugin</a></strong> developed by <a href="https://masedi.net/" target="_blank" title="WooCommerce Developer">MasEDI.Net</a></div>';			
				echo $credits;
			}
		}
		
		/**
		 * Logging method.
		 * @param string $message
		 */
		public static function log( $message ) {
			if ( self::$log_enabled ) {
				if ( empty( self::$log ) ) {
					self::$log = new WC_Logger();
				}
				self::$log->add( 'wcipaymu', $message );
			}
		}

		/**
		 * Initialize Gateway Settings Form Fields.
		 *
		 * @access public
		 * @return void
		 **/
		public function init_form_fields() {
		
			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enable/Disable', 'wcipaymu'),
					'type' => 'checkbox',
					'label' => __('Enable iPaymu payment gateway.', 'wcipaymu'),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __('Gateway Title', 'wcipaymu'),
					'type' => 'text',
					'description' => __('Payment method title which the customer will see during checkout.', 'wcipaymu'),
					'default' => __('iPaymu', 'wcipaymu'),
					'desc_tip' => true
				),
				'description' => array(
					'title' => __('Description', 'wcipaymu'),
					'type' => 'textarea',
					'description' => __('Payment method description which the customer will see during checkout.', 'wcipaymu'),
					'default' => sprintf(__('Automatic Bank Transfer payment via iPaymu. Alternatively you can pay with your PayPal account instead of Ipaymu, you can also pay with credit card if you don\'t have iPaymu or PayPal account. Need an iPaymu Account? <a href="%s">Register here!</a>', 'wcipaymu'), 'https://my.ipaymu.com?rid=masedi'),
					'desc_tip' => true
				),
				'ipaymu_username' => array(
					'title' => __('iPaymu Username', 'wcipaymu'),
					'type' => 'text',
					'description' => __('Please enter your iPaymu Login ID (username).', 'wcipaymu') . ' ' . sprintf(__('If you don\'t have iPaymu account you can get one for free. <a href="%s" target="_blank">Register iPaymu account here</a>!', 'wcipaymu'), 'https://my.ipaymu.com?rid=masedi'),
					'default' => ''
				),
				'ipaymu_apikey' => array(
					'title' => __('iPaymu API Key', 'wcipaymu'),
					'type' => 'text',
					'description' => __('Please enter your iPaymu API Key.', 'wcipaymu') . ' ' . sprintf(__('You can to get this information in: <a href="%s" target="_blank">Ipaymu Account</a>.', 'wcipaymu'), 'https://my.ipaymu.com/members/index.htm'),
					'default' => ''
				),
				'paypal_payment' => array(
					'title' => __('PayPal Payment', 'wcipaymu'),
					'type' => 'title',
					'description' => __('PayPal is official iPaymu alternative payment.', 'wcipaymu')
				),
				'paypal_enabled' => array(
					'title' => __('Enable/Disable PayPal Module', 'wcipaymu'),
					'type' => 'checkbox',
					'description' => __('If you select to enable PayPal, you must also enable PayPal module in your iPaymu account.', 'wcipaymu'),
					'default' => 'no'
				),
				'paypal_email' => array(
					'title' => __('PayPal Email', 'wcipaymu'),
					'type' => 'text',
					'description' => __('If you select to enable PayPal; Please enter your PayPal email address; this is needed if you enable PayPal Module.', 'wcipaymu'),
					'default' => '',
					'desc_tip' => true,
					'placeholder' => 'you@youremail.com'
				),
				'invoice_prefix' => array(
					'title' => __('PayPal Invoice Prefix', 'wcipaymu'),
					'type' => 'text',
					'description' => __('If you select to enable PayPal; Please enter a prefix for your Paypal invoice numbers. If you use your PayPal account for multiple stores ensure this prefix is unqiue as PayPal will not allow orders with the same invoice number.', 'wcipaymu'),
					'default' => 'WC-',
					'desc_tip' => true
				),
				'currency_rate' => array(
					'title' => __('USD Currency Rate', 'wcipaymu'),
					'type' => 'text',
					'description' => __('If you select to enable PayPal; Please enter an Exchange Rate for 1 US Dollar (USD) in your default currency (ex: 9500). Due to Paypal has no support for Indonesia Rupiah (IDR) and iPaymu can not automatically convert currency from IDR to USD for Paypal payment, you should set a default currency rate to avoid the wrong price. If not set, WooCommerce Gateway iPaymu will use live rate provided by KlikBCA.', 'wcipaymu'),
					'default' => '',
					'desc_tip' => true
				),
				'testing' => array(
					'title' => __('Gateway Testing', 'wcipaymu'),
					'type' => 'title',
					'description' => '',
				),
				'debug' => array(
					'title' => __('Debug Log', 'wcipaymu'),
					'type' => 'checkbox',
					'label' => __('Enable logging', 'wcipaymu'),
					'default' => 'no',
					'description' => __('Log iPaymu events, such as API requests, inside <code>woocommerce/logs/ipaymu.txt</code>', 'wcipaymu'),
				),
				'credit' => array(
					'title' => __('Credit Link', 'wcipaymu'),
					'type' => 'title',
					'description' => '',
				),
				'credit_link' => array(
					'title' => __('Enable/Disable Credit Link', 'wcipaymu'),
					'type' => 'checkbox',
					'label' => __('Enable credit link', 'wcipaymu'),
					'default' => 'yes',
					'description' => __('', 'wcipaymu'),
				)
			);
		}

		/**
		* Admin Panel Options
		* - Options for bits like 'title' and availability on a country-by-country basis.
		*
		* @since 1.0.0
		**/
		public function admin_options() {
			?>
			<h3><?php _e( 'WooCommerce Gateway iPaymu', 'wcipaymu' ); ?></h3>
			<p><?php _e( 'The plugin works by sending the customer to iPaymu website to enter their payment information.', 'wcipaymu' ); ?></p>
			<table class="form-table">
			<?php
			if ( $this->is_valid_for_use() ) :
				// Generate the HTML For the settings form.
				$this->generate_settings_html();
			else :
			?>
				<div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'wcipaymu' ); ?></strong>: 
				<?php echo sprintf( __( 'WooCommerce Gateway iPaymu does not support your store currency. Current supported currencies: Indonesia Rupiah (IDR) and US Dollar (USD). <a href="%s">Click here to configure your currency!</a>', 'wcipaymu' ), admin_url( 'admin.php?page=wc-settings&tab=general' ) ) ?>
				</p></div>
			<?php
			endif;
			?>
			</table><!--/.form-table-->
			<?php
		}

		/**
		 * Get iPaymu Args for passing to iPaymu Gateway
		 *
		 * @access public
		 * @param mixed $order
		 * @return array
		 **/
		function get_ipaymu_args( $order ) {
			$total_price = $order->get_total();

			// log event
			$this->log( 'Generating iPaymu payment args for order #' . $order->id . '...' );
			
			/* Price, Tax, Discount, and Shipping cost calculation  **/
			// Not sure if it is needed for Ipaymu, not yet checked
			$item_names = array();
			$item_numbers = array();
			$item_amounts = 0; // total item amount in cart checkout
			$total_qty = 0; // total item quantity in cart checkout

			// If prices include tax or have order discounts, send the whole order as a single item
			if ( get_option( 'woocommerce_prices_include_tax' ) == 'yes' || $order->get_total_discount() > 0) {
				// Don't pass items. iPaymu has no option for tax inclusive pricing sadly. Pass 1 item for the order items overall
				if ( sizeof( $order->get_items() ) > 0) {
					foreach ( $order->get_items() as $item ) {
						if ( $item['qty'] ) {
							$item_names[] = '(' . $item['name'] . ') x ' . $item['qty'];
							$total_qty += $item['qty'];
						}
					}
				}

				$qty_label = sprintf( _n( '1 item', '%1$s items', $total_qty, 'wcipaymu' ), number_format_i18n( $total_qty ) );
				$product_name = $qty_label . ': ' . implode('; ', $item_names);
				$product_quantity = 1;
				$product_amount = number_format( $order->get_total() - $order->get_total_shipping() - $order->get_shipping_tax() + $order->get_total_discount(), 2, '.', '' );

				// Shipping Cost
				if ( ( $order->get_total_shipping() + $order->get_shipping_tax() ) > 0 ) {
					$shipping_name = sprintf( __( 'Shipping via %s', 'wcipaymu' ), ucwords( $order->shipping_method_title ) );
					$shipping_quantity = 1;
					$shipping_amount = number_format( $order->get_total_shipping() + $order->get_shipping_tax() , 2, '.', '' );
				}
			} else {
				// Tax
				$tax = $order->get_total_tax();

				// Cart Contents
				$item_loop = 0;
				
				if ( sizeof( $order->get_items() ) > 0 ) {
					foreach ( $order->get_items() as $item ) {
						if ( $item['qty'] ) {
							$item_loop++;
							$product = $order->get_product_from_item( $item );
							$item_name 	= $item['name'];
							$item_meta = new WC_Order_Item_Meta( $item['item_meta'] );
							
							if ( $meta = $item_meta->display( true, true ) ) {
								$item_name .= ' ('.$meta.')';
							}
							
							$item_names[] = $item_name;
							
							if ( $product->get_sku() ) {
								$item_numbers[] = $product->get_sku();
							}
							
							$total_qty += $item['qty'];
							$item_amounts += $order->get_item_total( $item, false );
						}
					}
				}

				$qty_label = sprintf( _n( '1 item', '%1$s items', $total_qty, 'wcipaymu' ), number_format_i18n( $total_qty ) );
				$product_name = $qty_label . ': ' . implode('; ', $item_names);

				// Shipping Cost item - iPaymu has no option for shipping inclusive, we want to send shipping for the order
				if ( $order->get_total_shipping() > 0 ) {
					$item_loop++;
					$shipping_name = sprintf( __( 'Shipping via %s', 'wcipaymu' ), ucwords( $order->shipping_method_title ) );
					$shipping_quantity = 1;
					$shipping_amount = number_format( $order->get_total_shipping(), 2, '.', '' );
				}
			} // Price, Tax, Discount, and Shipping cost calculation

			// Prepare Args (Parameters)
			$ipaymu_args = array(
				'key'		=> $this->ipaymu_apikey,
				'action'	=> 'payment',
				'product'	=> sprintf( __( 'Order #%s' , 'wcipaymu' ), $order->get_order_number() ),
				'price'		=> $total_price,
				'quantity'	=> 1,
				'comments'	=> sprintf( __( 'Payment for Order #%s contains %s', 'wcipaymu' ), $order->get_order_number(), $product_name ),
				'ureturn'	=> add_query_arg( 'utm_nooverride', '1', $this->get_return_url( $order ) ),
				'unotify'	=> WC()->api_request_url( 'wc_gateway_ipaymu' ) . '?order_id=' . $order->id . '&order_key=' . $order->order_key,
				'ucancel'	=> $order->get_cancel_order_url(),
				'format'	=> 'json' // Format: xml | json. Default: xml 
			);

			// If iPaymu PayPal Module enabled (Jika menggunakan Opsi PayPal)	
			if ( $this->paypal_enabled == 'yes' ) {
				$currency_code = get_woocommerce_currency();
				
				// Recalculate item price if it is not in USD, since Paypal has no support Rupiah
				if ( $currency_code <> 'USD' ) {
					// get user defined currency rate
					$user_usd_currency_rate = trim( $this->currency_rate );

					if ( ! empty( $user_usd_currency_rate ) ) {
						$usd_currency_rate = $user_usd_currency_rate;
					} else {			
						// get cache stored currency rate
						$cached_usd_currency_rate = get_transient( $this->id . '_currency_rate' );
					
						if ( ! empty( $cached_usd_currency_rate ) ) {
							$usd_currency_rate = $cached_usd_currency_rate;
						} else {
							// get kurs BCA
							$live_usd_currency_rate = trim( $this->get_kurs_bca( $this->bca_kurs_url, 'USD' ) );
						
							// cache currency rate to avoid continuosely grabbing from kurs BCA (you can set cache for 24 hrs)
							set_transient( $this->id . '_currency_rate', $live_usd_currency_rate, 86400 );
							
							// update currency rate setting
							//$wcipaymu_settings = get_option( 'woocommerce_' . $this->id . '_settings' );
							//$wcipaymu_settings['currency_rate'] = $live_usd_currency_rate;
							//update_option( 'woocommerce_' . $this->id . '_settings', $wcipaymu_settings );
							
							$usd_currency_rate = $live_usd_currency_rate;
						}
					}
				}
				
				// convert the total price in IDR to USD using live currency rate
				$price_usd = $total_price / $usd_currency_rate;
				
				$ipaymu_args = array_merge( $ipaymu_args, array(
					'invoice_number' => $this->invoice_prefix . $order->id, // Optional
					'paypal_email'   => $this->paypal_email,
					'paypal_price'   => number_format( $price_usd, 2, '.', '' ), // Total harga dalam kurs USD
				) );
			}

			$ipaymu_args = apply_filters( 'woocommerce_ipaymu_args', $ipaymu_args );
			
			// log event
			$this->log( 'iPaymu payment args for order #' . $order->id . ': ' . print_r( $ipaymu_args, true ) );

			return $ipaymu_args;
		}
	
		/**
		* Generate the form.
		*
		* @param mixed $order_id
		* @return string
		 **/
		function generate_ipaymu_form( $order_id ) {
			// get order instance
			$order = wc_get_order( $order_id );

			$args = $this->get_ipaymu_args( $order );

			// log event
			self::log( 'Generating iPaymu payment form for order #' . $order_id . '...' );

			$response = $this->api_request( $this->gateway_url, $args );
			
			if ( $response['status'] ) {
			
				$result = $this->parse_response( $response );
				
				if ( $result['status'] == true ) {
					// log event
					self::log( 'Receiving iPaymu gateway response for order #' . $order_id . ': ' . print_r( $result, true ) );

					if ( $this->paypal_enabled == 'yes' ) {
						return '<a href="' . $result['rawdata'] . '"><img src="' . plugins_url( 'images/ipaymu_button_cc.png', __FILE__ ) . '" alt="' . __( 'Pay via iPaymu', 'wcipaymu' ) . '" title="' . __( 'Pay via iPaymu', 'wcipaymu' ) . '" /></a>';
					} else {
						return '<a href="' . $result['rawdata'] . '"><img src="' . plugins_url( 'images/ipaymu_button_co.png', __FILE__ ) . '" alt="' . __( 'Pay via iPaymu', 'wcipaymu' ) . '" title="' . __( 'Pay via iPaymu', 'wcipaymu' ) . '" /></a>';
					}
				} else {
					// log event
					self::log( 'iPaymu Gateway Error, while receiving payment response for order #' . $order_id . ': ' . print_r( $result, true ) );

					return $this->ipaymu_order_error( $order );
				}
			
			} else {
				// log event
				self::log( 'Oops! An error occured while receiving payment response from iPaymu gateway.' );

				return $this->ipaymu_order_error( $order );
			}
		}

		/**
		 * Order error button.
		 *
		 * @access publick
		 * @param  object $order.
		 * @return string Error message and cancel button.
		 **/
		function ipaymu_order_error( $order ) {
			// Display message if there is problem.
			$html = '<p>' . __( 'Sorry, an error has occurred while processing your payment, please try again. Or contact our billing department for further assistance.', 'wcipaymu' ) . '</p>';
			$html .='<a class="button retry" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Click to try again', 'wcipaymu' ) . '</a>';
			return $html;
		}

		/**
		 * Process the payment and return the result.
		 *
		 * @access public
		 * @param int $order_id
		 * @return array
		 **/
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			return array(
				'result' 	=> 'success',
				'redirect'	=> add_query_arg( 'order', $order->id, 
					add_query_arg( 'key', $order->order_key, get_permalink( woocommerce_get_page_id( 'pay' ) ) ) 
				)
			);
		}

		/**
		 * Output for the order received page.
		 *
		 * @access public
		 * @return void
		 **/
		function receipt_page( $order ) {
			echo '<p>'.__( 'Thank you for your order, please click the button below to pay with iPaymu.', 'wcipaymu' ).'</p>';
			echo $this->generate_ipaymu_form( $order );
		}

		/**
		 * Parse iPaymu payment gateway response.
		 *
		 * @access public
		 * @param $raw Object.
		 * @return array of response.
		 **/
		function parse_response( $response ) {
			if ( $response['status'] ) {
				$result = json_decode( $response['rawdata'], true );
				if ( isset( $result['url'] ) ) {
					return array( 'status' => true, 'sessionID' => $result['sessionID'], 'rawdata' => $result['url'] );
				} else {
					return array( 
						'status' => false, 
						'sessionID' => '', 
						'rawdata' => 'Request Error ' . $result['Status'] . ': ' . $result['Keterangan']
					);
				}
			} else {
				return array( 'status' => false, 'sessionID' => '', 'rawdata' => $response['rawdata'] );
			}
		}

		/**
		 * Check iPaymu API validation. (Not sure if it is needed for Ipaymu)
		 *
		 * @access public
		 * @return void
		 **/
		function check_gateway_response_is_valid( $data ) {
			// log event
			self::log( 'Validating iPaymu gateway payment response: ' . print_r( $data, true)  );
			
			// valid iPaymu gateway response contains sid post data
			if ( isset( $data['sid'] ) ) {
				// log event
				self::log( 'Success: Received valid payment response from iPaymu gateway.' );

				return true;
			} else {
				// log event
				self::log( 'Error: Received invalid payment response from iPaymu gateway.' );

				return false;
			}
		}

		/**
		 * Check for iPaymu API Response. (Not sure if it is needed for Ipaymu)
		 *
		 * @access public
		 * @return void
		 **/
		function check_gateway_response() {
			if ( is_admin() ) {
				return;
			}
			// update for compatibility with version 2.0
			//if (isset($_GET['ipaymuListener']) && $_GET['ipaymuListener'] == 'notify') {
			
				@ob_clean();
				
				// Get order key from unotify url parameters and append to posted data from iPaymu response (due to iPaymu still have no method to send custom params)
				$_POST['order_id'] = $_GET['order_id'];
				$_POST['order_key'] = $_GET['order_key'];
				
				$_POST = stripslashes_deep( $_POST );
				
				// Test only, kita perlu melakukan verifikasi response dari iPaymu stlh user berhasil melakukan payment
				$status = $this->check_gateway_response_is_valid( $_POST );
				
				if ( $status === true ) {
					header( 'HTTP/1.1 200 OK' );
					do_action( 'wc_gateway_ipaymu_valid_request', $_POST );
					
					// log event
					self::log( 'Receiving payment response from iPaymu gateway: ' . print_r( $_POST, true ) );
				} else {
					header( 'HTTP/1.1 404 Not Found' );
					wp_die( 'Gateway Error: iPaymu API Request Failure.' );
					
					// log event
					self::log( 'Gateway Error: iPaymu API Request Failure.' );
				}
			//}
		}

		/**
		 * Successful Payment!
		 *
		 * @access public
		 * @param array $posted result response from gateway
		 * @return void
		 **/
		function successful_request( $posted ) {
			// Custom holds post ID
			//$order_status = array('cancelled', 'completed', 'failed', 'on-hold', 'pending', 'processing', 'refunded');
			
			// Received response from iPaymu Gateway sent to unotify
			//$return_params = array('trx_id', 'sid', 'product', 'quantity', 'merchant', 'buyer', 'total', 'action', 'comments', 'referer');
			
			// Received response from iPaymu Gateway sent to unotify if using PayPal
			//$return_params = array('sid', 'product', 'quantity', 'merchant', 'total', 'paypal_trx_id', 'paypal_invoice_number', 'paypal_currency', 'paypal_trx_total', 'paypal_trx_fee', 'paypal_buyer_email', 'paypal_buyer_status', 'paypal_buyer_name', 'action', 'referer');
		
			$order_id = $posted['order_id']; // get from unotify url
			$order_key = $posted['order_key']; // get from unotify url
			$trx_sid = $posted['sid'];
			$trx_status = $posted['status'];
				
			// check if payment made using Paypal instead of Ipaymu
			if ( isset( $posted['paypal_trx_id'] ) ) {
				// get order id from paypal invoice number
				//$order_id = (int) str_replace($this->invoice_prefix, '', $posted['paypal_invoice_number']); 
				$trx_id = $posted['paypal_trx_id'];
				$trx_total = $posted['paypal_trx_total'];
				$trx_fee = $posted['paypal_trx_fee'];
				$trx_buyer_name = $posted['paypal_buyer_name'];
				$trx_buyer_email = $posted['paypal_buyer_email'];
			} else {
				// get order id from product name, format: Order #ID_NO - Product Name
				//preg_match('/^(?:Order\s)#(.*?)[\s\-\s](.*?)/', $posted['product'], $matches);
				//$order_id = (int) trim($matches[1]);
				$trx_id = $posted['trx_id'];
				$trx_total = $posted['total'];
				$trx_fee = 0;
				$trx_buyer_name = $posted['buyer'];
				$trx_buyer_bank = $posted['no_rekening_deposit'];
			}
				
			$order = wc_get_order( $order_id );
				
			self::log( 'Validating payment status from order #' . $order->id . ' which order key is ' . $order_key );
				
			// Checks whether returned order_key not equal with current order_key
			if ( $order->order_key !== $order_key ) {
				// log event
				$this->log( 'Payment Error: Order Key (' . $order_key . ') does not match invoice number.' );
				exit;
			}
	
			if ( isset ( $trx_sid ) && $trx_id <> '' ) {
				// test, give the gateway server a delay time before checking the payment status
				sleep(3);
					
				// checking iPaymu payment status
				$payment = $this->check_payment_status( $trx_id );
				
				if ( ! $payment ) {
					// log event
					self::log( 'An error occured while checking payment status from iPaymu gateway. ' . print_r( $payment, true ) );
				} else {
					// log event
					self::log( 'Updating payment status for order #' . $order->id );
					
					// update order status
					switch ( $payment['Status'] ) :
						case "-1":
							// Processing
							$order->update_status( 
								'processing', 
								__( 'Waiting payment processed by iPaymu.', 'wcipaymu') 
							);
								
							$order->add_order_note( sprintf( __( 'Waiting payment - %s', 'wcipaymu' ), $payment['Keterangan'] ) );
									
							// Send email notify to buyer
							$mailer 	= WC()->mailer();
							$message	= $mailer->wrap_message(
								__( 'Waiting payment processed', 'wcipaymu' ),
								sprintf( __( 'Waiting payment for order %s from iPaymu on: %s', 'wcipaymu'), 
								$order->get_order_number(), $payment['Waktu'] )
							);
							$mailer->send( 
								get_option( 'woocommerce_new_order_email_recipient' ), 
								sprintf( __( 'Your order %s has been received and still waiting for payment being processed by iPaymu', 'wcipaymu' ), $order->get_order_number() ), 
								$message 
							);
								
						break;
						
						case "1":
							// Check order not already completed.
							if ( $order->status == 'completed' ) {
								// log event
								$this->log( 'Aborting... Order #' . $order_id . ' is already completed.' );
								exit;
							}
								
							// Validate Amount
							if ( $order->get_total() != $posted['total'] ) {
								// log event
								$this->log( 'Payment validation error: Amounts do not match. Total payment (gross ' . $trx_total . ') and (fee ' . $trx_fee . '). Order total ' . $order->get_total() . '.' );
								
								// Put this order on-hold for manual checking
								$order->update_status( 'on-hold', sprintf( __( 'Payment validation error: iPaymu amounts do not match. Total payment (gross %s) and (fee %s). Order total %s', 'wcipaymu' ), $trx_total, $trx_fee, $order->get_total() ) );
								exit;
							}
								
							// Save iPaymu payment details meta data
							if ( isset( $posted['paypal_trx_id'] ) ) {
								update_post_meta( $order->id, 'Payment type', 'PayPal via iPaymu' );
								if ( ! empty( $posted['paypal_buyer_email'] ) ) {
									update_post_meta( $order->id, 'Payer PayPal address', wc_clean( $posted['paypal_buyer_email'] ) );
								}
								if ( ! empty( $posted['paypal_buyer_name'] ) ) {
									update_post_meta( $order->id, 'Payer first name', wc_clean( $posted['paypal_buyer_name'] ) );
								}
							} else {
								update_post_meta( $order->id, 'Payment type', 'iPaymu' );
							}
							
							if ( ! empty( $trx_id ) ) {
								update_post_meta( $order->id, 'Payment trx ID', $trx_id );
							}
								
							if ( ! empty( $posted['buyer'] ) ) {
								update_post_meta( $order->id, 'Payer first name', wc_clean( $posted['buyer'] ) );
							}
								
							if ( ! empty( $posted['comments'] ) ) {
								update_post_meta( $order->id, 'Payment comments', wc_clean( $posted['comments'] ) );
							}

							// Payment completed
							$order->payment_complete();
							$order->add_order_note( sprintf( __( 'Payment completed using iPaymu - %s', 'wcipaymu' ), $payment['Keterangan'] ) );
								
							// Send email notification to buyer
							$mailer 	= WC()->mailer();
							$message	= $mailer->wrap_message(
								__( 'Order received and processed', 'wcipaymu' ),
								sprintf( __( 'Payment for order %s has been received via iPaymu on: %s and we\'re processing your order now. Please be patient while we are shipping your order.', 'wcipaymu' ), $order->get_order_number(), $payment['Waktu'] ) );
							$mailer->send( 
								get_option( 'woocommerce_new_order_email_recipient' ), 
								sprintf( __( 'Your payment for order %s has been received', 'wcipaymu' ), $order->get_order_number() ), 
								$message
							);

							// log event
							if ( isset( $posted['paypal_trx_id'] ) ) {
								self::log( 'Payment Status: Completed using Paypal via iPaymu.' );
							} else {
								self::log( 'Payment Status: Completed using iPaymu.' );
							}
						break;
							
						case "2":
							// Batal (cancelled)
						break;
							
						case "3":
							// Refunded
								
							// Only handle full refunds, not partial
							$order_total = $order->get_total();
							
							// iPaymu fee is 1% of total paid amount and not refundable, so we need to add it to the refunded balance
							$ipaymu_fee = $order_total * $this->ipaymu_fee;
								
							if ( $order_total == ( $payment['Nominal'] + $ipaymu_fee ) ) {
								// Mark order as refunded
								$order->update_status( 
									'refunded', 
									__( 'Payment refunded via iPaymu.', 'wcipaymu') 
								);
								
								$order->add_order_note( sprintf( __( 'Payment refunded - %s', 'wcipaymu' ), $payment['Keterangan'] ) );
									
								// Send email notify to buyer
								$mailer 	= WC()->mailer();
								$message	= $mailer->wrap_message(
									__( 'Order refunded/reversed', 'wcipaymu' ),
									sprintf( __( 'Order %s has been marked as refunded via iPaymu on: %s', 'wcipaymu'), 
									$order->get_order_number(), $payment['Waktu'] )
								);
								$mailer->send( 
									get_option( 'woocommerce_new_order_email_recipient' ), 
									sprintf( __( 'Payment for order %s refunded/reversed', 'wcipaymu' ), $order->get_order_number() ), 
									$message
								);

								// log event
								self::log( 'Payment Status: Refunded. Total refunded: ' . $payment['Nominal'] . ' + iPaymu transaction fee: ' . $ipaymu_fee );
							}
						break;
							
						case "-1004":
							// Error: ID Transaksi salah
							// log event
							self::log( 'Payment Status: Invalid transaction ID.' );
						break;
							
						case "-1005":
							// Error: ID Transaksi tidak ditemukan
							// log event
							self::log( 'Payment Status: Transaction ID not found.' );
						break;
							
						default:
							// No action
						break;
					endswitch; // End switch
				}
			} else {
				// log event
				self::log( 'Payment Status: Transaction ID not found.' );
			}
		}
		
		/**
		 * Checking for iPaymu Payment Status.
		 *
		 * @access public
		 * @params $trx_id transaction id from gateway payment response
		 * @return void
		 **/
		function check_payment_status( $trx_id ) {
			$args = array(
				'key' => $this->ipaymu_apikey,
				'id' => $trx_id,
				'format' => 'json'
			);
			
			// log event
			self::log( 'Checking iPaymu payment status for transaction ID ' . print_r( $trx_id, true ) . '...' ) ;

			$response = $this->api_request( $this->gateway_trx_url, $args );
			
			if ( $response['status'] ) {
				// log event
				self::log( 'Received valid response for iPaymu payment status check: ' . print_r( $response, true ) );
				return json_decode( $response['rawdata'], true );
			} else {
				// log event
				self::log( 'Received unexpected error response for iPaymu payment status check: ' . print_r( $response, true ) );
				return false;
			}
		}
		
		/**
		 * Send debugging email
		 *
		 * @access public
		 * @return void
		**/
		protected function send_email_notification( $subject, $message ) {
			$new_order_settings = get_option( 'woocommerce_new_order_settings', array() );
			$mailer             = WC()->mailer();
			$message            = $mailer->wrap_message( $subject, $message );

			$mailer->send( ! empty( $new_order_settings['recipient'] ) ? $new_order_settings['recipient'] : get_option( 'admin_email' ), strip_tags( $subject ), $message );
		}
		
		/**
		 * API Gateway Request
		 *
		 * @access public
		 * @param  object	$params Array with gateway parameters.
		 * @param  string	$url Gateway URL.
		 * @param  string	$method Request method: POST or GET.
		 * @return Array Response: status, rawdata.
		 **/
		function api_request( $url, $params = array(), $cookie = false, $proxy = '', $proxyauth = '', $timeout = 60 ) {
			// default http method
			$method = 'GET';
			
			// set user agent
			$ua = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.84 Safari/537.36";
	
			// set url target
			$url = str_replace( '&amp;', '&', urldecode( trim($url) ) );
			
			// set http POST method and params
			if ( isset( $params ) ) {
				$method = 'POST';
				$postdata = http_build_query( $params, '', '&' );
			}
			
			// set cookie file
			if ( $cookie !== false ) $cookiefile = tempnam( '/tmp', 'CURLCOOKIE' );
			
			// Open CURL connection
			if ( function_exists( 'curl_initz' ) ) {
			
				$ch = curl_init();
				curl_setopt( $ch, CURLOPT_USERAGENT, $ua );
				curl_setopt( $ch, CURLOPT_URL, $url );
				
				if ( $cookie !== false ) {
					curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookie );
					curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookie );
				}
		
		        //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
				curl_setopt( $ch, CURLOPT_AUTOREFERER, true );
				//curl_setopt($ch, CURLOPT_MAXREDIRS, 10); // if follow location set to true

				// If set timeout enabled
				if ( $timeout > 0 ) {
					curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $timeout );
					curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
				}

				// If method http POST
				if ( $method == 'POST' ) {
					curl_setopt( $ch, CURLOPT_POST, true );
					curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
				}
				
				// If set proxy enabled
				if ( $proxy != '' ) {
					curl_setopt( $ch, CURLOPT_PROXY, $proxy );
					
					if ( $proxyauth != '' ) {
						curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxyauth );
					}
				}				

				// log event
				self::log( 'Sending request to API gateway via cURL...' );

				// execute request
				$response = @curl_exec( $ch );

				if ( $response !== false ) {
					// log event
					self::log( 'Received valid request response from API gateway via cURL.' );
					$result = array( 'status' => true, 'rawdata' => $response );
				} else {
					$curl_error = curl_error( $ch );
					// log event
					self::log( 'Error while processing request to API gateway via cURL. Response: ' . print_r ( $curl_error, true ) );
					$result = array( 'status' => false, 'rawdata' => 'Request Error: ' . $curl_error );
				}
				
				curl_close( $ch );

			} else {
				// Use built-in wp_remote_post instead curl.
				
				// log event
				self::log( 'Sending request to API gateway via wp_remote_post...' );
				
				$args = array(
					'body' => $params,
					'timeout' => 60,
					'httpversion' => '1.1',
					'method'  => $method,
					'user-agent' => $ua
				);
				$response = wp_safe_remote_post( $url, $args );

				// Check to see if the request was valid.
				if ( ! is_wp_error( $response ) && $response['response']['code'] == 200 ) {

					$response_body = $response['body'];
					 
					if ( isset ( $response['body'] ) && ! empty( $response['body'] ) ) {
						// log event
						self::log( 'Received valid request response from API gateway via wp_remote_post.' );
						$result = array( 'status' => true, 'rawdata' => $response['body'] ) ;
						
					} else {
						// log event
						self::log( 'Received invalid request response from API gateway via wp_remote_post.' );
						$result = array( 'status' => false, 'rawdata' => 'Request Error: Error while processing payment from iPaymu.' );
					}
				} else {
					// log event
					self::log( 'Error while processing request to API gateway via wp_remote_post.' );
					$result = array( 'status' => false, 'rawdata' => 'Received invalid payment request response from iPaymu Gateway.' );
				}
			}
			
			return $result;
		}
		
		/**
		 * Retrieve currency rate from KursBCA
		 *
		 * @param $kurs_url string url of currency converter
		 * @param $currency string of currency code
		 * @access public
		 * @return string
		 **/
		public function get_kurs_bca( $kurs_url, $currency ) {
			$bca_rates = array();
			
			// log event
			self::log( 'Retrieve live currency rate from Kurs BCA...' );
			
			// get result Object array status, rawdata
			$response = $this->api_request( $kurs_url );
			
			if ( $response['status'] ) {
				$html = $response['rawdata'];

				// match table from bca kurs html web page
				$table = preg_match_all( '#<tbody\ class="text-right">(.+?)</tbody>#ims', $html, $matches );

				// look for table containing Kurs
				$looked_table = $matches[1][0];

				// parse the table raw
				$t_row = preg_match_all( '#<tr>(.+?)</tr>#ims', $looked_table, $tr_matches, PREG_SET_ORDER );

				if ( $t_row ) {
					foreach ( $tr_matches as $i => $tr_match ) {
						// parse the table raw data
						$t_data = preg_match_all( '#<td[^>]+>(.+?)</td>#ims', $tr_match[0], $td_matches, PREG_SET_ORDER );
						
						if ( $t_data ) {
							// take only the e-rate data
							$cur = trim( $td_matches[0][1] );
							$sell_rate = trim( $td_matches[1][1] );
							$buy_rate = trim( $td_matches[2][1] );
							// make result in array for JSON output
							$bca_rates[$cur] = array( 'Sell' => self::parse_number( $sell_rate, '.', ',' ), 'Buy' => self::parse_number( $buy_rate, '.', ',' ) );
						}
					}
				}
				
				// log event
				self::log( 'Receive live currency rate from Kurs BCA: ' . print_r( $bca_rates, true ) );
				
				// return value USD
				if ( isset( $bca_rates[$currency]['Buy'] ) ) {
					return $bca_rates[$currency]['Buy'];
				} else {
					return null;
				}
			} else {
				// log event
				self::log( 'Oops! An error occured while retrieving live currency rate from Kurs BCA: ' . print_r( $bca_rates, true ) );
				return false;
			}
		}
		
		public static function parse_number($number, $thousand_sep=null, $dec_point=null) {
			if (empty($thousand_sep)) {
				$locale = localeconv();
				$thousand_sep = $locale['thousands_sep'];
			}
			if (empty($dec_point)) {
				$locale = localeconv();
				$dec_point = $locale['decimal_point'];
			}
	
			$number = str_replace( $thousand_sep, '', $number );
			return floatval(str_replace($dec_point, '.', preg_replace('/[^\d'.preg_quote($dec_point).']/', '', $number)));
		}
	} // End of WC_Gateway_Ipaymu class.
	
	/**
	 * Add the gateway to WooCommerce.
	 *
	 * @access public
	 * @param array $methods
	 * @return array
	 **/
	function add_gateway_ipaymu( $methods ) {
		$methods[] = 'WC_Gateway_Ipaymu';
		return $methods;
	}
	add_filter( 'woocommerce_payment_gateways', 'add_gateway_ipaymu' );
	

	/**
	 * Add New Currencies and symbols for Indonesia Rupiah for iPaymu Gateway
	 * If not work, put the code below on function.php file under your Wordpress theme 
	 */
	if ( ! function_exists( 'wc_add_idr_currency' ) ) {
		function wc_add_idr_currency( $currencies ) {
			$currencies['IDR'] = __( 'Indonesian Rupiah', 'wcipaymu' );	
			return $currencies;
		}
		add_filter( 'woocommerce_currencies', 'wc_add_idr_currency' );
	}

	if ( ! function_exists( 'wc_add_idr_currency_symbol' ) ) {
		function wc_add_idr_currency_symbol( $currency_symbol, $currency ) {
			switch( $currency ) {
				case 'IDR': $currency_symbol = 'Rp.'; break;
			}
			return $currency_symbol;
		}
		add_filter( 'woocommerce_currency_symbol', 'wc_add_idr_currency_symbol', 10, 2 );
	}
	
	/**
	 * Additional currencies for Gravity Forms, 
	 * basically should not here, but very it is useful for store that using gravity form as addons.
	 */
	if ( class_exists( 'RGForms' ) && ! function_exists( 'gf_idr_currency' ) ) {
		function gf_idr_currency( $currencies ) {
			$currencies['IDR'] = array(
				'name' => __( 'Indonesia Rupiah', 'gravityforms' ), 
				'symbol_left' => 'Rp.', 
				'symbol_right' => '', 
				'symbol_padding' => ' ', 
				'thousand_separator' => '.', 
				'decimal_separator' => ',', 
				'decimals' => 2
			);	
			return $currencies; 
		}
		add_filter( 'gform_currencies', 'gf_idr_currency' );
	}
	
} // End function wc_gateway_ipaymu_init.
add_action( 'plugins_loaded', 'wc_gateway_ipaymu_init', 0 );
