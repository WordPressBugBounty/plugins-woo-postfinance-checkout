<?php
/**
 * Plugin Name: PostFinanceCheckout
 * Author: postfinancecheckout AG
 * Text Domain: postfinancecheckout
 * Domain Path: /languages/
 *
 * PostFinanceCheckout
 * This plugin will add support for all PostFinanceCheckout payments methods and connect the PostFinanceCheckout servers to your WooCommerce webshop (https://postfinance.ch/en/business/products/e-commerce/postfinance-checkout-all-in-one.html).
 *
 * @category Class
 * @package  PostFinanceCheckout
 * @author   postfinancecheckout AG (https://postfinance.ch/en/business/products/e-commerce/postfinance-checkout-all-in-one.html)
 * @license  http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PostFinanceCheckout_Webhook_Transaction_Strategy
 *
 * This class provides the implementation for processing transaction webhooks.
 * It includes methods for handling specific actions that need to be taken when
 * transaction-related webhook notifications are received, such as updating order
 * statuses, recording transaction logs, or triggering further business logic.
 */
class WC_PostFinanceCheckout_Webhook_Transaction_Strategy extends WC_PostFinanceCheckout_Webhook_Strategy_Base {

	/**
	 * Match function.
	 *
	 * @inheritDoc
	 * @param string $webhook_entity_id The webhook entity id.
	 */
	public function match( string $webhook_entity_id ) {
		return WC_PostFinanceCheckout_Service_Webhook::POSTFINANCECHECKOUT_TRANSACTION == $webhook_entity_id;
	}

	/**
	 * Process the webhook request.
	 *
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction The webhook request object.
	 * @return mixed The result of the processing.
	 */
	public function process( WC_PostFinanceCheckout_Webhook_Request $request ) {
		$order = $this->get_order( $request );
		$entity = $this->load_entity( $request );
		if ( false != $order && $order->get_id() ) {
			$this->process_order_related_inner( $order, $entity );
		}
	}

	/**
	 * Process order related inner.
	 *
	 * @param WC_Order $order order.
	 * @param mixed $transaction transaction.
	 * @return void
	 * @throws Exception Exception.
	 */
	protected function process_order_related_inner( WC_Order $order, $transaction ) {
		$transaction_info = WC_PostFinanceCheckout_Entity_Transaction_Info::load_by_order_id( $order->get_id() );
		$transaction_state = $transaction->getState();
		if ( $transaction_state != $transaction_info->get_state() ) {
			switch ( $transaction_state ) {
				case \PostFinanceCheckout\Sdk\Model\TransactionState::CONFIRMED:
				case \PostFinanceCheckout\Sdk\Model\TransactionState::PROCESSING:
					$this->confirm( $transaction, $order );
					break;
				case \PostFinanceCheckout\Sdk\Model\TransactionState::AUTHORIZED:
					$this->authorize( $transaction, $order );
					break;
				case \PostFinanceCheckout\Sdk\Model\TransactionState::DECLINE:
					$this->decline( $transaction, $order );
					break;
				case \PostFinanceCheckout\Sdk\Model\TransactionState::FAILED:
					$this->failed( $transaction, $order );
					break;
				case \PostFinanceCheckout\Sdk\Model\TransactionState::FULFILL:
					$this->authorize( $transaction, $order );
					$this->fulfill( $transaction, $order );
					break;
				case \PostFinanceCheckout\Sdk\Model\TransactionState::VOIDED:
					$this->voided( $transaction, $order );
					break;
				case \PostFinanceCheckout\Sdk\Model\TransactionState::COMPLETED:
					$this->authorize( $transaction, $order );
					$this->waiting( $transaction, $order );
					break;
				default:
					// Nothing to do.
					break;
			}
		}

		WC_PostFinanceCheckout_Service_Transaction::instance()->update_transaction_info( $transaction, $order );
	}

	/**
	 * Confirm.
	 *
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction transaction.
	 * @param WC_Order $order order.
	 * @return void
	 */
	protected function confirm( \PostFinanceCheckout\Sdk\Model\Transaction $transaction, WC_Order $order ) {
		if ( ! $order->get_meta( '_postfinancecheckout_confirmed', true ) && ! $order->get_meta( '_postfinancecheckout_authorized', true ) ) {
			do_action( 'wc_postfinancecheckout_confirmed', $transaction, $order );
			$order->add_meta_data( '_postfinancecheckout_confirmed', 'true', true );
			$default_status = apply_filters( 'wc_postfinancecheckout_confirmed_status', 'postfi-redirected', $order );
			apply_filters( 'postfinancecheckout_order_update_status', $order, \PostFinanceCheckout\Sdk\Model\TransactionState::CONFIRMED, $default_status );
			wc_maybe_reduce_stock_levels( $order->get_id() );
		}
	}

