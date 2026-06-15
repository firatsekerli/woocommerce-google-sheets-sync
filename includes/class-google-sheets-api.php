<?php
/**
 * Google Sheets API Handler
 * 
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_GS_Google_Sheets_API {
    
    /**
     * Google Client instance
     */
    private $client;
    
    /**
     * Sheets Service instance
     */
    private $service;
    
    /**
     * Settings instance
     */
    private $settings;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = new WC_GS_Settings();
        $this->init_client();
    }
    
    /**
     * Initialize Google Client
     */
    private function init_client() {
        if (!class_exists('Google_Client')) {
            return false;
        }
        
        $client_id = $this->settings->get_option('google_client_id');
        $client_secret = $this->settings->get_option('google_client_secret');
        
        if (empty($client_id) || empty($client_secret)) {
            return false;
        }
        
        $this->client = new Google_Client();
        $this->client->setClientId($client_id);
        $this->client->setClientSecret($client_secret);
        $this->client->setRedirectUri($this->get_redirect_uri());
        
        // UPDATED: Include write permissions for spreadsheets
        $this->client->addScope(Google_Service_Sheets::SPREADSHEETS); // Full read/write access
        $this->client->addScope(Google_Service_Drive::DRIVE_READONLY);
        $this->client->addScope('email');
        $this->client->addScope('profile');
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        
        // Check if we have stored tokens
        $access_token = get_option('wc_gs_sync_access_token');
        if ($access_token) {
            $this->client->setAccessToken($access_token);
            
            // Refresh token if needed
            if ($this->client->isAccessTokenExpired()) {
                $refresh_token = $this->client->getRefreshToken();
                if ($refresh_token) {
                    $this->client->fetchAccessTokenWithRefreshToken($refresh_token);
                    update_option('wc_gs_sync_access_token', $this->client->getAccessToken());
                }
            }
        }
        
        return true;
    }
    
    /**
     * Get OAuth authorization URL
     */
    public function get_auth_url() {
        if (!$this->client) {
            return false;
        }
        
        return $this->client->createAuthUrl();
    }
    
    /**
     * Get redirect URI
     */
    public function get_redirect_uri() {
        return admin_url('admin.php?page=wc-google-sheets-sync&auth=callback');
    }
    
    /**
     * Handle OAuth callback
     */
    public function handle_auth_callback($auth_code) {
        if (!$this->client) {
            error_log('WC Google Sheets: Client not initialized');
            return new WP_Error('client_error', 'Google Client not initialized');
        }
        
        try {
            error_log('WC Google Sheets: Attempting to exchange auth code: ' . substr($auth_code, 0, 20) . '...');
            
            $token = $this->client->fetchAccessTokenWithAuthCode($auth_code);
            
            error_log('WC Google Sheets: Token response: ' . print_r($token, true));
            
            if (isset($token['error'])) {
                error_log('WC Google Sheets: Token error: ' . $token['error_description']);
                return new WP_Error('auth_error', $token['error_description']);
            }
            
            // Store the access token
            update_option('wc_gs_sync_access_token', $token);
            error_log('WC Google Sheets: Access token saved successfully');
            
            return true;
            
        } catch (Exception $e) {
            error_log('WC Google Sheets: Exception during auth: ' . $e->getMessage());
            return new WP_Error('auth_exception', $e->getMessage());
        }
    }
    
    /**
     * Check if user is authenticated
     */
    public function is_authenticated() {
        if (!$this->client) {
            return false;
        }
        
        $access_token = get_option('wc_gs_sync_access_token');
        if (!$access_token) {
            return false;
        }
        
        $this->client->setAccessToken($access_token);
        
        // Check if token is valid (not expired or if refresh token works)
        if ($this->client->isAccessTokenExpired()) {
            $refresh_token = $this->client->getRefreshToken();
            if (!$refresh_token) {
                return false;
            }
            
            try {
                $this->client->fetchAccessTokenWithRefreshToken($refresh_token);
                update_option('wc_gs_sync_access_token', $this->client->getAccessToken());
                return true;
            } catch (Exception $e) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Disconnect/revoke authentication
     */
    public function disconnect() {
        if ($this->client && $this->is_authenticated()) {
            try {
                $this->client->revokeToken();
            } catch (Exception $e) {
                // Continue even if revoke fails
            }
        }
        
        // Clear stored tokens
        delete_option('wc_gs_sync_access_token');
        
        return true;
    }
    
    /**
     * Get authenticated user info
     */
    public function get_user_info() {
        if (!$this->is_authenticated()) {
            return false;
        }
        
        try {
            $oauth2 = new Google_Service_Oauth2($this->client);
            return $oauth2->userinfo->get();
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get sheet data
     */
    public function get_sheet_data($sheet_id, $range = 'A:Z') {
        if (!$this->is_authenticated()) {
            return new WP_Error('not_authenticated', 'Not authenticated with Google');
        }
        
        try {
            if (!$this->service) {
                $this->service = new Google_Service_Sheets($this->client);
            }
            
            $response = $this->service->spreadsheets_values->get($sheet_id, $range);
            return $response->getValues();
            
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage());
        }
    }
    
    /**
     * NEW: Batch update cells in Google Sheets
     */
    public function batch_update_sheet($sheet_id, $updates) {
        if (!$this->is_authenticated()) {
            return new WP_Error('not_authenticated', 'Not authenticated with Google');
        }
        
        try {
            if (!$this->service) {
                $this->service = new Google_Service_Sheets($this->client);
            }
            
            $body = new Google_Service_Sheets_BatchUpdateValuesRequest();
            $body->setValueInputOption('RAW');
            
            $value_ranges = array();
            foreach ($updates as $update) {
                $range = new Google_Service_Sheets_ValueRange();
                $range->setRange($update['range']);
                $range->setValues($update['values']);
                $value_ranges[] = $range;
            }
            
            $body->setData($value_ranges);
            
            $result = $this->service->spreadsheets_values->batchUpdate($sheet_id, $body);
            return $result;
            
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage());
        }
    }
    
    /**
     * NEW: Update a single cell in Google Sheets
     */
    public function update_cell($sheet_id, $range, $value) {
        if (!$this->is_authenticated()) {
            return new WP_Error('not_authenticated', 'Not authenticated with Google');
        }
        
        try {
            if (!$this->service) {
                $this->service = new Google_Service_Sheets($this->client);
            }
            
            $body = new Google_Service_Sheets_ValueRange();
            $body->setValues(array(array($value)));
            
            $params = array(
                'valueInputOption' => 'RAW'
            );
            
            $result = $this->service->spreadsheets_values->update(
                $sheet_id, 
                $range, 
                $body, 
                $params
            );
            
            return $result;
            
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage());
        }
    }
    
    /**
     * NEW: Clear a range of values in a Google Sheet
     */
    public function clear_values($sheet_id, $range) {
        if (!$this->is_authenticated()) {
            return new WP_Error('not_authenticated', 'Not authenticated with Google');
        }

        try {
            if (!$this->service) {
                $this->service = new Google_Service_Sheets($this->client);
            }

            $clear_request = new Google_Service_Sheets_ClearValuesRequest();
            $result = $this->service->spreadsheets_values->clear($sheet_id, $range, $clear_request);
            return $result;

        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage());
        }
    }

    /**
     * Get sheet info
     */
    public function get_sheet_info($sheet_id) {
        if (!$this->is_authenticated()) {
            return new WP_Error('not_authenticated', 'Not authenticated with Google');
        }
        
        try {
            if (!$this->service) {
                $this->service = new Google_Service_Sheets($this->client);
            }
            
            $spreadsheet = $this->service->spreadsheets->get($sheet_id);
            return array(
                'title' => $spreadsheet->getProperties()->getTitle(),
                'sheets' => $spreadsheet->getSheets(),
                'url' => 'https://docs.google.com/spreadsheets/d/' . $sheet_id
            );
            
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage());
        }
    }
    
    /**
     * List user's spreadsheets using Drive API
     */
    public function list_spreadsheets($page_token = null) {
        if (!$this->is_authenticated()) {
            return new WP_Error('not_authenticated', 'Not authenticated with Google');
        }
        
        try {
            $drive_service = new Google_Service_Drive($this->client);
            
            $options = array(
                'q' => "mimeType='application/vnd.google-apps.spreadsheet' and trashed=false",
                'orderBy' => 'modifiedTime desc',
                'pageSize' => 20,
                'fields' => 'nextPageToken, files(id, name, modifiedTime, webViewLink)'
            );
            
            if ($page_token) {
                $options['pageToken'] = $page_token;
            }
            
            $response = $drive_service->files->listFiles($options);
            
            return array(
                'files' => $response->getFiles(),
                'nextPageToken' => $response->getNextPageToken()
            );
            
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage());
        }
    }
    
    /**
     * Search spreadsheets by name
     */
    public function search_spreadsheets($search_term) {
        if (!$this->is_authenticated()) {
            return new WP_Error('not_authenticated', 'Not authenticated with Google');
        }
        
        try {
            $drive_service = new Google_Service_Drive($this->client);
            
            $query = "mimeType='application/vnd.google-apps.spreadsheet' and trashed=false and name contains '" . addslashes($search_term) . "'";
            
            $options = array(
                'q' => $query,
                'orderBy' => 'modifiedTime desc',
                'pageSize' => 20,
                'fields' => 'files(id, name, modifiedTime, webViewLink)'
            );
            
            $response = $drive_service->files->listFiles($options);
            
            return $response->getFiles();
            
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage());
        }
    }
}