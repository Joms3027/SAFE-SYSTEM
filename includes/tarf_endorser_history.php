<?php
/**
 * Past endorsements / rejections for TARF workflow actors (supervisor, endorser, fund, president).
 */

declare(strict_types=1);

/**
 * @param ''|'supervisor'|'endorser'|'fund_availability'|'president' $role
 */
function tarf_endorser_history_user_may_access(PDO $db, int $userId, string $role): bool
{
    if (!in_array($role, ['supervisor', 'endorser', 'fund_availability', 'president'], true)) {
        return false;
    }
    if ($role === 'supervisor') {
        return function_exists('hasPardonOpenerAssignments') && hasPardonOpenerAssignments($userId, $db);
    }
    if ($role === 'endorser') {
        return function_exists('tarf_is_endorser_target_user') && tarf_is_endorser_target_user($userId, $db);
    }
    if ($role === 'fund_availability') {
        return function_exists('tarf_user_holds_fund_availability_designation')
            && tarf_user_holds_fund_availability_designation($userId, $db);
    }
    if ($role === 'president') {
        return function_exists('tarf_is_president_key_official_viewer')
            && tarf_is_president_key_official_viewer($userId);
    }

    return false;
}

/**
 * Human-readable final workflow status for history table.
 */
function tarf_endorser_history_final_status_label(string $status): string
{
    $s = $status === 'pending' ? 'pending_supervisor' : $status;
    switch ($s) {
        case 'pending_joint':
            return 'Awaiting endorsements';
        case 'pending_supervisor':
            return 'Awaiting supervisor';
        case 'pending_endorser':
            return 'Awaiting applicable endorser';
        case 'pending_president':
            return 'Awaiting President (final)';
        case 'endorsed':
            return 'Approved (final)';
        case 'rejected':
            return 'Rejected';
        default:
            return $status !== '' ? $status : '—';
    }
}

/**
 * @param array<string, mixed> $r
 * @param ''|'supervisor'|'endorser'|'fund_availability'|'president' $role
 * @return array<string, mixed>|null
 */
function tarf_endorser_history_row_payload(array $r, string $role, int $viewerId, string $basePath): ?array
{
    $rejBy = (int) ($r['rejected_by'] ?? 0);
    $rejStage = (string) ($r['rejection_stage'] ?? '');
    $wasReject = $rejBy === $viewerId && $rejStage === $role;

    $wasEndorse = false;
    $endorseAt = '';
    switch ($role) {
        case 'supervisor':
            $wasEndorse = ((int) ($r['supervisor_endorsed_by'] ?? 0)) === $viewerId
                && !empty($r['supervisor_endorsed_at']);
            $endorseAt = (string) ($r['supervisor_endorsed_at'] ?? '');
            break;
        case 'endorser':
            $wasEndorse = ((int) ($r['endorser_endorsed_by'] ?? 0)) === $viewerId
                && !empty($r['endorser_endorsed_at']);
            $endorseAt = (string) ($r['endorser_endorsed_at'] ?? '');
            break;
        case 'fund_availability':
            $wasEndorse = ((int) ($r['fund_availability_endorsed_by'] ?? 0)) === $viewerId
                && !empty($r['fund_availability_endorsed_at']);
            $endorseAt = (string) ($r['fund_availability_endorsed_at'] ?? '');
            break;
        case 'president':
            $wasEndorse = ((int) ($r['president_endorsed_by'] ?? 0)) === $viewerId
                && !empty($r['president_endorsed_at']);
            $endorseAt = (string) ($r['president_endorsed_at'] ?? '');
            break;
        default:
            return null;
    }

    if ($wasReject && $wasEndorse) {
        $wasEndorse = false;
    }
    if (!$wasReject && !$wasEndorse) {
        return null;
    }

    $action = $wasReject ? 'rejected' : 'endorsed';
    $actionRaw = $wasReject ? (string) ($r['rejected_at'] ?? '') : $endorseAt;
    $actionTs = $actionRaw !== '' ? strtotime($actionRaw) : false;
    $actionIso = $actionTs ? date('c', $actionTs) : '';

    $fd = json_decode($r['form_data'] ?? '{}', true);
    $fd = is_array($fd) ? $fd : [];
    $isNtarf = (($fd['form_kind'] ?? '') === 'ntarf');
    $kindLabel = $isNtarf ? 'NTARF' : 'TARF';
    $summary = $isNtarf
        ? trim((string) ($fd['activity_requested'] ?? ''))
        : trim((string) ($fd['event_purpose'] ?? ''));
    if (function_exists('mb_strimwidth')) {
        $summary = $summary !== '' ? mb_strimwidth($summary, 0, 90, '…', 'UTF-8') : '—';
    } else {
        $summary = $summary !== '' ? (strlen($summary) > 90 ? substr($summary, 0, 87) . '...' : $summary) : '—';
    }

    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $st = (string) ($r['status'] ?? '');
    $viewUrl = function_exists('clean_url')
        ? clean_url($basePath . '/faculty/tarf_request_view.php?id=' . (int) $r['id'], $basePath)
        : ($basePath . '/faculty/tarf_request_view.php?id=' . (int) $r['id']);

    return [
        'id' => (int) $r['id'],
        'serial_year' => (int) ($r['serial_year'] ?? 0),
        'form_kind' => $kindLabel,
        'requester_name' => $name,
        'department' => trim((string) ($r['department'] ?? '')),
        'my_action' => $action,
        'my_action_label' => $wasReject ? 'Rejected' : 'Endorsed',
        'action_at' => $actionIso,
        'action_at_display' => $actionTs ? date('M j, Y g:i A', $actionTs) : '—',
        'final_status' => $st,
        'final_status_label' => tarf_endorser_history_final_status_label($st),
        'summary' => $summary,
        'rejection_reason' => $wasReject ? trim((string) ($r['rejection_reason'] ?? '')) : '',
        'view_url' => $viewUrl,
    ];
}

