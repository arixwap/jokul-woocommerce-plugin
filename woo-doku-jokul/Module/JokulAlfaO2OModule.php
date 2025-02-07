<?php

require_once(DOKU_JOKUL_PLUGIN_PATH . '/Service/JokulAlfaO2OService.php');
require_once(DOKU_JOKUL_PLUGIN_PATH . '/Common/JokulDb.php');

class JokulAlfaO2OModule extends WC_Payment_Gateway
{
    public function __construct()
    {

        $this->init_form_fields();
        $this->id                   = 'jokul_alfao2o';
        $this->has_fields           = true;
        $this->method_name          = 'Alfamart';
        $this->method_code          = 'ALFAMART';
        $this->title                = !empty($this->get_option('channel_name')) ? $this->get_option('channel_name') : $this->method_name;
        $this->method_title         = __('Jokul', 'woocommerce-gateway-jokul');
        $this->method_description   = sprintf(__('Accept payment through various payment channels with Jokul. Make it easy for your customers to purchase on your store.', 'woocommerce'));
        $this->checkout_msg         = 'Please transfer your payment using this payment code : ';

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
        $this->footerMessage = $this->get_option('footer_message');
        $paymentDescription = $this->get_option('payment_description');

        $this->sac_check = isset($mainSettings['sac_check' ]) ? $mainSettings['sac_check' ] : null;
        $this->sac_textbox = isset($mainSettings['sac_textbox']) ? $mainSettings['sac_textbox'] : null;


        if (empty($this->$paymentDescription)) {
            $this->paymentDescription   = 'Bayar pesanan dengan pembayaran melalui Alfamart';
        }
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thank_you_page_alfa_o2o'), 1, 10);
    }

    public function init_form_fields()
    {
        $this->form_fields = require(DOKU_JOKUL_PLUGIN_PATH . '/Form/JokulAlfaO2OSetting.php');
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

    public function process_payment($order_id)
    {
        global $woocommerce;

        $order  = wc_get_order($order_id);
        $amount = $order->get_total();
        $params = array(
            'customerEmail' => $order->get_billing_email(),
            'customerName' => $order->get_billing_first_name() . " " . $order->get_billing_last_name(),
            'amount' => $amount,
            'invoiceNumber' => $order->get_order_number(),
            'expiryTime' => $this->expiredTime,
            'info1' => '',
            'info2' => '',
            'info3' => '',
            'reusableStatus' => false,
            'woo_version' => $woocommerce->version,
            'footerMessage' => $this->footerMessage,
            'sac_check' => $this->sac_check,
            'sac_textbox' => $this->sac_textbox,

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

        $this->alfaO2OService = new JokulAlfaO2OService();
        $response = $this->alfaO2OService->generated($config, $params);
        if (!is_wp_error($response)) {

            $vaNumber = '';
            $vaExpired = '';
            $processType = 'PAYMENT_FAILED';

            if (!isset($response['error']['message']) && isset($response['online_to_offline_info']['payment_code'])) {

                wc_reduce_stock_levels($order->get_id());

                $order->add_order_note($this->checkout_msg . $response['online_to_offline_info']['payment_code'], true);
                $woocommerce->cart->empty_cart();

                update_post_meta($order_id, 'jokul_va_amount', $amount);
                update_post_meta($order_id, 'jokul_method_code', $this->method_code);
                update_post_meta($order_id, 'jokul_va_number', $response['online_to_offline_info']['payment_code']);
                update_post_meta($order_id, 'jokul_va_expired', $response['online_to_offline_info']['expired_date']);
                update_post_meta($order_id, 'jokul_va_how_to_page', $response['online_to_offline_info']['how_to_pay_page']);

                $order = wc_get_order($response['order']['invoice_number']);
                $order->update_status('pending');

                $vaNumber = get_post_meta($order_id, 'jokul_va_number', true);
                $vaExpired = get_post_meta($order_id, 'jokul_va_expired', true);
                $processType = 'PAYMENT_PENDING';

                JokulAlfaO2OModule::addDb($response, $amount, $order, $vaNumber, $vaExpired, $processType);

                $this->jokulUtils = new JokulUtils();
                $this->jokulUtils->send_email($order, $params, $response['online_to_offline_info']['how_to_pay_api']);

                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            } else {
                $this->checkout_msg = 'Error occured: ' . $response['error']['message'] . '. Please check Jokul WooCommerce plugin configuration or check your Jokul Back Office configuration.';
                $order->add_order_note($this->checkout_msg, true);

                JokulAlfaO2OModule::addDb($response, $amount, $order, $vaNumber, $vaExpired, $processType);
                wc_add_notice('There is something wrong. Please try again.', 'error');
                return;
            }
        } else {
            $this->checkout_msg = 'Error occured: Connection error. Please try again in a few minutes. If still happening, please contact Jokul Support team (care@doku.com).';
            $order->add_order_note($this->checkout_msg, true);

            JokulAlfaO2OModule::addDb($response, $amount, $order, $vaNumber, $vaExpired, $processType);
            wc_add_notice('There is something wrong with the payment system. Please try again in a few minutes.', 'error');
            return;
        }
    }

    public function addDb($response, $amount, $order, $vaNumber, $vaExpired, $processType)
    {
        $this->jokulUtils = new JokulUtils();
        $getIp = $this->jokulUtils->getIpaddress();

        $trx = array();
        $trx['invoice_number']          = $order->get_order_number();
        $trx['result_msg']              = null;
        $trx['process_type']            = $processType;
        $trx['raw_post_data']           = file_get_contents('php://input');
        $trx['ip_address']              = $getIp;
        $trx['amount']                  = $amount;
        $trx['payment_channel']         = $this->method_code;
        $trx['payment_code']            = $vaNumber;
        $trx['doku_payment_datetime']   = $vaExpired;
        $trx['process_datetime']        = gmdate("Y-m-d H:i:s");
        $trx['message']                 = $this->checkout_msg;

        $this->jokulDb = new JokulDb();
        $this->jokulDb->addData($trx);
    }

    public function thank_you_page_alfa_o2o($order_id)
    {
        $vaNumber       = get_post_meta($order_id, 'jokul_va_number', true);
        $vaExpired      = get_post_meta($order_id, 'jokul_va_expired', true);
        $vaAmount       = get_post_meta($order_id, 'jokul_va_amount', true);
        $howToPage      = get_post_meta($order_id, 'jokul_va_how_to_page', true);

        $newDate = new DateTime($vaExpired);

        echo '<h2>Payment details</h2>';
    ?>
        <p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">Please transfer your payment using this payment code:</p>

        <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">

            <li class="woocommerce-order-overview__va va">
                <?php _e('Payment Code:', 'woocommerce'); ?>
                <strong><?php _e($vaNumber, 'woocommerce'); ?></strong>
            </li>

            <li class="woocommerce-order-overview__amount amount">
                <?php _e('Payment Amount:', 'woocommerce'); ?>
                <strong><?php _e(wc_price($vaAmount), 'woocommerce'); ?></strong>
            </li>

            <li class="woocommerce-order-overview__date date">
                <?php _e('Make Your Payment Before:', 'woocommerce'); ?>
                <strong><?php _e($newDate->format('d M Y H:i'), 'woocommerce'); ?></strong>
            </li>
        </ul>
        <p>
            <a href=<?php _e($howToPage, 'woocommerce'); ?> target="_blank">Click here to see payment instructions</a>
        </p>
<?php
    }
}
?>
