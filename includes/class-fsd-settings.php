<?php
/**
 * Einstellungsseite: Freemius API-Zugangsdaten.
 *
 * Freemius stellt zwei Arten von API-Keys bereit, die jeweils eine andere
 * "Scope-ID" im Authorization-Header benötigen:
 * - Developer-Keys ("Mein Profil → Keys"): Scope-ID = Developer-ID, Zugriff auf alle Produkte.
 *   Nur damit lassen sich Affiliates über die API anlegen.
 * - Produkt-Keys (Produkt-Einstellungen → Keys): Scope-ID = Produkt-ID, nur für dieses eine Produkt.
 *   Damit werden Dashboard, Käufe und die Affiliates-Liste abgefragt.
 *
 * Deshalb werden hier beide Schlüsselpaare getrennt abgefragt – jedes mit
 * seiner eigenen ID – plus die Affiliate-Programm-ID. Ein früherer
 * "Scope"-Umschalter für ein einziges Schlüsselpaar entfällt; die Zuordnung
 * ergibt sich jetzt eindeutig aus dem jeweiligen Abschnitt.
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

		// 1) Developer-Keys – ausschließlich für das Affiliate-Anmeldeformular.
		add_settings_section(
			'fsd_dev_api_section',
			__( '1. Developer-Keys (für das Affiliate-Anmeldeformular)', 'freemius-dashboard' ),
			array( $this, 'render_dev_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'dev_scope_id',
			__( 'Developer-ID', 'freemius-dashboard' ),
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
				'description' => __( 'Aus „Mein Profil → Keys“ – muss zur Developer-ID gehören, nicht zu einem Produkt-Key.', 'freemius-dashboard' ),
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

		// 2) Produkt-Keys – für Dashboard, Käufe und die Affiliates-Liste.
		add_settings_section(
			'fsd_product_api_section',
			__( '2. Produkt-Keys (für Dashboard, Käufe & Affiliates-Liste)', 'freemius-dashboard' ),
			array( $this, 'render_product_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'product_id',
			__( 'Produkt-ID', 'freemius-dashboard' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'fsd_product_api_section',
			array(
				'key'         => 'product_id',
				'placeholder' => 'z. B. 5678',
				'description' => __( 'Das Produkt, dessen Daten angezeigt werden sollen. Dient bei Produkt-Keys zugleich als Scope-ID im Authorization-Header.', 'freemius-dashboard' ),
			)
		);

		add_settings_field(
			'public_key',
			__( 'Produkt Public Key', 'freemius-dashboard' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'fsd_product_api_section',
			array(
				'key'         => 'public_key',
				'placeholder' => 'pk_...',
				'description' => __( 'Aus den Produkt-Einstellungen → „Keys“. Muss zum selben Produkt gehören wie die Produkt-ID oben.', 'freemius-dashboard' ),
			)
		);

		add_settings_field(
			'secret_key',
			__( 'Produkt Secret Key', 'freemius-dashboard' ),
			array( $this, 'render_secret_field' ),
			self::PAGE_SLUG,
			'fsd_product_api_section',
			array( 'key' => 'secret_key' )
		);

		// 3) Affiliate-Programm – die Programm-ID (das Anlegen selbst nutzt die Developer-Keys).
		add_settings_section(
			'fsd_affiliate_section',
			__( '3. Affiliate-Programm', 'freemius-dashboard' ),
			array( $this, 'render_affiliate_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'affiliate_terms_id',
			__( 'Affiliate-Programm-ID', 'freemius-dashboard' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'fsd_affiliate_section',
			array(
				'key'         => 'affiliate_terms_id',
				'placeholder' => 'z. B. 42',
				'description' => __( 'Zu finden im Freemius Developer-Dashboard unter Produkt-Einstellungen → „AFFILIATION“ (im ersten Tab). Nötig für die Affiliates-Liste und das Anmeldeformular.', 'freemius-dashboard' ),
			)
		);
	}

	public static function defaults() {
		return array(
			'dev_scope_id'       => '',
			'dev_public_key'     => '',
			'dev_secret_key'     => '',
			'product_id'         => '',
			'public_key'         => '',
			'secret_key'         => '',
			'affiliate_terms_id' => '',
		);
	}

	public static function get_settings() {
		$settings = get_option( FSD_OPTION_KEY, self::defaults() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings = self::migrate( $settings );

		return wp_parse_args( $settings, self::defaults() );
	}

	/**
	 * Überführt Altbestände auf das neue Schema mit getrennten Developer- und
	 * Produkt-Keys.
	 *
	 * Frühere Versionen speicherten ein einziges Schlüsselpaar
	 * (scope_id/public_key/secret_key) plus einen Umschalter "api_scope":
	 * - api_scope = "product": scope_id war identisch mit der Produkt-ID, die
	 *   Keys sind Produkt-Keys und bleiben unverändert in der Produkt-Sektion.
	 * - api_scope = "developer": das Schlüsselpaar waren Developer-Keys. Diese
	 *   wandern in die Developer-Sektion; die Produkt-Keys müssen dann neu
	 *   eingetragen werden (Developer-Keys mit Produkt-Scope würde Freemius mit
	 *   "Invalid Authorization header" ablehnen).
	 */
	private static function migrate( $settings ) {
		// Noch ältere Migration (< 1.1.0): "developer_id" war der feste Name.
		if ( empty( $settings['scope_id'] ) && ! empty( $settings['developer_id'] ) ) {
			$settings['scope_id']  = $settings['developer_id'];
			$settings['api_scope'] = 'developer';
		}

		$has_legacy = isset( $settings['api_scope'] ) || isset( $settings['scope_id'] );

		if ( $has_legacy ) {
			$scope    = isset( $settings['api_scope'] ) ? $settings['api_scope'] : 'developer';
			$scope_id = isset( $settings['scope_id'] ) ? $settings['scope_id'] : '';

			if ( 'developer' === $scope && '' !== $scope_id ) {
				// Hauptschlüssel waren Developer-Keys -> in die Developer-Sektion.
				if ( empty( $settings['dev_scope_id'] ) ) {
					$settings['dev_scope_id'] = $scope_id;
				}
				if ( empty( $settings['dev_public_key'] ) && ! empty( $settings['public_key'] ) ) {
					$settings['dev_public_key'] = $settings['public_key'];
				}
				if ( empty( $settings['dev_secret_key'] ) && ! empty( $settings['secret_key'] ) ) {
					$settings['dev_secret_key'] = $settings['secret_key'];
				}
				// Produkt-Sektion hatte in diesem Modus keine echten Produkt-Keys.
				$settings['public_key'] = '';
				$settings['secret_key'] = '';
			}
			// Bei "product" ist scope_id == product_id und public/secret sind
			// bereits Produkt-Keys – nichts zu tun.

			unset( $settings['api_scope'], $settings['scope_id'], $settings['developer_id'] );
		}

		return $settings;
	}

	public function sanitize( $input ) {
		$existing = self::get_settings();
		$output   = self::defaults();

		$output['dev_scope_id']   = isset( $input['dev_scope_id'] ) ? sanitize_text_field( $input['dev_scope_id'] ) : '';
		$output['dev_public_key'] = isset( $input['dev_public_key'] ) ? sanitize_text_field( $input['dev_public_key'] ) : '';
		$output['product_id']     = isset( $input['product_id'] ) ? sanitize_text_field( $input['product_id'] ) : '';
		$output['public_key']     = isset( $input['public_key'] ) ? sanitize_text_field( $input['public_key'] ) : '';
		$output['affiliate_terms_id'] = isset( $input['affiliate_terms_id'] ) ? sanitize_text_field( $input['affiliate_terms_id'] ) : '';

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

	public function render_dev_section_intro() {
		?>
		<p>
			<?php esc_html_e( 'Das Anlegen von Affiliates über die Freemius-API erfordert zwingend Developer-Keys („Mein Profil → Keys“ – oben rechts im Freemius-Dashboard, NICHT in den Produkt-Einstellungen). Produkt-Keys werden dafür von Freemius mit „AccessForbidden“ abgelehnt. Diese drei Felder werden ausschließlich für das [fsd_affiliate_signup]-Formular verwendet.', 'freemius-dashboard' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Häufigster Fehler („Invalid Authorization header“): hier wird versehentlich die Produkt-ID statt der Developer-ID eingetragen, oder Public/Secret Key stammen aus unterschiedlichen Schlüsselpaaren. Alle drei Felder müssen aus derselben Seite „Mein Profil → Keys“ kopiert werden. Der Secret Key wird lokal in der WordPress-Datenbank gespeichert – beschränke den Zugriff auf vertrauenswürdige Administratoren.', 'freemius-dashboard' ); ?>
		</p>
		<?php
	}

	public function render_product_section_intro() {
		echo '<p>' . esc_html__( 'Produkt-Keys findest du in den Einstellungen des jeweiligen Produkts unter „Keys“ (Zugriff nur auf dieses eine Produkt). Sie werden für das Dashboard, die Käufe-Übersicht und die Affiliates-Liste verwendet. Bei Produkt-Keys ist die Scope-ID immer identisch mit der Produkt-ID – daher genügt hier die Produkt-ID.', 'freemius-dashboard' ) . '</p>';
	}

	public function render_affiliate_section_intro() {
		echo '<p>' . esc_html__( 'Nur nötig, wenn du die Affiliates-Liste oder das [fsd_affiliate_signup]-Formular nutzen willst. Das Anlegen neuer Affiliates verwendet die Developer-Keys aus Abschnitt 1, gelesen wird die Liste mit den Produkt-Keys aus Abschnitt 2.', 'freemius-dashboard' ) . '</p>';
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
			id="<?php echo esc_attr( 'fsd-field-' . $key ); ?>"
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

	public function render_dev_scope_id_field() {
		$settings   = self::get_settings();
		$dev_id     = $settings['dev_scope_id'];
		$product_id = $settings['product_id'];
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
			<?php esc_html_e( 'Zu finden unter „Mein Profil → Keys“ – eine kleine, produktunabhängige Zahl, verschieden von der Produkt-ID und der Affiliate-Programm-ID.', 'freemius-dashboard' ); ?>
		</p>
		<?php if ( '' !== $dev_id && '' !== $product_id && $dev_id === $product_id ) : ?>
			<p class="fsd-notice fsd-notice--error">
				<?php esc_html_e( 'Warnung: Diese ID ist identisch mit der Produkt-ID aus Abschnitt 2. Das ist bei einer Developer-ID unwahrscheinlich – bitte im Freemius-Dashboard unter „Mein Profil → Keys“ prüfen.', 'freemius-dashboard' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}
}
