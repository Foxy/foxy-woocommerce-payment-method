<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

final class WC_Gateway_Foxy_Blocks_Support extends AbstractPaymentMethodType {
    // public WigWag_Client $client;
    private $gateway;
    protected $name = 'foxy';
    public $client;

    public function initialize(): void { 
        //echo 'aaya';die;
        $this->settings = get_option( 'woocommerce_foxy_settings', [] );
		$gateways       = WC()->payment_gateways->payment_gateways();
		$this->gateway  = $gateways[ $this->name ];
        // $this->settings = get_option( 'woocommerce_foxy_settings', [] );
        
        // $gateways = WC()->payment_gateways->payment_gateways();
		// $this->gateway  = $gateways[ $this->name ];

        // $this->gateway = new Foxy_Payment_Gateway();
        // $client_id = $this->gateway->get_option('client_id');
        // echo $client_id;die;
        // $client_secret = $this->gateway->get_option('client_secret');

        // $this->client = new WigWag_Client($client_id, $client_secret);
    }

    public function is_active(): bool {
        return true;
    }

    public function get_payment_method_script_handles() {
        $script_path = '/assets/js/frontend/blocks.js';
        $script_asset_path = FOXY_PLUGIN_PATH . '/assets/js/frontend/blocks.asset.php';

        $script_asset      = file_exists( $script_asset_path )
			? require( $script_asset_path )
			: array(
				'dependencies' => array(),
				'version'      => '1.2.0'
			);

        $script_url = FOXY_PLUGIN_URL . $script_path;
        // echo $script_url;die;
        $dependencies = [];
        wp_register_script(
            'wc-foxy-payments-blocks',
            $script_url,
			$script_asset[ 'dependencies' ],
			$script_asset[ 'version' ],
			true
        );

        // wp_enqueue_style(
        //     'wigwag-blocks-checkout-style',
        //     FOXY_PLUGIN_URL.'/build/index.css',
        //     [],
        //     filemtime(FOXY_PLUGIN_PATH.'/build/index.css')
        // );
        if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-foxy-payments-blocks', 'woocommerce-gateway-foxy', FOXY_PLUGIN_PATH . 'languages/' );
		}

		return [ 'wc-foxy-payments-blocks' ];
        // return ['wc-foxy-blocks-integration'];
    }

    	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	// public function get_payment_method_data() {
		// $js_params = WC_Stripe_Feature_Flags::is_stripe_ece_enabled()
		// 	? $this->get_express_checkout_javascript_params()
		// 	: $this->get_payment_request_javascript_params();
		// // We need to call array_merge_recursive so the blocks 'button' setting doesn't overwrite
		// // what's provided from the gateway or payment request configuration.
		// return array_replace_recursive(
		// 	$this->get_gateway_javascript_params(),
		// 	$js_params,
		// 	// Blocks-specific options
		// 	[
		// 		'icons'                          => $this->get_icons(),
		// 		'supports'                       => $this->get_supported_features(),
		// 		'showSavedCards'                 => $this->get_show_saved_cards(),
		// 		'showSaveOption'                 => $this->get_show_save_option(),
		// 		'isAdmin'                        => is_admin(),
		// 		'shouldShowPaymentRequestButton' => $this->should_show_payment_request_button(),
		// 		'button'                         => [
		// 			'customLabel' => $this->payment_request_configuration->get_button_label(),
		// 		],
		// 	]
		// );
	// }

    public function get_payment_method_data(): array {
        // require ($script_asset_path)

        $icon_url = FOXY_PLUGIN_URL.'/assets/foxy-logo.png';
        return [
            'title' => 'Foxy for woocommerce',
            'description' => 'foxy description',//$this->settings['description'],
            'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] )
            // 'icon_url' => $icon_url,
            // 'button' => [
            //     'customLabel' => 'some customization',
            // ],
        ];
    }
}
