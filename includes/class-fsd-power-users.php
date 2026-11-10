<?php
/**
 * Power-User-Liste: Kunden mit mehr als MIN_INSTALLS aktiven Installationen,
 * absteigend nach Installationen. Details (inkl. Brevo-Status) werden beim
 * Klick per AJAX in einem Modal geladen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Power_Users {

	const MIN_INSTALLS = 10;
	const CACHE_KEY    = 'fsd_power_users';
	const CACHE_TTL    = HOUR_IN_SECONDS;
	const AJAX_DETAILS = 'fsd_power_user_details';
	const NONCE        = 'fsd_power_user_details';

	/** @var FSD_Brevo */
	private $brevo;

	/** @var FSD_Api|null */
	private $api;

	public function __construct( FSD_Brevo $brevo ) {
		$this->brevo = $brevo;
	}

	private function api() {
		if ( null === $this->api ) {
			$s         = FSD_Settings::get_settings();
			$this->api = new FSD_Api( $s['product_id'], $s['public_key'], $s['secret_key'], $s['product_id'] );
		}
		return $this->api;
	}

	public function enqueue_script() {
		wp_enqueue_script( 'fsd-power-users', FSD_PLUGIN_URL . 'assets/js/fsd-power-users.js', array( 'jquery' ), FSD_VERSION, true );
		wp_localize_script(
			'fsd-power-users',
			'fsdPowerUsers',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'action'  => self::AJAX_DETAILS,
			)
		);
	}

	/**
	 * @return array|WP_Error Liste aus array( user_id, installs, name, email ), absteigend nach installs.
	 */
	private function get_list() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$installs = $this->api()->get_installs();
		if ( is_wp_error( $installs ) ) {
			return $installs;
		}

		$counts = array();
		foreach ( $installs as $install ) {
			$active = ! empty( $install->is_active ) && empty( $install->is_uninstalled );
			if ( ! $active || empty( $install->user_id ) ) {
				continue;
			}
			$uid            = (int) $install->user_id;
			$counts[ $uid ] = ( $counts[ $uid ] ?? 0 ) + 1;
		}

		arsort( $counts );

		$list = array();
		foreach ( $counts as $uid => $count ) {
			if ( $count <= self::MIN_INSTALLS ) {
				break;
			}
			$user   = $this->get_user( $uid );
			$list[] = array(
				'user_id'  => $uid,
				'installs' => $count,
				'name'     => $user ? trim( ( $user->first ?? '' ) . ' ' . ( $user->last ?? '' ) ) : '',
				'email'    => $user ? (string) ( $user->email ?? '' ) : '',
			);
		}

		set_transient( self::CACHE_KEY, $list, self::CACHE_TTL );
		return $list;
	}

	private function get_user( $user_id ) {
		$key  = 'fsd_user_' . (int) $user_id;
		$user = get_transient( $key );
		if ( false === $user ) {
			$fetched = $this->api()->get_user( $user_id );
			$user    = is_wp_error( $fetched ) ? null : $fetched;
			set_transient( $key, $user, DAY_IN_SECONDS );
		}
		return $user;
	}

	public function render() {
		$list = $this->get_list();

		echo '<div class="fsd-card">';
		echo '<h2 class="fsd-card__title">' . esc_html( sprintf( /* translators: %d: Mindestanzahl */ __( 'Power User (mehr als %d Installationen)', 'freemius-dashboard' ), self::MIN_INSTALLS ) ) . '</h2>';

		if ( is_wp_error( $list ) ) {
			printf( '<p class="fsd-notice fsd-notice--error">%s</p></div>', esc_html( $list->get_error_message() ) );
			return;
		}

		echo '<div class="fsd-table-wrap"><table class="fsd-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Kunde', 'freemius-dashboard' ) . '</th>';
		echo '<th class="fsd-table__amount">' . esc_html__( 'Installationen', 'freemius-dashboard' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $list ) {
			echo '<tr><td colspan="2" class="fsd-table__empty">' . esc_html__( 'Keine Power User gefunden.', 'freemius-dashboard' ) . '</td></tr>';
		}

		foreach ( $list as $row ) {
			$label = '' !== $row['name'] ? $row['name'] : ( '' !== $row['email'] ? $row['email'] : '#' . $row['user_id'] );
			echo '<tr class="fsd-power-user" data-user-id="' . esc_attr( $row['user_id'] ) . '" data-installs="' . esc_attr( $row['installs'] ) . '" tabindex="0">';
			echo '<td><div class="fsd-customer"><span class="fsd-customer__name">' . esc_html( $label ) . '</span>';
			if ( '' !== $row['name'] && '' !== $row['email'] ) {
				echo '<span class="fsd-customer__email">' . esc_html( $row['email'] ) . '</span>';
			}
			echo '</div></td>';
			echo '<td class="fsd-table__amount">' . esc_html( $row['installs'] ) . '</td></tr>';
		}

		echo '</tbody></table></div></div>';
		echo '<div class="fsd-modal" id="fsd-power-modal" hidden><div class="fsd-modal__box" role="dialog" aria-modal="true">';
		echo '<button type="button" class="fsd-modal__close" aria-label="' . esc_attr__( 'Schließen', 'freemius-dashboard' ) . '">&times;</button>';
		echo '<div class="fsd-modal__content"></div></div></div>';
	}

	/** AJAX: Detaildaten eines Power Users inkl. Brevo-Status (live). */
	public function ajax_details() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'freemius-dashboard' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$user    = $user_id ? $this->get_user( $user_id ) : null;
		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'Nutzer nicht gefunden.', 'freemius-dashboard' ) ) );
		}

		$email = strtolower( trim( (string) ( $user->email ?? '' ) ) );
		$rows  = array(
			__( 'Name', 'freemius-dashboard' )             => trim( ( $user->first ?? '' ) . ' ' . ( $user->last ?? '' ) ) ?: '—',
			__( 'E-Mail', 'freemius-dashboard' )           => $email ?: '—',
			__( 'Freemius-ID', 'freemius-dashboard' )      => (string) $user_id,
			__( 'Aktive Installationen', 'freemius-dashboard' ) => (string) $this->installs_for( $user_id ),
			__( 'Marketing (Freemius)', 'freemius-dashboard' )  => FSD_Brevo::consent_value( $user, true ) === 'YES' ? __( 'Aktiviert', 'freemius-dashboard' ) : __( 'Nicht aktiviert', 'freemius-dashboard' ),
		);

		$rows = array_merge( $rows, $this->brevo_rows( $email ) );

		wp_send_json_success( array( 'rows' => $rows ) );
	}

	private function installs_for( $user_id ) {
		$list = $this->get_list();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			if ( (int) $row['user_id'] === (int) $user_id ) {
				return (int) $row['installs'];
			}
		}
		return 0;
	}

	/** @return array Label => Wert für den Brevo-Teil des Modals. */
	private function brevo_rows( $email ) {
		$label_brevo = __( 'In Brevo angelegt', 'freemius-dashboard' );
		$label_mkt   = __( 'Marketing-Mails (Brevo)', 'freemius-dashboard' );

		if ( ! FSD_Brevo::is_active() || '' === $email ) {
			return array( $label_brevo => __( 'Brevo nicht aktiv', 'freemius-dashboard' ) );
		}

		$contact = $this->brevo->get_contact( $email );
		if ( is_wp_error( $contact ) ) {
			return array( $label_brevo => __( 'Unbekannt: ', 'freemius-dashboard' ) . $contact->get_error_message() );
		}
		if ( ! $contact ) {
			return array( $label_brevo => __( 'Nein', 'freemius-dashboard' ) );
		}

		$attrs   = isset( $contact->attributes ) ? (array) $contact->attributes : array();
		$flag    = $attrs['MARKETING_MAILS'] ?? null;
		$flag_on = true === $flag || in_array( strtoupper( (string) $flag ), array( 'YES', 'TRUE', '1' ), true );
		$in_list = (bool) array_intersect(
			FSD_Brevo::parse_list_ids( FSD_Brevo::get_settings()['marketing_list_ids'] ),
			array_map( 'intval', (array) ( $contact->listIds ?? array() ) )
		);

		if ( FSD_Brevo::is_brevo_opted_out( $contact ) ) {
			$marketing = __( 'Abgemeldet', 'freemius-dashboard' );
		} else {
			$marketing = ( $flag_on || $in_list ) ? __( 'Aktiviert', 'freemius-dashboard' ) : __( 'Nicht aktiviert', 'freemius-dashboard' );
		}

		return array(
			$label_brevo => __( 'Ja', 'freemius-dashboard' ),
			$label_mkt   => $marketing,
		);
	}
}
