<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Woo_Wallet_Wallet' ) ) {

    class Woo_Wallet_Wallet {
        /**
         * WordPress user ID.
         * @var INT 
         */
        public $user_id = 0;
        /**
         * Wallet balance.
         * @var float 
         */
        public $wallet_balance = 0;
        /**
         * Current wallet balance meta key.
         * @var string 
         */
        public $meta_key = '_current_woo_wallet_balance';

        /*
         * Class constructor
         */

        public function __construct() {
            $this->user_id = get_current_user_id();
        }

        /**
         * setter method
         * @param int $user_id
         */
        private function set_user_id( $user_id = '' ) {
            $this->user_id = $user_id ? $user_id : $this->user_id;
        }

        /**
         * Get user wallet balance or display
         * @global object $wpdb
         * @param int $user_id
         * @param string $context
         * @return mixed
         */
        public function get_wallet_balance( $user_id = '', $context = 'view' ) {
            global $wpdb;
            if (empty( $user_id ) ) {
                $user_id = get_current_user_id();
            }
            $this->set_user_id( $user_id );
            $this->wallet_balance = 0;
            if ( $this->user_id ) {
                $credit_amount = array_sum(wp_list_pluck( get_wallet_transactions( array( 'user_id' => $this->user_id, 'where' => array( array( 'key' => 'type', 'value' => 'credit' ) ) ) ), 'amount' ) );
                $debit_amount = array_sum(wp_list_pluck( get_wallet_transactions( array( 'user_id' => $this->user_id, 'where' => array( array( 'key' => 'type', 'value' => 'debit' ) ) ) ), 'amount' ) );
                $balance = $credit_amount - $debit_amount;
                $this->wallet_balance = apply_filters( 'woo_wallet_current_balance', $balance, $this->user_id );
            }
            return 'view' === $context ? wc_price( $this->wallet_balance, woo_wallet_wc_price_args($this->user_id) ) : number_format( $this->wallet_balance, wc_get_price_decimals(), '.', '' );
        }

        /**
         * Create wallet payment credit transaction
         * @param int $user_id
         * @param float $amount
         * @param string $details
         * @return int transaction id
         */
        public function credit( $user_id = '', $amount = 0, $details = '' ) {
            $this->set_user_id( $user_id );
            return $this->recode_transaction( $amount, 'credit', $details );
        }

        /**
         * Create wallet payment debit transaction
         * @param int $user_id
         * @param float $amount
         * @param string $details
         * @return int transaction id
         */
        public function debit( $user_id = '', $amount = 0, $details = '' ) {
            $this->set_user_id( $user_id );
            return $this->recode_transaction( $amount, 'debit', $details );
        }

        /**
         * Credit wallet balance through order payment
         * @param int $order_id
         * @return void
         */
        public function wallet_credit_purchase( $order_id ) {
            $wallet_product = get_wallet_rechargeable_product();
            $charge_amount = 0;
            if ( get_post_meta( $order_id, '_wc_wallet_purchase_credited', true ) || !$wallet_product) {
                return;
            }
            $order = wc_get_order( $order_id );
            if ( ! is_wallet_rechargeable_order( $order ) ) {
                return;
            }
            $recharge_amount = apply_filters( 'woo_wallet_credit_purchase_amount', $order->get_subtotal( 'edit' ), $order_id );
            if ( 'on' === woo_wallet()->settings_api->get_option( 'is_enable_gateway_charge', '_wallet_settings_credit', 'off' ) ) {
                $charge_amount = woo_wallet()->settings_api->get_option( $order->get_payment_method(), '_wallet_settings_credit', 0 );
                if ( 'percent' === woo_wallet()->settings_api->get_option( 'gateway_charge_type', '_wallet_settings_credit', 'percent' ) ) {
                    $recharge_amount -= $recharge_amount * ( $charge_amount / 100 );
                } else {
                    $recharge_amount -= $charge_amount;
                }
                update_post_meta( $order_id, '_wc_wallet_purchase_gateway_charge', $charge_amount );
            }
            $transaction_id = $this->credit( $order->get_customer_id(), $recharge_amount, __( 'Wallet credit through purchase #', 'woo-wallet' ) . $order->get_order_number() );
            if ( $transaction_id ) {
                update_post_meta( $order_id, '_wc_wallet_purchase_credited', true );
                update_post_meta( $order_id, '_wallet_payment_transaction_id', $transaction_id );
                update_wallet_transaction_meta( $transaction_id, '_wc_wallet_purchase_gateway_charge', $charge_amount, $order->get_customer_id() );
                update_wallet_transaction_meta( $transaction_id, '_type', 'credit_purchase', $order->get_customer_id() );
                do_action( 'woo_wallet_credit_purchase_completed', $transaction_id, $order );
            }
        }

        public function wallet_cashback( $order_id ) {
            $order = wc_get_order( $order_id );
            /* General Cashback */
            if ( apply_filters( 'process_woo_wallet_general_cashback', !get_post_meta( $order->get_id(), '_general_cashback_transaction_id', true ) && $order->get_customer_id(), $order ) && woo_wallet()->cashback->calculate_cashback(false, $order->get_id()) ) {
                $transaction_id = $this->credit( $order->get_customer_id(), woo_wallet()->cashback->calculate_cashback(false, $order->get_id()), __( 'Wallet credit through cashback #', 'woo-wallet' ) . $order->get_order_number() );
                if ( $transaction_id ) {
                    update_wallet_transaction_meta( $transaction_id, '_type', 'cashback', $order->get_customer_id() );
                    update_post_meta( $order->get_id(), '_general_cashback_transaction_id', $transaction_id );
                    do_action( 'woo_wallet_general_cashback_credited', $transaction_id, $order );
                }
            }
            /* Coupon Cashback */
            if ( apply_filters( 'process_woo_wallet_coupon_cashback', !get_post_meta( $order->get_id(), '_coupon_cashback_transaction_id', true ) && $order->get_customer_id(), $order ) && get_post_meta( $order->get_id(), '_coupon_cashback_amount', true ) ) {
                $coupon_cashback_amount = apply_filters( 'woo_wallet_coupon_cashback_amount', get_post_meta( $order->get_id(), '_coupon_cashback_amount', true ), $order );
                if ( $coupon_cashback_amount ) {
                    $transaction_id = $this->credit( $order->get_customer_id(), $coupon_cashback_amount, __( 'Wallet credit through cashback by applying coupon', 'woo-wallet' ) );
                    if ( $transaction_id ) {
                        update_wallet_transaction_meta( $transaction_id, '_type', 'cashback', $order->get_customer_id() );
                        update_post_meta( $order->get_id(), '_coupon_cashback_transaction_id', $transaction_id );
                        do_action( 'woo_wallet_coupon_cashback_credited', $transaction_id, $order );
                    }
                }
            }
        }

        public function wallet_partial_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            $partial_payment_amount = get_order_partial_payment_amount( $order_id );
            if ( $partial_payment_amount && !get_post_meta( $order_id, '_partial_pay_through_wallet_compleate', true ) ) {
                $transaction_id = $this->debit( $order->get_customer_id(), $partial_payment_amount, __( 'For order payment #', 'woo-wallet' ) . $order->get_order_number() );
                if ( $transaction_id ) {
                    $order->add_order_note(sprintf( __( '%s paid through wallet', 'woo-wallet' ), wc_price( $partial_payment_amount, woo_wallet_wc_price_args($order->get_customer_id()) ) ) );
                    update_wallet_transaction_meta( $transaction_id, '_partial_payment', true, $order->get_customer_id() );
                    update_post_meta( $order_id, '_partial_pay_through_wallet_compleate', $transaction_id );
                    do_action( 'woo_wallet_partial_payment_completed', $transaction_id, $order );
                }
            }
        }

        public function process_cancelled_order( $order_id ) {
            $order = wc_get_order( $order_id );
            /** credit partial payment amount * */
            $partial_payment_amount = get_order_partial_payment_amount( $order_id );
            if ( $partial_payment_amount && get_post_meta( $order_id, '_partial_pay_through_wallet_compleate', true ) ) {
                $this->credit( $order->get_customer_id(), $partial_payment_amount, sprintf( __( 'Your order with ID #%s has been cancelled and hence your wallet amount has been refunded!', 'woo-wallet' ), $order->get_order_number() ) );
                $order->add_order_note(sprintf( __( 'Wallet amount %s has been credited to customer upon cancellation', 'woo-wallet' ), $partial_payment_amount ) );
                delete_post_meta( $order_id, '_partial_pay_through_wallet_compleate' );
                update_post_meta($order_id, '_woo_wallet_partial_payment_refunded', true);
            }

            /** debit cashback amount * */
            if ( apply_filters( 'woo_wallet_debit_cashback_upon_cancellation', get_total_order_cashback_amount( $order_id ) ) ) {
                $total_cashback_amount = get_total_order_cashback_amount( $order_id );
                if ( $total_cashback_amount ) {
                    if ( $this->debit( $order->get_customer_id(), $total_cashback_amount, sprintf( __( 'Cashback for #%s has been debited upon cancellation', 'woo-wallet' ), $order->get_order_number() ) ) ) {
                        delete_post_meta( $order_id, '_general_cashback_transaction_id' );
                        delete_post_meta( $order_id, '_coupon_cashback_transaction_id' );
                    }
                }
            }
        }

        /**
         * Record wallet transactions
         * @global object $wpdb
         * @param int $amount
         * @param string $type
         * @param string $details
         * @return boolean | transaction id
         */
        private function recode_transaction( $amount, $type, $details ) {
            global $wpdb;
            if(!$this->user_id){
                return false;
            }
            if(is_wallet_account_locked($this->user_id)){
                return false;
            }
            if ( $amount < 0 ) {
                $amount = 0;
            }
            $balance = $this->get_wallet_balance( $this->user_id, '' );
            if ( $type == 'debit' && apply_filters( 'woo_wallet_disallow_negative_transaction', ( $balance <= 0 || $amount > $balance), $amount, $balance) ) {
                return false;
            }
            if ( $type == 'credit' ) {
                $balance += $amount;
            } else if ( $type == 'debit' ) {
                $balance -= $amount;
            }
            if ( $wpdb->insert( "{$wpdb->base_prefix}woo_wallet_transactions", apply_filters( 'woo_wallet_transactions_args', array( 'blog_id' => $GLOBALS['blog_id'], 'user_id' => $this->user_id, 'type' => $type, 'amount' => $amount, 'balance' => $balance, 'currency' => get_woocommerce_currency(), 'details' => $details, 'date' => current_time('mysql'), 'created_by' => get_current_user_id() ), array( '%d', '%d', '%s', '%f', '%f', '%s', '%s', '%s', '%d' ) ) ) ) {
                $transaction_id = $wpdb->insert_id;
                update_user_meta($this->user_id, $this->meta_key, $balance);
                clear_woo_wallet_cache( $this->user_id );
                do_action( 'woo_wallet_transaction_recorded', $transaction_id, $this->user_id, $amount, $type);
                if(isset(WC()->mailer()->emails['Woo_Wallet_Email_New_Transaction'])) {
                    $email_admin = WC()->mailer()->emails['Woo_Wallet_Email_New_Transaction'];
                    if( !is_null( $email_admin ) && apply_filters( 'is_enable_email_notification_for_transaction', true, $transaction_id ) ){
                        $email_admin->trigger( $transaction_id );
                    }
                }
                if(isset(WC()->mailer()->emails['Woo_Wallet_Email_Low_Wallet_Balance'])) {
                    $low_balance_email = WC()->mailer()->emails['Woo_Wallet_Email_Low_Wallet_Balance'];
                    if( !is_null( $low_balance_email ) ){
                        $low_balance_email->trigger( $this->user_id, $type );
                    }
                }
                return $transaction_id;
            }
            return false;
        }

    }

}
