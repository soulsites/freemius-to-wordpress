<?php
/** ActiveCampaign settings, webhook processing and buyer reconciliation. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSD_ActiveCampaign {
	const PAGE_SLUG  = 'fsd-activecampaign';
	const GROUP      = 'fsd_activecampaign_group';
	const OPTION_KEY = 'fsd_activecampaign_settings';

	public static function defaults() {
		return array(
			'enabled' => false, 'api_url' => '', 'api_token' => '', 'list_id' => '',
			'tag_buyer' => 'Freemius - Käufer', 'tag_subscription' => 'Freemius - Abo', 'tag_lifetime' => 'Freemius - Lifetime',
			'tag_opt_in' => 'Freemius - Marketing Opt-in', 'tag_opt_out' => 'Freemius - Marketing Opt-out', 'tag_unknown' => 'Freemius - Marketing unbekannt',
			'tag_license_prefix' => 'Freemius - Lizenz:',
		);
	}

	public static function get_settings() {
		$value = FSD_Settings::get_unfiltered_option( self::OPTION_KEY );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	public function register() {
		register_setting( self::GROUP, self::OPTION_KEY, array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize' ), 'default' => self::defaults() ) );
	}

	public function sanitize( $input ) {
		$old = self::get_settings();
		$out = self::defaults();
		$out['enabled'] = ! empty( $input['enabled'] );
		$out['api_url'] = isset( $input['api_url'] ) ? esc_url_raw( trim( wp_unslash( $input['api_url'] ) ) ) : '';
		$out['api_token'] = ! empty( $input['api_token'] ) ? sanitize_text_field( wp_unslash( $input['api_token'] ) ) : $old['api_token'];
		$out['list_id'] = isset( $input['list_id'] ) ? absint( $input['list_id'] ) : '';
		foreach ( array( 'tag_buyer', 'tag_subscription', 'tag_lifetime', 'tag_opt_in', 'tag_opt_out', 'tag_unknown', 'tag_license_prefix' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : self::defaults()[ $key ];
		}
		return $out;
	}

	private function api() {
		$s = self::get_settings();
		return new FSD_ActiveCampaign_Api( $s['api_url'], $s['api_token'] );
	}

	public function handle_event( $event ) {
		$s = self::get_settings();
		if ( empty( $s['enabled'] ) || ! is_object( $event ) ) {
			return;
		}
		$type = isset( $event->type ) ? (string) $event->type : '';
		$objects = isset( $event->objects ) ? $event->objects : new stdClass();
		$user = isset( $objects->user ) ? $objects->user : null;
		if ( ! $user || empty( $user->email ) ) {
			return;
		}
		$consent = 'unknown';
		if ( 'user.marketing.opted_in' === $type ) { $consent = 'opt_in'; }
		if ( 'user.marketing.opted_out' === $type ) { $consent = 'opt_out'; }
		if ( isset( $user->is_marketing_allowed ) ) { $consent = $user->is_marketing_allowed ? 'opt_in' : 'opt_out'; }
		$payment = isset( $objects->payment ) ? $objects->payment : new stdClass();
		$this->transfer( array(
			'email' => (string) $user->email, 'first' => isset( $user->first ) ? (string) $user->first : '', 'last' => isset( $user->last ) ? (string) $user->last : '',
			'license_id' => isset( $payment->license_id ) ? (string) $payment->license_id : '',
			'is_lifetime' => empty( $payment->subscription_id ), 'consent' => $consent,
		), array(), 'payment.created' === $type );
	}

	public function transfer( $buyer, $extra_tags = array(), $include_purchase_tags = true ) {
		$s = self::get_settings();
		$api = $this->api();
		$contact = $api->sync_contact( $buyer['email'], $buyer['first'], $buyer['last'] );
		if ( is_wp_error( $contact ) ) { return $contact; }
		$subscribed = $api->subscribe( $contact->id, $s['list_id'] );
		if ( is_wp_error( $subscribed ) ) { return $subscribed; }
		$tags = array();
		if ( $include_purchase_tags ) {
			$tags = array( $s['tag_buyer'], ! empty( $buyer['is_lifetime'] ) ? $s['tag_lifetime'] : $s['tag_subscription'] );
		}
		$consent_key = 'opt_in' === $buyer['consent'] ? 'tag_opt_in' : ( 'opt_out' === $buyer['consent'] ? 'tag_opt_out' : 'tag_unknown' );
		foreach ( array( 'tag_opt_in', 'tag_opt_out', 'tag_unknown' ) as $candidate ) {
			if ( $candidate !== $consent_key ) {
				$removed = $api->remove_tag( $contact->id, $s[ $candidate ] );
				if ( is_wp_error( $removed ) ) { return $removed; }
			}
		}
		$tags[] = $s[ $consent_key ];
		if ( $include_purchase_tags && ! empty( $buyer['license_id'] ) ) { $tags[] = trim( $s['tag_license_prefix'] . ' ' . $buyer['license_id'] ); }
		$tags = array_merge( $tags, $extra_tags );
		foreach ( array_unique( array_filter( $tags ) ) as $tag ) {
			$result = $api->add_tag( $contact->id, $tag );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		return true;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s = self::get_settings();
		$fields = array(
			'api_url' => 'API-URL', 'list_id' => 'Listen-ID', 'tag_buyer' => 'Käufer-Tag', 'tag_subscription' => 'Abo-Tag', 'tag_lifetime' => 'Lifetime-Tag',
			'tag_opt_in' => 'Marketing Opt-in-Tag', 'tag_opt_out' => 'Marketing Opt-out-Tag', 'tag_unknown' => 'Marketing unbekannt-Tag', 'tag_license_prefix' => 'Lizenz-Tag-Präfix',
		);
		?>
		<div class="wrap fsd-wrap"><h1 class="fsd-title"><?php esc_html_e( 'Freemius – ActiveCampaign', 'freemius-dashboard' ); ?></h1>
		<?php settings_errors(); ?>
		<?php $notice = get_transient( 'fsd_ac_notice_' . get_current_user_id() ); if ( $notice ) : delete_transient( 'fsd_ac_notice_' . get_current_user_id() ); ?>
		<div class="notice <?php echo 'error' === $notice[0] ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div><?php endif; ?>
		<div class="fsd-card"><form method="post" action="options.php"><?php settings_fields( self::GROUP ); ?>
		<table class="form-table"><tr><th>Synchronisierung</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> Aktiviert</label></td></tr>
		<?php foreach ( $fields as $key => $label ) : ?><tr><th><label for="fsd-ac-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input class="regular-text fsd-input" id="fsd-ac-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>" /></td></tr><?php endforeach; ?>
		<tr><th>API-Token</th><td><input type="password" autocomplete="new-password" class="regular-text fsd-input" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_token]" placeholder="<?php echo $s['api_token'] ? 'Gespeichert – leer lassen zum Beibehalten' : ''; ?>" /></td></tr></table>
		<?php submit_button( __( 'Speichern', 'freemius-dashboard' ), 'fsd-btn fsd-btn--filled' ); ?></form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="fsd_ac_test_connection" /><?php wp_nonce_field( 'fsd_ac_test_connection' ); ?><?php submit_button( 'ActiveCampaign-Verbindung testen', 'fsd-btn fsd-btn--tonal', 'submit', false ); ?></form>
		<hr /><h2>Freemius-Webhook</h2><p><input class="large-text" readonly onclick="this.select()" value="<?php echo esc_url( rest_url( FSD_Webhook::NAMESPACE . '/webhook' ) ); ?>" /></p><p class="description">In Freemius aktivieren: <code>payment.created</code>, <code>user.marketing.opted_in</code>, <code>user.marketing.opted_out</code> und <code>user.marketing.reset</code>.</p>
		<hr /><h2>Käufer abgleichen</h2><p>Vergleicht alle über die Freemius-Zahlungen auffindbaren Käufer mit ActiveCampaign. Es werden noch keine fehlenden Kontakte übertragen.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="fsd_ac_reconcile" /><?php wp_nonce_field( 'fsd_ac_reconcile' ); ?><?php submit_button( 'Abgleich starten', 'fsd-btn fsd-btn--tonal', 'submit', false ); ?></form>
		<?php $this->render_missing(); ?></div></div><?php
	}

	public function test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Keine Berechtigung.' ); }
		check_admin_referer( 'fsd_ac_test_connection' );
		$result = $this->api()->test();
		$this->redirect( is_wp_error( $result ) ? 'error' : 'done', is_wp_error( $result ) ? $result->get_error_message() : 'ActiveCampaign-Verbindung erfolgreich.' );
	}

	public function reconcile() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Keine Berechtigung.' ); }
		check_admin_referer( 'fsd_ac_reconcile' );
		$fs = FSD_Settings::get_settings();
		$freemius = new FSD_Api( $fs['product_id'], $fs['public_key'], $fs['secret_key'], $fs['product_id'] );
		$payments = $freemius->get_payments( new DateTimeImmutable( '2010-01-01' ), new DateTimeImmutable( 'tomorrow' ) );
		if ( is_wp_error( $payments ) ) { $this->redirect( 'error', $payments->get_error_message() ); }
		$buyers = array();
		foreach ( $payments as $payment ) {
			$user = isset( $payment->user ) ? $payment->user : ( ! empty( $payment->user_id ) ? $freemius->get_user( $payment->user_id ) : null );
			if ( is_wp_error( $user ) || ! $user || empty( $user->email ) ) { continue; }
			$email = strtolower( (string) $user->email );
			$buyers[ $email ] = array( 'email' => $email, 'first' => isset( $user->first ) ? (string) $user->first : '', 'last' => isset( $user->last ) ? (string) $user->last : '', 'license_id' => isset( $payment->license_id ) ? (string) $payment->license_id : '', 'is_lifetime' => empty( $payment->subscription_id ), 'consent' => isset( $user->is_marketing_allowed ) ? ( $user->is_marketing_allowed ? 'opt_in' : 'opt_out' ) : 'unknown' );
		}
		$missing = array(); $api = $this->api();
		foreach ( $buyers as $buyer ) { $found = $api->find_contact( $buyer['email'] ); if ( is_wp_error( $found ) ) { $this->redirect( 'error', $found->get_error_message() ); } if ( ! $found ) { $missing[] = $buyer; } }
		set_transient( 'fsd_ac_missing_' . get_current_user_id(), $missing, HOUR_IN_SECONDS );
		$this->redirect( 'done', count( $missing ) . ' fehlende Kontakte gefunden.' );
	}

	private function render_missing() {
		$missing = get_transient( 'fsd_ac_missing_' . get_current_user_id() );
		if ( false === $missing ) { return; }
		echo '<h3>Fehlende Kontakte (' . esc_html( count( $missing ) ) . ')</h3>';
		foreach ( $missing as $index => $buyer ) { ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:12px;align-items:center;margin:8px 0"><input type="hidden" name="action" value="fsd_ac_transfer_missing" /><input type="hidden" name="index" value="<?php echo esc_attr( $index ); ?>" /><?php wp_nonce_field( 'fsd_ac_transfer_missing_' . $index ); ?><code><?php echo esc_html( $buyer['email'] ); ?></code><input name="extra_tags" placeholder="Optionale Tags, kommagetrennt" class="regular-text" /><button class="button">Übertragen</button></form>
		<?php }
	}

	public function transfer_missing() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Keine Berechtigung.' ); }
		$index = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : -1; check_admin_referer( 'fsd_ac_transfer_missing_' . $index );
		$missing = get_transient( 'fsd_ac_missing_' . get_current_user_id() );
		if ( ! isset( $missing[ $index ] ) ) { $this->redirect( 'error', 'Kontakt nicht mehr verfügbar.' ); }
		$tags = isset( $_POST['extra_tags'] ) ? array_map( 'sanitize_text_field', preg_split( '/[,\r\n]+/', wp_unslash( $_POST['extra_tags'] ) ) ) : array();
		$result = $this->transfer( $missing[ $index ], $tags );
		if ( is_wp_error( $result ) ) { $this->redirect( 'error', $result->get_error_message() ); }
		unset( $missing[ $index ] ); set_transient( 'fsd_ac_missing_' . get_current_user_id(), array_values( $missing ), HOUR_IN_SECONDS );
		$this->redirect( 'done', 'Kontakt übertragen.' );
	}

	private function redirect( $status, $message ) {
		set_transient( 'fsd_ac_notice_' . get_current_user_id(), array( $status, $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ); exit;
	}
}
