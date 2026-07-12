/**
 * Zweistufiges Affiliate-Anmeldeformular:
 * 1) Daten eingeben -> Code per E-Mail anfordern (fsd_affiliate_request_code).
 * 2) Code eingeben (oder per Link automatisch übernommen) -> wird per AJAX
 *    geprüft und bei Erfolg direkt die Bewerbung angelegt (fsd_affiliate_verify_code).
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'fsd-affiliate-signup-form' );

		if ( ! form || ! window.fsdAffiliateSignup ) {
			return;
		}

		var i18n       = fsdAffiliateSignup.i18n;
		var result     = document.getElementById( 'fsd-affiliate-signup-result' );
		var step1      = document.getElementById( 'fsd-affiliate-step-1' );
		var step2      = document.getElementById( 'fsd-affiliate-step-2' );
		var pageUrlEl  = document.getElementById( 'fsd-aff-page-url' );
		var tokenEl    = document.getElementById( 'fsd-aff-token' );
		var codeEl     = document.getElementById( 'fsd-aff-code' );
		var requestBtn = document.getElementById( 'fsd-aff-request-code-btn' );
		var verifyBtn  = document.getElementById( 'fsd-aff-verify-btn' );
		var backBtn    = document.getElementById( 'fsd-aff-back-btn' );

		if ( pageUrlEl ) {
			pageUrlEl.value = window.location.origin + window.location.pathname;
		}

		function showResult( message, isError ) {
			result.textContent = message;
			result.className = 'fsd-affiliate-form__result' + ( isError ? ' fsd-affiliate-form__result--error' : ' fsd-affiliate-form__result--ok' );
		}

		function showPending( message ) {
			result.textContent = message;
			result.className = 'fsd-affiliate-form__result';
		}

		function goToStep2() {
			step1.style.display = 'none';
			step2.style.display = '';
		}

		function post( action, formData ) {
			formData.append( 'action', action );
			formData.append( 'nonce', fsdAffiliateSignup.nonce );

			return fetch( fsdAffiliateSignup.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} ).then( function ( response ) {
				return response.json();
			} );
		}

		function requestCode() {
			showPending( i18n.sending );
			requestBtn.disabled = true;

			var formData = new FormData( form );

			post( 'fsd_affiliate_request_code', formData )
				.then( function ( json ) {
					if ( json && json.success ) {
						tokenEl.value = json.data.token;
						showResult( json.data.message, false );
						goToStep2();
						codeEl.focus();
					} else {
						var message = json && json.data && json.data.message ? json.data.message : 'Unbekannter Fehler';
						showResult( i18n.error + message, true );
					}
				} )
				.catch( function () {
					showResult( i18n.error + 'Netzwerkfehler', true );
				} )
				.finally( function () {
					requestBtn.disabled = false;
				} );
		}

		function verifyCode() {
			showPending( i18n.checking );
			verifyBtn.disabled = true;

			var formData = new FormData();
			formData.append( 'token', tokenEl.value );
			formData.append( 'code', codeEl.value.trim() );

			return post( 'fsd_affiliate_verify_code', formData )
				.then( function ( json ) {
					if ( json && json.success ) {
						showResult( json.data.message, false );
						form.reset();
						step2.style.display = 'none';
						step1.style.display = '';
					} else {
						var message = json && json.data && json.data.message ? json.data.message : 'Unbekannter Fehler';
						showResult( i18n.error + message, true );
					}
				} )
				.catch( function () {
					showResult( i18n.error + 'Netzwerkfehler', true );
				} )
				.finally( function () {
					verifyBtn.disabled = false;
				} );
		}

		requestBtn.addEventListener( 'click', requestCode );
		verifyBtn.addEventListener( 'click', verifyCode );
		backBtn.addEventListener( 'click', function () {
			step2.style.display = 'none';
			step1.style.display = '';
			showPending( '' );
		} );

		// Klick auf den Link aus der Bestätigungs-E-Mail: Token/Code aus der
		// URL übernehmen und automatisch prüfen + absenden.
		var params = new URLSearchParams( window.location.search );
		var urlToken = params.get( 'fsd_verify_token' );
		var urlCode  = params.get( 'fsd_verify_code' );

		if ( urlToken && urlCode ) {
			tokenEl.value = urlToken;
			codeEl.value = urlCode;
			goToStep2();
			verifyCode();
		}
	} );
} )();
