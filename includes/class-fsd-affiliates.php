<?php
/**
 * Affiliates-Seite: Partner-Tabelle mit Provisionssätzen und im gewählten
 * Kalendermonat verdienter Provision.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Affiliates {

	const PAGE_SLUG = 'fsd-affiliates';
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/** @var FSD_Api */
	private $api;

	public function __construct() {
		$settings  = FSD_Settings::get_settings();
		$this->api = new FSD_Api(
			$settings['scope_id'],
			$settings['public_key'],
			$settings['secret_key'],
			$settings['product_id']
		);
	}

	private function get_cached_affiliates() {
		$cached = get_transient( 'fsd_affiliates' );
		if ( false !== $cached ) {
			return $cached;
		}

		$affiliates = $this->api->get_affiliates();

		if ( is_wp_error( $affiliates ) ) {
			return $affiliates;
		}

		set_transient( 'fsd_affiliates', $affiliates, self::CACHE_TTL );

		return $affiliates;
	}

	private function get_cached_terms() {
		$cached = get_transient( 'fsd_affiliate_terms' );
		if ( false !== $cached ) {
			return $cached;
		}

		$terms = $this->api->get_affiliate_terms();

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		set_transient( 'fsd_affiliate_terms', $terms, self::CACHE_TTL );

		return $terms;
	}

	private function get_cached_payments( DateTimeInterface $from, DateTimeInterface $to ) {
		$cache_key = 'fsd_payments_' . md5( $from->format( 'c' ) . '|' . $to->format( 'c' ) );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$payments = $this->api->get_payments( $from, $to );

		if ( is_wp_error( $payments ) ) {
			return $payments;
		}

		set_transient( $cache_key, $payments, self::CACHE_TTL );

		return $payments;
	}

	// Freemius liefert die Standard-Provision je nach API-Version unter leicht
	// unterschiedlichen Feldnamen – wir prüfen die gängigen Varianten der Reihe nach.
	private static function default_commission_rate( $terms ) {
		if ( is_wp_error( $terms ) || ! is_object( $terms ) ) {
			return null;
		}

		foreach ( array( 'commission', 'revenue_share', 'commission_percentage' ) as $field ) {
			if ( isset( $terms->$field ) && '' !== $terms->$field ) {
				return (float) $terms->$field;
			}
		}

		return null;
	}

	private static function commission_rate( $affiliate, $default_rate ) {
		if ( isset( $affiliate->custom_commission ) && '' !== $affiliate->custom_commission && null !== $affiliate->custom_commission ) {
			return (float) $affiliate->custom_commission;
		}

		return $default_rate;
	}

	private static function affiliate_label( $affiliate ) {
		$user = isset( $affiliate->user ) ? $affiliate->user : null;

		if ( $user ) {
			$first = isset( $user->first ) ? trim( (string) $user->first ) : '';
			$last  = isset( $user->last ) ? trim( (string) $user->last ) : '';
			$name  = trim( $first . ' ' . $last );
			$email = isset( $user->email ) ? (string) $user->email : '';

			if ( '' !== $name ) {
				return array( $name, $email );
			}
			if ( '' !== $email ) {
				return array( $email, '' );
			}
		}

		return array(
			/* translators: %s: affiliate ID */
			sprintf( __( 'Partner #%s', 'freemius-dashboard' ), isset( $affiliate->id ) ? $affiliate->id : '?' ),
			'',
		);
	}

	private static function status_label( $status ) {
		$map = array(
			'active'    => array( __( 'Aktiv', 'freemius-dashboard' ), 'fsd-chip--active' ),
			'pending'   => array( __( 'Ausstehend', 'freemius-dashboard' ), 'fsd-chip--pending' ),
			'blocked'   => array( __( 'Gesperrt', 'freemius-dashboard' ), 'fsd-chip--blocked' ),
			'suspended' => array( __( 'Suspendiert', 'freemius-dashboard' ), 'fsd-chip--blocked' ),
			'rejected'  => array( __( 'Abgelehnt', 'freemius-dashboard' ), 'fsd-chip--blocked' ),
		);

		if ( isset( $map[ $status ] ) ) {
			return $map[ $status ];
		}

		return array( $status ? ucfirst( $status ) : __( 'Unbekannt', 'freemius-dashboard' ), 'fsd-chip--lifetime' );
	}

	/**
	 * Summiert Netto-Umsatz & Verkäufe je Affiliate-ID für den gewählten Monat.
	 *
	 * @return array<int, array{count:int, net:float, currency:string}>
	 */
	private static function aggregate_payments_by_affiliate( $payments ) {
		$stats = array();

		if ( ! is_array( $payments ) ) {
			return $stats;
		}

		foreach ( $payments as $payment ) {
			if ( empty( $payment->affiliate_id ) ) {
				continue;
			}

			$affiliate_id = (int) $payment->affiliate_id;

			if ( ! isset( $stats[ $affiliate_id ] ) ) {
				$stats[ $affiliate_id ] = array(
					'count'    => 0,
					'net'      => 0.0,
					'currency' => isset( $payment->currency ) ? strtoupper( $payment->currency ) : '',
				);
			}

			$gross = isset( $payment->gross ) ? (float) $payment->gross : 0.0;
			$vat   = isset( $payment->vat ) ? (float) $payment->vat : 0.0;

			++$stats[ $affiliate_id ]['count'];
			$stats[ $affiliate_id ]['net'] += ( $gross - $vat );
		}

		return $stats;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . FSD_Settings::PAGE_SLUG );

		if ( ! $this->api->is_configured() ) {
			echo '<div class="wrap fsd-wrap"><h1 class="fsd-title">' . esc_html__( 'Freemius – Affiliates', 'freemius-dashboard' ) . '</h1>';
			printf(
				'<div class="fsd-card fsd-notice">%s <a href="%s">%s</a></div>',
				esc_html__( 'Bitte hinterlege zunächst deine Freemius API-Zugangsdaten.', 'freemius-dashboard' ),
				esc_url( $settings_url ),
				esc_html__( 'Zu den Einstellungen', 'freemius-dashboard' )
			);
			echo '</div>';
			return;
		}

		list( $from, $to, $ym ) = FSD_Month_Filter::get_selected_range();

		$affiliates = $this->get_cached_affiliates();
		$terms      = $this->get_cached_terms();
		$payments   = $this->get_cached_payments( $from, $to );

		echo '<div class="wrap fsd-wrap">';
		echo '<h1 class="fsd-title">' . esc_html__( 'Freemius – Affiliates', 'freemius-dashboard' ) . '</h1>';

		if ( is_wp_error( $affiliates ) ) {
			printf( '<div class="fsd-card fsd-notice fsd-notice--error">%s</div>', esc_html( $affiliates->get_error_message() ) );
			echo '</div>';
			return;
		}

		echo '<div class="fsd-card">';
		echo '<div class="fsd-toolbar">';
		echo '<h2 class="fsd-card__title">' . esc_html__( 'Affiliate-Partner', 'freemius-dashboard' ) . '</h2>';
		FSD_Month_Filter::render( self::PAGE_SLUG, $ym );
		echo '</div>';

		if ( is_wp_error( $payments ) ) {
			printf( '<p class="fsd-notice fsd-notice--error">%s</p>', esc_html( $payments->get_error_message() ) );
			$payments = array();
		}

		$default_rate = self::default_commission_rate( $terms );
		$stats        = self::aggregate_payments_by_affiliate( $payments );

		$this->render_table( $affiliates, $stats, $default_rate );

		echo '</div>';
		echo '</div>';
	}

	private function render_table( $affiliates, $stats, $default_rate ) {
		$affiliates = is_array( $affiliates ) ? $affiliates : array();

		usort(
			$affiliates,
			static function ( $a, $b ) {
				list( $name_a ) = self::affiliate_label( $a );
				list( $name_b ) = self::affiliate_label( $b );
				return strcasecmp( $name_a, $name_b );
			}
		);
		?>
		<div class="fsd-table-wrap">
			<table class="fsd-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Partner', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Status', 'freemius-dashboard' ); ?></th>
						<th class="fsd-table__amount"><?php esc_html_e( 'Provision', 'freemius-dashboard' ); ?></th>
						<th class="fsd-table__amount"><?php esc_html_e( 'Verkäufe (Monat)', 'freemius-dashboard' ); ?></th>
						<th class="fsd-table__amount"><?php esc_html_e( 'Provision verdient (Monat)', 'freemius-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $affiliates ) ) : ?>
						<tr>
							<td colspan="5" class="fsd-table__empty"><?php esc_html_e( 'Keine Affiliate-Partner gefunden.', 'freemius-dashboard' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $affiliates as $affiliate ) : ?>
							<?php
							list( $name, $email )               = self::affiliate_label( $affiliate );
							list( $status_text, $status_class ) = self::status_label( isset( $affiliate->status ) ? $affiliate->status : '' );
							$rate                                = self::commission_rate( $affiliate, $default_rate );
							$affiliate_id                        = isset( $affiliate->id ) ? (int) $affiliate->id : 0;
							$row_stats                            = isset( $stats[ $affiliate_id ] ) ? $stats[ $affiliate_id ] : null;
							$earned                                = $row_stats ? $row_stats['net'] * ( ( null !== $rate ? $rate : 0 ) / 100 ) : 0.0;
							?>
							<tr>
								<td>
									<div class="fsd-customer">
										<span class="fsd-customer__name"><?php echo esc_html( $name ); ?></span>
										<?php if ( $email ) : ?>
											<span class="fsd-customer__email"><?php echo esc_html( $email ); ?></span>
										<?php endif; ?>
									</div>
								</td>
								<td><span class="fsd-chip <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_text ); ?></span></td>
								<td class="fsd-table__amount">
									<?php echo null !== $rate ? esc_html( number_format_i18n( $rate, 1 ) . ' %' ) : '—'; ?>
								</td>
								<td class="fsd-table__amount">
									<?php echo esc_html( (string) ( $row_stats ? $row_stats['count'] : 0 ) ); ?>
								</td>
								<td class="fsd-table__amount">
									<?php
									echo $row_stats
										? esc_html( number_format_i18n( $earned, 2 ) . ' ' . $row_stats['currency'] )
										: esc_html( number_format_i18n( 0, 2 ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
