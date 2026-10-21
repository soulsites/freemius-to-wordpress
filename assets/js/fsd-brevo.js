/**
 * Brevo-Seite: Abgleich und „Alle übertragen“ schrittweise per AJAX.
 * Jeder Request arbeitet serverseitig nur wenige Sekunden (siehe
 * FSD_Brevo_Reconcile), das Script ruft so lange erneut auf, bis "done".
 */
( function ( jQuery ) {
	'use strict';

	class FsdBrevoBatch {
		/**
		 * @param {jQuery} $button   Auslösender Button.
		 * @param {jQuery} $progress Element für Fortschrittsmeldungen.
		 * @param {string} action    AJAX-Action.
		 * @param {Object} config    Lokalisierte Daten (fsdBrevo).
		 */
		constructor( $button, $progress, action, config ) {
			this.$button   = $button;
			this.$progress = $progress;
			this.action    = action;
			this.config    = config;
		}

		start( data ) {
			this.$button.prop( 'disabled', true );
			this.setMessage( 'Starte …', '' );
			this.step( data || {} );
		}

		step( data ) {
			jQuery.post(
				this.config.ajaxUrl,
				jQuery.extend( { action: this.action, nonce: this.config.nonce }, data )
			)
				.done( ( response ) => this.handle( response ) )
				.fail( ( xhr ) => this.fail( 'Request fehlgeschlagen (HTTP ' + xhr.status + ').' ) );
		}

		handle( response ) {
			if ( ! response || ! response.success ) {
				this.fail( response && response.data && response.data.message ? response.data.message : 'Unbekannter Fehler.' );
				return;
			}

			this.setMessage( response.data.message, '' );

			if ( response.data.done ) {
				window.location.reload();
				return;
			}

			this.step( this.nextData( response.data ) );
		}

		nextData() {
			return {};
		}

		fail( message ) {
			this.setMessage( 'Fehler: ' + message, 'fsd-test-result--error' );
			this.$button.prop( 'disabled', false );
		}

		setMessage( message, cssClass ) {
			this.$progress.text( message ).removeClass( 'fsd-test-result--error' ).addClass( cssClass );
		}
	}

	class FsdBrevoTransfer extends FsdBrevoBatch {
		nextData( data ) {
			return { skip: data.skip };
		}
	}

	jQuery( function () {
		const config = window.fsdBrevo;
		if ( ! config ) {
			return;
		}

		const $progress = jQuery( '#fsd-brevo-progress' );

		jQuery( '#fsd-brevo-reconcile' ).on( 'click', function () {
			new FsdBrevoBatch( jQuery( this ), $progress, config.actions.reconcile, config ).start( { reset: 1 } );
		} );

		jQuery( '#fsd-brevo-transfer-all' ).on( 'click', function () {
			new FsdBrevoTransfer( jQuery( this ), $progress, config.actions.transfer, config ).start( { skip: 0 } );
		} );
	} );
}( jQuery ) );