	/**
	 * Authorize.
	 *
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction transaction.
	 * @param \WC_Order $order order.
	 */
	protected function authorize( \PostFinanceCheckout\Sdk\Model\Transaction $transaction, WC_Order $order ) {
		if ( ! $order->get_meta( '_postfinancecheckout_authorized', true ) ) {
			do_action( 'wc_postfinancecheckout_authorized', $transaction, $order );
			$order->add_meta_data( '_postfinancecheckout_authorized', 'true', true );
			$default_status = apply_filters( 'wc_postfinancecheckout_authorized_status', 'on-hold', $order );
			apply_filters( 'postfinancecheckout_order_update_status', $order, \PostFinanceCheckout\Sdk\Model\TransactionState::AUTHORIZED, $default_status );
			wc_maybe_reduce_stock_levels( $order->get_id() );
			if ( isset( WC()->cart ) ) {
				WC()->cart->empty_cart();
			}
		}
	}

	/**
	 * Waiting.
	 *
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction transaction.
	 * @param WC_Order $order order.
	 * @return void
	 */
	protected function waiting( \PostFinanceCheckout\Sdk\Model\Transaction $transaction, WC_Order $order ) {
		if ( ! $order->get_meta( '_postfinancecheckout_manual_check', true ) ) {
			do_action( 'wc_postfinancecheckout_completed', $transaction, $order );
			$default_status = apply_filters( 'wc_postfinancecheckout_completed_status', 'processing', $order );
			apply_filters( 'postfinancecheckout_order_update_status', $order, \PostFinanceCheckout\Sdk\Model\TransactionState::COMPLETED, $default_status );
		}
	}

	/**
	 * Decline.
	 *
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction transaction.
	 * @param WC_Order $order order.
	 * @return void
	 */
	protected function decline( \PostFinanceCheckout\Sdk\Model\Transaction $transaction, WC_Order $order ) {
		do_action( 'wc_postfinancecheckout_declined', $transaction, $order );
		$default_status = apply_filters( 'wc_postfinancecheckout_decline_status', 'cancelled', $order );
		apply_filters( 'postfinancecheckout_order_update_status', $order, \PostFinanceCheckout\Sdk\Model\TransactionState::DECLINE, $default_status );
		WC_PostFinanceCheckout_Helper::instance()->maybe_restock_items_for_order( $order );
	}

	/**
	 * Failed.
	 *
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction transaction.
	 * @param WC_Order $order order.
	 * @return void
	 */
	protected function failed( \PostFinanceCheckout\Sdk\Model\Transaction $transaction, WC_Order $order ) {
		do_action( 'wc_postfinancecheckout_failed', $transaction, $order );
		$valid_order_statuses = array(
			// Default pending status.
			'pending',
			// Custom order statuses mapped.
			apply_filters( 'postfinancecheckout_wc_status_for_transaction', 'confirmed' ),
			apply_filters( 'postfinancecheckout_wc_status_for_transaction', 'failed' )
		);
		if ( in_array( $order->get_status( 'edit' ), $valid_order_statuses ) ) {
			$default_status = apply_filters( 'wc_postfinancecheckout_failed_status', 'failed', $order );
			apply_filters( 'postfinancecheckout_order_update_status', $order, \PostFinanceCheckout\Sdk\Model\TransactionState::FAILED, $default_status, );
			WC_PostFinanceCheckout_Helper::instance()->maybe_restock_items_for_order( $order );
		}
	}

	/**
	 * Fulfill.
	 *
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction transaction.
	 * @param WC_Order $order order.
	 * @return void
	 */
	protected function fulfill( \PostFinanceCheckout\Sdk\Model\Transaction $transaction, WC_Order $order ) {
		do_action( 'wc_postfinancecheckout_fulfill', $transaction, $order );
		// Sets the status to procesing or complete depending on items.
		$order->payment_complete( $transaction->getId() );
	}

	/**
	 * Voided.
	 *
	 * @param \PostFinanceCheckout\Sdk\Model\Transaction $transaction transaction.
	 * @param WC_Order $order order.
	 * @return void
	 */
	protected function voided( \PostFinanceCheckout\Sdk\Model\Transaction $transaction, WC_Order $order ) {
		$default_status = apply_filters( 'wc_postfinancecheckout_voided_status', 'cancelled', $order );
		apply_filters( 'postfinancecheckout_order_update_status', $order, \PostFinanceCheckout\Sdk\Model\TransactionState::VOIDED, $default_status );
		do_action( 'wc_postfinancecheckout_voided', $transaction, $order );
	}
}
