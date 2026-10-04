/**
 * Keeps the demo panels in step with client-side navigation: WooCommerce's filter blocks update the product
 * list without a page load, so after each URL change this fetches the new page and swaps in its
 * "Under the hood" panel (and the blog's facet counts).
 */
( function () {
	'use strict';
	const SELECTORS = [ '.uth', '.demo-facets' ];
	let last = window.location.href;
	let pending = null;

	function refresh() {
		if ( window.location.href === last ) {
			return;
		}
		last = window.location.href;
		if ( pending ) {
			pending.abort();
		}
		pending = new AbortController();
		window.fetch( last, { signal: pending.signal, credentials: 'same-origin' } )
			.then( ( response ) => response.text() )
			.then( ( html ) => {
				const next = new DOMParser().parseFromString( html, 'text/html' );
				SELECTORS.forEach( ( selector ) => {
					const current = document.querySelector( selector );
					const fresh = next.querySelector( selector );
					if ( current && fresh ) {
						current.replaceWith( document.importNode( fresh, true ) );
					}
				} );
			} )
			.catch( () => {} );
	}

	[ 'pushState', 'replaceState' ].forEach( ( method ) => {
		const original = window.history[ method ];
		window.history[ method ] = function () {
			const result = original.apply( this, arguments );
			window.setTimeout( refresh, 0 );
			return result;
		};
	} );
	window.addEventListener( 'popstate', refresh );
}() );
