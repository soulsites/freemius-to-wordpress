<?php
/** Brevo API v3 client (https://developers.brevo.com/reference). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Brevo_Api {
	const API_BASE = 'https://api.brevo.com/v3/';

	private $api_key;

	public function __construct( $api_key ) {
		$this->api_key = trim( (string) $api_key );
	}

	public function is_configured() {
		return '' !== $this->api_key;
	}

	/**
	 * @return mixed|null|WP_Error Dekodierte Antwort, null bei leerer Antwort (z. B. 204).
	 */
	private function request( $method, $path, $body = null, $query = array(), $timeout = 20, $retry = true ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'fsd_brevo_not_configured', __( 'Brevo ist nicht vollständig konfiguriert.', 'freemius-dashboard' ) );
		}

		$url = self::API_BASE . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $timeout,
			'headers' => array(
				'api-key'      => $this->api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$attempts = $retry ? 3 : 1;
		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ) );
			if ( $code >= 200 && $code < 300 ) {
				return $data;
			}

			// 429 und vorübergehende Brevo-Serverfehler treten gelegentlich auf.
			// Solche schnellen HTTP-Antworten gefahrlos mit kurzem Backoff wiederholen.
			if ( $attempt < $attempts && ( 429 === $code || $code >= 500 ) ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				sleep( max( 1, min( 5, $retry_after ?: $attempt ) ) );
				continue;
			}

			$message = isset( $data->message ) ? (string) $data->message : sprintf( __( 'Brevo API-Fehler (HTTP %d).', 'freemius-dashboard' ), $code );
			return new WP_Error( 'fsd_brevo_api_error', $message, array( 'status' => $code ) );
		}
	}

	/** Prüft, ob ein Fehler bei einem späteren Versuch voraussichtlich behebbar ist. */
	public static function is_transient_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		return 0 === $status || 429 === $status || $status >= 500;
	}

	public function test() {
		return $this->request( 'GET', 'account' );
	}

	/**
	 * @return object|null|WP_Error Kontakt, null wenn nicht vorhanden.
	 */
	public function get_contact( $email, $timeout = 20 ) {
		$result = $this->request( 'GET', 'contacts/' . rawurlencode( $email ), null, array( 'identifierType' => 'email_id' ), $timeout );
		if ( is_wp_error( $result ) && 404 === (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
			return null;
		}
		return $result;
	}

	/**
	 * Legt einen Kontakt an oder aktualisiert ihn (updateEnabled).
	 */
	public function upsert_contact( $email, $attributes, $list_ids = array() ) {
		$body = array(
			'email'         => $email,
			'updateEnabled' => true,
		);
		if ( $attributes ) {
			$body['attributes'] = (object) $attributes;
		}
		if ( $list_ids ) {
			$body['listIds'] = array_values( array_map( 'intval', $list_ids ) );
		}
		$result = $this->request( 'POST', 'contacts', $body );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Ordnet einen bestehenden Kontakt Listen zu und entfernt ihn gleichzeitig
	 * aus den jeweils entgegengesetzten Listen.
	 */
	public function update_contact_lists( $email, $list_ids, $unlink_list_ids ) {
		$body = array();
		if ( $list_ids ) {
			$body['listIds'] = array_values( array_unique( array_map( 'intval', $list_ids ) ) );
		}
		if ( $unlink_list_ids ) {
			$body['unlinkListIds'] = array_values( array_unique( array_map( 'intval', $unlink_list_ids ) ) );
		}
		if ( ! $body ) {
			return true;
		}
		$result = $this->request( 'PUT', 'contacts/' . rawurlencode( $email ), $body, array( 'identifierType' => 'email_id' ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * @return array|WP_Error Attribut-Definitionen, indiziert nach Name (Großbuchstaben).
	 */
	public function get_attributes() {
		$result = $this->request( 'GET', 'contacts/attributes' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$attributes = array();
		foreach ( (array) ( $result->attributes ?? array() ) as $attribute ) {
			if ( isset( $attribute->name ) && 'normal' === ( $attribute->category ?? 'normal' ) ) {
				$attributes[ strtoupper( $attribute->name ) ] = $attribute;
			}
		}
		return $attributes;
	}

	/** @return array|WP_Error Listen-ID => Name. */
	public function get_lists() {
		$lists = array();
		for ( $offset = 0; $offset < 1000; $offset += 50 ) {
			$result = $this->request( 'GET', 'contacts/lists', null, array( 'limit' => 50, 'offset' => $offset ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( (array) ( $result->lists ?? array() ) as $list ) {
				$lists[ (int) $list->id ] = (string) $list->name;
			}
			if ( count( (array) ( $result->lists ?? array() ) ) < 50 ) {
				break;
			}
		}
		return $lists;
	}

	/**
	 * Aktive E-Mail-Templates. Double-Opt-in-Templates (Tag "optin") stehen vorne.
	 *
	 * @return array|WP_Error Template-ID => Name.
	 */
	public function get_templates() {
		$result = $this->request( 'GET', 'smtp/templates', null, array( 'templateStatus' => 'true', 'limit' => 1000 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$optin = array();
		$other = array();
		foreach ( (array) ( $result->templates ?? array() ) as $template ) {
			$is_optin = false !== stripos( (string) ( $template->tag ?? '' ), 'optin' );
			$label    = '#' . (int) $template->id . ' – ' . (string) $template->name . ( $is_optin ? ' (Double Opt-in)' : '' );
			if ( $is_optin ) {
				$optin[ (int) $template->id ] = $label;
			} else {
				$other[ (int) $template->id ] = $label;
			}
		}
		return $optin + $other;
	}

	/**
	 * Startet den Brevo-Double-Opt-in: Brevo verschickt die Bestätigungsmail
	 * (Template mit {{ doubleoptin }}-Link) und legt den Kontakt erst nach
	 * Klick auf den Link in den Listen an.
	 */
	public function double_optin( $email, $attributes, $list_ids, $template_id, $redirect_url ) {
		$body = array(
			'email'          => $email,
			'includeListIds' => array_values( array_map( 'intval', $list_ids ) ),
			'templateId'     => (int) $template_id,
			'redirectionUrl' => $redirect_url,
		);
		if ( $attributes ) {
			$body['attributes'] = (object) $attributes;
		}
		// Nicht automatisch wiederholen: Sonst könnte Brevo mehrere DOI-Mails senden.
		$result = $this->request( 'POST', 'contacts/doubleOptinConfirmation', $body, array(), 20, false );
		return is_wp_error( $result ) ? $result : true;
	}
}
