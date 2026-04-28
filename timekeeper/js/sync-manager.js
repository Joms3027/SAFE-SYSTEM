/**
 * Sync Manager for Offline Attendance Records
 * Automatically syncs pending records when internet is available
 */

class SyncManager {
    constructor(offlineStorage) {
        this.offlineStorage = offlineStorage;
        this.isOnline = navigator.onLine;
        this.syncInProgress = false;
        this.syncInterval = null;
        this.requestTimeout = 30000; // 30 seconds timeout
        this.syncingRecords = new Set(); // Track records currently being synced to prevent duplicates
        
        this.init();
    }

    init() {
        // Listen for online/offline events
        window.addEventListener('online', () => {
            console.log('[SyncManager] Internet connection restored');
            this.isOnline = true;
            this.syncPendingRecords();
        });

        window.addEventListener('offline', () => {
            console.log('[SyncManager] Internet connection lost');
            this.isOnline = false;
        });

        // Check online status periodically
        this.checkOnlineStatus();

        // Start periodic sync check (every 30 seconds)
        this.syncInterval = setInterval(() => {
            if (this.isOnline && !this.syncInProgress) {
                this.syncPendingRecords();
            }
        }, 30000);

        // Initial sync if online
        if (this.isOnline) {
            setTimeout(() => this.syncPendingRecords(), 2000);
        }

        // Listen for service worker messages (background sync)
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', (event) => {
                if (event.data && event.data.type === 'SYNC_ATTENDANCE') {
                    console.log('[SyncManager] Background sync triggered by service worker');
                    this.syncPendingRecords();
                }
            });
        }

        // Register for background sync if available
        if ('serviceWorker' in navigator && 'sync' in self.ServiceWorkerRegistration.prototype) {
            navigator.serviceWorker.ready.then(registration => {
                // Background sync will be triggered by service worker
                console.log('[SyncManager] Background sync API available');
            });
        }
    }

    async checkOnlineStatus() {
        try {
            // Try to fetch a small resource to verify connectivity
            const response = await fetch('api/get-user-info.php', {
                method: 'HEAD',
                cache: 'no-cache',
                mode: 'no-cors'
            }).catch(() => null);
            
            this.isOnline = navigator.onLine;
        } catch (error) {
            this.isOnline = false;
        }
    }

    /**
     * Sync all pending attendance records
     */
    async syncPendingRecords() {
        if (this.syncInProgress || !this.isOnline) {
            return;
        }

        this.syncInProgress = true;
        console.log('[SyncManager] Starting sync of pending records...');

        try {
            const pendingRecords = await this.offlineStorage.getPendingRecords();
            
            if (pendingRecords.length === 0) {
                console.log('[SyncManager] No pending records to sync');
                this.syncInProgress = false;
                this.updateSyncStatus(0);
                return;
            }

            console.log(`[SyncManager] Found ${pendingRecords.length} pending records`);

            let successCount = 0;
            let failCount = 0;

            // Sync each record (retry forever for transient errors — stay pending until success or server rejection)
            for (const record of pendingRecords) {
                // Skip if this record is already being synced (prevent duplicates)
                if (this.syncingRecords.has(record.id)) {
                    console.log(`[SyncManager] Record ${record.id} is already being synced, skipping`);
                    continue;
                }
                
                this.syncingRecords.add(record.id);
                
                try {
                    const result = await this.syncRecord(record);
                    
                    // Handle result object
                    const synced = result.success === true;
                    const isRetryable = result.isRetryable !== undefined ? result.isRetryable : true;
                    
                    if (synced) {
                        successCount++;
                        try {
                            await this.offlineStorage.markAsSynced(record.id);
                            console.log(`[SyncManager] Record ${record.id} marked as synced successfully`);
                        } catch (markError) {
                            console.error(`[SyncManager] Failed to mark record ${record.id} as synced:`, markError);
                            // Retry marking as synced - this is critical
                            try {
                                await new Promise(resolve => setTimeout(resolve, 100));
                                await this.offlineStorage.markAsSynced(record.id);
                                console.log(`[SyncManager] Record ${record.id} marked as synced on retry`);
                            } catch (retryError) {
                                console.error(`[SyncManager] Failed to mark record ${record.id} as synced on retry:`, retryError);
                                // Don't fail the whole sync if marking fails - record is already synced to server
                            }
                        }
                    } else {
                        failCount++;
                        // Only increment retry count for retryable errors
                        if (isRetryable) {
                            await this.offlineStorage.updateRetryCount(record.id, true);
                        } else {
                            // For non-retryable errors (holiday, TARF, validation), mark as failed so we don't retry
                            console.warn(`[SyncManager] Record ${record.id} failed with non-retryable error, marking as failed`);
                            await this.offlineStorage.markAsFailed(record.id, result.message || 'Server rejected');
                        }
                    }
                } catch (error) {
                    console.error('[SyncManager] Unexpected error syncing record:', error);
                    failCount++;
                    // Check if error is retryable
                    const statusCode = error.statusCode || 0;
                    const isRetryable = error.isRetryable !== undefined 
                        ? error.isRetryable 
                        : this.isRetryableError(statusCode);
                    
                    if (isRetryable) {
                        await this.offlineStorage.updateRetryCount(record.id, true);
                    }
                } finally {
                    // Always remove from syncing set
                    this.syncingRecords.delete(record.id);
                }
                
                // Add small delay between requests to avoid overwhelming the server
                if (pendingRecords.length > 1) {
                    await new Promise(resolve => setTimeout(resolve, 200));
                }
            }

            // Cleanup old synced records
            await this.offlineStorage.cleanupOldRecords();
            
            // Cleanup old user cache entries
            await this.offlineStorage.cleanupOldUserCache();

            // Small delay to ensure all IndexedDB transactions are fully committed
            await new Promise(resolve => setTimeout(resolve, 150));

            // Get updated pending count (after marking failed records and cleanup)
            // Verify with a fresh query to ensure accuracy
            let remainingPending = await this.offlineStorage.getPendingCount();
            
            // Double-check if count seems incorrect (if we had successes but still have pending)
            if (successCount > 0 && remainingPending > 0) {
                // Wait a bit more and re-check to handle any IndexedDB commit delays
                await new Promise(resolve => setTimeout(resolve, 100));
                remainingPending = await this.offlineStorage.getPendingCount();
            }
            
            console.log(`[SyncManager] Sync complete: ${successCount} succeeded, ${failCount} failed, ${remainingPending} still pending`);
            this.updateSyncStatus(remainingPending);

        } catch (error) {
            console.error('[SyncManager] Error during sync:', error);
            // Update status even if sync failed
            try {
                const remainingPending = await this.offlineStorage.getPendingCount();
                this.updateSyncStatus(remainingPending);
            } catch (statusError) {
                console.error('[SyncManager] Failed to update status after error:', statusError);
            }
        } finally {
            // Mark sync as complete BEFORE final status update
            this.syncInProgress = false;
            
            // Final status update to ensure UI is accurate
            // Add delay to ensure IndexedDB transactions are fully committed
            setTimeout(async () => {
                try {
                    const finalPending = await this.offlineStorage.getPendingCount();
                    // Use updateSyncStatus which will use the updated syncInProgress (false)
                    this.updateSyncStatus(finalPending);
                } catch (statusError) {
                    console.error('[SyncManager] Failed to update final status:', statusError);
                }
            }, 150);
        }
    }

    /**
     * Check if an error is retryable (transient server errors)
     */
    isRetryableError(statusCode, error) {
        // Network errors are retryable
        if (!statusCode) {
            return true;
        }
        
        // 5xx errors (server errors) are retryable
        if (statusCode >= 500 && statusCode < 600) {
            return true;
        }
        
        // 408 (Request Timeout) and 429 (Too Many Requests) are retryable
        if (statusCode === 408 || statusCode === 429) {
            return true;
        }
        
        // 4xx errors (client errors) are generally not retryable
        // except for specific cases
        return false;
    }

    /**
     * Sync a single attendance record
     */
    async syncRecord(record) {
        try {
            // Prepare request body (record-attendance.php accepts user_id or qr_data)
            const requestBody = {
                attendance_type: record.attendance_type
            };
            // Prefer valid user_id (number); undefined/null/0 can come from IndexedDB for qr_data-only records
            const hasValidUserId = typeof record.user_id === 'number' && record.user_id > 0;
            const hasValidQrData = record.qr_data != null && String(record.qr_data).trim() !== '';
            if (hasValidUserId) {
                requestBody.user_id = record.user_id;
            } else if (hasValidQrData) {
                requestBody.qr_data = String(record.qr_data).trim();
            } else {
                console.warn(`[SyncManager] Record ${record.id} has no valid user_id or qr_data (user_id=${record.user_id}, qr_data=${record.qr_data ? '(present)' : 'missing'}), skipping`);
                return { success: false, isRetryable: false };
            }

            if (record.station_id) {
                requestBody.station_id = record.station_id;
            }
            
            // Include the original timestamp and time if available (for offline records)
            // This ensures the server uses the original time when the action occurred, not the sync time
            if (record.recorded_time) {
                requestBody.recorded_time = record.recorded_time;
                console.log(`[SyncManager] Using recorded_time from record ${record.id}: ${record.recorded_time}`);
            } else if (record.timestamp) {
                // Fallback: extract time from ISO timestamp for older records
                try {
                    const timestampDate = new Date(record.timestamp);
                    const hours = String(timestampDate.getHours()).padStart(2, '0');
                    const minutes = String(timestampDate.getMinutes()).padStart(2, '0');
                    const seconds = String(timestampDate.getSeconds()).padStart(2, '0');
                    requestBody.recorded_time = `${hours}:${minutes}:${seconds}`;
                    console.log(`[SyncManager] Extracted recorded_time from timestamp for record ${record.id}: ${requestBody.recorded_time}`);
                } catch (e) {
                    console.warn(`[SyncManager] Could not parse timestamp for record ${record.id}:`, e);
                }
            } else {
                console.warn(`[SyncManager] No recorded_time or timestamp found for record ${record.id}, server will use current time`);
            }
            
            if (record.log_date) {
                requestBody.log_date = record.log_date;
                console.log(`[SyncManager] Using log_date from record ${record.id}: ${record.log_date}`);
            } else if (record.timestamp) {
                // Fallback: extract date from ISO timestamp - use LOCAL date (not UTC)
                // toISOString() uses UTC which can give wrong date for early morning in Manila (UTC+8)
                try {
                    const timestampDate = new Date(record.timestamp);
                    const y = timestampDate.getFullYear();
                    const m = String(timestampDate.getMonth() + 1).padStart(2, '0');
                    const d = String(timestampDate.getDate()).padStart(2, '0');
                    requestBody.log_date = `${y}-${m}-${d}`;
                    console.log(`[SyncManager] Extracted log_date from timestamp for record ${record.id}: ${requestBody.log_date}`);
                } catch (e) {
                    console.warn(`[SyncManager] Could not parse timestamp date for record ${record.id}:`, e);
                }
            } else {
                console.warn(`[SyncManager] No log_date or timestamp found for record ${record.id}, server will use current date`);
            }
            
            console.log(`[SyncManager] Syncing record ${record.id} with:`, {
                attendance_type: requestBody.attendance_type,
                user_id: requestBody.user_id,
                qr_data: requestBody.qr_data ? '(present)' : undefined,
                recorded_time: requestBody.recorded_time,
                log_date: requestBody.log_date,
                station_id: requestBody.station_id
            });

            // Create AbortController for timeout
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), this.requestTimeout);

            try {
                // Send to server with timeout
                const response = await fetch('api/record-attendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(requestBody),
                    credentials: 'same-origin',
                    signal: controller.signal
                });

                clearTimeout(timeoutId);

                // Try to parse response as JSON
                let result;
                let responseText = null;
                try {
                    responseText = await response.text();
                    if (!responseText || responseText.trim() === '') {
                        // Empty response but status is OK - might be successful
                        if (response.ok) {
                            console.log(`[SyncManager] Empty response but status OK for record ${record.id}, assuming success`);
                            return { success: true };
                        }
                        throw new Error('Empty response from server');
                    }
                    
                    // Try to parse JSON, handling potential whitespace issues
                    const trimmedText = responseText.trim();
                    result = JSON.parse(trimmedText);
                    
                    // Log the parsed result for debugging
                    console.log(`[SyncManager] Parsed response for record ${record.id}:`, result);
                } catch (parseError) {
                    console.error(`[SyncManager] Failed to parse response for record ${record.id}:`, parseError);
                    if (responseText) {
                        console.error(`[SyncManager] Response text was:`, responseText.substring(0, 200));
                    }
                    // If we can't parse JSON but status is OK, it might still be successful
                    if (response.ok) {
                        console.log(`[SyncManager] Response OK but invalid JSON for record ${record.id}, assuming success`);
                        return { success: true };
                    }
                    const error = new Error(`Invalid JSON response: ${parseError.message}`);
                    error.statusCode = response.status || 0;
                    error.isRetryable = true;
                    throw error;
                }

                if (!response.ok) {
                    const statusCode = response.status;
                    const isRetryable = this.isRetryableError(statusCode);
                    const errorMessage = result.message || response.statusText || 'Unknown error';

                    const error = new Error(`HTTP ${statusCode}: ${errorMessage}`);
                    error.statusCode = statusCode;
                    error.isRetryable = isRetryable;
                    
                    // For non-retryable errors, don't increment retry count
                    if (!isRetryable) {
                        console.warn(`[SyncManager] Non-retryable error for record ${record.id}: ${error.message}`);
                        return { success: false, isRetryable: false };
                    }
                    
                    throw error;
                }

                // Response is OK, check success flag
                // Handle both boolean true and string "true" for robustness
                const isSuccess = result.success === true || result.success === 'true' || result.success === 1;
                
                if (isSuccess) {
                    console.log(`[SyncManager] Successfully synced record ${record.id}`, result);
                    return { success: true };
                } else {
                    // Server rejected the record (validation error, holiday, TARF, etc.)
                    const msg = result.message || 'Unknown reason';
                    console.warn(`[SyncManager] Server rejected record ${record.id}:`, msg, result);
                    return { success: false, isRetryable: false, message: msg };
                }

            } catch (fetchError) {
                clearTimeout(timeoutId);
                
                // Handle timeout/abort
                if (fetchError.name === 'AbortError') {
                    const error = new Error('Request timeout');
                    error.statusCode = 0;
                    error.isRetryable = true;
                    throw error;
                }
                
                // Re-throw if it's already an error we created
                if (fetchError.statusCode !== undefined) {
                    throw fetchError;
                }
                
                // Network errors (connection refused, etc.)
                const error = new Error(fetchError.message || 'Network error');
                error.statusCode = 0;
                error.isRetryable = true;
                throw error;
            }

        } catch (error) {
            const statusCode = error.statusCode || 0;
            const isRetryable = error.isRetryable !== undefined ? error.isRetryable : this.isRetryableError(statusCode);
            
            // Log with more context
            if (statusCode >= 500) {
                console.warn(`[SyncManager] Server error (${statusCode}) for record ${record.id}, will retry:`, error.message);
            } else if (statusCode === 0) {
                console.warn(`[SyncManager] Network error for record ${record.id}, will retry:`, error.message);
            } else {
                console.error(`[SyncManager] Failed to sync record ${record.id}:`, error.message);
            }
            
            return { success: false, isRetryable };
        }
    }

    /**
     * Update UI with sync status
     */
    updateSyncStatus(pendingCount) {
        // Ensure pendingCount is a number
        const count = typeof pendingCount === 'number' ? pendingCount : 0;
        // Dispatch custom event for UI updates
        const event = new CustomEvent('syncStatusUpdate', {
            detail: {
                pendingCount: count,
                isOnline: this.isOnline,
                isSyncing: this.syncInProgress
            }
        });
        window.dispatchEvent(event);
        console.log(`[SyncManager] Status updated: ${count} pending, syncing: ${this.syncInProgress}, online: ${this.isOnline}`);
    }

    /**
     * Force sync now
     */
    async forceSync() {
        await this.checkOnlineStatus();
        if (!this.isOnline) {
            throw new Error('No internet connection');
        }
        await this.syncPendingRecords();
    }

    /**
     * Get current sync status
     */
    async getStatus() {
        const pendingCount = await this.offlineStorage.getPendingCount();
        const status = {
            isOnline: this.isOnline,
            pendingCount: pendingCount,
            isSyncing: this.syncInProgress
        };
        // Log status for debugging
        if (pendingCount > 0) {
            console.log('[SyncManager] Current status:', status);
        }
        return status;
    }

    /**
     * Debug method to get detailed sync information
     */
    async getDebugInfo() {
        const pendingRecords = await this.offlineStorage.getPendingRecords();
        return {
            isOnline: this.isOnline,
            isSyncing: this.syncInProgress,
            pendingCount: pendingRecords.length,
            pendingRecords: pendingRecords.map(r => ({
                id: r.id,
                user_id: r.user_id,
                attendance_type: r.attendance_type,
                retry_count: r.retry_count,
                timestamp: r.timestamp
            }))
        };
    }

    /**
     * Cleanup on destroy
     */
    destroy() {
        if (this.syncInterval) {
            clearInterval(this.syncInterval);
            this.syncInterval = null;
        }
    }
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SyncManager;
}

