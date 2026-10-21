<?php
/** Protokoll der Brevo-Synchronisierungen (Ringpuffer in wp_options). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Brevo_Log {
	const OPTION_KEY  = 'fsd_brevo_log';
	const MAX_ENTRIES = 500;

	const STATUS_SUCCESS = 'success';
	const STATUS_ERROR   = 'error';
	const STATUS_SKIPPED = 'skipped';

	public function all() {
		$entries = get_option( self::OPTION_KEY, array() );
		return is_array( $entries ) ? $entries : array();
	}

	public function add( $status, $email, $event, $message = '', $payment_id = '' ) {
		$entries = $this->all();
		array_unshift(
			$entries,
			array(
				'time'       => time(),
				'status'     => $status,
				'email'      => strtolower( (string) $email ),
				'event'      => (string) $event,
				'payment_id' => (string) $payment_id,
				'message'    => (string) $message,
			)
		);
		update_option( self::OPTION_KEY, array_slice( $entries, 0, self::MAX_ENTRIES ), false );
	}

	public function find_by_payment( $payment_id ) {
		if ( '' === (string) $payment_id ) {
			return null;
		}
		foreach ( $this->all() as $entry ) {
			if ( (string) $payment_id === $entry['payment_id'] ) {
				return $entry;
			}
		}
		return null;
	}

	public function is_payment_synced( $payment_id ) {
		foreach ( $this->all() as $entry ) {
			if ( '' !== (string) $payment_id && (string) $payment_id === $entry['payment_id'] && self::STATUS_SUCCESS === $entry['status'] ) {
				return true;
			}
		}
		return false;
	}

	public function clear() {
		delete_option( self::OPTION_KEY );
	}
}
