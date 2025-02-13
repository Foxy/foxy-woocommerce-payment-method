<?php

defined('ABSPATH') || exit;

class Foxy_Exception extends Exception {
    public function __construct(string $message, int $code = 0) {
        parent::__construct('[Foxy Exception] '.$message, $code);
    }
}

class Foxy_Failed_Request_Exception extends Foxy_Exception {
    public function __construct(Foxy_Response $response) {
        parent::__construct("Request failed: ({$response->status_code}) {$response->raw_body}");
    }
}

class Foxy_Failed_Authentication_Exception extends Foxy_Exception {
    public function __construct(Foxy_Response $response) {
        parent::__construct("Authentication failed: ({$response->status_code}) {$response->raw_body}");
    }
}

class Foxy_Payment_Link_Failed_Exception extends Foxy_Exception {
    public function __construct() {
        parent::__construct("Payment Link generation failed");
    }
}

class Foxy_Cart_Empty_Exception extends Foxy_Exception {
    public function __construct() {
        parent::__construct("Please add some products to the cart first!");
    }
}

class Foxy_Not_Found_Exception extends Foxy_Exception {
    public function __construct(string $message = "Not found!", int $code = 404) {
        parent::__construct($message, $code);
    }
}

class Foxy_Response {
    public $status_code;
    public $is_error;
    public $data;
    public $raw_body;

    public function __construct($raw_response, $status_code) {
        $this->status_code = gettype($status_code) === 'string' ? 0 : $status_code;
        $this->raw_body = $raw_response;
        $this->data = json_decode($raw_response, true);
        $this->is_error = $status_code >= 400;
    }
}

class Foxy_Client {
    private $base_url_test = "https://api.foxycart.test";
    // private $base_url_test = "https://api.foxycartdev.com";
    private $base_url_live = "https://api.foxycart.com";
    private $foxy_domain_test = "foxycart.test";
    // private $foxy_domain_test = "foxycartdev.com";
    private $foxy_domain_live = "foxycart.com";
    private $access_token;
    private $auth_token;
    private $customers_uri;
    private $carts_uri;
    private $store_domain;
    private $use_remote_domain;
    public $settings;
    private static $instance;
    private $logger;

    const ACCESS_TOKEN_EXPIRES_AT = "access_token_expires_at";
    const EXPIRES_IN = "expires_in";
    const REFRESH_TOKEN = "refresh_token";
    const ACCESS_TOKEN = "access_token";

    protected function __construct() {
        $settings = get_option( 'woocommerce_foxy_settings', [] );
        $this->client_id = $settings['client_id'];
        $this->client_secret = $settings['client_secret'];
        $this->refresh_token = $settings['refresh_token'];
        $this->access_token = $settings['access_token'];
        $this->logger = wc_get_logger();
        /**
         * temporary fix for the timeout error
         * (by default wp timeout for any request is 300 seconds but while interacting with foxy it might take more than that)
         */
        add_filter( 'http_request_timeout', function( $timeout ) { return 60; });
        $this->init_store();
    }

    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_store() {
        $api_home_response = $this->make_foxy_request($this->get_foxy_base_url(), 'GET');
        $store_uri = $api_home_response->data["_links"]["fx:store"]["href"];

        $store_home_response = $this->make_foxy_request($store_uri, 'GET');
        $this->customers_uri = $store_home_response->data["_links"]["fx:customers"]["href"];
        $this->carts_uri = $store_home_response->data["_links"]["fx:carts"]["href"];
        $this->store_domain = $store_home_response->data["store_domain"];
        $this->use_remote_domain = $store_home_response->data["use_remote_domain"];

        // this transient will be used for SSO purpose
        set_transient('foxy_store_secret', $store_home_response->data["webhook_key"], DAY_IN_SECONDS);

        // Initialize the transaction webhook 
        // $this->init_transaction_webhook($store_home_response->data["_links"]["fx:webhooks"]["href"]);

        // Check if SSO is enabled and enable it and set URL if not
        $this->init_sso($store_home_response->data, $store_uri);

        // enable the safe redirection to foxy checkout page
        $this->init_safe_redirection();
    }

    public function init_safe_redirection() {
        if ($this->store_domain) { 
            add_filter( 'allowed_redirect_hosts', array( $this, 'extend_allowed_domains_list' ) );
        }
    }

