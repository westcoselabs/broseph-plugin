# Broseph — Example curl Requests

All requests require signed headers.  
Generate headers with: `node tools/sign-request.js <METHOD> <PATH> [body]`

Replace `https://example.com` and `[HEADERS]` with your site URL and generated headers.

---

## GET /status

```bash
node tools/sign-request.js GET /wp-json/broseph/v1/status

curl -X GET https://example.com/wp-json/broseph/v1/status \
  -H "X-Broseph-Site-Id: $BROSEPH_SITE_ID" \
  -H "X-Broseph-Timestamp: $TS" \
  -H "X-Broseph-Nonce: $NONCE" \
  -H "X-Broseph-Signature: $SIG"
```

---

## POST /scan

```bash
node tools/sign-request.js POST /wp-json/broseph/v1/scan

curl -X POST https://example.com/wp-json/broseph/v1/scan \
  [HEADERS]
```

---

## GET /pages

```bash
node tools/sign-request.js GET /wp-json/broseph/v1/pages

curl -X GET "https://example.com/wp-json/broseph/v1/pages?status=publish,draft&per_page=20" \
  [HEADERS]
```

---

## GET /pages/{id}

```bash
node tools/sign-request.js GET /wp-json/broseph/v1/pages/42

curl -X GET https://example.com/wp-json/broseph/v1/pages/42 \
  [HEADERS]
```

---

## POST /pages/duplicate

```bash
BODY='{"source_page_id":42,"new_title":"My Copy","new_slug":"my-copy","copy_meta":true}'
node tools/sign-request.js POST /wp-json/broseph/v1/pages/duplicate "$BODY"

curl -X POST https://example.com/wp-json/broseph/v1/pages/duplicate \
  [HEADERS] \
  -H "Content-Type: application/json" \
  -d "$BODY"
```

---

## POST /landing-pages/create — template_native

```bash
BODY='{
  "mode": "template_native",
  "template_page_id": 10,
  "title": "Estate Sale Companies Bakersfield",
  "slug": "estate-sale-companies-bakersfield",
  "excerpt": "Looking for estate sale companies in Bakersfield?",
  "replacements": {
    "{{CITY}}": "Bakersfield",
    "{{SERVICE}}": "Estate Sale Services",
    "{{PRIMARY_KEYWORD}}": "estate sale companies Bakersfield"
  },
  "meta": {
    "title": "Estate Sale Companies Bakersfield | Simply Decorated",
    "description": "Top-rated estate sale companies serving Bakersfield, CA."
  }
}'
node tools/sign-request.js POST /wp-json/broseph/v1/landing-pages/create "$BODY"

curl -X POST https://example.com/wp-json/broseph/v1/landing-pages/create \
  [HEADERS] \
  -H "Content-Type: application/json" \
  -d "$BODY"
```

---

## POST /landing-pages/create — template_gitpress

```bash
BODY='{
  "mode": "template_gitpress",
  "template_page_id": 10,
  "title": "Estate Sale Bakersfield",
  "slug": "estate-sale-bakersfield",
  "meta": {
    "title": "Estate Sale Bakersfield | Simply Decorated",
    "description": "Professional estate sale services in Bakersfield CA."
  },
  "gitpress": {
    "owner": "citrynmarketingdevelopment",
    "repo": "site-content",
    "base_path": "clients/simply-decorated/pages/estate-sale-bakersfield",
    "blocks": [
      {"name":"hero",  "path":"hero.html",  "format":"html",     "placeholder":"{{GITPRESS_HERO}}"},
      {"name":"intro", "path":"intro.md",   "format":"markdown", "placeholder":"{{GITPRESS_INTRO}}"},
      {"name":"faqs",  "path":"faqs.md",    "format":"markdown", "placeholder":"{{GITPRESS_FAQS}}"}
    ]
  }
}'
node tools/sign-request.js POST /wp-json/broseph/v1/landing-pages/create "$BODY"
```

---

## POST /gitpress/validate-shortcode

```bash
BODY='{"shortcode":"[divi_github_content owner=\"acme\" repo=\"content\" path=\"pages/home.html\" format=\"html\"]"}'
node tools/sign-request.js POST /wp-json/broseph/v1/gitpress/validate-shortcode "$BODY"
```

---

## GET /divi/page/{id}/summary

```bash
node tools/sign-request.js GET /wp-json/broseph/v1/divi/page/42/summary

curl -X GET https://example.com/wp-json/broseph/v1/divi/page/42/summary \
  [HEADERS]
```

---

## POST /divi/code-module/insert-gitpress

```bash
BODY='{
  "page_id": 42,
  "shortcode": "[divi_github_content owner=\"acme\" repo=\"content\" path=\"pages/hero.html\" format=\"html\"]",
  "placement": {"mode": "append_to_page"},
  "save_mode": "draft_copy"
}'
node tools/sign-request.js POST /wp-json/broseph/v1/divi/code-module/insert-gitpress "$BODY"
```

---

## POST /reports/weekly

```bash
node tools/sign-request.js POST /wp-json/broseph/v1/reports/weekly

curl -X POST https://example.com/wp-json/broseph/v1/reports/weekly \
  [HEADERS]
```

---

## GET /tools

```bash
node tools/sign-request.js GET /wp-json/broseph/v1/tools

curl -X GET https://example.com/wp-json/broseph/v1/tools \
  [HEADERS]
```
