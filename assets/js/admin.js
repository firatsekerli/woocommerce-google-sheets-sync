/**
 * WooCommerce Google Sheets Sync - Admin JavaScript
 * 
 * @package WC_Google_Sheets_Sync
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize admin functionality
        WCGSSyncAdmin.init();
    });

    var WCGSSyncAdmin = {
        
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Bind sync button click
            $(document).on('click', '.wc-gs-sync-start', this.startSync);
            
            // Bind settings form submission
            $(document).on('submit', '#wc-gs-sync-settings-form', this.saveSettings);
        },

        startSync: function(e) {
            e.preventDefault();
            // TODO: Implement sync functionality
            console.log('Sync started');
        },

        saveSettings: function(e) {
            e.preventDefault();
            // TODO: Implement settings save
            console.log('Settings saved');
        },

        updateProgress: function(percentage) {
            $('.wc-gs-sync-progress-bar').css('width', percentage + '%');
        },

        showNotice: function(message, type) {
            type = type || 'info';
            var notice = '<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>';
            $('.wc-gs-sync-wrap').prepend(notice);
        }
    };

    // Make WCGSSyncAdmin globally available
    window.WCGSSyncAdmin = WCGSSyncAdmin;

})(jQuery);