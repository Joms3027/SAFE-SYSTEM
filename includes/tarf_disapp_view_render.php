<?php
/**
 * DISAPP-style TARF card HTML (shared by full page and modal fragment).
 */
if (!function_exists('tarf_disapp_escape')) {
    function tarf_disapp_escape($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tarf_render_disapp_card_html')) {
    /**
     * @param array $row tarf_requests row
     */
    function tarf_render_disapp_card_html(PDO $db, array $row, int $viewerId): string
    {
        require_once __DIR__ . '/tarf_form_options.php';
        require_once __DIR__ . '/tarf_workflow.php';

        $form = json_decode($row['form_data'], true);
        if (!is_array($form)) {
            $form = [];
        }

        if (($form['form_kind'] ?? '') === 'ntarf') {
            require_once __DIR__ . '/ntarf_disapp_view_render.php';
            return ntarf_render_disapp_card_html($db, $row, $viewerId);
        }

        if (($form['form_kind'] ?? '') === 'tarf') {
            require_once __DIR__ . '/tarf_official_order_docx.php';
            $form = tarf_enrich_requester_form_for_docx($db, $row, $form);
        }

        $attachments = [];
        if (!empty($row['attachments'])) {
            $attachments = json_decode($row['attachments'], true);
            if (!is_array($attachments)) {
                $attachments = [];
            }
        }

        $opts = tarf_get_form_options();

        $cosLabel = ($form['cos_jo'] ?? '') === 'yes_certify'
            ? $opts['cos_jo_options']['yes_certify']
            : 'No';

        $typeTravel = trim(($form['travel_purpose_type'] ?? '') . ' — ' . ($opts['travel_request_type'][$form['travel_request_type'] ?? ''] ?? ''));

        $reqSupportLines = [];
        foreach ($form['publicity'] ?? [] as $k) {
            if (isset($opts['publicity_support'][$k])) {
                $reqSupportLines[] = $opts['publicity_support'][$k];
            }
        }
        if (!empty($form['publicity_other'])) {
            $reqSupportLines[] = 'Other: ' . $form['publicity_other'];
        }
        foreach ($form['support_travel'] ?? [] as $k) {
            if (isset($opts['travel_support'][$k])) {
                $reqSupportLines[] = $opts['travel_support'][$k];
            }
        }
        if (!empty($form['support_travel_other'])) {
            $reqSupportLines[] = 'Other: ' . $form['support_travel_other'];
        }
        $requestedSupportHtml = $reqSupportLines
            ? nl2br(tarf_disapp_escape(implode("\n", $reqSupportLines)))
            : '—';

        $fundLine = '—';
        if (!empty($form['funding_charged_to'])) {
            $spec = $form['funding_specifier'] ?? '';
            $fundLine = tarf_disapp_escape($form['funding_charged_to']);
            if ($spec !== '') {
                $fundLine .= ', ' . tarf_disapp_escape($spec);
            }
        }

        $fundCertDisplay = tarf_fund_availability_certifier_display_name($db, $row, $form);
        $fundCertLabel = $fundCertDisplay !== '' ? tarf_disapp_escape($fundCertDisplay) : '—';

        $totalAmt = isset($form['total_estimated_amount']) && $form['total_estimated_amount'] !== ''
            ? tarf_disapp_escape((string) $form['total_estimated_amount'])
            : '—';

        $filedTs = !empty($form['submitted_at_server'])
            ? date('F j, Y g:i A', strtotime($form['submitted_at_server']))
            : date('F j, Y g:i A', strtotime($row['created_at']));

        $status = $row['status'] ?? 'pending_supervisor';
        if ($status === 'pending') {
            $status = 'pending_supervisor';
        }
        $supName = '';
        if (!empty($row['supervisor_endorsed_by'])) {
            $supName = tarf_display_name_for_user((int) $row['supervisor_endorsed_by'], $db);
        }
        $endName = '';
        if (!empty($row['endorser_endorsed_by'])) {
            $endName = tarf_display_name_for_user((int) $row['endorser_endorsed_by'], $db);
        }
        $presName = '';
        if (!empty($row['president_endorsed_by'])) {
            $presName = tarf_display_name_for_user((int) $row['president_endorsed_by'], $db);
        }

        $fundAvailName = '';
        if (!empty($row['fund_availability_endorsed_by'])) {
            $fundAvailName = tarf_display_name_for_user((int) $row['fund_availability_endorsed_by'], $db);
        }

        $statusMain = 'Awaiting supervisor endorsement';
        $statusNotesEndorser = '—';
        $statusNotesFund = '—';
        $finalApprovalLine = 'Pending';

        if (in_array($status, ['pending_joint', 'pending_supervisor', 'pending_endorser'], true)) {
            $needFund = tarf_request_requires_fund_availability_endorsement($row);
            $statusMain = 'Parallel endorsements — supervisor, applicable endorser'
                . ($needFund ? ', Budget/Accounting fund' : '');
            $sb = [];
            if ($supName !== '') {
                $ln = 'Supervisor: ' . $supName;
                if (!empty($row['supervisor_endorsed_at'])) {
                    $ln .= ' (' . date('M j, Y g:i A', strtotime($row['supervisor_endorsed_at'])) . ')';
                } else {
                    $ln .= ' — pending';
                }
                if (!empty($row['supervisor_comment'])) {
                    $ln .= ' — ' . $row['supervisor_comment'];
                }
                $sb[] = $ln;
            } else {
                $sb[] = 'Supervisor: pending';
            }
            if ($endName !== '') {
                $ln = 'Applicable endorser: ' . $endName;
                if (!empty($row['endorser_endorsed_at'])) {
                    $ln .= ' (' . date('M j, Y g:i A', strtotime($row['endorser_endorsed_at'])) . ')';
                } else {
                    $ln .= ' — pending';
                }
                if (!empty($row['endorser_comment'])) {
                    $ln .= ' — ' . $row['endorser_comment'];
                }
                $sb[] = $ln;
            } else {
                $sb[] = 'Applicable endorser: pending';
            }
            $statusNotesEndorser = implode("\n", $sb);
            if ($needFund) {
                if ($fundAvailName !== '') {
                    $fn = 'Budget/Accounting fund: ' . $fundAvailName;
                    if (!empty($row['fund_availability_endorsed_at'])) {
                        $fn .= ' (' . date('M j, Y g:i A', strtotime($row['fund_availability_endorsed_at'])) . ')';
                    } else {
                        $fn .= ' — pending';
                    }
                    if (!empty($row['fund_availability_comment'])) {
                        $fn .= ' — ' . $row['fund_availability_comment'];
                    }
                    $statusNotesFund = $fn;
                } else {
                    $statusNotesFund = 'Budget/Accounting fund: pending';
                }
            } else {
                $statusNotesFund = 'Fund endorsement not required for this request.';
            }
        } elseif ($status === 'pending_president') {
            $finalApprovalLine = 'Awaiting President (key official)';
            $needFundPrez = tarf_request_requires_fund_availability_endorsement($row);
            $statusMain = $needFundPrez
                ? 'Supervisor, applicable endorser, and Budget/Accounting endorsements complete — final approval by President'
                : 'Supervisor and applicable endorser endorsements complete — final approval by President';
            if ($supName !== '') {
                $statusNotesEndorser = 'Supervisor: ' . $supName;
                if (!empty($row['supervisor_comment'])) {
                    $statusNotesEndorser .= ' — ' . $row['supervisor_comment'];
                }
            }
            $linesFundPrez = [];
            if ($endName !== '') {
                $lnAe = 'Applicable endorser: ' . $endName;
                if (!empty($row['endorser_endorsed_at'])) {
                    $lnAe .= ' (' . date('M j, Y g:i A', strtotime($row['endorser_endorsed_at'])) . ')';
                }
                if (!empty($row['endorser_comment'])) {
                    $lnAe .= ' — ' . $row['endorser_comment'];
                }
                $linesFundPrez[] = $lnAe;
            }
            if ($needFundPrez && $fundAvailName !== '') {
                $lnF = 'Budget/Accounting fund: ' . $fundAvailName;
                if (!empty($row['fund_availability_endorsed_at'])) {
                    $lnF .= ' (' . date('M j, Y g:i A', strtotime($row['fund_availability_endorsed_at'])) . ')';
                }
                if (!empty($row['fund_availability_comment'])) {
                    $lnF .= ' — ' . $row['fund_availability_comment'];
                }
                $linesFundPrez[] = $lnF;
            }
            $statusNotesFund = !empty($linesFundPrez) ? implode("\n", $linesFundPrez) : '—';
        } elseif ($status === 'endorsed') {
            if ($presName !== '') {
                $finalApprovalLine = 'Approved — President (key official)';
                $statusMain = 'Fully approved in portal';
                if ($supName !== '') {
                    $statusNotesEndorser = 'Supervisor: ' . $supName;
                    if (!empty($row['supervisor_comment'])) {
                        $statusNotesEndorser .= ' — ' . $row['supervisor_comment'];
                    }
                }
                $endorserBlock = '';
                if ($endName !== '') {
                    $endorserBlock = 'Applicable endorser: ' . $endName;
                    if (!empty($row['endorser_endorsed_at'])) {
                        $endorserBlock .= ' (' . date('M j, Y g:i A', strtotime($row['endorser_endorsed_at'])) . ')';
                    }
                    if (!empty($row['endorser_comment'])) {
                        $endorserBlock .= ' — ' . $row['endorser_comment'];
                    }
                }
                $presBlock = 'President: ' . $presName;
                if (!empty($row['president_endorsed_at'])) {
                    $presBlock .= ' (' . date('M j, Y g:i A', strtotime($row['president_endorsed_at'])) . ')';
                }
                if (!empty($row['president_comment'])) {
                    $presBlock .= ' — ' . $row['president_comment'];
                }
                $statusNotesFund = trim($endorserBlock . ($endorserBlock !== '' ? "\n" : '') . $presBlock);
            } else {
                $finalApprovalLine = 'Endorsed (applicable endorser)';
                $statusMain = 'Fully endorsed in portal';
                if ($supName !== '') {
                    $statusNotesEndorser = 'Supervisor: ' . $supName;
                    if (!empty($row['supervisor_comment'])) {
                        $statusNotesEndorser .= ' — ' . $row['supervisor_comment'];
                    }
                }
                if ($endName !== '') {
                    $statusNotesFund = 'Applicable endorser: ' . $endName;
                    if (!empty($row['endorser_endorsed_at'])) {
                        $statusNotesFund .= ' (' . date('M j, Y g:i A', strtotime($row['endorser_endorsed_at'])) . ')';
                    }
                    if (!empty($row['endorser_comment'])) {
                        $statusNotesFund .= ' — ' . $row['endorser_comment'];
                    }
                }
            }
        } elseif ($status === 'rejected') {
            $finalApprovalLine = 'Rejected';
            $stage = $row['rejection_stage'] ?? '';
            $statusMain = $stage === 'supervisor'
                ? 'Rejected by supervisor'
                : ($stage === 'endorser'
                    ? 'Rejected by applicable endorser'
                    : ($stage === 'fund_availability'
                        ? 'Rejected by Budget/Accounting fund endorser'
                        : ($stage === 'president' ? 'Rejected by President (key official)' : 'Rejected')));
            $reason = trim($row['rejection_reason'] ?? '');
            $statusNotesEndorser = $reason !== '' ? $reason : '—';
            $statusNotesFund = '—';
        }

        $basePath = getBasePath();
        $uploadBase = rtrim($basePath, '/') . '/uploads/';
        $filledOfficialOrderHref = null;
        $filledOfficialOrderLabel = null;
        if (($row['status'] ?? '') === 'endorsed') {
            $filledOfficialOrderHref = clean_url(
                rtrim($basePath, '/') . '/faculty/tarf_request_pdf.php?id=' . (int) $row['id'],
                $basePath
            );
            $filledOfficialOrderLabel = 'TARF — filled official form (activity request + office order).pdf';
        }
        $disappShowOfficialFormLink = (($row['status'] ?? '') === 'endorsed');

        require_once __DIR__ . '/tarf_disapp_office_order_html.php';
        $officeOrderSectionHtml = tarf_render_travel_office_order_section_html($row, $form);

        $endorseGridSupervisorName = ($supName !== '') ? $supName : '—';

        ob_start();
        include __DIR__ . '/tarf_disapp_card_body.php';

        return (string) ob_get_clean();
    }
}
