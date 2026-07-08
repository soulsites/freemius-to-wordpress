/**
 * Verbindungstest-Button und Scope/Produkt-ID-Sync auf der Einstellungsseite.
 */
( function () {
	'use strict';

	function initScopeSync() {
		var scopeField   = document.getElementById( 'fsd-scope-field' );
		var productField = document.getElementById( 'fsd-field-product_id' );
		var scopeIdField = document.getElementById( 'fsd-field-scope_id' );

		if ( ! scopeField || ! productField || ! scopeIdField ) {
			return;
		}

		function applyState() {
			var radios = scopeField.querySelectorAll( 'input[type="radio"]' );
			var scope = 'developer';
			radios.forEach( function ( radio ) {
				if ( radio.checked ) {
					scope = radio.value;
				}
			} );

			if ( 'product' === scope ) {
				productField.value = scopeIdField.value;
				productField.readOnly = true;
			} else {
				productField.readOnly = false;
			}
		}

		scopeField.addEventListener( 'change', applyState );
		scopeIdField.addEventListener( 'input', applyState );
		applyState();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initScopeSync();

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
