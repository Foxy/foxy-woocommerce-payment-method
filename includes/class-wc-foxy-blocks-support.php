<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

final class Foxy_Blocks_Support extends AbstractPaymentMethodType {
    // public WigWag_Client $client;
    private $gateway;
    protected $name = 'foxy';
    public $client;

    public function initialize(): void { 
        //echo 'aaya';die;
        $this->settings = get_option( 'woocommerce_foxy_settings', [] );
		$gateways       = WC()->payment_gateways->payment_gateways();
		$this->gateway  = $gateways[ $this->name ];
        
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
        $script_path = '/build/index.js';
        $script_asset_path = FOXY_PLUGIN_PATH.'/build/index.asset.php';
        $script_asset = file_exists($script_asset_path)
            ? require ($script_asset_path)
            : ['dependencies' => [], 'version' => filemtime(FOXY_PLUGIN_PATH.$script_path)];
        $script_url = FOXY_PLUGIN_URL.$script_path;

        $dependencies = [];
        wp_register_script(
            'foxy-blocks-integration',
            $script_url,
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );

        // wp_enqueue_style(
        //     'wigwag-blocks-checkout-style',
        //     FOXY_PLUGIN_URL.'/build/index.css',
        //     [],
        //     filemtime(FOXY_PLUGIN_PATH.'/build/index.css')
        // );

        return ['foxy-blocks-integration'];
    }



    public function get_payment_method_data(): array {
        // require ($script_asset_path)

        $icon_url = FOXY_PLUGIN_URL.'/assets/foxy-logo.png';
        return [
            'title' => 'Foxy for WooCommerce',
            'description' => $this->settings['description'],
            'icon_url' => $icon_url,
            'button' => [
                'customLabel' => 'some customization',
            ],
            'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] )
        ];
    }
}
