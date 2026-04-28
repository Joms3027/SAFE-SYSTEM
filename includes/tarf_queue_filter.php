<?php
/**
 * Client-side search/filter helpers for faculty TARF / NTARF endorsement queues.
 */

declare(strict_types=1);

/**
 * @param array<string, mixed> $row DB row with form_data, first_name, last_name, department, created_at
 * @return array{search_name: string, search_filed: string, search_dept: string, activity_start: string, activity_end: string}
 */
function tarf_queue_compute_row_search_data(array $row): array
{
    $fd = json_decode($row['form_data'] ?? '{}', true);
    $fd = is_array($fd) ? $fd : [];

    $name = strtolower(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
    $dept = strtolower(trim((string) ($row['department'] ?? '')));
    $co = trim((string) ($fd['college_office_project'] ?? $fd['college_office'] ?? ''));
    if ($co !== '') {
        $dept = trim($dept . ' ' . strtolower($co));
    }
    $dept = preg_replace('/\s+/', ' ', $dept) ?? $dept;

    $createdRaw = $row['created_at'] ?? '';
    $filedTs = $createdRaw !== '' ? strtotime($createdRaw) : false;
    $filedYmd = $filedTs ? date('Y-m-d', $filedTs) : '';

    $actStart = '';
    $actEnd = '';
    if (($fd['form_kind'] ?? '') === 'ntarf') {
        $ds = trim((string) ($fd['date_activity_start'] ?? ''));
        $de = trim((string) ($fd['date_activity_end'] ?? ''));
        if ($de === '') {
            $de = $ds;
        }
        $tsS = $ds !== '' ? strtotime($ds) : false;
        $tsE = $de !== '' ? strtotime($de) : false;
        if ($tsS) {
            $actStart = date('Y-m-d', $tsS);
        }
        if ($tsE) {
            $actEnd = date('Y-m-d', $tsE);
        } elseif ($tsS) {
            $actEnd = $actStart;
        }
    } else {
        $ds = trim((string) ($fd['date_departure'] ?? ''));
        $de = trim((string) ($fd['date_return'] ?? ''));
        if ($de === '') {
            $de = $ds;
        }
        $tsS = $ds !== '' ? strtotime($ds) : false;
        $tsE = $de !== '' ? strtotime($de) : false;
        if ($tsS) {
            $actStart = date('Y-m-d', $tsS);
        }
        if ($tsE) {
            $actEnd = date('Y-m-d', $tsE);
        } elseif ($tsS) {
            $actEnd = $actStart;
        }
    }
    if ($actStart !== '' && $actEnd !== '' && $actEnd < $actStart) {
        $t = $actStart;
        $actStart = $actEnd;
        $actEnd = $t;
    }

    return [
        'search_name' => $name,
        'search_filed' => $filedYmd,
        'search_dept' => $dept,
        'activity_start' => $actStart,
        'activity_end' => $actEnd,
    ];
}

/**
 * HTML data-* attributes for a queue table row (for tarf_queue_filter_script).
 */
function tarf_queue_row_data_attrs(array $row): string
{
    $d = tarf_queue_compute_row_search_data($row);
    return sprintf(
        ' data-tarf-queue-row="1" data-search-name="%s" data-search-filed="%s" data-search-dept="%s" data-activity-start="%s" data-activity-end="%s"',
        htmlspecialchars($d['search_name'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($d['search_filed'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($d['search_dept'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($d['activity_start'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($d['activity_end'], ENT_QUOTES, 'UTF-8')
    );
}

function tarf_queue_filter_bar(): void
{
    ?>
<div class="card-body border-bottom py-3 tarf-queue-filter-section">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-sm-6 col-xl-3">
                <label class="form-label small text-muted mb-0" for="tarfQueueFilterName">Requester name</label>
                <input type="text" class="form-control form-control-sm tarf-queue-filter-name" id="tarfQueueFilterName" placeholder="Contains…" autocomplete="name">
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <label class="form-label small text-muted mb-0" for="tarfQueueFilterFiled">Date filed</label>
                <input type="date" class="form-control form-control-sm tarf-queue-filter-filed" id="tarfQueueFilterFiled">
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <label class="form-label small text-muted mb-0" for="tarfQueueFilterDept">College / department</label>
                <input type="text" class="form-control form-control-sm tarf-queue-filter-dept" id="tarfQueueFilterDept" placeholder="Contains…" autocomplete="organization">
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <label class="form-label small text-muted mb-0" for="tarfQueueFilterActivity">Activity date</label>
                <input type="date" class="form-control form-control-sm tarf-queue-filter-activity" id="tarfQueueFilterActivity" title="Falls within travel or on-site activity range">
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
            <button type="button" class="btn btn-sm btn-outline-secondary tarf-queue-filter-clear" id="tarfQueueFilterClear">Clear filters</button>
            <span class="small text-muted tarf-queue-filter-count ms-sm-auto" id="tarfQueueFilterCount" aria-live="polite"></span>
        </div>
</div>
    <?php
}

function tarf_queue_filter_script(): void
{
    ?>
<script>
(function() {
    function applyTarfQueueFilters() {
        var nameEl = document.getElementById('tarfQueueFilterName');
        var filedEl = document.getElementById('tarfQueueFilterFiled');
        var deptEl = document.getElementById('tarfQueueFilterDept');
        var actEl = document.getElementById('tarfQueueFilterActivity');
        var countEl = document.getElementById('tarfQueueFilterCount');
        if (!nameEl || !filedEl || !deptEl || !actEl) return;

        var nameQ = (nameEl.value || '').trim().toLowerCase();
        var filedQ = (filedEl.value || '').trim();
        var deptQ = (deptEl.value || '').trim().toLowerCase();
        var actQ = (actEl.value || '').trim();

        var rows = document.querySelectorAll('tr[data-tarf-queue-row]');
        var n = 0;
        rows.forEach(function(tr) {
            var ok = true;
            var sn = tr.getAttribute('data-search-name') || '';
            var sf = tr.getAttribute('data-search-filed') || '';
            var sd = tr.getAttribute('data-search-dept') || '';
            var as = tr.getAttribute('data-activity-start') || '';
            var ae = tr.getAttribute('data-activity-end') || '';

            if (nameQ && sn.indexOf(nameQ) === -1) ok = false;
            if (filedQ && sf !== filedQ) ok = false;
            if (deptQ && sd.indexOf(deptQ) === -1) ok = false;
            if (actQ) {
                if (!as || !ae) ok = false;
                else if (actQ < as || actQ > ae) ok = false;
            }
            tr.classList.toggle('d-none', !ok);
            if (ok) n++;
        });
        if (countEl) {
            countEl.textContent = rows.length ? ('Showing ' + n + ' of ' + rows.length) : '';
        }
    }

    ['tarfQueueFilterName', 'tarfQueueFilterDept'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', applyTarfQueueFilters);
    });
    ['tarfQueueFilterFiled', 'tarfQueueFilterActivity'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', applyTarfQueueFilters);
    });
    var clearBtn = document.getElementById('tarfQueueFilterClear');
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            var a = document.getElementById('tarfQueueFilterName');
            var b = document.getElementById('tarfQueueFilterFiled');
            var c = document.getElementById('tarfQueueFilterDept');
            var d = document.getElementById('tarfQueueFilterActivity');
            if (a) a.value = '';
            if (b) b.value = '';
            if (c) c.value = '';
            if (d) d.value = '';
            applyTarfQueueFilters();
        });
    }
    applyTarfQueueFilters();
})();
</script>
    <?php
}
