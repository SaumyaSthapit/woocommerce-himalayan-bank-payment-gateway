<?php
/*
 * Plugin Name: WooCommerce HBL Payment Gateway
 * Plugin URI: https://github.com/saumyasthapit/woocommerce-himalayan-bank-payment-gateway
 * Description: Take HBL credit card payments on your woocommerce store.
 * Author: Saumya Sthapit
 * Author URI: https://github.com/saumyasthapit
 * Version: 1.0.0
 */

//function myplugin_activate()
//{
//
//    // Activation code here...
//}
//
//register_activation_hook(__FILE__, 'myplugin_activate');
//
//function myplugin_deactivate()
//{
//
//    // Deactivation code here...
//}
//
//register_deactivation_hook(__FILE__, 'myplugin_deactivate');

/**
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter('woocommerce_payment_gateways', 'HBL_add_gateway_class');
function HBL_add_gateway_class($gateways)
{
    $gateways[] = 'WC_HBL_Gateway'; // Adding HBL Gateway
    return $gateways;
}

/**
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'HBL_init_gateway_class');
function HBL_init_gateway_class()
{

    class WC_HBL_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = "hbl";
            $this->icon = "https://www.himalayanbank.com/themes/himalayan/assets/img/logo.png";
            $this->method_title = "HBL Payment Gateway";
            $this->method_description = "HBL Payment Gateway Description";

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->secret_key = $this->settings['secret_key'];
            $this->merchant_id = $this->settings['merchant_id'];

//            add_action('init', array(&$this, 'check_hbl_response'));
            //update for woocommerce >2.0
            add_action('woocommerce_api_webhook-hbl', array($this, 'webhook'));
            add_action('woocommerce_api_webhook-hbl-frontend', array($this, 'webhookFrontEnd'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

//            add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

            add_action('woocommerce_receipt_' . $this->id, array(&$this, 'receipt_page'), 10, 1);
        }

        /**
         * Display order detail and button to redirect user to HBL website
         **/
        function receipt_page($order_id)
        {
            $order = new WC_Order($order_id);
            echo '<p>' . __('Thank you for your order, please click the button below to pay with Credit / Debit Cards', 'tech') . '</p>';

            $amount = sprintf('%010d', (int)$order->get_total()) . "00"; // padding total amount with zeros as per HBL requirement

            // preparing hash string
            $stringToHash = $this->merchant_id . $order_id . $amount . "524" . "Y";
            $hashValue = hash_hmac('SHA256', $stringToHash, $this->secret_key, false);
            $hashValue = strtoupper($hashValue);
            $hashValue = urlencode($hashValue);

            $paymentForm = "";
            $paymentForm .= '<form method="POST" action="https://hblpgw.2c2p.com/HBLPGW/Payment/Payment/Payment" id="hbl_payment_form">';
            $paymentForm .= '<input type="text" id="paymentGatewayID" name="paymentGatewayID" value="' . $this->merchant_id . '" />';
            $paymentForm .= '<input type="text" id="invoiceNo" name="invoiceNo" value="' . $order_id . '" />';
            $paymentForm .= '<input type="text" id="productDesc" name="productDesc" value="Online Payment" />';
            $paymentForm .= '<input type="text" id="amount" name="amount" value="' . $amount . '" />';
            $paymentForm .= '<input type="text" id="currencyCode" name="currencyCode" value="524" />';
            $paymentForm .= '<input type="text" id="nonSecure" name="nonSecure" value="Y" />';
            $paymentForm .= '<input type="text" id="hashValue" name="hashValue" value="' . $hashValue . '" /><br />';
            $paymentForm .= '<input type="submit" class="button-alt" id="submit_2c2p_payment_form" value="' . esc_html__('Pay', 'woocommerce') . '" />&nbsp;&nbsp;';
            $paymentForm .= '<a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . esc_html__('Cancel order &amp; restore cart', 'woocommerce') . '</a>';
            $paymentForm .= '</form>';

            echo $paymentForm;
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable / Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable HBL Payment', 'woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout . ', 'woocommerce'),
                    'default' => __('Cheque Payment', 'woocommerce'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Customer Message', 'woocommerce'),
                    'type' => 'textarea',
                    'default' => ''
                ),
                'secret_key' => array(
                    'title' => __('Secret Key', 'woocommerce'),
                    'type' => 'text',
                    'default' => '',
                    'description' => __('Secret Key Provided By Bank', 'woocommerce'),
                    'desc_tip' => true
                ),
                'merchant_id' => array(
                    'title' => __('Merchant ID', 'woocommerce'),
                    'type' => 'text',
                    'default' => ''
                )
            );
        }

        public function payment_scripts()
        {
        }

        public function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);

            return array(
                'result' => 'success',
//                'redirect' => add_query_arg('order_id', $order_id, add_query_arg('key', $order->order_key, "http://localhost/ads/payment/pay-with-hbl/"))
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        /**
         * Handle response from HBL server (for backend url) and update database accordingly
         **/
        public function webhook()
        {

            global $woocommerce;

            $paymentGatewayID = isset($_REQUEST['paymentGatewayID']) ? sanitize_text_field($_REQUEST['paymentGatewayID']) : null;
            $respCode = isset($_REQUEST['respCode']) ? sanitize_text_field($_REQUEST['respCode']) : null;
            $fraudCode = isset($_REQUEST['respCode']) ? sanitize_text_field($_REQUEST['respCode']) : null;
            $pan = isset($_REQUEST['Pan']) ? sanitize_text_field($_REQUEST['Pan']) : null;
            $amount = isset($_REQUEST['Amount']) ? sanitize_text_field($_REQUEST['Amount']) : null;
            $invoiceNo = isset($_REQUEST['invoiceNo']) ? sanitize_text_field($_REQUEST['invoiceNo']) : null;
            $tranRef = isset($_REQUEST['tranRef']) ? sanitize_text_field($_REQUEST['tranRef']) : null;
            $approvalCode = isset($_REQUEST['approvalCode']) ? sanitize_text_field($_REQUEST['approvalCode']) : null;
            $eci = isset($_REQUEST['Eci']) ? sanitize_text_field($_REQUEST['Eci']) : null;
            $dateTime = isset($_REQUEST['dateTime']) ? sanitize_text_field($_REQUEST['dateTime']) : null;
            $status = isset($_REQUEST['Status']) ? sanitize_text_field($_REQUEST['Status']) : "";

            $hashValue = isset($_REQUEST['hashValue']) ? sanitize_text_field($_REQUEST['hashValue']) : null;

            try {
//                $order = wc_get_order($invoiceNo);
                $order = new WC_Order($invoiceNo);
                if ($order) {
                    if ($this->validateResponseHash($_REQUEST)) { //checking data integrity via hash [SHA1]
                        if ($order->get_status() !== 'completed') {
                            $order->add_order_note(json_encode($_REQUEST));
                            if (in_array(strtoupper($status), array('AP', 'S', 'RS'))) { // check status from bank server
                                // check response status for valid codes
                                $order->payment_complete($tranRef);
                                echo "Transaction completed";
                            } else {
//                                $order->add_order_note("Transaction Status: " . $this->get_status_text($status));
                                $order->update_status('on-hold');
                                $woocommerce->cart->empty_cart();
                                echo "Transaction fail";
                            }
                        } else {
                            // Order status is completed / already completed
                        }
//            die($order->get_status());
//            $order = wc_get_order( $_GET['id'] );
//            $order->payment_complete();
//            $order->reduce_order_stock();
//
//            update_option('webhook_debug', $_GET);
                    } else {
                        // Invalid hash value detected
                        $order->add_order_note("Invalid hash value detected");
                        $order->add_order_note(json_encode($_REQUEST));
                    }
                } else {
                    // NO ORDER FOUND WITH THIS ID
                }
            } catch (Exception $e) {
                echo $e->getCode();
            }

            die("<br />__FINISH__");
        }

        /**
         * Handle response from HBL server (for frontend url) and redirect user to another page
         **/
        public function webhookFrontEnd()
        {
            global $woocommerce;

            $paymentGatewayID = isset($_REQUEST['paymentGatewayID']) ? sanitize_text_field($_REQUEST['paymentGatewayID']) : null;
            $respCode = isset($_REQUEST['respCode']) ? sanitize_text_field($_REQUEST['respCode']) : null;
            $fraudCode = isset($_REQUEST['respCode']) ? sanitize_text_field($_REQUEST['respCode']) : null;
            $pan = isset($_REQUEST['Pan']) ? sanitize_text_field($_REQUEST['Pan']) : null;
            $amount = isset($_REQUEST['Amount']) ? sanitize_text_field($_REQUEST['Amount']) : null;
            $invoiceNo = isset($_REQUEST['invoiceNo']) ? sanitize_text_field($_REQUEST['invoiceNo']) : null;
            $tranRef = isset($_REQUEST['tranRef']) ? sanitize_text_field($_REQUEST['tranRef']) : null;
            $approvalCode = isset($_REQUEST['approvalCode']) ? sanitize_text_field($_REQUEST['approvalCode']) : null;
            $eci = isset($_REQUEST['Eci']) ? sanitize_text_field($_REQUEST['Eci']) : null;
            $dateTime = isset($_REQUEST['dateTime']) ? sanitize_text_field($_REQUEST['dateTime']) : null;
            $status = isset($_REQUEST['Status']) ? sanitize_text_field($_REQUEST['Status']) : "";

            $hashValue = isset($_REQUEST['hashValue']) ? sanitize_text_field($_REQUEST['hashValue']) : null;

            try {
                $order = new WC_Order($invoiceNo);
                if ($order) {
//                    if ($this->validateResponseHash($_REQUEST)) {
                    if (in_array(strtoupper($status), array('AP', 'S', 'RS'))) {
                        // check response status for valid codes
                        echo $redirect_url = $this->get_return_url($order);
                        echo "<br />Transaction completed";
                    } else {
                        echo $redirect_url = $order->get_checkout_payment_url(true);
                        echo "<br />Transaction fail";
                    }
//                    } else {
//                        // Invalid hash value detected
//                        echo "Invalid hash value detected";
//                    }
                }
                header('Location: ' . $redirect_url);
            } catch (Exception $e) {
                die($e->getMessage());
            }
        }

        /**
         * @param $request
         * @return bool
         */
        public function validateResponseHash($request)
        {
            $stringToHash = null;
            $stringToHash .= $request["paymentGatewayID"];
            $stringToHash .= $request["respCode"];
            $stringToHash .= $request["fraudCode"];
            $stringToHash .= $request["Pan"];
            $stringToHash .= $request["Amount"];
            $stringToHash .= $request["invoiceNo"];
            $stringToHash .= $request["tranRef"];
            $stringToHash .= $request["approvalCode"];
            $stringToHash .= $request["Eci"];
            $stringToHash .= $request["dateTime"];
            $stringToHash .= $request["Status"];

            $signData = hash_hmac('SHA1', $stringToHash, $this->secret_key, false);
            $signData = strtoupper($signData);

            if (strcasecmp($signData, $request['hashValue']) == 0) return true;

            return false;
        }

        public function makeHashValue($signatureString, $secretKey)
        {
            $signData = hash_hmac('SHA256', $signatureString, $secretKey, false);
            $signData = strtoupper($signData);
            return urlencode($signData);
        }
    }
}

add_filter('plugin_action_links', 'hbl_add_action_plugin', 10, 5);
function hbl_add_action_plugin($actions, $plugin_file)
{
    static $plugin;

    if (!isset($plugin)) {
        $plugin = plugin_basename(__FILE__);
    }
    if ($plugin == $plugin_file) {

        $settings = array(
            'settings' => '<a href="admin.php?page=wc-settings&tab=checkout&section=hbl">' . esc_html__('Settings', 'woocommerce') . '</a> '
        );

        $actions = array_merge($settings, $actions);
    }

    return $actions;
}