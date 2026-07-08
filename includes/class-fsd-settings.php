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
	}

	public static function defaults() {
		return array(
			'api_scope'          => 'developer',
			'scope_id'           => '',
			'public_key'         => '',
			'secret_key'         => '',
			'product_id'         => '',
			'affiliate_terms_id' => '',
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

		// Bei Produkt-Keys ist die Scope-ID zwingend identisch mit der Produkt-ID –
		// eine Abweichung hier ist genau das, was Freemius mit
		// "Invalid Authorization header" quittiert.
		if ( 'product' === $output['api_scope'] ) {
			$output['product_id'] = $output['scope_id'];
		}

		// Secret Key nur überschreiben, wenn ein neuer Wert eingegeben wurde,
		// damit das maskierte Feld beim Speichern nicht versehentlich geleert wird.
		if ( isset( $input['secret_key'] ) && '' !== trim( $input['secret_key'] ) ) {
			$output['secret_key'] = sanitize_text_field( $input['secret_key'] );
		} else {
			$output['secret_key'] = $existing['secret_key'];
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

	public function render_secret_field() {
		$settings = self::get_settings();
		$has_key  = '' !== $settings['secret_key'];
		?>
		<input
			type="password"
			class="regular-text fsd-input"
			name="<?php echo esc_attr( FSD_OPTION_KEY . '[secret_key]' ); ?>"
			value=""
			placeholder="<?php echo $has_key ? esc_attr__( '•••••••••••••••• (unverändert lassen)', 'freemius-dashboard' ) : esc_attr__( 'sk_...', 'freemius-dashboard' ); ?>"
			autocomplete="new-password"
		/>
		<?php if ( $has_key ) : ?>
			<p class="description"><?php esc_html_e( 'Es ist bereits ein Secret Key hinterlegt. Nur ausfüllen, um ihn zu ändern.', 'freemius-dashboard' ); ?></p>
		<?php endif; ?>
		<?php
	}
}
