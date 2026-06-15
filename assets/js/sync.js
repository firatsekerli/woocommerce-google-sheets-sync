/**
 * WooCommerce Google Sheets Sync - JavaScript
 * 
 * @package WC_Google_Sheets_Sync
 */

jQuery(document).ready(function($) {
    
    // Handle sync button clicks
    $('.wc-gs-sync-sheet').on('click', function(e) {
        e.preventDefault();

        var button = $(this);
        var sheetId = button.data('sheet-id');

        if (!sheetId) {
            alert('Invalid sheet ID');
            return;
        }

        // Start sync
        startSync(sheetId, button);
    });

    // Handle "Export Products to Sheet" button clicks
    $('.wc-gs-export-sheet').on('click', function(e) {
        e.preventDefault();

        var button = $(this);
        var sheetId = button.data('sheet-id');

        if (!sheetId) {
            alert('Invalid sheet ID');
            return;
        }

        if (!confirm('This will overwrite the sheet\'s data rows with all current WooCommerce products. The header row is kept. Continue?')) {
            return;
        }

        var originalText = button.text();
        button.prop('disabled', true).text('Exporting...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wc_gs_export_to_sheet',
                sheet_id: sheetId,
                nonce: wc_gs_sync_nonce
            },
            success: function(response) {
                if (response.success) {
                    alert((response.data && response.data.message) ? response.data.message : 'Export complete.');
                } else {
                    alert('Export failed: ' + (response.data || 'Unknown error'));
                }
                button.prop('disabled', false).text(originalText);
            },
            error: function() {
                alert('Export failed. Please try again.');
                button.prop('disabled', false).text(originalText);
            }
        });
    });
    
    /**
     * Start sync process
     */
    function startSync(sheetId, button) {
        // Disable button and show loading
        button.prop('disabled', true).text('Starting Sync...');
        
        // Show progress modal
        showProgressModal();
        
        // Make AJAX request to start sync
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wc_gs_sync_sheet',
                sheet_id: sheetId,
                nonce: wc_gs_sync_nonce
            },
            success: function(response) {
                if (response.success) {
                    // Start tracking progress
                    trackSyncProgress(response.data.sync_id, button);
                } else {
                    showSyncError(response.data);
                    button.prop('disabled', false).text('Sync Now');
                    hideProgressModal();
                }
            },
            error: function() {
                showSyncError('Failed to start sync. Please try again.');
                button.prop('disabled', false).text('Sync Now');
                hideProgressModal();
            }
        });
    }
    
    /**
     * Track sync progress
     */
    function trackSyncProgress(syncId, button) {
        var progressInterval = setInterval(function() {
            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'wc_gs_get_sync_progress',
                    sync_id: syncId
                },
                success: function(response) {
                    if (response.success) {
                        updateProgressDisplay(response.data);
                        
                        // Check if sync is complete
                        if (response.data.status === 'completed' || response.data.status === 'error') {
                            clearInterval(progressInterval);
                            handleSyncComplete(response.data, button);
                        }
                    } else {
                        clearInterval(progressInterval);
                        showSyncError('Failed to get sync progress');
                        button.prop('disabled', false).text('Sync Now');
                        hideProgressModal();
                    }
                },
                error: function() {
                    clearInterval(progressInterval);
                    showSyncError('Failed to track sync progress');
                    button.prop('disabled', false).text('Sync Now');
                    hideProgressModal();
                }
            });
        }, 1000); // Check every second
    }
    
    /**
     * Update progress display
     */
    function updateProgressDisplay(progressData) {
        $('#sync-progress-bar').css('width', progressData.progress + '%');
        $('#sync-progress-text').text(progressData.progress + '%');
        $('#sync-current-step').text(progressData.current_step);
        
        // Update stats
        $('#sync-processed-rows').text(progressData.processed_rows);
        $('#sync-total-rows').text(progressData.total_rows);
        $('#sync-created-products').text(progressData.created_products);
        $('#sync-updated-products').text(progressData.updated_products);
        $('#sync-skipped-rows').text(progressData.skipped_rows);
        
        // Update errors
        if (progressData.errors && progressData.errors.length > 0) {
            var errorsList = $('#sync-errors-list');
            errorsList.empty();
            progressData.errors.forEach(function(error) {
                errorsList.append('<li>Row ' + error.row + ': ' + error.message + '</li>');
            });
            $('#sync-errors').show();
        }
    }
    
    /**
     * Handle sync completion
     */
    function handleSyncComplete(progressData, button) {
        button.prop('disabled', false).text('Sync Now');
        
        // DEBUG: Log the progress data
        console.log('handleSyncComplete called with:', progressData);
        
        if (progressData.status === 'completed') {
            // Check if there are errors FIRST
            var hasErrors = progressData.errors && progressData.errors.length > 0;
            
            // DEBUG: Log error check
            console.log('Errors found:', hasErrors, 'Error count:', progressData.errors ? progressData.errors.length : 0);
            
            showSyncSuccess(progressData);
            
            if (hasErrors) {
                // Keep modal open indefinitely if there are errors
                showManualCloseButton();
                console.log('Sync completed with errors - keeping modal open');
            } else {
                // Auto-close only if no errors
                console.log('Sync completed without errors - auto-closing');
                setTimeout(function() {
                    location.reload();
                }, 3000);
                
                setTimeout(function() {
                    hideProgressModal();
                }, 5000);
            }
        } else {
            showSyncError('Sync failed: ' + progressData.current_step);
            // Keep modal open for manual close on error
            showManualCloseButton();
        }
    }
    
    /**
     * Show progress modal
     */
    function showProgressModal() {
        var modal = createProgressModal();
        $('body').append(modal);
        $('#wc-gs-sync-modal').fadeIn();
    }
    
    /**
     * Hide progress modal
     */
    function hideProgressModal() {
        $('#wc-gs-sync-modal').fadeOut(function() {
            $(this).remove();
        });
    }
    
    /**
     * Create progress modal HTML
     */
    function createProgressModal() {
        return `
            <div id="wc-gs-sync-modal" class="wc-gs-modal" style="display: none;">
                <div class="wc-gs-modal-backdrop"></div>
                <div class="wc-gs-modal-content">
                    <div class="wc-gs-modal-header">
                        <h2>Syncing Google Sheet</h2>
                    </div>
                    <div class="wc-gs-modal-body">
                        <div class="wc-gs-progress-container">
                            <div class="wc-gs-progress-bar">
                                <div id="sync-progress-bar" class="wc-gs-progress-fill"></div>
                                <span id="sync-progress-text" class="wc-gs-progress-text">0%</span>
                            </div>
                            <p id="sync-current-step" class="wc-gs-current-step">Initializing sync...</p>
                        </div>
                        
                        <div class="wc-gs-sync-stats">
                            <div class="wc-gs-stat-item">
                                <span class="wc-gs-stat-label">Processed:</span>
                                <span id="sync-processed-rows">0</span> / <span id="sync-total-rows">0</span>
                            </div>
                            <div class="wc-gs-stat-item">
                                <span class="wc-gs-stat-label">Created:</span>
                                <span id="sync-created-products" class="wc-gs-stat-success">0</span>
                            </div>
                            <div class="wc-gs-stat-item">
                                <span class="wc-gs-stat-label">Updated:</span>
                                <span id="sync-updated-products" class="wc-gs-stat-info">0</span>
                            </div>
                            <div class="wc-gs-stat-item">
                                <span class="wc-gs-stat-label">Skipped:</span>
                                <span id="sync-skipped-rows" class="wc-gs-stat-warning">0</span>
                            </div>
                        </div>
                        
                        <div id="sync-errors" class="wc-gs-sync-errors" style="display: none;">
                            <h4>Errors:</h4>
                            <ul id="sync-errors-list"></ul>
                        </div>
                        
                        <div id="sync-messages" class="wc-gs-sync-messages"></div>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Show sync success message
     */
    function showSyncSuccess(progressData) {
        var message = `
            <div class="notice notice-success">
                <h4>Sync Completed Successfully!</h4>
                <p>
                    Created: <strong>${progressData.created_products}</strong> products, 
                    Updated: <strong>${progressData.updated_products}</strong> products, 
                    Skipped: <strong>${progressData.skipped_rows}</strong> rows
                </p>
            </div>
        `;
        $('#sync-messages').html(message);
    }
    
    /**
     * Show sync error message
     */
    function showSyncError(message) {
        var errorHtml = `
            <div class="notice notice-error">
                <h4>Sync Failed</h4>
                <p>${message}</p>
            </div>
        `;
        
        if ($('#sync-messages').length) {
            $('#sync-messages').html(errorHtml);
        } else {
            alert('Sync Error: ' + message);
        }
    }
    
    // Close modal when clicking backdrop
    $(document).on('click', '.wc-gs-modal-backdrop', function() {
        hideProgressModal();
    });
});

// CSS for progress modal (inline for simplicity)
var modalCSS = `
<style>
.wc-gs-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 100000;
}