    public function extend_allowed_domains_list( $hosts ) {
        if ($this->use_remote_domain) {

        }
        $foxy_hosts = $this->use_remote_domain ? [$this->store_domain] : [ 
            $this->store_domain . "." . $this->foxy_domain_test,
            $this->store_domain . "." . $this->foxy_domain_live
        ];
        return array_merge( $hosts, $foxy_hosts );
    }

    public function init_sso($store_data, $store_uri) {
        $sso_url = site_url('index.php') . '?rest_route=/foxy/v1/sso';
        if (!$store_data["use_single_sign_on"] || $store_data["single_sign_on_url"] != $sso_url) {
            $store_response = $this->make_foxy_request($store_uri, "PATCH", [
                "use_single_sign_on" => true,
                "single_sign_on_url" => $sso_url
            ]);
        }
    }

    public function init_transaction_webhook($webhook_uri) {
        $webhook_response = $this->make_foxy_request($webhook_uri, 'GET');
        $all_webhooks = $webhook_response->data["_embedded"]["fx:webhooks"];
        $webhook_url = site_url('index.php') . '?rest_route=/foxy/v1/webhook/transaction';
        $webhook_name = "WC_Store_Transaction_Webhook";
        $webhook_format = "json";
        $webhook_resource = "transaction";
        $wc_webhooks = array_filter($all_webhooks, function($webhook) use($webhook_name, $webhook_format, $webhook_resource, $webhook_url) {
            return $webhook["format"] == $webhook_format 
                    && $webhook["name"] == $webhook_name
                    && $webhook["event_resource"] ==  $webhook_resource
                    && $webhook["url"] == $webhook_url;
        });
        
        if (count($wc_webhooks) === 0) {
            $encryption_key = wp_generate_password(32);
            $data = [
                "name" => $webhook_name,
                "format" => $webhook_format,
                "url" => $webhook_url,
                "encryption_key" => $encryption_key,
                "event_resource" => $webhook_resource
            ];
            $response = $this->make_foxy_request($webhook_uri, 'POST', $data);
            if ($response->status_code == '201') {
                set_transient('foxy_webhook_encryption_key', $encryption_key, DAY_IN_SECONDS);
            }
       } else {
            $encryption_key = reset($wc_webhooks)["encryption_key"];
            set_transient('foxy_webhook_encryption_key', $encryption_key, DAY_IN_SECONDS);
       }
    }
    
    public function get_foxy_base_url() {
        $options = $this->get_foxy_settings();
        return strtolower($options['is_test']) == 'yes' ? $this->base_url_test : $this->base_url_live;
    }

    /**
     * @throws Foxy_Failed_Request_Exception
     * @throws Foxy_Not_Found_Exception
     */
    public function get_payment_status($payment_id) {
        $response = $this->make_foxy_request($this->get_foxy_base_url() . "/transactions/$payment_id", 'GET');

        if ($response->status_code === 404) {
            throw new Foxy_Not_Found_Exception("Transaction not found!");
            return null;
        }

        if ($response->is_error) {
            throw new Foxy_Failed_Request_Exception($response);
        }

        return $response->data['status'];
    }

    public function update_customer($customer_data) {
        $wc_customer_id = $customer_data["id"];
        $user_meta = get_user_meta($wc_customer_id, "foxy-customer-id");
        
        $foxy_customer_id = count($user_meta) ? $user_meta[0] : "";

        if ($foxy_customer_id) {
            $data = [
                "email" => $customer_data["email"],
                "first_name" => $customer_data["first_name"],
                "last_name" => $customer_data["last_name"]
            ];

            $response = $this->make_foxy_request($this->get_foxy_base_url() . "/customers/$foxy_customer_id", 'PATCH', $data);
            $this->logger->debug("Foxy Customer #$foxy_customer_id (WC #$wc_customer_id) updated", array( 'source' => 'foxy-logs' ));
        } else {
            $foxy_customer_id = $this->create_customer($customer_data);
        }

        if (!$foxy_customer_id) {
            throw new Exception("Foxy customer Id not found for WC customer #$wc_customer_id");
            return false;
        }

        $this->update_default_addresses($foxy_customer_id, $customer_data);
    }

