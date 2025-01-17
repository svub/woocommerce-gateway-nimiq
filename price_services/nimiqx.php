<?php
include_once( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'interface.php' );

class WC_Gateway_Nimiq_Price_Service_Nimiqx implements WC_Gateway_Nimiq_Price_Service_Interface {

    private $api_endpoint = 'https://api.nimiqx.com/';
    private $api_key = false;

    /**
     * Initializes the validation service
     *
     * @param {WC_Gateway_Nimiq} $gateway - A WC_Gateway_Nimiq class instance
     *
     * @return {void}
     */
    public function __construct( $gateway ) {
        $this->gateway = $gateway;

        $this->api_key = $gateway->get_option( 'nimiqx_api_key' );
        if ( empty( $this->api_key ) ) {
            throw new Exception( __( 'API key not set.', 'wc-gateway-nimiq') );
        }
        if ( !ctype_xdigit( $this->api_key ) ) {
            throw new Exception( __( 'Invalid API key.', 'wc-gateway-nimiq') );
        }
    }

    /**
     * @param {string} $currency
     * @return {float}
     */
    public function getCurrentPrice( $currency ) {
        $currency = strtolower( $currency );
        $api_response = wp_remote_get( $this->makeUrl( 'price/' . $currency ) );

        if ( is_wp_error( $api_response ) ) {
            return $api_response;
        }

        $result = json_decode( $api_response[ 'body' ], true );

        if ( isset($result['error']) && $result['error'] ) {
            return new WP_Error( 'service', $result['error'] );
        }

        $price = $result[ $currency ];

        if ( empty( $price ) ) {
            return new WP_Error( 'service', sprintf( __( 'The currency %s is not supported by NimiqX.', 'wc-gateway-nimiq' ), strtoupper( $currency ) ) );
        };

        return $price;
    }

    private function makeUrl( $path ) {
        return 'https://api.nimiqx.com/' . $path . '?api_key=' . $this->api_key;
    }
}
