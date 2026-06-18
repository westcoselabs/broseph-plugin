# Open Claw – Broseph Workflow Guide

This document is the authoritative guide for how Open Claw should create and modify WordPress pages through Broseph, and how it should read and respect the Broseph permission system.

---

## Permissions

### How permissions work

Broseph has a granular permission system controlled from **Broseph > Permissions** in the WordPress admin. Every permission maps to a WordPress option and is enforced in three places simultaneously:

1. **Settings UI** — the admin enables/disables the permission.
2. **`/tools` context** — `GET /tools` returns `context.permissions` with the current state of every permission.
3. **Endpoint enforcement** — every mutation/external-effect endpoint calls `PermissionsService` directly before executing; a disabled permission returns HTTP 403 with `code: broseph_permission_denied`.

### After changing a permission

After the admin toggles a permission in Broseph > Permissions and clicks Save, Open Claw **must** call:

```
GET /broseph/v1/tools
```

The response `context.permissions` and each `tool.available` field are the live source of truth. Open Claw should treat these as authoritative — if `available: false`, do not attempt the call.

When Brandon enables **Allow publishing pages**, Open Claw must refresh `GET /broseph/v1/tools` before attempting any publish action.

### Permission error format

When an endpoint rejects due to a disabled permission:

```json
{
  "code": "broseph_permission_denied",
  "message": "This action is disabled in Broseph permissions.",
  "data": {
    "status": 403,
    "permission": "can_create_gitpress_pages"
  }
}
```

The `permission` field tells Open Claw exactly which setting to check in Broseph > Permissions.

### Full permissions map

| Permission key | Default | Controls |
|---|---|---|
| `can_publish_pages` | false | Publishing draft pages |
| `can_live_edit` | false | Editing published pages directly |
| `can_delete_drafts` | false | Trashing draft pages |
| `can_create_gitpress_pages` | true | `/gitpress/pages/create` |
| `can_convert_pages_to_gitpress` | false | `/pages/{id}/convert-to-gitpress` |
| `can_manage_gitpress_layout` | false | `POST /gitpress/managed-layout` (reading the layout is always allowed once signed) |
| `can_create_divi_template_pages` | true | `/divi/pages/create-from-template` |
| `can_edit_divi_code_modules` | true | `/divi/code-module/insert-gitpress` |
| `can_use_js_snippets` | false | JS in code modules |
| `can_send_mail_tests` | true | `/mail/test` |
| `can_submit_form_tests` | false | `/forms/test`, `/forms/full-test` |
| `can_use_form_mail_fallback` | true | Fallback in `/forms/full-test` |
| `can_update_plugins` | false | `/updates/plugins/update-selected` |
| `can_update_themes` | false | `/updates/themes/update-selected` |
| `can_update_active_theme` | false | Active-theme updates (also requires `can_update_themes`) |
| `can_update_inactive_plugins` | false | Inactive plugin updates (also requires `can_update_plugins`) |
| `can_update_core` | false | WordPress core updates |
| `can_execute_php` | **permanently false** | PHP execution — always blocked |
| `can_self_update` | **permanently false** | Broseph self-update — always blocked |

---

---

## Decision Summary

| Situation | Strategy | Endpoint |
|-----------|----------|----------|
| New AI-generated landing page, GitPress active | `gitpress_canvas` | `POST /broseph/v1/gitpress/pages/create` |
| Existing live page must keep page ID/permalink but switch to GitPress | `convert_page_to_gitpress` | `POST /broseph/v1/pages/{id}/convert-to-gitpress` |
| New page cloned from an existing Divi layout | `divi_template` | `POST /broseph/v1/divi/pages/create-from-template` |
| Adding content to an existing Divi page | `existing_divi_code_module` | `POST /broseph/v1/divi/code-module/insert-gitpress` |
| No GitPress, no template | `native_draft` | `POST /broseph/v1/pages/create-draft` |
| Unsure | — | `POST /broseph/v1/content/resolve-strategy` first |

---

## Rules

### 1. GitPress Canvas is the default for new pages

When creating a brand-new AI-generated landing page and GitPress (`divi_github_content` shortcode) is active, **always use `gitpress_canvas`** unless a Divi template layout is explicitly requested.

