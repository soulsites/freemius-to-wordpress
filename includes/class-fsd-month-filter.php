<?php
/**
 * Gemeinsame Kalendermonat-Filterlogik für Seiten mit monatsbasierten Freemius-Daten.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Month_Filter {

	/**
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: string} [from, to, ym]
	 */
	public static function get_selected_range() {
		$tz = wp_timezone();
		$ym = isset( $_GET['fsd_month'] ) ? sanitize_text_field( wp_unslash( $_GET['fsd_month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $ym ) ) {
			$ym = wp_date( 'Y-m' );
		}

		try {
			$from_local = new DateTimeImmutable( $ym . '-01 00:00:00', $tz );
		} catch ( Exception $e ) {
			$from_local = new DateTimeImmutable( wp_date( 'Y-m' ) . '-01 00:00:00', $tz );
			$ym         = $from_local->format( 'Y-m' );
		}

		$to_local = $from_local->modify( '+1 month' );

		$from_utc = $from_local->setTimezone( new DateTimeZone( 'UTC' ) );
		$to_utc   = $to_local->setTimezone( new DateTimeZone( 'UTC' ) );

		return array( $from_utc, $to_utc, $ym );
	}

	public static function render( $page_slug, $selected_ym ) {
		echo '<form method="get" class="fsd-filter">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '" />';
		echo '<select name="fsd_month" class="fsd-select" onchange="this.form.submit()">';

		$tz     = wp_timezone();
		$cursor = new DateTimeImmutable( 'first day of this month', $tz );

		for ( $i = 0; $i < 12; $i++ ) {
			$value = $cursor->format( 'Y-m' );
			$label = date_i18n( 'F Y', $cursor->getTimestamp() );
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $selected_ym, $value, false ),
				esc_html( $label )
			);
			$cursor = $cursor->modify( '-1 month' );
		}

		echo '</select>';
		echo '<noscript><button type="submit" class="fsd-btn fsd-btn--tonal">' . esc_html__( 'Anzeigen', 'freemius-dashboard' ) . '</button></noscript>';
		echo '</form>';
	}
}
