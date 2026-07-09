/**
 * Sendet das Affiliate-Anmeldeformular per AJAX an admin-ajax.php.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'fsd-affiliate-signup-form' );
		var result = document.getElementById( 'fsd-affiliate-signup-result' );

		if ( ! form || ! window.fsdAffiliateSignup ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var button = form.querySelector( 'button[type="submit"]' );

			result.textContent = fsdAffiliateSignup.i18n.sending;
			result.className = 'fsd-affiliate-form__result';
			button.disabled = true;

			var formData = new FormData( form );
			formData.append( 'action', 'fsd_affiliate_signup' );
			formData.append( 'nonce', fsdAffiliateSignup.nonce );

			fetch( fsdAffiliateSignup.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( json && json.success ) {
						result.textContent = json.data.message;
						result.className = 'fsd-affiliate-form__result fsd-affiliate-form__result--ok';
						form.reset();
					} else {
						var message = json && json.data && json.data.message ? json.data.message : 'Unbekannter Fehler';
						result.textContent = fsdAffiliateSignup.i18n.error + message;
						result.className = 'fsd-affiliate-form__result fsd-affiliate-form__result--error';
					}
				} )
				.catch( function () {
					result.textContent = fsdAffiliateSignup.i18n.error + 'Netzwerkfehler';
					result.className = 'fsd-affiliate-form__result fsd-affiliate-form__result--error';
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	} );
} )();