GitPress Canvas:
- Does not require Divi Builder.
- Sets the shortcode in the page metabox, not in `post_content`.
- Supports `full_page_canvas` mode, which bypasses the theme wrapper.
- The GitHub file must exist (or be created by Open Claw) before the page renders correctly.

### 2. Divi Template only when a layout is requested

Use `divi_template` only when:
- A `template_page_id` is explicitly provided, **or**
- `requires_divi_layout: true` is passed to the resolver.

Do not choose `divi_template` simply because Divi is active.

### 3. Divi Code Module insertion is for existing pages only

Use `existing_divi_code_module` only when:
- A `page_id` pointing to an **existing** Divi page is provided.
- The goal is to add or modify content on that page.

Do not use Code Module insertion for brand-new pages.

### 3b. Convert an existing page when the URL and page ID must be preserved

Use `convert_page_to_gitpress` when an existing live page should keep its original page ID, permalink, slug, title, status, parent, menu relationships, featured image, and SEO metadata, but should stop relying on Divi/native `post_content` for rendering.

For published page conversions:
- Always use `backup_first: true`.
- Always include `expected_slug` and `expected_status`.
- Never use `create_gitpress_page` when the goal is to preserve an existing URL/page ID.
- Never use Divi Code Module insertion when the goal is to stop using Divi content.
- Do not publish or unpublish as part of conversion; the endpoint preserves the current status.

### 4. Never combine GitPress Canvas and Code Module on the same new page

`gitpress_canvas` and `existing_divi_code_module` are mutually exclusive workflows for new pages. Combining them on the same page is only valid if the user explicitly requests it after understanding both approaches.

### 5. Always draft, never publish

Every page creation endpoint in Broseph creates a `draft`. Pages are never published automatically. A human must review and publish from wp-admin.

### 5b. Publish and trash require explicit page IDs

To publish pages, Open Claw must use `POST /broseph/v1/pages/bulk-publish` with an explicit `page_ids` array. Never publish without explicit user instruction.

To remove probe drafts, Open Claw must use `POST /broseph/v1/pages/bulk-trash` with an explicit `page_ids` array. Only draft and pending pages may be trashed. Never trash without explicit user instruction.

### 6. Call `/content/resolve-strategy` when unsure

Before creating any page, if the correct workflow is unclear, call the resolver endpoint. It returns the recommended strategy, the exact endpoint to call next, and all required parameters.

---

## GitPress Managed Mode

GitPress / Divi GitHub Sync supports three page render modes. All three are accepted anywhere Broseph accepts a `render_mode` value (`/gitpress/pages/create`, `/pages/{id}/convert-to-gitpress`):

- **`theme_wrapped`** — Use for normal pages that should use the client's existing Divi/WordPress header, menu, and footer.
- **`full_canvas`** — Use for standalone landing pages that should render only the page body shortcode, bypassing the theme wrapper.
- **`gitpress_managed`** — Use when GitPress itself should control the reusable header and footer, sourced from GitHub via the global GitPress-managed shortcodes (`dgs_managed_header_shortcode` / `dgs_managed_footer_shortcode`), with the page body rendered between them.

`gitpress_managed` always normalizes `full_page_canvas` to `true` and `render_position` to `replace`; `full_width_content` is ignored. Conflicting values sent by Open Claw are normalized safely and reported back in the `warnings` array rather than rejected.

GitPress Managed mode is never forced onto existing pages — it is only applied when `render_mode: "gitpress_managed"` is explicitly passed to `create_gitpress_page` or `convert_page_to_gitpress`.

### Reading and updating the managed header/footer

The global managed header/footer shortcodes are site-wide settings, separate from any individual page. Open Claw can read them at any time, but updating them requires the **"Allow managing GitPress header/footer layout"** permission (`can_manage_gitpress_layout`, default `false`).

**Read (always allowed once signed):**
```
GET /wp-json/broseph/v1/gitpress/managed-layout
```
```json
{
  "gitpress_active": true,
  "render_mode_supported": true,
  "header": { "shortcode": "[divi_github_content owner=\"...\" repo=\"...\" branch=\"main\" path=\"global/header.html\" format=\"html\"]", "is_set": true },
  "footer": { "shortcode": "[divi_github_content owner=\"...\" repo=\"...\" branch=\"main\" path=\"global/footer.html\" format=\"html\"]", "is_set": true },
  "warnings": []
}
```

