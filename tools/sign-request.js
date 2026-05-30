#!/usr/bin/env node
/**
 * Broseph request signer — local development / testing only.
 *
 * Usage:
 *   node sign-request.js <METHOD> <PATH> [body]
 *
 * Environment variables:
 *   BROSEPH_SITE_ID  — copied from Settings → Broseph in WordPress
 *   BROSEPH_SECRET   — raw value from wp_options (broseph_shared_secret)
 *
 * Example:
 *   BROSEPH_SITE_ID=abc BROSEPH_SECRET=xyz node sign-request.js GET /wp-json/broseph/v1/status
 *
 * Never commit real credentials. Use a .env file (gitignored) or export them in your shell.
 */

'use strict';

const crypto = require('crypto');

const SITE_ID = process.env.BROSEPH_SITE_ID || '';
const SECRET  = process.env.BROSEPH_SECRET  || '';

if ( ! SITE_ID || ! SECRET ) {
	console.error(
		'ERROR: Set BROSEPH_SITE_ID and BROSEPH_SECRET environment variables before running this script.'
	);
	process.exit( 1 );
}

const method    = ( process.argv[2] || 'GET' ).toUpperCase();
// path is the REST route WITHOUT the /wp-json prefix — this is what WordPress
// passes to $request->get_route() and uses for signature verification.
const path      = process.argv[3] || '/broseph/v1/status';
const body      = process.argv[4] || '';

const timestamp = Math.floor( Date.now() / 1000 ).toString();
const nonce     = crypto.randomBytes( 16 ).toString( 'hex' );
const bodyHash  = crypto.createHash( 'sha256' ).update( body, 'utf8' ).digest( 'hex' );
const payload   = [ method, path, timestamp, nonce, bodyHash ].join( '\n' );
const signature = crypto.createHmac( 'sha256', SECRET ).update( payload ).digest( 'hex' );

const headers = {
	'X-Broseph-Site-Id'   : SITE_ID,
	'X-Broseph-Timestamp' : timestamp,
	'X-Broseph-Nonce'     : nonce,
	'X-Broseph-Signature' : signature,
};

console.log( '\n── Broseph Signed Headers ──────────────────────────────' );
for ( const [ key, val ] of Object.entries( headers ) ) {
	console.log( `${key}: ${val}` );
}

const curlBody = body ? `\\\n  -H "Content-Type: application/json" \\\n  -d '${body}'` : '';

console.log( '\n── curl example ────────────────────────────────────────' );
console.log(
	`curl -X ${method} \\\n` +
	Object.entries( headers )
		.map( ( [ k, v ] ) => `  -H "${k}: ${v}"` )
		.join( ' \\\n' ) +
	' \\\n' +
	curlBody +
	`  "https://YOUR-SITE.com${path}"\n`
);
