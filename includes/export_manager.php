<?php
/**
 * Data Export Manager
 * Handles CSV, Excel (via CSV), and PDF exports
 */

class ExportManager {
    private $db;
    
    public function __construct() {
        $database = Database::getInstance();
        $this->db = $database->getConnection();
    }
    
    /**
     * Export faculty list to CSV
     */
    public function exportFacultyList($filters = []) {
        $query = "SELECT 
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            fp.employee_id,
            fp.department,
            fp.position,
            fp.contact_number,
            fp.employment_status,
            u.is_active,
            u.created_at
        FROM users u
        LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
        WHERE u.user_type = 'faculty'";
        
        // Apply filters
        $params = [];
        if (!empty($filters['department'])) {
            $query .= " AND fp.department = ?";
            $params[] = $filters['department'];
        }
        if (!empty($filters['status'])) {
            $query .= " AND fp.employment_status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['active'])) {
            $query .= " AND u.is_active = ?";
            $params[] = $filters['active'];
        }
        
        $query .= " ORDER BY u.last_name, u.first_name";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->generateCSV($data, 'faculty_list_' . date('Y-m-d'));
    }
    
    /**
     * Export submissions report
     */
    public function exportSubmissionsReport($filters = []) {
        $query = "SELECT 
            fs.id,
            u.first_name,
            u.last_name,
            u.email,
            r.title as requirement_title,
            fs.original_filename,
            fs.file_size,
            fs.status,
            fs.submitted_at,
            fs.admin_notes,
            fs.version
        FROM faculty_submissions fs
        JOIN users u ON fs.faculty_id = u.id
        JOIN requirements r ON fs.requirement_id = r.id
        WHERE 1=1";
        
        // Apply filters
        $params = [];
        if (!empty($filters['status'])) {
            $query .= " AND fs.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['requirement_id'])) {
            $query .= " AND fs.requirement_id = ?";
            $params[] = $filters['requirement_id'];
        }
        if (!empty($filters['date_from'])) {
            $query .= " AND fs.submitted_at >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $query .= " AND fs.submitted_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $query .= " ORDER BY fs.submitted_at DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format file sizes
        foreach ($data as &$row) {
            $row['file_size'] = $this->formatFileSize($row['file_size']);
        }
        
        return $this->generateCSV($data, 'submissions_report_' . date('Y-m-d'));
    }
    
    /**
     * Export PDS report
     */
    public function exportPDSReport($filters = []) {
        $query = "SELECT 
            pds.id,
            u.first_name,
            u.last_name,
            u.email,
            pds.status,
            pds.submitted_at,
            pds.reviewed_at,
            pds.created_at,
            pds.updated_at
        FROM faculty_pds pds
        JOIN users u ON pds.faculty_id = u.id
        WHERE 1=1";
        
        // Apply filters
        $params = [];
        if (!empty($filters['status'])) {
            $query .= " AND pds.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $query .= " AND pds.submitted_at >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $query .= " AND pds.submitted_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $query .= " ORDER BY pds.submitted_at DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->generateCSV($data, 'pds_report_' . date('Y-m-d'));
    }
    
    /**
     * Export system activity logs
     */
    public function exportActivityLogs($filters = []) {
        $query = "SELECT 
            sl.id,
            CONCAT(u.first_name, ' ', u.last_name) as user_name,
            u.email,
            u.user_type,
            sl.action,
            sl.description,
            sl.ip_address,
            sl.created_at
        FROM system_logs sl
        LEFT JOIN users u ON sl.user_id = u.id
        WHERE 1=1";
        
        // Apply filters
        $params = [];
        if (!empty($filters['action'])) {
            $query .= " AND sl.action = ?";
            $params[] = $filters['action'];
        }
        if (!empty($filters['user_id'])) {
            $query .= " AND sl.user_id = ?";
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['date_from'])) {
            $query .= " AND sl.created_at >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $query .= " AND sl.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $query .= " ORDER BY sl.created_at DESC LIMIT 10000";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->generateCSV($data, 'activity_logs_' . date('Y-m-d'));
    }
    
    /**
     * Export requirements summary
     */
    public function exportRequirementsSummary() {
        $query = "SELECT 
            r.id,
            r.title,
            r.description,
            r.category,
            r.deadline,
            r.is_active,
            COUNT(DISTINCT fs.faculty_id) as total_submissions,
            SUM(CASE WHEN fs.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN fs.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN fs.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
            r.created_at
        FROM requirements r
        LEFT JOIN faculty_submissions fs ON r.id = fs.requirement_id
        GROUP BY r.id
        ORDER BY r.created_at DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->generateCSV($data, 'requirements_summary_' . date('Y-m-d'));
    }
    
    /**
     * Generate CSV file from data array
     */
    private function generateCSV($data, $filename) {
        if (empty($data)) {
            return false;
        }
        
        // Set headers for download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Write headers
        fputcsv($output, array_keys($data[0]));
        
        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Format file size for human readability
     */
    private function formatFileSize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    
    /**
     * Get available export types
     */
    public function getExportTypes() {
        return [
            'faculty_list' => 'Faculty List',
            'submissions_report' => 'Submissions Report',
            'pds_report' => 'PDS Report',
            'activity_logs' => 'Activity Logs',
            'requirements_summary' => 'Requirements Summary'
        ];
    }
}

/**
 * Helper function to get export manager instance
 */
function getExportManager() {
    static $instance = null;
    if ($instance === null) {
        $instance = new ExportManager();
    }
    return $instance;
}
?>
