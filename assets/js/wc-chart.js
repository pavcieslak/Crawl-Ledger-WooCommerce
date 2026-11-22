/**
 * Crawl Ledger for WooCommerce — "Top products fetched by bots" chart.
 * Uses Chart.js bundled with Crawl Ledger 1.0.2+ (handle: crawl-ledger-chartjs).
 */
( function () {
	'use strict';

	var dataEl = document.getElementById( 'cl-wc-data' );
	var canvas = document.getElementById( 'cl-wc-chart' );
	if ( ! dataEl || ! canvas || typeof window.Chart === 'undefined' ) {
		return;
	}

	var data;
	try {
		data = JSON.parse( dataEl.textContent || '{}' );
	} catch ( e ) {
		return;
	}
	if ( ! data.labels || ! data.labels.length ) {
		return;
	}

	new window.Chart( canvas, {
		type: 'bar',
		data: {
			labels: data.labels,
			datasets: [
				{ label: data.i18n.ai, data: data.ai, backgroundColor: '#2563eb', borderRadius: 3, borderSkipped: false, stack: 'p', maxBarThickness: 22 },
				{ label: data.i18n.other, data: data.other, backgroundColor: '#cbd5e1', borderRadius: 3, borderSkipped: false, stack: 'p', maxBarThickness: 22 }
			]
		},
		options: {
			indexAxis: 'y',
			responsive: true,
			maintainAspectRatio: false,
			interaction: { mode: 'index', intersect: false },
			scales: {
				x: { stacked: true, beginAtZero: true, grid: { color: '#f0f0f1' }, border: { display: false }, ticks: { precision: 0, color: '#646970' } },
				y: { stacked: true, grid: { display: false }, ticks: { color: '#1d2327' } }
			},
			plugins: {
				legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10, padding: 14 } },
				tooltip: { backgroundColor: '#1d2327', padding: 10, usePointStyle: true }
			}
		}
	} );
}() );
