<?php
/**
 * Plugin Name: Nimiq Checkout for WooCommerce
 * Plugin URI: https://github.com/nimiq/woocommerce-gateway-nimiq
 * Description: Let customers pay with their Nimiq account directly in the browser
 * Author: Nimiq
 * Author URI: https://nimiq.com
 * Version: 2.7.4
 * Text Domain: wc-gateway-nimiq
 * Domain Path: /i18n/languages/
 * Requires at least: 4.9
 * Tested up to: 5.2
 * WC requires at least: 3.5
 * WC tested up to: 3.6
 *
 * Copyright: (c) 2018-2019 Nimiq Network Ltd., 2015-2016 SkyVerge, Inc. and WooCommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Gateway-Nimiq
 * @author    Nimiq
 * @category  Admin
 * @copyright Copyright (c) 2018-2019 Nimiq Network Ltd., 2015-2016 SkyVerge, Inc. and WooCommerce
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 * This Nimiq gateway forks the WooCommerce core "Cheque" payment gateway to create another payment method.
 */

defined( 'ABSPATH' ) or exit;


// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}


/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + Nimiq gateway
 */
function wc_nimiq_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_Nimiq';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_nimiq_add_to_gateways' );


/**
 * Adds plugin page links
 *
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_nimiq_gateway_plugin_links( $links ) {

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nimiq_gateway' ) . '">' . __( 'Configure', 'wc-gateway-nimiq' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_nimiq_gateway_plugin_links' );


// We load the plugin later to ensure WC is loaded first since we're extending it.
add_action( 'plugins_loaded', 'wc_nimiq_gateway_init', 11 );

/**
 * Initializes plugin
 *
 * @since 1.0.0
 */
