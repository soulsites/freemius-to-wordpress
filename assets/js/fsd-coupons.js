/**
 * Gutscheine: "Alle markieren" + Validierung/Bestätigung der Massenaktion.
 */
( function ( jQuery ) {
	'use strict';

	class FsdCoupons {
		/**
		 * @param {Object} config Lokalisierte Daten (fsdCoupons).
		 */
		constructor( config ) {
			this.i18n   = config.i18n || {};
			this.$form  = jQuery( '#fsd-coupons-form' );
			this.$all   = jQuery( '#fsd-coupons-all' );
		}

		checks() {
			return this.$form.find( '.fsd-coupon-check' );
		}

		init() {
			this.$all.on( 'change', () => this.checks().prop( 'checked', this.$all.prop( 'checked' ) ) );
			this.$form.on( 'change', '.fsd-coupon-check', () => this.syncAll() );
			this.$form.on( 'submit', ( e ) => this.validate( e ) );
		}

		syncAll() {
			const $checks = this.checks();
			this.$all.prop( 'checked', $checks.length > 0 && $checks.length === $checks.filter( ':checked' ).length );
		}

		validate( e ) {
			const action = this.$form.find( 'select[name="bulk_action"]' ).val();

			if ( ! action ) {
				e.preventDefault();
				window.alert( this.i18n.noAction );
				return;
			}
			if ( ! this.checks().filter( ':checked' ).length ) {
				e.preventDefault();
				window.alert( this.i18n.noSelection );
				return;
			}
			if ( 'delete' === action && ! window.confirm( this.i18n.confirmDelete ) ) {
				e.preventDefault();
			}
		}
	}

	jQuery( function () {
		if ( window.fsdCoupons ) {
			new FsdCoupons( window.fsdCoupons ).init();
		}
	} );
}( jQuery ) );
