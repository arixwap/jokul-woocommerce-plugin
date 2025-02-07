<?php

require_once(DOKU_JOKUL_PLUGIN_PATH . '/Service/JokulCheckoutService.php');
require_once(DOKU_JOKUL_PLUGIN_PATH . '/Service/JokulCheckStatusService.php');
require_once(DOKU_JOKUL_PLUGIN_PATH . '/Common/JokulDb.php');
require_once(DOKU_JOKUL_PLUGIN_PATH . '/Common/JokulUtils.php');

class JokulCheckoutModule extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->init_form_fields();
        $this->id                   = 'jokul_checkout';
        $this->has_fields           = true;
        $this->method_name          = 'Jokul Checkout';
        $this->method_code          = 'JOKUL_CHECKOUT';
        $this->title                = !empty($this->get_option('channel_name')) ? $this->get_option('channel_name') : $this->method_name;
        $this->method_title         = __('Jokul', 'woocommerce-gateway-jokul');
        $this->method_description   = sprintf(__('Accept payment through various payment channels with Jokul. Make it easy for your customers to purchase on your store.', 'woocommerce'));
        $this->checkout_msg         = 'This your payment on Jokul Checkout : ';

        $this->init_settings();
        $mainSettings = get_option('woocommerce_jokul_gateway_settings');
        $this->environmentPaymentJokul = isset($mainSettings['environment_payment_jokul']) ? $mainSettings['environment_payment_jokul'] : null;
        $this->sandboxClientId = isset($mainSettings['sandbox_client_id']) ? $mainSettings['sandbox_client_id'] : null;
        $this->sandboxSharedKey = isset($mainSettings['sandbox_shared_key']) ? $mainSettings['sandbox_shared_key'] : null;
        $this->prodClientId = isset($mainSettings['prod_client_id']) ? $mainSettings['prod_client_id'] : null;
        $this->prodSharedKey = isset($mainSettings['prod_shared_key']) ? $mainSettings['prod_shared_key'] : null;
        $this->expiredTime = isset($mainSettings['expired_time']) ? $mainSettings['expired_time'] : null;
        $this->emailNotifications = isset($mainSettings['email_notifications']) ? $mainSettings['email_notifications'] : null;

        $this->enabled = $this->get_option('enabled');
        $this->channelName = $this->get_option('channel_name');
        $paymentDescription = $this->get_option('payment_description');

        $this->payment_method = $this->get_option('payment_method');
        $this->auto_redirect_jokul = $this->get_option('auto_redirect_jokul');

        $this->sac_check = isset($mainSettings['sac_check' ]) ? $mainSettings['sac_check' ] : null;
        $this->sac_textbox = isset($mainSettings['sac_textbox']) ? $mainSettings['sac_textbox'] : null;

        if (empty($paymentDescription)) {
            $this->paymentDescription   = 'Bayar Pesanan Dengan Jokul Checkout';
        } else {
            $this->paymentDescription = $paymentDescription;
        }

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        $haystack = explode("&", $_SERVER['QUERY_STRING']);
        if( WC()->session != null ){
                $chosen_payment_method = WC()->session->get('chosen_payment_method');
            if ($chosen_payment_method == 'jokul_checkout') {
                if (isset($haystack[1]) && $haystack[1] == 'jokul=show') {
                    add_filter('the_title',  array($this, 'woo_title_order_pending'));
                    add_action('woocommerce_thankyou_' . $this->id, array($this, 'thank_you_page_pending'), 1, 10);
                } else {
                    add_filter('the_title',  array($this, 'woo_title_order_received'));
                }
            }
        }

    }

    public function get_order_data($order)
    {
        $pattern = "/[^A-Za-z0-9? .,_-]/";
        $order_post = get_post($order->get_id());
        $dp = wc_get_price_decimals();
        $order_data = array();
        // add line items
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $term_names = wp_get_post_terms( $item->get_product_id(), 'product_cat', array('fields' => 'names') );
            $categories_string = implode(',', $term_names);
            $product_id = null;
            $product_sku = null;
            // Check if the product exists.
            if (is_object($product)) {
                $product_id = $product->get_id();
                $product_sku = $product->get_sku();
            }
            $meta = new WC_Order_Item_Meta($item, $product);
            $item_meta = array();
            foreach ($meta->get_formatted(null) as $meta_key => $formatted_meta) {
                $item_meta[] = array('key' => $meta_key, 'label' => $formatted_meta['label'], 'value' => $formatted_meta['value']);
            }
            $order_data[] = array('price' => wc_format_decimal($order->get_item_total($item, false, false), $dp), 'quantity' => wc_stock_amount($item['qty']), 'name' => preg_replace($pattern, "", $item['name']), 'sku' => $product_sku, 'category' => $categories_string, 'url' => 'https://www.doku.com/');


        }
        // Add shipping.
        foreach ($order->get_shipping_methods() as $shipping_item_id => $shipping_item) {
            if (wc_format_decimal($shipping_item['cost'], $dp) > 0) {
                $order_data[] = array('name' => preg_replace($pattern, "", $shipping_item['name']), 'price' => wc_format_decimal($shipping_item['cost'], $dp), 'quantity' => '1', 'sku' => '0', 'category' => 'uncategorized', 'url' => 'https://www.doku.com/');
            }
        }
        // Add taxes.
        foreach ($order->get_tax_totals() as $tax_code => $tax) {
            if (wc_format_decimal($tax->amount, $dp) > 0) {
                $order_data[] = array('name' => preg_replace($pattern, "", $tax->label), 'price' => wc_format_decimal($tax->amount, $dp), 'quantity' => '1', 'sku' => '0', 'category' => 'uncategorized', 'url' => 'https://www.doku.com/');
            }
        }
        // Add fees.
        foreach ($order->get_fees() as $fee_item_id => $fee_item) {
            if (wc_format_decimal($order->get_line_total($fee_item), $dp) > 0) {
                $order_data[] = array('name' => preg_replace($pattern, "", $fee_item['name']), 'price' => wc_format_decimal($order->get_line_total($fee_item), $dp), 'quantity' => '1', 'sku' => '0', 'category' => 'uncategorized', 'url' => 'https://www.doku.com/');
            }
        }
        // Add coupons.
        foreach ($order->get_items('coupon') as $coupon_item_id => $coupon_item) {
            if (wc_format_decimal($coupon_item['discount_amount'], $dp) > 0) {
                $order_data[] = array('name' => preg_replace($pattern, "", $coupon_item['name']), 'price' => wc_format_decimal($coupon_item['discount_amount'], $dp), 'quantity' => '1', 'sku' => '0', 'category' => 'uncategorized', 'url' => 'https://www.doku.com/');
            }
        }
        $order_data = apply_filters('woocommerce_cli_order_data', $order_data);
        return $order_data;
    }

    public function process_payment($order_id)
    {
        global $woocommerce;
        $pattern = "/[^A-Za-z0-9? .-\/+,=_:@]/";

        $order  = wc_get_order($order_id);
        $amount = $order->get_total();
        $order_data = $order->get_data();

        $params = array(
            'customerId' => 0 !== $order->get_customer_id() ? $order->get_customer_id() : null,
            'customerEmail' => $order->get_billing_email(),
            'customerName' => $order->get_billing_first_name() . " " . $order->get_billing_last_name(),
            'amount' => $amount,
            'invoiceNumber' => $order->get_order_number(),
            'expiryTime' => $this->expiredTime,
            'phone' => $order->get_billing_phone(),
            'country' => $order->get_billing_country(),
            'address' => preg_replace($pattern, "", $order->get_shipping_address_1()),
            'itemQty' => $this->get_order_data($order),
            'payment_method' => $this->payment_method,
            'postcode' => $order_data['billing']['postcode'],
            'state' => $order_data['billing']['state'],
            'city' => $order_data['billing']['city'],
            'info1' => '',
            'info2' => '',
            'info3' => '',
            'woo_version' => $woocommerce->version,
            'reusableStatus' => false,
            'callback_url' => $this->get_return_url($order) . '&' . $order_id,
            'sac_check' => $this->sac_check,
            'auto_redirect' => $this->auto_redirect_jokul,
            'sac_textbox' => $this->sac_textbox,
            'first_name_shipping' => $order->shipping_first_name,
            'address_shipping' => preg_replace($pattern, "",$order->shipping_address_1),
            'city_shipping' => $order->shipping_city,
            'postal_code_shipping' => $order->shipping_postcode
        );

        if ($this->environmentPaymentJokul == 'false') {
            $clientId = $this->sandboxClientId;
            $sharedKey = $this->sandboxSharedKey;
        } else if ($this->environmentPaymentJokul == 'true') {
            $clientId = $this->prodClientId;
            $sharedKey = $this->prodSharedKey;
        }

        $config = array(
            'client_id' => $clientId,
            'shared_key' => $sharedKey,
            'environment' => $this->environmentPaymentJokul
        );

        update_post_meta($order_id, 'checkoutParams', $params);
        update_post_meta($order_id, 'checkoutConfig', $config);

        $this->jokulCheckoutService = new JokulCheckoutService();
        $response = $this->jokulCheckoutService->generated($config, $params);
        if (!is_wp_error($response)) {
            if ($response['message'][0] == "SUCCESS" && isset($response['response']['payment']['url'])) {
                update_post_meta($order_id, 'checkoutUrl', $response['response']['payment']['url']);
                JokulCheckoutModule::addDb($response, $amount);
                $this->orderId = $order_id;
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order) . "&jokul=show&" . $order_id
                );
            } else if ( isset($response['payment']['url']) ) {
                update_post_meta($order_id, 'checkoutUrl', $response['payment']['url']);
                JokulCheckoutModule::addDb($response, $amount);
                $this->orderId = $order_id;
                return array(
                    'result' => 'success',
                    'redirect' => $response['payment']['url']
                );
            } else {
                wc_add_notice('There is something wrong. Please try again.', 'error');
            }
        } else {
            wc_add_notice('There is something wrong. Please try again.', 'error');
        }
    }

    public function init_form_fields()
    {
        $this->form_fields = require(DOKU_JOKUL_PLUGIN_PATH . '/Form/JokulCheckoutSetting.php');
    }

    public function process_admin_options()
    {
        $this->init_settings();

        $post_data = $this->get_post_data();

        foreach ($this->get_form_fields() as $key => $field) {
            if ('title' !== $this->get_field_type($field)) {
                try {
                    $this->settings[$key] = $this->get_field_value($key, $field, $post_data);
                } catch (Exception $e) {
                    $this->add_error($e->getMessage());
                }
            }
        }

        if (!isset($post_data['woocommerce_' . $this->id . '_enabled']) && $this->get_option_key() == 'woocommerce_' . $this->id . '_settings') {
            $this->settings['enabled'] = $this->enabled;
        }

        if (isset($post_data['woocommerce_' . $this->id . '_secret_key']) || isset($post_data['woocommerce_' . $this->id . '_secret_key_dev'])) {
            delete_transient('main_settings_jokul_pg');
        }

        return update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings), 'yes');
    }

    public function admin_options()
    {
?>
        <script>
            jQuery(document).ready(function($) {
                $('.channel-name-format').text('<?= $this->title; ?>');
                $('#woocommerce_<?= $this->id; ?>_channel_name').change(
                    function() {
                        $('.channel-name-format').text($(this).val());
                    }
                );

                var isSubmitCheckDone = false;

                $("button[name='save']").on('click', function(e) {
                    if (isSubmitCheckDone) {
                        isSubmitCheckDone = false;
                        return;
                    }

                    e.preventDefault();

                    var paymentDescription = $('#woocommerce_<?= $this->id; ?>_payment_description').val();
                    if (paymentDescription.length > 250) {
                        return swal({
                            text: 'Text is too long, please reduce the message and ensure that the length of the character is less than 250.',
                            buttons: {
                                cancel: 'Cancel'
                            }
                        });
                    } else {
                        isSubmitCheckDone = true;
                    }

                    $("button[name='save']").trigger('click');
                });
            });
        </script>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
    <?php
    }

    public function payment_fields()
    {
        if ($this->paymentDescription) {
            echo wpautop(wp_kses_post($this->paymentDescription));
        }
    }

    public function addDb($response, $amount)
    {

        $this->jokulUtils = new JokulUtils();
        $getIp = $this->jokulUtils->getIpaddress();
        $trx = array();
        $trx['invoice_number']          = $response['response']['order']['invoice_number'];
        $trx['result_msg']              = null;
        $trx['process_type']            = 'PAYMENT_PENDING';
        $trx['raw_post_data']           = file_get_contents('php://input');
        $trx['ip_address']              = $getIp;
        $trx['amount']                  = $amount;
        $trx['payment_channel']         = $this->method_code;
        $trx['payment_code']            = null;
        $trx['doku_payment_datetime']   = gmdate("Y-m-d H:i:s");
        $trx['process_datetime']        = gmdate("Y-m-d H:i:s");
        $trx['message']                 = "Payment Pending message come from Jokul. Success : completed";


        $this->jokulDb = new JokulDb();
        $this->jokulDb->addData($trx);
    }

    public function thank_you_page_pending($order_id)
    {
        $jokulCheckoutURL       = get_post_meta($order_id, 'checkoutUrl', true);
    ?>
        <div style="text-align: center;">
            <button style="text-align:center;background-color: red;color: white;" onclick="openPopup()"> Proceed to Payment</button>
        </div>

        <script type="text/javascript" src="https://sandbox.doku.com/jokul-checkout-js/v1/jokul-checkout-1.0.0.js"></script>
        <script type='text/javascript'>
            openPopup();

            function openPopup() {
                loadJokulCheckout('<?php _e($jokulCheckoutURL, 'woocommerce'); ?>'); // Replace it with the response.payment.url you retrieved from the response
            }
        </script>
<?php
    }

    function woo_title_order_pending($title)
    {
        if ($title === 'Order received') {
            return "Payment Pending";
        } else {
            return $title;
        }
    }

    function woo_title_order_received($title)
    {
        global $woocommerce;

        if (function_exists('is_order_received_page') && is_order_received_page() && $title === 'Order received') {
            global $wp;
            $order_id = absint($wp->query_vars['order-received']);
            $order  = wc_get_order($order_id);

            $woocommerce->cart->empty_cart();
            wc_reduce_stock_levels($order->get_id());

            $paramsValue       = get_post_meta($order->get_id(), 'checkoutParams', true);
            $configValue       = get_post_meta($order->get_id(), 'checkoutConfig', true);

            $this->jokulCheckStatusService = new JokulCheckStatusService();
            $response = $this->jokulCheckStatusService->generated($configValue, $paramsValue);

            if (!is_wp_error($response)) {
                if (strtolower($response['acquirer']['id']) == strtolower('OVO')) {
                    $jokulUtils = new JokulUtils();
                    $jokulDb = new JokulDb();
                    $jokulUtils->doku_log($jokulUtils, 'Jokul Acquirer : ' . $response['acquirer']['id'], $paramsValue['invoiceNumber']);
                    if (strtolower($response['transaction']['status']) == strtolower('SUCCESS')) {
                        $jokulDb->updateData($paramsValue['invoiceNumber'], $response['transaction']['status']);
                        $order = wc_get_order($paramsValue['invoiceNumber']);
                        $order->update_status('processing');
                        $order->payment_complete();
                        $jokulUtils->doku_log($jokulUtils, 'Jokul Check Status Update Status : ' . 'processing', $paramsValue['invoiceNumber']);
                    } else {
                        $jokulDb->updateData($paramsValue['invoiceNumber'], $response['transaction']['status']);
                        $order = wc_get_order($paramsValue['invoiceNumber']);
                        $order->update_status('failed');
                        $jokulUtils->doku_log($jokulUtils, 'Jokul Check Status Update Status : ' . 'failed', $paramsValue['invoiceNumber']);
                    }
                }
            }

            return "Order Received";
        } else {
            return $title;
        }
    }
}
?>
