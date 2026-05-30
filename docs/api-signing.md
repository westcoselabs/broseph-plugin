# Broseph API — Request Signing

All Broseph REST endpoints require HMAC-SHA256 request signing.  
No public/unauthenticated Broseph routes exist.

---

## Required Headers

| Header | Description |
|---|---|
| `X-Broseph-Site-Id` | UUID stored as `broseph_site_id` in WordPress options |
| `X-Broseph-Timestamp` | Unix timestamp (seconds). Must be within ±5 minutes of server time |
| `X-Broseph-Nonce` | Unique random string per request (16+ hex chars). Cannot be reused within 10 minutes |
| `X-Broseph-Signature` | HMAC-SHA256 signature (hex) |

---

## Signature Algorithm

```
signature = HMAC_SHA256(
    method    + "\n" +
    path      + "\n" +
    timestamp + "\n" +
    nonce     + "\n" +
    body_hash,
    shared_secret
)
```

Where:

| Variable | Value |
|---|---|
| `method` | Uppercase HTTP method: `GET`, `POST` |
| `path` | WordPress REST **route** — omit the `/wp-json` prefix, e.g. `/broseph/v1/status` |
| `timestamp` | Same value sent in `X-Broseph-Timestamp` |
| `nonce` | Same value sent in `X-Broseph-Nonce` |
| `body_hash` | SHA-256 of the raw request body (UTF-8 bytes). For GET requests with no body, use SHA-256 of an empty string |
| `shared_secret` | 64-char hex string stored as `broseph_shared_secret` (never exposed in responses) |

### Node.js pseudocode

```javascript
const crypto   = require('crypto');
const method   = 'GET';
const path     = '/wp-json/broseph/v1/status';
const body     = '';                          // empty for GET
const ts       = Math.floor(Date.now() / 1000).toString();
const nonce    = crypto.randomBytes(16).toString('hex');
const bodyHash = crypto.createHash('sha256').update(body).digest('hex');
const payload  = [method, path, ts, nonce, bodyHash].join('\n');
const sig      = crypto.createHmac('sha256', sharedSecret).update(payload).digest('hex');
```

### PHP pseudocode

```php
$method    = 'GET';
$path      = '/wp-json/broseph/v1/status';
$body      = '';
$ts        = time();
$nonce     = bin2hex(random_bytes(16));
$body_hash = hash('sha256', $body);
$payload   = implode("\n", [$method, $path, $ts, $nonce, $body_hash]);
$sig       = hash_hmac('sha256', $payload, $shared_secret);
```

---

## Error Codes

| Error Code | HTTP | Meaning |
|---|---|---|
| `broseph_missing_headers` | 401 | One or more required headers absent |
| `broseph_invalid_site_id` | 401 | `X-Broseph-Site-Id` doesn't match stored value |
| `broseph_expired_timestamp` | 401 | Timestamp outside ±5-minute window |
| `broseph_nonce_replayed` | 401 | Nonce already used within the last 10 minutes |
| `broseph_invalid_signature` | 401 | HMAC verification failed |

---

## Finding Your Credentials

1. In WordPress admin, go to **Settings → Broseph**
2. Copy the **Site ID** (UUID)
3. The **Shared Secret** is masked in the UI; retrieve it directly from `wp_options` where `option_name = 'broseph_shared_secret'`

> **Never commit the shared secret to version control.**