.wc-gs-modal-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
}

.wc-gs-modal-content {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}

.wc-gs-modal-header {
    padding: 20px 20px 10px;
    border-bottom: 1px solid #ddd;
}

.wc-gs-modal-header h2 {
    margin: 0;
    font-size: 18px;
}

.wc-gs-modal-body {
    padding: 20px;
}

.wc-gs-progress-container {
    margin-bottom: 20px;
}

.wc-gs-progress-bar {
    position: relative;
    background: #f0f0f0;
    border-radius: 20px;
    height: 30px;
    overflow: hidden;
    margin-bottom: 10px;
}

.wc-gs-progress-fill {
    background: linear-gradient(45deg, #0073aa, #005a87);
    height: 100%;
    width: 0%;
    transition: width 0.3s ease;
    border-radius: 20px;
}

.wc-gs-progress-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-weight: bold;
    font-size: 12px;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
}

.wc-gs-current-step {
    font-style: italic;
    color: #666;
    margin: 0;
}

.wc-gs-sync-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 15px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 5px;
}

.wc-gs-stat-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.wc-gs-stat-label {
    font-weight: bold;
    color: #333;
}

.wc-gs-stat-success {
    color: #46b450;
    font-weight: bold;
}

.wc-gs-stat-info {
    color: #0073aa;
    font-weight: bold;
}

.wc-gs-stat-warning {
    color: #ffb900;
    font-weight: bold;
}

.wc-gs-sync-errors {
    background: #ffeaea;
    border: 1px solid #dc3232;
    border-radius: 5px;
    padding: 15px;
    margin-bottom: 15px;
}

.wc-gs-sync-errors h4 {
    margin: 0 0 10px 0;
    color: #dc3232;
}

.wc-gs-sync-errors ul {
    margin: 0;
    padding-left: 20px;
}

.wc-gs-sync-errors li {
    margin-bottom: 5px;
    color: #721c24;
}

.wc-gs-sync-messages {
    margin-top: 15px;
}

.wc-gs-sync-messages .notice {
    padding: 10px 15px;
    border-radius: 5px;
    margin: 0;
}

.wc-gs-sync-messages .notice h4 {
    margin: 0 0 5px 0;
}

.wc-gs-sync-messages .notice p {
    margin: 0;
}
</style>
`;

// Inject CSS
jQuery('head').append(modalCSS);