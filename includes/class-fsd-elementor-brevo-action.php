<?php
/**
 * Elementor-Pro-Formularaktion "Brevo": trägt Absender als Kontakt in Brevo
 * ein – wahlweise direkt oder per Brevo-Double-Opt-in. Nutzt den API-Key aus
 * "Freemius → Brevo". Wird nur über FSD_Elementor_Integration geladen.
 */

use Elementor\Controls_Manager;
use ElementorPro\Modules\Forms\Classes\Action_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Elementor_Brevo_Action extends Action_Base {
	const NAME        = 'fsd_brevo';
	const CACHE_TTL   = HOUR_IN_SECONDS;
	const LOG_EVENT   = 'elementor_form';
	const CACHE_LISTS = 'fsd_brevo_el_lists';
	const CACHE_TPL   = 'fsd_brevo_el_templates';

	/** @var FSD_Brevo */
	private $brevo;

	public function __construct( FSD_Brevo $brevo ) {
		$this->brevo = $brevo;
	}

	public function get_name() {
		return self::NAME;
	}

	public function get_label() {
		return 'Brevo';
	}

	/* --------------------------------------------------------------------
	 * Editor-Einstellungen
	 * ------------------------------------------------------------------ */

	public function register_settings_section( $widget ) {
		$lists     = $this->cached_options( self::CACHE_LISTS, 'get_lists' );
		$templates = $this->cached_options( self::CACHE_TPL, 'get_templates' );
		$doi       = array( 'fsd_brevo_double_optin' => 'yes' );

		$widget->start_controls_section(
			'section_fsd_brevo',
			array(
				'label'     => 'Brevo',
				'condition' => array( 'submit_actions' => $this->get_name() ),
			)
		);

		if ( ! $this->brevo->api()->is_configured() ) {
			$widget->add_control(
				'fsd_brevo_notice',
				array(
					'type'            => Controls_Manager::RAW_HTML,
					'raw'             => 'Bitte zuerst unter <b>Freemius → Brevo</b> den API-Key hinterlegen.',
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
				)
			);
		}

		$widget->add_control(
			'fsd_brevo_list_ids',
			$lists
				? array( 'label' => 'Liste(n) mit Marketing', 'type' => Controls_Manager::SELECT2, 'multiple' => true, 'label_block' => true, 'options' => $lists )
				: array( 'label' => 'Listen-IDs mit Marketing', 'type' => Controls_Manager::TEXT, 'placeholder' => 'z. B. 3, 7', 'description' => 'Kommagetrennt.' )
		);

		$widget->add_control(
			'fsd_brevo_non_marketing_list_ids',
			$lists
				? array( 'label' => 'Liste(n) ohne Marketing', 'type' => Controls_Manager::SELECT2, 'multiple' => true, 'label_block' => true, 'options' => $lists )
				: array( 'label' => 'Listen-IDs ohne Marketing', 'type' => Controls_Manager::TEXT, 'placeholder' => 'z. B. 9', 'description' => 'Kommagetrennt.' )
		);

		$widget->add_control(
			'fsd_brevo_double_optin',
			array(
				'label'        => 'Double Opt-in',
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
				'description'  => 'Brevo verschickt eine Bestätigungsmail. Der Kontakt landet erst nach Klick auf den Link in den Listen.',
			)
		);

		$widget->add_control(
			'fsd_brevo_doi_template',
			$templates
				? array( 'label' => 'DOI-Template', 'type' => Controls_Manager::SELECT, 'label_block' => true, 'options' => array( '' => '– auswählen –' ) + $templates, 'condition' => $doi )
				: array( 'label' => 'DOI-Template-ID', 'type' => Controls_Manager::NUMBER, 'condition' => $doi )
		);

		$widget->add_control(
			'fsd_brevo_doi_redirect',
			array(
				'label'       => 'Weiterleitung nach Bestätigung',
				'type'        => Controls_Manager::TEXT,
				'input_type'  => 'url',
				'label_block' => true,
				'placeholder' => home_url( '/' ),
				'condition'   => $doi,
			)
		);

		$widget->add_control(
			'fsd_brevo_email_field',
			array(
				'label'       => 'Feld-ID E-Mail',
				'type'        => Controls_Manager::TEXT,
				'default'     => 'email',
				'separator'   => 'before',
				'description' => 'Die "ID" des Formularfelds (Feld → Erweitert).',
			)
		);

		$widget->add_control( 'fsd_brevo_firstname_field', array( 'label' => 'Feld-ID Vorname', 'type' => Controls_Manager::TEXT ) );
		$widget->add_control( 'fsd_brevo_lastname_field', array( 'label' => 'Feld-ID Nachname', 'type' => Controls_Manager::TEXT ) );

		$widget->add_control(
			'fsd_brevo_consent_field',
			array(
				'label'       => 'Feld-ID Einwilligung (optional)',
				'type'        => Controls_Manager::TEXT,
				'description' => 'z. B. ein Akzeptanz-Feld. Angehakt wählt die Marketing-Liste, nicht angehakt die Liste ohne Marketing.',
			)
		);

		$widget->add_control(
			'fsd_brevo_marketing_mails',
			array(
				'label'        => 'Ohne Einwilligungsfeld: Marketing',
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => 'Wird nur verwendet, wenn keine Feld-ID für die Einwilligung gesetzt ist.',
			)
		);

		$widget->add_control(
			'fsd_brevo_attributes',
			array(
				'label'       => 'Weitere Attribute',
				'type'        => Controls_Manager::TEXTAREA,
				'placeholder' => "FIRMA=company\nRESELLER=\"Website\"",
				'description' => 'Eine Zeile je Attribut: <code>ATTRIBUT=feld_id</code> oder fester Wert <code>ATTRIBUT="Wert"</code>.',
			)
		);

		$widget->end_controls_section();
	}

	/** Brevo-Listen/-Templates für die Editor-Auswahl, eine Stunde gecacht. */
	private function cached_options( $cache_key, $method ) {
		if ( ! $this->brevo->api()->is_configured() ) {
			return array();
		}
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$options = $this->brevo->api()->$method();
		$options = is_wp_error( $options ) ? array() : $options;
		set_transient( $cache_key, $options, is_array( $options ) && $options ? self::CACHE_TTL : 5 * MINUTE_IN_SECONDS );
		return $options;
	}

	public function on_export( $element ) {
		foreach ( array( 'fsd_brevo_list_ids', 'fsd_brevo_non_marketing_list_ids', 'fsd_brevo_doi_template' ) as $key ) {
			unset( $element['settings'][ $key ] );
		}
		return $element;
	}

	/* --------------------------------------------------------------------
	 * Absenden
	 * ------------------------------------------------------------------ */

	public function run( $record, $ajax_handler ) {
		$settings  = $record->get( 'form_settings' );
		$fields    = $this->field_values( $record->get( 'fields' ) );
		$form_name = ! empty( $settings['form_name'] ) ? $settings['form_name'] : 'Formular';
		$email     = strtolower( trim( $this->field( $fields, $settings['fsd_brevo_email_field'] ?? 'email' ) ) );

		if ( ! is_email( $email ) ) {
			$this->fail( $ajax_handler, '', 'Keine gültige E-Mail-Adresse im Feld "' . ( $settings['fsd_brevo_email_field'] ?? '' ) . '".', $form_name );
			return;
		}

		$consent_field = trim( (string) ( $settings['fsd_brevo_consent_field'] ?? '' ) );
		$has_marketing = '' !== $consent_field
			? '' !== $this->field( $fields, $consent_field )
			: 'yes' === ( $settings['fsd_brevo_marketing_mails'] ?? '' );
		$settings['_fsd_resolved_marketing'] = $has_marketing ? 'yes' : 'no';

		list( $attributes, $skipped ) = $this->brevo->prepare_attributes( $this->attribute_values( $settings, $fields ) );

		$marketing_ids     = FSD_Brevo::parse_list_ids( implode( ',', (array) ( $settings['fsd_brevo_list_ids'] ?? array() ) ) );
		$non_marketing_ids = FSD_Brevo::parse_list_ids( implode( ',', (array) ( $settings['fsd_brevo_non_marketing_list_ids'] ?? array() ) ) );
		$list_ids          = $has_marketing ? $marketing_ids : $non_marketing_ids;
		$unlink_ids        = array_values( array_diff( $has_marketing ? $non_marketing_ids : $marketing_ids, $list_ids ) );
		$api               = $this->brevo->api();

		if ( $has_marketing && 'yes' === ( $settings['fsd_brevo_double_optin'] ?? '' ) ) {
			$template = absint( $settings['fsd_brevo_doi_template'] ?? 0 );
			$redirect = ! empty( $settings['fsd_brevo_doi_redirect'] ) ? esc_url_raw( $settings['fsd_brevo_doi_redirect'] ) : home_url( '/' );

			if ( ! $template || ! $list_ids ) {
				$this->fail( $ajax_handler, $email, 'Für Double Opt-in müssen DOI-Template und mindestens eine Liste gesetzt sein.', $form_name );
				return;
			}
			$result  = $api->double_optin( $email, $attributes, $list_ids, $template, $redirect, $unlink_ids );
			$message = $form_name . ': Double-Opt-in-Mail versendet';
		} else {
			$result  = $api->upsert_contact( $email, $attributes, $list_ids );
			if ( ! is_wp_error( $result ) && ( $list_ids || $unlink_ids ) ) {
				$result = $api->update_contact_lists( $email, $list_ids, $unlink_ids );
			}
			$message = $form_name . ': Kontakt eingetragen (' . ( $has_marketing ? 'mit Marketing' : 'ohne Marketing' ) . ')';
		}

		if ( is_wp_error( $result ) ) {
			$this->fail( $ajax_handler, $email, $result->get_error_message(), $form_name );
			return;
		}
		$this->brevo->log_result( $skipped, $email, self::LOG_EVENT, $message );
	}

	/**
	 * Attributwerte aus Feld-Zuordnung und "Weitere Attribute".
	 */
	private function attribute_values( $settings, $fields ) {
		$values = array(
			'FIRSTNAME' => $this->field( $fields, $settings['fsd_brevo_firstname_field'] ?? '' ),
			'LASTNAME'  => $this->field( $fields, $settings['fsd_brevo_lastname_field'] ?? '' ),
		);
		$values['MARKETING_MAILS'] = 'yes' === ( $settings['_fsd_resolved_marketing'] ?? '' ) ? 'YES' : 'NO';

		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $settings['fsd_brevo_attributes'] ?? '' ) ) as $line ) {
			if ( ! preg_match( '/^\s*([A-Za-z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $match ) ) {
				continue;
			}
			$name  = strtoupper( $match[1] );
			$value = $match[2];
			if ( preg_match( '/^"(.*)"$/', $value, $fixed ) ) {
				$values[ $name ] = sanitize_text_field( $fixed[1] );
			} else {
				$values[ $name ] = $this->field( $fields, $value );
			}
		}
		return $values;
	}

	/** @return array Feld-ID => Wert (Mehrfachwerte kommagetrennt). */
	private function field_values( $fields ) {
		$values = array();
		foreach ( (array) $fields as $id => $field ) {
			$value           = $field['value'] ?? '';
			$values[ $id ]   = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return $values;
	}

	private function field( $fields, $id ) {
		$id = trim( (string) $id );
		return '' !== $id && isset( $fields[ $id ] ) ? sanitize_text_field( $fields[ $id ] ) : '';
	}

	/** Protokolliert den Fehler und zeigt ihn nur Administratoren im Formular an – das Absenden selbst bleibt erfolgreich. */
	private function fail( $ajax_handler, $email, $message, $form_name ) {
		$this->brevo->log_result( new WP_Error( 'fsd_brevo_form', $form_name . ': ' . $message ), $email, self::LOG_EVENT, '' );
		$ajax_handler->add_admin_error_message( 'Brevo: ' . $message );
	}
}
