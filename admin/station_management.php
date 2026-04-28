<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

// Helper function to build pagination URL
function buildPaginationUrl($page, $search = '', $departmentFilter = '') {
    $params = ['page' => $page];
    if ($search) {
        $params['search'] = $search;
    }
    if ($departmentFilter) {
        $params['department'] = $departmentFilter;
    }
    return 'station_management.php?' . http_build_query($params);
}

// Get all stations with filters
$search = $_GET['search'] ?? '';
$departmentFilter = $_GET['department'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClause = "1=1";
$params = [];

if ($search) {
    $whereClause .= " AND s.name LIKE ?";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
}

// Get total count for pagination
$countSql = "SELECT COUNT(*) as total FROM stations s WHERE $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalRecords = $countStmt->fetch()['total'];
$totalPages = ceil($totalRecords / $perPage);

// Get paginated stations
$limit = (int)$perPage;
$offsetValue = (int)$offset;
$sql = "SELECT s.* 
        FROM stations s 
        WHERE $whereClause 
        ORDER BY s.name ASC 
        LIMIT $limit OFFSET $offsetValue";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$stations = $stmt->fetchAll();

// Get statistics
$statsStmt = $db->query("
    SELECT 
        COUNT(*) as total_stations
    FROM stations
");
$stats = $statsStmt->fetch();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Station Management', 'Manage scanning stations');
    ?>
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'Station Management',
                    'Create and manage scanning stations for attendance tracking',
                    'fas fa-building',
                    [],
                    '<button type="button" class="btn btn-primary" onclick="showAddModal()"><i class="fas fa-plus me-1"></i>Add New Station</button>'
                );
                ?>

                <?php displayMessage(); ?>

                <!-- Statistics Card -->
                <div class="row mb-4">
                    <div class="col-md-6 mx-auto">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <p class="text-muted mb-1">Total Stations</p>
                                        <h4 class="mb-0"><?php echo number_format($stats['total_stations']); ?></h4>
                                    </div>
                                    <div class="text-primary">
                                        <i class="fas fa-building fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stations List -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Station List 
                            <?php if ($totalRecords > 0): ?>
                                (Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalRecords); ?> of <?php echo $totalRecords; ?>)
                            <?php else: ?>
                                (0)
                            <?php endif; ?>
                        </h5>
                        <div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportStations()">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Search Filter -->
                        <form method="GET" class="row g-3 mb-3">
                            <div class="col-md-10">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Search by station name">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i>
                                    </button>
                                    <a href="station_management.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </div>
                            </div>
                        </form>

                        <?php if (empty($stations)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-building fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Stations Found</h5>
                                <p class="text-muted">No stations match your current filters.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Station Name</th>
                                                <th>Device Status</th>
                                                <th>Actions</th>
                                            </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stations as $station): ?>
                                            <tr>
                                                <td><?php echo $station['id']; ?></td>
                                                <td><strong><?php echo htmlspecialchars($station['name'] ?? ''); ?></strong></td>
                                                <td>
                                                    <?php if (!empty($station['device_token'])): ?>
                                                        <span class="badge bg-success"><i class="fas fa-lock me-1"></i>Device Locked</span>
                                                        <?php if (!empty($station['device_registered_at'])): ?>
                                                            <br><small class="text-muted">Registered: <?php echo date('M d, Y g:i A', strtotime($station['device_registered_at'])); ?></small>
                                                        <?php endif; ?>
                                                        <?php if (!empty($station['last_device_ip'])): ?>
                                                            <br><small class="text-muted">IP: <?php echo htmlspecialchars($station['last_device_ip']); ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark"><i class="fas fa-unlock me-1"></i>Not Registered</span>
                                                        <br><small class="text-muted">Awaiting first login</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button type="button" class="btn btn-info" 
                                                                onclick='editStation(<?php echo json_encode($station); ?>)' 
                                                                title="Edit station">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <?php if (!empty($station['device_token'])): ?>
                                                            <button type="button" class="btn btn-warning" 
                                                                    onclick="resetDevice(<?php echo $station['id']; ?>, '<?php echo htmlspecialchars($station['name'] ?? ''); ?>')" 
                                                                    title="Reset device binding">
                                                                <i class="fas fa-sync"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-danger" 
                                                                onclick="deleteStation(<?php echo $station['id']; ?>, '<?php echo htmlspecialchars($station['name'] ?? ''); ?>')" 
                                                                title="Delete station">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <nav aria-label="Page navigation" class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <!-- Previous Button -->
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo buildPaginationUrl($page - 1, $search, $departmentFilter); ?>" aria-label="Previous">
                                                <span aria-hidden="true">&laquo; Previous</span>
                                            </a>
                                        </li>
                                        
                                        <!-- Page Numbers -->
                                        <?php
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $page + 2);
                                        
                                        if ($startPage > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?php echo buildPaginationUrl(1, $search, $departmentFilter); ?>">1</a>
                                            </li>
                                            <?php if ($startPage > 2): ?>
                                                <li class="page-item disabled">
                                                    <span class="page-link">...</span>
                                                </li>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo buildPaginationUrl($i, $search, $departmentFilter); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($endPage < $totalPages): ?>
                                            <?php if ($endPage < $totalPages - 1): ?>
                                                <li class="page-item disabled">
                                                    <span class="page-link">...</span>
                                                </li>
                                            <?php endif; ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?php echo buildPaginationUrl($totalPages, $search, $departmentFilter); ?>">
                                                    <?php echo $totalPages; ?>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <!-- Next Button -->
                                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo buildPaginationUrl($page + 1, $search, $departmentFilter); ?>" aria-label="Next">
                                                <span aria-hidden="true">Next &raquo;</span>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add/Edit Station Modal -->
    <div class="modal fade" id="stationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New Station</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="stationForm">
                    <div class="modal-body">
                        <input type="hidden" id="station_id" name="station_id">
                        <input type="hidden" id="action" name="action" value="create">
                        
                        <div class="mb-3">
                            <label for="station_name" class="form-label">Station Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="station_name" name="station_name" required>
                            <small class="text-muted">Enter a unique name for this scanning station</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="station_pin" class="form-label">Station PIN <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="station_pin" name="station_pin" required minlength="4" maxlength="8" pattern="[0-9]+">
                            <small class="text-muted">4-8 digit numeric PIN for station access</small>
                        </div>
                        
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Device Locking:</strong> When someone first logs in to this station, their device will be automatically registered and locked. Only that device will be able to access this station afterwards.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save me-1"></i>Save Station
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-modal="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this station?</p>
                    <div class="alert alert-warning">
                        <strong>Station:</strong> <span id="deleteStationName"></span>
                    </div>
                    <p class="text-danger mb-0">This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
    <link rel="stylesheet" href="<?php echo asset_url('vendor/sweetalert2.min.css', true); ?>">
    <script src="<?php echo asset_url('vendor/sweetalert2.min.js', true); ?>"></script>
    <script>
        let stationModalInstance;
        let deleteModalInstance;
        let stationToDelete = null;

        document.addEventListener('DOMContentLoaded', function() {
            stationModalInstance = new bootstrap.Modal(document.getElementById('stationModal'));
            deleteModalInstance = new bootstrap.Modal(document.getElementById('deleteModal'));
            
            // Handle form submission
            document.getElementById('stationForm').addEventListener('submit', function(e) {
                e.preventDefault();
                saveStation();
            });
            
            // Handle delete confirmation
            document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
                if (stationToDelete) {
                    performDelete(stationToDelete);
                }
            });

        });

        function showAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Station';
            document.getElementById('stationForm').reset();
            document.getElementById('action').value = 'create';
            document.getElementById('station_id').value = '';
            document.getElementById('station_pin').setAttribute('required', 'required');
            stationModalInstance.show();
        }

        function editStation(station) {
            document.getElementById('modalTitle').textContent = 'Edit Station';
            document.getElementById('action').value = 'update';
            document.getElementById('station_id').value = station.id;
            document.getElementById('station_name').value = station.name;
            // Clear PIN field for edit (optional change)
            document.getElementById('station_pin').value = '';
            document.getElementById('station_pin').removeAttribute('required');
            stationModalInstance.show();
        }

        function saveStation() {
            const formData = new FormData(document.getElementById('stationForm'));
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
            
            fetch('station_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        throw new Error('Server returned non-JSON response. This may indicate a PHP error.');
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    stationModalInstance.hide();
                    showSuccess(data.message);
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showError(data.message);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('An error occurred while saving the station. Please check the console for details.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        }

        function deleteStation(id, name) {
            stationToDelete = id;
            document.getElementById('deleteStationName').textContent = name;
            deleteModalInstance.show();
        }

        function performDelete(stationId) {
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            const originalText = confirmBtn.innerHTML;
            
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting...';
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('station_id', stationId);
            
            fetch('station_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        throw new Error('Server returned non-JSON response. This may indicate a PHP error.');
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    deleteModalInstance.hide();
                    showSuccess(data.message);
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showError(data.message);
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('An error occurred while deleting the station. Please check the console for details.');
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = originalText;
            });
        }

        function exportStations() {
            window.location.href = 'station_api.php?action=export';
        }

        function resetDevice(stationId, stationName) {
            // Check if SweetAlert2 is loaded
            if (typeof Swal === 'undefined') {
                // Fallback to confirm dialog if SweetAlert2 is not available
                if (confirm(`Are you sure you want to reset device binding for ${stationName}?\n\nThis will allow a new device to register when someone logs in next time.`)) {
                    performResetDevice(stationId, stationName);
                }
                return;
            }
            
            Swal.fire({
                title: 'Reset Device Binding?',
                html: `Are you sure you want to reset device binding for <strong>${stationName}</strong>?<br><br>
                       This will allow a new device to register when someone logs in next time.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Reset Device',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    performResetDevice(stationId, stationName);
                }
            });
        }

        function performResetDevice(stationId, stationName) {
            const formData = new FormData();
            formData.append('action', 'reset_device');
            formData.append('station_id', stationId);
            
            fetch('station_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        throw new Error('Server returned non-JSON response. This may indicate a PHP error.');
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Device Reset',
                            text: `Device binding reset for ${stationName}. Next login will register a new device.`,
                            confirmButtonColor: '#198754'
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        alert(`Device binding reset for ${stationName}. Next login will register a new device.`);
                        window.location.reload();
                    }
                } else {
                    showError(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showError('An error occurred while resetting device binding. Please check the console for details.');
            });
        }

        function showSuccess(message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show';
            alertDiv.innerHTML = `
                <i class="fas fa-check-circle me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.querySelector('.main-content').insertBefore(
                alertDiv, 
                document.querySelector('.main-content').firstChild.nextSibling
            );
        }

        function showError(message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-danger alert-dismissible fade show';
            alertDiv.innerHTML = `
                <i class="fas fa-exclamation-circle me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.querySelector('.main-content').insertBefore(
                alertDiv, 
                document.querySelector('.main-content').firstChild.nextSibling
            );
        }
    </script>
</body>
</html>

