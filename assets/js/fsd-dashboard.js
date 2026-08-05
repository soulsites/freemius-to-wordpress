/**
 * Lädt die Käufe-Tabelle und den 30-Tage-Chart auf der Dashboard-Seite
 * seitenweise per AJAX nach dem ersten Seitenaufbau, statt sie synchron beim
 * Seitenaufruf zu berechnen. Bei vielen hundert Käufen kann das Laden aller
 * Zahlungen über die Freemius-API sonst so lange dauern, dass entweder die
 * Admin-Seite gefühlt "hängt" oder das PHP-Skript-Zeitlimit reißt, bevor
 * überhaupt etwas ausgegeben wurde.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var app = document.getElementById( 'fsd-dashboard-app' );
		if ( ! app || ! window.fsdDashboard ) {
			return;
		}

		var ym = app.getAttribute( 'data-ym' ) || '';

		loadChart();
		loadTable( ym );

		function fetchPage( scope, page, ym, callback ) {
			var formData = new FormData();
			formData.append( 'action', 'fsd_dashboard_page' );
			formData.append( 'nonce', fsdDashboard.nonce );
			formData.append( 'scope', scope );
			formData.append( 'page', page );
			if ( ym ) {
				formData.append( 'ym', ym );
			}

			fetch( fsdDashboard.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					callback( null, json );
				} )
				.catch( function ( err ) {
					callback( err );
				} );
		}

		function showError( el, json ) {
			var message = json && json.data && json.data.message ? json.data.message : 'Netzwerkfehler';
			el.textContent = fsdDashboard.i18n.error + message;
			el.style.display = '';
		}

		function loadChart() {
			var loading = document.getElementById( 'fsd-chart-loading' );
			var errorEl = document.getElementById( 'fsd-chart-error' );
			var canvas = document.getElementById( 'fsd-chart-canvas' );
			var days = null;
			var counts = {};

			function step( page ) {
				fetchPage( 'chart', page, null, function ( err, json ) {
					if ( err || ! json || ! json.success ) {
						showError( errorEl, json );
						loading.style.display = 'none';
						return;
					}

					var data = json.data;
					if ( data.days ) {
						days = data.days;
					}
					Object.keys( data.counts || {} ).forEach( function ( day ) {
						counts[ day ] = ( counts[ day ] || 0 ) + data.counts[ day ];
					} );

					if ( data.has_more ) {
						step( page + 1 );
						return;
					}

					loading.style.display = 'none';

					var series = ( days || [] ).map( function ( d ) {
						return { date: d.date, label: d.label, count: counts[ d.date ] || 0 };
					} );

					if ( window.FSDChart ) {
						window.FSDChart.render( canvas, series );
					}
				} );
			}

			step( 0 );
		}

		function loadTable( ym ) {
			var loading = document.getElementById( 'fsd-table-loading' );
			var errorEl = document.getElementById( 'fsd-table-error' );
			var tbody = document.getElementById( 'fsd-table-body' );
			var totalsWrap = document.getElementById( 'fsd-totals' );
			var totalsValues = document.getElementById( 'fsd-totals-values' );
			var totals = {};
			var count = 0;

			function step( page ) {
				fetchPage( 'table', page, ym, function ( err, json ) {
					if ( err || ! json || ! json.success ) {
						showError( errorEl, json );
						loading.style.display = 'none';
						return;
					}

					var data = json.data;

					if ( data.rows_html ) {
						tbody.insertAdjacentHTML( 'beforeend', data.rows_html );
					}

					( data.nets || [] ).forEach( function ( entry ) {
						totals[ entry.currency ] = ( totals[ entry.currency ] || 0 ) + entry.net;
					} );
					count += ( data.nets || [] ).length;

					loading.textContent = fsdDashboard.i18n.loadingTable.replace( '%d', count );

					if ( data.has_more ) {
						step( page + 1 );
						return;
					}

					loading.style.display = 'none';

					if ( 0 === count ) {
						var emptyRow = document.createElement( 'tr' );
						var td = document.createElement( 'td' );
						td.className = 'fsd-table__empty';
						td.colSpan = 17;
						td.textContent = fsdDashboard.i18n.empty;
						emptyRow.appendChild( td );
						tbody.appendChild( emptyRow );
						return;
					}

					finalizeTotals();
				} );
			}

			function finalizeTotals() {
				// Die eigentliche Zahlenformatierung (Locale-abhängig, z. B.
				// Dezimaltrennzeichen) bleibt bewusst in PHP (number_format_i18n) –
				// hier wird nur die bereits aufsummierte Summe je Währung geschickt.
				var formData = new FormData();
				formData.append( 'action', 'fsd_dashboard_totals' );
				formData.append( 'nonce', fsdDashboard.nonce );
				formData.append( 'totals', JSON.stringify( totals ) );

				fetch( fsdDashboard.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData,
				} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( json ) {
						if ( json && json.success ) {
							totalsValues.innerHTML = json.data.html;
							totalsWrap.style.display = '';
						}
					} )
					.catch( function () {
						// Summenzeile ist nicht kritisch – bei Fehlschlag bleibt sie einfach leer.
					} );
			}

			step( 0 );
		}
	} );
} )();
