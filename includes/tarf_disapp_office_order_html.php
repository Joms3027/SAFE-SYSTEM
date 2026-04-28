<?php
/**
 * OFFICE ORDER narrative blocks for DISAPP HTML (modal / print) — mirrors merged Word office-order sections.
 */
if (!function_exists('tarf_render_travel_office_order_section_html')) {
    /**
     * Travel (TARF): office-order block shown below the activity-request grid.
     */
    function tarf_render_travel_office_order_section_html(array $row, array $form): string
    {
        require_once __DIR__ . '/tarf_form_options.php';
        require_once __DIR__ . '/tarf_official_order_docx.php';

        if (!function_exists('tarf_disapp_escape')) {
            require_once __DIR__ . '/tarf_disapp_view_render.php';
        }

        $opts = tarf_get_form_options();
        $to = tarf_travel_format_to_recipients($form);
        $id = (int) ($row['id'] ?? 0);
        $year = (int) ($row['serial_year'] ?? 0);
        $noLine = 'No. ' . ($id > 0 ? (string) $id : '—') . ', s. ' . ($year > 0 ? (string) $year : '—');

        $purpose = trim((string) ($form['travel_purpose_type'] ?? ''));
        $trtKey = $form['travel_request_type'] ?? '';
        $trtLabel = isset($opts['travel_request_type'][$trtKey]) ? (string) $opts['travel_request_type'][$trtKey] : '';
        $typeTravel = $purpose !== '' || $trtLabel !== ''
            ? trim($purpose . ($purpose !== '' && $trtLabel !== '' ? ' — ' : '') . $trtLabel)
            : '—';

        $event = trim((string) ($form['event_purpose'] ?? ''));
        $dest = trim((string) ($form['destination'] ?? ''));
        $dep = trim((string) ($form['date_departure'] ?? ''));
        $ret = trim((string) ($form['date_return'] ?? ''));

        $e = static function (string $s): string {
            return tarf_disapp_escape($s);
        };

        $body = 'You are hereby <strong>' . $e('authorized') . '</strong> to undertake official travel';
        if ($event !== '') {
            $body .= ' for <strong>' . $e($event) . '</strong>';
        }
        if ($dest !== '') {
            $body .= ' at <strong>' . $e($dest) . '</strong>';
        }
        if ($dep !== '' || $ret !== '') {
            $body .= ', for the period <strong>' . $e($dep) . '</strong> to <strong>' . $e($ret) . '</strong>';
        }
        if ($typeTravel !== '' && $typeTravel !== '—') {
            $body .= ', under type of travel: <strong>' . $e($typeTravel) . '</strong>';
        }
        $body .= '.';

        $html = '<div class="tarf-oo-title">OFFICE ORDER FOR TRAVEL ACTIVITIES</div>'
            . '<div class="tarf-oo-no">' . $e($noLine) . '</div>'
            . '<p class="tarf-oo-to"><strong>TO:</strong> ' . $e($to) . '</p>'
            . '<p class="tarf-oo-body">' . $body . '</p>';

        return $html;
    }
}

if (!function_exists('tarf_render_ntarf_office_order_section_html')) {
    /**
     * Non-travel (NTARF): office-order block shown below the activity-request grid.
     */
    function tarf_render_ntarf_office_order_section_html(array $row, array $form): string
    {
        require_once __DIR__ . '/tarf_official_order_docx.php';

        if (!function_exists('tarf_disapp_escape')) {
            require_once __DIR__ . '/tarf_disapp_view_render.php';
        }

        $to = tarf_ntarf_format_to_recipients($form);
        $id = (int) ($row['id'] ?? 0);
        $year = (int) ($row['serial_year'] ?? 0);
        $noLine = 'No. ' . ($id > 0 ? (string) $id : '—') . ', s. ' . ($year > 0 ? (string) $year : '—');

        $act = trim((string) ($form['activity_requested'] ?? ''));
        $ds = trim((string) ($form['date_activity_start'] ?? ''));
        $de = trim((string) ($form['date_activity_end'] ?? ''));
        $ts = trim((string) ($form['time_activity_start'] ?? ''));
        $te = trim((string) ($form['time_activity_end'] ?? ''));
        $inv = trim((string) ($form['type_of_involvement'] ?? ''));

        $e = static function (string $s): string {
            return tarf_disapp_escape($s);
        };

        $body = 'You are hereby <strong>' . $e('authorized') . '</strong> to be involved in the ';
        if ($act !== '') {
            $body .= '<strong>' . $e($act) . '</strong>';
        } else {
            $body .= '<strong>' . $e('—') . '</strong>';
        }
        $body .= ' on <strong>' . $e($ds) . '</strong> TO <strong>' . $e($de) . '</strong>';
        if ($ts !== '' || $te !== '') {
            $body .= ' (<strong>' . $e($ts) . '</strong> — <strong>' . $e($te) . '</strong>)';
        }
        if ($inv !== '') {
            $body .= ' as <strong>' . $e($inv) . '</strong>';
        }
        $body .= '.';

        $html = '<div class="tarf-oo-title">OFFICE ORDER FOR NON-TRAVEL ACTIVITIES</div>'
            . '<div class="tarf-oo-no">' . $e($noLine) . '</div>'
            . '<p class="tarf-oo-to"><strong>TO:</strong> ' . $e($to) . '</p>'
            . '<p class="tarf-oo-body">' . $body . '</p>';

        return $html;
    }
}
