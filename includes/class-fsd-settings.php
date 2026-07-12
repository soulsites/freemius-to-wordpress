<?php
/**
 * Einstellungsseite: Freemius API-Zugangsdaten.
 *
 * Freemius stellt zwei Arten von API-Keys bereit, die jeweils eine andere
 * "Scope-ID" im Authorization-Header benötigen:
 * - Developer-Keys ("Mein Profil → Keys"): Scope-ID = Developer-ID, Zugriff auf alle Produkte.
 * - Produkt-Keys (Produkt-Einstellungen → Keys): Scope-ID = Produkt-ID, nur für dieses eine Produkt.
 * Wird die falsche ID zum jeweiligen Schlüsselpaar gesendet, lehnt Freemius die
 * Anfrage mit "Invalid Authorization header" ab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Settings {

	const PAGE_SLUG = 'fsd-settings';
	const GROUP     = 'fsd_settings_group';

	public function register() {
		register_setting(
			self::GROUP,
			FSD_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'fsd_api_section',
			__( 'Freemius API-Zugangsdaten', 'freemius-dashboard' ),
			array( $this, 'render_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'api_scope',
			__( 'Art der API-Keys', 'freemius-dashboard' ),
			array( $this, 'render_scope_field' ),
			self::PAGE_SLUG,
			'fsd_api_section'
		);

		add_settings_field(
			'scope_id',
			__( 'Scope-ID', 'freemius-dashboard' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'fsd_api_section',
			array(
				'key'         => 'scope_id',
				'placeholder' => 'z. B. 1234',
				'description' => __( 'Bei Developer-Keys: deine Developer-ID (Mein Profil → Keys). Bei Produkt-Keys: die Produkt-ID selbst.', 'freemius-dashboard' ),
			)
		);

		add_settings_field(
			'public_key',
			__( 'Public Key', 'freemius-dashboard' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'fsd_api_section',
			array( 'key' => 'public_key', 'placeholder' => 'pk_...' )
		);

		add_settings_field(
			'secret_key',
			__( 'Secret Key', 'freemius-dashboard' ),
			array( $this, 'render_secret_field' ),
			self::PAGE_SLUG,
			'fsd_api_section'
		);

		add_settings_field(
			'product_id',
			__( 'Produkt-ID (für die Käufe-Abfrage)', 'freemius-dashboard' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'fsd_api_section',
			array(
				'key'         => 'product_id',
				'placeholder' => 'z. B. 5678',
				'description' => __( 'Das Produkt, dessen Käufe angezeigt werden sollen. Bei Produkt-Keys identisch mit der Scope-ID oben.', 'freemius-dashboard' ),
			)
		);

		add_settings_field(
			'affiliate_terms_id',
			__( 'Affiliate-Programm-ID (für die Affiliates-Seite)', 'freemius-dashboard' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'fsd_api_section',
			array(
				'key'         => 'affiliate_terms_id',
				'placeholder' => 'z. B. 42',
				'description' => __( 'Zu finden im Freemius Developer-Dashboard unter Produkt-Einstellungen → „AFFILIATION" (im ersten Tab). Nur nötig, wenn du die Affiliates-Seite nutzen willst.', 'freemius-dashboard' ),
			)
		);

		add_settings_section(
			'fsd_dev_api_section',
			__( '⚠ Freemius Developer-Keys (nur für das Affiliate-Anmeldeformular)', 'freemius-dashboard' ),
			array( $this, 'render_dev_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'dev_scope_id',
			__( 'Developer-ID (NICHT die Produkt-ID!)', 'freemius-dashboard' ),
			array( $this, 'render_dev_scope_id_field' ),
			self::PAGE_SLUG,
			'fsd_dev_api_section'
		);

		add_settings_field(
			'dev_public_key',
			__( 'Developer Public Key', 'freemius-dashboard' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'fsd_dev_api_section',
			array(
				'key'         => 'dev_public_key',
				'placeholder' => 'pk_...',
				'description' => __( 'Muss zur selben Developer-ID oben gehören, nicht zu einem Produkt-Key.', 'freemius-dashboard' ),
			)
		);

		add_settings_field(
			'dev_secret_key',
			__( 'Developer Secret Key', 'freemius-dashboard' ),
			array( $this, 'render_secret_field' ),
			self::PAGE_SLUG,
			'fsd_dev_api_section',
			array( 'key' => 'dev_secret_key' )
		);
	}

	public static function defaults() {
		return array(
			'api_scope'          => 'developer',
			'scope_id'           => '',
			'public_key'         => '',
			'secret_key'         => '',
			'product_id'         => '',
			'affiliate_terms_id' => '',
			'dev_scope_id'       => '',
			'dev_public_key'     => '',
			'dev_secret_key'     => '',
		);
	}

	public static function get_settings() {
		$settings = get_option( FSD_OPTION_KEY, self::defaults() );
		$settings = is_array( $settings ) ? $settings : array();

		// Migration von Versionen < 1.1.0: "developer_id" hieß früher fest so
		// und ging von Developer-Scope-Keys aus.
		if ( empty( $settings['scope_id'] ) && ! empty( $settings['developer_id'] ) ) {
			$settings['scope_id']  = $settings['developer_id'];
			$settings['api_scope'] = 'developer';
		}

		return wp_parse_args( $settings, self::defaults() );
	}

	public function sanitize( $input ) {
		$existing = self::get_settings();
		$output   = self::defaults();

		$output['api_scope'] = ( isset( $input['api_scope'] ) && 'product' === $input['api_scope'] ) ? 'product' : 'developer';
		$output['scope_id']  = isset( $input['scope_id'] ) ? sanitize_text_field( $input['scope_id'] ) : '';
		$output['public_key'] = isset( $input['public_key'] ) ? sanitize_text_field( $input['public_key'] ) : '';
		$output['product_id'] = isset( $input['product_id'] ) ? sanitize_text_field( $input['product_id'] ) : '';
		$output['affiliate_terms_id'] = isset( $input['affiliate_terms_id'] ) ? sanitize_text_field( $input['affiliate_terms_id'] ) : '';
		$output['dev_scope_id']   = isset( $input['dev_scope_id'] ) ? sanitize_text_field( $input['dev_scope_id'] ) : '';
		$output['dev_public_key'] = isset( $input['dev_public_key'] ) ? sanitize_text_field( $input['dev_public_key'] ) : '';

		// Bei Produkt-Keys ist die Scope-ID zwingend identisch mit der Produkt-ID –
		// eine Abweichung hier ist genau das, was Freemius mit
		// "Invalid Authorization header" quittiert.
		if ( 'product' === $output['api_scope'] ) {
			$output['product_id'] = $output['scope_id'];
		}

		// Secret Keys nur überschreiben, wenn ein neuer Wert eingegeben wurde,
		// damit die maskierten Felder beim Speichern nicht versehentlich geleert werden.
		if ( isset( $input['secret_key'] ) && '' !== trim( $input['secret_key'] ) ) {
			$output['secret_key'] = sanitize_text_field( $input['secret_key'] );
		} else {
			$output['secret_key'] = $existing['secret_key'];
		}

		if ( isset( $input['dev_secret_key'] ) && '' !== trim( $input['dev_secret_key'] ) ) {
			$output['dev_secret_key'] = sanitize_text_field( $input['dev_secret_key'] );
		} else {
			$output['dev_secret_key'] = $existing['dev_secret_key'];
		}

		add_settings_error(
			FSD_OPTION_KEY,
			'fsd_settings_saved',
			__( 'Einstellungen gespeichert.', 'freemius-dashboard' ),
			'success'
		);

		return $output;
	}

	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Developer-Keys findest du oben rechts unter „Mein Profil → Keys“ (Zugriff auf alle deine Produkte). Produkt-Keys findest du in den Einstellungen des jeweiligen Produkts unter „Keys“ (nur für dieses eine Produkt). Der Secret Key wird lokal in der WordPress-Datenbank gespeichert – beschränke den Zugriff auf vertrauenswürdige Administratoren.', 'freemius-dashboard' ) . '</p>';
	}

	public function render_scope_field() {
		$settings = self::get_settings();
		$scope    = $settings['api_scope'];
		?>
		<fieldset id="fsd-scope-field">
			<label>
				<input type="radio" name="<?php echo esc_attr( FSD_OPTION_KEY . '[api_scope]' ); ?>" value="developer" <?php checked( $scope, 'developer' ); ?> />
				<?php esc_html_e( 'Developer-Keys (Mein Profil → Keys)', 'freemius-dashboard' ); ?>
			</label>
			<br />
			<label>
				<input type="radio" name="<?php echo esc_attr( FSD_OPTION_KEY . '[api_scope]' ); ?>" value="product" <?php checked( $scope, 'product' ); ?> />
				<?php esc_html_e( 'Produkt-Keys (Produkt-Einstellungen → Keys)', 'freemius-dashboard' ); ?>
			</label>
		</fieldset>
		<?php
	}

	public function render_text_field( $args ) {
		$settings = self::get_settings();
		$key      = $args['key'];
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		?>
		<input
			type="text"
			class="regular-text fsd-input"
			id="<?php echo esc_attr( 'fsd-field-' . $key ); ?>"
			name="<?php echo esc_attr( FSD_OPTION_KEY . '[' . $key . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
			autocomplete="off"
		/>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_secret_field( $args = array() ) {
		$settings = self::get_settings();
		$key      = isset( $args['key'] ) ? $args['key'] : 'secret_key';
		$has_key  = '' !== $settings[ $key ];
		?>
		<input
			type="password"
			class="regular-text fsd-input"
			name="<?php echo esc_attr( FSD_OPTION_KEY . '[' . $key . ']' ); ?>"
			value=""
			placeholder="<?php echo $has_key ? esc_attr__( '•••••••••••••••• (unverändert lassen)', 'freemius-dashboard' ) : esc_attr__( 'sk_...', 'freemius-dashboard' ); ?>"
			autocomplete="new-password"
		/>
		<?php if ( $has_key ) : ?>
			<p class="description"><?php esc_html_e( 'Es ist bereits ein Secret Key hinterlegt. Nur ausfüllen, um ihn zu ändern.', 'freemius-dashboard' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_dev_section_intro() {
		?>
		<p>
			<?php esc_html_e( 'Das Anlegen von Affiliates über die Freemius-API erfordert zwingend Developer-Keys ("Mein Profil → Keys" – oben rechts im Freemius-Dashboard, NICHT in den Produkt-Einstellungen) – Produkt-Keys werden von Freemius dafür mit "AccessForbidden" abgelehnt, unabhängig von der Auswahl oben. Diese drei Felder werden ausschließlich für das [fsd_affiliate_signup]-Formular verwendet, alle anderen Funktionen (Dashboard, Käufe, Affiliates-Liste) nutzen weiterhin nur die Zugangsdaten im Abschnitt oben.', 'freemius-dashboard' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Häufigster Fehler ("Invalid Authorization header"): hier wird versehentlich die Produkt-ID statt der Developer-ID eingetragen, oder Public/Secret Key stammen aus unterschiedlichen Schlüsselpaaren. Alle drei Felder müssen aus derselben Seite "Mein Profil → Keys" kopiert werden.', 'freemius-dashboard' ); ?>
		</p>
		<?php
	}

	public function render_dev_scope_id_field() {
		$settings   = self::get_settings();
		$dev_id     = $settings['dev_scope_id'];
		$product_id = $settings['product_id'];
		$scope_id   = $settings['scope_id'];
		?>
		<input
			type="text"
			class="regular-text fsd-input"
			id="fsd-field-dev_scope_id"
			name="<?php echo esc_attr( FSD_OPTION_KEY . '[dev_scope_id]' ); ?>"
			value="<?php echo esc_attr( $dev_id ); ?>"
			placeholder="z. B. 987 (deine persönliche Developer-ID)"
			autocomplete="off"
		/>
		<p class="description">
			<?php esc_html_e( 'Zu finden unter „Mein Profil → Keys“ – eine kleine, produktunabhängige Zahl, verschieden von jeder Produkt- oder Affiliate-Programm-ID auf dieser Seite.', 'freemius-dashboard' ); ?>
		</p>
		<?php if ( '' !== $dev_id && ( $dev_id === $product_id || $dev_id === $scope_id ) ) : ?>
			<p class="fsd-notice fsd-notice--error">
				<?php esc_html_e( 'Warnung: Diese ID ist identisch mit der Produkt-ID / Scope-ID oben. Das ist bei einer Developer-ID unwahrscheinlich – bitte im Freemius-Dashboard unter „Mein Profil → Keys“ prüfen.', 'freemius-dashboard' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}
}
