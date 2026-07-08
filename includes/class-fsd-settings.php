<?php
/**
 * Einstellungsseite: Freemius API-Zugangsdaten (Developer ID, Public Key, Secret Key, Produkt-ID).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Settings {

	const PAGE_SLUG  = 'fsd-settings';
	const GROUP      = 'fsd_settings_group';

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
			'developer_id',
			__( 'Developer ID', 'freemius-dashboard' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'fsd_api_section',
			array( 'key' => 'developer_id', 'placeholder' => 'z. B. 1234' )
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
			__( 'Produkt-ID (Plugin-ID)', 'freemius-dashboard' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'fsd_api_section',
			array( 'key' => 'product_id', 'placeholder' => 'z. B. 5678' )
		);
	}

	public static function defaults() {
		return array(
			'developer_id' => '',
			'public_key'   => '',
			'secret_key'   => '',
			'product_id'   => '',
		);
	}

	public static function get_settings() {
		$settings = get_option( FSD_OPTION_KEY, self::defaults() );

		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::defaults() );
	}

	public function sanitize( $input ) {
		$existing = self::get_settings();
		$output   = self::defaults();

		$output['developer_id'] = isset( $input['developer_id'] ) ? sanitize_text_field( $input['developer_id'] ) : '';
		$output['public_key']   = isset( $input['public_key'] ) ? sanitize_text_field( $input['public_key'] ) : '';
		$output['product_id']   = isset( $input['product_id'] ) ? sanitize_text_field( $input['product_id'] ) : '';

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
		echo '<p>' . esc_html__( 'Diese Zugangsdaten findest du im Freemius Developer Dashboard unter „Account → Keys“. Der Secret Key wird lokal in der WordPress-Datenbank gespeichert – beschränke den Zugriff auf vertrauenswürdige Administratoren.', 'freemius-dashboard' ) . '</p>';
	}

	public function render_text_field( $args ) {
		$settings = self::get_settings();
		$key      = $args['key'];
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		?>
		<input
			type="text"
			class="regular-text fsd-input"
			name="<?php echo esc_attr( FSD_OPTION_KEY . '[' . $key . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
			autocomplete="off"
		/>
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
