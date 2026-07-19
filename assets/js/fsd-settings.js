/**
 * Verbindungstest-Button auf der Einstellungsseite.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'fsd-test-connection' );
		var result = document.getElementById( 'fsd-test-connection-result' );

		if ( ! button || ! window.fsdSettings ) {
			return;
		}

		button.addEventListener( 'click', function () {
			result.textContent = fsdSettings.i18n.testing;
			result.className = 'fsd-test-result';
			button.disabled = true;

			var formData = new FormData();
			formData.append( 'action', 'fsd_test_connection' );
			formData.append( 'nonce', fsdSettings.nonce );

			// Aktuell im Formular stehende Werte mitschicken, damit getestet wird,
			// was tatsächlich eingetragen ist – nicht nur der zuletzt gespeicherte Stand.
			[ 'product_id', 'public_key', 'secret_key' ].forEach( function ( key ) {
				var field = document.getElementById( 'fsd-field-' + key );
				if ( field ) {
					formData.append( key, field.value );
				}
			} );

			fetch( fsdSettings.ajaxUrl, {
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
						result.className = 'fsd-test-result fsd-test-result--ok';
					} else {
						var message = json && json.data && json.data.message ? json.data.message : 'Unbekannter Fehler';
						result.textContent = fsdSettings.i18n.error + message;
						result.className = 'fsd-test-result fsd-test-result--error';
					}
				} )
				.catch( function () {
					result.textContent = fsdSettings.i18n.error + 'Netzwerkfehler';
					result.className = 'fsd-test-result fsd-test-result--error';
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	} );
} )();
