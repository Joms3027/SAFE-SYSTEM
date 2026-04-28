<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!validateFormToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid form submission. Please try again.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $del = $db->prepare('DELETE FROM employee_feedback WHERE id = ?');
                $del->execute([$id]);
                logAction('FEEDBACK_DELETE', 'Deleted employee feedback id ' . $id);
                $_SESSION['success'] = 'Feedback entry removed.';
            } catch (Exception $e) {
                error_log('feedback delete: ' . $e->getMessage());
                $_SESSION['error'] = 'Could not delete that entry.';
            }
        }
    }
    header('Location: feedback.php');
    exit;
}

$tableMissing = false;
$items = [];
$total = 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

try {
    $total = (int)$db->query('SELECT COUNT(*) FROM employee_feedback')->fetchColumn();
    $perPageSafe = max(1, min(100, $perPage));
    $offsetSafe = max(0, $offset);
    $sql = "
        SELECT f.id, f.submitter_name, f.message, f.created_at, f.satisfaction_rating, d.name AS department_name
        FROM employee_feedback f
        INNER JOIN departments d ON d.id = f.department_id
        ORDER BY f.created_at DESC
        LIMIT {$perPageSafe} OFFSET {$offsetSafe}
    ";
    $items = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'employee_feedback') !== false && (stripos($msg, "doesn't exist") !== false || stripos($msg, 'Unknown table') !== false)) {
        $tableMissing = true;
    } elseif (stripos($msg, 'satisfaction_rating') !== false && stripos($msg, 'Unknown column') !== false) {
        $_SESSION['error'] = 'Database is missing column satisfaction_rating. Run db/migrations/20260416_add_satisfaction_rating_employee_feedback.sql';
    } else {
        error_log('admin feedback list: ' . $msg);
        $_SESSION['error'] = 'Could not load feedback.';
    }
}

$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

