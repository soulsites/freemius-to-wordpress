<?php
/**
 * Plugin-Bootstrap: Menüs, Assets, AJAX-Verbindungstest.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Plugin {

	private static $instance = null;

	/** @var FSD_Settings */
	private $settings;

	/** @var FSD_Email_Settings */
	private $email_settings;

	/** @var FSD_Webhook */
	private $webhook;

	/** @var FSD_Dashboard */
	private $dashboard;

	/** @var FSD_Affiliates */
	private $affiliates;

	/** @var FSD_Affiliate_Signup */
	private $affiliate_signup;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->settings         = new FSD_Settings();
		$this->email_settings   = new FSD_Email_Settings();
		$this->webhook          = new FSD_Webhook();
		$this->affiliate_signup = new FSD_Affiliate_Signup();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_init', array( $this->email_settings, 'register' ) );
		add_action( 'init', array( $this->affiliate_signup, 'register' ) );
		add_action( 'rest_api_init', array( $this->webhook, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_fsd_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	public function register_menu() {
		$capability = 'manage_options';

		add_menu_page(
			__( 'Freemius Dashboard', 'freemius-dashboard' ),
			__( 'Freemius', 'freemius-dashboard' ),
			$capability,
			FSD_Dashboard::PAGE_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			58
		);

		add_submenu_page(
			FSD_Dashboard::PAGE_SLUG,
			__( 'Dashboard', 'freemius-dashboard' ),
			__( 'Dashboard', 'freemius-dashboard' ),
			$capability,
			FSD_Dashboard::PAGE_SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			FSD_Dashboard::PAGE_SLUG,
			__( 'Einstellungen', 'freemius-dashboard' ),
			__( 'Einstellungen', 'freemius-dashboard' ),
			$capability,
			FSD_Settings::PAGE_SLUG,
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			FSD_Dashboard::PAGE_SLUG,
			__( 'Affiliates', 'freemius-dashboard' ),
			__( 'Affiliates', 'freemius-dashboard' ),
			$capability,
			FSD_Affiliates::PAGE_SLUG,
			array( $this, 'render_affiliates' )
		);

		add_submenu_page(
			FSD_Dashboard::PAGE_SLUG,
			__( 'E-Mails', 'freemius-dashboard' ),
			__( 'E-Mails', 'freemius-dashboard' ),
			$capability,
			FSD_Email_Settings::PAGE_SLUG,
			array( $this, 'render_emails' )
		);
	}

	public function render_dashboard() {
		if ( null === $this->dashboard ) {
			$this->dashboard = new FSD_Dashboard();
		}
		$this->dashboard->render();
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap fsd-wrap">
			<h1 class="fsd-title"><?php esc_html_e( 'Freemius – Einstellungen', 'freemius-dashboard' ); ?></h1>
			<div class="fsd-card">
				<form method="post" action="options.php">
					<?php
					settings_fields( FSD_Settings::GROUP );
					do_settings_sections( FSD_Settings::PAGE_SLUG );
					submit_button( __( 'Speichern', 'freemius-dashboard' ), 'fsd-btn fsd-btn--filled' );
					?>
				</form>
				<p>
					<button type="button" class="fsd-btn fsd-btn--tonal" id="fsd-test-connection">
						<?php esc_html_e( 'Verbindung testen', 'freemius-dashboard' ); ?>
					</button>
					<span id="fsd-test-connection-result" class="fsd-test-result"></span>
				</p>
			</div>
		</div>
		<?php
	}

	public function render_affiliates() {
		if ( null === $this->affiliates ) {
			$this->affiliates = new FSD_Affiliates();
		}
		$this->affiliates->render();
	}

	public function render_emails() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap fsd-wrap">
			<h1 class="fsd-title"><?php esc_html_e( 'Freemius – E-Mails', 'freemius-dashboard' ); ?></h1>
			<div class="fsd-card">
				<form method="post" action="options.php">
					<?php
					settings_fields( FSD_Email_Settings::GROUP );
					do_settings_sections( FSD_Email_Settings::PAGE_SLUG );
					submit_button( __( 'Speichern', 'freemius-dashboard' ), 'fsd-btn fsd-btn--filled' );
					?>
				</form>
			</div>
		</div>
		<?php
	}

	public function enqueue_assets( $hook ) {
		$dashboard_hook  = 'toplevel_page_' . FSD_Dashboard::PAGE_SLUG;
		$settings_hook   = FSD_Dashboard::PAGE_SLUG . '_page_' . FSD_Settings::PAGE_SLUG;
		$affiliates_hook = FSD_Dashboard::PAGE_SLUG . '_page_' . FSD_Affiliates::PAGE_SLUG;
		$emails_hook     = FSD_Dashboard::PAGE_SLUG . '_page_' . FSD_Email_Settings::PAGE_SLUG;

		if ( ! in_array( $hook, array( $dashboard_hook, $settings_hook, $affiliates_hook, $emails_hook ), true ) ) {
			return;
		}

		wp_enqueue_style( 'fsd-admin', FSD_PLUGIN_URL . 'assets/css/fsd-admin.css', array(), FSD_VERSION );

		if ( $dashboard_hook === $hook ) {
			wp_enqueue_script( 'fsd-chart', FSD_PLUGIN_URL . 'assets/js/fsd-chart.js', array(), FSD_VERSION, true );
		}

		if ( $settings_hook === $hook ) {
			wp_enqueue_script( 'fsd-settings', FSD_PLUGIN_URL . 'assets/js/fsd-settings.js', array(), FSD_VERSION, true );
			wp_localize_script(
				'fsd-settings',
				'fsdSettings',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'fsd_test_connection' ),
					'i18n'    => array(
						'testing' => __( 'Verbindung wird geprüft …', 'freemius-dashboard' ),
						'error'   => __( 'Fehler: ', 'freemius-dashboard' ),
					),
				)
			);
		}
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'fsd_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'freemius-dashboard' ) ), 403 );
		}

		$settings = FSD_Settings::get_settings();
		$api      = new FSD_Api( $settings['product_id'], $settings['public_key'], $settings['secret_key'], $settings['product_id'] );

		if ( ! $api->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Bitte alle Felder ausfüllen und speichern.', 'freemius-dashboard' ) ) );
		}

		$product = $api->get_product();

		if ( is_wp_error( $product ) ) {
			wp_send_json_error( array( 'message' => $product->get_error_message() ) );
		}

		$title = isset( $product->title ) ? $product->title : ( isset( $product->slug ) ? $product->slug : $settings['product_id'] );

		wp_send_json_success(
			array(
				/* translators: %s: product title */
				'message' => sprintf( __( 'Verbindung erfolgreich: „%s“', 'freemius-dashboard' ), $title ),
			)
		);
	}
}
