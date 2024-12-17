<?php

/*
 * Plugin Name:          Foxy for WooCommerce
 * Description:          Use Foxy for WooCommerce to easily and securely accept Card payment on your WooCommerce store.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.0
 * WC requires at least: 8.0
 * WC tested up to:      9.3
 * Author:               Foxy.io
 * Author URI:           https://foxy.io
 * License:              GPL v3
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          Foxy
 */

defined('ABSPATH') || exit;

define('FOXY_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
define('FOXY_PLUGIN_PATH', untrailingslashit(plugin_dir_path(__FILE__)));

add_action('plugins_loaded', 'foxy_init_gateway', 11);
function foxy_init_gateway(): void {
    if (!class_exists('WC_Payment_Gateway')) {
        // WooCommerce is not defined
        return;
    }

    if (class_exists('Foxy_Payment_Gateway')) {
        // Already initialised
        return;
    }

    require_once FOXY_PLUGIN_PATH.'/includes/class-wc-foxy-gateway.php';
    require_once FOXY_PLUGIN_PATH.'/includes/class-wc-foxy-hooks.php';
    require_once FOXY_PLUGIN_PATH.'/includes/class-wc-foxy-client.php';
}

add_action( 'before_woocommerce_init', function() {
    // Declaring HPOS feature compatibility
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
    // Declaring cart and checkout blocks compatibility
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

// show settings link under foxy on plugin page
add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'foxy_plugin_admin_links');
function foxy_plugin_admin_links(array $links): array {
    $link = '<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=foxy').'">'.__('Settings', 'foxy').'</a>';
    array_unshift($links, $link);
    return $links;
}

// add foxy as payment method
add_filter('woocommerce_payment_gateways', 'add_foxy_gateway');
function add_foxy_gateway(array $methods): array {
    $methods[] = 'Foxy_Payment_Gateway';

    return $methods;
}

// add rest api endpoins which will be used for various purposes
add_action('rest_api_init', 'foxy_register_rest_api_routes');
function foxy_register_rest_api_routes(): void {

    // rest api endpoint to handle the callback once user returns to WC after the payment
    register_rest_route(
        'foxy/v1',
        '/callback',
        [
            'methods' => 'GET',
            'callback' => 'foxy_handle_callback',
            'permission_callback' => '__return_true',
        ]
    );

    // rest api endpoint to handle foxy SSO
    register_rest_route(
        'foxy/v1',
        '/sso',
        [
            'methods' => 'GET',
            'callback' => 'foxy_handle_sso',
            'permission_callback' => '__return_true',
        ]
    );

    // rest api endpoint to handle transaction webhooks from foxy
    register_rest_route(
        'foxy/v1',
        '/webhook/transaction',
        [
            'methods' => 'POST',
            'callback' => 'foxy_transaction_webhook',
            'permission_callback' => '__return_true',
        ]
    );
}

/**
 * Rest api endpoint handler function
 * for handling transaction webhook
 */
function foxy_transaction_webhook(WP_REST_Request $request) {
    $data = file_get_contents('php://input');
    $parsed_data = json_decode($data, true);
    
    $event = $_SERVER['HTTP_FOXY_WEBHOOK_EVENT'];
    $settings = get_option( 'woocommerce_foxy_settings', [] );
    
    $logger = wc_get_logger();

    $webhook_encryption_key = $settings['webhook_signature'];
    // Verify the webhook payload
    $signature = hash_hmac('sha256', $data, $webhook_encryption_key);

    // will unlock this later
    if (!hash_equals($signature, $_SERVER['HTTP_FOXY_WEBHOOK_SIGNATURE'])) {
        http_response_code(500);
        return new WP_REST_Response(
            "Signature verification failed - data corrupted",
            500,
            []
        );
    }

    if (!is_array($parsed_data)) {
        return get_wp_rest_response("No data", 500);
    }

    /**
     * check if we have an order in WC for the foxy transaction id we receive in webhook
     * If not found then return 400 error
     */
    $foxy_transaction_id = $parsed_data["id"];
    $logger->debug("Got Foxy transaction webhook `$event` for #$foxy_transaction_id", ['source' => ' foxy-logs']);

    if (!in_array($event, ['transaction/created', 'transaction/modified', 'transaction/captured', 'transaction/refunded', 'transaction/voided', 'transaction/refeed'])) {
        $logger->error("Received unsupported webhook `$event` for transaction #$foxy_transaction_id", ['source' => ' foxy-logs']);
        return get_wp_rest_response("webhook not supported", 400);
    }

    $args = array(
        'meta_key'      => 'foxy_transaction_id',
        'meta_value'    => $foxy_transaction_id,
        'meta_compare'  => '=',
    );

    // need to check this later. Might be that wc_get_orders() return boolean false if no orders found which will throw exception. If it returns empty array then this is okay
    $orders = wc_get_orders($args);
    
    if (!count($orders)) {
        try {
            /**
             * If we don't have foxy_transaction_id in order meta then it's possible that this transaction was created for past due settlement in Foxy
             * We will try to get Foxy subscription for this and check if we have corresponding subscription in WC
             */
            $foxy_client = Foxy_Client::get_instance();
            $foxy_subscription_id = $foxy_client->get_foxy_subscription_from_transaction_id($foxy_transaction_id);

            if (!$foxy_subscription_id) {
                \WC_Admin_Notices::add_custom_notice( "foxy-webhook-transaction-not-found", "Received a transaction webhook from Foxy for transaction #$foxy_transaction_id but no corresponding order was found in WC" );
                $logger->debug("WC order not found for Foxy Transaction #$foxy_transaction_id", ['source' => ' foxy-logs']);
                return get_wp_rest_response("WC order not found for Foxy Transaction #$foxy_transaction_id", 400);
            }
            
            $args = array(
                'meta_key'      => 'foxy_subscription_id',
                'meta_value'    => $foxy_subscription_id,
                'meta_compare'  => '=',
            );
        
            // need to check this later. Might be that wc_get_orders() return boolean false if no orders found which will throw exception. If it returns empty array then this is okay
            $orders = wc_get_orders($args);

            if (!count($orders)) {
                \WC_Admin_Notices::add_custom_notice( "foxy-webhook-transaction-not-found", "Received a transaction webhook from Foxy for transaction #$foxy_transaction_id (Foxy Subscription #$foxy_subscription_id was found) but no corresponding order was found in WC" );
                $logger->debug("WC order not found for Foxy Transaction #$foxy_transaction_id (Subscription #$foxy_subscription_id was found)", ['source' => ' foxy-logs']);
                return get_wp_rest_response("WC order not found for Foxy Transaction #$foxy_transaction_id", 400);
            }
        } catch (Exception $e) {
            \WC_Admin_Notices::add_custom_notice( "foxy-webhook-transaction-error", "Something went wrong while processing the Foxy transaction webhook received for <b>#$foxy_transaction_id</b>" );
            $logger->error($e->getMessage(), ['source' => ' foxy-logs']);
            return get_wp_rest_response("Something went wrong", 500);
        }
    }

    $order = $orders[0];
    
    if (update_order_status($order, $parsed_data['status'], $foxy_transaction_id)) {
        return get_wp_rest_response("OK");
    }
}

function update_order_status($order, $foxy_transaction_status, $foxy_transaction_id) {
    $logger = wc_get_logger();
    $order_status = "";
    switch (strtolower($foxy_transaction_status)) {
        case "captured":
        case "approved":
        case "authorized":
            $order_status = "completed";
            break;
        case "rejected":
        case "declined":
            $order_status = "failed";
            break;
        case "refunded":
        case "voided":
            $order_status = "refunded";
            break;
        case "pending";
            $order_status = "processing";
            break;
    }

    if (empty($order_status)) {
        $logger->warning("Order status `$foxy_transaction_status` not supported", ['source' => ' foxy-logs']);
        return false;
    }

    $wc_order_id = $order->get_id();
    if ($order->update_status( $order_status )) {
        $logger->debug("Order status for #$wc_order_id (Foxy transaction #$foxy_transaction_id) changed to $order_status", ['source' => ' foxy-logs']);  
    } else {
        $logger->error("Something went wrong while updating the status for #$wc_order_id (Foxy transaction #$foxy_transaction_id) to $order_status", ['source' => ' foxy-logs']);
        return false;
    }

    return true;
} 

/**
 * Rest api endpoint handler function
 * for handling foxy SSO
 */
function foxy_handle_sso(WP_REST_Request $request) {
    $settings = get_option( 'woocommerce_foxy_settings', [] );

    $foxy_payment_session_data = WC()->session->get('foxy_payment_session');

    $foxy_customer_id = $foxy_payment_session_data['customer_id'];
    $timestamp = time() + 300;
    $foxycart_secret_key = $settings['store_secret'];
    $auth_token = sha1($foxy_customer_id . '|' . $timestamp . '|' . $foxycart_secret_key);

    $foxy_payment_link = $foxy_payment_session_data['payment_link'] . '&' . http_build_query([
        'fc_auth_token' => $auth_token,
        'fc_customer_id' => $foxy_customer_id,
        'timestamp' => $timestamp,
    ]);

    wp_redirect($foxy_payment_link);
}

/**
 * add custom "voided" status which is not available by default in WC
 * most probably we won't need it and instead will use "refunded" in its place
 */
// add_filter( 'woocommerce_register_shop_order_post_statuses', 'foxy_register_custom_order_status' );
// function foxy_register_custom_order_status( $order_statuses ) {
//    $order_statuses['wc-voided'] = array(
//       'label' => 'Voided',
//       'public' => false,
//       'exclude_from_search' => false,
//       'show_in_admin_all_list' => true,
//       'show_in_admin_status_list' => true,
//     );
//    return $order_statuses;
// }
// add_filter( 'wc_order_statuses', 'foxy_show_custom_order_status_single_order_dropdown' );
// function foxy_show_custom_order_status_single_order_dropdown( $order_statuses ) {
//    $order_statuses['wc-voided'] = 'Voided';
//    return $order_statuses;
// }

/**
 * For testing only.
 * This will add minutes for subscription renewal periods
 */
// function eg_extend_subscription_period_intervals( $intervals ) {
//     $logger = wc_get_logger();
//     $logger->error(json_encode($intervals), ['source' => ' options']);
//     $intervals['minute'] = 'minutes';
//     return $intervals;
// }
// add_filter( 'woocommerce_subscription_available_time_periods', 'eg_extend_subscription_period_intervals' );

/**
 * Rest api endpoint handler function
 * for handling the redirection from foxy after payment is done
 */
function foxy_handle_callback(WP_REST_Request $request): WP_REST_Response {
    $logger = wc_get_logger();
    $foxy_transaction_id = $request->get_param('fc_order_id');
    $logger->debug("Got back from Foxy for Transaction ID: {$foxy_transaction_id}", ['source' => ' foxy-logs']);

    $args = array(
        'meta_key'      => 'foxy_transaction_id',
        'meta_value'    => $foxy_transaction_id,
        'meta_compare'  => '=',
    );
    $orders = wc_get_orders($args);
    $foxy_payment_session_data = WC()->session->get('foxy_payment_session');
    WC()->session->__unset('foxy_payment_session');

    /**
     * Check that we have order having meta data equal to foxy_transaction_id
     * Also check that this transaction id is same which we stored in session before redirecting the user to foxy
     * so that we don't do something undesired if user is changing the transaction id in the URL when we get redirected back from foxy
     */
    // need to check this later. Might be that wc_get_orders() return boolean false if no orders found which will throw exception. If it returns empty array then this is okay
    if (!count($orders) || $foxy_payment_session_data['foxy_transaction_id'] != $foxy_transaction_id) {
        $logger->error("Order not found for Foxy Transaction ID: {$foxy_transaction_id}", ['source' => ' foxy-logs']);
        wc_add_notice('Order not found', 'error');

        return foxy_generate_webhook_response(home_url());
    }

    $order = $orders[0];
    $foxy_client = Foxy_Client::get_instance();

    try {
        $payment_status = $foxy_client->get_payment_status($foxy_transaction_id);

        if (!$payment_status) {
            $logger->error('error while checking the status of transaction', ['source' => ' foxy-logs']);
            wc_add_notice('Something went wrong. Please contact the store.', 'error');
            return foxy_generate_webhook_response($order->get_view_order_url());
        }
    } catch (Exception $exception) {
        $logger->error($exception, ['source' => ' foxy']);
        wc_add_notice('Failed to check status of your payment. Please contact the store.', 'error');
        return foxy_generate_webhook_response($order->get_view_order_url());
    }
    $wc_order_id = $order->get_id();
    $logger->debug("Payment status is `$payment_status` for transaction #$foxy_transaction_id (WC Order #$wc_order_id)", ['source' => ' foxy-logs']);

    update_order_status($order, $payment_status, $foxy_transaction_id);

    if (!in_array($payment_status, ['captured', 'approved', 'authorized'])) {
        $logger->warning("Unpaid status ({$payment_status}) for transaction ID {$foxy_transaction_id}", ['source' => ' foxy-logs']);
        wc_add_notice('Payment not completed', 'error');
        return foxy_generate_webhook_response($order->get_view_order_url());
    }

    return foxy_generate_webhook_response($order->get_checkout_order_received_url());
}

function foxy_generate_webhook_response(string $url): WP_REST_Response {
    return new WP_REST_Response(
        null,
        302,
        [
            'Location' => $url,
        ]
    );
}

function get_wp_rest_response($data = null, $status = 200, $headers = array()) {
    return new WP_REST_Response(
        $data,
        $status,
        $headers
    );
}


add_action('woocommerce_blocks_loaded', 'foxy_load_gateway_block_support');
function foxy_load_gateway_block_support(): void {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once FOXY_PLUGIN_PATH.'/includes/class-wc-foxy-blocks-support.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new Foxy_Blocks_Support() );
        }
    );
    // add_action(
    //     'woocommerce_blocks_payment_method_type_registration',
    //     function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
    //         $container = Automattic\WooCommerce\Blocks\Package::container();
    //         // // registers as shared instance.
    //         $container->register(
    //             Foxy_Blocks_Support::class,
    //             function () {
    //                 return new Foxy_Blocks_Support();
    //             }
    //         );
    //         $payment_method_registry->register(
    //             $container->get(Foxy_Blocks_Support::class)
    //         );
    //     },
    // );
}