    public function update_default_addresses($foxy_customer_id, $customer_data) {

        if (!$foxy_customer_id) {
            return;
        }

        $wc_customer_id = $customer_data["id"];
        if (array_key_exists("billing", $customer_data)) {
            try {
                $foxy_billing_address = $this->prepare_address_data($customer_data["billing"]);
                $this->make_foxy_request($this->get_foxy_base_url() . "/customers/$foxy_customer_id/default_billing_address", 'PATCH', $foxy_billing_address);
                $this->logger->debug("Updated default billing address for Foxy Customer #$foxy_customer_id (WC #$wc_customer_id)", array( 'source' => 'foxy-logs' ));
            } catch (Exception $e) {
                $this->logger->error("Something went wrong while updating default billing address for Foxy Customer #$foxy_customer_id (WC #$wc_customer_id)", array( 'source' => 'foxy-logs' ));
            }
        }

        if (array_key_exists("shipping", $customer_data)) {
            try {
                $foxy_shipping_address = $this->prepare_address_data($customer_data["shipping"]);
                $this->make_foxy_request($this->get_foxy_base_url() . "/customers/$foxy_customer_id/default_shipping_address", 'PATCH', $foxy_shipping_address);
                $this->logger->debug("Updated default shipping address for Foxy Customer #$foxy_customer_id (WC #$wc_customer_id)", array( 'source' => 'foxy-logs' ));
            } catch (Exception $e) {
                $this->logger->error("Something went wrong while updating default shipping address for Foxy Customer #$foxy_customer_id (WC #$wc_customer_id)", array( 'source' => 'foxy-logs' ));
            }
        }
    }

    public function prepare_address_data($address) {
        $foxy_address = [];
        $foxy_address["first_name"] = $address["first_name"];
        $foxy_address["last_name"] = $address["last_name"];
        $foxy_address["company"] = $address["company"];
        $foxy_address["address1"] = $address["address_1"];
        $foxy_address["address2"] = $address["address_2"];
        $foxy_address["city"] = $address["city"];
        $foxy_address["region"] = $address["state"];
        $foxy_address["postal_code"] = $address["postcode"];
        $foxy_address["country"] = $address["country"];
        $foxy_address["phone"] = $address["phone"];

        return $foxy_address;
    }

    public function delete_customer($customer_data) {
        $user_meta = get_user_meta($customer_data["id"], "foxy-customer-id");
        // need to check this later. Might be that get_user_meta() return boolean false if no meta data found which will throw exception. If it returns empty array then this is okay
        $foxy_customer_id = count($user_meta) ? $user_meta[0] : "";

        if ($foxy_customer_id) {
            $data = [
                "email" => $customer_data["email"],
                "first_name" => $customer_data["first_name"],
                "last_name" => $customer_data["last_name"]
            ];

            try {
                $this->make_foxy_request($this->get_foxy_base_url() . "/customers/$foxy_customer_id", 'DELETE', $data);
                $this->logger->log('debug', "Foxy Customer #$foxy_customer_id deleted", array( 'source' => 'foxy-logs' ));
            } catch (Foxy_Failed_Request_Exception $e) {
                $this->logger->log('debug', $e->getMessage(), array( 'source' => 'foxy-logs' ));
            }
        } else {
            throw new Foxy_Not_Found_Exception("Customer #" . $customer_data["id"] . " not found in foxy!");
            return null;
        }
    }

    public function create_customer($customer_data) {
        $data = [
            "email" => $customer_data["email"],
            "first_name" => $customer_data["first_name"],
            "last_name" => $customer_data["last_name"]
        ];

        try {
            // check if a user with this email already exists in foxy
            $params = http_build_query(['email' => $customer_data["email"]]);
            $customer_response = $this->make_foxy_request($this->customers_uri . '?' . $params , 'GET');
            if ($customer_response->data["total_items"]) {
                $foxy_customer_id = $customer_response->data["_embedded"]["fx:customers"][0]["id"];
                if ($customer_data["id"]) {
                    $this->add_foxy_customer_id_to_wc_customer($customer_data["id"], $foxy_customer_id);
                }
                $this->logger->log('debug', "Foxy Customer #$foxy_customer_id found", array( 'source' => 'foxy-logs' ));
                return $foxy_customer_id;
            }

            $response = $this->make_foxy_request($this->customers_uri, 'POST', $data);

            if ($response->status_code == 201) {
                $foxy_customer_url = $response->data["_links"]["self"]["href"];
                $foxy_customer_url = explode("/", $foxy_customer_url);
                $foxy_customer_id = end($foxy_customer_url);

                // we won't have customer id if user is shopping as guest
                if ($customer_data["id"]) {
                    $this->add_foxy_customer_id_to_wc_customer($customer_data["id"], $foxy_customer_id);
                }
                $this->logger->log('debug', "Foxy Customer #$foxy_customer_id created", array( 'source' => 'foxy-logs' ));

                return $foxy_customer_id;
            }
        } catch (Foxy_Failed_Request_Exception $e) {   
            $this->logger->log('debug', $e->getMessage(), array( 'source' => 'foxy-logs' ));
        }
        
        return;
    }

