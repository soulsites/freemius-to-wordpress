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
	const CRON_HOOK = 'fsd_send_purchase_notification';
	const AC_CRON_HOOK = 'fsd_sync_activecampaign_event';

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
			// Der Mailversand (wp_mail) kann je nach SMTP-Konfiguration mehrere
			// Sekunden dauern. Freemius bricht Webhook-Zustellungen nach 10 Sekunden
			// als Timeout ab, daher wird die Benachrichtigung asynchron über WP-Cron
			// verschickt und der Webhook sofort quittiert.
			wp_schedule_single_event( time(), self::CRON_HOOK, array( $event ) );
		}

		if ( is_object( $event ) && ! empty( $event->type ) && in_array( $event->type, array( 'payment.created', 'user.marketing.opted_in', 'user.marketing.opted_out', 'user.marketing.reset' ), true ) ) {
			wp_schedule_single_event( time(), self::AC_CRON_HOOK, array( $event ) );
		}

		return new WP_REST_Response( null, 200 );
	}

	public function notify_purchase( $event ) {
		$email_settings = FSD_Email_Settings::get_settings();

		if ( empty( $email_settings['enabled'] ) || empty( $email_settings['recipients'] ) ) {
			return false;
		}

		return $this->send_purchase_notification( $event, $email_settings );
	}

	/**
	 * Verschickt eine Beispiel-Kaufmail an die gespeicherten Empfänger.
	 *
	 * Der Test ist unabhängig vom Aktivierungs-Schalter und vom Freemius-Webhook,
	 * verwendet aber denselben Absender und dasselbe Template wie echte Kaufmails.
	 *
	 * @return bool True, wenn alle Testmails von wp_mail() angenommen wurden.
	 */
	public function send_test_email() {
		$email_settings = FSD_Email_Settings::get_settings();

		if ( empty( $email_settings['recipients'] ) ) {
			return false;
		}

		$event = (object) array(
			'objects' => (object) array(
				'payment' => (object) array(
					'id'       => 'TEST-' . gmdate( 'Ymd-His' ),
					'gross'    => 49.00,
					'currency' => 'EUR',
				),
				'user'    => (object) array(
					'first' => 'Max',
					'last'  => 'Mustermann',
					'email' => 'max.mustermann@example.com',
				),
				'plan'    => (object) array(
					'title' => __( 'Beispiel-Premiumplan', 'freemius-dashboard' ),
				),
				'product' => (object) array(
					'title' => __( 'Testprodukt', 'freemius-dashboard' ),
				),
			),
		);

		return $this->send_purchase_notification( $event, $email_settings, true );
	}

	private function send_purchase_notification( $event, $email_settings, $is_test = false ) {

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

		$gross    = isset( $payment->gross ) ? (float) $payment->gross : 0.0;
		$amount   = number_format_i18n( $gross, 2 );
		$currency = isset( $payment->currency ) ? strtoupper( $payment->currency ) : '';

		if ( ! empty( $email_settings['disable_zero_amount'] ) && 0.0 === $gross ) {
			return false;
		}

		$customer_name  = '';
		$customer_email = '';
		if ( $user ) {
			$first          = isset( $user->first ) ? trim( (string) $user->first ) : '';
			$last           = isset( $user->last ) ? trim( (string) $user->last ) : '';
			$customer_name  = trim( $first . ' ' . $last );
			$customer_email = isset( $user->email ) ? (string) $user->email : '';
		}

		$payment_id = ! empty( $payment->id ) ? (string) $payment->id : '';

		/* translators: %s: Produktname */
		$subject = sprintf( __( '🎉 Neuer Kauf: %s', 'freemius-dashboard' ), $product_title );
		if ( $is_test ) {
			$subject = sprintf( __( '[Testmail] %s', 'freemius-dashboard' ), $subject );
		}

		$body = $this->render_html_body(
			array(
				'product_title'  => $product_title,
				'plan_title'     => $plan_title,
				'amount'         => $amount,
				'currency'       => $currency,
				'customer_name'  => $customer_name,
				'customer_email' => $customer_email,
				'payment_id'     => $payment_id,
			)
		);

		$sender_name  = ! empty( $email_settings['sender_name'] ) ? $email_settings['sender_name'] : get_bloginfo( 'name' );
		$sender_email = ! empty( $email_settings['sender_email'] ) ? $email_settings['sender_email'] : get_option( 'admin_email' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $sender_name, $sender_email ),
		);

		$sent = true;
		foreach ( $email_settings['recipients'] as $recipient ) {
			if ( ! wp_mail( $recipient, $subject, $body, $headers ) ) {
				$sent = false;
			}
		}

		return $sent;
	}

	private function render_html_body( $data ) {
		$product_title  = esc_html( $data['product_title'] );
		$plan_title     = esc_html( $data['plan_title'] );
		$amount         = esc_html( $data['amount'] );
		$currency       = esc_html( $data['currency'] );
		$customer_name  = esc_html( $data['customer_name'] );
		$customer_email = esc_html( $data['customer_email'] );
		$payment_id     = esc_html( $data['payment_id'] );

		$customer_row = '';
		if ( '' !== $customer_name || '' !== $customer_email ) {
			$customer_value = trim( $customer_name . ( '' !== $customer_email ? ' &lt;' . $customer_email . '&gt;' : '' ) );
			$customer_row   = $this->render_row( __( 'Kunde', 'freemius-dashboard' ), $customer_value );
		}

		$payment_row = '' !== $payment_id ? $this->render_row( __( 'Zahlungs-ID', 'freemius-dashboard' ), $payment_id ) : '';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
</head>
<body style="margin:0; padding:0; background-color:#f3f4f8; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f8; padding:32px 16px;">
		<tr>
			<td align="center">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#ffffff; border-radius:20px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
					<tr>
						<td style="background-color:#37618e; padding:32px 32px 28px; text-align:center;">
							<div style="font-size:36px; line-height:1; margin-bottom:8px;">🎉</div>
							<h1 style="margin:0; color:#ffffff; font-size:22px; font-weight:600;"><?php esc_html_e( 'Neuer Verkauf!', 'freemius-dashboard' ); ?></h1>
						</td>
					</tr>
					<tr>
						<td style="padding:32px;">
							<p style="margin:0 0 24px; color:#1a1c1e; font-size:16px; line-height:1.6;">
								<?php esc_html_e( 'Glückwunsch, du hast gerade einen neuen Kauf erzielt. Weiter so! 🚀', 'freemius-dashboard' ); ?>
							</p>

							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
								<tr>
									<td style="background-color:#d3e4ff; border-radius:16px; padding:20px 24px; text-align:center;">
										<div style="color:#43474e; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:4px;"><?php esc_html_e( 'Betrag', 'freemius-dashboard' ); ?></div>
										<div style="color:#001c37; font-size:30px; font-weight:700;"><?php echo $amount; ?> <?php echo $currency; ?></div>
									</td>
								</tr>
							</table>

							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
								<?php echo $this->render_row( __( 'Produkt', 'freemius-dashboard' ), $product_title ); ?>
								<?php echo $this->render_row( __( 'Plan', 'freemius-dashboard' ), $plan_title ); ?>
								<?php echo $customer_row; ?>
								<?php echo $payment_row; ?>
							</table>
						</td>
					</tr>
					<tr>
						<td style="padding:20px 32px; background-color:#f3f4f8; text-align:center;">
							<p style="margin:0; color:#43474e; font-size:12px;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	private function render_row( $label, $value ) {
		ob_start();
		?>
		<tr>
			<td style="padding:10px 0; border-bottom:1px solid #e2e4e9; color:#43474e; font-size:14px; width:40%;"><?php echo esc_html( $label ); ?></td>
			<td style="padding:10px 0; border-bottom:1px solid #e2e4e9; color:#1a1c1e; font-size:14px; font-weight:500; text-align:right;"><?php echo wp_kses( $value, array() ); ?></td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}
}
