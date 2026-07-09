<?php
/**
 * Frontend-Shortcode [fsd_affiliate_signup]: Anmeldeformular, über das sich
 * Besucher als Freemius-Affiliate-Partner bewerben können. Die Bewerbung wird
 * per API als Affiliate mit Status "pending" angelegt; Freigabe/Ablehnung
 * erfolgt weiterhin manuell im Freemius-Dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Affiliate_Signup {

	const SHORTCODE    = 'fsd_affiliate_signup';
	const NONCE_ACTION = 'fsd_affiliate_signup';
	const AJAX_ACTION  = 'fsd_affiliate_signup';

	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_submit' ) );
	}

	public function render_shortcode() {
		$settings = FSD_Settings::get_settings();
		$api      = new FSD_Api( $settings['scope_id'], $settings['public_key'], $settings['secret_key'], $settings['product_id'] );

		if ( ! $api->is_configured() || '' === $settings['affiliate_terms_id'] ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p>' . esc_html__( 'Affiliate-Anmeldeformular: Bitte zuerst die Freemius API-Zugangsdaten und die Affiliate-Programm-ID in den Freemius-Einstellungen hinterlegen.', 'freemius-dashboard' ) . '</p>';
			}
			return '';
		}

		wp_enqueue_style( 'fsd-admin', FSD_PLUGIN_URL . 'assets/css/fsd-admin.css', array(), FSD_VERSION );
		wp_enqueue_script( 'fsd-affiliate-signup', FSD_PLUGIN_URL . 'assets/js/fsd-affiliate-signup.js', array(), FSD_VERSION, true );
		wp_localize_script(
			'fsd-affiliate-signup',
			'fsdAffiliateSignup',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'sending' => __( 'Wird gesendet …', 'freemius-dashboard' ),
					'error'   => __( 'Fehler: ', 'freemius-dashboard' ),
				),
			)
		);

		ob_start();
		?>
		<form class="fsd-wrap fsd-affiliate-form" id="fsd-affiliate-signup-form" novalidate>
			<div class="fsd-card">
				<p class="fsd-affiliate-form__field">
					<label for="fsd-aff-name"><?php esc_html_e( 'Name', 'freemius-dashboard' ); ?> *</label>
					<input class="fsd-input" type="text" id="fsd-aff-name" name="name" required autocomplete="name" />
				</p>
				<p class="fsd-affiliate-form__field">
					<label for="fsd-aff-email"><?php esc_html_e( 'E-Mail-Adresse', 'freemius-dashboard' ); ?> *</label>
					<input class="fsd-input" type="email" id="fsd-aff-email" name="email" required autocomplete="email" />
				</p>
				<p class="fsd-affiliate-form__field">
					<label for="fsd-aff-domain"><?php esc_html_e( 'Website/Domain', 'freemius-dashboard' ); ?></label>
					<input class="fsd-input" type="url" id="fsd-aff-domain" name="domain" placeholder="https://" autocomplete="url" />
				</p>
				<p class="fsd-affiliate-form__field">
					<label for="fsd-aff-paypal"><?php esc_html_e( 'PayPal-E-Mail (für Auszahlungen)', 'freemius-dashboard' ); ?></label>
					<input class="fsd-input" type="email" id="fsd-aff-paypal" name="paypal_email" autocomplete="off" />
				</p>
				<p class="fsd-affiliate-form__field">
					<label for="fsd-aff-promo"><?php esc_html_e( 'Wie möchtest du das Produkt bewerben?', 'freemius-dashboard' ); ?></label>
					<textarea class="fsd-input" id="fsd-aff-promo" name="promotion_method_description" rows="4"></textarea>
				</p>
				<p class="fsd-affiliate-form__field fsd-affiliate-form__honeypot" aria-hidden="true">
					<label for="fsd-aff-website2"><?php esc_html_e( 'Bitte leer lassen', 'freemius-dashboard' ); ?></label>
					<input type="text" id="fsd-aff-website2" name="website" tabindex="-1" autocomplete="off" />
				</p>
				<p>
					<button type="submit" class="fsd-btn fsd-btn--filled"><?php esc_html_e( 'Als Affiliate bewerben', 'freemius-dashboard' ); ?></button>
				</p>
				<div id="fsd-affiliate-signup-result" class="fsd-affiliate-form__result" role="status"></div>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	public function ajax_submit() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// Honeypot: Bots füllen üblicherweise auch versteckte Felder aus.
		if ( ! empty( $_POST['website'] ) ) {
			wp_send_json_success( array( 'message' => __( 'Danke für deine Bewerbung!', 'freemius-dashboard' ) ) );
		}

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( '' === $name || '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Bitte Name und eine gültige E-Mail-Adresse angeben.', 'freemius-dashboard' ) ) );
		}

		// Einfacher Spam-/Doppel-Schutz: pro E-Mail max. 1 Bewerbung alle 10 Minuten.
		$throttle_key = 'fsd_aff_signup_' . md5( strtolower( $email ) );
		if ( false !== get_transient( $throttle_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Du hast dich bereits beworben. Bitte warte auf die Bestätigungsmail von Freemius.', 'freemius-dashboard' ) ) );
		}

		$domain       = isset( $_POST['domain'] ) ? esc_url_raw( wp_unslash( $_POST['domain'] ) ) : '';
		$paypal_email = isset( $_POST['paypal_email'] ) ? sanitize_email( wp_unslash( $_POST['paypal_email'] ) ) : '';
		$promo        = isset( $_POST['promotion_method_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['promotion_method_description'] ) ) : '';

		$settings = FSD_Settings::get_settings();
		$api      = new FSD_Api( $settings['scope_id'], $settings['public_key'], $settings['secret_key'], $settings['product_id'] );

		if ( ! $api->is_configured() || '' === $settings['affiliate_terms_id'] ) {
			wp_send_json_error( array( 'message' => __( 'Das Affiliate-Programm ist derzeit nicht verfügbar.', 'freemius-dashboard' ) ) );
		}

		$result = $api->create_affiliate(
			$settings['affiliate_terms_id'],
			array(
				'name'                          => $name,
				'email'                         => $email,
				'domain'                        => $domain,
				'paypal_email'                  => $paypal_email,
				'promotion_method_description'  => $promo,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		set_transient( $throttle_key, 1, 10 * MINUTE_IN_SECONDS );

		delete_transient( 'fsd_affiliates_' . $settings['affiliate_terms_id'] );

		wp_send_json_success(
			array(
				'message' => __( 'Danke für deine Bewerbung! Du erhältst in Kürze eine E-Mail von Freemius mit den nächsten Schritten.', 'freemius-dashboard' ),
			)
		);
	}
}