    public function add_foxy_customer_id_to_wc_customer($wc_customer_id, $foxy_customer_id) {
        update_user_meta(
            $wc_customer_id,
            "foxy-customer-id",
            $foxy_customer_id
        );
    }

    public function get_foxy_subscription_from_transaction_id($foxy_transaction_id) {
        $response = $this->make_foxy_request($this->get_foxy_base_url() . "/transactions/$foxy_transaction_id?zoom=subscriptions", 'GET');

        if ($response->data["type"] == 'transaction') {
            if (!array_key_exists("_embedded", $response->data) || !count($response->data["_embedded"]["fx:subscriptions"])) {
                return null;
            }
            $foxy_subscription_link = $response->data["_embedded"]["fx:subscriptions"][0]["_links"]["self"]["href"];
        } else {
            if (!array_key_exists("fx:subscription", $response->data["_links"])) {
                return null;
            }
            $foxy_subscription_link = $response->data["_links"]["fx:subscription"]["href"];
        }
        
        $subscription_endpoint_parts = @explode("/", $foxy_subscription_link);
        $foxy_subscription_id = end($subscription_endpoint_parts);
        return $foxy_subscription_id ? $foxy_subscription_id : null;
    }

    public function get_subscription_from_order($order) {
        $subscriptions = wcs_get_subscriptions_for_order($order->get_id(), array( 'order_type' => 'any' ) );
        
        return reset($subscriptions);
    }

    public function get_foxy_sub_id_from_transaction_id($foxy_transaction_id) {
        try {
            $response = $this->make_foxy_request($this->get_foxy_base_url() . "/transactions/$foxy_transaction_id?zoom=subscriptions", 'GET');
            $foxy_subscription_link = $response->data["_embedded"]["fx:subscriptions"][0]["_links"]["self"]["href"];
            $subscription_endpoint_parts = @explode("/", $foxy_subscription_link);
            return end($subscription_endpoint_parts);
        } catch (Exception $exception) {
            $this->logger->log('debug', $exception->getMessage(), array( 'source' => 'foxy-logs' ));
            return null;
        }
    }

    public function get_foxy_subscription_id_from_wc_subscription($subscription) {
        $foxy_subscription_id = $subscription->get_meta('foxy_subscription_id');
        $subscription_id = $subscription->get_id();
        $parent_order = $subscription->get_parent();
        $parent_order_id = $subscription->get_parent_id();

        if (!$foxy_subscription_id) {
            $original_foxy_transaction_id = $parent_order->get_meta('foxy_transaction_id');
            if (!$original_foxy_transaction_id) {
                throw new Foxy_Not_Found_Exception("Foxy transaction for WC subscription #$subscription_id (WC Order #$parent_order_id) not found in foxy!");
                return null;
            }

            $this->logger->log('debug', "Foxy transaction Id #$original_foxy_transaction_id found for subscription #$subscription_id" , array( 'source' => 'foxy-logs' ));

            $foxy_subscription_id = $this->get_foxy_sub_id_from_transaction_id($original_foxy_transaction_id);
        }

        if (!$foxy_subscription_id) {
            throw new Foxy_Not_Found_Exception("Foxy subscription for WC subscription #$subscription_id (WC Order #$parent_order_id) not found in foxy!");
            return null;
        }

        $this->logger->log('debug', "Foxy subscription #$foxy_subscription_id fetched for WC subscription #$subscription_id", array( 'source' => 'foxy-logs' ));
        $subscription->update_meta_data( 'foxy_subscription_id', $foxy_subscription_id );
        $subscription->save();

        if (!$parent_order->get_meta('foxy_subscription_id')) {
            $parent_order->update_meta_data( 'foxy_subscription_id', $foxy_subscription_id );
            $parent_order->save();
        }

        return $foxy_subscription_id;
    }

