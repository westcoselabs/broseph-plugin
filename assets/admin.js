( function () {
	'use strict';

	/*
	 * Connection page interactivity — Broseph > Connection.
	 *
	 * Config is read from data-* attributes on #broseph-connection-wrap
	 * (set by ConnectionPage::render_page via PHP, manage_options only).
	 * The full shared secret lives in data-secret on #broseph-secret-display —
	 * also manage_options only, never in REST responses or public pages.
	 *
	 * Guard: this file is loaded on all Broseph admin pages; it exits
	 * immediately on any page that does not have the connection wrap element.
	 */

	function init() {
		var el = {
			wrap:          document.getElementById( 'broseph-connection-wrap' ),
			secretDisplay: document.getElementById( 'broseph-secret-display' ),
			revealBtn:     document.getElementById( 'broseph-reveal-btn' ),
			copySecretBtn: document.getElementById( 'broseph-copy-secret-btn' ),
			copySiteIdBtn: document.getElementById( 'broseph-copy-siteid-btn' ),
			copyEnvBtn:    document.getElementById( 'broseph-copy-env-btn' ),
			countdown:     document.getElementById( 'broseph-reveal-countdown' ),
			status:        document.getElementById( 'broseph-copy-status' ),
		};

		/* Only run on Broseph > Connection */
		if ( ! el.wrap ) return;

		/* Config injected via PHP data attributes — avoids inline-script CSP issues. */
		var cfg = {
			siteId:   el.wrap.dataset.siteId   || '',
			siteUrl:  el.wrap.dataset.siteUrl  || '',
			restBase: el.wrap.dataset.restBase || '',
		};

		var revealedSecret = null;
		var hideInterval   = null;

		/* ── Accessible status announcements ────────────────────── */

		function announce( msg ) {
			if ( el.status ) {
				el.status.textContent = msg;
			}
		}

		/* ── Mask ───────────────────────────────────────────────── */

		function maskSecret() {
			revealedSecret = null;

			if ( el.secretDisplay ) {
				el.secretDisplay.textContent = el.secretDisplay.dataset.masked || '';
				el.secretDisplay.classList.remove( 'broseph-secret-revealed' );
				el.secretDisplay.classList.add( 'broseph-secret-masked' );
			}
			if ( el.revealBtn ) {
				el.revealBtn.textContent = 'Reveal';
				el.revealBtn.disabled    = false;
				el.revealBtn.setAttribute( 'aria-expanded', 'false' );
			}
			if ( el.copySecretBtn ) {
				el.copySecretBtn.disabled = true;
				el.copySecretBtn.setAttribute( 'aria-disabled', 'true' );
			}
			if ( el.countdown ) { el.countdown.hidden = true; }
			if ( hideInterval ) { clearInterval( hideInterval ); hideInterval = null; }

			announce( 'Secret hidden.' );
		}

		/* ── Reveal ─────────────────────────────────────────────── */

		function revealSecret() {
			/*
			 * The full secret is embedded in data-secret by PHP for manage_options
			 * users only. No AJAX or network request needed.
			 */
			var secret = ( el.secretDisplay && el.secretDisplay.dataset.secret ) || '';

			if ( ! secret ) {
				announce( 'No shared secret is configured. Use Regenerate Secret to create one.' );
				return;
			}

			revealedSecret = secret;

			if ( el.secretDisplay ) {
				el.secretDisplay.textContent = secret;
				el.secretDisplay.classList.remove( 'broseph-secret-masked' );
				el.secretDisplay.classList.add( 'broseph-secret-revealed' );
			}
			if ( el.revealBtn ) {
				el.revealBtn.textContent = 'Hide';
				el.revealBtn.disabled    = false;
				el.revealBtn.setAttribute( 'aria-expanded', 'true' );
			}
			if ( el.copySecretBtn ) {
				el.copySecretBtn.disabled = false;
				el.copySecretBtn.setAttribute( 'aria-disabled', 'false' );
			}

			/* 30-second auto-hide countdown */
			var remaining = 30;
			if ( el.countdown ) {
				el.countdown.hidden      = false;
				el.countdown.textContent = 'Auto-hiding in ' + remaining + 's';
			}

			announce( 'Secret revealed. Auto-hiding in 30 seconds.' );

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

		/* ── Clipboard helpers ──────────────────────────────────── */

		function flashButton( btn, label ) {
			var orig        = btn.textContent;
			btn.textContent = label;
			setTimeout( function () { btn.textContent = orig; }, 2000 );
		}

		function copyText( text, btn ) {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then(
					function () {
						if ( btn ) flashButton( btn, '✓ Copied!' );
						announce( 'Copied to clipboard.' );
					},
					function () { fallbackCopy( text, btn ); }
				);
			} else {
				fallbackCopy( text, btn );
			}
		}

		function fallbackCopy( text, btn ) {
			var ta           = document.createElement( 'textarea' );
			ta.value         = text;
			ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;';
			document.body.appendChild( ta );
			ta.focus();
			ta.select();
			var ok = false;
			try { ok = document.execCommand( 'copy' ); } catch ( e ) { /* silent */ }
			document.body.removeChild( ta );

			if ( ok ) {
				if ( btn ) flashButton( btn, '✓ Copied!' );
				announce( 'Copied to clipboard.' );
			} else {
				if ( btn ) flashButton( btn, '✗ Failed' );
				announce( 'Copy failed. Please select the value and copy it manually.' );
			}
		}

		/* ── Reveal / Hide toggle ───────────────────────────────── */

		if ( el.revealBtn ) {
			el.revealBtn.addEventListener( 'click', function () {
				if ( revealedSecret !== null ) {
					maskSecret();
				} else {
					revealSecret();
				}
			} );
		}

		/* ── Copy Site ID ───────────────────────────────────────── */

		if ( el.copySiteIdBtn ) {
			el.copySiteIdBtn.addEventListener( 'click', function () {
				copyText( cfg.siteId, el.copySiteIdBtn );
			} );
		}

		/* ── Copy Secret (only active when revealed) ────────────── */

		if ( el.copySecretBtn ) {
			el.copySecretBtn.addEventListener( 'click', function () {
				if ( revealedSecret !== null ) {
					copyText( revealedSecret, el.copySecretBtn );
				}
			} );
		}

		/* ── Copy Open Claw ENV template ────────────────────────── */

		if ( el.copyEnvBtn ) {
			el.copyEnvBtn.addEventListener( 'click', function () {
				/*
				 * If the secret has not been revealed, substitute a placeholder so the
				 * user can paste the template immediately and fill in the secret later.
				 */
				var secretVal = revealedSecret !== null ? revealedSecret : 'REVEAL_SECRET_FIRST';
				var env = [
					'BROSEPH_SITE_URL='      + cfg.siteUrl,
					'BROSEPH_REST_BASE='     + cfg.restBase,
					'BROSEPH_SITE_ID='       + cfg.siteId,
					'BROSEPH_SHARED_SECRET=' + secretVal,
				].join( '\n' );
				copyText( env, el.copyEnvBtn );
			} );
		}
	}

	/*
	 * Run after the DOM is ready. Works whether the script fires during parsing
	 * (readyState loading), after it (interactive/complete), or from the footer.
	 */
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

}() );