**Update (requires `can_manage_gitpress_layout`):**
```
POST /wp-json/broseph/v1/gitpress/managed-layout
```
```json
{
  "header_shortcode": "[divi_github_content owner=\"citrynmarketingdevelopment\" repo=\"wp-landingpages\" branch=\"main\" path=\"global/headers/optimax-header.html\" format=\"html\"]",
  "footer_shortcode": "[divi_github_content owner=\"citrynmarketingdevelopment\" repo=\"wp-landingpages\" branch=\"main\" path=\"global/footers/optimax-footer.html\" format=\"html\"]"
}
```

Only `[divi_github]` and `[divi_github_content]` shortcodes are accepted. Script tags, PHP tags, `javascript:` URLs, credential URLs, and token/secret/key query parameters are always rejected. If only one of the two fields is invalid, the valid field is still saved and the invalid one is reported in `warnings`. Update this setting only when Brandon explicitly asks to change the managed header or footer — never as a side effect of creating or converting a page.

### Standard Open Claw flow for GitPress Managed pages

1. Refresh `GET /broseph/v1/tools`.
2. Confirm `gitpress_managed` is listed in `context.gitpress.supported_render_modes`.
3. Confirm the managed header/footer shortcodes are set using `GET /gitpress/managed-layout` (or check `context.gitpress.managed_layout` from `/tools`).
4. If Brandon explicitly asks to set or update the managed header/footer, use `POST /gitpress/managed-layout`.
5. Create or convert the page with `render_mode: "gitpress_managed"`.
6. Verify the live/preview output shows the GitPress header, the page body, and the GitPress footer.
7. Confirm the Divi/theme header and footer are not duplicated on the page.

---

## Example Payloads

### 1. Create a GitPress Canvas page

**Endpoint:** `POST /wp-json/broseph/v1/gitpress/pages/create`
**Signed routePath:** `/broseph/v1/gitpress/pages/create`

```json
{
  "title": "Broseph",
  "slug": "broseph",
  "excerpt": "AI WordPress control hub for Open Claw.",
  "shortcode": "[divi_github_content owner=\"westcoselabs\" repo=\"site-content\" path=\"clients/citryn/pages/broseph/index.html\" format=\"html\"]",
  "render_position": "after",
  "full_page_canvas": true,
  "meta": {
    "title": "Broseph | AI WordPress Agent Connector",
    "description": "Broseph connects Open Claw to WordPress with safe signed API tools."
  }
}
```

**`render_position` values:** `"before"` | `"after"` | `"replace"`
(Also accepts `"before_content"`, `"after_content"`, `"replace_content"` — normalised automatically.)

**Expected response:**
```json
{
  "status": "draft_created",
  "page_id": 312,
  "preview_url": "https://example.com/?page_id=312&preview=true",
  "edit_url": "https://example.com/wp-admin/post.php?post=312&action=edit",
  "shortcode": "[divi_github_content owner=\"westcoselabs\" ...]",
  "render_position": "after",
  "full_page_canvas": true,
  "warnings": []
}
```

---

### 1b. Create a GitPress Managed page

**Endpoint:** `POST /wp-json/broseph/v1/gitpress/pages/create`
**Signed routePath:** `/broseph/v1/gitpress/pages/create`

```json
{
  "title": "Payroll Services Denver, CO",
  "slug": "payroll-services-denver-co",
  "shortcode": "[divi_github_content owner=\"citrynmarketingdevelopment\" repo=\"wp-landingpages\" branch=\"main\" path=\"optimax/payroll-services-denver-co.html\" format=\"html\"]",
  "render_mode": "gitpress_managed",
  "render_position": "replace",
  "full_width_content": false,
  "full_page_canvas": true
}
```

GitPress renders this page between the global managed header and footer shortcodes — Open Claw does not need to repeat header/footer content per page. The page is always created as a `draft`.

---

### 2. Create a page from a Divi template

**Endpoint:** `POST /wp-json/broseph/v1/divi/pages/create-from-template`
**Signed routePath:** `/broseph/v1/divi/pages/create-from-template`