function wc_nimiq_gateway_init() {

	class WC_Gateway_Nimiq extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {

			$this->id                 = 'nimiq_gateway';
			$this->has_fields         = true;
			$this->method_title       = 'Nimiq';
			$this->method_description = __( 'Allows Nimiq payments. Orders are marked as "on-hold" when received.', 'wc-gateway-nimiq' );

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions' );

			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
			add_action( 'woocommerce_api_wc_gateway_nimiq', array( $this, 'handle_redirect_response' ) );
			add_action( 'admin_notices', array( $this, 'do_store_nim_address_check' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_settings_script' ) );

			// Customer Emails
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

			// Add style, so it can be loaded in the header of the page
			wp_enqueue_style('NimiqPayment', plugin_dir_url( __FILE__ ) . 'styles.css');
		}

		/**
		 * Returns current plugin version
		 */
		public function version() {
			return get_file_data( __FILE__, [ 'Version' ], 'plugin')[ 0 ];
		}

		public function get_icon() {
			/**
			 * Data URLs need to be escaped like this:
			 * - all # must be %23
			 * - all double quotes (") must be single quotes (')
			 * - :// must be %3A%2F%2F
			 * - all slashes (/) must be %2F
			 */
			$icon_src = "data:image/svg+xml,<svg xmlns='http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg' height='26' width='26' version='1.1' viewBox='0 0 72 72'><defs><radialGradient gradientTransform='matrix(0.99996243,0,0,1,0.00384744,3.9999988)' gradientUnits='userSpaceOnUse' r='72.019997' cy='63.169998' cx='54.169998' id='radial-gradient'><stop id='stop4' stop-color='%23ec991c' offset='0' /><stop id='stop6' stop-color='%23e9b213' offset='1' /></radialGradient></defs><path fill='url(%23radial-gradient)' stroke-width='0.99998122' d='M 71.201173,32.999999 56.201736,6.9999988 a 5.9997746,6 0 0 0 -5.199804,-3 H 21.003059 a 5.9997746,6 0 0 0 -5.189805,3 L 0.80381738,32.999999 a 5.9997746,6 0 0 0 0,6 l 14.99943662,26 a 5.9997746,6 0 0 0 5.199805,3 h 29.998873 a 5.9997746,6 0 0 0 5.189805,-3 l 14.999436,-26 a 5.9997746,6 0 0 0 0.01,-6 z' /></svg>";

			$img  = '<img src="' . $icon_src . '" alt="' . esc_attr__( 'Nimiq logo', 'wc-gateway-nimiq' ) . '">';
			$link = '<a href="https://nimiq.com/en/#splash" class="about_nimiq" target="_blank">' . esc_html__( 'What is Nimiq?', 'wc-gateway-nimiq' ) . '</a>';

			return $img . $link ;
		}

		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {
			// include_once() does not work here, as when saving the settings the file needs to be included twice
			include( plugin_dir_path( __FILE__ ) . 'settings.php' );
			$this->form_fields = $woo_nimiq_checkout_settings;
		}

		private function get_payment_request( $order_id ) {
			$order = wc_get_order( $order_id );

			$order_total = floatval( $order->get_total() );
			$order_currency = $order->get_currency();

			$cryptoman = new Crypto_Manager( $this );

			$order_hash = $order->get_meta( 'order_hash' );
			if ( empty( $order_hash ) ) {
				$order_hash = $this->compute_order_hash( $order );
				$order->update_meta_data( 'order_hash', $order_hash );
			}

			// To uniquely identify the payment transaction, we add a shortened hash of
			// the order details to the transaction message.
			$tx_message = ( !empty( $this->get_option( 'message' ) ) ? $this->get_option( 'message' ) . ' ' : '' )
				. '(' . strtoupper( $this->get_short_order_hash( $order_hash ) ) . ')';

			$tx_message_bytes = unpack('C*', $tx_message); // Convert to byte array

			$fees = $cryptoman->get_fees( count( $tx_message_bytes ) );

			// Collect common request properties used in both request types
			$request = [
				'appName' => get_bloginfo( 'name' ),
				'shopLogoUrl' => $this->get_option( 'shop_logo_url' ),
				'extraData' => $tx_message,
			];

			if ( $order_currency === 'NIM') {
				$order->update_meta_data( 'order_total_nim', $order_total );

				// Use NimiqCheckoutRequest (version 1)
				$request = array_merge( $request, [
					'version' => 1,
					'recipient' => Order_Utils::get_order_recipient_address( $order, $this ),
					'value' => intval( Order_Utils::get_order_total_crypto( $order ) ),
					'fee' => $fees[ 'nim' ],
				] );
			} else {
				$price_service = $this->get_option( 'price_service' );
				include_once( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'price_services' . DIRECTORY_SEPARATOR . $price_service . '.php' );
				$class = 'WC_Gateway_Nimiq_Price_Service_' . ucfirst( $price_service );
				$price_service = new $class( $this );

				$accepted_cryptos = $cryptoman->get_accepted_cryptos();

				$prices = $price_service->get_prices( $accepted_cryptos, $order_currency );

				$expires = strtotime( '+15 minutes' );
				$order->update_meta_data( 'crypto_rate_expires', $expires );

				if ( is_wp_error( $prices ) ) {
					$order->update_meta_data( 'conversion_error', $prices->get_error_message() );
					return $prices;
				}

				$order_totals_crypto = $cryptoman->calculate_quotes( $order_total, $prices );
				$order_totals_unit = Crypto_Manager::coins_to_units( $order_totals_crypto );

				foreach ( $accepted_cryptos as $crypto ) {
					$order->update_meta_data( $crypto . '_price', $prices[ $crypto ] );
					$order->update_meta_data( 'order_total_' . $crypto, $order_totals_crypto[ $crypto ] );
				}

				// Generate CSRF token, webhook URL
				$csrf_token = bin2hex( openssl_random_pseudo_bytes( 16 ) );
				$order->update_meta_data( 'checkout_csrf_token', $csrf_token );
				$callback_url = get_site_url() . '/wc-api/nimiq_checkout_callback?id=' . $order_id . '&csrf_token=' . $csrf_token;

				// Use MultiCurrencyCheckoutRequest (version 2)
				$payment_options = [];
				foreach ( $accepted_cryptos as $crypto ) {
					$recipient = $crypto === 'nim' ? Order_Utils::get_order_recipient_addresses( $order, $this )[ 'nim' ] : null;
					$amount = $order_totals_unit[ $crypto ];
					$fee = $fees[ $crypto ];

					$payment_options[] = [
						'type' => 0, // 0 = DIRECT
						'currency' => $crypto,
						'expires' => $expires,
						'amount' => $amount,
						'protocolSpecific' => array_merge( [
							'recipient' => $recipient,
						], $crypto === 'eth' ? [
							'gasLimit' => $fee[ 'gas_limit' ],
							'gasPrice' => strval( $fee[ 'gas_price' ] ),
						] : [
							'fee' => $fee,
						] ),
					];
				};

				$request = array_merge( $request, [
					'version' => 2,
					'callbackUrl' => $callback_url,
					'time' => time(),
					'fiatAmount' => $order_total,
					'fiatCurrency' => $order_currency,
					'paymentOptions' => $payment_options,
				] );
			}

			$order->save();

			return $request;
		}

		private function handle_redirect_response() {
			$order_id = $this->get_param( 'id' );

			if ( empty( $order_id ) ) {
				// Redirect to main site
				wp_redirect( get_site_url() );
				exit;
			}

			$is_valid = $this->validate_fields( $order_id );

			if ( !$is_valid ) {
				// Redirect to payment page
				$order = wc_get_order( $order_id );
				wp_redirect( $order->get_checkout_payment_url( $on_checkout = false ) );
				exit;
			}

			$redirect = $this->process_payment( $order_id );

			wp_redirect( $redirect[ 'redirect' ] );
			exit;
		}

		public function payment_fields() {
			if ( ! is_wc_endpoint_url( 'order-pay' ) ) {
				$description = $this->get_description();
				if ( $description ) {
					echo wpautop( wptexturize( $description ) );
				}
				return;
			}

			if ( !isset( $_GET['pay_for_order'] ) || !isset( $_GET['key'] ) ) {
				return;
			}

			$order_id = wc_get_order_id_by_order_key( wc_clean( $_GET['key'] ) );
			$request = $this->get_option( 'rpc_behavior' ) === 'popup' ? $this->get_payment_request( $order_id ) : [];

			if ( is_wp_error( $request ) ) {
				?>
				<div id="nim_gateway_info_block">
					<p class="form-row" style="color: red; font-style: italic;">
						<?php _e( 'ERROR:', 'wc-gateway-nimiq' ); ?><br>
						<?php echo( $request->get_error_message() ); ?>
					</p>
				</div>
				<?php
				return;
			}

			// These scripts are enqueued at the end of the page
			wp_enqueue_script('HubApi', plugin_dir_url( __FILE__ ) . 'js/HubApi.standalone.umd.js', [], $this->version(), true );

			wp_register_script( 'NimiqCheckout', plugin_dir_url( __FILE__ ) . 'js/checkout.js', [ 'jquery', 'HubApi' ], $this->version(), true );
			wp_localize_script( 'NimiqCheckout', 'CONFIG', array(
				'HUB_URL'      => $this->get_option( 'network' ) === 'main' ? 'https://hub.nimiq.com' : 'https://hub.nimiq-testnet.com',
				'RPC_BEHAVIOR' => $this->get_option( 'rpc_behavior' ),
				'REQUEST'      => json_encode( $request ),
			) );
			wp_enqueue_script( 'NimiqCheckout' );

			?>

			<div id="nim_gateway_info_block">
				<?php if ( $this->get_option( 'rpc_behavior' ) === 'popup' ) { ?>
					<noscript>
						<strong>
							<?php _e( 'Javascript is required to use Nimiq Checkout. Please activate Javascript to continue.', 'wc-gateway-nimiq' ); ?>
						</strong>
					</noscript>

					<input type="hidden" name="rpcId" id="rpcId" value="">
					<input type="hidden" name="status" id="status" value="">
					<input type="hidden" name="result" id="result" value="">
				<?php } ?>

				<p class="form-row">
					<?php _e( 'Please click the big button below to pay.', 'wc-gateway-nimiq' ); ?>
				</p>
			</div>

			<div id="nim_payment_complete_block" class="hidden">
				<i class="fas fa-check-circle" style="color: seagreen;"></i>
				<?php _e( 'Payment complete', 'wc-gateway-nimiq' ); ?>
			</div>
			<?php
		}

		protected function compute_order_hash( $order ) {
			$order_data = $order->get_data();

			$serialized_order_data = implode(',', [
				$order->get_id(),
				$order_data[ 'date_created' ]->getTimestamp(),
				$order_data[ 'currency' ],
				$order->get_total(),
				$order_data['billing']['first_name'],
				$order_data['billing']['last_name'],
				$order_data['billing']['address_1'],
				$order_data['billing']['city'],
				$order_data['billing']['state'],
				$order_data['billing']['postcode'],
				$order_data['billing']['country'],
				$order_data['billing']['email'],
			]);

			return sha1( $serialized_order_data );
		}

		public function get_short_order_hash( $long_hash ) {
			return substr( $long_hash, 0, 6 );
		}

		private function get_param( $key, $method = 'get' ) {
			$data = $method === 'get'
				? $_GET
				: $method === 'post'
					? $_POST
					: $method;

			if ( !isset( $data[ $key ] ) ) return null;
			return sanitize_text_field( $data[ $key ] );
		}

		public function validate_fields( $order_id = null, $response = null) {
			$response = $response ?: $_POST;

			if ( !isset( $response[ 'rpcId' ] ) ) return true;

			$status = $this->get_param( 'status', $response );
			$result = $this->get_param( 'result', $response );

			if ( $status === 'error' || empty( $result ) ) return false;

			$result = json_decode( $result );

			// Get order_id from GET param (for when RPC behavior is 'popup')
			if ( isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) ) {
				$order_id = wc_get_order_id_by_order_key( wc_clean( $_GET['key'] ) );
			}

			if ( empty( $order_id ) ) return false;

			$order = wc_get_order( $order_id );

			$currency = Order_Utils::get_order_currency( $order, false );

			if ( $currency === 'nim' ) {
				$transaction_hash = $result->hash;
				$customer_nim_address = $result->raw->sender;

				if ( ! $transaction_hash ) {
					wc_add_notice( __( 'You must submit the Nimiq transaction first.', 'wc-gateway-nimiq' ), 'error' );
					return false;
				}

			if ( strlen( $transaction_hash) !== 64 ) {
				wc_add_notice( __( 'Invalid transaction hash (' . $transaction_hash . '). Please contact support with this error message.', 'wc-gateway-nimiq' ), 'error' );
					return false;
				}

				$order->update_meta_data( 'transaction_hash', $transaction_hash );
				$order->update_meta_data( 'customer_nim_address', $customer_nim_address );
				$order-save();
			}

			return true;
		}

		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {
			if ( $this->instructions ) {
				echo wpautop( wptexturize( $this->instructions ) );
			}
		}

		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 * @param WC_Order $order
		 * @param bool $sent_to_admin
		 * @param bool $plain_text
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
			if ( $this->instructions && ! $sent_to_admin && $order->get_payment_method() === $this->id && $order->has_status( 'on-hold' ) ) {
				echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
			}
		}

		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {

			$order = wc_get_order( $order_id );

			if ( !isset( $_POST[ 'rpcId' ] ) ) {
				// Remove cart
				WC()->cart->empty_cart();

				$order->update_status( 'pending-payment', __( 'Awaiting payment.', 'wc-gateway-nimiq' ) );

				if ( $this->get_option( 'rpc_behavior' ) === 'redirect' ) {
					// Redirect to Hub for payment

					$target = $this->get_option( 'network' ) === 'main' ? 'https://hub.nimiq.com' : 'https://hub.nimiq-testnet.com';
					$id = 42;
					$returnUrl = get_site_url() . '/wc-api/WC_Gateway_Nimiq?id=' . $order_id;
					$command = 'checkout';
					$args = [ $this->get_payment_request( $order_id ) ];
					$responseMethod = 'post';

					include_once( plugin_dir_path( __FILE__ ) . 'nimiq-utils/RpcUtils.php' );

					$url = Nimiq\Utils\RpcUtils::prepareRedirectInvocation(
						$target,
						$id,
						$returnUrl,
						$command,
						$args,
						$responseMethod,
					);

					return [
						'result'   => 'success',
						'redirect' => $url
					];
				}

				// Return payment-page redirect from where the Hub popup is opened
				return array(
					'result' 	=> 'success',
					'redirect'	=> $order->get_checkout_payment_url( $on_checkout = false )
				);
			}

			// Mark as on-hold (we're awaiting transaction validation)
			$order->update_status( 'on-hold', __( 'Awaiting transaction validation.', 'wc-gateway-nimiq' ) );

			// Reduce stock levels
			wc_reduce_stock_levels( $order_id );

			// Return thank-you redirect
			return array(
				'result' 	=> 'success',
				'redirect'	=> $this->get_return_url( $order )
			);
		}

		// Check if the store NIM address is set and show admin notice otherwise
		// Custom function not required by the Gateway
		public function do_store_nim_address_check() {
			if( $this->enabled == "yes" ) {
				if( empty( $this->get_option( 'nimiq_address' ) ) ) {
					echo '<div class="error notice"><p>'. sprintf( __( 'You must fill in your store\'s Nimiq address to be able to take payments in NIM. <a href="%s">Set your Nimiq address here.</a>', 'wc-gateway-nimiq' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=nimiq_gateway' ) ) .'</p></div>';
				}
			}
		}

		/**
		 * Enqueue script on WooCommerce settings pages
		 *
		 * @since 2.2.1
		 * @param string $hook - Name of the current admin page.
		 */
		public function enqueue_admin_settings_script( $hook ) {
			if ( $hook !== 'woocommerce_page_wc-settings' ) return;
			wp_enqueue_script( 'NimiqSettings', plugin_dir_url( __FILE__ ) . 'js/settings.js', [ 'jquery' ], $this->version(), true );
		}

	} // end WC_Gateway_Nimiq class

} // end wc_nimiq_gateway_init()

// Includes that register actions and filters and are thus self-calling
include_once( plugin_dir_path( __FILE__ ) . 'includes/nimiq_currency.php' );
include_once( plugin_dir_path( __FILE__ ) . 'includes/bulk_actions.php' );
include_once( plugin_dir_path( __FILE__ ) . 'includes/validation_scheduler.php' );
include_once( plugin_dir_path( __FILE__ ) . 'includes/webhook.php' );

// Utility classes called from other code
include_once( plugin_dir_path( __FILE__ ) . 'includes/order_utils.php' );
include_once( plugin_dir_path( __FILE__ ) . 'includes/crypto_manager.php' );
