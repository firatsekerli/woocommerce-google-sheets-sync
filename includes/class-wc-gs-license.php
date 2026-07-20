<?php
/**
 * License client for WooCommerce Google Sheets Sync.
 *
 * Talks to the store's License Manager for WooCommerce (LMFWC) REST API to
 * activate and validate a key, caches the result, and answers feature-gate
 * questions via can(). See docs/LICENSING.md for the full design.
 *
 * IMPORTANT: enforcement is OFF by default. Until a site opts in (constant
 * WC_GS_LICENSE_ENFORCE or the `wc_gs_license_enforced` filter), can() always
 * returns true and the plugin behaves exactly as an unlicensed build does
 * today. Nothing here changes runtime behaviour until that switch is flipped.
 *
 * @package WC_Google_Sheets_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_GS_License {

    /** Option key for the cached license state. */
    const OPTION = 'wc_gs_license';

    /** How often to re-validate against the store (seconds). */
    const CHECK_INTERVAL = DAY_IN_SECONDS;

    /**
     * Map of gated feature keys to the minimum tier that unlocks them. Any
     * feature not listed here is a base feature (available without a license).
     */
    const FEATURE_TIERS = array(
        'variable_products' => 'pro',
        'auto_sync'         => 'pro',
        'multi_sheet'       => 'pro',
        'advanced_fields'   => 'pro',
    );

    /** Ranked tiers, lowest to highest. Index is the comparison rank. */
    const TIER_RANK = array('none', 'standard', 'pro');

    /** @var WC_GS_License|null */
    private static $instance = null;

    /** @var array|null in-request cache of the stored license state */
    private $state = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Re-validate in the background, at most once per check interval.
        add_action('init', array($this, 'maybe_refresh'));
        // License form actions (activate / deactivate / refresh).
        add_action('admin_post_wc_gs_license', array($this, 'handle_admin_action'));
    }

    /* ---------------------------------------------------------------------
     * Admin form handler
     * ------------------------------------------------------------------- */

    /**
     * Handle the License section form: activate, deactivate, or re-check a key.
     * Nonce- and capability-gated; redirects back to the Settings tab with a
     * status notice.
     */
    public function handle_admin_action() {
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wc_gs_license_nonce')) {
            wp_die(__('Security check failed', 'wc-google-sheets-sync'));
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'wc-google-sheets-sync'));
        }

        $op = isset($_POST['license_op']) ? sanitize_key($_POST['license_op']) : '';

        if ($op === 'activate') {
            $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
            $result = $this->activate($key);
        } elseif ($op === 'deactivate') {
            $result = $this->deactivate();
        } elseif ($op === 'refresh') {
            $result = $this->refresh();
        } else {
            $result = array('success' => false, 'message' => __('Unknown action.', 'wc-google-sheets-sync'));
        }

        wp_safe_redirect(add_query_arg(array(
            'page'        => 'wc-google-sheets-sync',
            'tab'         => 'settings',
            'license_msg' => $result['success'] ? 'ok' : 'err',
            'license_txt' => rawurlencode($result['message']),
        ), admin_url('admin.php')));
        exit;
    }

    /* ---------------------------------------------------------------------
     * Enforcement switch
     * ------------------------------------------------------------------- */

    /**
     * Whether license enforcement is active. OFF by default; enable with the
     * WC_GS_LICENSE_ENFORCE constant or the `wc_gs_license_enforced` filter.
     */
    public function enforced() {
        $on = defined('WC_GS_LICENSE_ENFORCE') && WC_GS_LICENSE_ENFORCE;
        return (bool) apply_filters('wc_gs_license_enforced', $on);
    }

    /**
     * Whether the base (non-Pro) feature set itself requires a valid license.
     *
     * Default false => "free base + Pro" model. Filter to true for a
     * "Standard + Pro" model where even base features need a paid license.
     */
    public function base_requires_license() {
        return (bool) apply_filters('wc_gs_license_base_requires_license', false);
    }

    /* ---------------------------------------------------------------------
     * Gate query
     * ------------------------------------------------------------------- */

    /**
     * Can this site use a given feature?
     *
     * When enforcement is off, always true. Otherwise base features follow
     * base_requires_license(); gated features require the mapped tier.
     *
     * @param string $feature One of the FEATURE_TIERS keys, or any string
     *                        (unknown => treated as a base feature).
     */
    public function can($feature) {
        if (!$this->enforced()) {
            return true;
        }

        $required = isset(self::FEATURE_TIERS[$feature]) ? self::FEATURE_TIERS[$feature] : null;

        if ($required === null) {
            // Base feature: gated only under the Standard+Pro model.
            $allowed = $this->base_requires_license() ? $this->is_valid() : true;
        } else {
            $allowed = $this->tier_at_least($required);
        }

        return (bool) apply_filters('wc_gs_license_can', $allowed, $feature, $this);
    }

    /**
     * Whether the current tier meets or exceeds a required tier.
     */
    public function tier_at_least($required) {
        if (!$this->is_valid()) {
            return false;
        }
        $have = array_search($this->tier(), self::TIER_RANK, true);
        $need = array_search($required, self::TIER_RANK, true);
        if ($have === false || $need === false) {
            return false;
        }
        return $have >= $need;
    }

    /* ---------------------------------------------------------------------
     * State accessors
     * ------------------------------------------------------------------- */

    private function state() {
        if ($this->state === null) {
            $stored = get_option(self::OPTION, array());
            $this->state = is_array($stored) ? $stored : array();
        }
        return $this->state;
    }

    private function save_state(array $state) {
        $this->state = $state;
        update_option(self::OPTION, $state, false);
    }

    /** The stored license key (empty string if none). */
    public function key() {
        $s = $this->state();
        return isset($s['key']) ? (string) $s['key'] : '';
    }

    /**
     * Current status: 'valid', 'invalid', 'expired', or 'inactive' (no key).
     * Honors the offline grace period before downgrading a previously-valid
     * license when the store can't be reached.
     */
    public function status() {
        $s = $this->state();
        if (empty($s['key'])) {
            return 'inactive';
        }
        $status = isset($s['status']) ? (string) $s['status'] : 'inactive';

        // Grace: if the last check failed but we were valid, keep honoring it.
        if (!empty($s['last_error']) && !empty($s['last_valid_at'])) {
            $grace = (int) apply_filters('wc_gs_license_grace_days', 14) * DAY_IN_SECONDS;
            if ((time() - (int) $s['last_valid_at']) <= $grace) {
                return 'valid';
            }
        }
        return $status;
    }

    public function is_valid() {
        return $this->status() === 'valid';
    }

    public function is_pro() {
        return $this->is_valid() && $this->tier() === 'pro';
    }

    /** Derived tier: 'none' when not valid, else the mapped tier. */
    public function tier() {
        if (!$this->is_valid()) {
            return 'none';
        }
        $s = $this->state();
        return isset($s['tier']) && $s['tier'] ? (string) $s['tier'] : 'pro';
    }

    /** Mask a license key for display (keep the last 4 characters). */
    public function mask_key($key) {
        $key = (string) $key;
        $len = strlen($key);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }
        return str_repeat('•', $len - 4) . substr($key, -4);
    }

    /** Unix timestamp of the license expiry, or 0 if none/perpetual. */
    public function expires_at() {
        $s = $this->state();
        return isset($s['expires_at']) ? (int) $s['expires_at'] : 0;
    }

    /* ---------------------------------------------------------------------
     * Activate / deactivate / refresh
     * ------------------------------------------------------------------- */

    /**
     * Activate a license key against the store.
     *
     * @return array{success:bool,message:string}
     */
    public function activate($key) {
        $key = trim((string) $key);
        if ($key === '') {
            return array('success' => false, 'message' => __('Enter a license key.', 'wc-google-sheets-sync'));
        }

        $result = $this->remote_call('licenses/activate/' . rawurlencode($key));
        if (is_wp_error($result)) {
            return array('success' => false, 'message' => $result->get_error_message());
        }

        $data = isset($result['data']) ? $result['data'] : $result;
        $state = $this->state_from_response($key, $data);
        $state['token'] = isset($data['activationData']['token'])
            ? (string) $data['activationData']['token']
            : (isset($data['token']) ? (string) $data['token'] : '');
        $this->save_state($state);

        if ($state['status'] === 'valid') {
            return array('success' => true, 'message' => __('License activated.', 'wc-google-sheets-sync'));
        }
        return array('success' => false, 'message' => __('That license key could not be activated.', 'wc-google-sheets-sync'));
    }

    /**
     * Deactivate locally (and best-effort on the store), clearing stored state.
     */
    public function deactivate() {
        $s = $this->state();
        $token = isset($s['token']) ? (string) $s['token'] : '';
        if ($token !== '') {
            // Best effort; ignore failures — we clear locally regardless.
            $this->remote_call('licenses/deactivate/' . rawurlencode($token));
        }
        delete_option(self::OPTION);
        $this->state = array();
        return array('success' => true, 'message' => __('License removed.', 'wc-google-sheets-sync'));
    }

    /**
     * Re-validate the stored key against the store now.
     */
    public function refresh() {
        $key = $this->key();
        if ($key === '') {
            return array('success' => false, 'message' => __('No license key stored.', 'wc-google-sheets-sync'));
        }

        $result = $this->remote_call('licenses/validate/' . rawurlencode($key));

        $state = $this->state();
        $state['last_check'] = time();

        if (is_wp_error($result)) {
            // Couldn't reach the store — record the error; grace keeps us valid.
            $state['last_error'] = $result->get_error_message();
            $this->save_state($state);
            return array('success' => false, 'message' => $result->get_error_message());
        }

        $data = isset($result['data']) ? $result['data'] : $result;
        $fresh = $this->state_from_response($key, $data);
        // Preserve the activation token and grace anchor across refreshes.
        $fresh['token'] = isset($state['token']) ? $state['token'] : '';
        $this->save_state($fresh);

        return array(
            'success' => $fresh['status'] === 'valid',
            'message' => $fresh['status'] === 'valid'
                ? __('License is valid.', 'wc-google-sheets-sync')
                : __('License is not valid.', 'wc-google-sheets-sync'),
        );
    }

    /**
     * Re-validate in the background at most once per CHECK_INTERVAL. Hooked to
     * `init`; cheap no-op when there's no key or the last check is recent.
     */
    public function maybe_refresh() {
        $s = $this->state();
        if (empty($s['key'])) {
            return;
        }
        $last = isset($s['last_check']) ? (int) $s['last_check'] : 0;
        if ((time() - $last) < self::CHECK_INTERVAL) {
            return;
        }
        // Space out checks even if init fires repeatedly without a full reload.
        $s['last_check'] = time();
        $this->save_state($s);
        $this->refresh();
    }

    /* ---------------------------------------------------------------------
     * Response mapping + transport
     * ------------------------------------------------------------------- */

    /**
     * Build a normalized state array from an LMFWC license payload.
     */
    private function state_from_response($key, $data) {
        $expires_at = 0;
        if (!empty($data['expiresAt'])) {
            $ts = strtotime((string) $data['expiresAt']);
            $expires_at = $ts ? (int) $ts : 0;
        }

        $expired = $expires_at > 0 && $expires_at < time();

        // LMFWC status codes: 2 = delivered, 3 = active, 4 = inactive, etc.
        // Treat a present license that isn't expired as valid; the store's
        // activation limits are enforced during activate().
        $status = $expired ? 'expired' : 'valid';

        $tier = $this->derive_tier($data);

        $state = array(
            'key'         => (string) $key,
            'status'      => $status,
            'tier'        => $tier,
            'expires_at'  => $expires_at,
            'last_check'  => time(),
            'last_error'  => '',
        );
        if ($status === 'valid') {
            $state['last_valid_at'] = time();
        }
        return $state;
    }

    /**
     * Map an LMFWC license to a plugin tier. LMFWC has no native tier field, so
     * we map the license's productId; default any valid license to 'pro'.
     */
    private function derive_tier($data) {
        $product_id = isset($data['productId']) ? (int) $data['productId'] : 0;
        return (string) apply_filters('wc_gs_license_tier_for_product', 'pro', $product_id, $data);
    }

    /**
     * Perform an LMFWC REST GET. Returns the decoded `data` payload on success
     * or a WP_Error. Store URL + API credentials come from constants/filters;
     * secrets are never stored in the plugin or committed.
     *
     * @return array|WP_Error
     */
    private function remote_call($endpoint) {
        $base = $this->store_url();
        $ck   = $this->consumer_key();
        $cs   = $this->consumer_secret();

        if ($base === '' || $ck === '' || $cs === '') {
            return new WP_Error(
                'wc_gs_license_unconfigured',
                __('Licensing is not configured on this site.', 'wc-google-sheets-sync')
            );
        }

        $url = trailingslashit($base) . 'wp-json/lmfwc/v2/' . ltrim($endpoint, '/');

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($ck . ':' . $cs),
                'Accept'        => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($body) && isset($body['message'])
                ? (string) $body['message']
                : sprintf(__('License server returned HTTP %d.', 'wc-google-sheets-sync'), $code);
            return new WP_Error('wc_gs_license_http', $message);
        }

        if (!is_array($body) || empty($body['success'])) {
            $message = is_array($body) && isset($body['message'])
                ? (string) $body['message']
                : __('Unexpected response from the license server.', 'wc-google-sheets-sync');
            return new WP_Error('wc_gs_license_bad_response', $message);
        }

        return $body;
    }

    private function store_url() {
        $url = defined('WC_GS_LICENSE_STORE_URL') ? WC_GS_LICENSE_STORE_URL : '';
        return (string) apply_filters('wc_gs_license_store_url', $url);
    }

    private function consumer_key() {
        $ck = defined('WC_GS_LICENSE_CK') ? WC_GS_LICENSE_CK : '';
        return (string) apply_filters('wc_gs_license_consumer_key', $ck);
    }

    private function consumer_secret() {
        $cs = defined('WC_GS_LICENSE_CS') ? WC_GS_LICENSE_CS : '';
        return (string) apply_filters('wc_gs_license_consumer_secret', $cs);
    }
}

if (!function_exists('wc_gs_can')) {
    /**
     * Convenience gate check. Returns true when the given Pro feature is
     * available (always true while enforcement is off). Use this at the point
     * a gated feature is invoked, e.g. `if (!wc_gs_can('variable_products'))`.
     */
    function wc_gs_can($feature) {
        if (!class_exists('WC_GS_License')) {
            return true;
        }
        return WC_GS_License::instance()->can($feature);
    }
}
