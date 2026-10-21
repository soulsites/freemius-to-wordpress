<?php
/**
 * Registriert die Brevo-Aktion für das Elementor-Pro-Formular-Widget
 * ("Aktionen nach dem Absenden"). Ohne Elementor Pro passiert nichts –
 * die Aktionsklasse wird erst innerhalb des Elementor-Hooks geladen, weil sie
 * von \ElementorPro\Modules\Forms\Classes\Action_Base erbt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Elementor_Integration {

	/** @var FSD_Brevo */
	private $brevo;

	public function __construct( FSD_Brevo $brevo ) {
		$this->brevo = $brevo;
	}

	public function register() {
		add_action( 'elementor_pro/forms/actions/register', array( $this, 'register_form_action' ) );
	}

	public static function is_available() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	public function register_form_action( $registrar ) {
		require_once FSD_PLUGIN_DIR . 'includes/class-fsd-elementor-brevo-action.php';
		$registrar->register( new FSD_Elementor_Brevo_Action( $this->brevo ) );
	}
}