```json
{
  "template_page_id": 327232,
  "title": "Services — AI Automation",
  "slug": "services-ai-automation",
  "excerpt": "How we use AI to automate your business.",
  "replacements": {
    "{{H1}}": "AI Automation Services",
    "{{INTRO}}": "We build AI workflows that save your team hours every week."
  },
  "meta": {
    "title": "AI Automation Services | WestCose Labs",
    "description": "Custom AI automation for modern businesses."
  },
  "code_modules": [
    {
      "mode": "append",
      "label": "Services intro block",
      "content": "[divi_github_content owner=\"westcoselabs\" repo=\"site-content\" path=\"clients/citryn/pages/services/intro.md\" format=\"markdown\"]"
    }
  ]
}
```

**Expected response:**
```json
{
  "status": "draft_created",
  "page_id": 418,
  "preview_url": "https://example.com/?page_id=418&preview=true",
  "edit_url": "https://example.com/wp-admin/post.php?post=418&action=edit",
  "divi_builder_active": true,
  "inserted_code_modules": 1,
  "replaced_tokens": ["{{H1}}", "{{INTRO}}"],
  "warnings": []
}
```

> **Note:** `code_modules` supports only `"mode": "append"` in v1.

---

### 3. Insert a GitPress Code Module into an existing Divi page

**Endpoint:** `POST /wp-json/broseph/v1/divi/code-module/insert-gitpress`
**Signed routePath:** `/broseph/v1/divi/code-module/insert-gitpress`

```json
{
  "page_id": 59,
  "shortcode": "[divi_github_content owner=\"westcoselabs\" repo=\"site-content\" path=\"clients/citryn/pages/contact/faq.md\" format=\"markdown\"]",
  "placement": {
    "mode": "append_to_page"
  },
  "save_mode": "draft_copy"
}
```

**`placement.mode` values:** `"append_to_page"` | `"after_module_index"`
**`save_mode` values:** `"draft_copy"` (default) | `"update_draft_only"` | `"live_edit"` (requires setting enabled)

**Expected response:**
```json
{
  "target_page_id": 423,
  "original_page_id": 59,
  "preview_url": "https://example.com/?page_id=423&preview=true",
  "inserted_shortcode": "[divi_github_content owner=\"westcoselabs\" ...]",
  "save_mode_used": "draft_copy"
}
```

---

### 4. Resolve the correct strategy before acting

**Endpoint:** `POST /wp-json/broseph/v1/content/resolve-strategy`
**Signed routePath:** `/broseph/v1/content/resolve-strategy`

**Scenario A — new page, no constraints:**
```json
{
  "task_type": "create_landing_page",
  "preferred_mode": "auto"
}
```

**Expected response (GitPress active):**
```json
{
  "strategy": "gitpress_canvas",
  "endpoint": "/broseph/v1/gitpress/pages/create",
  "reason": "GitPress is active — gitpress_canvas is the recommended default for new AI-generated landing pages. No Divi Builder required.",
  "requires_github_files": true,
  "requires_divi": false,
  "requires_gitpress": true,
  "requires_existing_page": false,
  "requires_template_page": false,
  "requires_approval": false,
  "warnings": []
}
```

**Scenario B — clone a specific Divi layout:**
```json
{
  "task_type": "create_landing_page",
  "preferred_mode": "auto",
  "template_page_id": 327232,
  "requires_divi_layout": true
}
```

**Expected response:**
```json
{
  "strategy": "divi_template",
  "endpoint": "/broseph/v1/divi/pages/create-from-template",
  "reason": "requires_divi_layout is true with template_page_id provided; cloning Divi layout.",
  "requires_github_files": false,
  "requires_divi": false,
  "requires_gitpress": false,
  "requires_existing_page": false,
  "requires_template_page": true,
  "requires_approval": false,
  "warnings": []
}
```

**Scenario C — modify an existing Divi page:**
```json
{
  "task_type": "create_landing_page",
  "preferred_mode": "auto",
  "page_id": 59
}
```

**Expected response (page 59 is a Divi page):**
```json
{
  "strategy": "existing_divi_code_module",
  "endpoint": "/broseph/v1/divi/code-module/insert-gitpress",
  "reason": "Target page is a Divi page — use Divi Code Module insertion to add GitPress content.",
  "requires_github_files": false,
  "requires_divi": true,
  "requires_gitpress": true,
  "requires_existing_page": true,
  "requires_template_page": false,
  "requires_approval": false,
  "warnings": []
}
```

