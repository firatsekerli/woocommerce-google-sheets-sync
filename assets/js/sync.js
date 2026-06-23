/**
 * WooCommerce Google Sheets Sync - JavaScript
 * 
 * @package WC_Google_Sheets_Sync
 */

jQuery(document).ready(function($) {

    // Track the running sync so the Cancel button can stop it.
    var activeSyncId = null;
    var activeProgressInterval = null;
    var activeSyncButton = null;

    /**
     * Reset the "active sync" tracking and hide the Cancel button.
     */
    function clearActiveSync() {
        if (activeProgressInterval) {
            clearInterval(activeProgressInterval);
        }
        activeSyncId = null;
        activeProgressInterval = null;
        activeSyncButton = null;
        $('#wc-gs-cancel-sync').hide();
    }

    // Handle "Cancel Sync" clicks
    $('#wc-gs-cancel-sync').on('click', function(e) {
        e.preventDefault();
        if (!activeSyncId) {
            return;
        }
        if (!confirm('Cancel the running sync? Products already imported will stay; the remaining work and sheet write-back will stop.')) {
            return;
        }

        var cancelBtn = $(this);
        var button = activeSyncButton;
        var syncId = activeSyncId;
        cancelBtn.prop('disabled', true).text('Cancelling...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wc_gs_cancel_sync',
                sync_id: syncId,
                nonce: wc_gs_sync_nonce
            },
            complete: function() {
                clearActiveSync();
                $('#sync-current-step').text('Sync cancelled.');
                hideInlineProgress();
                if (button) {
                    button.prop('disabled', false).text('Sync Now');
                }
                cancelBtn.prop('disabled', false).text('Cancel Sync');
            }
        });
    });

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

        // Show which sheet is being synced in the progress panel
        var sheetName = button.closest('.wc-gs-connected-sheet-card').find('.wc-gs-sheet-title').text().replace(/\s+/g, ' ').trim();

        // Show the inline progress panel
        showInlineProgress();
        if (sheetName) {
            $('#sync-sheet-name').text('Sheet: ' + sheetName);
        }
        
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
                    hideInlineProgress();
                }
            },
            error: function() {
                showSyncError('Failed to start sync. Please try again.');
                button.prop('disabled', false).text('Sync Now');
                hideInlineProgress();
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
                    sync_id: syncId,
                    nonce: wc_gs_sync_nonce
                },
                success: function(response) {
                    if (response.success) {
                        updateProgressDisplay(response.data);
                        
                        // Check if sync is complete
                        if (response.data.status === 'completed' || response.data.status === 'error' || response.data.status === 'cancelled') {
                            clearInterval(progressInterval);
                            handleSyncComplete(response.data, button);
                        }
                    } else {
                        clearActiveSync();
                        showSyncError('Failed to get sync progress');
                        button.prop('disabled', false).text('Sync Now');
                        hideInlineProgress();
                    }
                },
                error: function() {
                    clearActiveSync();
                    showSyncError('Failed to track sync progress');
                    button.prop('disabled', false).text('Sync Now');
                    hideInlineProgress();
                }
            });
        }, 1000); // Check every second

        // Expose the running sync to the Cancel button.
        activeSyncId = syncId;
        activeProgressInterval = progressInterval;
        activeSyncButton = button;
        $('#wc-gs-cancel-sync').show();
    }
    
    /**
     * Update progress display
     */
    function updateProgressDisplay(progressData) {
        if (progressData.sheet_title) {
            $('#sync-sheet-name').text('Sheet: ' + progressData.sheet_title);
        }
        $('#sync-progress-bar').css('width', progressData.progress + '%');
        $('#sync-progress-text').text(progressData.progress + '%');
        $('#sync-current-step').text(progressData.current_step);
        
        // Update stats
        $('#sync-processed-rows').text(progressData.processed_rows);
        $('#sync-total-rows').text(progressData.total_rows);
        $('#sync-created-products').text(progressData.created_products);
        $('#sync-updated-products').text(progressData.updated_products);
        $('#sync-deleted-products').text(progressData.deleted_products || 0);
        $('#sync-skipped-rows').text(progressData.skipped_rows);
        $('#sync-variations').text(progressData.variations || 0);
        $('#sync-error-count').text(progressData.errors ? progressData.errors.length : 0);
        
        // Update errors
        if (progressData.errors && progressData.errors.length > 0) {
            var errorsList = $('#sync-errors-list');
            errorsList.empty();
            progressData.errors.forEach(function(error) {
                // Use .text() so sheet-derived error messages can't inject HTML/script
                errorsList.append($('<li>').text('Row ' + error.row + ': ' + error.message));
            });
            $('#sync-errors').show();
        }
    }
    
    /**
     * Handle sync completion
     */
    function handleSyncComplete(progressData, button) {
        button.prop('disabled', false).text('Sync Now');
        clearActiveSync();

        if (progressData.status === 'completed') {
            showSyncSuccess(progressData);

            var hasErrors = progressData.errors && progressData.errors.length > 0;
            if (!hasErrors) {
                // Reload so the connected-sheet cards / last-run stats refresh
                setTimeout(function() { location.reload(); }, 2000);
            }
            // If there are errors, leave the inline panel visible so they stay on screen.
        } else if (progressData.status === 'cancelled') {
            // Cancelled by the user — leave the "Sync cancelled." step visible.
            hideInlineProgress();
        } else {
            showSyncError('Sync failed: ' + progressData.current_step);
        }
    }
    
    /**
     * Show and reset the inline progress panel (rendered in the dashboard).
     */
    function showInlineProgress() {
        var $panel = $('#wc-gs-sync-progress-panel');
        if (!$panel.length) {
            return;
        }
        // The panel is always visible; show the bar and reset the counters.
        $('#sync-progress-bar-wrap').show();
        $('#sync-progress-bar').css('width', '0%');
        $('#sync-progress-text').text('0%');
        $('#sync-current-step').text('Initializing sync...');
        $('#sync-processed-rows, #sync-total-rows, #sync-created-products, #sync-updated-products, #sync-deleted-products, #sync-skipped-rows, #sync-variations, #sync-error-count').text('0');
        $('#sync-errors-list').empty();
        $('#sync-errors').hide();
        $('#sync-messages').empty();
        if ($panel.get(0) && $panel.get(0).scrollIntoView) {
            $panel.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    /**
     * Hide the live progress bar (the panel itself stays visible).
     */
    function hideInlineProgress() {
        $('#sync-progress-bar-wrap').hide();
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
                    Skipped: <strong>${progressData.skipped_rows}</strong> rows,
                    Variations: <strong>${progressData.variations || 0}</strong>
                </p>
            </div>
        `;
        $('#sync-messages').html(message);
    }
    
    /**
     * Show sync error message
     */
    function showSyncError(message) {
        if ($('#sync-messages').length) {
            // Build with .text() so sheet-derived messages can't inject HTML/script
            var notice = $('<div class="notice notice-error">')
                .append($('<h4>').text('Sync Failed'))
                .append($('<p>').text(message));
            $('#sync-messages').empty().append(notice);
        } else {
            alert('Sync Error: ' + message);
        }
    }
    
});
