/**
 * Power-User-Liste: Klick auf eine Zeile lädt die Details per AJAX in ein Modal.
 */
( function ( jQuery ) {
	'use strict';

	class FsdPowerUsers {
		/**
		 * @param {Object} config Lokalisierte Daten (fsdPowerUsers).
		 */
		constructor( config ) {
			this.config   = config;
			this.$modal   = jQuery( '#fsd-power-modal' );
			this.$content = this.$modal.find( '.fsd-modal__content' );
		}

		init() {
			jQuery( document ).on( 'click', '.fsd-power-user', ( e ) => this.open( jQuery( e.currentTarget ) ) );
			jQuery( document ).on( 'keydown', '.fsd-power-user', ( e ) => {
				if ( 'Enter' === e.key ) {
					this.open( jQuery( e.currentTarget ) );
				}
			} );
			this.$modal.on( 'click', ( e ) => {
				if ( e.target === this.$modal[ 0 ] || jQuery( e.target ).hasClass( 'fsd-modal__close' ) ) {
					this.close();
				}
			} );
			jQuery( document ).on( 'keydown', ( e ) => {
				if ( 'Escape' === e.key ) {
					this.close();
				}
			} );
		}

		open( $row ) {
			this.$content.text( 'Lade …' );
			this.$modal.prop( 'hidden', false );

			jQuery.post( this.config.ajaxUrl, {
				action: this.config.action,
				nonce: this.config.nonce,
				user_id: $row.data( 'userId' ),
			} )
				.done( ( response ) => this.render( response ) )
				.fail( ( xhr ) => this.$content.text( 'Fehler (HTTP ' + xhr.status + ').' ) );
		}

		render( response ) {
			if ( ! response || ! response.success ) {
				this.$content.text( response && response.data && response.data.message ? response.data.message : 'Unbekannter Fehler.' );
				return;
			}

			const $list = jQuery( '<dl class="fsd-modal__list"></dl>' );
			jQuery.each( response.data.rows, ( label, value ) => {
				$list.append( jQuery( '<dt></dt>' ).text( label ), jQuery( '<dd></dd>' ).text( value ) );
			} );
			this.$content.empty().append( $list );
		}

		close() {
			this.$modal.prop( 'hidden', true );
		}
	}

	jQuery( function () {
		if ( window.fsdPowerUsers ) {
			new FsdPowerUsers( window.fsdPowerUsers ).init();
		}
	} );
}( jQuery ) );