    public function renew_subscription($amount, $renewal_order) {
        try {
            $subscription = $this->get_subscription_from_order($renewal_order);
            $subscription_id = $subscription->get_id();
            
            $foxy_subscription_id = $this->get_foxy_subscription_id_from_wc_subscription($subscription);

            // We will add foxy subscription ID as meta data to WC transaction and subscription just in case we need it somewhere later
            $renewal_order->update_meta_data( 'foxy_subscription_id', $foxy_subscription_id );
            $renewal_order->save();

            $foxy_subscription_link = $this->get_foxy_base_url() . "/subscriptions/$foxy_subscription_id";
            $response = $this->make_foxy_request($foxy_subscription_link, 'PATCH', ['past_due_amount' => $amount]);
            
            if ($response->status_code == 200) {
                $this->logger->debug("Past due sent for subscription #$subscription_id (Foxy subscription #$foxy_subscription_id)", array( 'source' => 'foxy-logs' ));
                $past_due_charge_link = array_key_exists("fx:charge_past_due", $response->data["_links"]) ? $response->data["_links"]["fx:charge_past_due"]["href"] : "";

                if (empty($past_due_charge_link)) {
                    $this->logger->debug("No past due charge found for subscription #$subscription_id (Foxy subscription #$foxy_subscription_id)", array( 'source' => 'foxy-logs' ));
                    return;
                }
                
                $this->make_foxy_request($past_due_charge_link, 'POST');
            }
        }  catch (Exception $e) {   
            $renewal_order->update_status( 'failed', __( 'Subscription renewal failed on Foxy.', 'woocommerce-gateway-foxy' ) );
            $this->logger->log('debug', $e->getMessage(), array( 'source' => 'foxy-logs' ));
            throw new Exception("Something went wrong while sending subscription <b>#$subscription_id</b> (Foxy subscription <b>#$foxy_subscription_id</b>) for renewal");
            return null;
        }
    }

