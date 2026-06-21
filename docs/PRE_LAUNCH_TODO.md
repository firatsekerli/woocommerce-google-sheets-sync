# Pre-Launch To-Do

A running checklist of things to address before releasing the plugin. Nothing
here is a known bug in current behavior — it's hardening, polish, business, and
feature work for launch.

## Blockers / must-do before release

- [ ] **Gate debug logging behind a switch.** The plugin calls `error_log()`
      heavily on every sync (`WC_GS_Sync:` per-row `print_r`, `WC_GS_Timing:`,
      image/attribute steps). Wrap them in one helper that only logs when
      `WP_DEBUG` is on (or a "Debug logging" setting, off by default) so normal
      use writes nothing and the log file doesn't grow.
- [ ] **Bump compatibility headers.** `readme.txt` / main file say "Tested up to:
      6.5" and "WC tested up to: 8.9" — update to current WP/WooCommerce and
      re-test.
- [ ] **Reconcile plugin metadata.** Plugin URI / Author URI point to
      `ultimatesubscriptions.com` while `composer.json` author is
      `wapiti-digital`. Pick the correct branding before launch.
- [x] **"Get Template Google Sheet" button** now points at the real template
      (`WC_GS_SYNC_TEMPLATE_URL`, the `/copy` link) and is shown on the add-sheet
      screen, configure screen, and Help tab.
- [ ] **Decide on the unused `wc_gs_sync_logs` table.** It's created on activation
      but never used (the logger was removed). Either build a sync-history view on
      it or stop creating it.
- [ ] **Finalize the release.** Move `CHANGELOG.md [Unreleased]` to a version,
      set `readme.txt` Stable tag to match the plugin header (currently 1.2.0),
      and tag the release.
- [ ] **Independent security review.** A code-level pass + base-code audit was
      done and fixes applied, but a commercial release should get an external
      review (e.g., Patchstack/WPScan) before sale.

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
