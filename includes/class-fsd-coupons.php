<?php
/**
 * Gutscheine-Seite: Tabelle aller Freemius-Gutscheine mit Massenaktionen
 * (aktivieren, deaktivieren, löschen).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Coupons {

	const PAGE_SLUG   = 'fsd-coupons';
	const CACHE_KEY   = 'fsd_coupons';
	const CACHE_TTL   = 5 * MINUTE_IN_SECONDS;
	const BULK_ACTION = 'fsd_coupons_bulk';
	const NONCE       = 'fsd_coupons_bulk';

	/** @var FSD_Api|null */
	private $api;

	private function api() {
		if ( null === $this->api ) {
			$s         = FSD_Settings::get_settings();
			$this->api = new FSD_Api( $s['product_id'], $s['public_key'], $s['secret_key'], $s['product_id'] );
		}
		return $this->api;
	}

	/** @return array Aktions-Key => Label. */
	private static function bulk_actions() {
		return array(
			'activate'   => __( 'Aktivieren', 'freemius-dashboard' ),
			'deactivate' => __( 'Deaktivieren', 'freemius-dashboard' ),
			'delete'     => __( 'Löschen', 'freemius-dashboard' ),
		);
	}

	public function enqueue_script() {
		wp_enqueue_script( 'fsd-coupons', FSD_PLUGIN_URL . 'assets/js/fsd-coupons.js', array( 'jquery' ), FSD_VERSION, true );
		wp_localize_script(
			'fsd-coupons',
			'fsdCoupons',
			array(
				'i18n' => array(
					'noSelection'   => __( 'Bitte mindestens einen Gutschein markieren.', 'freemius-dashboard' ),
					'noAction'      => __( 'Bitte eine Aktion wählen.', 'freemius-dashboard' ),
					'confirmDelete' => __( 'Markierte Gutscheine wirklich endgültig löschen?', 'freemius-dashboard' ),
				),
			)
		);
	}

	/** @return array|WP_Error */
	private function get_coupons() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$coupons = $this->api()->get_coupons();
		if ( is_wp_error( $coupons ) ) {
			return $coupons;
		}

		usort(
			$coupons,
			static function ( $a, $b ) {
				return strcmp( (string) ( $b->created ?? '' ), (string) ( $a->created ?? '' ) );
			}
		);

		set_transient( self::CACHE_KEY, $coupons, self::CACHE_TTL );
		return $coupons;
	}

	/** admin-post: Massenaktion ausführen und mit Ergebnis zurückleiten. */
	public function handle_bulk() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'freemius-dashboard' ) );
		}
		check_admin_referer( self::NONCE );

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = isset( $_POST['coupon_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['coupon_ids'] ) ) ) : array();
		$done   = 0;
		$errors = array();

		if ( isset( self::bulk_actions()[ $action ] ) ) {
			foreach ( $ids as $id ) {
				$result = $this->run_action( $action, $id );
				if ( is_wp_error( $result ) ) {
					$errors[] = '#' . $id . ': ' . $result->get_error_message();
				} else {
					++$done;
				}
			}
			delete_transient( self::CACHE_KEY );
		}

		if ( $errors ) {
			set_transient( 'fsd_coupons_errors_' . get_current_user_id(), $errors, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'fsd_done'   => $done,
					'fsd_failed' => count( $errors ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function run_action( $action, $id ) {
		switch ( $action ) {
			case 'activate':
				return $this->api()->update_coupon_active( $id, true );
			case 'deactivate':
				return $this->api()->update_coupon_active( $id, false );
			case 'delete':
				return $this->api()->delete_coupon( $id );
		}
		return new WP_Error( 'fsd_unknown_action', __( 'Unbekannte Aktion.', 'freemius-dashboard' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap fsd-wrap">';
		echo '<h1 class="fsd-title">' . esc_html__( 'Freemius – Gutscheine', 'freemius-dashboard' ) . '</h1>';

		if ( ! $this->api()->is_configured() ) {
			printf(
				'<div class="fsd-card fsd-notice">%s <a href="%s">%s</a></div></div>',
				esc_html__( 'Bitte hinterlege zunächst deine Freemius API-Zugangsdaten.', 'freemius-dashboard' ),
				esc_url( admin_url( 'admin.php?page=' . FSD_Settings::PAGE_SLUG ) ),
				esc_html__( 'Zu den Einstellungen', 'freemius-dashboard' )
			);
			return;
		}

		$this->render_result_notice();

		$coupons = $this->get_coupons();
		if ( is_wp_error( $coupons ) ) {
			printf( '<div class="fsd-card fsd-notice fsd-notice--error">%s</div></div>', esc_html( $coupons->get_error_message() ) );
			return;
		}

		echo '<div class="fsd-card">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="fsd-coupons-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::BULK_ACTION ) . '" />';
		wp_nonce_field( self::NONCE );

		echo '<div class="fsd-toolbar">';
		echo '<h2 class="fsd-card__title">' . esc_html( sprintf( /* translators: %d: Anzahl */ __( 'Gutscheine (%d)', 'freemius-dashboard' ), count( $coupons ) ) ) . '</h2>';
		echo '<div class="fsd-bulk">';
		echo '<select name="bulk_action" class="fsd-select"><option value="">' . esc_html__( 'Massenaktion', 'freemius-dashboard' ) . '</option>';
		foreach ( self::bulk_actions() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> <button type="submit" class="fsd-btn fsd-btn--tonal">' . esc_html__( 'Übernehmen', 'freemius-dashboard' ) . '</button>';
		echo '</div></div>';

		$this->render_table( $coupons );

		echo '</form></div></div>';
	}

	private function render_result_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['fsd_done'] ) ) {
			return;
		}
		$done   = absint( $_GET['fsd_done'] );
		$failed = isset( $_GET['fsd_failed'] ) ? absint( $_GET['fsd_failed'] ) : 0;
		// phpcs:enable

		/* translators: %d: Anzahl */
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Gutschein(e) bearbeitet.', 'freemius-dashboard' ), $done ) ) . '</p></div>';

		if ( $failed ) {
			$key    = 'fsd_coupons_errors_' . get_current_user_id();
			$errors = (array) get_transient( $key );
			delete_transient( $key );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( /* translators: %d: Anzahl */ __( '%d Aktion(en) fehlgeschlagen:', 'freemius-dashboard' ), $failed ) ) . '</p><ul>';
			foreach ( array_filter( $errors ) as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	private function render_table( $coupons ) {
		$columns = array(
			__( 'Code', 'freemius-dashboard' ),
			__( 'Status', 'freemius-dashboard' ),
			__( 'Rabatt', 'freemius-dashboard' ),
			__( 'Einlösungen', 'freemius-dashboard' ),
			__( 'Gültig ab', 'freemius-dashboard' ),
			__( 'Gültig bis', 'freemius-dashboard' ),
			__( 'Kunden', 'freemius-dashboard' ),
			__( 'Optionen', 'freemius-dashboard' ),
			__( 'Erstellt', 'freemius-dashboard' ),
		);

		echo '<div class="fsd-table-wrap"><table class="fsd-table fsd-coupons"><thead><tr>';
		echo '<th class="fsd-table__check"><input type="checkbox" id="fsd-coupons-all" aria-label="' . esc_attr__( 'Alle markieren', 'freemius-dashboard' ) . '" /></th>';
		foreach ( $columns as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $coupons ) {
			echo '<tr><td colspan="' . esc_attr( count( $columns ) + 1 ) . '" class="fsd-table__empty">' . esc_html__( 'Keine Gutscheine gefunden.', 'freemius-dashboard' ) . '</td></tr>';
		}

		foreach ( $coupons as $c ) {
			$id     = (int) ( $c->id ?? 0 );
			$active = ! empty( $c->is_active );
			echo '<tr>';
			echo '<td class="fsd-table__check"><input type="checkbox" class="fsd-coupon-check" name="coupon_ids[]" value="' . esc_attr( $id ) . '" /></td>';
			echo '<td><div class="fsd-customer"><span class="fsd-customer__name">' . esc_html( (string) ( $c->code ?? '' ) ) . '</span><span class="fsd-customer__email">#' . esc_html( $id ) . '</span></div></td>';
			printf(
				'<td><span class="fsd-chip %s">%s</span></td>',
				esc_attr( $active ? 'fsd-chip--active' : 'fsd-chip--blocked' ),
				esc_html( $active ? __( 'Aktiv', 'freemius-dashboard' ) : __( 'Inaktiv', 'freemius-dashboard' ) )
			);
			echo '<td>' . esc_html( self::discount( $c ) ) . '</td>';
			echo '<td>' . esc_html( self::redemptions( $c ) ) . '</td>';
			echo '<td>' . esc_html( self::date( $c->start_date ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( self::date( $c->end_date ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( self::user_type( $c->user_type ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( self::options( $c ) ) . '</td>';
			echo '<td>' . esc_html( self::date( $c->created ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	private static function discount( $c ) {
		$value = (float) ( $c->discount ?? 0 );
		return 'percentage' === ( $c->discount_type ?? 'percentage' )
			? number_format_i18n( $value, 0 ) . ' %'
			: number_format_i18n( $value, 2 ) . ' $';
	}

	private static function redemptions( $c ) {
		$used  = (int) ( $c->redemptions ?? 0 );
		$limit = $c->redemptions_limit ?? null;
		return null === $limit || '' === $limit ? $used . ' / ∞' : $used . ' / ' . (int) $limit;
	}

	private static function date( $value ) {
		if ( empty( $value ) ) {
			return '—';
		}
		$ts = strtotime( (string) $value );
		return $ts ? wp_date( get_option( 'date_format' ), $ts ) : (string) $value;
	}

	private static function user_type( $type ) {
		$map = array(
			'all'      => __( 'Alle', 'freemius-dashboard' ),
			'new'      => __( 'Neukunden', 'freemius-dashboard' ),
			'current'  => __( 'Bestandskunden', 'freemius-dashboard' ),
			'previous' => __( 'Ehemalige', 'freemius-dashboard' ),
			'customer' => __( 'Kunden', 'freemius-dashboard' ),
		);
		return $map[ $type ] ?? ( $type ? (string) $type : __( 'Alle', 'freemius-dashboard' ) );
	}

	private static function options( $c ) {
		$opts = array();
		if ( ! empty( $c->is_one_per_user ) ) {
			$opts[] = __( '1× pro Kunde', 'freemius-dashboard' );
		}
		if ( ! empty( $c->has_renewals_discount ) || ! empty( $c->is_renewals_discount ) ) {
			$opts[] = __( 'Auch Verlängerungen', 'freemius-dashboard' );
		}
		if ( ! empty( $c->plans ) ) {
			$opts[] = __( 'Pläne: ', 'freemius-dashboard' ) . ( is_array( $c->plans ) ? implode( ', ', $c->plans ) : (string) $c->plans );
		}
		if ( ! empty( $c->licenses ) ) {
			$opts[] = __( 'Lizenzen: ', 'freemius-dashboard' ) . ( is_array( $c->licenses ) ? implode( ', ', $c->licenses ) : (string) $c->licenses );
		}
		if ( ! empty( $c->billing_cycles ) ) {
			$opts[] = __( 'Zyklen: ', 'freemius-dashboard' ) . ( is_array( $c->billing_cycles ) ? implode( ', ', $c->billing_cycles ) : (string) $c->billing_cycles );
		}
		return $opts ? implode( ' · ', $opts ) : '—';
	}
}