    public function cancel_subscription($foxy_subscription_id) {
        try {
            $sub_endpoint = $this->get_foxy_base_url() . "/subscriptions/$foxy_subscription_id";
            $sub_response = $this->make_foxy_request($sub_endpoint, "PATCH", ["is_active" => false ]);
            if ($sub_response->status_code == '200') {
                $this->logger->debug("Foxy subscription #$foxy_subscription_id deactivated", ['source' => 'foxy-logs']);    
            } else {
                $this->logger->error("Something went wrong while deactivating Foxy subscription #$foxy_subscription_id", ['source' => 'foxy-logs']);    
            }
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage(), ['source' => 'foxy-logs']);
        }
    }

    public function update_foxy_transaction_status($foxy_transaction_id, $status, $order_id) {
        try {
            $foxy_status = $status == 'voided' ? 'void' : 'refund';
            $transaction_endpoint = $this->get_foxy_base_url() . "/transactions/$foxy_transaction_id";
            $transaction_response = $this->make_foxy_request($transaction_endpoint, "GET");
            $cancellation_endpoint = $status == 'voided' ? $transaction_response->data["_links"]["fx:void"]["href"] : $transaction_response->data["_links"]["fx:refund"]["href"];

            if (!$cancellation_endpoint) {
                \WC_Admin_Notices::add_custom_notice( "foxy-order-status-change-not-allowed", "WC order <b>#$order_id</b> was updated to <b>$status</b> but this action is not allowed in Foxy (Foxy Transaction <b>#$foxy_transaction_id</b>)" );
                $this->logger->error("<b>$foxy_status</b> action not allowed for Foxy transaction #$foxy_transaction_id", ['source' => 'foxy-logs']);
                return;
            }

            $status_change_response = $this->make_foxy_request($cancellation_endpoint, "POST");
            if ($status_change_response->status_code == '200') {
                $this->logger->debug("Foxy transaction #$foxy_transaction_id was $status", ['source' => 'foxy-logs']);    
            }

        } catch (Exception $exception) {
            \WC_Admin_Notices::add_custom_notice( "foxy-order-status-change-error", "WC order #$order_id was updated to <b>$status</b> but failed to update the status in Foxy" );
            $this->logger->error($exception->getMessage(), ['source' => 'foxy-logs']);
            $this->logger->error("Something went wrong while changing the status of Foxy transaction #$foxy_transaction_id (WC #$order_id) to $status", ['source' => 'foxy-logs']);    
        }
    }

    public function create_foxy_payment_link($order_id, $is_payment_method_change = false) {
        if ($is_payment_method_change) {
            /**
             * Maybe we need to do it in a better way. 
             * For code reusability we are using same method for creating payment link for payment method change as well for generating foxy checkout link
             */
            $subscription_id = $order_id;
            $subscription = new WC_Subscription($subscription_id);
        } else {
            $order = new WC_Order($order_id);
            $order->update_status('awaiting-payment', __('Awaiting payment', 'woocommerce'));
        }
        $cart_items = WC()->cart->get_cart();
        
        if (count($cart_items) || $is_payment_method_change) {
            $cart_response = $this->make_foxy_request($this->carts_uri, "POST", []);
            $self_endpoint = $cart_response->data["_links"]["self"]["href"];
            $items_endpoint = $cart_response->data["_links"]["fx:items"]["href"];
            $session_endpoint = $cart_response->data["_links"]["fx:create_session"]["href"];

            $self_endpoint_parts = @explode("/", $self_endpoint);
            $transaction_id = end($self_endpoint_parts);
            $this->logger->debug("Transaction got created with #$transaction_id", array( 'source' => 'foxy-logs' ));

            if ($is_payment_method_change) {
                $parent_order = $subscription->get_parent();
                // its possible that the current subscription was migrated before payment method change. In this case there won't be any parent id
                if ($parent_order) {
                    $parent_order->update_meta_data( 'foxy_transaction_id', $transaction_id );
                    $parent_order->save();
                }

                $subscription->update_meta_data( 'foxy_transaction_id', $transaction_id );
                $subscription->save();
                $this->logger->debug("Added {'foxy_transaction_id' : $transaction_id} to parent order of WC subscription #$subscription_id", array( 'source' => 'foxy-logs' ));    
            } else {
                $order->update_meta_data( 'foxy_transaction_id', $transaction_id );
                $order->save();
                $this->logger->log('debug', "Added {'foxy_transaction_id' : $transaction_id} to WC order #$order_id", array( 'source' => 'foxy-logs' ));
            }

            $item_data = [];
            if ($is_payment_method_change) {
                $item_data["name"] = "WC Subscription #$subscription_id";
                $item_data["price"] = $subscription->get_total('edit');
                $item_data["subscription_frequency"] = '10y';
                $next_payment_date = $subscription->calculate_date('next_payment');
                $item_data["subscription_start_date"] = date("Y-m-d", strtotime($next_payment_date));
            } else {
                $item_data["name"] = "WC Order #$order_id";
                $item_data["price"] = WC()->cart->total;
                $item_data["url"] = wc_get_cart_url();
                if (WC_Subscriptions_Order::order_contains_subscription($order_id)) {
                    $item_data["subscription_frequency"] = '10y';
                }
            }

            $this->make_foxy_request($items_endpoint, "POST", $item_data);

            $session_response = $this->make_foxy_request($session_endpoint, "POST", []);
            $foxy_customer_id = $this->get_current_foxy_customer_id();

            /**
             * It's possible that the current WC user is not mapped in Foxy, or the user is shopping in guest mode
             * If that's the case then we will check if user exists in Foxy, will create it if not
             */
            if (!$foxy_customer_id || !is_user_logged_in()) {
                $foxy_customer_id = $this->create_customer([
                    "id" => $is_payment_method_change ? $subscription->get_customer_id() : get_current_user_id(),
                    "email" => $is_payment_method_change ? $subscription->get_billing_email() : $order->get_billing_email(),
                    "first_name" => $is_payment_method_change ? $subscription->get_billing_first_name() : $order->get_billing_first_name(),
                    "last_name" => $is_payment_method_change ? $subscription->get_billing_last_name() : $order->get_billing_last_name()
                ]);
            }

            $timestamp = time() + 600;
            $foxycart_secret_key = get_transient('foxy_store_secret');

            $auth_token = sha1($foxy_customer_id . '|' . $timestamp . '|' . $foxycart_secret_key);
            $payment_link = $session_response->data["cart_link"];
            $payment_link = str_replace('/cart?', '/checkout?', $payment_link);

            $session_data = [
                'foxy_transaction_id' => $transaction_id,
                'payment_link' => $payment_link,
                'customer_id' => $foxy_customer_id,
                'attempt' => 1
            ];

            if ($is_payment_method_change) {
                $session_data['change_payment_method'] = true;
                $session_data['wc_subscription_id'] = $subscription_id;
            }

            WC()->session->set('foxy_payment_session', $session_data);

            return $payment_link . '&' . http_build_query([
                'fc_auth_token' => $auth_token,
                'fc_customer_id' => $foxy_customer_id,
                'timestamp' => $timestamp,
            ]);
        } else {
            throw new Exception("Cart is empty");
        }
    }

    public function get_current_foxy_customer_id() {
        $user_ID = get_current_user_id();
        $user_meta = get_user_meta($user_ID, "foxy-customer-id");
        return $user_meta && count($user_meta) ? $user_meta[0] : null;
    }

    private function refresh_token() {
        $options = $this->get_foxy_settings();
        $this->auth_token = base64_encode($options["client_id"] . ":" . $options["client_secret"]);
        $data = [
            "grant_type" => self::REFRESH_TOKEN,
            "refresh_token" => $options[self::REFRESH_TOKEN]
        ];
        
        try {
            $token_response = $this->make_foxy_request($this->get_foxy_base_url() . "/token", "POST", $data, true);
            $this->access_token = $token_response->data[self::ACCESS_TOKEN];
            $token_expires_at = time() + intval($token_response->data[self::EXPIRES_IN]);
            $this->update_foxy_settings([
                self::ACCESS_TOKEN_EXPIRES_AT => $token_expires_at,
                self::ACCESS_TOKEN => $this->access_token
            ]);
            $this->logger->log('debug', 'access_token updated', array( 'source' => 'foxy-logs' ));
        } catch (Foxy_Failed_Request_Exception $e) {   
            $this->logger->log('debug', $e->getMessage(), array( 'source' => 'foxy-logs' ));
        }
    }

    private function should_refresh_token() {
        $options = $this->get_foxy_settings();

        if (!array_key_exists(self::ACCESS_TOKEN_EXPIRES_AT, $options) || empty($options[self::ACCESS_TOKEN_EXPIRES_AT])) {
            return true;
        }

        $access_token_expires_at = $options[self::ACCESS_TOKEN_EXPIRES_AT];
        if ((intval($access_token_expires_at) - intval(time())) < 300 ) {
            return true;
        }

        return false;
    }

    /**
     * @throws Foxy_Failed_Request_Exception
     * @return Foxy_Response
     */
    private function make_foxy_request(
        string $endpoint,
        string $method,
        array $data = [],
        bool $refreshToken = false
    ): Foxy_Response {
        $headers = ['Content-Type' => 'application/json', 'FOXY-API-VERSION' => 1];
        
        if ($refreshToken) {
            $headers['Authorization'] = 'Basic ' . $this->auth_token;
            // NOTE: multipart/form-data is somehow not working. Maybe will check back again sometime in future
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            // $headers['Content-Type'] = "multipart/form-data;";
            $body = $data;
        } else {
            if ($this->should_refresh_token()) {
                $this->refresh_token();
            }
            $headers['Authorization'] = 'Bearer ' . $this->access_token;
            $body = $method !== 'GET' ? wp_json_encode($data) : null;
        }

        $result = wp_remote_request($endpoint, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body
        ]);

        // $this->logger->log('debug', json_encode($result), array( 'source' => 'foxy-logs' ));

        $body = wp_remote_retrieve_body($result);
        $response_code = wp_remote_retrieve_response_code($result);
        $response = new Foxy_Response($body, $response_code);

        if (is_wp_error($result) || $response->is_error) {
            throw new Foxy_Failed_Request_Exception($response);
        }

        return $response;
    }

    /**
	 * Get the main Foxy settings option.
	 * @return array $settings The Stripe settings.
	 */
	public static function get_foxy_settings() {
        $settings = get_option( 'woocommerce_foxy_settings', [] );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}
		return $settings;
	}

    public static function update_foxy_settings($new_settings = []) {
        $current_settings = get_option( 'woocommerce_foxy_settings', [] );
        update_option( 'woocommerce_foxy_settings', array_merge($current_settings, $new_settings) );
    }
}
