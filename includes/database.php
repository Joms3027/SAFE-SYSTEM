<?php
/**
 * Database access uses one PDO per HTTP request (singleton). That is the usual PHP substitute for
 * connection pooling: concurrency is bounded by concurrent requests, not idle workers. Heavy reuse
 * across app servers belongs in infrastructure (e.g. ProxySQL). Connections are released on
 * shutdown via releaseInstance(); non-persistent PDO closes the server session when the handle drops.
 */
class Database {
    private static $instance = null;
    private $host = DB_HOST;
    private $dbname = DB_NAME;
    private $user = DB_USER;
    private $pass = DB_PASS;
    private $conn;
    private static $queryCache = [];
    private static $cacheEnabled = true;
    private static $cacheTTL = 60; // 1 minute — shorter TTL since file cache is no longer cleared on every write
    private static $fileCacheDir = null;
    private static $fileCacheEnabled = true;
    private static $maxCacheSize = 50 * 1024 * 1024; // 50MB max cache size

    /** @var bool Ensures shutdown releases the PDO once per request */
    private static $shutdownRegistered = false;

    /**
     * PDO options shared by the constructor and reconnect().
     * Default (DB_PERSISTENT false): connections close with the request — avoids idle "Sleep" slots
     * piling up per PHP worker on shared MySQL. Enable DB_PERSISTENT only with a dedicated DB and
     * sufficient max_connections.
     */
    private static function getPdoOptions(): array {
        $persistent = defined('DB_PERSISTENT') && DB_PERSISTENT;
        $options = [
            PDO::ATTR_PERSISTENT => $persistent,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::ATTR_TIMEOUT => 5,
        ];
        if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
            $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 5;
        }
        return $options;
    }

    private static function registerShutdownHandler(): void {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function([self::class, 'releaseInstance']);
    }

    /**
     * Drops the PDO handle and clears the singleton (end of request lifecycle).
     */
    public static function releaseInstance(): void {
        if (self::$instance !== null) {
            self::$instance->releasePdo();
            self::$instance = null;
        }
    }

    /**
     * Alias for releaseInstance() — call from CLI/cron after work completes if the shutdown hook is unreliable.
     */
    public static function disconnect(): void {
        self::releaseInstance();
    }

    /**
     * Run a callback with getInstance(), then always disconnect. Use for standalone CLI batches only —
     * not inside normal web requests that expect the singleton to survive the whole script.
     *
     * @param callable(Database $db): mixed $callback
     * @return mixed
     */
    public static function runScoped(callable $callback) {
        try {
            return $callback(self::getInstance());
        } finally {
            self::releaseInstance();
        }
    }

    private function releasePdo(): void {
        $this->conn = null;
    }

    public function __destruct() {
        $this->releasePdo();
    }

    private function __construct() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4";
            $this->conn = new PDO($dsn, $this->user, $this->pass, self::getPdoOptions());
            
            // Initialize file cache directory
            if (self::$fileCacheDir === null) {
                $cachePath = dirname(__DIR__) . '/storage/cache';
                if (!is_dir($cachePath)) {
                    @mkdir($cachePath, 0755, true);
                }
                self::$fileCacheDir = $cachePath;
            }
        } catch(PDOException $e) {
            $errorMsg = "Database connection failed: " . $e->getMessage();
            error_log($errorMsg);
            
            // Check if we're in a local/development environment
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1']) || 
                          strpos($host, 'localhost') !== false ||
                          strpos($host, '127.0.0.1') !== false ||
                          preg_match('/^169\.254\./', $host) ||  // Link-local (APIPA) addresses
                          preg_match('/^192\.168\./', $host) ||  // Private network range
                          preg_match('/^10\./', $host) ||        // Private network range
                          preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host); // Private network 172.16-31.x.x
            
            error_log("DB Connection Details - Host: " . DB_HOST . ", DB: " . DB_NAME . ", User: " . DB_USER);
            
            http_response_code(500);
            if ($isLocalhost) {
                die("Database Connection Error: " . htmlspecialchars($e->getMessage()) . 
                    "<br><br><strong>Common issues:</strong><br>" .
                    "- MySQL service might not be running<br>" .
                    "- Database might not exist yet<br>" .
                    "- Check your database credentials (set via environment variables)");
            } else {
                die("Database connection error. Please contact the administrator. Error code: DB_CONN_001");
            }
        }
    }
    
    /**
     * Get singleton instance of Database with connection retry
     * Implements connection pooling-style retry for high-availability
     * 
     * @param int $maxRetries Maximum connection attempts
     * @return Database
     * @throws Exception If connection fails after all retries
     */
    public static function getInstance($maxRetries = 3) {
        if (self::$instance === null) {
            $attempts = 0;
            $lastError = null;
            
            while ($attempts < $maxRetries) {
                $attempts++;
                try {
                    self::$instance = new self();
                    self::registerShutdownHandler();

                    // Set additional connection attributes for concurrent access
                    $conn = self::$instance->getConnection();
                    // Set query timeout to prevent long-running queries from blocking
                    $conn->setAttribute(PDO::ATTR_TIMEOUT, 30); // 30 second query timeout

                    return self::$instance;
                } catch (PDOException $e) {
                    $lastError = $e;
                    $errorCode = $e->errorInfo[1] ?? 0;
                    
                    // Retry on connection-related errors
                    // 2002 = Can't connect to MySQL server
                    // 2003 = Can't connect to MySQL server (socket)
                    // 2006 = MySQL server has gone away
                    // 2013 = Lost connection during query
                    // 1040 = Too many connections (may resolve if we wait)
                    if (in_array($errorCode, [2002, 2003, 2006, 2013, 1040])) {
                        if ($attempts < $maxRetries) {
                            // Wait before retry with exponential backoff + jitter
                            // Jitter helps prevent thundering herd when many users reconnect simultaneously
                            $baseDelay = 100000 * pow(2, $attempts - 1); // 100ms, 200ms, 400ms...
                            $jitter = rand(0, 50000); // Random 0-50ms to spread out retries
                            usleep(min($baseDelay + $jitter, 2000000)); // Max 2 seconds
                            continue;
                        }
                    }
                    
                    // Non-retryable error, throw immediately
                    throw $e;
                }
            }
            
            // All retries exhausted
            if ($lastError) {
                throw $lastError;
            }
        }
        return self::$instance;
    }
    
    /**
     * Get singleton instance or null (no exceptions)
     * Useful when you want to handle DB unavailability gracefully
     * 
     * @return Database|null
     */
    public static function getInstanceOrNull() {
        try {
            return self::getInstance();
        } catch (Exception $e) {
            error_log("Database connection failed (graceful): " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}
    
    /**
     * Prevent unserializing of the instance
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }

    public function getConnection() {
        return $this->conn;
    }

    /**
     * Get cache key for query
     */
    private function getCacheKey($sql, $params) {
        return md5($sql . serialize($params));
    }

    /**
     * Get file cache path for a cache key
     */
    private function getFileCachePath($cacheKey) {
        if (!self::$fileCacheDir || !is_dir(self::$fileCacheDir)) {
            return null;
        }
        return self::$fileCacheDir . '/' . substr($cacheKey, 0, 2) . '/' . $cacheKey . '.cache';
    }

    /**
     * Read from file cache with proper locking to avoid reading partial writes
     */
    private function readFileCache($cacheKey) {
        if (!self::$fileCacheEnabled) {
            return null;
        }
        
        $cachePath = $this->getFileCachePath($cacheKey);
        if (!$cachePath || !file_exists($cachePath)) {
            return null;
        }
        
        // Use file locking to avoid reading while another process is writing
        $fp = @fopen($cachePath, 'r');
        if ($fp === false) {
            return null;
        }
        
        // Try to get a shared (read) lock - non-blocking
        if (!flock($fp, LOCK_SH | LOCK_NB)) {
            // File is being written, skip cache this time
            fclose($fp);
            return null;
        }
        
        $data = '';
        while (!feof($fp)) {
            $data .= fread($fp, 8192);
        }
        
        flock($fp, LOCK_UN);
        fclose($fp);
        
        if (empty($data)) {
            return null;
        }
        
        $cached = @unserialize($data);
        if (!$cached || !isset($cached['time']) || !isset($cached['data'])) {
            // Invalid cache file, delete it
            @unlink($cachePath);
            return null;
        }
        
        // Check if cache is still valid
        if (time() - $cached['time'] > self::$cacheTTL) {
            @unlink($cachePath);
            return null;
        }
        
        return $cached['data'];
    }

    /**
     * Write to file cache atomically to prevent corruption
     * Uses temp file + rename for atomic write
     */
    private function writeFileCache($cacheKey, $data) {
        if (!self::$fileCacheEnabled) {
            return false;
        }
        
        $cachePath = $this->getFileCachePath($cacheKey);
        if (!$cachePath) {
            return false;
        }
        
        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        $cached = [
            'data' => $data,
            'time' => time()
        ];
        
        // Check cache size before writing
        static $lastSizeCheck = 0;
        $now = time();
        // Only check size every 60 seconds to avoid performance hit
        if ($now - $lastSizeCheck > 60) {
            $lastSizeCheck = $now;
            if ($this->getCacheSize() > self::$maxCacheSize) {
                $this->cleanOldCache();
            }
        }
        
        // Use atomic write: write to temp file, then rename
        // This prevents other processes from reading partial writes
        $tempPath = $cachePath . '.tmp.' . getmypid() . '.' . mt_rand();
        
        $result = @file_put_contents($tempPath, serialize($cached), LOCK_EX);
        if ($result === false) {
            @unlink($tempPath);
            return false;
        }
        
        // Atomic rename (on same filesystem, rename is atomic)
        if (!@rename($tempPath, $cachePath)) {
            // Rename failed, try copy + delete
            @copy($tempPath, $cachePath);
            @unlink($tempPath);
        }
        
        return true;
    }

    /**
     * Get total cache size
     */
    private function getCacheSize() {
        if (!self::$fileCacheDir || !is_dir(self::$fileCacheDir)) {
            return 0;
        }
        
        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$fileCacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }

    /**
     * Clean old cache files
     */
    private function cleanOldCache() {
        if (!self::$fileCacheDir || !is_dir(self::$fileCacheDir)) {
            return;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$fileCacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        $now = time();
        $cleaned = 0;
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                $data = @file_get_contents($file->getPathname());
                if ($data) {
                    $cached = @unserialize($data);
                    if ($cached && isset($cached['time'])) {
                        // Delete if expired
                        if ($now - $cached['time'] > self::$cacheTTL) {
                            @unlink($file->getPathname());
                            $cleaned++;
                        }
                    }
                }
            }
        }
        
        // If still too large, delete oldest files
        if ($this->getCacheSize() > self::$maxCacheSize) {
            $files = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$fileCacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'cache') {
                    $files[] = [
                        'path' => $file->getPathname(),
                        'mtime' => $file->getMTime()
                    ];
                }
            }
            
            // Sort by modification time (oldest first)
            usort($files, function($a, $b) {
                return $a['mtime'] - $b['mtime'];
            });
            
            // Delete oldest 20% of files
            $deleteCount = max(1, (int)(count($files) * 0.2));
            for ($i = 0; $i < $deleteCount && $this->getCacheSize() > self::$maxCacheSize; $i++) {
                @unlink($files[$i]['path']);
            }
        }
    }

    /**
     * Clear in-memory query cache only (fast, called on every write).
     * File cache is left to expire via TTL to avoid expensive directory walks on every INSERT/UPDATE/DELETE.
     */
    public static function clearCache() {
        self::$queryCache = [];
    }

    /**
     * Clear both in-memory and file cache.
     * Use sparingly (e.g. admin cache-clear action) since it walks the full cache directory.
     */
    public static function clearAllCache() {
        self::$queryCache = [];
        
        if (self::$fileCacheDir && is_dir(self::$fileCacheDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$fileCacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'cache') {
                    @unlink($file->getPathname());
                }
            }
        }
    }

    /**
     * Enable or disable query caching
     */
    public static function setCacheEnabled($enabled) {
        self::$cacheEnabled = $enabled;
    }

    /**
     * Enable or disable file-based caching
     */
    public static function setFileCacheEnabled($enabled) {
        self::$fileCacheEnabled = $enabled;
    }

    /**
     * Escape SQL identifier (table/column name) to prevent SQL injection
     * Only allows alphanumeric characters, underscores, and dots (for database.table)
     * 
     * @param string $identifier Table or column name
     * @return string Escaped identifier
     */
    private function escapeIdentifier($identifier) {
        // Remove any characters that aren't alphanumeric, underscore, or dot
        // This prevents SQL injection through table/column names
        $identifier = preg_replace('/[^a-zA-Z0-9_\.]/', '', $identifier);
        
        // Split by dot for database.table format
        $parts = explode('.', $identifier);
        $escaped = array_map(function($part) {
            // Wrap each part in backticks
            return '`' . str_replace('`', '``', $part) . '`';
        }, $parts);
        
        return implode('.', $escaped);
    }

    /**
     * Escape multiple identifiers (for column lists)
     * 
     * @param string|array $identifiers Column names or '*' for all columns
     * @return string Escaped column list
     */
    private function escapeIdentifiers($identifiers) {
        if ($identifiers === '*') {
            return '*';
        }
        
        if (is_array($identifiers)) {
            return implode(', ', array_map([$this, 'escapeIdentifier'], $identifiers));
        }
        
        // Handle comma-separated string
        $parts = array_map('trim', explode(',', $identifiers));
        return implode(', ', array_map([$this, 'escapeIdentifier'], $parts));
    }

    /**
     * Run a one-off SQL string (SHOW …, etc.) and close the result cursor immediately.
     *
     * @return array|false
     */
    private function queryFetchAssoc(string $sql) {
        $stmt = $this->conn->query($sql);
        try {
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } finally {
            $stmt->closeCursor();
        }
    }

    public function insert($table, $data) {
        // Escape table name to prevent SQL injection
        $table = $this->escapeIdentifier($table);
        
        // Escape column names
        $columns = $this->escapeIdentifiers(array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $stmt = $this->conn->prepare($sql);
        try {
            $result = $stmt->execute($data);

            // Clear cache after insert
            if ($result) {
                self::clearCache();
            }

            return $result;
        } finally {
            $stmt->closeCursor();
        }
    }

    public function update($table, $data, $where, $whereParams = []) {
        // Escape table name to prevent SQL injection
        $table = $this->escapeIdentifier($table);
        
        // Escape column names in SET clause
        $set = '';
        foreach ($data as $key => $value) {
            $escapedKey = $this->escapeIdentifier($key);
            $set .= "$escapedKey = :$key, ";
        }
        $set = rtrim($set, ', ');
        
        // IMPORTANT: $where should already be a prepared statement condition with placeholders
        // Example: "id = :id" or "id = ?"
        // Merge data and whereParams for execution
        $allParams = array_merge($data, $whereParams);
        
        $sql = "UPDATE $table SET $set WHERE $where";
        $stmt = $this->conn->prepare($sql);
        try {
            $result = $stmt->execute($allParams);

            // Clear cache after update
            if ($result) {
                self::clearCache();
            }

            return $result;
        } finally {
            $stmt->closeCursor();
        }
    }

    public function select($table, $columns = '*', $where = null, $params = [], $useCache = true) {
        // Escape table name and columns to prevent SQL injection
        $table = $this->escapeIdentifier($table);
        $columns = $this->escapeIdentifiers($columns);
        
        $sql = "SELECT $columns FROM $table";
        if ($where) {
            // $where should be a prepared statement condition, not raw SQL
            // Example: "id = ?" or "name = :name"
            $sql .= " WHERE $where";
        }
        
        // Check cache if enabled
        if (self::$cacheEnabled && $useCache) {
            $cacheKey = $this->getCacheKey($sql, $params);
            
            // Check in-memory cache first
            if (isset(self::$queryCache[$cacheKey])) {
                $cached = self::$queryCache[$cacheKey];
                if (time() - $cached['time'] < self::$cacheTTL) {
                    return $cached['data'];
                }
                unset(self::$queryCache[$cacheKey]);
            }
            
            // Check file cache
            $fileCached = $this->readFileCache($cacheKey);
            if ($fileCached !== null) {
                // Also store in memory cache for faster access
                self::$queryCache[$cacheKey] = [
                    'data' => $fileCached,
                    'time' => time()
                ];
                return $fileCached;
            }
        }
        
        $stmt = $this->conn->prepare($sql);
        try {
            $stmt->execute($params);
            $result = $stmt->fetchAll();

            // Cache result if enabled
            if (self::$cacheEnabled && $useCache) {
                $cacheKey = $this->getCacheKey($sql, $params);

                // Store in memory cache
                self::$queryCache[$cacheKey] = [
                    'data' => $result,
                    'time' => time()
                ];

                // Also store in file cache for persistence
                $this->writeFileCache($cacheKey, $result);
            }

            return $result;
        } finally {
            $stmt->closeCursor();
        }
    }

    public function selectOne($table, $columns = '*', $where = null, $params = [], $useCache = true) {
        // Escape table name and columns to prevent SQL injection
        $table = $this->escapeIdentifier($table);
        $columns = $this->escapeIdentifiers($columns);
        
        $sql = "SELECT $columns FROM $table";
        if ($where) {
            // $where should be a prepared statement condition, not raw SQL
            $sql .= " WHERE $where";
        }
        $sql .= " LIMIT 1";
        
        // Check cache if enabled
        if (self::$cacheEnabled && $useCache) {
            $cacheKey = $this->getCacheKey($sql, $params);
            
            // Check in-memory cache first
            if (isset(self::$queryCache[$cacheKey])) {
                $cached = self::$queryCache[$cacheKey];
                if (time() - $cached['time'] < self::$cacheTTL) {
                    return $cached['data'];
                }
                unset(self::$queryCache[$cacheKey]);
            }
            
            // Check file cache
            $fileCached = $this->readFileCache($cacheKey);
            if ($fileCached !== null) {
                // Also store in memory cache for faster access
                self::$queryCache[$cacheKey] = [
                    'data' => $fileCached,
                    'time' => time()
                ];
                return $fileCached;
            }
        }
        
        $stmt = $this->conn->prepare($sql);
        try {
            $stmt->execute($params);
            $result = $stmt->fetch();

            // Cache result if enabled
            if (self::$cacheEnabled && $useCache) {
                $cacheKey = $this->getCacheKey($sql, $params);

                // Store in memory cache
                self::$queryCache[$cacheKey] = [
                    'data' => $result,
                    'time' => time()
                ];

                // Also store in file cache for persistence
                $this->writeFileCache($cacheKey, $result);
            }

            return $result;
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Execute a raw SQL query with caching support
     */
    public function query($sql, $params = [], $useCache = true) {
        // Check cache if enabled
        if (self::$cacheEnabled && $useCache && stripos(trim($sql), 'SELECT') === 0) {
            $cacheKey = $this->getCacheKey($sql, $params);
            
            // Check in-memory cache
            if (isset(self::$queryCache[$cacheKey])) {
                $cached = self::$queryCache[$cacheKey];
                if (time() - $cached['time'] < self::$cacheTTL) {
                    return $cached['data'];
                }
                unset(self::$queryCache[$cacheKey]);
            }
            
            // Check file cache
            $fileCached = $this->readFileCache($cacheKey);
            if ($fileCached !== null) {
                self::$queryCache[$cacheKey] = [
                    'data' => $fileCached,
                    'time' => time()
                ];
                return $fileCached;
            }
        }
        
        $stmt = $this->conn->prepare($sql);
        try {
            $stmt->execute($params);

            // Only cache SELECT queries
            if (stripos(trim($sql), 'SELECT') === 0) {
                $result = $stmt->fetchAll();

                if (self::$cacheEnabled && $useCache) {
                    $cacheKey = $this->getCacheKey($sql, $params);
                    self::$queryCache[$cacheKey] = [
                        'data' => $result,
                        'time' => time()
                    ];
                    $this->writeFileCache($cacheKey, $result);
                }

                return $result;
            }

            // For INSERT/UPDATE/DELETE, clear cache
            self::clearCache();
            return $stmt->rowCount();
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Batch insert for better performance
     */
    public function batchInsert($table, $dataArray) {
        if (empty($dataArray)) {
            return false;
        }
        
        // Escape table name to prevent SQL injection
        $table = $this->escapeIdentifier($table);
        
        $columns = array_keys($dataArray[0]);
        // Escape column names
        $columnsStr = $this->escapeIdentifiers($columns);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        
        $values = [];
        $params = [];
        foreach ($dataArray as $row) {
            $values[] = $placeholders;
            foreach ($columns as $col) {
                $params[] = $row[$col] ?? null;
            }
        }
        
        $sql = "INSERT INTO $table ($columnsStr) VALUES " . implode(', ', $values);
        $stmt = $this->conn->prepare($sql);
        try {
            $result = $stmt->execute($params);

            if ($result) {
                self::clearCache();
            }

            return $result;
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Begin a database transaction
     * Use this for operations that need to be atomic (all-or-nothing)
     * 
     * @return bool True on success
     */
    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }

    /**
     * Commit the current transaction
     * 
     * @return bool True on success
     */
    public function commit() {
        $result = $this->conn->commit();
        if ($result) {
            self::clearCache();
        }
        return $result;
    }

    /**
     * Rollback the current transaction
     * 
     * @return bool True on success
     */
    public function rollback() {
        return $this->conn->rollBack();
    }

    /**
     * Check if currently in a transaction
     * 
     * @return bool True if in transaction
     */
    public function inTransaction() {
        return $this->conn->inTransaction();
    }

    /**
     * Execute a callback within a transaction with automatic retry on deadlock
     * This prevents data inconsistency from concurrent access
     * 
     * @param callable $callback Function to execute within transaction
     * @param int $maxRetries Maximum number of retry attempts on deadlock
     * @return mixed Result of the callback
     * @throws Exception If transaction fails after all retries
     */
    public function transactional(callable $callback, $maxRetries = 3) {
        $attempts = 0;
        $lastException = null;
        
        while ($attempts < $maxRetries) {
            $attempts++;
            
            try {
                $this->beginTransaction();
                $result = $callback($this);
                $this->commit();
                return $result;
            } catch (PDOException $e) {
                // Rollback the failed transaction
                if ($this->inTransaction()) {
                    $this->rollback();
                }
                
                $lastException = $e;
                
                // Check if it's a deadlock or lock wait timeout (MySQL error codes)
                // 1213 = Deadlock found when trying to get lock
                // 1205 = Lock wait timeout exceeded
                $errorCode = $e->errorInfo[1] ?? 0;
                if (in_array($errorCode, [1213, 1205])) {
                    // Wait a small random time before retry to reduce collision chance
                    usleep(rand(10000, 100000)); // 10-100ms
                    continue;
                }
                
                // Not a deadlock, throw immediately
                throw $e;
            }
        }
        
        // All retries exhausted
        throw $lastException ?? new Exception('Transaction failed after ' . $maxRetries . ' attempts');
    }

    /**
     * Execute INSERT ... ON DUPLICATE KEY UPDATE (upsert) - prevents race conditions
     * This is atomic and handles concurrent inserts safely
     * 
     * @param string $table Table name
     * @param array $data Data to insert
     * @param array $updateColumns Columns to update on duplicate key (if empty, updates all)
     * @return bool True on success
     */
    public function upsert($table, $data, $updateColumns = []) {
        $table = $this->escapeIdentifier($table);
        $columns = $this->escapeIdentifiers(array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        // Build ON DUPLICATE KEY UPDATE clause
        $updateParts = [];
        $updateData = [];
        
        if (empty($updateColumns)) {
            // Update all columns
            foreach ($data as $key => $value) {
                $escapedKey = $this->escapeIdentifier($key);
                $updateParts[] = "$escapedKey = VALUES($escapedKey)";
            }
        } else {
            // Update only specified columns
            foreach ($updateColumns as $col) {
                if (isset($data[$col])) {
                    $escapedCol = $this->escapeIdentifier($col);
                    $updateParts[] = "$escapedCol = VALUES($escapedCol)";
                }
            }
        }
        
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        if (!empty($updateParts)) {
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);
        }
        
        $stmt = $this->conn->prepare($sql);
        try {
            $result = $stmt->execute($data);

            if ($result) {
                self::clearCache();
            }

            return $result;
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Select with row-level locking for updates (FOR UPDATE)
     * Use within a transaction to prevent concurrent modifications
     * 
     * @param string $table Table name
     * @param string $columns Columns to select
     * @param string $where WHERE clause with placeholders
     * @param array $params Parameters for WHERE clause
     * @return array|false Result row or false
     */
    public function selectForUpdate($table, $columns = '*', $where = null, $params = []) {
        $table = $this->escapeIdentifier($table);
        $columns = $this->escapeIdentifiers($columns);
        
        $sql = "SELECT $columns FROM $table";
        if ($where) {
            $sql .= " WHERE $where";
        }
        $sql .= " FOR UPDATE";
        
        $stmt = $this->conn->prepare($sql);
        try {
            $stmt->execute($params);
            return $stmt->fetch();
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Get the last inserted ID
     * 
     * @return string Last insert ID
     */
    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }

    /**
     * Execute a query with connection retry on transient failures
     * Handles connection drops during long-running operations
     * 
     * @param string $sql SQL query
     * @param array $params Parameters
     * @param int $maxRetries Maximum retry attempts
     * @return PDOStatement Prepared statement (caller must consume results and call closeCursor() when done)
     */
    public function executeWithRetry($sql, $params = [], $maxRetries = 3) {
        $attempts = 0;
        $lastException = null;
        
        while ($attempts < $maxRetries) {
            $attempts++;
            
            try {
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            } catch (PDOException $e) {
                $lastException = $e;
                
                // Check if it's a connection error (MySQL error codes)
                // 2006 = MySQL server has gone away
                // 2013 = Lost connection to MySQL server
                // 2002 = Connection refused
                $errorCode = $e->errorInfo[1] ?? 0;
                if (in_array($errorCode, [2006, 2013, 2002])) {
                    // Try to reconnect
                    try {
                        $this->reconnect();
                        continue;
                    } catch (Exception $reconnectError) {
                        // Reconnection failed
                        error_log("Database reconnection failed: " . $reconnectError->getMessage());
                    }
                }
                
                // Not a connection error or reconnect failed, throw
                throw $e;
            }
        }
        
        throw $lastException ?? new Exception('Query failed after ' . $maxRetries . ' attempts');
    }

    /**
     * Attempt to reconnect to the database
     */
    private function reconnect() {
        $this->conn = null;
        $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4";
        $this->conn = new PDO($dsn, $this->user, $this->pass, self::getPdoOptions());
        $this->conn->setAttribute(PDO::ATTR_TIMEOUT, 30);
    }

    /**
     * Check if the database connection is alive
     * Useful before executing critical operations
     * 
     * @return bool True if connection is alive
     */
    public function isConnected() {
        try {
            $stmt = $this->conn->query('SELECT 1');
            try {
                $stmt->fetchColumn();
                return true;
            } finally {
                $stmt->closeCursor();
            }
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Ensure connection is alive, reconnect if needed
     * Call this before critical operations
     * 
     * @return bool True if connection is ready
     */
    public function ensureConnection() {
        if (!$this->isConnected()) {
            try {
                $this->reconnect();
                return true;
            } catch (PDOException $e) {
                error_log("Database reconnection failed: " . $e->getMessage());
                return false;
            }
        }
        return true;
    }

    /**
     * Delete a record with proper error handling
     * 
     * @param string $table Table name
     * @param string $where WHERE clause with placeholders
     * @param array $params Parameters for WHERE clause
     * @return int Number of affected rows
     */
    public function delete($table, $where, $params = []) {
        $table = $this->escapeIdentifier($table);
        
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = $this->conn->prepare($sql);
        try {
            $stmt->execute($params);

            $rowCount = $stmt->rowCount();
            if ($rowCount > 0) {
                self::clearCache();
            }

            return $rowCount;
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Get the number of rows affected by the last operation
     * 
     * @return int Row count
     */
    public function rowCount() {
        $stmt = $this->conn->query('SELECT ROW_COUNT()');
        try {
            return (int) $stmt->fetchColumn();
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Get database server status for monitoring
     * Useful for checking if the system is under heavy load
     * 
     * @return array Status information
     */
    public function getServerStatus() {
        try {
            $status = [];

            $result = $this->queryFetchAssoc("SHOW STATUS LIKE 'Threads_connected'");
            $status['threads_connected'] = $result['Value'] ?? 0;

            $result = $this->queryFetchAssoc("SHOW VARIABLES LIKE 'max_connections'");
            $status['max_connections'] = $result['Value'] ?? 100;

            // Calculate connection usage percentage
            $status['connection_usage'] = round(($status['threads_connected'] / $status['max_connections']) * 100, 2);

            $result = $this->queryFetchAssoc("SHOW STATUS LIKE 'Slow_queries'");
            $status['slow_queries'] = $result['Value'] ?? 0;

            // Warning if connection usage is high
            $status['status'] = $status['connection_usage'] > 80 ? 'high_load' : 'normal';

            return $status;
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Execute a query with graceful degradation under high load
     * Automatically retries with backoff if database is busy
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @param bool $isReadOnly Whether this is a read-only query
     * @return mixed Query result or false on failure
     */
    public function executeGraceful($sql, $params = [], $isReadOnly = true) {
        $maxAttempts = 3;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            $attempt++;
            
            try {
                // Ensure connection is alive before executing
                if (!$this->ensureConnection()) {
                    usleep(100000 * $attempt); // 100ms, 200ms, 300ms
                    continue;
                }
                
                $stmt = $this->conn->prepare($sql);
                try {
                    $stmt->execute($params);

                    if (stripos(trim($sql), 'SELECT') === 0) {
                        return $stmt->fetchAll();
                    }
                    return $stmt->rowCount();
                } finally {
                    $stmt->closeCursor();
                }
            } catch (PDOException $e) {
                $errorCode = $e->errorInfo[1] ?? 0;

                // Handle recoverable errors
                // 1040 = Too many connections
                // 1205 = Lock wait timeout
                // 1213 = Deadlock
                // 2006 = Server gone away
                // 2013 = Lost connection
                if (in_array($errorCode, [1040, 1205, 1213, 2006, 2013])) {
                    if ($attempt < $maxAttempts) {
                        // Exponential backoff with jitter
                        $delay = (100000 * pow(2, $attempt - 1)) + rand(0, 50000);
                        usleep(min($delay, 2000000)); // Max 2 seconds
                        
                        // Try to reconnect for connection errors
                        if (in_array($errorCode, [2006, 2013])) {
                            try {
                                $this->reconnect();
                            } catch (Exception $e) {
                                // Continue to retry
                            }
                        }
                        continue;
                    }
                }
                
                // Non-recoverable error or exhausted retries
                error_log("Database query failed after $attempt attempts: " . $e->getMessage());
                return false;
            }
        }
        
        return false;
    }

    /**
     * Check if database is under heavy load
     * Use this to defer non-critical operations
     * 
     * @return bool True if database is under heavy load
     */
    public function isUnderHeavyLoad() {
        try {
            $result = $this->queryFetchAssoc("SHOW STATUS LIKE 'Threads_connected'");
            $threadsConnected = intval($result['Value'] ?? 0);

            $result = $this->queryFetchAssoc("SHOW VARIABLES LIKE 'max_connections'");
            $maxConnections = intval($result['Value'] ?? 100);

            // Consider heavy load if more than 70% connections used
            return ($threadsConnected / $maxConnections) > 0.70;
        } catch (Exception $e) {
            // On error, assume not under load to avoid blocking
            return false;
        }
    }
}
?>