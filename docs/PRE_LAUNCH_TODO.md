# Pre-Launch To-Do

A running checklist of things to address before releasing the plugin. Nothing
here is a known bug in current behavior — it's hardening, polish, business, and
feature work for launch.

## Blockers / must-do before release

- [x] **Gate debug logging behind a switch.** All plugin `error_log()` calls now
      route through a single `wc_gs_log()` helper that writes only when `WP_DEBUG`
      is on **or** the new "Debug logging" setting is enabled (off by default), so a
      normal production sync writes nothing. Filterable via `wc_gs_debug_logging`.
      *(Minor follow-up: the `print_r()` arguments in some calls still build their
      string even when logging is off — CPU only, no disk writes; could be made
      lazy later.)*
- [x] **Bump compatibility headers.** Bumped to "Tested up to: 6.8" / "WC tested up
      to: 9.8" in the main file and `readme.txt`. *(Set to recent stable versions;
      confirm/raise to the exact current WP/WooCommerce you've tested on.)*
- [x] **Reconcile plugin metadata.** Branding settled: **Author = Wapiti Digital**
      (`Author URI: https://wapiti.digital/`), **Plugin URI = ultimatesubscriptions.com**
      (the sales page). `composer.json` already matched (Wapiti Digital /
      wapiti.digital).
- [x] **"Get Template Google Sheet" button** now points at the real template
      (`WC_GS_SYNC_TEMPLATE_URL`, the `/copy` link) and is shown on the add-sheet
      screen, configure screen, and Help tab.
- [x] **Decide on the unused `wc_gs_sync_logs` table.** Decision: stop creating it.
      The activation routine no longer creates the table and now drops any legacy
      copy (`cleanup_legacy_tables()`); `uninstall.php` still drops it too.
- [~] **Finalize the release.** Version set to **1.3.0** (main header +
      `WC_GS_SYNC_VERSION`), `readme.txt` Stable tag → 1.3.0 with a 1.3.0 changelog
      entry, and `CHANGELOG.md [Unreleased]` moved to **[1.3.0] - 2026-07-20**.
      Remaining: bump the compatibility headers (below) and tag the release at
      merge time.
- [~] **Independent security review.** A full-plugin code pass was run: all AJAX /
      admin actions are nonce- + capability-gated with sanitized input and safe
      redirects; OAuth callback is CSRF-protected (state nonce); all SQL uses
      `$wpdb->prepare`; output is escaped (stats are int-cast); image download is
      SSRF-guarded (`wp_http_validate_url`) and time-capped; no anonymous
      (`nopriv`) endpoints; tokens/secrets are never logged. **No high/medium
      issues found.** Low/hardening: (1) constrained the client-supplied `sync_id`
      to a safe key in the progress/cancel handlers [done]; (2) secrets/token are
      stored plaintext in options — consider encryption-at-rest (below). A
      commercial release should still get an **external** review (Patchstack/WPScan)
      before sale.

## Features (paid tier / roadmap)

- [ ] **Variable products support** (currently simple products only). This is the
      main premium feature from the monetization plan. When other product types
      (variable/grouped/external) are added, **scope the `Virtual` / `Downloadable`
      / `Download *` columns to `Type === simple`** — those flags only exist on
      simple products in WooCommerce. Not needed today because the plugin only ever
      creates simple products, so those columns can't reach another type yet.
- [ ] **Licensing / freemium layer.** Add a provider (Lemon Squeezy / Paddle /
      Freemius) and wire the gates: connected-sheet limit (hook
      `wc_gs_sync_max_sheets` already exists), variable products, and auto-sync.
- [x] **Removed the "unverified app" warning.** The plugin now requests only the
      non-sensitive `drive.file` scope and lets the user choose the spreadsheet
      with the Google Picker, so there is no "Google hasn't verified this app"
      screen and no verification/CASA requirement. Setup still uses BYO
      credentials (Client ID/Secret + an API key for the Picker).
- [ ] **Reduce OAuth onboarding friction further (post-launch).** Users still
      create their own Google Cloud project, OAuth client, and API key. The
      friction-free upgrade is a hosted "connect" broker so users sign in with one
      click and never touch the Google Cloud Console — a larger project deferred
      for now.

## Polish / nice-to-have

- [ ] **Shorter auto-sync intervals** (e.g., every 15 minutes) — cheap now that
      unchanged rows are skipped.
- [ ] **Dashboard hint when a sheet isn't included in auto-sync** (global Auto
      Sync on, but the per-sheet box off) so nobody is confused by "not syncing".
- [ ] **Fuller two-way write-back (optional product decision).** Today only SKU,
      GTIN and Quantity write back to the sheet from WooCommerce; decide whether
      price/stock/featured/etc. should too (and who wins on conflict).
- [ ] **`composer audit` in CI** (or a periodic check). Dependency CVEs are a
      moving target; today the vendor tree is clean.
- [ ] **i18n:** regenerate the `.pot` so all the new strings are translatable.
- [ ] **Large-catalog testing** (thousands of rows). The whole job (all rows) is
      stored in one option (`wc_gs_sync_job_*`); validate memory/size and the
      inline-vs-Action-Scheduler hand-off at scale.
- [ ] **Uninstall scope.** Currently removes options, tokens, transients and
      job/state. Decide whether to also remove per-product meta
      (`_wc_gs_data_hash`, `_wc_gs_source_url`) and generated `pa_*` attributes
      (probably leave product data intact — document the choice).
- [ ] **Surface the server-cron guidance** (already on Settings) more prominently
      for users who rely on auto-sync, since WP-Cron is traffic-triggered.

## Known design notes (document, not necessarily change)

- The Google Sheet is the source of truth; editing a product directly in
  WooCommerce is not written back (except SKU/GTIN/Quantity) and is not reverted
  by an unchanged sheet row. (Explained in the Help tab.)
- Auto-sync requires BOTH the global switch and the per-sheet checkbox.
- Attributes that collided into one taxonomy on a pre-fix version are not
  auto-cleaned; remove the mixed attribute and re-sync.
