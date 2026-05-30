# Broseph Manual Test Plan

Use `tools/sign-request.js` to generate valid headers before each signed request.  
Set environment variables `BROSEPH_SITE_ID` and `BROSEPH_SECRET` before running the script.

---

## 1. Plugin Activation

- Install plugin via `/wp-admin/plugins.php`
- Activate — **expect**: no fatal errors, redirect to plugin list
- Go to **Settings → Broseph**
- **Expect**: Site ID is a UUID, shared secret is masked, all three toggles show defaults (Live Edits off, JS Snippets off, GitPress pages on)
- Check `wp_options` table — confirm `broseph_site_id`, `broseph_shared_secret`, and three toggle options exist
- Check DB — confirm `{prefix}broseph_action_logs` and `{prefix}broseph_reports` tables exist

---

## 2. Settings Page

- Toggle **Allow Live Edits** on, save — **expect**: toggle stays on after reload
- Toggle back off, save — **expect**: option is `0` in DB
- Verify **Plugin Version**, **Site URL**, **WP Version**, **PHP Version**, **Active Theme** all display correctly with no XSS characters

---

## 3. Secret Regeneration

- Click **Regenerate Secret** — **expect**: confirmation dialog appears
- Confirm — **expect**: redirect back to settings page, masked secret shows updated suffix
- Verify old signature no longer works (send signed request with old secret — expect 401)

---

## 4. Unauthorized /status Rejected

```bash
curl -X GET https://example.com/wp-json/broseph/v1/status
```

**Expect**: HTTP 401, `broseph_missing_headers`

---

## 5. Authorized /status Accepted

```bash
# generate headers
node tools/sign-request.js GET /wp-json/broseph/v1/status
# paste headers into curl:
curl -X GET https://example.com/wp-json/broseph/v1/status \
  -H "X-Broseph-Site-Id: ..." \
  -H "X-Broseph-Timestamp: ..." \
  -H "X-Broseph-Nonce: ..." \
  -H "X-Broseph-Signature: ..."
```

**Expect**: HTTP 200, JSON with `auth_status: "accepted"`

---

## 6. Nonce Replay Rejection

- Run the same signed request twice using the same nonce
- **Expect**: first request 200, second request 401 `broseph_nonce_replayed`

---

## 7. /scan

```bash
node tools/sign-request.js POST /wp-json/broseph/v1/scan
curl -X POST ... [signed headers] https://example.com/wp-json/broseph/v1/scan
```

**Expect**: HTTP 200, JSON with `active_plugins`, `is_divi_active`, `admin_email_domain` (not full email), `permalink_structure`

---

## 8. GET /pages

```bash
node tools/sign-request.js GET /wp-json/broseph/v1/pages
curl -X GET ... [signed] "https://example.com/wp-json/broseph/v1/pages?status=publish"
```

**Expect**: HTTP 200, array of pages with `is_divi_page`, `has_gitpress_shortcodes`

---

## 9. GET /pages/{id}

```bash
node tools/sign-request.js GET /wp-json/broseph/v1/pages/1
curl -X GET ... [signed] https://example.com/wp-json/broseph/v1/pages/1
```

**Expect**: HTTP 200, `content_raw`, `seo_meta` object, `gitpress_shortcodes_found` count

---

## 10. POST /pages/duplicate

```bash
node tools/sign-request.js POST /wp-json/broseph/v1/pages/duplicate '{"source_page_id":1,"new_title":"Test Copy","new_slug":"test-copy","copy_meta":true}'
curl -X POST ... [signed] -d '{"source_page_id":1,...}' https://example.com/wp-json/broseph/v1/pages/duplicate
```

**Expect**: HTTP 201, `new_page_id`, `preview_url`; original page unchanged; new page is draft

---

## 11. /gitpress/status

**Expect** (GitPress not installed): `is_active: false`, `shortcode_registered: false`
**Expect** (GitPress installed): `is_active: true`, `plugin_detected_name` populated

---

## 12. /gitpress/validate-shortcode

**Valid shortcode**:
```json
{"shortcode": "[divi_github_content owner=\"acme\" repo=\"content\" path=\"pages/home.html\" format=\"html\"]"}
```
**Expect**: `valid: true`, `errors: []`

**Invalid shortcode** (missing owner/url):
```json
{"shortcode": "[divi_github_content format=\"html\"]"}
```
**Expect**: `valid: false`, errors describing missing fields

---

## 13. /divi/page/{id}/summary

- Use a page ID known to have Divi content
- **Expect**: `is_divi_page: true`, `modules` array with `module_type`, `start_offset`, `end_offset`
- Use a non-Divi page ID — **Expect**: `is_divi_page: false`, `modules: []`

---

## 14. /landing-pages/create — template_native

```json
{
  "mode": "template_native",
  "template_page_id": 1,
  "title": "Estate Sale Companies Bakersfield",
  "slug": "estate-sale-bakersfield",
  "replacements": {"{{CITY}}": "Bakersfield"},
  "meta": {"title": "Estate Sale Bakersfield", "description": "Test"}
}
```

**Expect**: HTTP 201, `status: "draft_created"`, `strategy: "template_native"`, `replaced_tokens: ["{{CITY}}"]`; verify original template unchanged

---

## 15. /landing-pages/create — template_gitpress

- Requires GitPress active
- Use a template page that contains `{{GITPRESS_HERO}}` placeholder

```json
{
  "mode": "template_gitpress",
  "template_page_id": 1,
  "title": "Bakersfield Landing Page",
  "slug": "bakersfield-landing",
  "gitpress": {
    "owner": "acme",
    "repo": "content",
    "base_path": "clients/acme/pages/bakersfield",
    "blocks": [
      {"name": "hero", "path": "hero.html", "format": "html", "placeholder": "{{GITPRESS_HERO}}"}
    ]
  }
}
```

**Expect**: `required_github_files` lists the full path, `inserted_shortcodes` shows the shortcode, new page is draft

---

## 16. /reports/weekly

```bash
node tools/sign-request.js POST /wp-json/broseph/v1/reports/weekly
curl -X POST ... [signed] https://example.com/wp-json/broseph/v1/reports/weekly
```

**Expect**: HTTP 200, `status` is `healthy`/`needs_review`/`critical`, `checks` array, report stored in DB

- Go to **Tools → Broseph Reports** in WP admin — **expect**: latest report appears in table

---

## 17. /tools

```bash
node tools/sign-request.js GET /wp-json/broseph/v1/tools
```

**Expect**: array of 13+ tools, each with `name`, `method`, `endpoint`, `risk_level`, `available`

---

## 18. Logs Page

- Go to **Tools → Broseph Logs** in WP admin
- **Expect**: recent log entries appear after running the above tests
- Status badges colored correctly (green for `accepted`, red for `rejected`)
- Empty state: no PHP notices when no logs exist
