/**
 * The "Under the hood" drawer: the header's (i) button toggles it, and it stays open across searches in the
 * same tab. It also keeps the demo panels in step with client-side navigation: WooCommerce's filter blocks
 * update the product list without a page load, so after each URL change this fetches the new page and swaps
 * in its panel (and the blog's facet counts).
 */
( function () {
	'use strict';
	const SELECTORS = [ '.uth', '.demo-facets' ];
	const STORE = 'meili-demo-uth-open';
	let last = window.location.href;
	let pending = null;

	function remember( open ) {
		try {
			window.sessionStorage.setItem( STORE, open ? '1' : '' );
		} catch ( e ) {}
	}

	function remembered() {
		try {
			return '1' === window.sessionStorage.getItem( STORE );
		} catch ( e ) {
			return false;
		}
	}

	function setOpen( open, focus ) {
		const panel = document.querySelector( '.uth' );
		if ( ! panel ) {
			return;
		}
		panel.hidden = ! open;
		document.querySelectorAll( '.demo-info' ).forEach( ( button ) => button.setAttribute( 'aria-expanded', String( open ) ) );
		remember( open );
		if ( focus ) {
			const target = open ? panel.querySelector( '.uth__close' ) : document.querySelector( '.demo-info' );
			if ( target ) {
				target.focus();
			}
		}
	}

	document.addEventListener( 'click', ( event ) => {
		if ( event.target.closest( '.demo-info' ) ) {
			const panel = document.querySelector( '.uth' );
			setOpen( Boolean( panel && panel.hidden ), false );
		} else if ( event.target.closest( '.uth__close' ) ) {
			setOpen( false, true );
		}
	} );
	document.addEventListener( 'keydown', ( event ) => {
		const panel = document.querySelector( '.uth' );
		if ( 'Escape' === event.key && panel && ! panel.hidden ) {
			setOpen( false, true );
		}
	} );
	setOpen( remembered(), false );

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
						const node = document.importNode( fresh, true );
						if ( 'hidden' in current ) {
							node.hidden = current.hidden;
						}
						current.replaceWith( node );
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
