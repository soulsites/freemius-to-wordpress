<?php
/** ActiveCampaign API v3 client. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_ActiveCampaign_Api {
	private $url;
	private $token;

	public function __construct( $url, $token ) {
		$this->url   = untrailingslashit( trim( (string) $url ) );
		$this->token = trim( (string) $token );
	}

	public function is_configured() {
		return '' !== $this->url && '' !== $this->token;
	}

	private function request( $method, $path, $body = null, $query = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'fsd_ac_not_configured', __( 'ActiveCampaign ist nicht vollständig konfiguriert.', 'freemius-dashboard' ) );
		}

		$url = $this->url . '/api/3/' . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => array( 'Api-Token' => $this->token, 'Content-Type' => 'application/json' ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data->message ) ? (string) $data->message : sprintf( __( 'ActiveCampaign API-Fehler (HTTP %d).', 'freemius-dashboard' ), $code );
			return new WP_Error( 'fsd_ac_api_error', $message, array( 'status' => $code ) );
		}
		return $data;
	}

	public function test() {
		return $this->request( 'GET', 'lists', null, array( 'limit' => 1 ) );
	}

	public function find_contact( $email ) {
		$result = $this->request( 'GET', 'contacts', null, array( 'email' => $email, 'limit' => 1 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return ! empty( $result->contacts[0] ) ? $result->contacts[0] : null;
	}

	public function sync_contact( $email, $first = '', $last = '' ) {
		$result = $this->request( 'POST', 'contact/sync', array( 'contact' => array( 'email' => $email, 'firstName' => $first, 'lastName' => $last ) ) );
		return is_wp_error( $result ) ? $result : $result->contact;
	}

	public function subscribe( $contact_id, $list_id ) {
		return $this->request( 'POST', 'contactLists', array( 'contactList' => array( 'list' => (int) $list_id, 'contact' => (int) $contact_id, 'status' => 1 ) ) );
	}

	private function get_or_create_tag( $name ) {
		$result = $this->request( 'GET', 'tags', null, array( 'search' => $name, 'limit' => 100 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( (array) $result->tags as $tag ) {
			if ( 0 === strcasecmp( $name, $tag->tag ) ) {
				return $tag->id;
			}
		}
		$result = $this->request( 'POST', 'tags', array( 'tag' => array( 'tag' => $name, 'tagType' => 'contact', 'description' => 'Automatisch durch Freemius Dashboard angelegt' ) ) );
		return is_wp_error( $result ) ? $result : $result->tag->id;
	}

	public function add_tag( $contact_id, $name ) {
		if ( '' === trim( $name ) ) {
			return true;
		}
		$tag_id = $this->get_or_create_tag( trim( $name ) );
		if ( is_wp_error( $tag_id ) ) {
			return $tag_id;
		}
		$assigned = $this->request( 'GET', 'contacts/' . (int) $contact_id . '/contactTags' );
		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}
		foreach ( (array) $assigned->contactTags as $association ) {
			if ( (int) $association->tag === (int) $tag_id ) {
				return true;
			}
		}
		return $this->request( 'POST', 'contactTags', array( 'contactTag' => array( 'contact' => (int) $contact_id, 'tag' => (int) $tag_id ) ) );
	}

	public function remove_tag( $contact_id, $name ) {
		if ( '' === trim( $name ) ) { return true; }
		$tag_id = $this->get_or_create_tag( trim( $name ) );
		if ( is_wp_error( $tag_id ) ) { return $tag_id; }
		$assigned = $this->request( 'GET', 'contacts/' . (int) $contact_id . '/contactTags' );
		if ( is_wp_error( $assigned ) ) { return $assigned; }
		foreach ( (array) $assigned->contactTags as $association ) {
			if ( (int) $association->tag === (int) $tag_id ) {
				return $this->request( 'DELETE', 'contactTags/' . (int) $association->id );
			}
		}
		return true;
	}
}
