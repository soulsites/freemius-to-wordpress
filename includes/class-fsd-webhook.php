<?php
/**
 * Nimmt Freemius-Webhooks entgegen und verschickt bei einem Kauf
 * (Event "payment.created") eine Benachrichtigung an die in
 * FSD_Email_Settings hinterlegten E-Mail-Adressen.
 *
 * Signaturprüfung gemäß Freemius-Dokumentation: HMAC-SHA256 des rohen
 * Request-Bodys mit dem Produkt-Secret-Key (siehe FSD_Settings), verglichen
 * mit dem Header "X-Signature".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Webhook {

	const NAMESPACE = 'fsd/v1';

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle( WP_REST_Request $request ) {
		$secret_key = FSD_Settings::get_settings()['secret_key'];
		$raw_body   = $request->get_body();
		$signature  = (string) $request->get_header( 'x-signature' );

		if ( '' === $secret_key || '' === $signature
			|| ! hash_equals( hash_hmac( 'sha256', $raw_body, $secret_key ), $signature ) ) {
			// Ungültige Signatur: keine Details preisgeben, siehe Freemius-Dokumentation.
			return new WP_REST_Response( null, 200 );
		}

		$event = json_decode( $raw_body );

		if ( is_object( $event ) && ! empty( $event->type ) && 'payment.created' === $event->type ) {
			$this->notify_purchase( $event );
		}

		return new WP_REST_Response( null, 200 );
	}

	private function notify_purchase( $event ) {
		$email_settings = FSD_Email_Settings::get_settings();

		if ( empty( $email_settings['enabled'] ) || empty( $email_settings['recipients'] ) ) {
			return;
		}

		$objects = isset( $event->objects ) ? $event->objects : new stdClass();
		$payment = isset( $objects->payment ) ? $objects->payment : new stdClass();
		$user    = isset( $objects->user ) ? $objects->user : null;
		$plan    = isset( $objects->plan ) ? $objects->plan : null;
		$product = isset( $objects->product ) ? $objects->product : null;

		$product_title = ( $product && ! empty( $product->title ) ) ? $product->title : get_bloginfo( 'name' );
		$plan_title    = '—';
		if ( $plan && ! empty( $plan->title ) ) {
			$plan_title = $plan->title;
		} elseif ( $plan && ! empty( $plan->name ) ) {
			$plan_title = $plan->name;
		}

		$amount   = isset( $payment->gross ) ? number_format_i18n( (float) $payment->gross, 2 ) : '—';
		$currency = isset( $payment->currency ) ? strtoupper( $payment->currency ) : '';

		$customer_name  = '';
		$customer_email = '';
		if ( $user ) {
			$first          = isset( $user->first ) ? trim( (string) $user->first ) : '';
			$last           = isset( $user->last ) ? trim( (string) $user->last ) : '';
			$customer_name  = trim( $first . ' ' . $last );
			$customer_email = isset( $user->email ) ? (string) $user->email : '';
		}

		/* translators: %s: Produktname */
		$subject = sprintf( __( 'Neuer Kauf: %s', 'freemius-dashboard' ), $product_title );

		$lines   = array();
		$lines[] = sprintf( /* translators: %s: Produktname */ __( 'Produkt: %s', 'freemius-dashboard' ), $product_title );
		$lines[] = sprintf( /* translators: %s: Plan-Name */ __( 'Plan: %s', 'freemius-dashboard' ), $plan_title );
		$lines[] = sprintf( /* translators: 1: Betrag, 2: Währung */ __( 'Betrag: %1$s %2$s', 'freemius-dashboard' ), $amount, $currency );

		if ( '' !== $customer_name || '' !== $customer_email ) {
			$lines[] = sprintf(
				/* translators: 1: Kundenname, 2: Kunden-E-Mail */
				__( 'Kunde: %1$s %2$s', 'freemius-dashboard' ),
				$customer_name,
				'' !== $customer_email ? '<' . $customer_email . '>' : ''
			);
		}

		if ( ! empty( $payment->id ) ) {
			$lines[] = sprintf( /* translators: %s: Zahlungs-ID */ __( 'Zahlungs-ID: %s', 'freemius-dashboard' ), $payment->id );
		}

		$body = implode( "\n", $lines );

		foreach ( $email_settings['recipients'] as $recipient ) {
			wp_mail( $recipient, $subject, $body );
		}
	}
}
