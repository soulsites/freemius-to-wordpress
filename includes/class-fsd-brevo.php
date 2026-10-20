<?php
/**
 * Brevo-Integration: Einstellungen, Webhook-Verarbeitung (Käufer nach jedem
 * Kauf in Brevo eintragen), Protokoll, Kontakt-Status fürs Dashboard und
 * Abgleich bestehender Käufer.
 *
 * Werte werden vor dem Senden anhand der Attribut-Definitionen des
 * Brevo-Kontos typisiert (Text, Zahl, Datum, Boolean, Kategorie). Attribute,
 * die im Brevo-Konto nicht existieren, werden übersprungen und protokolliert.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_Brevo {
	const PAGE_SLUG        = 'fsd-brevo';
	const GROUP            = 'fsd_brevo_group';
	const OPTION_KEY       = 'fsd_brevo_settings';
	const ATTRIBUTES_CACHE = 'fsd_brevo_attributes';
	const PRESENCE_PREFIX  = 'fsd_brevo_presence_';

	const PRESENCE_YES   = 'yes';
	const PRESENCE_NO    = 'no';
	const PRESENCE_ERROR = 'error';

	/** @var FSD_Brevo_Log */
	private $log;

	/** @var FSD_Brevo_Api|null */
	private $api;

	public function __construct() {
		$this->log = new FSD_Brevo_Log();
	}

	/** Synchronisierbare Brevo-Attribute (Spalten der Brevo-Kontaktliste). */
	public static function attributes() {
		return array(
			'FIRSTNAME'          => 'Vorname',
			'LASTNAME'           => 'Nachname',
			'MARKETING_MAILS'    => 'Marketing-Mails (YES/NO)',
			'FREEMIUS_ID'        => 'Freemius-Nutzer-ID',
			'FREEMIUS_UMSATZ'    => 'Freemius-Umsatz (brutto, kumuliert)',
			'UMSATZ_GESAMT'      => 'Umsatz gesamt (brutto, kumuliert)',
			'ANZAHL_KAEUFE'      => 'Anzahl Käufe',
			'LETZTER_KAUF_DATUM' => 'Letzter Kauf',
			'ERSTKAUF_DATUM'     => 'Erstkauf',
			'LETZTES_PRODUKT'    => 'Letztes Produkt / Plan',
			'BILLINGCYCLE'       => 'Abrechnungszyklus',
			'LIZENZ'             => 'Lizenz-ID',
			'KUNDENSTATUS'       => 'Kundenstatus (Kunde/Free)',
			'BEZAHLT'            => 'Bezahlt (YES/NO)',
			'ERSTATTUNG'         => 'Erstattung (YES/NO)',
			'GUTSCHEIN_GENUTZT'  => 'Gutschein genutzt (YES/NO)',
			'AFFILIATE_ID'       => 'Affiliate-ID',
			'LAND'               => 'Land',
			'RESELLER'           => 'Reseller / Quelle',
		);
	}

	public static function defaults() {
		return array(
			'enabled'    => false,
			'api_key'    => '',
			'list_ids'   => '',
			'reseller'   => 'Freemius',
			'attributes' => array_keys( self::attributes() ),
		);
	}

	public static function get_settings() {
		$value = FSD_Settings::get_unfiltered_option( self::OPTION_KEY );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	public static function is_active() {
		$s = self::get_settings();
		return ! empty( $s['enabled'] ) && '' !== $s['api_key'];
	}

	public function register() {
		register_setting( self::GROUP, self::OPTION_KEY, array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize' ), 'default' => self::defaults() ) );
	}

	public function sanitize( $input ) {
		$old = self::get_settings();
		$out = self::defaults();

		$out['enabled']  = ! empty( $input['enabled'] );
		$out['api_key']  = ! empty( $input['api_key'] ) ? sanitize_text_field( wp_unslash( $input['api_key'] ) ) : $old['api_key'];
		$out['list_ids'] = isset( $input['list_ids'] ) ? implode( ',', self::parse_list_ids( wp_unslash( $input['list_ids'] ) ) ) : '';
		$out['reseller'] = isset( $input['reseller'] ) ? sanitize_text_field( wp_unslash( $input['reseller'] ) ) : $out['reseller'];

		$allowed           = array_keys( self::attributes() );
		$selected          = isset( $input['attributes'] ) ? array_map( 'sanitize_key', (array) $input['attributes'] ) : array();
		$out['attributes'] = array_values( array_intersect( $allowed, array_map( 'strtoupper', $selected ) ) );

		delete_transient( self::ATTRIBUTES_CACHE );
		return $out;
	}

	private static function parse_list_ids( $value ) {
		return array_values( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', (string) $value ) ) ) );
	}

	private function api() {
		if ( null === $this->api ) {
			$this->api = new FSD_Brevo_Api( self::get_settings()['api_key'] );
		}
		return $this->api;
	}

	/* --------------------------------------------------------------------
	 * Webhook-Verarbeitung (asynchron per WP-Cron, siehe FSD_Webhook).
	 * ------------------------------------------------------------------ */

	public function handle_event( $event ) {
		if ( ! self::is_active() || ! is_object( $event ) ) {
			return;
		}
		$type    = isset( $event->type ) ? (string) $event->type : '';
		$objects = isset( $event->objects ) ? $event->objects : new stdClass();
		$user    = isset( $objects->user ) ? $objects->user : null;
		$payment = isset( $objects->payment ) ? $objects->payment : null;
		$pay_id  = ( $payment && ! empty( $payment->id ) ) ? (string) $payment->id : '';

		if ( ! $user || empty( $user->email ) ) {
			$this->log->add( FSD_Brevo_Log::STATUS_ERROR, '', $type, 'Event enthält keine Käufer-E-Mail.', $pay_id );
			return;
		}
		$email = strtolower( (string) $user->email );

		if ( 'payment.created' === $type ) {
			$this->sync_payment( $email, $user, $payment, $objects );
			return;
		}

		// Reine Einwilligungs-Events: nur MARKETING_MAILS aktualisieren.
		$consent = 'user.marketing.opted_in' === $type ? 'YES' : 'NO';
		$result  = $this->push( $email, array( 'MARKETING_MAILS' => $consent ), false );
		$this->log_result( $result, $email, $type, 'MARKETING_MAILS = ' . $consent );
	}

	private function sync_payment( $email, $user, $payment, $objects ) {
		$pay_id = ( $payment && ! empty( $payment->id ) ) ? (string) $payment->id : '';

		if ( ! $payment ) {
			$this->log->add( FSD_Brevo_Log::STATUS_ERROR, $email, 'payment.created', 'Event enthält keine Zahlung.' );
			return;
		}
		if ( isset( $payment->environment ) && 1 === (int) $payment->environment ) {
			$this->log->add( FSD_Brevo_Log::STATUS_SKIPPED, $email, 'payment.created', 'Sandbox-Zahlung – nicht übertragen.', $pay_id );
			return;
		}
		// Freemius stellt Webhooks ggf. mehrfach zu – Zahlungen nur einmal aufsummieren.
		if ( $this->log->is_payment_synced( $pay_id ) ) {
			$this->log->add( FSD_Brevo_Log::STATUS_SKIPPED, $email, 'payment.created', 'Zahlung wurde bereits übertragen.', $pay_id );
			return;
		}

		$contact = $this->api()->get_contact( $email );
		if ( is_wp_error( $contact ) ) {
			$this->log->add( FSD_Brevo_Log::STATUS_ERROR, $email, 'payment.created', $contact->get_error_message(), $pay_id );
			return;
		}
		$current = ( $contact && isset( $contact->attributes ) ) ? (array) $contact->attributes : array();
		$values  = $this->values_from_payment( $user, $payment, $objects, $current, null === $contact );
		$result  = $this->push( $email, $values, true );

		$this->log_result( $result, $email, 'payment.created', ( null === $contact ? 'Kontakt angelegt' : 'Kontakt aktualisiert' ), $pay_id );
	}

	/**
	 * Berechnet die Attributwerte für eine einzelne Zahlung auf Basis der
	 * aktuellen Brevo-Werte (Umsatz und Anzahl werden aufsummiert).
	 */
	private function values_from_payment( $user, $payment, $objects, $current, $is_new ) {
		$gross     = isset( $payment->gross ) ? (float) $payment->gross : 0.0;
		$is_refund = ( isset( $payment->type ) && 'refund' === $payment->type ) || $gross < 0;
		$date      = self::date_of( $payment );
		$total     = round( (float) ( $current['UMSATZ_GESAMT'] ?? 0 ) + $gross, 2 );

		return array(
			'FIRSTNAME'          => isset( $user->first ) ? (string) $user->first : '',
			'LASTNAME'           => isset( $user->last ) ? (string) $user->last : '',
			'FREEMIUS_ID'        => isset( $user->id ) ? (string) $user->id : ( isset( $payment->user_id ) ? (string) $payment->user_id : '' ),
			'FREEMIUS_UMSATZ'    => round( (float) ( $current['FREEMIUS_UMSATZ'] ?? 0 ) + $gross, 2 ),
			'UMSATZ_GESAMT'      => $total,
			'ANZAHL_KAEUFE'      => (int) ( $current['ANZAHL_KAEUFE'] ?? 0 ) + ( $is_refund ? 0 : 1 ),
			'LETZTER_KAUF_DATUM' => $is_refund ? '' : $date,
			'ERSTKAUF_DATUM'     => empty( $current['ERSTKAUF_DATUM'] ) && ! $is_refund ? $date : '',
			'LETZTES_PRODUKT'    => $is_refund ? '' : self::product_label( $objects ),
			'BILLINGCYCLE'       => self::billing_cycle( $payment, $objects ),
			'LIZENZ'             => isset( $payment->license_id ) ? (string) $payment->license_id : '',
			'KUNDENSTATUS'       => $total > 0 ? 'Kunde' : 'Free',
			'BEZAHLT'            => $total > 0 ? 'YES' : 'NO',
			'ERSTATTUNG'         => $is_refund ? 'YES' : ( $is_new ? 'NO' : '' ),
			'GUTSCHEIN_GENUTZT'  => ! empty( $payment->coupon_id ) ? 'YES' : ( $is_new ? 'NO' : '' ),
			'AFFILIATE_ID'       => ! empty( $payment->affiliate_id ) ? (string) $payment->affiliate_id : '',
			'LAND'               => isset( $payment->country_code ) ? strtoupper( (string) $payment->country_code ) : '',
			'RESELLER'           => self::get_settings()['reseller'],
			'MARKETING_MAILS'    => self::consent_value( $user, $is_new ),
		);
	}

	/** YES/NO aus Freemius "is_marketing_allowed"; unbekannt => bei neuen Kontakten NO, sonst unverändert. */
	private static function consent_value( $user, $is_new ) {
		if ( isset( $user->is_marketing_allowed ) && null !== $user->is_marketing_allowed ) {
			return $user->is_marketing_allowed ? 'YES' : 'NO';
		}
		return $is_new ? 'NO' : '';
	}

	private static function date_of( $payment ) {
		if ( empty( $payment->created ) ) {
			return gmdate( 'Y-m-d' );
		}
		$time = strtotime( $payment->created . ' UTC' );
		return $time ? gmdate( 'Y-m-d', $time ) : gmdate( 'Y-m-d' );
	}

	private static function product_label( $objects ) {
		$product = isset( $objects->product->title ) ? (string) $objects->product->title : '';
		$plan    = isset( $objects->plan->title ) ? (string) $objects->plan->title : ( isset( $objects->plan->name ) ? (string) $objects->plan->name : '' );
		return trim( $product . ( '' !== $product && '' !== $plan ? ' – ' : '' ) . $plan );
	}

	private static function billing_cycle( $payment, $objects ) {
		if ( empty( $payment->subscription_id ) ) {
			return 'Lifetime';
		}
		$cycle = isset( $objects->subscription->billing_cycle ) ? (int) $objects->subscription->billing_cycle : 0;
		if ( 1 === $cycle ) {
			return 'Monatlich';
		}
		if ( 12 === $cycle ) {
			return 'Jährlich';
		}
		return 'Abo';
	}

	/* --------------------------------------------------------------------
	 * Übertragung
	 * ------------------------------------------------------------------ */

	/**
	 * Filtert, typisiert und überträgt Attributwerte.
	 *
	 * @param bool $add_to_lists Kontakt den konfigurierten Listen hinzufügen.
	 *
	 * @return array|WP_Error Liste übersprungener Attribute bei Erfolg.
	 */
	private function push( $email, $values, $add_to_lists ) {
		$s        = self::get_settings();
		$enabled  = array_flip( (array) $s['attributes'] );
		$defs     = $this->attribute_definitions();
		$payload  = array();
		$skipped  = array();

		foreach ( $values as $name => $value ) {
			if ( ! isset( $enabled[ $name ] ) || '' === $value || null === $value ) {
				continue;
			}
			$cast = $this->cast( $value, is_array( $defs ) ? ( $defs[ $name ] ?? false ) : null );
			if ( null === $cast ) {
				$skipped[] = $name;
				continue;
			}
			$payload[ $name ] = $cast;
		}

		$result = $this->api()->upsert_contact( $email, $payload, $add_to_lists ? self::parse_list_ids( $s['list_ids'] ) : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		set_transient( self::PRESENCE_PREFIX . md5( $email ), self::PRESENCE_YES, 6 * HOUR_IN_SECONDS );
		return $skipped;
	}

	/** @return array|null Attribut-Definitionen oder null, falls nicht ladbar (dann ungeprüft senden). */
	private function attribute_definitions() {
		$cached = get_transient( self::ATTRIBUTES_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$defs = $this->api()->get_attributes();
		if ( is_wp_error( $defs ) ) {
			return null;
		}
		set_transient( self::ATTRIBUTES_CACHE, $defs, DAY_IN_SECONDS );
		return $defs;
	}

	/**
	 * @param object|false|null $def Definition, false = Attribut fehlt in Brevo, null = unbekannt.
	 *
	 * @return mixed|null Typisierter Wert oder null zum Überspringen.
	 */
	private function cast( $value, $def ) {
		if ( false === $def ) {
			return null;
		}
		if ( null === $def ) {
			return $value;
		}
		$type = isset( $def->type ) ? (string) $def->type : ( ! empty( $def->enumeration ) ? 'category' : 'text' );

		switch ( $type ) {
			case 'float':
			case 'id':
				return is_numeric( $value ) ? $value + 0 : null;
			case 'boolean':
				return in_array( strtoupper( (string) $value ), array( 'YES', 'TRUE', '1' ), true );
			case 'date':
				return (string) $value;
			case 'category':
				foreach ( (array) ( $def->enumeration ?? array() ) as $option ) {
					if ( 0 === strcasecmp( (string) $option->label, (string) $value ) ) {
						return (int) $option->value;
					}
				}
				return null;
			default:
				return (string) $value;
		}
	}

	private function log_result( $result, $email, $event, $message, $payment_id = '' ) {
		if ( is_wp_error( $result ) ) {
			$this->log->add( FSD_Brevo_Log::STATUS_ERROR, $email, $event, $result->get_error_message(), $payment_id );
			return;
		}
		if ( $result ) {
			$message .= ' (übersprungen: ' . implode( ', ', $result ) . ')';
		}
		$this->log->add( FSD_Brevo_Log::STATUS_SUCCESS, $email, $event, $message, $payment_id );
	}

	/* --------------------------------------------------------------------
	 * Dashboard: Ist der Käufer in Brevo vorhanden?
	 * ------------------------------------------------------------------ */

	/**
	 * @return string PRESENCE_* oder '' wenn Brevo nicht aktiv ist.
	 */
	public function get_presence( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || ! self::is_active() ) {
			return '';
		}
		$key    = self::PRESENCE_PREFIX . md5( $email );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		$contact = $this->api()->get_contact( $email, 8 );
		if ( is_wp_error( $contact ) ) {
			set_transient( $key, self::PRESENCE_ERROR, 5 * MINUTE_IN_SECONDS );
			return self::PRESENCE_ERROR;
		}
		$presence = $contact ? self::PRESENCE_YES : self::PRESENCE_NO;
		set_transient( $key, $presence, $contact ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		return $presence;
	}

	public function get_log_entry( $payment_id ) {
		return $this->log->find_by_payment( $payment_id );
	}

	/* --------------------------------------------------------------------
	 * Admin-Seite
	 * ------------------------------------------------------------------ */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::get_settings();
		$notice = get_transient( 'fsd_brevo_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'fsd_brevo_notice_' . get_current_user_id() );
		}
		$name = self::OPTION_KEY;
		?>
		<div class="wrap fsd-wrap"><h1 class="fsd-title"><?php esc_html_e( 'Freemius – Brevo', 'freemius-dashboard' ); ?></h1>
		<?php settings_errors(); ?>
		<?php if ( $notice ) : ?>
			<div class="notice <?php echo 'error' === $notice[0] ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
		<?php endif; ?>
		<div class="fsd-card">
			<form method="post" action="options.php"><?php settings_fields( self::GROUP ); ?>
				<table class="form-table">
					<tr><th>Synchronisierung</th><td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> Käufer nach jedem Kauf in Brevo eintragen</label></td></tr>
					<tr><th><label for="fsd-brevo-api-key">API-Key</label></th><td><input type="password" autocomplete="new-password" id="fsd-brevo-api-key" class="regular-text fsd-input" name="<?php echo esc_attr( $name ); ?>[api_key]" placeholder="<?php echo $s['api_key'] ? esc_attr__( 'Gespeichert – leer lassen zum Beibehalten', 'freemius-dashboard' ) : 'xkeysib-…'; ?>" /><p class="description">Brevo → SMTP &amp; API → API-Keys.</p></td></tr>
					<tr><th><label for="fsd-brevo-list-ids">Listen-IDs</label></th><td><input id="fsd-brevo-list-ids" class="regular-text fsd-input" name="<?php echo esc_attr( $name ); ?>[list_ids]" value="<?php echo esc_attr( $s['list_ids'] ); ?>" placeholder="z. B. 3, 7" /><p class="description">Kommagetrennt. Käufer werden diesen Listen hinzugefügt.</p></td></tr>
					<tr><th><label for="fsd-brevo-reseller">Wert für RESELLER</label></th><td><input id="fsd-brevo-reseller" class="regular-text fsd-input" name="<?php echo esc_attr( $name ); ?>[reseller]" value="<?php echo esc_attr( $s['reseller'] ); ?>" /></td></tr>
					<tr><th>Attribute</th><td>
						<?php foreach ( self::attributes() as $key => $label ) : ?>
							<label style="display:block;margin-bottom:4px"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[attributes][]" value="<?php echo esc_attr( strtolower( $key ) ); ?>" <?php checked( in_array( $key, (array) $s['attributes'], true ) ); ?> /> <code><?php echo esc_html( $key ); ?></code> – <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<p class="description">Attribute, die im Brevo-Konto nicht existieren oder deren Wert nicht zum Attributtyp passt, werden übersprungen und im Protokoll vermerkt.</p>
					</td></tr>
				</table>
				<?php submit_button( __( 'Speichern', 'freemius-dashboard' ), 'fsd-btn fsd-btn--filled' ); ?>
			</form>
			<?php $this->render_action_form( 'fsd_brevo_test_connection', 'Brevo-Verbindung testen' ); ?>

			<hr /><h2>Freemius-Webhook</h2>
			<p><input class="large-text" readonly onclick="this.select()" value="<?php echo esc_url( rest_url( FSD_Webhook::NAMESPACE . '/webhook' ) ); ?>" /></p>
			<p class="description">In Freemius aktivieren: <code>payment.created</code>, <code>user.marketing.opted_in</code>, <code>user.marketing.opted_out</code> und <code>user.marketing.reset</code>.</p>

			<hr /><h2>Käufer abgleichen</h2>
			<p>Vergleicht alle über die Freemius-Zahlungen auffindbaren Käufer mit Brevo. Fehlende Kontakte können anschließend mit allen Kaufdaten übertragen werden.</p>
			<?php $this->render_action_form( 'fsd_brevo_reconcile', 'Abgleich starten' ); ?>
			<?php $this->render_missing(); ?>
		</div>
		<div class="fsd-card"><?php $this->render_log(); ?></div>
		</div>
		<?php
	}

	private function render_action_form( $action, $label, $hidden = array() ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:4px 8px 4px 0">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php foreach ( $hidden as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php endforeach; ?>
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, 'fsd-btn fsd-btn--tonal', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_missing() {
		$missing = get_transient( 'fsd_brevo_missing_' . get_current_user_id() );
		if ( false === $missing ) {
			return;
		}
		echo '<h3>Fehlende Kontakte (' . esc_html( count( $missing ) ) . ')</h3>';
		if ( ! $missing ) {
			return;
		}
		$this->render_action_form( 'fsd_brevo_transfer_missing', 'Alle übertragen', array( 'index' => 'all' ) );
		echo '<table class="fsd-table"><thead><tr><th>E-Mail</th><th>Käufe</th><th>Umsatz</th><th>Marketing</th><th></th></tr></thead><tbody>';
		foreach ( $missing as $index => $buyer ) {
			echo '<tr><td><code>' . esc_html( $buyer['email'] ) . '</code></td>';
			echo '<td>' . esc_html( $buyer['values']['ANZAHL_KAEUFE'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $buyer['values']['UMSATZ_GESAMT'], 2 ) ) . '</td>';
			echo '<td>' . esc_html( $buyer['values']['MARKETING_MAILS'] ) . '</td><td>';
			$this->render_action_form( 'fsd_brevo_transfer_missing', 'Übertragen', array( 'index' => $index ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_log() {
		$entries = $this->log->all();
		$labels  = array(
			FSD_Brevo_Log::STATUS_SUCCESS => array( 'fsd-chip--active', 'Erfolgreich' ),
			FSD_Brevo_Log::STATUS_ERROR   => array( 'fsd-chip--blocked', 'Fehler' ),
			FSD_Brevo_Log::STATUS_SKIPPED => array( 'fsd-chip--pending', 'Übersprungen' ),
		);
		echo '<div class="fsd-toolbar"><h2 class="fsd-card__title">Protokoll (' . esc_html( count( $entries ) ) . ')</h2>';
		if ( $entries ) {
			$this->render_action_form( 'fsd_brevo_clear_log', 'Protokoll leeren' );
		}
		echo '</div>';
		echo '<div class="fsd-table-wrap"><table class="fsd-table"><thead><tr><th>Zeit</th><th>Status</th><th>E-Mail</th><th>Event</th><th>Zahlungs-ID</th><th>Meldung</th></tr></thead><tbody>';
		if ( ! $entries ) {
			echo '<tr><td colspan="6" class="fsd-table__empty">Noch keine Einträge.</td></tr>';
		}
		foreach ( $entries as $entry ) {
			$label = $labels[ $entry['status'] ] ?? $labels[ FSD_Brevo_Log::STATUS_SKIPPED ];
			echo '<tr><td>' . esc_html( wp_date( 'd.m.Y H:i:s', $entry['time'] ) ) . '</td>';
			echo '<td><span class="fsd-chip ' . esc_attr( $label[0] ) . '">' . esc_html( $label[1] ) . '</span></td>';
			echo '<td>' . esc_html( $entry['email'] ?: '—' ) . '</td>';
			echo '<td><code>' . esc_html( $entry['event'] ) . '</code></td>';
			echo '<td>' . esc_html( $entry['payment_id'] ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $entry['message'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* --------------------------------------------------------------------
	 * admin-post-Aktionen
	 * ------------------------------------------------------------------ */

	private function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'freemius-dashboard' ) );
		}
		check_admin_referer( $action );
	}

	public function test_connection() {
		$this->authorize( 'fsd_brevo_test_connection' );
		$result = $this->api()->test();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}
		$account = isset( $result->email ) ? ' (' . $result->email . ')' : '';
		$this->redirect( 'done', 'Brevo-Verbindung erfolgreich' . $account . '.' );
	}

	public function clear_log() {
		$this->authorize( 'fsd_brevo_clear_log' );
		$this->log->clear();
		$this->redirect( 'done', 'Protokoll geleert.' );
	}

	public function reconcile() {
		$this->authorize( 'fsd_brevo_reconcile' );
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$fs       = FSD_Settings::get_settings();
		$freemius = new FSD_Api( $fs['product_id'], $fs['public_key'], $fs['secret_key'], $fs['product_id'] );
		$payments = $freemius->get_payments( new DateTimeImmutable( '2010-01-01' ), new DateTimeImmutable( 'tomorrow' ) );
		if ( is_wp_error( $payments ) ) {
			$this->redirect( 'error', $payments->get_error_message() );
		}

		$buyers = $this->aggregate_buyers( $payments, $freemius );
		$missing = array();
		foreach ( $buyers as $buyer ) {
			$found = $this->api()->get_contact( $buyer['email'] );
			if ( is_wp_error( $found ) ) {
				$this->redirect( 'error', $found->get_error_message() );
			}
			set_transient( self::PRESENCE_PREFIX . md5( $buyer['email'] ), $found ? self::PRESENCE_YES : self::PRESENCE_NO, HOUR_IN_SECONDS );
			if ( ! $found ) {
				$missing[] = $buyer;
			}
		}
		set_transient( 'fsd_brevo_missing_' . get_current_user_id(), $missing, HOUR_IN_SECONDS );
		$this->redirect( 'done', sprintf( '%d Käufer geprüft, %d fehlen in Brevo.', count( $buyers ), count( $missing ) ) );
	}

	/**
	 * Fasst alle (Nicht-Sandbox-)Zahlungen je Käufer zu absoluten Attributwerten zusammen.
	 */
	private function aggregate_buyers( $payments, FSD_Api $freemius ) {
		usort(
			$payments,
			static function ( $a, $b ) {
				return strcmp( (string) ( $a->created ?? '' ), (string) ( $b->created ?? '' ) );
			}
		);

		$buyers = array();
		foreach ( $payments as $payment ) {
			if ( isset( $payment->environment ) && 1 === (int) $payment->environment ) {
				continue;
			}
			$user = isset( $payment->user ) ? $payment->user : ( ! empty( $payment->user_id ) ? $freemius->get_user( $payment->user_id ) : null );
			if ( is_wp_error( $user ) || ! $user || empty( $user->email ) ) {
				continue;
			}
			$email     = strtolower( (string) $user->email );
			$gross     = isset( $payment->gross ) ? (float) $payment->gross : 0.0;
			$is_refund = ( isset( $payment->type ) && 'refund' === $payment->type ) || $gross < 0;
			$date      = self::date_of( $payment );
			$prev      = $buyers[ $email ]['values'] ?? array(
				'ANZAHL_KAEUFE'     => 0,
				'UMSATZ_GESAMT'     => 0.0,
				'ERSTKAUF_DATUM'    => '',
				'ERSTATTUNG'        => 'NO',
				'GUTSCHEIN_GENUTZT' => 'NO',
			);
			$total     = round( $prev['UMSATZ_GESAMT'] + $gross, 2 );

			$buyers[ $email ] = array(
				'email'  => $email,
				'values' => array(
					'FIRSTNAME'          => isset( $user->first ) ? (string) $user->first : '',
					'LASTNAME'           => isset( $user->last ) ? (string) $user->last : '',
					'FREEMIUS_ID'        => isset( $user->id ) ? (string) $user->id : (string) ( $payment->user_id ?? '' ),
					'FREEMIUS_UMSATZ'    => $total,
					'UMSATZ_GESAMT'      => $total,
					'ANZAHL_KAEUFE'      => $prev['ANZAHL_KAEUFE'] + ( $is_refund ? 0 : 1 ),
					'LETZTER_KAUF_DATUM' => $is_refund ? ( $buyers[ $email ]['values']['LETZTER_KAUF_DATUM'] ?? '' ) : $date,
					'ERSTKAUF_DATUM'     => '' !== $prev['ERSTKAUF_DATUM'] || $is_refund ? $prev['ERSTKAUF_DATUM'] : $date,
					'LETZTES_PRODUKT'    => isset( $payment->plan->title ) ? (string) $payment->plan->title : ( $buyers[ $email ]['values']['LETZTES_PRODUKT'] ?? '' ),
					'BILLINGCYCLE'       => self::billing_cycle( $payment, new stdClass() ),
					'LIZENZ'             => isset( $payment->license_id ) ? (string) $payment->license_id : '',
					'KUNDENSTATUS'       => $total > 0 ? 'Kunde' : 'Free',
					'BEZAHLT'            => $total > 0 ? 'YES' : 'NO',
					'ERSTATTUNG'         => $is_refund ? 'YES' : $prev['ERSTATTUNG'],
					'GUTSCHEIN_GENUTZT'  => ! empty( $payment->coupon_id ) ? 'YES' : $prev['GUTSCHEIN_GENUTZT'],
					'AFFILIATE_ID'       => ! empty( $payment->affiliate_id ) ? (string) $payment->affiliate_id : '',
					'LAND'               => isset( $payment->country_code ) ? strtoupper( (string) $payment->country_code ) : '',
					'RESELLER'           => self::get_settings()['reseller'],
					'MARKETING_MAILS'    => self::consent_value( $user, true ),
				),
			);
		}
		return $buyers;
	}

	public function transfer_missing() {
		$this->authorize( 'fsd_brevo_transfer_missing' );
		$missing = get_transient( 'fsd_brevo_missing_' . get_current_user_id() );
		$missing = is_array( $missing ) ? $missing : array();
		$index   = isset( $_POST['index'] ) ? sanitize_key( wp_unslash( $_POST['index'] ) ) : '';
		$indexes = 'all' === $index ? array_keys( $missing ) : array( (int) $index );

		$done   = 0;
		$errors = array();
		foreach ( $indexes as $i ) {
			if ( ! isset( $missing[ $i ] ) ) {
				continue;
			}
			$buyer  = $missing[ $i ];
			$result = $this->push( $buyer['email'], $buyer['values'], true );
			$this->log_result( $result, $buyer['email'], 'abgleich', 'Kontakt aus Abgleich übertragen' );
			if ( is_wp_error( $result ) ) {
				$errors[] = $buyer['email'] . ': ' . $result->get_error_message();
				continue;
			}
			unset( $missing[ $i ] );
			++$done;
		}
		set_transient( 'fsd_brevo_missing_' . get_current_user_id(), array_values( $missing ), HOUR_IN_SECONDS );

		if ( $errors ) {
			$this->redirect( 'error', sprintf( '%d übertragen, %d Fehler: %s', $done, count( $errors ), implode( ' | ', $errors ) ) );
		}
		$this->redirect( 'done', sprintf( '%d Kontakt(e) übertragen.', $done ) );
	}

	private function redirect( $status, $message ) {
		set_transient( 'fsd_brevo_notice_' . get_current_user_id(), array( $status, $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}
}
