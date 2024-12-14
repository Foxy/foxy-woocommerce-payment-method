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
        add_action( 'profile_update', [ $this, 'wp_foxy_update_customer_action' ] );
    }

    public function wp_foxy_new_customer_action($customer_id) {
        $customer_array = new WC_Customer( $customer_id );
        $customer = $customer_array->get_data();

        try {
            Foxy_Client::get_instance()->create_customer($customer);
        } catch (Exception $e) {
            $this->logger->log('debug', $e->getMessage(), array( 'source' => 'foxy-logs' ));
        }
    }

    public function wp_foxy_delete_customer_action($customer_id) {
        $customer_array = new WC_Customer( $customer_id );
        $customer = $customer_array->get_data();

        try {
            Foxy_Client::get_instance()->delete_customer($customer);
        } catch (Exception $e) {
            $this->logger->log('debug', $e->getMessage(), array( 'source' => 'foxy-logs' ));
        }
    }

    public function wp_foxy_update_customer_action($customer_id) {
        $customer_array = new WC_Customer( $customer_id );
        $customer = $customer_array->get_data();

        try {
            Foxy_Client::get_instance()->update_customer($customer);
        } catch (Exception $e) {
            $this->logger->log('debug', $e->getMessage(), array( 'source' => 'foxy-logs' ));
        }
    }
}