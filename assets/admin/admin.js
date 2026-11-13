/* PressHelm admin: while waiting for the owner to press Start in Telegram, refresh the page
   every few seconds (up to 10 minutes, the life of the one-time link). */
( function () {
	var el = document.querySelector( '[data-presshelm-reload]' );
	if ( ! el ) {
		return;
	}
	var every = Math.max( 3, parseInt( el.getAttribute( 'data-presshelm-reload' ), 10 ) || 5 ) * 1000;
	var key = 'presshelm_wait_started';
	var started;
	try {
		started = parseInt( window.sessionStorage.getItem( key ), 10 ) || Date.now();
		window.sessionStorage.setItem( key, String( started ) );
	} catch ( e ) {
		started = Date.now();
	}
	if ( Date.now() - started > 10 * 60 * 1000 ) {
		return;
	}
	window.setTimeout( function () {
		if ( ! document.hidden ) {
			window.location.reload();
		} else {
			document.addEventListener( 'visibilitychange', function once() {
				document.removeEventListener( 'visibilitychange', once );
				window.location.reload();
			} );
		}
	}, every );
} )();
