<?php
/**
 * Dashboard-Seite: Käufe-Tabelle (Kalendermonat), Netto-Einnahmen, Chart der letzten 30 Tage.
 *
 * Bei vielen Käufen (mehrere hundert) kann das Laden aller Zahlungen über die
 * Freemius-API mehrere Sekunden bis Minuten dauern (mehrere sequentielle
 * HTTP-Anfragen, da Freemius die Ergebnisse seitenweise liefert). Würde das
 * synchron beim Seitenaufruf passieren, würde die Admin-Seite entsprechend
 * lange "hängen" – im ungünstigsten Fall länger als das PHP-Skript-Zeitlimit
 * (max_execution_time), was zu einem Timeout ohne jede Ausgabe führen kann.
 *
 * Deshalb rendert render() nur noch das Seiten-Grundgerüst (Karten, Filter,
 * Lade-Platzhalter). Die eigentlichen Zahlungsdaten werden anschließend per
 * JavaScript (assets/js/fsd-dashboard.js) seitenweise über AJAX nachgeladen
 * (fsd_dashboard_page) und die Tabelle/den Chart inkrementell befüllt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Dashboard {

	const PAGE_SLUG = 'fsd-dashboard';
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;
	const NONCE_ACTION = 'fsd_dashboard';

	/** @var FSD_Api */
	private $api;

	public function __construct() {
		$settings  = FSD_Settings::get_settings();
		$this->api = new FSD_Api(
			$settings['product_id'],
			$settings['public_key'],
			$settings['secret_key'],
			$settings['product_id']
		);
	}

	/**
	 * Lädt eine einzelne Seite Zahlungen für den angegebenen Zeitraum, gecacht
	 * pro Seite (nicht mehr als ein Gesamtblock), damit ein einzelner
	 * AJAX-Request immer nur eine schnelle Freemius-Anfrage auslöst.
	 *
	 * @return array|WP_Error {payments: array, has_more: bool} oder WP_Error.
	 */
	private function get_cached_page( DateTimeInterface $from, DateTimeInterface $to, $page ) {
		$cache_key = 'fsd_pay_p_' . md5( $from->format( 'c' ) . '|' . $to->format( 'c' ) . '|' . (int) $page );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = $this->api->get_payments_page( $from, $to, $page );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Sandbox-/Test-Käufe (Freemius "environment" = 1) sind keine echten Verkäufe
		// und dürfen weder in der Tabelle noch in den Summen auftauchen.
		$result['payments'] = array_values(
			array_filter(
				$result['payments'],
				static function ( $payment ) {
					return ! self::is_sandbox( $payment );
				}
			)
		);

		$result['payments'] = $this->hydrate_missing_customers( $result['payments'] );

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Ergänzt bei Payments ohne verwertbaren Vor-/Nachnamen (das eingebettete
	 * user-Objekt fehlt trotz extended=true gelegentlich komplett oder enthält
	 * nur die E-Mail-Adresse) den Kundennamen über einen einzelnen Users-API-Aufruf,
	 * damit statt der User-ID immer Vor- und Nachname angezeigt werden können.
	 * Ergebnisse werden 1 Tag lang gecacht, da sich Nutzerdaten selten ändern.
	 */
	private function hydrate_missing_customers( $payments ) {
		foreach ( $payments as $payment ) {
			if ( self::has_customer_name( $payment ) || empty( $payment->user_id ) ) {
				continue;
			}

			$user_cache_key = 'fsd_user_' . (int) $payment->user_id;
			$user           = get_transient( $user_cache_key );

			if ( false === $user ) {
				$fetched = $this->api->get_user( $payment->user_id );
				$user    = is_wp_error( $fetched ) ? null : $fetched;
				set_transient( $user_cache_key, $user, DAY_IN_SECONDS );
			}

			if ( $user ) {
				$payment->user = $user;
			}
		}

		return $payments;
	}

	private static function has_customer_name( $payment ) {
		if ( empty( $payment->user ) ) {
			return false;
		}

		$first = isset( $payment->user->first ) ? trim( (string) $payment->user->first ) : '';
		$last  = isset( $payment->user->last ) ? trim( (string) $payment->user->last ) : '';

		return '' !== $first || '' !== $last;
	}

	private static function is_subscription_payment( $payment ) {
		return ! empty( $payment->subscription_id );
	}

	private static function is_refund( $payment ) {
		return ( isset( $payment->type ) && 'refund' === $payment->type ) || ( isset( $payment->gross ) && (float) $payment->gross < 0 );
	}

	private static function is_sandbox( $payment ) {
		return isset( $payment->environment ) && 1 === (int) $payment->environment;
	}

	private static function has_coupon( $payment ) {
		return ! empty( $payment->coupon_id );
	}

	private static function net_amount( $payment ) {
		$gross = isset( $payment->gross ) ? (float) $payment->gross : 0.0;
		$vat   = isset( $payment->vat ) ? (float) $payment->vat : 0.0;

		return $gross - $vat;
	}

	private static function gross_amount( $payment ) {
		return isset( $payment->gross ) ? (float) $payment->gross : 0.0;
	}

	private static function vat_amount( $payment ) {
		return isset( $payment->vat ) ? (float) $payment->vat : 0.0;
	}

	private static function field( $payment, $name ) {
		return ( isset( $payment->$name ) && '' !== $payment->$name ) ? (string) $payment->$name : '—';
	}

	private static function customer_label( $payment ) {
		$user = isset( $payment->user ) ? $payment->user : null;

		if ( $user ) {
			$first = isset( $user->first ) ? trim( (string) $user->first ) : '';
			$last  = isset( $user->last ) ? trim( (string) $user->last ) : '';
			$name  = trim( $first . ' ' . $last );
			$email = isset( $user->email ) ? (string) $user->email : '';

			if ( '' !== $name && '' !== $email ) {
				return array( $name, $email );
			}
			if ( '' !== $email ) {
				return array( $email, '' );
			}
		}

		if ( ! empty( $payment->user_id ) ) {
			return array(
				/* translators: %s: user ID */
				sprintf( __( 'Kunde #%s', 'freemius-dashboard' ), $payment->user_id ),
				'',
			);
		}

		return array( __( 'Unbekannt', 'freemius-dashboard' ), '' );
	}

	private static function plan_label( $payment ) {
		if ( isset( $payment->plan->title ) && '' !== $payment->plan->title ) {
			return $payment->plan->title;
		}
		if ( isset( $payment->plan->name ) && '' !== $payment->plan->name ) {
			return $payment->plan->name;
		}
		if ( ! empty( $payment->plan_id ) ) {
			/* translators: %s: plan ID */
			return sprintf( __( 'Plan #%s', 'freemius-dashboard' ), $payment->plan_id );
		}

		return '—';
	}

	/**
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 */
	private static function last_30_days_range() {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$from = $now->modify( '-29 days' )->setTime( 0, 0, 0 );
		$to   = $now->setTime( 0, 0, 0 )->modify( '+1 day' );

		return array( $from, $to );
	}

	/**
	 * Baut das leere 30-Tage-Gerüst (ein Eintrag je Kalendertag, Anzahl 0), das
	 * der Client beim ersten Chart-Batch erhält und anschließend mit den über
	 * mehrere Batches gesammelten Zählungen auffüllt.
	 */
	private static function day_skeleton( DateTimeInterface $from, DateTimeInterface $to ) {
		$tz     = wp_timezone();
		$cursor = $from instanceof DateTimeImmutable ? $from : DateTimeImmutable::createFromInterface( $from );
		$cursor = $cursor->setTimezone( $tz );
		$end    = $to instanceof DateTimeImmutable ? $to : DateTimeImmutable::createFromInterface( $to );
		$end    = $end->setTimezone( $tz );

		$days = array();
		while ( $cursor < $end ) {
			$days[] = array(
				'date'  => $cursor->format( 'Y-m-d' ),
				'label' => date_i18n( 'd.m.', $cursor->getTimestamp() ),
			);
			$cursor = $cursor->modify( '+1 day' );
		}

		return $days;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . FSD_Settings::PAGE_SLUG );

		if ( ! $this->api->is_configured() ) {
			echo '<div class="wrap fsd-wrap"><h1>' . esc_html__( 'Freemius Dashboard', 'freemius-dashboard' ) . '</h1>';
			printf(
				'<div class="fsd-card fsd-notice">%s <a href="%s">%s</a></div>',
				esc_html__( 'Bitte hinterlege zunächst deine Freemius API-Zugangsdaten.', 'freemius-dashboard' ),
				esc_url( $settings_url ),
				esc_html__( 'Zu den Einstellungen', 'freemius-dashboard' )
			);
			echo '</div>';
			return;
		}

		list( , , $ym ) = FSD_Month_Filter::get_selected_range();

		echo '<div class="wrap fsd-wrap" id="fsd-dashboard-app" data-ym="' . esc_attr( $ym ) . '">';
		echo '<h1 class="fsd-title">' . esc_html__( 'Freemius Dashboard', 'freemius-dashboard' ) . '</h1>';

		// Chart-Karte.
		echo '<div class="fsd-card">';
		echo '<h2 class="fsd-card__title">' . esc_html__( 'Käufe der letzten 30 Tage', 'freemius-dashboard' ) . '</h2>';
		echo '<div class="fsd-chart"><canvas id="fsd-chart-canvas" height="220"></canvas></div>';
		echo '<p class="fsd-loading" id="fsd-chart-loading">' . esc_html__( 'Lade Diagrammdaten …', 'freemius-dashboard' ) . '</p>';
		echo '<p class="fsd-notice fsd-notice--error" id="fsd-chart-error" style="display:none;"></p>';
		echo '</div>';

		// Filter + Tabelle.
		echo '<div class="fsd-card">';
		echo '<div class="fsd-toolbar">';
		echo '<h2 class="fsd-card__title">' . esc_html__( 'Käufe', 'freemius-dashboard' ) . '</h2>';
		FSD_Month_Filter::render( self::PAGE_SLUG, $ym );
		echo '</div>';

		echo '<p class="fsd-notice fsd-notice--error" id="fsd-table-error" style="display:none;"></p>';
		$this->render_table_shell();
		echo '<p class="fsd-loading" id="fsd-table-loading">' . esc_html__( 'Lade Käufe …', 'freemius-dashboard' ) . '</p>';

		echo '<div class="fsd-totals" id="fsd-totals" style="display:none;">';
		echo '<span class="fsd-totals__label">' . esc_html__( 'Einnahmen netto', 'freemius-dashboard' ) . '</span>';
		echo '<span id="fsd-totals-values"></span>';
		echo '</div>';

		echo '</div>'; // .fsd-card
		echo '</div>'; // .wrap
	}

	private function render_table_shell() {
		?>
		<div class="fsd-table-wrap">
			<table class="fsd-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Zahlungs-ID', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Datum', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Kunde', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Land', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Plan', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Typ', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Zahlungsart', 'freemius-dashboard' ); ?></th>
						<th class="fsd-table__amount"><?php esc_html_e( 'Brutto', 'freemius-dashboard' ); ?></th>
						<th class="fsd-table__amount"><?php esc_html_e( 'USt.', 'freemius-dashboard' ); ?></th>
						<th class="fsd-table__amount"><?php esc_html_e( 'Netto', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'USt-ID', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Gutschein-ID', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Externe ID', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Lizenz-ID', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Abo-ID', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Quelle', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Aktualisiert', 'freemius-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody id="fsd-table-body"></tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Rendert eine einzelne Käufe-Tabellenzeile. Wird pro AJAX-Batch für jede
	 * geladene Zahlung aufgerufen und die entstehende HTML-Zeile an den
	 * Client zurückgegeben – die Formatierung/Escaping bleibt damit an einer
	 * einzigen Stelle in PHP statt in JavaScript dupliziert zu werden.
	 */
	private function render_row( $payment ) {
		list( $name, $email ) = self::customer_label( $payment );
		$is_sub               = self::is_subscription_payment( $payment );
		$is_refund            = self::is_refund( $payment );
		$has_coupon           = self::has_coupon( $payment );
		$gross                = self::gross_amount( $payment );
		$vat                  = self::vat_amount( $payment );
		$net                  = self::net_amount( $payment );
		$currency             = isset( $payment->currency ) ? strtoupper( $payment->currency ) : '';
		$created              = ! empty( $payment->created ) ? mysql2date( 'd.m.Y H:i', $payment->created ) : '—';
		$updated              = ! empty( $payment->updated ) ? mysql2date( 'd.m.Y H:i', $payment->updated ) : '—';

		ob_start();
		?>
		<tr>
			<td><?php echo esc_html( self::field( $payment, 'id' ) ); ?></td>
			<td><?php echo esc_html( $created ); ?></td>
			<td>
				<div class="fsd-customer">
					<span class="fsd-customer__name"><?php echo esc_html( $name ); ?></span>
					<?php if ( $email ) : ?>
						<span class="fsd-customer__email"><?php echo esc_html( $email ); ?></span>
					<?php endif; ?>
				</div>
			</td>
			<td><?php echo esc_html( strtoupper( self::field( $payment, 'country_code' ) ) ); ?></td>
			<td><?php echo esc_html( self::plan_label( $payment ) ); ?></td>
			<td>
				<span class="fsd-chip <?php echo $is_sub ? 'fsd-chip--sub' : 'fsd-chip--lifetime'; ?>">
					<?php echo $is_sub ? esc_html__( 'Abo', 'freemius-dashboard' ) : esc_html__( 'Lifetime', 'freemius-dashboard' ); ?>
				</span>
				<?php if ( $is_refund ) : ?>
					<span class="fsd-chip fsd-chip--refund"><?php esc_html_e( 'Erstattung', 'freemius-dashboard' ); ?></span>
				<?php endif; ?>
				<?php if ( $has_coupon ) : ?>
					<span class="fsd-chip fsd-chip--coupon"><?php esc_html_e( 'Gutschein', 'freemius-dashboard' ); ?></span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( self::field( $payment, 'gateway' ) ); ?></td>
			<td class="fsd-table__amount">
				<?php echo esc_html( number_format_i18n( $gross, 2 ) . ' ' . $currency ); ?>
			</td>
			<td class="fsd-table__amount">
				<?php echo esc_html( number_format_i18n( $vat, 2 ) . ' ' . $currency ); ?>
			</td>
			<td class="fsd-table__amount <?php echo $net < 0 ? 'fsd-amount--negative' : ''; ?>">
				<?php echo esc_html( number_format_i18n( $net, 2 ) . ' ' . $currency ); ?>
			</td>
			<td><?php echo esc_html( self::field( $payment, 'vat_id' ) ); ?></td>
			<td><?php echo esc_html( self::field( $payment, 'coupon_id' ) ); ?></td>
			<td><?php echo esc_html( self::field( $payment, 'external_id' ) ); ?></td>
			<td><?php echo esc_html( self::field( $payment, 'license_id' ) ); ?></td>
			<td><?php echo esc_html( self::field( $payment, 'subscription_id' ) ); ?></td>
			<td><?php echo esc_html( self::field( $payment, 'source' ) ); ?></td>
			<td><?php echo esc_html( $updated ); ?></td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	private function render_totals_html( array $totals ) {
		if ( empty( $totals ) ) {
			return '<span class="fsd-totals__value">0,00</span>';
		}

		$html = '';
		foreach ( $totals as $currency => $sum ) {
			$html .= '<span class="fsd-totals__value">' . esc_html( number_format_i18n( $sum, 2 ) . ' ' . $currency ) . '</span>';
		}

		return $html;
	}

	/**
	 * AJAX-Handler: liefert eine einzelne Seite Zahlungen (Tabelle oder Chart)
	 * als JSON zurück. Wird von assets/js/fsd-dashboard.js wiederholt
	 * aufgerufen, bis has_more=false ist.
	 */
	public function ajax_get_page() {
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		$page  = isset( $_POST['page'] ) ? max( 0, (int) $_POST['page'] ) : 0;

		if ( ! in_array( $scope, array( 'table', 'chart' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Ungültige Anfrage.', 'freemius-dashboard' ) ) );
		}

		if ( ! $this->api->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Bitte hinterlege zunächst deine Freemius API-Zugangsdaten.', 'freemius-dashboard' ) ) );
		}

		if ( 'table' === $scope ) {
			$ym = isset( $_POST['ym'] ) ? sanitize_text_field( wp_unslash( $_POST['ym'] ) ) : '';
			list( $from, $to ) = FSD_Month_Filter::range_for_ym( $ym );
		} else {
			list( $from, $to ) = self::last_30_days_range();
		}

		$result = $this->get_cached_page( $from, $to, $page );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$payments = $result['payments'];

		if ( 'table' === $scope ) {
			usort(
				$payments,
				static function ( $a, $b ) {
					return strcmp( (string) ( $b->created ?? '' ), (string) ( $a->created ?? '' ) );
				}
			);

			$rows_html = '';
			$nets      = array();

			foreach ( $payments as $payment ) {
				$rows_html .= $this->render_row( $payment );
				$nets[]     = array(
					'currency' => isset( $payment->currency ) ? strtoupper( $payment->currency ) : '—',
					'net'      => self::net_amount( $payment ),
				);
			}

			wp_send_json_success(
				array(
					'rows_html' => $rows_html,
					'nets'      => $nets,
					'has_more'  => $result['has_more'],
				)
			);
		}

		// scope === 'chart'
		$tz     = wp_timezone();
		$counts = array();

		foreach ( $payments as $payment ) {
			if ( self::is_refund( $payment ) || empty( $payment->created ) ) {
				continue;
			}

			try {
				$created = new DateTime( $payment->created, new DateTimeZone( 'UTC' ) );
				$created->setTimezone( $tz );
			} catch ( Exception $e ) {
				continue;
			}

			$day             = $created->format( 'Y-m-d' );
			$counts[ $day ]  = isset( $counts[ $day ] ) ? $counts[ $day ] + 1 : 1;
		}

		$response = array(
			'counts'   => $counts,
			'has_more' => $result['has_more'],
		);

		if ( 0 === $page ) {
			$response['days'] = self::day_skeleton( $from, $to );
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX-Handler: formatiert die vom Client über alle Batches aufsummierten
	 * Netto-Summen je Währung. Die Summierung selbst passiert im Client (reine
	 * Arithmetik), die lokalisierte Zahlenformatierung (number_format_i18n)
	 * bleibt dadurch serverseitig einheitlich.
	 */
	public function ajax_get_totals() {
		$raw    = isset( $_POST['totals'] ) ? wp_unslash( $_POST['totals'] ) : '';
		$parsed = json_decode( (string) $raw, true );

		$totals = array();
		if ( is_array( $parsed ) ) {
			foreach ( $parsed as $currency => $sum ) {
				$currency               = sanitize_text_field( (string) $currency );
				$totals[ $currency ] = (float) $sum;
			}
		}

		wp_send_json_success( array( 'html' => $this->render_totals_html( $totals ) ) );
	}
}
