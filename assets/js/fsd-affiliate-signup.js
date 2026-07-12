/**
 * Zweistufiges Affiliate-Anmeldeformular, rein linkbasiert bestätigt:
 * 1) Daten eingeben -> Bestätigungslink per E-Mail anfordern (requestAction).
 * 2) Link in der E-Mail anklicken (bestätigt automatisch im Hintergrund,
 *    confirmAction) -> im Formular auf "Ich habe den Link bestätigt"
 *    klicken -> finalizeAction prüft die Bestätigung und legt bei Erfolg
 *    direkt die Bewerbung an.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'fsd-affiliate-signup-form' );

		if ( ! form || ! window.fsdAffiliateSignup ) {
			return;
		}

		var cfg            = fsdAffiliateSignup;
		var i18n           = cfg.i18n;
		var result         = document.getElementById( 'fsd-affiliate-signup-result' );
		var step1          = document.getElementById( 'fsd-affiliate-step-1' );
		var step2          = document.getElementById( 'fsd-affiliate-step-2' );
		var pageUrlEl      = document.getElementById( 'fsd-aff-page-url' );
		var tokenEl        = document.getElementById( 'fsd-aff-token' );
		var requestBtn     = document.getElementById( 'fsd-aff-request-code-btn' );
		var confirmBtn     = document.getElementById( 'fsd-aff-confirm-btn' );
		var resendBtn      = document.getElementById( 'fsd-aff-resend-btn' );
		var backBtn        = document.getElementById( 'fsd-aff-back-btn' );
		var resendTimer    = null;

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

		function goToStep1() {
			step2.style.display = 'none';
			step1.style.display = '';
		}

		function post( action, params ) {
			var formData = new FormData();
			for ( var key in params ) {
				if ( Object.prototype.hasOwnProperty.call( params, key ) ) {
					formData.append( key, params[ key ] );
				}
			}
			formData.append( 'action', action );
			formData.append( 'nonce', cfg.nonce );

			return fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} ).then( function ( response ) {
				return response.json();
			} );
		}

		function startResendCooldown( seconds ) {
			clearResendCooldown();

			var remaining = seconds;
			resendBtn.disabled = true;
			resendBtn.textContent = i18n.resendIn.replace( '%d', remaining );

			resendTimer = window.setInterval( function () {
				remaining -= 1;
				if ( remaining <= 0 ) {
					clearResendCooldown();
					return;
				}
				resendBtn.textContent = i18n.resendIn.replace( '%d', remaining );
			}, 1000 );
		}

		function clearResendCooldown() {
			if ( resendTimer ) {
				window.clearInterval( resendTimer );
				resendTimer = null;
			}
			resendBtn.disabled = false;
			resendBtn.textContent = i18n.resend;
		}

		function requestLink() {
			showPending( i18n.sending );
			requestBtn.disabled = true;

			var formData = new FormData( form );
			var params   = {};
			formData.forEach( function ( value, key ) {
				params[ key ] = value;
			} );

			post( cfg.requestAction, params )
				.then( function ( json ) {
					if ( json && json.success ) {
						tokenEl.value = json.data.token;
						showResult( json.data.message, false );
						goToStep2();
						startResendCooldown( cfg.resendCooldown );
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

		function confirmLink( token ) {
			return post( cfg.confirmAction, { token: token } ).then( function ( json ) {
				if ( json && json.success ) {
					showResult( json.data.message, false );
				} else {
					var message = json && json.data && json.data.message ? json.data.message : 'Unbekannter Fehler';
					showResult( i18n.error + message, true );
				}
			} );
		}

		function finalize() {
			showPending( i18n.checking );
			confirmBtn.disabled = true;

			post( cfg.finalizeAction, { token: tokenEl.value } )
				.then( function ( json ) {
					if ( json && json.success ) {
						showResult( json.data.message, false );
						clearResendCooldown();
						form.reset();
						goToStep1();
					} else {
						var message = json && json.data && json.data.message ? json.data.message : 'Unbekannter Fehler';
						showResult( i18n.error + message, true );
					}
				} )
				.catch( function () {
					showResult( i18n.error + 'Netzwerkfehler', true );
				} )
				.finally( function () {
					confirmBtn.disabled = false;
				} );
		}

		requestBtn.addEventListener( 'click', requestLink );
		confirmBtn.addEventListener( 'click', finalize );
		resendBtn.addEventListener( 'click', requestLink );
		backBtn.addEventListener( 'click', function () {
			clearResendCooldown();
			goToStep1();
			showPending( '' );
		} );

		// Landung über den Link aus der Bestätigungs-E-Mail: Token übernehmen
		// und automatisch als bestätigt markieren (legt noch keinen Affiliate an).
		var params   = new URLSearchParams( window.location.search );
		var urlToken = params.get( 'fsd_verify_token' );

		if ( urlToken ) {
			tokenEl.value = urlToken;
			goToStep2();
			resendBtn.disabled = false;
			resendBtn.textContent = i18n.resend;
			showPending( i18n.checking );
			confirmLink( urlToken );
		}
	} );
} )();
