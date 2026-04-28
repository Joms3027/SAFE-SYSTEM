<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();
$basePath = getBasePath();
$formAction = clean_url($basePath . '/faculty/feedback.php', $basePath);

$departments = [];
try {
    $departments = $db->query('SELECT id, name FROM departments ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('faculty/feedback.php departments: ' . $e->getMessage());
}

$defaultName = trim((string)($_SESSION['user_name'] ?? ''));
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateFormToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please refresh the page and try again.';
    } else {
        $deptId = isset($_POST['department_id']) ? (int)$_POST['department_id'] : 0;
        $rating = isset($_POST['satisfaction_rating']) ? (int)$_POST['satisfaction_rating'] : 0;
        $nameRaw = trim((string)($_POST['submitter_name'] ?? ''));
        $name = $nameRaw === '' ? null : sanitizeInput($nameRaw);
        $messageRaw = trim((string)($_POST['message'] ?? ''));
        if ($deptId <= 0) {
            $error = 'Please select a department.';
        } elseif ($rating < 1 || $rating > 5) {
            $error = 'Please choose a satisfaction rating from 1 to 5.';
        } elseif ($messageRaw === '') {
            $error = 'Please enter your feedback message.';
        } elseif (mb_strlen($messageRaw) > 10000) {
            $error = 'Message is too long (maximum 10,000 characters).';
        } else {
            $check = $db->prepare('SELECT id FROM departments WHERE id = ? LIMIT 1');
            $check->execute([$deptId]);
            if (!$check->fetch()) {
                $error = 'Invalid department selected.';
            } else {
                $message = sanitizeInput($messageRaw);
                try {
                    $ins = $db->prepare(
                        'INSERT INTO employee_feedback (submitter_name, department_id, satisfaction_rating, message) VALUES (?, ?, ?, ?)'
                    );
                    $ins->execute([$name, $deptId, $rating, $message]);
                    logAction('EMPLOYEE_FEEDBACK_SUBMIT', 'Feedback submitted (department_id=' . $deptId . ', rating=' . $rating . ')');
                    $success = 'Thank you. Your feedback has been submitted.';
                } catch (Exception $e) {
                    error_log('employee_feedback insert: ' . $e->getMessage());
                    if (strpos($e->getMessage(), 'employee_feedback') !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
                        $error = 'Feedback is not available yet. Please contact the administrator.';
                    } else {
                        $error = 'Could not save your feedback. Please try again later.';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#003366">
    <title>Employee Feedback - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile-button-fix.css', true); ?>" rel="stylesheet">
    <style>
        .rating-scale { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .rating-scale .btn-check:checked + .btn { background-color: var(--bs-primary); color: #fff; border-color: var(--bs-primary); }
    </style>
</head>
<body class="layout-faculty">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>

    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header">
                    <div class="page-title">
                        <i class="fas fa-comment-dots"></i>
                        <span>Employee feedback</span>
                    </div>
                    <p class="page-subtitle">Share feedback about faculty and staff. Your department selection helps route your message.</p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible show shadow-sm mb-3" role="alert">
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible show shadow-sm mb-3" role="alert">
                        <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row justify-content-center">
                    <div class="col-lg-7 col-md-9">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-pen me-2"></i>Submit feedback
                            </div>
                            <div class="card-body">
                                <?php if (empty($departments)): ?>
                                    <p class="text-danger mb-0">No departments are configured yet. Please try again later.</p>
                                <?php elseif ($success === ''): ?>
                                    <form method="post" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php addFormToken(); ?>
                                        <div class="mb-3">
                                            <label for="submitter_name" class="form-label">Your name</label>
                                            <input type="text" class="form-control" id="submitter_name" name="submitter_name" maxlength="255" autocomplete="name"
                                                   placeholder="Optional"
                                                  >
                                            <div class="form-text">Leave blank to submit anonymously.</div>
                                        </div>
                                        <div class="mb-3">
                                            <span class="form-label d-block">Satisfaction rating <span class="text-danger">*</span></span>
                                            <div class="form-text mb-2">1 = very dissatisfied, 5 = very satisfied.</div>
                                            <div class="rating-scale" role="group" aria-label="Satisfaction 1 to 5">
                                                <?php
                                                $postRating = isset($_POST['satisfaction_rating']) ? (int)$_POST['satisfaction_rating'] : 0;
                                                for ($i = 1; $i <= 5; $i++) {
                                                    $rid = 'rating_' . $i;
                                                    $checked = $postRating === $i ? ' checked' : '';
                                                    echo '<input type="radio" class="btn-check" name="satisfaction_rating" id="' . htmlspecialchars($rid, ENT_QUOTES, 'UTF-8') . '" value="' . $i . '" required' . $checked . '>';
                                                    echo '<label class="btn btn-outline-primary px-3" for="' . htmlspecialchars($rid, ENT_QUOTES, 'UTF-8') . '">' . $i . '</label>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="department_id" class="form-label">Department <span class="text-danger">*</span></label>
                                            <select class="form-select" id="department_id" name="department_id" required>
                                                <option value="">— Select department —</option>
                                                <?php
                                                $sel = isset($_POST['department_id']) ? (string)(int)$_POST['department_id'] : '';
                                                foreach ($departments as $d) {
                                                    $id = (string)$d['id'];
                                                    $selAttr = ($sel !== '' && $sel === $id) ? ' selected' : '';
                                                    echo '<option value="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . $selAttr . '>'
                                                        . htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8') . '</option>';
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label for="message" class="form-label">Feedback <span class="text-danger">*</span></label>
                                            <textarea class="form-control" id="message" name="message" rows="6" required maxlength="10000" placeholder="Your message"><?php
                                                echo isset($_POST['message']) ? htmlspecialchars((string)$_POST['message'], ENT_QUOTES, 'UTF-8') : '';
                                            ?></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-paper-plane me-1"></i> Submit feedback
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <a href="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary">Submit another</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/mobile.js', true); ?>"></script>
</body>
</html>
