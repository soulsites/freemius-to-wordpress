<?php
/**
 * Einstellungsseite: E-Mail-Benachrichtigungen bei neuen Käufen.
 *
 * Freemius sendet dazu einen Webhook (Event "payment.created") an die hier
 * angezeigte URL. Der Webhook wird über den Secret Key aus FSD_Settings
 * signiert und in FSD_Webhook geprüft.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Email_Settings {

	const PAGE_SLUG = 'fsd-emails';
	const GROUP     = 'fsd_email_settings_group';

	public function register() {
		register_setting(
			self::GROUP,
			FSD_EMAIL_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'fsd_email_section',
			__( 'Kauf-Benachrichtigungen per E-Mail', 'freemius-dashboard' ),
			array( $this, 'render_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'enabled',
			__( 'Benachrichtigungen', 'freemius-dashboard' ),
			array( $this, 'render_enabled_field' ),
			self::PAGE_SLUG,
			'fsd_email_section'
		);

		add_settings_field(
			'recipients',
			__( 'Empfänger', 'freemius-dashboard' ),
			array( $this, 'render_recipients_field' ),
			self::PAGE_SLUG,
			'fsd_email_section'
		);

		add_settings_field(
			'sender',
			__( 'Absender', 'freemius-dashboard' ),
			array( $this, 'render_sender_field' ),
			self::PAGE_SLUG,
			'fsd_email_section'
		);

		add_settings_field(
			'disable_zero_amount',
			__( 'Kostenlose Käufe', 'freemius-dashboard' ),
			array( $this, 'render_disable_zero_amount_field' ),
			self::PAGE_SLUG,
			'fsd_email_section'
		);

		add_settings_field(
			'webhook_url',
			__( 'Webhook-URL für Freemius', 'freemius-dashboard' ),
			array( $this, 'render_webhook_url_field' ),
			self::PAGE_SLUG,
			'fsd_email_section'
		);
	}

	public static function defaults() {
		return array(
			'enabled'             => false,
			'recipients'          => array(),
			'sender_name'         => get_bloginfo( 'name' ),
			'sender_email'        => get_option( 'admin_email' ),
			'disable_zero_amount' => false,
		);
	}

	public static function get_settings() {
		$settings = get_option( FSD_EMAIL_OPTION_KEY, self::defaults() );
		$settings = is_array( $settings ) ? $settings : array();

		return wp_parse_args( $settings, self::defaults() );
	}

	public function sanitize( $input ) {
		$output = self::defaults();

		$output['enabled'] = ! empty( $input['enabled'] );

		$raw_recipients = isset( $input['recipients'] ) ? (string) $input['recipients'] : '';
		$lines          = preg_split( '/[\r\n,]+/', $raw_recipients );
		$emails         = array();

		foreach ( (array) $lines as $line ) {
			$email = sanitize_email( trim( $line ) );
			if ( '' !== $email && is_email( $email ) && ! in_array( $email, $emails, true ) ) {
				$emails[] = $email;
			}
		}

		$output['recipients'] = $emails;

		$sender_name = isset( $input['sender_name'] ) ? sanitize_text_field( wp_unslash( $input['sender_name'] ) ) : '';
		$output['sender_name'] = ( '' !== $sender_name ) ? $sender_name : self::defaults()['sender_name'];

		$sender_email = isset( $input['sender_email'] ) ? sanitize_email( wp_unslash( $input['sender_email'] ) ) : '';
		$output['sender_email'] = is_email( $sender_email ) ? $sender_email : self::defaults()['sender_email'];

		$output['disable_zero_amount'] = ! empty( $input['disable_zero_amount'] );

		add_settings_error(
			FSD_EMAIL_OPTION_KEY,
			'fsd_email_settings_saved',
			__( 'Einstellungen gespeichert.', 'freemius-dashboard' ),
			'success'
		);

		return $output;
	}

	public function render_section_intro() {
		$secret_key = FSD_Settings::get_settings()['secret_key'];
		?>
		<p>
			<?php esc_html_e( 'Freemius kann bei jedem Kauf einen Webhook an deine Website senden. Trage dazu die unten angezeigte URL im Freemius Developer-Dashboard unter „Events & Webhooks" als Endpoint ein und aktiviere mindestens das Event „payment.created". Diese Seite legt fest, an welche E-Mail-Adressen deine Website daraufhin automatisch eine Nachricht mit den Kaufinformationen verschickt.', 'freemius-dashboard' ); ?>
		</p>
		<?php if ( '' === $secret_key ) : ?>
			<p class="fsd-notice fsd-notice--error">
				<?php esc_html_e( 'Bitte hinterlege zuerst deinen Secret Key auf der Einstellungen-Seite – er wird auch zur Prüfung der Webhook-Signatur benötigt.', 'freemius-dashboard' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	public function render_enabled_field() {
		$settings = self::get_settings();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( FSD_EMAIL_OPTION_KEY . '[enabled]' ); ?>" value="1" <?php checked( $settings['enabled'] ); ?> />
			<?php esc_html_e( 'Bei jedem Kauf eine E-Mail an die unten stehenden Adressen senden.', 'freemius-dashboard' ); ?>
		</label>
		<?php
	}

	public function render_recipients_field() {
		$settings = self::get_settings();
		$value    = implode( "\n", $settings['recipients'] );
		?>
		<textarea
			class="large-text fsd-input"
			rows="5"
			id="fsd-field-recipients"
			name="<?php echo esc_attr( FSD_EMAIL_OPTION_KEY . '[recipients]' ); ?>"
			placeholder="name@example.com"
		><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Eine E-Mail-Adresse pro Zeile (oder durch Komma getrennt).', 'freemius-dashboard' ); ?></p>
		<?php
	}

	public function render_sender_field() {
		$settings = self::get_settings();
		?>
		<p>
			<label for="fsd-field-sender-name"><?php esc_html_e( 'Name', 'freemius-dashboard' ); ?></label><br />
			<input
				type="text"
				class="regular-text fsd-input"
				id="fsd-field-sender-name"
				name="<?php echo esc_attr( FSD_EMAIL_OPTION_KEY . '[sender_name]' ); ?>"
				value="<?php echo esc_attr( $settings['sender_name'] ); ?>"
			/>
		</p>
		<p>
			<label for="fsd-field-sender-email"><?php esc_html_e( 'E-Mail-Adresse', 'freemius-dashboard' ); ?></label><br />
			<input
				type="email"
				class="regular-text fsd-input"
				id="fsd-field-sender-email"
				name="<?php echo esc_attr( FSD_EMAIL_OPTION_KEY . '[sender_email]' ); ?>"
				value="<?php echo esc_attr( $settings['sender_email'] ); ?>"
			/>
		</p>
		<p class="description"><?php esc_html_e( 'Name und Adresse, mit denen die Kauf-Benachrichtigungen verschickt werden.', 'freemius-dashboard' ); ?></p>
		<?php
	}

	public function render_disable_zero_amount_field() {
		$settings = self::get_settings();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( FSD_EMAIL_OPTION_KEY . '[disable_zero_amount]' ); ?>" value="1" <?php checked( $settings['disable_zero_amount'] ); ?> />
			<?php esc_html_e( 'Keine E-Mail verschicken, wenn der Kaufbetrag 0 EUR beträgt.', 'freemius-dashboard' ); ?>
		</label>
		<?php
	}

	public function render_webhook_url_field() {
		$url = rest_url( FSD_Webhook::NAMESPACE . '/webhook' );
		?>
		<input
			type="text"
			class="regular-text fsd-input"
			style="width: 100%; max-width: 480px;"
			readonly="readonly"
			onclick="this.select();"
			value="<?php echo esc_url( $url ); ?>"
		/>
		<p class="description">
			<?php esc_html_e( 'Diese URL in Freemius unter Produkt-Einstellungen → „Events & Webhooks" als Endpoint eintragen und mindestens das Event „payment.created" aktivieren.', 'freemius-dashboard' ); ?>
		</p>
		<?php
	}
}
