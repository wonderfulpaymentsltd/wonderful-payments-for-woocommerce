<?php
/*
 * Plugin Name: Wonderful Payments for WooCommerce
 * Description: Account to account payments powered by Open Banking.
 * Author: Wonderful Payments Ltd
 * Author URI: https://www.wonderful.co.uk
 * Version: 0.1
 *
 * Copyright: (C) 2023, Wonderful Payments Limited <tek@wonderful.co.uk>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

defined('ABSPATH') or exit;

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * Add the gateway to WC Available Gateways
 */
function wc_wonderful_payments_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Gateway_Wonderful_Payments';
    return $gateways;
}

add_filter('woocommerce_payment_gateways', 'wc_wonderful_payments_add_to_gateways');

/*
 * Wonderful Payments Gateway
 */
add_action('plugins_loaded', 'wc_wonderful_payments_init', 11);

function wc_wonderful_payments_init()
{
    class WC_Gateway_Wonderful_Payments extends WC_Payment_Gateway
    {

        public function __construct()
        {
            $this->id = 'wonderful_payments_gateway';
            $this->icon = '';
            $this->has_fields = false;
            $this->method_title = __('Wonderful Payments', 'wc-gateway-wonderful');
            $this->method_description = __(
                'Account to account bank payments, powered by Open Banking',
                'wc-gateway-wonderful'
            );
            $this->endpoint = 'https://wonderful.one/api/public/v1/payments';
			$this->sslVerify = true;

            // Wonderful Payments only currently supports one-time purchases
            $this->supports = array(
                'products'
            );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();
            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->merchant_key = $this->get_option('merchant_key');

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options')
            );

            // Return Webhook
            add_action('woocommerce_api_wonderfulpayments', array($this, 'webhook'));
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {
            $this->form_fields = apply_filters('wc_wonderful_payments_form_fields', array(

                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc-gateway-wonderful'),
                    'type' => 'checkbox',
                    'label' => __('Enable Wonderful Payments', 'wc-gateway-wonderful'),
                    'default' => 'yes'
                ),

                'title' => array(
                    'title' => __('Title', 'wc-gateway-wonderful'),
                    'type' => 'text',
                    'description' => __(
                        'This controls the title for the payment method the customer sees during checkout.',
                        'wc-gateway-wonderful'
                    ),
                    'default' => __('Instant bank payment', 'wc-gateway-wonderful'),
                    'desc_tip' => true,
                ),

                'description' => array(
                    'title' => __('Description', 'wc-gateway-wonderful'),
                    'type' => 'textarea',
                    'description' => __(
                        'Payment method description that the customer will see on your checkout.',
                        'wc-gateway-wonderful'
                    ),
                    'default' => __('No data entry. Pay effortlessly and securely through your mobile banking app. Powered by Wonderful Payments.', 'wc-gateway-wonderful'),
                    'desc_tip' => true,
                ),

                'merchant_key' => array(
                    'title' => __('Wonderful Payments Merchant Token', 'wc-gateway-wonderful'),
                    'type' => 'text',
                    'description' => __(
                        'Your merchant token will be provided to you when you register with Wonderful Payments',
                        'wc-gateway-wonderful'
                    ),
                    'default' => __('', 'wc-gateway-wonderful'),
                    'desc_tip' => true,
                ),
            ));
        }

        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            // Initiate a new payment and redirect to WP to pay
            $payload = [
                'customerEmailAddress' => $order->get_billing_email(),
                'merchantPaymentReference' => 'WOO-' . strtoupper(
                        substr(md5(uniqid(rand(), true)), 0, 6)
                    ) . '-' . $order->get_order_number(),
                'amount' => round($order->get_total() * 100), // in pence
                'currency' => 'GBP',
                'clientIpAddress' => $order->get_customer_ip_address(),
                'clientBrowserAgent' => $order->get_customer_user_agent(),
                'redirect_url' => get_site_url() . '/wc-api/wonderfulpayments',
            ];

            // Send the payload to WP, catch the redirect url and redirect accordingly.
            $response = wp_remote_post($this->endpoint, [
                'body' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->merchant_key,
                ],
	            'sslverify' => $this->sslVerify,
            ]);

			if (is_a($response, WP_Error::class)) {
				wc_add_notice( 'Unable to connect to Wonderful Payments, please try again or select another payment method.', 'error' );
				return;
			}

            $body = json_decode(wp_remote_retrieve_body($response));

            // Error checking and bail if creation failed!
            if ($response['response']['code'] != 201) {
                wc_add_notice('Unable to initiate Wonderful Payments checkout, please try again or select another payment method. Code: ' . $response['response']['code'], 'error');
                return;
            }

            // Mark as pending (we're awaiting the payment)
            $order->update_status(
                'pending',
                'Payment Created - Wonderful Payments ID:' . $body->data->wonderfulPaymentsId
            );

            // redirect to WP
            return array(
                'result' => 'success',
                'redirect' => $body->data->redirectTo,
            );
        }

        public function webhook()
        {
            if (!isset($_GET['wonderfulPaymentId'])) {
                echo 'error';
                exit;
            }

            // Get the payment status from Wonderful Payments, work out which order it is for, update and redirect.
            $response = wp_remote_get(
                $this->endpoint . '/' . $_GET['wonderfulPaymentId'],
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->merchant_key,
                    ],
                    'sslverify' => $this->sslVerify,
                ]
            );

            $body = json_decode(wp_remote_retrieve_body($response));

            // Extract the Order ID, lookup the WooCommerce order and then process according to the Wonderful Payments order state
            $order = wc_get_order(explode('-', $body->data->paymentReference)[2]);
	        $order->add_order_note(sprintf('Payment Update. Wonderful Payments ID: %s, Status: %s', $body->data->wonderfulPaymentsId, $body->data->status));

			// Check payment state and handle accordingly
	        switch ($body->data->status) {
		        case 'accepted':
		        case 'completed':
			        // Mark order payment complete
					$order->add_order_note(sprintf("Payment Success. Order reference: %s, Customer Bank: %s", $body->data->paymentReference, $body->data->selectedAspsp));
			        $order->payment_complete($body->data->paymentReference);
			        wp_safe_redirect($this->get_return_url($order));
					exit;

		        case 'pending':
					// Mark order "on hold" for manual review
			        $order->update_status('on-hold', 'Payment has been processed but has not been confirmed. Please manually check payment status before order processing.');
			        wp_safe_redirect($this->get_return_url($order));
			        exit;

		        case 'rejected':
					// Payment has failed, move order to "failed" and redirect back to checkout
			        $order->update_status('failed', 'Payment was rejected at the bank');
					wc_add_notice('Your payment was rejected by your bank, you have not be charged. Please try again.', 'error');
			        wp_safe_redirect($order->get_checkout_payment_url());
					exit;

				case 'cancelled':
					// Payment was explicitly cancelled, move order to "failed" and redirect back to checkout
			        $order->update_status('failed', 'Payment was cancelled by the customer');
					wc_add_notice('Your payment was cancelled.', 'notice');
			        wp_safe_redirect($order->get_checkout_payment_url());
					exit;

				case 'errored':
					// Payment errored, move order to "failed" and redirect back to checkout
			        $order->update_status('failed', 'Payment error during checkout');
					wc_add_notice('Your payment errored during checkout, please try again.', 'error');
			        wp_safe_redirect($order->get_checkout_payment_url());
					exit;

				case 'expired':
					// Payment has expired, move order to "failed" and redirect back to checkout
			        $order->update_status('failed', 'Payment expired');
					wc_add_notice('Your payment was not completed in time, you have not be charged. Please try again.', 'error');
			        wp_safe_redirect($order->get_checkout_payment_url());
					exit;
	        }

			// If we get this far, an unknown error has occurred
	        $order->update_status('failed', sprintf("Payment error: unknown payment state %s", $body->data->status));
	        wc_add_notice('An unexpected error occurred while processing your payment.');
	        wp_safe_redirect($order->get_checkout_payment_url());
	        exit;
        }

    }
}