/**
 * Offline Storage Manager for QR Scanner
 * Uses IndexedDB to store attendance records when offline
 */

class OfflineStorage {
    constructor() {
        this.dbName = 'QRScannerDB';
        this.version = 2; // Increment version to add user cache store
        this.db = null;
        this.storeName = 'pendingAttendance';
        this.userCacheStoreName = 'userCache';
    }

    /**
     * Initialize IndexedDB
     */
    async init() {
        return new Promise((resolve, reject) => {
            if (!('indexedDB' in window)) {
                reject(new Error('IndexedDB is not supported'));
                return;
            }

            const request = indexedDB.open(this.dbName, this.version);

            request.onerror = () => {
                reject(new Error('Failed to open IndexedDB'));
            };

            request.onsuccess = () => {
                this.db = request.result;
                console.log('[OfflineStorage] IndexedDB opened successfully');
                resolve(this.db);
            };

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                const oldVersion = event.oldVersion || 0;

                // Create object store for pending attendance records
                if (!db.objectStoreNames.contains(this.storeName)) {
                    const objectStore = db.createObjectStore(this.storeName, {
                        keyPath: 'id',
                        autoIncrement: true
                    });

                    // Create indexes for efficient queries
                    objectStore.createIndex('timestamp', 'timestamp', { unique: false });
                    objectStore.createIndex('user_id', 'user_id', { unique: false });
                    objectStore.createIndex('employee_id', 'employee_id', { unique: false });
                    objectStore.createIndex('sync_status', 'sync_status', { unique: false });
                    objectStore.createIndex('log_date', 'log_date', { unique: false });

                    console.log('[OfflineStorage] Object store created');
                }

                // Create object store for user cache (for offline QR scanning)
                if (!db.objectStoreNames.contains(this.userCacheStoreName)) {
                    const userCacheStore = db.createObjectStore(this.userCacheStoreName, {
                        keyPath: 'employee_id'
                    });

                    // Create indexes for efficient queries
                    userCacheStore.createIndex('user_id', 'user_id', { unique: true });
                    userCacheStore.createIndex('cached_at', 'cached_at', { unique: false });
                    userCacheStore.createIndex('last_used', 'last_used', { unique: false });

                    console.log('[OfflineStorage] User cache store created');
                }
            };
        });
    }

    /**
     * Store attendance record for offline sync
     */
    async storeAttendance(attendanceData) {
        if (!this.db) {
            await this.init();
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);

            // Preserve the original timestamp if provided, otherwise use current time
            // This ensures we keep the ISO timestamp from when the action occurred
            const originalTimestamp = attendanceData.timestamp || new Date().toISOString();
            
            const record = {
                ...attendanceData,
                timestamp: originalTimestamp, // Preserve original timestamp (ISO string) or set to current ISO string
                stored_at: Date.now(), // Track when record was stored in IndexedDB (for debugging)
                sync_status: 'pending',
                retry_count: 0,
                last_retry: null
            };

            // Ensure recorded_time and log_date are preserved
            if (!record.recorded_time && record.timestamp) {
                // Extract time from timestamp if recorded_time is missing
                try {
                    const timestampDate = new Date(record.timestamp);
                    const hours = String(timestampDate.getHours()).padStart(2, '0');
                    const minutes = String(timestampDate.getMinutes()).padStart(2, '0');
                    const seconds = String(timestampDate.getSeconds()).padStart(2, '0');
                    record.recorded_time = `${hours}:${minutes}:${seconds}`;
                } catch (e) {
                    console.warn('[OfflineStorage] Could not extract time from timestamp:', e);
                }
            }
            
            if (!record.log_date && record.timestamp) {
                // Extract LOCAL date from timestamp (not UTC - toISOString gives wrong date for early morning Manila)
                try {
                    const timestampDate = new Date(record.timestamp);
                    const y = timestampDate.getFullYear();
                    const m = String(timestampDate.getMonth() + 1).padStart(2, '0');
                    const d = String(timestampDate.getDate()).padStart(2, '0');
                    record.log_date = `${y}-${m}-${d}`;
                } catch (e) {
                    console.warn('[OfflineStorage] Could not extract date from timestamp:', e);
                }
            }

            const request = store.add(record);

            request.onsuccess = () => {
                console.log('[OfflineStorage] Attendance record stored:', {
                    id: request.result,
                    user_id: record.user_id,
                    attendance_type: record.attendance_type,
                    recorded_time: record.recorded_time,
                    log_date: record.log_date,
                    timestamp: record.timestamp
                });
                resolve(request.result);
            };

            request.onerror = () => {
                console.error('[OfflineStorage] Failed to store attendance:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Get all pending attendance records
     */
    async getPendingRecords() {
        if (!this.db) {
            await this.init();
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readonly');
            const store = transaction.objectStore(this.storeName);
            const index = store.index('sync_status');
            const request = index.getAll('pending');

            request.onsuccess = () => {
                resolve(request.result || []);
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    /**
     * Mark record as synced
     */
    async markAsSynced(id) {
        if (!this.db) {
            await this.init();
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);
            const getRequest = store.get(id);

            getRequest.onsuccess = () => {
                const record = getRequest.result;
                if (record) {
                    record.sync_status = 'synced';
                    record.synced_at = Date.now();
                    const updateRequest = store.put(record);

                    updateRequest.onsuccess = () => {
                        console.log('[OfflineStorage] Record marked as synced:', id);
                        resolve();
                    };

                    updateRequest.onerror = () => {
                        reject(updateRequest.error);
                    };
                } else {
                    resolve();
                }
            };

            getRequest.onerror = () => {
                reject(getRequest.error);
            };
        });
    }

    /**
     * Mark record as failed (exceeded max retries)
     */
    async markAsFailed(id, reason = 'Max retries exceeded') {
        if (!this.db) {
            await this.init();
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);
            const getRequest = store.get(id);

            getRequest.onsuccess = () => {
                const record = getRequest.result;
                if (record) {
                    record.sync_status = 'failed';
                    record.failed_at = Date.now();
                    record.failure_reason = reason;
                    const updateRequest = store.put(record);

                    updateRequest.onsuccess = () => {
                        console.log('[OfflineStorage] Record marked as failed:', id, reason);
                        resolve();
                    };

                    updateRequest.onerror = () => {
                        reject(updateRequest.error);
                    };
                } else {
                    resolve();
                }
            };

            getRequest.onerror = () => {
                reject(getRequest.error);
            };
        });
    }

    /**
     * Delete synced records older than 7 days
     */
    async cleanupOldRecords() {
        if (!this.db) {
            await this.init();
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);
            const index = store.index('sync_status');
            const request = index.getAll('synced');

            request.onsuccess = () => {
                const records = request.result || [];
                const sevenDaysAgo = Date.now() - (7 * 24 * 60 * 60 * 1000);
                let deletedCount = 0;

                records.forEach(record => {
                    if (record.synced_at && record.synced_at < sevenDaysAgo) {
                        store.delete(record.id);
                        deletedCount++;
                    }
                });

                console.log(`[OfflineStorage] Cleaned up ${deletedCount} old records`);
                resolve(deletedCount);
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    /**
     * Update retry count for failed sync
     */
    async updateRetryCount(id, increment = true) {
        if (!this.db) {
            await this.init();
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);
            const getRequest = store.get(id);

            getRequest.onsuccess = () => {
                const record = getRequest.result;
                if (record) {
                    if (increment) {
                        record.retry_count = (record.retry_count || 0) + 1;
                    }
                    record.last_retry = Date.now();
                    const updateRequest = store.put(record);

                    updateRequest.onsuccess = () => {
                        resolve(record.retry_count);
                    };

                    updateRequest.onerror = () => {
                        reject(updateRequest.error);
                    };
                } else {
                    resolve(0);
                }
            };

            getRequest.onerror = () => {
                reject(getRequest.error);
            };
        });
    }

    /**
     * Get count of pending records
     */
    async getPendingCount() {
        const records = await this.getPendingRecords();
        return records.length;
    }

    /**
     * Clear all records (for testing/debugging)
     */
    async clearAll() {
        if (!this.db) {
            await this.init();
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);
            const request = store.clear();

            request.onsuccess = () => {
                console.log('[OfflineStorage] All records cleared');
                resolve();
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    /**
     * Cache user data for offline QR scanning
     */
    async cacheUserData(userData) {
        if (!this.db) {
            await this.init();
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.userCacheStoreName], 'readwrite');
            const store = transaction.objectStore(this.userCacheStoreName);

            const cacheEntry = {
                employee_id: userData.employee_id,
                user_id: userData.user_id,
                user_data: userData,
                cached_at: Date.now(),
                last_used: Date.now()
            };

            const request = store.put(cacheEntry);

            request.onsuccess = () => {
                console.log('[OfflineStorage] User data cached:', userData.employee_id);
                resolve();
            };

            request.onerror = () => {
                console.error('[OfflineStorage] Failed to cache user data:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Get user data from cache by employee_id
     */
    async getUserFromCache(employeeId) {
        if (!this.db) {
            await this.init();
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.userCacheStoreName], 'readwrite');
            const store = transaction.objectStore(this.userCacheStoreName);
            const request = store.get(employeeId);

            request.onsuccess = () => {
                const cacheEntry = request.result;
                if (cacheEntry) {
                    // Update last_used timestamp
                    cacheEntry.last_used = Date.now();
                    store.put(cacheEntry);
                    
                    console.log('[OfflineStorage] User data retrieved from cache:', employeeId);
                    resolve(cacheEntry.user_data);
                } else {
                    resolve(null);
                }
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    /**
     * Get user data from cache by user_id
     */
    async getUserFromCacheByUserId(userId) {
        if (!this.db) {
            await this.init();
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.userCacheStoreName], 'readonly');
            const store = transaction.objectStore(this.userCacheStoreName);
            const index = store.index('user_id');
            const request = index.get(userId);

            request.onsuccess = () => {
                const cacheEntry = request.result;
                if (cacheEntry) {
                    console.log('[OfflineStorage] User data retrieved from cache by user_id:', userId);
                    resolve(cacheEntry.user_data);
                } else {
                    resolve(null);
                }
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    /**
     * Clean up old cached user data (older than 30 days)
     */
    async cleanupOldUserCache() {
        if (!this.db) {
            await this.init();
        }

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.userCacheStoreName], 'readwrite');
            const store = transaction.objectStore(this.userCacheStoreName);
            const index = store.index('last_used');
            const request = index.openCursor();

            const thirtyDaysAgo = Date.now() - (30 * 24 * 60 * 60 * 1000);
            let deletedCount = 0;

            request.onsuccess = (event) => {
                const cursor = event.target.result;
                if (cursor) {
                    if (cursor.value.last_used < thirtyDaysAgo) {
                        cursor.delete();
                        deletedCount++;
                    }
                    cursor.continue();
                } else {
                    console.log(`[OfflineStorage] Cleaned up ${deletedCount} old user cache entries`);
                    resolve(deletedCount);
                }
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = OfflineStorage;
}

