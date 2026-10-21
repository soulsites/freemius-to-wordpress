<?php
/**
 * Abgleich bestehender Freemius-Käufer mit Brevo.
 *
 * Läuft schrittweise über wiederholte AJAX-Requests (assets/js/fsd-brevo.js),
 * jeder Request arbeitet höchstens TIME_BUDGET Sekunden. So bleibt jeder
 * einzelne Request deutlich unter typischen Gateway-/Proxy-Timeouts, egal wie
 * viele Zahlungen und Käufer es gibt. Der Zwischenstand liegt in einem
 * Transient je Admin-Nutzer.
 *
 * Phasen: payments (Freemius-Zahlungen seitenweise laden) → users (fehlende
 * Nutzerdaten nachladen) → brevo (je Käufer prüfen, ob Kontakt existiert) → done.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Brevo_Reconcile {
	const AJAX_RECONCILE = 'fsd_brevo_reconcile_step';
	const AJAX_TRANSFER  = 'fsd_brevo_transfer_step';
	const NONCE          = 'fsd_brevo_reconcile';
	const TIME_BUDGET    = 12;
	const STATE_PREFIX   = 'fsd_brevo_reconcile_';
	const MISSING_PREFIX = 'fsd_brevo_missing_';

	/** @var FSD_Brevo */
	private $brevo;

	/** @var FSD_Api|null */
	private $freemius;

	/** @var float */
	private $started;

	public function __construct( FSD_Brevo $brevo ) {
		$this->brevo = $brevo;
	}

	public static function get_missing() {
		return get_transient( self::MISSING_PREFIX . get_current_user_id() );
	}

	public static function set_missing( $missing ) {
		set_transient( self::MISSING_PREFIX . get_current_user_id(), array_values( $missing ), HOUR_IN_SECONDS );
	}

	private function authorize() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'freemius-dashboard' ) ), 403 );
		}
		$this->started = microtime( true );
	}

	private function has_time() {
		return ( microtime( true ) - $this->started ) < self::TIME_BUDGET;
	}

	private function freemius() {
		if ( null === $this->freemius ) {
			$fs             = FSD_Settings::get_settings();
			$this->freemius = new FSD_Api( $fs['product_id'], $fs['public_key'], $fs['secret_key'], $fs['product_id'] );
		}
		return $this->freemius;
	}

	/* --------------------------------------------------------------------
	 * Abgleich
	 * ------------------------------------------------------------------ */

	public function ajax_reconcile_step() {
		$this->authorize();
		$key   = self::STATE_PREFIX . get_current_user_id();
		$state = get_transient( $key );

		if ( ! empty( $_POST['reset'] ) || ! is_array( $state ) ) {
			$state = array(
				'phase'    => 'payments',
				'offset'   => 0,
				'payments' => array(),
				'users'    => array(),
				'pending'  => array(),
				'buyers'   => array(),
				'checked'  => 0,
				'missing'  => array(),
			);
		}

		while ( 'done' !== $state['phase'] && $this->has_time() ) {
			$result = $this->{'run_' . $state['phase']}( $state );
			if ( is_wp_error( $result ) ) {
				delete_transient( $key );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
		}

		if ( 'done' === $state['phase'] ) {
			delete_transient( $key );
			self::set_missing( $state['missing'] );
			wp_send_json_success(
				array(
					'done'    => true,
					'message' => sprintf( '%d Käufer geprüft, %d fehlen in Brevo.', count( $state['buyers'] ), count( $state['missing'] ) ),
				)
			);
		}

		set_transient( $key, $state, HOUR_IN_SECONDS );
		wp_send_json_success( array( 'done' => false, 'message' => $this->progress( $state ) ) );
	}

	private function progress( $state ) {
		switch ( $state['phase'] ) {
			case 'payments':
				return sprintf( 'Lade Freemius-Zahlungen … (%d)', count( $state['payments'] ) );
			case 'users':
				return sprintf( 'Lade Kundendaten … (%d offen)', count( $state['pending'] ) );
			default:
				return sprintf( 'Prüfe Brevo … %d / %d', $state['checked'], count( $state['buyers'] ) );
		}
	}

	/** Eine Seite Freemius-Zahlungen laden und auf die benötigten Felder reduzieren. */
	private function run_payments( &$state ) {
		$chunk = $this->freemius()->get_payments_page( new DateTimeImmutable( '2010-01-01' ), new DateTimeImmutable( 'tomorrow' ), $state['offset'] );
		if ( is_wp_error( $chunk ) ) {
			return $chunk;
		}

		foreach ( $chunk as $payment ) {
			if ( isset( $payment->environment ) && 1 === (int) $payment->environment ) {
				continue;
			}
			$state['payments'][] = self::slim_payment( $payment );
			if ( isset( $payment->user->email ) && ! empty( $payment->user_id ) ) {
				$state['users'][ (int) $payment->user_id ] = self::slim_user( $payment->user );
			}
		}

		$state['offset'] += FSD_Api::PAGE_SIZE;
		if ( count( $chunk ) < FSD_Api::PAGE_SIZE ) {
			$ids              = array_unique( array_filter( array_map( 'intval', wp_list_pluck( $state['payments'], 'user_id' ) ) ) );
			$state['pending'] = array_values( array_diff( $ids, array_keys( $state['users'] ) ) );
			$state['phase']   = 'users';
		}
		return true;
	}

	/** Einen fehlenden Nutzer nachladen (1 Tag gecacht, wie im Dashboard). */
	private function run_users( &$state ) {
		$user_id = array_shift( $state['pending'] );
		if ( null === $user_id ) {
			$state['buyers'] = array_values( self::aggregate_buyers( $state['payments'], $state['users'] ) );
			$state['phase']  = 'brevo';
			unset( $state['payments'], $state['users'] );
			return true;
		}

		$user = get_transient( 'fsd_user_' . $user_id );
		if ( false === $user ) {
			$fetched = $this->freemius()->get_user( $user_id );
			$user    = is_wp_error( $fetched ) ? null : $fetched;
			set_transient( 'fsd_user_' . $user_id, $user, DAY_IN_SECONDS );
		}
		if ( $user && ! empty( $user->email ) ) {
			$state['users'][ $user_id ] = self::slim_user( $user );
		}
		return true;
	}

	/** Einen Käufer in Brevo prüfen. */
	private function run_brevo( &$state ) {
		if ( $state['checked'] >= count( $state['buyers'] ) ) {
			$state['phase'] = 'done';
			return true;
		}
		$buyer   = $state['buyers'][ $state['checked'] ];
		$present = $this->brevo->check_presence( $buyer['email'] );
		if ( is_wp_error( $present ) ) {
			return $present;
		}
		if ( ! $present ) {
			$state['missing'][] = $buyer;
		}
		++$state['checked'];
		return true;
	}

	private static function slim_payment( $payment ) {
		return array(
			'id'              => (string) ( $payment->id ?? '' ),
			'user_id'         => (int) ( $payment->user_id ?? 0 ),
			'gross'           => (float) ( $payment->gross ?? 0 ),
			'type'            => (string) ( $payment->type ?? '' ),
			'created'         => (string) ( $payment->created ?? '' ),
			'coupon_id'       => (string) ( $payment->coupon_id ?? '' ),
			'license_id'      => (string) ( $payment->license_id ?? '' ),
			'subscription_id' => (string) ( $payment->subscription_id ?? '' ),
			'affiliate_id'    => (string) ( $payment->affiliate_id ?? '' ),
			'country_code'    => (string) ( $payment->country_code ?? '' ),
			'plan_title'      => (string) ( $payment->plan->title ?? '' ),
		);
	}

	private static function slim_user( $user ) {
		return array(
			'id'                   => (string) ( $user->id ?? '' ),
			'email'                => strtolower( (string) $user->email ),
			'first'                => (string) ( $user->first ?? '' ),
			'last'                 => (string) ( $user->last ?? '' ),
			'is_marketing_allowed' => $user->is_marketing_allowed ?? null,
		);
	}

	/**
	 * Fasst alle Zahlungen je Käufer zu absoluten Attributwerten zusammen.
	 *
	 * @param array $payments Reduzierte Zahlungen (slim_payment).
	 * @param array $users    Reduzierte Nutzer je Freemius-Nutzer-ID (slim_user).
	 */
	private static function aggregate_buyers( $payments, $users ) {
		usort(
			$payments,
			static function ( $a, $b ) {
				return strcmp( $a['created'], $b['created'] );
			}
		);

		$reseller = FSD_Brevo::get_settings()['reseller'];
		$buyers   = array();
		foreach ( $payments as $payment ) {
			if ( empty( $users[ $payment['user_id'] ] ) ) {
				continue;
			}
			$user      = (object) $users[ $payment['user_id'] ];
			$object    = (object) $payment;
			$email     = $user->email;
			$is_refund = 'refund' === $payment['type'] || $payment['gross'] < 0;
			$date      = FSD_Brevo::date_of( $object );
			$prev      = $buyers[ $email ]['values'] ?? array(
				'ANZAHL_KAEUFE'      => 0,
				'UMSATZ_GESAMT'      => 0.0,
				'ERSTKAUF_DATUM'     => '',
				'LETZTER_KAUF_DATUM' => '',
				'LETZTES_PRODUKT'    => '',
				'ERSTATTUNG'         => 'NO',
				'GUTSCHEIN_GENUTZT'  => 'NO',
			);
			$total     = round( $prev['UMSATZ_GESAMT'] + $payment['gross'], 2 );

			$buyers[ $email ] = array(
				'email'  => $email,
				'values' => array(
					'FIRSTNAME'          => $user->first,
					'LASTNAME'           => $user->last,
					'FREEMIUS_ID'        => '' !== $user->id ? $user->id : (string) $payment['user_id'],
					'FREEMIUS_UMSATZ'    => $total,
					'UMSATZ_GESAMT'      => $total,
					'ANZAHL_KAEUFE'      => $prev['ANZAHL_KAEUFE'] + ( $is_refund ? 0 : 1 ),
					'LETZTER_KAUF_DATUM' => $is_refund ? $prev['LETZTER_KAUF_DATUM'] : $date,
					'ERSTKAUF_DATUM'     => '' !== $prev['ERSTKAUF_DATUM'] || $is_refund ? $prev['ERSTKAUF_DATUM'] : $date,
					'LETZTES_PRODUKT'    => '' !== $payment['plan_title'] ? $payment['plan_title'] : $prev['LETZTES_PRODUKT'],
					'BILLINGCYCLE'       => FSD_Brevo::billing_cycle( $object, new stdClass() ),
					'LIZENZ'             => $payment['license_id'],
					'KUNDENSTATUS'       => $total > 0 ? 'Kunde' : 'Free',
					'BEZAHLT'            => $total > 0 ? 'YES' : 'NO',
					'ERSTATTUNG'         => $is_refund ? 'YES' : $prev['ERSTATTUNG'],
					'GUTSCHEIN_GENUTZT'  => '' !== $payment['coupon_id'] ? 'YES' : $prev['GUTSCHEIN_GENUTZT'],
					'AFFILIATE_ID'       => $payment['affiliate_id'],
					'LAND'               => strtoupper( $payment['country_code'] ),
					'RESELLER'           => $reseller,
					'MARKETING_MAILS'    => FSD_Brevo::consent_value( $user, true ),
				),
			);
		}
		return $buyers;
	}

	/* --------------------------------------------------------------------
	 * Alle fehlenden Kontakte übertragen
	 * ------------------------------------------------------------------ */

	public function ajax_transfer_step() {
		$this->authorize();
		$missing = self::get_missing();
		$missing = is_array( $missing ) ? array_values( $missing ) : array();
		// Fehlgeschlagene Kontakte bleiben vorne in der Liste stehen und werden
		// in den Folge-Requests übersprungen (Details im Protokoll).
		$skip   = isset( $_POST['skip'] ) ? min( absint( $_POST['skip'] ), count( $missing ) ) : 0;
		$failed = array_slice( $missing, 0, $skip );
		$queue  = array_slice( $missing, $skip );

		while ( $queue && $this->has_time() ) {
			$buyer = array_shift( $queue );
			if ( is_wp_error( $this->brevo->transfer_buyer( $buyer ) ) ) {
				$failed[] = $buyer;
			}
		}
		self::set_missing( array_merge( $failed, $queue ) );

		wp_send_json_success(
			array(
				'done'    => ! $queue,
				'skip'    => count( $failed ),
				'message' => ! $queue
					? sprintf( 'Übertragung abgeschlossen, %d Fehler (siehe Protokoll).', count( $failed ) )
					: sprintf( 'Übertrage … %d offen', count( $queue ) ),
			)
		);
	}
}
