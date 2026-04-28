<?php
/**
 * Query Optimization Helper
 * Provides utilities to optimize database queries and prevent N+1 problems
 */

/**
 * Batch fetch related data to prevent N+1 queries
 * 
 * @param PDO $db Database connection
 * @param string $table Table name
 * @param array $ids Array of IDs to fetch
 * @param string $idColumn Column name for IDs (default: 'id')
 * @param string $columns Columns to select (default: '*')
 * @return array Associative array keyed by ID
 */
function batchFetchByIds($db, $table, $ids, $idColumn = 'id', $columns = '*') {
    if (empty($ids)) {
        return [];
    }
    
    // Remove duplicates and filter out invalid IDs
    $ids = array_unique(array_filter($ids, function($id) {
        return is_numeric($id) && $id > 0;
    }));
    
    if (empty($ids)) {
        return [];
    }
    
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT $columns FROM $table WHERE $idColumn IN ($placeholders)";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($ids);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Index by ID for easy lookup
        $indexed = [];
        foreach ($results as $row) {
            $indexed[$row[$idColumn]] = $row;
        }
        
        return $indexed;
    } catch (PDOException $e) {
        error_log("Batch fetch error: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetch related data with JOIN to avoid N+1 queries
 * 
 * @param PDO $db Database connection
 * @param string $mainTable Main table name
 * @param array $joins Array of join configurations
 *   Format: ['table' => 'table_name', 'on' => 'condition', 'type' => 'LEFT|INNER']
 * @param string $where WHERE clause
 * @param array $params Parameters for WHERE clause
 * @param string $columns Columns to select
 * @return array
 */
function fetchWithJoins($db, $mainTable, $joins = [], $where = '', $params = [], $columns = '*') {
    $sql = "SELECT $columns FROM $mainTable";
    
    foreach ($joins as $join) {
        $type = isset($join['type']) ? strtoupper($join['type']) : 'LEFT';
        $table = $join['table'];
        $on = $join['on'];
        $sql .= " $type JOIN $table ON $on";
    }
    
    if ($where) {
        $sql .= " WHERE $where";
    }
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Join fetch error: " . $e->getMessage());
        return [];
    }
}

/**
 * Group related data by a key to avoid multiple queries
 * 
 * @param array $data Array of data rows
 * @param string $keyColumn Column to group by
 * @return array Grouped data
 */
function groupByKey($data, $keyColumn) {
    $grouped = [];
    foreach ($data as $row) {
        $key = $row[$keyColumn] ?? null;
        if ($key !== null) {
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $row;
        }
    }
    return $grouped;
}

/**
 * Optimize a query by adding LIMIT and OFFSET for pagination
 * 
 * @param string $sql Original SQL query
 * @param int $page Page number (1-based)
 * @param int $perPage Items per page
 * @return string Optimized SQL with LIMIT and OFFSET
 */
function addPagination($sql, $page = 1, $perPage = 20) {
    $page = max(1, (int)$page);
    $perPage = max(1, min(100, (int)$perPage)); // Max 100 per page
    $offset = ($page - 1) * $perPage;
    
    // Remove existing LIMIT if present
    $sql = preg_replace('/\s+LIMIT\s+\d+(\s+OFFSET\s+\d+)?$/i', '', $sql);
    
    return $sql . " LIMIT $perPage OFFSET $offset";
}

/**
 * Check if a query uses indexes efficiently
 * 
 * @param PDO $db Database connection
 * @param string $sql SQL query
 * @param array $params Query parameters
 * @return array Query execution plan
 */
function explainQuery($db, $sql, $params = []) {
    try {
        $explainSql = "EXPLAIN " . $sql;
        $stmt = $db->prepare($explainSql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Explain query error: " . $e->getMessage());
        return [];
    }
}

/**
 * Optimize a SELECT query by ensuring it uses indexes
 * 
 * @param string $table Table name
 * @param array $conditions Array of WHERE conditions ['column' => 'value']
 * @param array $options Query options ['order' => 'column ASC', 'limit' => 10]
 * @return string Optimized SQL query
 */
function buildOptimizedSelect($table, $conditions = [], $options = []) {
    $sql = "SELECT " . ($options['columns'] ?? '*') . " FROM $table";
    
    if (!empty($conditions)) {
        $where = [];
        $params = [];
        foreach ($conditions as $column => $value) {
            if (is_array($value)) {
                // IN clause
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $where[] = "$column IN ($placeholders)";
                $params = array_merge($params, $value);
            } else {
                $where[] = "$column = ?";
                $params[] = $value;
            }
        }
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    
    if (isset($options['order'])) {
        $sql .= " ORDER BY " . $options['order'];
    }
    
    if (isset($options['limit'])) {
        $sql .= " LIMIT " . (int)$options['limit'];
        if (isset($options['offset'])) {
            $sql .= " OFFSET " . (int)$options['offset'];
        }
    }
    
    return $sql;
}

/**
 * Cache query results with automatic invalidation
 * 
 * @param callable $queryCallback Function that returns query results
 * @param string $cacheKey Unique cache key
 * @param int $ttl Time to live in seconds
 * @return mixed Query results
 */
function cachedQuery($queryCallback, $cacheKey, $ttl = 300) {
    static $cache = [];
    
    if (isset($cache[$cacheKey])) {
        $cached = $cache[$cacheKey];
        if (time() - $cached['time'] < $ttl) {
            return $cached['data'];
        }
        unset($cache[$cacheKey]);
    }
    
    $result = $queryCallback();
    $cache[$cacheKey] = [
        'data' => $result,
        'time' => time()
    ];
    
    return $result;
}

