/**
 * Zweistufiges Affiliate-Anmeldeformular, per Code bestätigt:
 * 1) Daten eingeben -> Bestätigungscode per E-Mail anfordern (requestAction).
 * 2) Code aus der E-Mail eingeben -> verifyAction prüft den Code und legt bei
 *    Erfolg direkt die Bewerbung an.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'fsd-affiliate-signup-form' );

		if ( ! form || ! window.fsdAffiliateSignup ) {
			return;
		}

		var cfg         = fsdAffiliateSignup;
		var i18n        = cfg.i18n;
		var result      = document.getElementById( 'fsd-affiliate-signup-result' );
		var step1       = document.getElementById( 'fsd-affiliate-step-1' );
		var step2       = document.getElementById( 'fsd-affiliate-step-2' );
		var tokenEl     = document.getElementById( 'fsd-aff-token' );
		var codeEl      = document.getElementById( 'fsd-aff-code' );
		var requestBtn  = document.getElementById( 'fsd-aff-request-code-btn' );
		var verifyBtn   = document.getElementById( 'fsd-aff-verify-btn' );
		var resendBtn   = document.getElementById( 'fsd-aff-resend-btn' );
		var backBtn     = document.getElementById( 'fsd-aff-back-btn' );
		var resendTimer = null;

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
			if ( codeEl ) {
				codeEl.focus();
			}
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

		function requestCode() {
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

		function verifyCode() {
			showPending( i18n.checking );
			verifyBtn.disabled = true;

			post( cfg.verifyAction, { token: tokenEl.value, code: codeEl.value } )
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
					verifyBtn.disabled = false;
				} );
		}

		requestBtn.addEventListener( 'click', requestCode );
		verifyBtn.addEventListener( 'click', verifyCode );
		resendBtn.addEventListener( 'click', requestCode );
		backBtn.addEventListener( 'click', function () {
			clearResendCooldown();
			goToStep1();
			showPending( '' );
		} );

		// Enter-Taste im Code-Feld löst die Bestätigung aus.
		if ( codeEl ) {
			codeEl.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					verifyCode();
				}
			} );
		}
	} );
} )();