$feedbackDetailsById = [];
foreach ($items as $row) {
    $feedbackDetailsById[(string)(int)$row['id']] = [
        'created_at' => date('M j, Y g:i A', strtotime($row['created_at'])),
        'submitter_name' => $row['submitter_name'] ?? '',
        'department_name' => $row['department_name'] ?? '',
        'satisfaction_rating' => isset($row['satisfaction_rating']) ? (int)$row['satisfaction_rating'] : 0,
        'message' => $row['message'] ?? '',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Employee feedback', 'Submitted feedback from the faculty and staff portal');
    ?>
</head>
<body class="layout-admin">
    <?php
    require_once '../includes/navigation.php';
    include_navigation();
    ?>

    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'Employee feedback',
                    'Messages submitted by employees from their account (name is optional).',
                    'fas fa-comment-dots',
                    [],
                    ''
                );
                ?>

                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars((string)$_SESSION['success'], ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                <?php if (!empty($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars((string)$_SESSION['error'], ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h5 class="mb-0">All submissions</h5>
                        <?php if (!$tableMissing): ?>
                            <span class="text-muted small"><?php echo number_format($total); ?> total</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($tableMissing): ?>
                            <p class="text-danger mb-0">
                                The feedback table is not installed yet. Run the migration
                                <code>db/migrations/20260415_create_employee_feedback_table.sql</code> on your database.
                            </p>
                        <?php elseif (empty($items)): ?>
                            <p class="text-muted mb-0">No feedback has been submitted yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Name</th>
                                            <th>Department</th>
                                            <th class="text-center">Rating</th>
                                            <th class="text-end text-nowrap" style="min-width: 7rem;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $row): ?>
                                            <tr>
                                                <td class="text-nowrap small">
                                                    <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8'); ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $sn = $row['submitter_name'];
                                                    echo $sn !== null && $sn !== ''
                                                        ? htmlspecialchars($sn, ENT_QUOTES, 'UTF-8')
                                                        : '<span class="text-muted">Anonymous</span>';
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($row['department_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-center text-nowrap" title="Satisfaction 1–5">
                                                    <?php
                                                    $r = isset($row['satisfaction_rating']) ? (int)$row['satisfaction_rating'] : 0;
                                                    if ($r >= 1 && $r <= 5) {
                                                        echo '<span class="fw-semibold">' . $r . '</span>/5 ';
                                                        echo str_repeat('<i class="fas fa-star text-warning" style="font-size:0.75rem"></i>', $r);
                                                    } else {
                                                        echo '<span class="text-muted">—</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1 align-items-center justify-content-end flex-nowrap" role="group" aria-label="Actions">
                                                        <button type="button" class="btn btn-sm btn-outline-primary" title="View details" data-bs-toggle="modal" data-bs-target="#feedbackDetailModal" data-feedback-id="<?php echo (int)$row['id']; ?>">
                                                            <i class="fas fa-eye" aria-hidden="true"></i><span class="d-none d-md-inline ms-1">View</span>
                                                        </button>
                                                        <form method="post" class="d-inline m-0" onsubmit="return confirm('Delete this feedback entry?');">
                                                            <?php addFormToken(); ?>
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                                <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalPages > 1): ?>
                                <nav class="mt-3 d-flex align-items-center gap-3 flex-wrap" aria-label="Feedback pagination">
                                    <?php if ($page > 1): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="feedback.php?page=<?php echo $page - 1; ?>">Previous</a>
                                    <?php endif; ?>
                                    <span class="text-muted small">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                                    <?php if ($page < $totalPages): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="feedback.php?page=<?php echo $page + 1; ?>">Next</a>
                                    <?php endif; ?>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="feedbackDetailModal" tabindex="-1" aria-labelledby="feedbackDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="feedbackDetailModalLabel"><i class="fas fa-comment-dots me-2"></i>Feedback details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-4 text-muted">Date</dt>
                        <dd class="col-sm-8" id="fb-detail-date">—</dd>
                        <dt class="col-sm-4 text-muted">Name</dt>
                        <dd class="col-sm-8" id="fb-detail-name">—</dd>
                        <dt class="col-sm-4 text-muted">Department</dt>
                        <dd class="col-sm-8" id="fb-detail-dept">—</dd>
                        <dt class="col-sm-4 text-muted">Rating</dt>
                        <dd class="col-sm-8" id="fb-detail-rating">—</dd>
                        <dt class="col-sm-4 text-muted align-self-start pt-1">Message</dt>
                        <dd class="col-sm-8"><pre class="mb-0 mt-0 p-2 bg-light rounded border small" style="white-space: pre-wrap; font-family: inherit;" id="fb-detail-message"></pre></dd>
                    </dl>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
    <script>
    (function () {
        var FEEDBACK_DETAILS = <?php echo json_encode($feedbackDetailsById, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE); ?>;
        var modal = document.getElementById('feedbackDetailModal');
        if (!modal) return;
        modal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn || !btn.getAttribute) return;
            var id = btn.getAttribute('data-feedback-id');
            var d = id ? FEEDBACK_DETAILS[id] : null;
            var setText = function (elId, text) {
                var el = document.getElementById(elId);
                if (el) el.textContent = text != null ? String(text) : '';
            };
            if (!d) {
                setText('fb-detail-date', '—');
                setText('fb-detail-name', '—');
                setText('fb-detail-dept', '—');
                setText('fb-detail-rating', '—');
                setText('fb-detail-message', '');
                return;
            }
            setText('fb-detail-date', d.created_at || '—');
            setText('fb-detail-name', (d.submitter_name && String(d.submitter_name).trim() !== '') ? d.submitter_name : 'Anonymous');
            setText('fb-detail-dept', d.department_name || '—');
            var r = parseInt(d.satisfaction_rating, 10);
            if (r >= 1 && r <= 5) {
                setText('fb-detail-rating', r + ' / 5');
            } else {
                setText('fb-detail-rating', '—');
            }
            setText('fb-detail-message', d.message || '');
        });
    })();
    </script>
</body>
</html>
