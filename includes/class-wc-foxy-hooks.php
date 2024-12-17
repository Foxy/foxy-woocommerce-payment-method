<?php

defined('ABSPATH') || exit;

class Foxy_Hooks {
    
    private $logger;

    public function __construct() {
        $this->logger = wc_get_logger();
    }

    public function init_hooks() {
        add_action('user_register', [ $this, 'wp_foxy_new_customer_action' ] );
        add_action('delete_user', [ $this, 'wp_foxy_delete_customer_action' ] );

        /**
         * `profile_update` fires every time a user/customer gets modified regardless if the admin was updating it or if the user updated their details
         * It also fires before placing the order on checkout page.
         * `woocommerce_update_customer` fires when the customer updates themselves and it also gets fired at checkout before placing order just like `profile_update`
         * 
         * Why we require `woocommerce_update_customer` when `profile_update` fires at both places?
         * Because we are relying on WC_Customer class object in order to fetch the latest details of the User. When user is placing the order `profile_update` gets fired
         * before `woocommerce_update_customer` which means the WC_Customer object will have stale data if user has updated his shipping/billing details during checkout.
         * In order to solve this issue we will use `woocommerce_update_customer` hook along with `profile_update`.
         * In brief, `woocommerce_update_customer` hook will be used during the checkout process and `profile_update` will be used when admin is updating the user
         */
        add_action( 'profile_update', [ $this, 'wp_foxy_update_customer_action' ] );
        add_action( 'woocommerce_update_customer', [ $this, 'wp_foxy_woocommerce_update_customer_action' ] );
    }

    public function wp_foxy_new_customer_action($customer_id) {
        $customer_array = new WC_Customer( $customer_id );
        $customer = $customer_array->get_data();

        // when a user is created, profile_update also gets fired. In order to prevent unnecessary update call we will disable it
        remove_action( 'profile_update', [ $this, 'wp_foxy_update_customer_action' ] );
        try {
            Foxy_Client::get_instance()->create_customer($customer);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), array( 'source' => 'foxy-logs' ));
        }
    }

    public function wp_foxy_delete_customer_action($customer_id) {
        $customer_array = new WC_Customer( $customer_id );
        $customer = $customer_array->get_data();

        try {
            Foxy_Client::get_instance()->delete_customer($customer);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), array( 'source' => 'foxy-logs' ));
        }
    }

    public function wp_foxy_woocommerce_update_customer_action($customer_id){
        $this->wp_foxy_update_customer($customer_id);
    }

    public function wp_foxy_update_customer_action($customer_id) {
        // we want to use this hook only for the admin (see comment at hooks declarations)
        if (is_admin()) {
            $this->wp_foxy_update_customer($customer_id);
        }
    }

    public function wp_foxy_update_customer($customer_id) {
        $customer_array = new WC_Customer( $customer_id );
        $customer = $customer_array->get_data();

        try {
            Foxy_Client::get_instance()->update_customer($customer);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), array( 'source' => 'foxy-logs' ));
        }
    }
}