/**
 * @param ''|'supervisor'|'endorser'|'fund_availability'|'president' $role
 * @return list<array<string, mixed>>
 */
function tarf_endorser_history_fetch(PDO $db, int $viewerId, string $role, string $basePath, int $limit = 250): array
{
    if (!in_array($role, ['supervisor', 'endorser', 'fund_availability', 'president'], true)) {
        return [];
    }

    $stage = $role;
    $where = '';
    switch ($role) {
        case 'supervisor':
            $where = '(tr.supervisor_endorsed_by = ? AND tr.supervisor_endorsed_at IS NOT NULL)
                OR (tr.rejected_by = ? AND tr.rejection_stage = ?)';
            break;
        case 'endorser':
            $where = '(tr.endorser_endorsed_by = ? AND tr.endorser_endorsed_at IS NOT NULL)
                OR (tr.rejected_by = ? AND tr.rejection_stage = ?)';
            break;
        case 'fund_availability':
            $where = '(tr.fund_availability_endorsed_by = ? AND tr.fund_availability_endorsed_at IS NOT NULL)
                OR (tr.rejected_by = ? AND tr.rejection_stage = ?)';
            break;
        case 'president':
            $where = '(tr.president_endorsed_by = ? AND tr.president_endorsed_at IS NOT NULL)
                OR (tr.rejected_by = ? AND tr.rejection_stage = ?)';
            break;
        default:
            return [];
    }

    $sql = "SELECT tr.*, u.first_name, u.last_name, fp.department
            FROM tarf_requests tr
            INNER JOIN users u ON u.id = tr.user_id
            LEFT JOIN faculty_profiles fp ON fp.user_id = tr.user_id
            WHERE $where";

    try {
        $st = $db->prepare($sql);
        $st->execute([$viewerId, $viewerId, $stage]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $p = tarf_endorser_history_row_payload($r, $role, $viewerId, $basePath);
        if ($p !== null) {
            $out[] = $p;
        }
    }

    usort(
        $out,
        static function (array $a, array $b): int {
            return strcmp((string) ($b['action_at'] ?? ''), (string) ($a['action_at'] ?? ''));
        }
    );

    if (count($out) > $limit) {
        $out = array_slice($out, 0, $limit);
    }

    return $out;
}

/**
 * Fixed top-right History button + modal + loader script (call once per queue page).
 *
 * @param ''|'supervisor'|'endorser'|'fund_availability'|'president' $workflowRole
 */
function tarf_endorser_history_render_fixed_button_and_modal(string $basePath, string $workflowRole): void
{
    if (!in_array($workflowRole, ['supervisor', 'endorser', 'fund_availability', 'president'], true)) {
        return;
    }
    $apiUrl = clean_url($basePath . '/faculty/tarf_endorser_history_api.php', $basePath);
    ?>
<style>
.tarf-endorser-history-fab {
    position: fixed;
    top: 4.75rem;
    right: 12px;
    z-index: 1020;
}
@media (min-width: 992px) {
    .tarf-endorser-history-fab { right: 20px; }
}
.tarf-endorser-history-modal .table { font-size: 0.875rem; }
.tarf-endorser-history-modal .table td { vertical-align: middle; }
</style>
<button type="button" class="btn btn-outline-primary btn-sm shadow-sm tarf-endorser-history-fab d-flex align-items-center gap-1"
        id="tarfEndorserHistoryOpen"
        data-bs-toggle="modal" data-bs-target="#tarfEndorserHistoryModal"
        title="Requests you endorsed or rejected">
    <i class="fas fa-history" aria-hidden="true"></i>
    <span>History</span>
</button>

<div class="modal fade tarf-endorser-history-modal" id="tarfEndorserHistoryModal" tabindex="-1" aria-labelledby="tarfEndorserHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tarfEndorserHistoryModalLabel"><i class="fas fa-history me-2 text-primary" aria-hidden="true"></i>Your TARF / NTARF history</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="tarfEndorserHistoryBody">
                <div class="text-center text-muted py-5 px-3">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Loading…
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var apiUrl = <?php echo json_encode($apiUrl, JSON_UNESCAPED_SLASHES); ?>;
    var role = <?php echo json_encode($workflowRole); ?>;
    var modalEl = document.getElementById('tarfEndorserHistoryModal');
    var bodyEl = document.getElementById('tarfEndorserHistoryBody');
    if (!modalEl || !bodyEl) return;

    function esc(s) {
        if (s == null) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function render(data) {
        if (!data.success) {
            bodyEl.innerHTML = '<div class="alert alert-danger m-3 mb-0">' + esc(data.message || 'Could not load history.') + '</div>';
            return;
        }
        var rows = data.rows || [];
        if (rows.length === 0) {
            bodyEl.innerHTML = '<div class="text-center text-muted py-5 px-3">No requests you have endorsed or rejected yet.</div>';
            return;
        }
        var html = '<div class="table-responsive"><table class="table table-hover table-striped mb-0 align-middle">'
            + '<thead class="table-light"><tr>'
            + '<th scope="col">ID</th><th scope="col">Type</th><th scope="col">Requester</th><th scope="col">Dept.</th>'
            + '<th scope="col">Your action</th><th scope="col">When</th><th scope="col">Final status</th><th scope="col">Summary</th><th scope="col"></th>'
            + '</tr></thead><tbody>';
        rows.forEach(function(r) {
            var badge = r.my_action === 'rejected'
                ? '<span class="badge bg-danger">Rejected</span>'
                : '<span class="badge bg-success">Endorsed</span>';
            var reason = (r.my_action === 'rejected' && r.rejection_reason)
                ? '<div class="small text-muted mt-1">' + esc(r.rejection_reason) + '</div>' : '';
            html += '<tr>'
                + '<td>' + esc(r.id) + '</td>'
                + '<td>' + esc(r.form_kind) + '</td>'
                + '<td>' + esc(r.requester_name) + '</td>'
                + '<td>' + esc(r.department || '—') + '</td>'
                + '<td>' + badge + reason + '</td>'
                + '<td>' + esc(r.action_at_display) + '</td>'
                + '<td>' + esc(r.final_status_label) + '</td>'
                + '<td><span class="d-inline-block text-truncate" style="max-width:14rem" title="' + esc(r.summary) + '">' + esc(r.summary) + '</span></td>'
                + '<td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="' + esc(r.view_url) + '" target="_blank" rel="noopener">View</a></td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        bodyEl.innerHTML = html;
    }

    modalEl.addEventListener('show.bs.modal', function() {
        bodyEl.innerHTML = '<div class="text-center text-muted py-5 px-3"><span class="spinner-border spinner-border-sm me-2" role="status"></span>Loading…</div>';
        fetch(apiUrl + '?role=' + encodeURIComponent(role), { credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(render)
            .catch(function() {
                bodyEl.innerHTML = '<div class="alert alert-danger m-3 mb-0">Network error. Try again.</div>';
            });
    });
    modalEl.addEventListener('hidden.bs.modal', function() {
        bodyEl.innerHTML = '';
    });
})();
</script>
    <?php
}
