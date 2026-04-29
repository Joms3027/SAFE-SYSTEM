<?php
/**
 * DISAPP-style NTARF card HTML (non-travel).
 */
if (!function_exists('ntarf_render_disapp_card_html')) {
    /**
     * @param array $row tarf_requests row
     */
    function ntarf_render_disapp_card_html(PDO $db, array $row, int $viewerId): string
    {
        require_once __DIR__ . '/ntarf_form_options.php';
        require_once __DIR__ . '/tarf_workflow.php';

        $form = json_decode($row['form_data'], true);
        if (!is_array($form)) {
            $form = [];
        }
        if (($form['form_kind'] ?? '') === 'ntarf') {
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

        $opts = ntarf_get_form_options();

        $requestedSupportLines = ntarf_ntarf_support_display_lines($form, $opts);
        $requestedSupportHtml = $requestedSupportLines
            ? nl2br(tarf_disapp_escape(implode("\n", $requestedSupportLines)))
            : '—';

        $ntarfCampusCell = trim((string) ($form['activity_campus'] ?? ''));
        if ($ntarfCampusCell === 'OUTSIDE THE CAMPUS' && !empty($form['activity_campus_other'])) {
            $ntarfCampusCell .= ' — ' . trim((string) $form['activity_campus_other']);
        }
        if ($ntarfCampusCell === '') {
            $ntarfCampusCell = '—';
        }
        $ntarfVenueCell = ntarf_compose_venue_display_line($form);
        if ($ntarfVenueCell === '') {
            $ntarfVenueCell = '—';
        }
        $ntarfTypeInvolvementCell = ntarf_format_involvement_display($form, $opts);
        if ($ntarfTypeInvolvementCell === '') {
            $ntarfTypeInvolvementCell = '—';
        }
        $ntarfEndorserVenueCell = trim((string) ($form['endorser_venue_availability'] ?? '')) ?: '—';
        $ntarfEndorserElectricCell = trim((string) ($form['endorser_electricity'] ?? '')) ?: '—';

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

        $statusNotesEndorser = '—';
        $statusNotesFund = '—';
        $finalApprovalLine = 'Pending';
        $notesApproverEndorser = '—';
        $notesFundAvailability = '—';
        $notesVenuePmes = '—';

        if (in_array($status, ['pending_joint', 'pending_supervisor', 'pending_endorser'], true)) {
            $needFund = tarf_request_requires_fund_availability_endorsement($row);
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
            $reason = trim($row['rejection_reason'] ?? '');
            $statusNotesEndorser = $reason !== '' ? $reason : '—';
            $statusNotesFund = '—';
        }

        $apLines = [];
        if ($supName !== '' || !empty($row['supervisor_endorsed_at']) || !empty($row['supervisor_comment'])) {
            $ln = 'Supervisor';
            if ($supName !== '') {
                $ln .= ': ' . $supName;
            }
            if (!empty($row['supervisor_endorsed_at'])) {
                $ln .= ' (' . date('M j, Y g:i A', strtotime($row['supervisor_endorsed_at'])) . ')';
            }
            if (!empty($row['supervisor_comment'])) {
                $ln .= '. ' . $row['supervisor_comment'];
            }
            $apLines[] = $ln;
        }
        if ($endName !== '' || !empty($row['endorser_endorsed_at']) || !empty($row['endorser_comment'])) {
            $ln = 'Applicable endorser';
            if ($endName !== '') {
                $ln .= ': ' . $endName;
            }
            if (!empty($row['endorser_endorsed_at'])) {
                $ln .= ' (' . date('M j, Y g:i A', strtotime($row['endorser_endorsed_at'])) . ')';
            }
            if (!empty($row['endorser_comment'])) {
                $ln .= '. ' . $row['endorser_comment'];
            }
            $apLines[] = $ln;
        }
        if ($presName !== '' || !empty($row['president_comment'])) {
            $ln = 'President (final)';
            if ($presName !== '') {
                $ln .= ': ' . $presName;
            }
            if (!empty($row['president_endorsed_at'])) {
                $ln .= ' (' . date('M j, Y g:i A', strtotime($row['president_endorsed_at'])) . ')';
            }
            if (!empty($row['president_comment'])) {
                $ln .= '. ' . $row['president_comment'];
            }
            $apLines[] = $ln;
        }
        if ($fundAvailName !== '' || !empty($row['fund_availability_endorsed_at']) || !empty($row['fund_availability_comment'])
            || (function_exists('tarf_request_requires_fund_availability_endorsement') && tarf_request_requires_fund_availability_endorsement($row))) {
            $ln = 'Budget/Accounting fund';
            if ($fundAvailName !== '') {
                $ln .= ': ' . $fundAvailName;
            }
            if (!empty($row['fund_availability_endorsed_at'])) {
                $ln .= ' (' . date('M j, Y g:i A', strtotime($row['fund_availability_endorsed_at'])) . ')';
            } elseif (tarf_request_requires_fund_availability_endorsement($row)) {
                $ln .= ' — pending';
            }
            if (!empty($row['fund_availability_comment'])) {
                $ln .= '. ' . $row['fund_availability_comment'];
            }
            $apLines[] = $ln;
        }
        if (count($apLines) > 0) {
            $notesApproverEndorser = implode("\n\n", $apLines);
        } elseif (in_array($status, ['pending_joint', 'pending_supervisor', 'pending_endorser'], true)) {
            $notesApproverEndorser = 'Parallel endorsement stage: supervisor, applicable endorser'
                . (tarf_request_requires_fund_availability_endorsement($row) ? ', Budget/Accounting fund.' : '.');
        } elseif ($status === 'pending_president') {
            $notesApproverEndorser = 'Awaiting President (final approval).';
        }

        $fundParts = [];
        if ($fundCertLabel !== '—') {
            $fundParts[] = 'Fund availability (designated certifier): ' . strip_tags($fundCertLabel);
        }
        if ($statusNotesFund !== '—' && trim($statusNotesFund) !== '') {
            $fundParts[] = $statusNotesFund;
        }
        $notesFundAvailability = count($fundParts) ? implode("\n\n", $fundParts) : '—';

        $venueText = ntarf_compose_venue_display_line($form);
        $emDash = "\xE2\x80\x94";
        $notesVenuePmes = $venueText !== ''
            ? 'Venue (from request): ' . $venueText . "\n\nPMES / facility system notes: " . $emDash
            : 'Venue (from request): ' . $emDash . "\n\nPMES / facility system notes: " . $emDash;

        if ($status === 'rejected') {
            $reason = trim($row['rejection_reason'] ?? '');
            $notesApproverEndorser = $reason !== '' ? $reason : 'Rejected.';
            $notesFundAvailability = $emDash;
            $notesVenuePmes = 'Venue (from request): ' . ($venueText !== '' ? $venueText : $emDash) . "\n\nPMES / facility system notes: " . $emDash;
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
            $filledOfficialOrderLabel = '2.1 [NON-TRAVEL] Activity Request Form (filled from request).pdf';
        }
        $disappShowOfficialFormLink = (($row['status'] ?? '') === 'endorsed');

        $dateTimeCell = tarf_disapp_escape($form['date_activity_start'] ?? '') . ' TO ' . tarf_disapp_escape($form['date_activity_end'] ?? '')
            . "\n(" . tarf_disapp_escape($form['time_activity_start'] ?? '') . ' TO ' . tarf_disapp_escape($form['time_activity_end'] ?? '') . ')';

        require_once __DIR__ . '/tarf_disapp_office_order_html.php';
        $officeOrderSectionHtml = tarf_render_ntarf_office_order_section_html($row, $form);

        ob_start();
        include __DIR__ . '/ntarf_disapp_card_body.php';

        return (string) ob_get_clean();
    }
}
