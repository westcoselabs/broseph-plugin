/* global confirm, alert */
( function () {
	'use strict';

	/*
	 * Connection page — only active when render_page() injects window.brosephConnection.
	 * admin.js is loaded on all Broseph admin pages; the config guard keeps it inert elsewhere.
	 */
	if ( typeof window.brosephConnection === 'undefined' ) return;

	var cfg = window.brosephConnection;

	var el = {
		wrap:          document.getElementById( 'broseph-connection-wrap' ),
		secretDisplay: document.getElementById( 'broseph-secret-display' ),
		revealBtn:     document.getElementById( 'broseph-reveal-btn' ),
		copySecretBtn: document.getElementById( 'broseph-copy-secret-btn' ),
		copySiteIdBtn: document.getElementById( 'broseph-copy-siteid-btn' ),
		copyEnvBtn:    document.getElementById( 'broseph-copy-env-btn' ),
		countdown:     document.getElementById( 'broseph-reveal-countdown' ),
	};

	if ( ! el.wrap ) return;

	var revealedSecret = null;
	var hideInterval   = null;

	/* ── Mask / reveal ──────────────────────────────────────── */

	function maskSecret() {
		revealedSecret = null;
		if ( el.secretDisplay ) {
			el.secretDisplay.textContent = el.secretDisplay.dataset.masked || '';
			el.secretDisplay.classList.remove( 'broseph-secret-revealed' );
			el.secretDisplay.classList.add( 'broseph-secret-masked' );
		}
		if ( el.revealBtn )     { el.revealBtn.textContent = 'Reveal'; el.revealBtn.disabled = false; }
		if ( el.copySecretBtn ) { el.copySecretBtn.disabled = true; }
		if ( el.countdown )     { el.countdown.hidden = true; }
		if ( hideInterval )     { clearInterval( hideInterval ); hideInterval = null; }
	}

	function revealSecret( secret ) {
		revealedSecret = secret;
		if ( el.secretDisplay ) {
			el.secretDisplay.textContent = secret;
			el.secretDisplay.classList.remove( 'broseph-secret-masked' );
			el.secretDisplay.classList.add( 'broseph-secret-revealed' );
		}
		if ( el.revealBtn )     { el.revealBtn.textContent = 'Hide'; el.revealBtn.disabled = false; }
		if ( el.copySecretBtn ) { el.copySecretBtn.disabled = false; }

		var remaining = 30;
		if ( el.countdown ) {
			el.countdown.hidden      = false;
			el.countdown.textContent = 'Auto-hiding in ' + remaining + 's';
		}

		if ( hideInterval ) clearInterval( hideInterval );
		hideInterval = setInterval( function () {
			remaining -= 1;
			if ( el.countdown ) {
				el.countdown.textContent = 'Auto-hiding in ' + remaining + 's';
			}
			if ( remaining <= 0 ) {
				clearInterval( hideInterval );
				hideInterval = null;
				maskSecret();
			}
		}, 1000 );
	}

	/* ── AJAX ───────────────────────────────────────────────── */

	function fetchSecret( callback ) {
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', cfg.ajaxUrl, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onload = function () {
			var data;
			try { data = JSON.parse( xhr.responseText ); } catch ( e ) {
				callback( 'Invalid server response.' );
				return;
			}
			if ( xhr.status !== 200 ) {
				callback( ( data && data.data ) ? data.data : 'Request failed (' + xhr.status + ').' );
				return;
			}
			if ( data && data.success && data.data && data.data.secret ) {
				callback( null, data.data.secret );
			} else {
				callback( ( data && data.data ) ? data.data : 'Unknown error.' );
			}
		};
		xhr.onerror = function () { callback( 'Network error.' ); };
		xhr.send( 'action=broseph_reveal_secret&nonce=' + encodeURIComponent( cfg.nonce ) );
	}

	/* ── Clipboard ──────────────────────────────────────────── */

	function flashButton( btn, label ) {
		var orig      = btn.textContent;
		btn.textContent = label;
		setTimeout( function () { btn.textContent = orig; }, 2000 );
	}

	function copyText( text, btn ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then(
				function ()  { if ( btn ) flashButton( btn, '✓ Copied!' ); },
				function ()  { fallbackCopy( text, btn ); }
			);
		} else {
			fallbackCopy( text, btn );
		}
	}

	function fallbackCopy( text, btn ) {
		var ta       = document.createElement( 'textarea' );
		ta.value     = text;
		ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;';
		document.body.appendChild( ta );
		ta.focus();
		ta.select();
		var ok = false;
		try { ok = document.execCommand( 'copy' ); } catch ( e ) { /* silent */ }
		document.body.removeChild( ta );
		if ( btn ) flashButton( btn, ok ? '✓ Copied!' : '✗ Failed' );
	}

	/* ── Reveal toggle ──────────────────────────────────────── */

	if ( el.revealBtn ) {
		el.revealBtn.addEventListener( 'click', function () {
			if ( revealedSecret !== null ) { maskSecret(); return; }

			if ( ! confirm(
				'This will temporarily show the full shared secret in your browser.\n' +
				'Make sure no one can see your screen.\n\nContinue?'
			) ) return;

			el.revealBtn.disabled    = true;
			el.revealBtn.textContent = 'Fetching…';

			fetchSecret( function ( err, secret ) {
				if ( err ) {
					el.revealBtn.disabled    = false;
					el.revealBtn.textContent = 'Reveal';
					alert( 'Could not reveal secret: ' + err );
					return;
				}
				revealSecret( secret );
			} );
		} );
	}

	/* ── Copy Site ID ───────────────────────────────────────── */

	if ( el.copySiteIdBtn ) {
		el.copySiteIdBtn.addEventListener( 'click', function () {
			copyText( cfg.siteId, el.copySiteIdBtn );
		} );
	}

	/* ── Copy Secret ────────────────────────────────────────── */

	if ( el.copySecretBtn ) {
		el.copySecretBtn.addEventListener( 'click', function () {
			if ( revealedSecret !== null ) {
				copyText( revealedSecret, el.copySecretBtn );
				return;
			}
			if ( ! confirm(
				'This will retrieve and copy the full shared secret.\n' +
				'Make sure no one can see your clipboard.\n\nContinue?'
			) ) return;

			el.copySecretBtn.disabled = true;
			fetchSecret( function ( err, secret ) {
				el.copySecretBtn.disabled = false;
				if ( err ) { alert( 'Could not retrieve secret: ' + err ); return; }
				revealSecret( secret );
				copyText( secret, el.copySecretBtn );
			} );
		} );
	}

	/* ── Copy Open Claw ENV template ────────────────────────── */

	if ( el.copyEnvBtn ) {
		el.copyEnvBtn.addEventListener( 'click', function () {
			function doEnvCopy( secret ) {
				var env = [
					'BROSEPH_SITE_URL='      + cfg.siteUrl,
					'BROSEPH_REST_BASE='     + cfg.restBase,
					'BROSEPH_SITE_ID='       + cfg.siteId,
					'BROSEPH_SHARED_SECRET=' + secret,
				].join( '\n' );
				copyText( env, el.copyEnvBtn );
			}

			if ( revealedSecret !== null ) { doEnvCopy( revealedSecret ); return; }

			if ( ! confirm(
				'Copying the ENV template requires the full shared secret.\n' +
				'Make sure no one can see your clipboard.\n\nContinue?'
			) ) return;

			el.copyEnvBtn.disabled = true;
			fetchSecret( function ( err, secret ) {
				el.copyEnvBtn.disabled = false;
				if ( err ) { alert( 'Could not retrieve secret: ' + err ); return; }
				revealSecret( secret );
				doEnvCopy( secret );
			} );
		} );
	}

}() );