**`preferred_mode` options:**
- `"auto"` — let Broseph choose based on the rules above
- `"gitpress_canvas"` — force GitPress Canvas (fails if GitPress not active)
- `"divi_template"` — force Divi template clone
- `"existing_divi_code_module"` — force Code Module insertion
- `"native_draft"` — force plain draft creation
- `"template_gitpress"` — Divi template + GitPress blocks (legacy combined mode)
- `"proposal_only"` — return a plan without executing

---

## Strategy Decision Tree

```
Create or modify a page?
│
├── Modifying an existing page?
│   └── Is it a Divi page? ─── Yes ──▶ existing_divi_code_module
│                                        POST /divi/code-module/insert-gitpress
│
└── Creating a new page
    │
    ├── requires_divi_layout: true + template_page_id given?
    │   └── Yes ──▶ divi_template
    │               POST /divi/pages/create-from-template
    │
    ├── GitPress active?
    │   └── Yes ──▶ gitpress_canvas  ◀── DEFAULT
    │               POST /gitpress/pages/create
    │
    ├── template_page_id given?
    │   └── Yes ──▶ divi_template
    │               POST /divi/pages/create-from-template
    │
    └── Nothing available ──▶ native_draft
                              POST /pages/create-draft
```

---

## Plugin and Theme Update Workflow

### When Open Claw may update plugins or themes

Open Claw may update selected plugins or themes when **all three** conditions are met:

1. The **"Allow plugin/theme updates"** setting is enabled in Broseph > Settings.
2. The user (Brandon) has **explicitly prompted** for the update.
3. The request payload contains an **explicit list** of plugins (`plugin_files`) or themes (`themes`) to update.

No confirm token is required. The admin setting is the safety gate.

### What is always skipped (regardless of settings)

- WordPress core — not supported in any Broseph phase.
- Broseph itself — the plugin cannot update itself.
- The active theme — skipped unless `allow_active_theme: true` is passed explicitly.
- Inactive plugins — skipped unless `allow_inactive: true` is passed explicitly.
- Plugins or themes not in the explicit list — no bulk updates.
- Plugins with no available update — silently reported as `skipped`.

### Example: update a theme

**Endpoint:** `POST /wp-json/broseph/v1/updates/themes/update-selected`
**Signed routePath:** `/broseph/v1/updates/themes/update-selected`

```json
{
  "themes": ["twentytwentyfive"]
}
```

**Expected response:**
```json
{
  "results": [
    {
      "theme": "twentytwentyfive",
      "name": "Twenty Twenty-Five",
      "previous_version": "1.0",
      "target_version": "1.1",
      "status": "updated",
      "message": "Updated from 1.0 to 1.1."
    }
  ],
  "summary": {
    "updated": 1,
    "skipped": 0,
    "failed": 0
  }
}
```

### Example: update selected plugins

**Endpoint:** `POST /wp-json/broseph/v1/updates/plugins/update-selected`
**Signed routePath:** `/broseph/v1/updates/plugins/update-selected`

```json
{
  "plugin_files": [
    "contact-form-7/wp-contact-form-7.php",
    "wordfence/wordfence.php"
  ],
  "allow_inactive": false
}
```

### Error responses

**Updates disabled (403):**
```json
{
  "code": "broseph_updates_disabled",
  "message": "Plugin/theme updates are disabled in Broseph settings. Enable \"Allow plugin/theme updates\" under Broseph > Settings."
}
```

**Empty list (400):**
```json
{
  "code": "broseph_bad_request",
  "message": "No plugins were selected for update. Provide a non-empty plugin_files array."
}
```

### Pre-update checklist

Before sending an update request:

- [ ] Confirm with the user that they want to apply the update now.
- [ ] Call `GET /updates` first to verify which updates are available and that `updates_allowed: true`.
- [ ] Build the explicit list — never send a wildcard or "update all".
- [ ] Note the `previous_version` from the response for rollback reference.

---

## Safety Checklist

Before any page creation call:

- [ ] The page will be created as a **draft** (never published automatically).
- [ ] The original template page is **not modified**.
- [ ] GitPress shortcode content is **validated** before saving (use `/gitpress/validate-shortcode`).
- [ ] PHP is **not** included in any code module content.
- [ ] Script tags are only included if `broseph_allow_js_snippets` is enabled in Broseph Settings.
- [ ] The GitHub file referenced by any `[divi_github_content]` shortcode **exists** before the page preview is shown to a user.
