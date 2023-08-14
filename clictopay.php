<?php

/*
Plugin Name: WooCommerce ClicToPay Payment Gateway
Plugin URI: https://aisysnext.com/wordpress/plugin/clictopay/
Description: ClicToPay Payment Gateway for Tunisian card payment.
Version: 1.0.1
Author: Aymen Jemi (AISYSNEXT)
Author URI: https://github.com/jemiaymen/
*/

add_action('plugins_loaded', 'init_wc_gateway_clicktopay', 0);

function init_wc_gateway_clicktopay()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_ClickToPay_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            global $woocommerce;

            $this->id            = 'wc_ctp';
            $this->method_title = __('ClicToPay', 'clictopay');
            $this->icon            =  plugins_url('images/clictopay-logo.png', __FILE__) .
            $this->has_fields     = false;

            // Load the form fields.
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Define user set variables
            $this->title             = "ClicToPay";
            $this->description         = "Pay with Credit Card in Tunisia";
            $this->enabled          = $this->get_option('enabled');
            $this->mode             = $this->get_option('mode');

            $this->username         = $this->get_option('username');
            $this->password         = $this->get_option('password');
            $this->success_url      = $this->get_option('success_url');
            $this->faild_url        = $this->get_option('faild_url');


            // Actions
            add_action('woocommerce_api_clictopay', array( $this, 'webhook' ) );
            add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        /**
         * get_icon function.
         *
         * @access public
         * @return string
         */
        public function get_icon()
        {
            global $woocommerce;

            $icon = '';
            if ($this->icon) {
                $icon = '<img src="' . plugins_url('images/' . $this->icon, __FILE__)  . '" alt="' . $this->title . '" />';
            }

            return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 1.0.0
         */
        public function admin_options()
        {
?>
            <h3><?php _e('ClicToPay', 'clictopay'); ?></h3>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <p>
                <?php _e('<br> <hr> <br>
				<div style="float:right;text-align:right;">
					Made with &hearts; at <a href="https://aisysnext.com" target="_blank">AISYSNEXT</a> | Besoin d\'aide? <a href="contact@aisysnext.com" target="_blank">Contactez-nous</a><br><br>
					<a href="https://aisysnext.com" target="_blank"><img src="' . plugins_url('images/aisysnext-logo-text.png', __FILE__) . '">
					</a>
				</div>', 'clictopay'); ?>
            </p>
<?php
        }

        /**
         * Initialise Gateway Settings Form Fields
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Activer/Désactiver', 'clictopay'),
                    'type' => 'checkbox',
                    'label' => __('Activer ClicToPay', 'clictopay'),
                    'default' => 'yes'
                ),
                'mode' => array(
                    'title' => __('Mode', 'clictopay'),
                    'type' => 'select',
                    'options' => array(
                        'test' => 'Test',
                        'prod' => 'Production'
                    ),
                    'default' => 'test'
                ),
                'username' => array(
                    'title' => __('Login', 'clictopay'),
                    'type' => 'text',
                    'description' => __('Login du compte ClicToPay.', 'clictopay'),
                    'default' => ''
                ),
                'password' => array(
                    'title' => __('Password', 'clictopay'),
                    'type' => 'password',
                    'description' => __('Password du compte ClicToPay.', 'clictopay'),
                    'default' => ''
                )
            );
        }

        /**
         * Not payment fields, but show the description of the payment.
         **/
        public function payment_fields()
        {
            if ($this->description) {
                echo wpautop(wptexturize($this->description));
            }
        }


        /**
         * Process the payment and return the result
         **/
        public function process_payment($order_id)
        {

            session_start();
            global $woocommerce;

            $order =  wc_get_order( $order_id );

            $webhook_url = sprintf('%s/wc-api/clictopay',get_site_url() );

            if ($this->mode == 'test') {
                $gateway_url = sprintf("https://test.clictopay.com/payment/rest/register.do?userName=%s&password=%s&orderNumber=%d&amount=%d&returnUrl=%s&currency=788",
                    $this->username,
                    $this->password,
                    $order_id,
                    (floatval($order->get_total()) * 1000),
                    $webhook_url 
                ) ;
            } else {
                $gateway_url = sprintf("https://ipay.clictopay.com/payment/rest/register.do?userName=%s&password=%s&orderNumber=%d&amount=%d&returnUrl=%s&currency=788",
                    $this->username,
                    $this->password,
                    $order_id,
                    (floatval($order->get_total()) * 1000),
                    $this->get_return_url($order)
                ) ;
            }


            $response = wp_remote_post($gateway_url);

            if(is_wp_error( $response )){
                wc_add_notice($response->get_error_message(), 'error' );
				return;
            }



            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($body['errorCode']) && (int)$body['errorCode'] !== 0) {
                wc_add_notice($body['errorMessage'], 'error');
                return;
            }

            WC()->session->set('clicktopay_orderId', $body['orderId']);
            $order->set_transaction_id($body['orderId']);

            return array(
                'result' => 'success',
                'redirect' =>  $body['formUrl']
            );
        }


        /**
         * Successful Payment webhook
         **/
        public function webhook()
        {
            session_start();
            global $woocommerce;


            if ($this->mode == 'test') {
                $gateway_check_url = sprintf(
                    "https://test.clictopay.com/payment/rest/getOrderStatus.do?orderId=%s&password=%s&userName=%s",
                    $_GET['orderId'],
                    $this->password,
                    $this->username
                );
            } elseif ($this->mode == 'prod') {
                $gateway_check_url = sprintf(
                    "https://ipay.clictopay.com/payment/rest/getOrderStatus.do?orderId=%s&password=%s&userName=%s",
                    $_GET['orderId'],
                    $this->password,
                    $this->username
                );
            }

            $response = wp_remote_get($gateway_check_url) ;

            if(is_wp_error( $response )){
                wc_add_notice($response->get_error_message(), 'error' );
				return;
            }


            $body = json_decode(wp_remote_retrieve_body($response), true);

            //error example
            //{"depositAmount":0,"currency":"788","authCode":2,"ErrorCode":"2","ErrorMessage":"Payment is declined","OrderStatus":6,"OrderNumber":"442","Amount":2300}

            if ($body['ErrorMessage'] === 'Success') {
                global $woocommerce;

                $order =  wc_get_order( $body['OrderNumber']);



                if ((floatval($order->get_total())  * 1000) == $body['depositAmount']) {
                    $order->add_order_note('Payée avec ClicToPay. Numéro de la transaction '. $_GET['orderId'] );
                    $order->payment_complete();
                    $order->reduce_order_stock();

                    wp_redirect($this->get_return_url($order));

                } else {
                    wc_add_notice(sprintf(__('Erreur de paiement le montant n\'est pas exact!.', 'clictopay')), $notice_type = 'error');
                    wp_redirect(get_permalink(get_option('woocommerce_checkout_page_id')));

                }
            } else {
                $order =  wc_get_order( $body['OrderNumber']);

                $order->add_order_note('Payement not valid. Numéro de la transaction ('. $_GET['orderId'] . ') message ('. $body['ErrorMessage'] . ')' );
                $order->update_status('failed', $body['ErrorMessage']);

                wc_add_notice(sprintf(__('Erreur de paiement.', 'clictopay')), $notice_type = 'error');
                wp_redirect(get_permalink(get_option('woocommerce_checkout_page_id')));

            }
        }
    }

    add_filter('woocommerce_payment_gateways', 'add_ctp_payment_gateway');

    function add_ctp_payment_gateway($gateways)
    {
        $gateways[] = 'WC_ClickToPay_Gateway';
        return $gateways;
    }
}
