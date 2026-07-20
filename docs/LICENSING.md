# Licensing & Monetization

How the paid/Pro model works and how the plugin's license **client** talks to the
store (the license **server**).

## Model

- **Sold paid-only via ultimatesubscriptions.com** (WooCommerce store).
- **Licensing server:** *License Manager for WooCommerce* (LMFWC) on the store,
  issuing keys and exposing its REST API for activate/validate.
- **One plugin build.** Behavior is gated by the license the user has.

### Tiers (flexible — decide later)
- Default: **Free base + Pro.** Base features work with no key; the Pro features
  below require a valid **Pro** license.
- To switch to **Standard + Pro** (a paid base tier, no free), set the filter
  `wc_gs_license_base_requires_license` to `true` — then base features also require
  a valid license.

### Pro (gated) features
| Feature key | What it gates |
|---|---|
| `variable_products` | Importing/exporting variable products (variation rows) |
| `auto_sync` | Scheduled automatic syncing |
| `multi_sheet` | More than one connected sheet |
| `advanced_fields` | Custom `Meta` columns (ACF/SEO) + multiple category paths |

Everything else (simple products, manual import/export, all standard fields, one
sheet) is **base**.

## Rollout is safe by default

Enforcement is **OFF** until explicitly enabled, so the plugin behaves exactly as
it does today until the store side is ready. Enable it with either:

```php
define('WC_GS_LICENSE_ENFORCE', true);   // wp-config.php
// or
add_filter('wc_gs_license_enforced', '__return_true');
```

When off, `WC_GS_License::instance()->can($feature)` always returns `true`.

## Client ↔ server (LMFWC REST)

The plugin talks to the store's LMFWC REST API. Configure (in `wp-config.php` on the
customer site, or via filters — never commit secrets):

```php
define('WC_GS_LICENSE_STORE_URL', 'https://ultimatesubscriptions.com');
define('WC_GS_LICENSE_CK', 'ck_xxx');   // LMFWC REST consumer key (read/validate scope)
define('WC_GS_LICENSE_CS', 'cs_xxx');   // LMFWC REST consumer secret
```

- **Activate:** `GET {store}/wp-json/lmfwc/v2/licenses/activate/{key}` (basic auth).
- **Validate:** `GET {store}/wp-json/lmfwc/v2/licenses/validate/{token}` or
  `.../licenses/{key}`.
- The client caches `{status, tier, expires_at, token, last_check}` and re-validates
  daily.
- **Offline grace period:** if a re-validation can't reach the store, the last known
  status is honored for `wc_gs_license_grace_days` (default 14) before downgrading —
  so a brief store outage doesn't lock paying customers out.

### Tier derivation
LMFWC licenses don't carry a "tier" natively, so the client maps the license's
`productId` to a tier via `wc_gs_license_tier_for_product` (default: any valid
license → `pro`). Add a Standard product later and map its id to `standard`.

## Integration checklist (store side — not in this repo)

1. Install **License Manager for WooCommerce**; enable its REST API and create a
   read/validate API key pair.
2. Create the Pro product (and Standard, if used); set activation limits per tier.
3. Sell via your existing WooCommerce (Subscriptions) checkout; LMFWC issues a key
   per order.
4. On the customer site, set the three constants above and flip enforcement on.
