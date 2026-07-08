<?php
/**
 * Schlanker Freemius-API-Client auf Basis der WordPress HTTP API.
 * Implementiert die Freemius Request-Signatur (HMAC-SHA256) gemäß der offiziellen
 * Freemius SDK-Referenzimplementierung.
 *
 * Die "Scope-ID" ist je nach Art der Keys entweder die Developer-ID
 * (Developer-Keys) oder die Produkt-ID (Produkt-Keys) – siehe FSD_Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Api {

	const API_BASE  = 'https://api.freemius.com';
	const PAGE_SIZE = 50;
	const MAX_PAGES = 20;

	/** @var string */
	private $scope_id;

	/** @var string */
	private $public_key;

	/** @var string */
	private $secret_key;

	/** @var string */
	private $product_id;

	public function __construct( $scope_id, $public_key, $secret_key, $product_id ) {
		$this->scope_id    = trim( (string) $scope_id );
		$this->public_key  = trim( (string) $public_key );
		$this->secret_key  = trim( (string) $secret_key );
		$this->product_id  = trim( (string) $product_id );
	}

	public function is_configured() {
		return '' !== $this->scope_id
			&& '' !== $this->public_key
			&& '' !== $this->secret_key
			&& '' !== $this->product_id;
	}

	private static function base64_url_encode( $input ) {
		return str_replace( '=', '', strtr( base64_encode( $input ), '+/', '-_' ) );
	}

	/**
	 * Baut die Auth-Header exakt nach dem Freemius-Signaturschema:
	 * string_to_sign = METHOD \n Content-MD5 \n Content-Type \n Date \n Resource-Pfad
	 * signature      = base64url( hash_hmac( 'sha256', string_to_sign, secret_key ) )
	 */
	private function build_auth_headers( $path, $method, $body_json ) {
		$method       = strtoupper( $method );
		$content_type = '';
		$content_md5  = '';

		if ( in_array( $method, array( 'POST', 'PUT' ), true ) ) {
			$content_type = 'application/json';
			if ( ! empty( $body_json ) ) {
				$content_md5 = md5( $body_json );
			}
		}

		$date = gmdate( 'r' );

		$string_to_sign = implode(
			"\n",
			array( $method, $content_md5, $content_type, $date, $path )
		);

		$signature = self::base64_url_encode( hash_hmac( 'sha256', $string_to_sign, $this->secret_key ) );

		$headers = array(
			'Date'          => $date,
			'Authorization' => 'FS ' . $this->scope_id . ':' . $this->public_key . ':' . $signature,
		);

		if ( '' !== $content_type ) {
			$headers['Content-Type'] = $content_type;
		}
		if ( '' !== $content_md5 ) {
			$headers['Content-MD5'] = $content_md5;
		}

		return $headers;
	}

	/**
	 * @return array|WP_Error Dekodierte JSON-Antwort oder WP_Error.
	 */
	private function request( $path, $method = 'GET', $query = array(), $body = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'fsd_not_configured', __( 'Freemius-API-Zugangsdaten sind nicht vollständig hinterlegt.', 'freemius-dashboard' ) );
		}

		$method    = strtoupper( $method );
		$body_json = ! empty( $body ) ? wp_json_encode( $body ) : '';

		$headers = $this->build_auth_headers( $path, $method, $body_json );

		$url = self::API_BASE . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 20,
		);
		if ( '' !== $body_json ) {
			$args['body'] = $body_json;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code          = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body );

		if ( $code < 200 || $code >= 300 ) {
			$message = ( isset( $data->error->message ) && '' !== $data->error->message )
				? $data->error->message
				: sprintf( /* translators: %d: HTTP status code */ __( 'Freemius-API-Anfrage fehlgeschlagen (HTTP %d).', 'freemius-dashboard' ), $code );

			return new WP_Error( 'fsd_api_error', $message, array( 'status' => $code ) );
		}

		return $data;
	}

	/**
	 * Ruft die Produktdaten ab – dient primär als Verbindungstest.
	 *
	 * @return array|WP_Error
	 */
	public function get_product() {
		$path = sprintf( '/v1/products/%d.json', (int) $this->product_id );

		return $this->request( $path, 'GET' );
	}

	/**
	 * Lädt alle Zahlungen (Käufe/Verlängerungen/Erstattungen) in einem Zeitraum.
	 *
	 * @param DateTimeInterface $from Beginn (inklusive).
	 * @param DateTimeInterface $to   Ende (exklusiv).
	 *
	 * @return array|WP_Error Liste von Payment-Objekten oder WP_Error.
	 */
	public function get_payments( DateTimeInterface $from, DateTimeInterface $to ) {
		$path = sprintf( '/v1/products/%d/payments.json', (int) $this->product_id );

		$all    = array();
		$offset = 0;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = array(
				'from'     => $from->format( 'Y-m-d H:i:s' ),
				'to'       => $to->format( 'Y-m-d H:i:s' ),
				'extended' => 'true',
				'count'    => self::PAGE_SIZE,
				'offset'   => $offset,
			);

			$result = $this->request( $path, 'GET', $query );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$chunk = ( isset( $result->payments ) && is_array( $result->payments ) ) ? $result->payments : array();

			if ( empty( $chunk ) ) {
				break;
			}

			$all = array_merge( $all, $chunk );

			if ( count( $chunk ) < self::PAGE_SIZE ) {
				break;
			}

			$offset += self::PAGE_SIZE;
		}

		return $all;
	}

	/**
	 * Lädt alle Provisionsbedingungen ("Affiliate-Programme") des Produkts. Jedes Produkt
	 * kann mehrere solcher Terms haben (z. B. Standard-Programm + individuell erstellte);
	 * Affiliates hängen jeweils unter einer bestimmten Terms-ID.
	 *
	 * @return array|WP_Error Liste von Terms-Objekten (jeweils u. a. 'id', 'commission') oder WP_Error.
	 */
	public function get_affiliate_terms() {
		$path = sprintf( '/v1/products/%d/aff.json', (int) $this->product_id );

		$result = $this->request( $path, 'GET' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_array( $result ) ) {
			return $result;
		}

		if ( isset( $result->terms ) && is_array( $result->terms ) ) {
			return $result->terms;
		}

		// Einzelnes Terms-Objekt statt Liste.
		return array( $result );
	}

	/**
	 * Lädt alle Affiliate-Partner des Produkts über alle übergebenen Provisionsbedingungen
	 * hinweg. Affiliates sind bei Freemius unter der jeweiligen Terms-ID verschachtelt
	 * ("/aff/{terms_id}/affiliates.json"), es gibt keinen produktweiten Sammel-Endpoint.
	 *
	 * @param array $terms Liste von Terms-Objekten mit jeweils einer 'id' (siehe get_affiliate_terms()).
	 *
	 * @return array|WP_Error Liste von Affiliate-Objekten oder WP_Error.
	 */
	public function get_affiliates( array $terms ) {
		$all  = array();
		$seen = array();

		foreach ( $terms as $term ) {
			if ( ! isset( $term->id ) ) {
				continue;
			}

			$path = sprintf( '/v1/products/%d/aff/%d/affiliates.json', (int) $this->product_id, (int) $term->id );

			$result = $this->request( $path, 'GET', array( 'all' => 'true', 'extended' => 'true' ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$chunk = isset( $result->affiliates ) && is_array( $result->affiliates ) ? $result->affiliates : ( is_array( $result ) ? $result : array() );

			foreach ( $chunk as $affiliate ) {
				$affiliate_id = isset( $affiliate->id ) ? (int) $affiliate->id : null;

				if ( null !== $affiliate_id ) {
					if ( isset( $seen[ $affiliate_id ] ) ) {
						continue;
					}
					$seen[ $affiliate_id ] = true;
				}

				$all[] = $affiliate;
			}
		}

		return $all;
	}
}
