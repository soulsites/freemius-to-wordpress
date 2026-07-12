<?php
/**
 * Frontend-Shortcode [fsd_affiliate_signup]: Anmeldeformular, über das sich
 * Besucher als Freemius-Affiliate-Partner bewerben können. Vor dem Anlegen
 * muss die angegebene E-Mail-Adresse bestätigt werden: die Bewerbung
 * verschickt einen sechsstelligen Bestätigungscode per Mail (reiner Text,
 * kein Link, kein HTML). Der Besucher trägt diesen Code im Formular ein;
 * stimmt er, wird der Affiliate per API mit Status "pending" angelegt.
 * Freigabe/Ablehnung erfolgt weiterhin manuell im Freemius-Dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Affiliate_Signup {

	const SHORTCODE       = 'fsd_affiliate_signup';
	const NONCE_ACTION    = 'fsd_affiliate_signup';
	const REQUEST_ACTION  = 'fsd_affiliate_request_code';
	const VERIFY_ACTION   = 'fsd_affiliate_verify_code';
	const PENDING_TTL     = 15 * MINUTE_IN_SECONDS;
	const RESEND_COOLDOWN = 60; // Sekunden.
	const MAX_ATTEMPTS    = 5;  // Falscheingaben pro Code, bevor er verfällt.

	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_' . self::REQUEST_ACTION, array( $this, 'ajax_request_code' ) );
		add_action( 'wp_ajax_nopriv_' . self::REQUEST_ACTION, array( $this, 'ajax_request_code' ) );
		add_action( 'wp_ajax_' . self::VERIFY_ACTION, array( $this, 'ajax_verify_code' ) );
		add_action( 'wp_ajax_nopriv_' . self::VERIFY_ACTION, array( $this, 'ajax_verify_code' ) );
	}

	/**
	 * Freemius lehnt das Anlegen von Affiliates mit Produkt-Keys ab
	 * ("AccessForbidden – required scope is user"), daher wird hierfür immer
	 * mit den separat hinterlegten Developer-Keys authentifiziert.
	 */
	private function get_dev_api( $settings ) {
		return new FSD_Api( $settings['dev_scope_id'], $settings['dev_public_key'], $settings['dev_secret_key'], $settings['product_id'] );
	}

	public function render_shortcode() {
		$settings = FSD_Settings::get_settings();
		$api      = $this->get_dev_api( $settings );

		if ( ! $api->is_configured() || '' === $settings['affiliate_terms_id'] ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p>' . esc_html__( 'Affiliate-Anmeldeformular: Bitte zuerst die Freemius Developer-Keys und die Affiliate-Programm-ID in den Freemius-Einstellungen hinterlegen.', 'freemius-dashboard' ) . '</p>';
			}
			return '';
		}

		wp_enqueue_style( 'fsd-admin', FSD_PLUGIN_URL . 'assets/css/fsd-admin.css', array(), FSD_VERSION );
		wp_enqueue_script( 'fsd-affiliate-signup', FSD_PLUGIN_URL . 'assets/js/fsd-affiliate-signup.js', array(), FSD_VERSION, true );
		wp_localize_script(
			'fsd-affiliate-signup',
			'fsdAffiliateSignup',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'requestAction'  => self::REQUEST_ACTION,
				'verifyAction'   => self::VERIFY_ACTION,
				'resendCooldown' => self::RESEND_COOLDOWN,
				'i18n'           => array(
					'sending'  => __( 'Wird gesendet …', 'freemius-dashboard' ),
					'checking' => __( 'Wird geprüft …', 'freemius-dashboard' ),
					'error'    => __( 'Fehler: ', 'freemius-dashboard' ),
					'resend'   => __( 'Code erneut senden', 'freemius-dashboard' ),
					/* translators: %d: Sekunden bis erneutes Senden möglich ist */
					'resendIn' => __( 'Code erneut senden (%ds)', 'freemius-dashboard' ),
				),
			)
		);

		ob_start();
		?>
		<form class="fsd-wrap fsd-affiliate-form" id="fsd-affiliate-signup-form" novalidate>
			<div class="fsd-card" id="fsd-affiliate-step-1">
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
					<button type="button" class="fsd-btn fsd-btn--filled" id="fsd-aff-request-code-btn"><?php esc_html_e( 'E-Mail-Adresse bestätigen', 'freemius-dashboard' ); ?></button>
				</p>
			</div>

			<div class="fsd-card" id="fsd-affiliate-step-2" style="display: none;">
				<p class="fsd-affiliate-form__field">
					<?php esc_html_e( 'Wir haben dir einen sechsstelligen Bestätigungscode per E-Mail geschickt. Bitte gib ihn hier ein, um deine Bewerbung abzuschließen.', 'freemius-dashboard' ); ?>
				</p>
				<input type="hidden" id="fsd-aff-token" name="token" value="" />
				<p class="fsd-affiliate-form__field">
					<label for="fsd-aff-code"><?php esc_html_e( 'Bestätigungscode', 'freemius-dashboard' ); ?> *</label>
					<input class="fsd-input" type="text" id="fsd-aff-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" placeholder="123456" />
				</p>
				<p>
					<button type="button" class="fsd-btn fsd-btn--filled" id="fsd-aff-verify-btn"><?php esc_html_e( 'Code bestätigen', 'freemius-dashboard' ); ?></button>
				</p>
				<p>
					<button type="button" class="fsd-btn fsd-btn--tonal" id="fsd-aff-resend-btn" disabled="disabled"><?php esc_html_e( 'Code erneut senden', 'freemius-dashboard' ); ?></button>
					<button type="button" class="fsd-btn fsd-btn--tonal" id="fsd-aff-back-btn"><?php esc_html_e( 'Zurück', 'freemius-dashboard' ); ?></button>
				</p>
			</div>

			<div id="fsd-affiliate-signup-result" class="fsd-affiliate-form__result" role="status"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Schritt 1: validiert die Eingaben, legt die Bewerbung als unbestätigt
	 * unter einem zufälligen Token zwischen und verschickt den Bestätigungscode.
	 */
	public function ajax_request_code() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// Honeypot: Bots füllen üblicherweise auch versteckte Felder aus. Wir
		// täuschen einen Erfolg vor, ohne wirklich eine E-Mail zu verschicken.
		if ( ! empty( $_POST['website'] ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'Wir haben dir einen Bestätigungscode per E-Mail geschickt.', 'freemius-dashboard' ),
					'token'   => wp_generate_password( 32, false, false ),
				)
			);
		}

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( '' === $name || '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Bitte Name und eine gültige E-Mail-Adresse angeben.', 'freemius-dashboard' ) ) );
		}

		$cooldown_key = 'fsd_aff_cooldown_' . md5( strtolower( $email ) );
		if ( false !== get_transient( $cooldown_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Bitte warte kurz, bevor du einen neuen Code anforderst.', 'freemius-dashboard' ) ) );
		}

		$throttle_key = 'fsd_aff_signup_' . md5( strtolower( $email ) );
		if ( false !== get_transient( $throttle_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Du hast dich bereits beworben. Bitte warte auf die Bestätigungsmail von Freemius.', 'freemius-dashboard' ) ) );
		}

		$domain       = isset( $_POST['domain'] ) ? esc_url_raw( wp_unslash( $_POST['domain'] ) ) : '';
		$paypal_email = isset( $_POST['paypal_email'] ) ? sanitize_email( wp_unslash( $_POST['paypal_email'] ) ) : '';
		$promo        = isset( $_POST['promotion_method_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['promotion_method_description'] ) ) : '';

		$token = wp_generate_password( 32, false, false );
		$code  = $this->generate_code();

		set_transient(
			'fsd_aff_pending_' . $token,
			array(
				'code'                          => $code,
				'attempts'                      => 0,
				'name'                          => $name,
				'email'                         => $email,
				'domain'                        => $domain,
				'paypal_email'                  => $paypal_email,
				'promotion_method_description'  => $promo,
			),
			self::PENDING_TTL
		);
		set_transient( $cooldown_key, 1, self::RESEND_COOLDOWN );

		$this->send_verification_email( $email, $name, $code );

		wp_send_json_success(
			array(
				'message' => __( 'Wir haben dir einen sechsstelligen Bestätigungscode per E-Mail geschickt. Bitte gib ihn hier ein.', 'freemius-dashboard' ),
				'token'   => $token,
			)
		);
	}

	/**
	 * Schritt 2: prüft den eingegebenen Code und legt bei Erfolg direkt die
	 * Bewerbung an (Status "pending").
	 */
	public function ajax_verify_code() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$code  = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ) ) : '';

		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Bitte fordere zuerst einen Bestätigungscode an.', 'freemius-dashboard' ) ) );
		}

		if ( '' === $code ) {
			wp_send_json_error( array( 'message' => __( 'Bitte gib den Bestätigungscode aus der E-Mail ein.', 'freemius-dashboard' ) ) );
		}

		$key     = 'fsd_aff_pending_' . $token;
		$pending = get_transient( $key );

		if ( false === $pending || ! is_array( $pending ) ) {
			wp_send_json_error( array( 'message' => __( 'Der Bestätigungscode ist abgelaufen oder ungültig. Bitte fordere einen neuen Code an.', 'freemius-dashboard' ) ) );
		}

		if ( ! hash_equals( (string) $pending['code'], (string) $code ) ) {
			$pending['attempts'] = isset( $pending['attempts'] ) ? (int) $pending['attempts'] + 1 : 1;

			if ( $pending['attempts'] >= self::MAX_ATTEMPTS ) {
				delete_transient( $key );
				wp_send_json_error( array( 'message' => __( 'Zu viele Fehlversuche. Bitte fordere einen neuen Bestätigungscode an.', 'freemius-dashboard' ) ) );
			}

			set_transient( $key, $pending, self::PENDING_TTL );
			wp_send_json_error( array( 'message' => __( 'Der Code stimmt nicht. Bitte prüfe deine E-Mail und versuche es erneut.', 'freemius-dashboard' ) ) );
		}

		$throttle_key = 'fsd_aff_signup_' . md5( strtolower( $pending['email'] ) );
		if ( false !== get_transient( $throttle_key ) ) {
			delete_transient( $key );
			wp_send_json_error( array( 'message' => __( 'Du hast dich bereits beworben. Bitte warte auf die Bestätigungsmail von Freemius.', 'freemius-dashboard' ) ) );
		}

		$settings = FSD_Settings::get_settings();
		$api      = $this->get_dev_api( $settings );

		if ( ! $api->is_configured() || '' === $settings['affiliate_terms_id'] ) {
			wp_send_json_error( array( 'message' => __( 'Das Affiliate-Programm ist derzeit nicht verfügbar.', 'freemius-dashboard' ) ) );
		}

		$result = $api->create_affiliate(
			$settings['affiliate_terms_id'],
			array(
				'name'                          => $pending['name'],
				'email'                         => $pending['email'],
				'domain'                        => $pending['domain'],
				'paypal_email'                  => $pending['paypal_email'],
				'promotion_method_description'  => $pending['promotion_method_description'],
			)
		);

		delete_transient( $key );

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

	private function generate_code() {
		return str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	private function send_verification_email( $email, $name, $code ) {
		$site_name = get_bloginfo( 'name' );

		/* translators: %s: site name */
		$subject = sprintf( __( 'Dein Bestätigungscode für das %s-Partnerprogramm', 'freemius-dashboard' ), $site_name );

		$lines = array();
		/* translators: %s: applicant name */
		$lines[] = sprintf( __( 'Hallo %s,', 'freemius-dashboard' ), $name );
		$lines[] = '';
		$lines[] = __( 'bitte bestätige deine E-Mail-Adresse, um deine Bewerbung fürs Partnerprogramm abzuschließen. Gib dazu im Anmeldeformular diesen Code ein:', 'freemius-dashboard' );
		$lines[] = '';
		$lines[] = $code;
		$lines[] = '';
		$lines[] = __( 'Der Code ist 15 Minuten gültig. Falls du diese Bewerbung nicht angefordert hast, kannst du diese E-Mail einfach ignorieren.', 'freemius-dashboard' );

		wp_mail( $email, $subject, implode( "\n", $lines ) );
	}
}
