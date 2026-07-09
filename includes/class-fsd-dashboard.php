<?php
/**
 * Dashboard-Seite: Käufe-Tabelle (Kalendermonat), Netto-Einnahmen, Chart der letzten 30 Tage.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Dashboard {

	const PAGE_SLUG   = 'fsd-dashboard';
	const CACHE_TTL   = 5 * MINUTE_IN_SECONDS;

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

		// Sandbox-/Test-Käufe (Freemius "environment" = 1) sind keine echten Verkäufe
		// und dürfen weder in der Tabelle noch in den Summen auftauchen.
		$payments = array_values(
			array_filter(
				$payments,
				static function ( $payment ) {
					return ! self::is_sandbox( $payment );
				}
			)
		);

		$payments = $this->hydrate_missing_customers( $payments );

		set_transient( $cache_key, $payments, self::CACHE_TTL );

		return $payments;
	}

	/**
	 * Ergänzt bei Payments ohne eingebettetes user-Objekt (kommt trotz extended=true
	 * gelegentlich vor) den Kundennamen über einen einzelnen Users-API-Aufruf.
	 * Ergebnisse werden 1 Tag lang gecacht, da sich Nutzerdaten selten ändern.
	 */
	private function hydrate_missing_customers( $payments ) {
		foreach ( $payments as $payment ) {
			if ( ! empty( $payment->user ) || empty( $payment->user_id ) ) {
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

	private function build_last_30_days_series( $from, $to, $payments ) {
		$tz     = wp_timezone();
		$counts = array();

		$cursor = $from;
		while ( $cursor < $to ) {
			$counts[ $cursor->format( 'Y-m-d' ) ] = 0;
			$cursor                                = $cursor->modify( '+1 day' );
		}

		if ( is_array( $payments ) ) {
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

				$day = $created->format( 'Y-m-d' );
				if ( isset( $counts[ $day ] ) ) {
					++$counts[ $day ];
				}
			}
		}

		$series = array();
		foreach ( $counts as $day => $count ) {
			$series[] = array(
				'date'  => $day,
				'label' => date_i18n( 'd.m.', strtotime( $day ) ),
				'count' => $count,
			);
		}

		return $series;
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

		list( $from, $to, $ym ) = FSD_Month_Filter::get_selected_range();

		$payments = $this->get_cached_payments( $from, $to );

		$now       = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$from_30   = $now->modify( '-29 days' )->setTime( 0, 0, 0 );
		$to_30     = $now->setTime( 0, 0, 0 )->modify( '+1 day' );
		$payments_30 = $this->get_cached_payments( $from_30, $to_30 );

		echo '<div class="wrap fsd-wrap">';
		echo '<h1 class="fsd-title">' . esc_html__( 'Freemius Dashboard', 'freemius-dashboard' ) . '</h1>';

		if ( is_wp_error( $payments ) ) {
			printf( '<div class="fsd-card fsd-notice fsd-notice--error">%s</div>', esc_html( $payments->get_error_message() ) );
		}

		// Chart-Karte.
		echo '<div class="fsd-card">';
		echo '<h2 class="fsd-card__title">' . esc_html__( 'Käufe der letzten 30 Tage', 'freemius-dashboard' ) . '</h2>';
		if ( is_wp_error( $payments_30 ) ) {
			printf( '<p class="fsd-notice fsd-notice--error">%s</p>', esc_html( $payments_30->get_error_message() ) );
		} else {
			$series = $this->build_last_30_days_series( $from_30, $to_30, $payments_30 );
			echo '<div class="fsd-chart"><canvas id="fsd-chart-canvas" height="220"></canvas></div>';
			echo '<script type="application/json" id="fsd-chart-data">' . wp_json_encode( $series ) . '</script>';
		}
		echo '</div>';

		// Filter + Tabelle.
		echo '<div class="fsd-card">';
		echo '<div class="fsd-toolbar">';
		echo '<h2 class="fsd-card__title">' . esc_html__( 'Käufe', 'freemius-dashboard' ) . '</h2>';
		FSD_Month_Filter::render( self::PAGE_SLUG, $ym );
		echo '</div>';

		if ( ! is_wp_error( $payments ) ) {
			$this->render_table( $payments );
			$this->render_totals( $payments );
		}
		echo '</div>';

		echo '</div>';
	}

	private function render_table( $payments ) {
		usort(
			$payments,
			static function ( $a, $b ) {
				return strcmp( (string) ( $b->created ?? '' ), (string) ( $a->created ?? '' ) );
			}
		);
		?>
		<div class="fsd-table-wrap">
			<table class="fsd-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Datum', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Kunde', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Plan', 'freemius-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Typ', 'freemius-dashboard' ); ?></th>
						<th class="fsd-table__amount"><?php esc_html_e( 'Betrag netto', 'freemius-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $payments ) ) : ?>
						<tr>
							<td colspan="5" class="fsd-table__empty"><?php esc_html_e( 'Keine Käufe in diesem Monat.', 'freemius-dashboard' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $payments as $payment ) : ?>
							<?php
							list( $name, $email ) = self::customer_label( $payment );
							$is_sub               = self::is_subscription_payment( $payment );
							$is_refund            = self::is_refund( $payment );
							$has_coupon           = self::has_coupon( $payment );
							$net                  = self::net_amount( $payment );
							$currency             = isset( $payment->currency ) ? strtoupper( $payment->currency ) : '';
							$created              = ! empty( $payment->created ) ? mysql2date( 'd.m.Y H:i', $payment->created ) : '—';
							?>
							<tr>
								<td><?php echo esc_html( $created ); ?></td>
								<td>
									<div class="fsd-customer">
										<span class="fsd-customer__name"><?php echo esc_html( $name ); ?></span>
										<?php if ( $email ) : ?>
											<span class="fsd-customer__email"><?php echo esc_html( $email ); ?></span>
										<?php endif; ?>
									</div>
								</td>
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
								<td class="fsd-table__amount <?php echo $net < 0 ? 'fsd-amount--negative' : ''; ?>">
									<?php echo esc_html( number_format_i18n( $net, 2 ) . ' ' . $currency ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_totals( $payments ) {
		$totals = array();

		foreach ( $payments as $payment ) {
			$currency = isset( $payment->currency ) ? strtoupper( $payment->currency ) : '—';
			if ( ! isset( $totals[ $currency ] ) ) {
				$totals[ $currency ] = 0.0;
			}
			$totals[ $currency ] += self::net_amount( $payment );
		}

		echo '<div class="fsd-totals">';
		echo '<span class="fsd-totals__label">' . esc_html__( 'Einnahmen netto', 'freemius-dashboard' ) . '</span>';

		if ( empty( $totals ) ) {
			echo '<span class="fsd-totals__value">0,00</span>';
		} else {
			foreach ( $totals as $currency => $sum ) {
				echo '<span class="fsd-totals__value">' . esc_html( number_format_i18n( $sum, 2 ) . ' ' . $currency ) . '</span>';
			}
		}

		echo '</div>';
	}
}
