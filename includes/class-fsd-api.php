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

	/** @var string 'product' (Produkt-Keys) oder 'developer' (Developer-Keys). */
	private $scope;

	/**
	 * @param string $scope 'product' für Produkt-Keys (Scope-ID = Produkt-ID) oder
	 *                       'developer' für Developer-Keys (Scope-ID = Developer-ID).
	 *                       Bestimmt, wie Freemius den Ressourcen-Pfad erwartet.
	 */
	public function __construct( $scope_id, $public_key, $secret_key, $product_id, $scope = 'product' ) {
		$this->scope_id    = trim( (string) $scope_id );
		$this->public_key  = trim( (string) $public_key );
		$this->secret_key  = trim( (string) $secret_key );
		$this->product_id  = trim( (string) $product_id );
		$this->scope       = ( 'developer' === $scope ) ? 'developer' : 'product';
	}

	/**
	 * Baut einen Ressourcen-Pfad unterhalb des aktiven Scopes. Freemius adressiert
	 * dasselbe Produkt je nach Scope unterschiedlich:
	 *   Produkt-Keys:   /v1/products/{product_id}/{suffix}
	 *   Developer-Keys: /v1/developers/{dev_id}/plugins/{product_id}/{suffix}
	 * Unter dem Developer-Scope heißt die Produkt-Ebene "plugins" (nicht
	 * "products"), genau wie in der offiziellen Freemius-SDK-Referenz. Wird der
	 * Produkt-Pfad mit Developer-Keys signiert, antwortet Freemius mit
	 * "Invalid Authorization header"; wird "products" statt "plugins" verwendet,
	 * mit "Invalid request path".
	 *
	 * @param string $suffix Pfad unterhalb der Produkt-Ebene, z. B.
	 *                        "aff/42/affiliates.json". Leer für das Produkt selbst.
	 */
	private function product_path( $suffix = '' ) {
		$suffix = ltrim( (string) $suffix, '/' );

		if ( 'developer' === $this->scope ) {
			$base = sprintf( '/v1/developers/%d/plugins/%d', (int) $this->scope_id, (int) $this->product_id );
		} else {
			$base = sprintf( '/v1/products/%d', (int) $this->product_id );
		}

		return '' === $suffix ? $base . '.json' : $base . '/' . $suffix;
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

			// Pfad + Fehlercode mit ausgeben, damit sich API-Fehler (z. B. falsche/fehlende
			// Parameter bei undokumentierten Endpoints) ohne Netzwerk-Mitschnitt zuordnen lassen.
			$error_type = isset( $data->error->type ) ? (string) $data->error->type : '';
			$detail     = trim( $path . ( '' !== $error_type ? ' – ' . $error_type : '' ) );

			return new WP_Error(
				'fsd_api_error',
				sprintf( '%s (%s)', $message, $detail ),
				array( 'status' => $code )
			);
		}

		return $data;
	}

	/**
	 * Ruft die Produktdaten ab – dient primär als Verbindungstest.
	 *
	 * @return array|WP_Error
	 */
	public function get_product() {
		$path = $this->product_path();

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
		$path = $this->product_path( 'payments.json' );

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
	 * Lädt einen einzelnen Nutzer (für Käufe, bei denen die Payments-Antwort trotz
	 * extended=true kein eingebettetes user-Objekt enthält).
	 *
	 * @param int $user_id Freemius-Nutzer-ID.
	 *
	 * @return array|WP_Error
	 */
	public function get_user( $user_id ) {
		$path = $this->product_path( sprintf( 'users/%d.json', (int) $user_id ) );

		return $this->request( $path, 'GET' );
	}

	/**
	 * Lädt die Provisionsbedingungen ("Affiliate-Programm") des Produkts – u. a. den
	 * Standard-Provisionssatz, der gilt, wenn ein Affiliate keine individuelle
	 * Provision (custom_commission) hat.
	 *
	 * @param int $terms_id Affiliate-Programm-ID (Freemius Developer-Dashboard →
	 *                       Produkt-Einstellungen → „AFFILIATION").
	 *
	 * @return array|WP_Error
	 */
	public function get_affiliate_term( $terms_id ) {
		$path = $this->product_path( sprintf( 'aff/%d.json', (int) $terms_id ) );

		return $this->request( $path, 'GET' );
	}

	/**
	 * Lädt alle Affiliate-Partner, die unter der angegebenen Provisionsbedingung
	 * (Affiliate-Programm-ID) registriert sind.
	 *
	 * @param int $terms_id Affiliate-Programm-ID (siehe get_affiliate_term()).
	 *
	 * @return array|WP_Error Liste von Affiliate-Objekten oder WP_Error.
	 */
	public function get_affiliates( $terms_id ) {
		$path = $this->product_path( sprintf( 'aff/%d/affiliates.json', (int) $terms_id ) );

		$result = $this->request( $path, 'GET', array( 'all' => 'true', 'extended' => 'true' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result->affiliates ) && is_array( $result->affiliates ) ) {
			return $result->affiliates;
		}

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Reicht eine neue Affiliate-Bewerbung ein (z. B. über das öffentliche
	 * Anmeldeformular). Der Status wird standardmäßig auf "pending" gesetzt,
	 * damit die Bewerbung im Freemius-Dashboard manuell geprüft/freigegeben
	 * werden kann, statt den Partner sofort zu aktivieren.
	 *
	 * @param int   $terms_id Affiliate-Programm-ID (siehe get_affiliate_term()).
	 * @param array $args {
	 *     @type string $name                        Vollständiger Name.
	 *     @type string $email                        E-Mail-Adresse.
	 *     @type string $paypal_email                 PayPal-E-Mail (optional).
	 *     @type string $domain                        Haupt-Website/Domain (optional).
	 *     @type string $promotion_method_description Wie das Produkt beworben werden soll (optional).
	 *     @type string $state                         Freemius-Status (Default: "pending").
	 * }
	 *
	 * @return array|WP_Error Das erstellte Affiliate-Objekt oder WP_Error.
	 */
	public function create_affiliate( $terms_id, $args ) {
		$path = $this->product_path( sprintf( 'aff/%d/affiliates.json', (int) $terms_id ) );

		$body = array(
			'name'  => $args['name'],
			'email' => $args['email'],
			'state' => ! empty( $args['state'] ) ? $args['state'] : 'pending',
		);

		if ( ! empty( $args['paypal_email'] ) ) {
			$body['paypal_email'] = $args['paypal_email'];
		}

		// Freemius erwartet die Domain ohne HTTP/S-Protokoll und ohne Pfad,
		// z. B. "example.com" – nicht "https://example.com/".
		if ( ! empty( $args['domain'] ) ) {
			$domain = preg_replace( '~^[a-z][a-z0-9+.-]*://~i', '', (string) $args['domain'] );
			$domain = preg_replace( '~[/?#].*$~', '', $domain );
			$domain = trim( $domain );

			if ( '' !== $domain ) {
				$body['additional_domains'] = array( $domain );
			}
		}

		if ( ! empty( $args['promotion_method_description'] ) ) {
			$body['promotion_method_description'] = $args['promotion_method_description'];
		}

		return $this->request( $path, 'POST', array(), $body );
	}
}
