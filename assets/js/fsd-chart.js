/**
 * Minimalistischer, abhängigkeitsfreier Balken-Chart (Canvas 2D)
 * für die Käufe der letzten 30 Tage.
 */
( function () {
	'use strict';

	function readData() {
		var node = document.getElementById( 'fsd-chart-data' );
		if ( ! node ) {
			return [];
		}
		try {
			return JSON.parse( node.textContent || '[]' );
		} catch ( e ) {
			return [];
		}
	}

	function drawChart( canvas, data ) {
		var ctx = canvas.getContext( '2d' );
		var dpr = window.devicePixelRatio || 1;
		var rect = canvas.getBoundingClientRect();
		var width = rect.width || canvas.parentElement.clientWidth;
		var height = 220;

		canvas.width = width * dpr;
		canvas.height = height * dpr;
		canvas.style.height = height + 'px';
		ctx.scale( dpr, dpr );

		ctx.clearRect( 0, 0, width, height );

		var styles = getComputedStyle( canvas.closest( '.fsd-wrap' ) );
		var primary = styles.getPropertyValue( '--fsd-primary' ).trim() || '#37618e';
		var gridColor = styles.getPropertyValue( '--fsd-outline' ).trim() || '#e2e4e9';
		var textColor = styles.getPropertyValue( '--fsd-on-surface-variant' ).trim() || '#43474e';

		var padding = { top: 10, right: 8, bottom: 24, left: 8 };
		var chartWidth = width - padding.left - padding.right;
		var chartHeight = height - padding.top - padding.bottom;

		var max = 1;
		data.forEach( function ( d ) {
			if ( d.count > max ) {
				max = d.count;
			}
		} );

		// Grundlinie.
		ctx.strokeStyle = gridColor;
		ctx.lineWidth = 1;
		ctx.beginPath();
		ctx.moveTo( padding.left, padding.top + chartHeight );
		ctx.lineTo( padding.left + chartWidth, padding.top + chartHeight );
		ctx.stroke();

		if ( ! data.length ) {
			return;
		}

		var slot = chartWidth / data.length;
		var barWidth = Math.max( 2, Math.min( 22, slot * 0.55 ) );
		var radius = Math.min( 4, barWidth / 2 );

		ctx.fillStyle = primary;

		data.forEach( function ( d, i ) {
			var barHeight = ( d.count / max ) * ( chartHeight - 12 );
			var x = padding.left + i * slot + ( slot - barWidth ) / 2;
			var y = padding.top + chartHeight - barHeight;

			if ( d.count > 0 ) {
				drawRoundedTopRect( ctx, x, y, barWidth, Math.max( barHeight, 2 ), radius );
			} else {
				ctx.fillStyle = gridColor;
				drawRoundedTopRect( ctx, x, padding.top + chartHeight - 2, barWidth, 2, 1 );
				ctx.fillStyle = primary;
			}
		} );

		// X-Achsen-Labels: jeden 5. Tag beschriften, um Überlappung zu vermeiden.
		ctx.fillStyle = textColor;
		ctx.font = '11px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
		ctx.textAlign = 'center';

		var step = Math.ceil( data.length / 8 );
		data.forEach( function ( d, i ) {
			if ( i % step === 0 || i === data.length - 1 ) {
				var x = padding.left + i * slot + slot / 2;
				ctx.fillText( d.label, x, height - 6 );
			}
		} );
	}

	function drawRoundedTopRect( ctx, x, y, w, h, r ) {
		ctx.beginPath();
		ctx.moveTo( x, y + h );
		ctx.lineTo( x, y + r );
		ctx.arcTo( x, y, x + r, y, r );
		ctx.lineTo( x + w - r, y );
		ctx.arcTo( x + w, y, x + w, y + r, r );
		ctx.lineTo( x + w, y + h );
		ctx.closePath();
		ctx.fill();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var canvas = document.getElementById( 'fsd-chart-canvas' );
		if ( ! canvas ) {
			return;
		}

		var data = readData();
		var render = function () {
			drawChart( canvas, data );
		};

		render();
		window.addEventListener( 'resize', debounce( render, 150 ) );
	} );

	function debounce( fn, wait ) {
		var timeout;
		return function () {
			clearTimeout( timeout );
			timeout = setTimeout( fn, wait );
		};
	}
} )();
