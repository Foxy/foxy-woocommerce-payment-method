<?php

defined('ABSPATH') || exit;

class Foxy_Payment_Gateway extends WC_Payment_Gateway {
    private $logger;
    public $has_fields;

    const DEFAULT_DESCRIPTION = "Pay securely by Credit or Debit card or PayPal through Foxy.";
    const DEFAULT_TITLE = "Make payments using Foxy";
    
    /**
     * Notices (array)
     *
     * @var array
     */
    public $notices = array();
    public function __construct() {
        

        $this->id = 'foxy';
        $this->icon = FOXY_PLUGIN_URL.'/assets/foxy-logo.png';
        $this->has_fields = true;
        $this->countries = ['USA'];
        $this->supports = [
            'products', 
            'subscriptions',
            'subscription_cancellation', 
            'subscription_suspension', 
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
        ];
        $this->method_title = 'Foxy';
        $this->method_description = 'Pay with Foxy';

        // Load the settings.
		$this->init_form_fields();
		$this->init_settings();

        $this->title = empty($this->get_option( 'title' )) ? static::DEFAULT_TITLE : $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        
        $this->includes();
        $this->logger = wc_get_logger();

        $hooks = new Foxy_Hooks();
        $hooks->init_hooks();
        
        add_action( 'woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
        add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'process_subscription_renewal_order' ), 10, 2 );
        add_action( 'woocommerce_subscription_status_updated', array( $this, 'process_subscription_status_update' ), 10, 3 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'process_order_status_update' ), 10, 3);
    }

    public function process_order_status_update($order_id, $old_status, $new_status) {
        if (in_array($old_status, ['completed']) && in_array($new_status, ['cancelled', 'refunded', 'voided'])) {
            try {
                $order = wc_get_order( $order_id );
                $foxy_transaction_id = $order->get_meta('foxy_transaction_id');

                if (!$foxy_transaction_id) {
                    \WC_Admin_Notices::add_custom_notice( "foxy-order-status-change-transaction-not-found", "No Foxy transaction Id found corresponding to WC order #$order_id" );
                    $this->logger->error("Foxy transaction Id not found for WC Order #$order_id", ['source' => 'foxy-logs']);
                    return null;
                }
                $this->logger->debug("Updating status of Foxy transaction #$foxy_transaction_id to $new_status", ['source' => 'foxy-logs']);

                $foxy_client = Foxy_Client::get_instance();
                $foxy_client->update_foxy_transaction_status($foxy_transaction_id, $new_status, $order_id);
            } catch (Exception $exception ) {
                \WC_Admin_Notices::add_custom_notice( "foxy-order-status-change-error", "Something went wrong while updating the Foxy transaction corresponding to WC order #$order_id" );
                $this->logger->error($exception->getMessage(), ['source' => 'foxy-logs']);
            }
        }
    }

    public function process_subscription_status_update($subscription, $new_status, $old_status) {
        if (in_array($new_status, ['cancelled', 'expired'])) {
            try {
                $foxy_client = Foxy_Client::get_instance();
                $foxy_subscription_id = $foxy_client->get_foxy_subscription_id_from_wc_subscription($subscription);
                $foxy_client->cancel_subscription($foxy_subscription_id);
            } catch (Exception $exception ) {
                $this->logger->error($exception->getMessage(), ['source' => 'foxy-logs']);
            }
        }
    }

    public function has_fields() {
		return (bool) $this->has_fields;
	}

    public function get_description() {
        return apply_filters( 'woocommerce_gateway_description', wp_kses_post( $this->description ), $this->id );
    }

    public function needs_setup(): bool {
        return !$this->get_option('client_id') || !$this->get_option('client_secret');
    }

    public function includes(): void {
        require_once plugin_dir_path(__FILE__).'class-wc-foxy-client.php';
    }

    /**
     * We might need this to inform about setting up the webhooks in Foxy/or the redirect URL in Foxy receipts
     */
    public function admin_options(): void {
        parent::admin_options();
        ?>
            <h4>Redirect URL</h4>
            <p>Make sure to add this as a redirect URL on your Foxy account page</p>
            <p>
                <?php echo site_url('index.php'); ?>
            </p>
        <?php
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'foxy'),
                'type' => 'checkbox',
                'label' => __('Enable Foxy Payments', 'foxy'),
                'default' => 'yes',
            ],
            'is_test' => [
                'title' => __('Is Test', 'foxy'),
                'type' => 'checkbox',
                'label' => __('Using Foxy payments in test mode?', 'foxy'),
                'default' => 'yes',
            ],
            'client_id' => [
                'title' => __('Client ID', 'foxy'),
                'type' => 'text',
                'description' => __('Foxy Client ID', 'foxy'),
                'desc_tip' => true,
                'custom_attributes' => [
                    'required' => 'true',
                ],
            ],
            'client_secret' => [
                'title' => __('Client Secret', 'foxy'),
                'type' => 'password',
                'description' => __('Foxy Client Secret', 'foxy'),
                'desc_tip' => true,
                'custom_attributes' => [
                    'required' => 'true',
                ],
            ],
            'access_token' => [
                'title' => __('Access Token', 'foxy'),
                'type' => 'password',
                'description' => __('Foxy Access Token', 'foxy'),
                'desc_tip' => true,
                'custom_attributes' => [
                    'required' => 'true',
                ],
            ],
            'refresh_token' => [
                'title' => __('Refresh Token', 'foxy'),
                'type' => 'password',
                'description' => __('Foxy Refresh Token', 'foxy'),
                'desc_tip' => true,
                'custom_attributes' => [
                    'required' => 'true',
                ],
            ],
            'store_secret' => [
                'title' => __('Store Secret', 'foxy'),
                'type' => 'password',
                'description' => __('Foxy Secret Store which can be found on advanced settings page in Foxy admin.', 'foxy'),
                'custom_attributes' => [
                    'required' => 'true',
                ],
            ],
            'title' => array(
                'title' => __('Title', $this->id),
                'type' => 'textarea',
                'description' => __('This controls the title which the user sees on receipt and payment history.', $this->id),
                'default' => __(static::DEFAULT_TITLE, $this->id)
            ),
            'description' => array(
                'title' => __('Description', $this->id),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', $this->id),
                'default' => __(static::DEFAULT_DESCRIPTION, $this->id)
            ),
            'skip_checkout' => [
                'title' => __('Skip Checkout Page', 'foxy'),
                'type' => 'checkbox',
                'label' => __('Skip the Foxy checkout page', 'foxy'),
                'default' => 'yes',
            ],
        ];
    }


    /**
	 * Process subscription payment.
	 *
	 * @param  float     $amount
	 * @param  WC_Order  $order
	 * @return void
	 */
	public function process_subscription_renewal_order( $amount, $renewal_order ) {
		try {
            $foxy_client = Foxy_Client::get_instance();
            $foxy_client->renew_subscription( $amount, $renewal_order);
        } catch (Exception $exception) {
            \WC_Admin_Notices::add_custom_notice( "foxy-subscription-renewal-failed", $exception->getMessage() );
            $this->logger->error($exception->getMessage(), ['source' => 'foxy-logs']);
            return null;
        }
	}

    public function process_payment($order_id) {
        $order = new WC_Order($order_id);
        $order->update_status('awaiting-payment', __('Awaiting payment', 'woocommerce'));
        
        try {
            $foxy_client = Foxy_Client::get_instance();
            $url = $foxy_client->create_foxy_payment_link($order);
        } catch (Exception $exception) {
            $this->handle_api_error($exception, 'Payment failed. Please try again later.');
            return null;
        }

        return [
            'result' => 'success',
            'redirect' => $url
        ];
    }

    private function handle_api_error(Exception $exception, string $message): void {
        $this->logger->error($exception, ['source' => 'foxy-logs']);
        wc_add_notice($message, 'error');
    }
}
