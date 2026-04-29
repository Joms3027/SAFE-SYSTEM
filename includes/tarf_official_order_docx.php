<?php
/**
 * Fill official Word “Activity Request” office-order templates (2.2 travel / 2.1 non-travel)
 * by replacing <<placeholders>> in word/document.xml. Invoked when a request is fully endorsed.
 */
if (!function_exists('tarf_official_order_xml_escape')) {
    function tarf_official_order_xml_escape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tarf_official_order_nonempty')) {
    /** Visible fallback when a field has no text (Word must not show empty placeholders). */
    function tarf_official_order_nonempty(?string $s, string $ifEmpty = '—'): string
    {
        $t = trim((string) $s);

        return $t === '' ? $ifEmpty : $t;
    }
}

if (!function_exists('tarf_official_order_join_nonempty')) {
    /**
     * Join trimmed non-empty parts with $sep. If nothing to join, return $ifEmpty.
     *
     * @param list<string|null> $parts
     */
    function tarf_official_order_join_nonempty(string $sep, string $ifEmpty, array $parts): string
    {
        $out = [];
        foreach ($parts as $p) {
            $t = trim((string) $p);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        if ($out === []) {
            return $ifEmpty;
        }

        return implode($sep, $out);
    }
}

if (!function_exists('tarf_official_orders_abs_dir')) {
    function tarf_official_orders_abs_dir(): string
    {
        return dirname(__DIR__) . '/uploads/tarf_official_orders';
    }
}

if (!function_exists('tarf_official_order_template_travel_form_disapp')) {
    /**
     * DISAPP-style [TRAVEL] ACTIVITY REQUEST FORM (full grid + placeholders). Paired with
     * tarf_official_order_template_travel_office_order() for merged endorsed output.
     */
    function tarf_official_order_template_travel_form_disapp(): string
    {
        $base = dirname(__DIR__) . '/traf_docx';
        foreach ([
            $base . '/travel/2.2D DISAPP [TRAVEL] Activity Request Form.docx',
            $base . '/2.2D DISAPP [TRAVEL] Activity Request Form.docx',
        ] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }

        return $base . '/2.2D DISAPP [TRAVEL] Activity Request Form.docx';
    }
}

if (!function_exists('tarf_official_order_template_travel_office_order')) {
    /**
     * Office order narrative only (short 2.2 file). Appended after the DISAPP form when merging.
     */
    function tarf_official_order_template_travel_office_order(): string
    {
        $base = dirname(__DIR__) . '/traf_docx';
        foreach ([
            $base . '/travel/2.2 [TRAVEL] Activity Request Form.docx',
            $base . '/2.2 [TRAVEL] Activity Request Form.docx',
        ] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }

        return $base . '/2.2 [TRAVEL] Activity Request Form.docx';
    }
}

if (!function_exists('tarf_official_order_template_travel')) {
    /** @deprecated For cache checks use form + office paths; this returns the office-order file only. */
    function tarf_official_order_template_travel(): string
    {
        return tarf_official_order_template_travel_office_order();
    }
}

if (!function_exists('tarf_official_order_template_ntarf')) {
    /**
     * Use the full ntarf/ Word file first: it includes the Activity Request table plus the
     * "OFFICE ORDER FOR NON-TRAVEL ACTIVITIES" narrative block. The "final" variant is form-only
     * (no office order section); keep it as fallback if the full file is missing.
     */
    function tarf_official_order_template_ntarf(): string
    {
        $base = dirname(__DIR__) . '/traf_docx';
        foreach ([
            $base . '/ntarf/2.1 [NON-TRAVEL] Activity Request Form.docx',
            $base . '/ntarf/2.1 [NON-TRAVEL] Activity Request Form final.docx',
            $base . '/2.1 [NON-TRAVEL] Activity Request Form.docx',
        ] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }

        return $base . '/2.1 [NON-TRAVEL] Activity Request Form.docx';
    }
}

if (!function_exists('tarf_enrich_requester_form_for_docx')) {
    /**
     * Backfill requester name / college when form_data is missing them (legacy TARF/NTARF rows or partial JSON).
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    function tarf_enrich_requester_form_for_docx(PDO $db, array $row, array $form): array
    {
        require_once __DIR__ . '/tarf_workflow.php';

        $uid = (int) ($row['user_id'] ?? 0);
        if ($uid <= 0) {
            return $form;
        }

        if (trim((string) ($form['requester_name'] ?? '')) === '') {
            $name = tarf_display_name_for_user($uid, $db);
            if ($name !== '') {
                $form['requester_name'] = $name;
            }
        }

        if (trim((string) ($form['college_office_project'] ?? '')) === '') {
            try {
                $st = $db->prepare('SELECT department FROM faculty_profiles WHERE user_id = ? LIMIT 1');
                $st->execute([$uid]);
                $dep = $st->fetchColumn();
                if ($dep !== false && trim((string) $dep) !== '') {
                    $form['college_office_project'] = trim((string) $dep);
                }
            } catch (Exception $e) {
                // keep form as-is
            }
        }

        return $form;
    }
}

if (!function_exists('tarf_ntarf_enrich_form_for_docx')) {
    /**
     * @deprecated Use tarf_enrich_requester_form_for_docx (same behavior).
     */
    function tarf_ntarf_enrich_form_for_docx(PDO $db, array $row, array $form): array
    {
        return tarf_enrich_requester_form_for_docx($db, $row, $form);
    }
}

if (!function_exists('tarf_ntarf_docx_status_notes')) {
    /**
     * Status / notes blocks for the NTARF Word form table (endorsed requests).
     *
     * @return array{approver:string,fund:string,fund_cert:string,venue_pmes:string}
     */
    function tarf_ntarf_docx_status_notes(PDO $db, array $row, array $form): array
    {
        require_once __DIR__ . '/ntarf_form_options.php';
        require_once __DIR__ . '/tarf_workflow.php';

        $dash = tarf_official_order_nonempty(null);

        $supName = !empty($row['supervisor_endorsed_by'])
            ? tarf_display_name_for_user((int) $row['supervisor_endorsed_by'], $db) : '';
        $endName = !empty($row['endorser_endorsed_by'])
            ? tarf_display_name_for_user((int) $row['endorser_endorsed_by'], $db) : '';
        $presName = !empty($row['president_endorsed_by'])
            ? tarf_display_name_for_user((int) $row['president_endorsed_by'], $db) : '';

        $fundCertLabel = $dash;
        $fundKey = trim((string) ($form['endorser_fund_availability'] ?? ''));
        if ($fundKey !== '') {
            $certName = tarf_fund_availability_certifier_display_name($db, $row, $form);
            $fundCertLabel = $certName !== '' ? $certName : $dash;
        }

        $status = (string) ($row['status'] ?? '');
        $statusNotesFund = $dash;

        if ($status === 'endorsed') {
            if ($presName !== '') {
                $endorserBlock = '';
                if ($endName !== '') {
                    $endorserBlock = 'Applicable endorser: ' . $endName;
                    if (!empty($row['endorser_endorsed_at'])) {
                        $endorserBlock .= ' (' . date('M j, Y g:i A', strtotime((string) $row['endorser_endorsed_at'])) . ')';
                    }
                    if (!empty($row['endorser_comment'])) {
                        $endorserBlock .= ' — ' . $row['endorser_comment'];
                    }
                }
                $presBlock = 'President: ' . $presName;
                if (!empty($row['president_endorsed_at'])) {
                    $presBlock .= ' (' . date('M j, Y g:i A', strtotime((string) $row['president_endorsed_at'])) . ')';
                }
                if (!empty($row['president_comment'])) {
                    $presBlock .= ' — ' . $row['president_comment'];
                }
                $statusNotesFund = trim($endorserBlock . ($endorserBlock !== '' ? "\n" : '') . $presBlock);
            } else {
                if ($endName !== '') {
                    $statusNotesFund = 'Applicable endorser: ' . $endName;
                    if (!empty($row['endorser_endorsed_at'])) {
                        $statusNotesFund .= ' (' . date('M j, Y g:i A', strtotime((string) $row['endorser_endorsed_at'])) . ')';
                    }
                    if (!empty($row['endorser_comment'])) {
                        $statusNotesFund .= ' — ' . $row['endorser_comment'];
                    }
                }
            }
        }

        $fundParts = [];
        if ($fundCertLabel !== $dash) {
            $fundParts[] = 'Fund availability (designated certifier): ' . $fundCertLabel;
        }
        if ($statusNotesFund !== $dash && trim($statusNotesFund) !== '') {
            $fundParts[] = $statusNotesFund;
        }
        $notesFundAvailability = $fundParts !== [] ? implode("\n\n", $fundParts) : $dash;

        $apLines = [];
        if ($supName !== '' || !empty($row['supervisor_endorsed_at']) || !empty($row['supervisor_comment'])) {
            $ln = 'Supervisor';
            if ($supName !== '') {
                $ln .= ': ' . $supName;
            }
            if (!empty($row['supervisor_endorsed_at'])) {
                $ln .= ' (' . date('M j, Y g:i A', strtotime((string) $row['supervisor_endorsed_at'])) . ')';
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
                $ln .= ' (' . date('M j, Y g:i A', strtotime((string) $row['endorser_endorsed_at'])) . ')';
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
                $ln .= ' (' . date('M j, Y g:i A', strtotime((string) $row['president_endorsed_at'])) . ')';
            }
            if (!empty($row['president_comment'])) {
                $ln .= '. ' . $row['president_comment'];
            }
            $apLines[] = $ln;
        }
        $notesApprover = $apLines !== [] ? implode("\n\n", $apLines) : $dash;

        $venueText = trim(ntarf_compose_venue_display_line($form));
        $notesVenuePmes = $venueText !== ''
            ? 'Venue (from request): ' . $venueText . "\n\nPMES / facility system notes: " . $dash
            : 'Venue (from request): ' . $dash . "\n\nPMES / facility system notes: " . $dash;

        return [
            'approver' => $notesApprover,
            'fund' => $notesFundAvailability,
            'fund_cert' => $fundCertLabel,
            'venue_pmes' => $notesVenuePmes,
        ];
    }
}

if (!function_exists('tarf_ntarf_all_docx_entity_values')) {
    /**
     * Plain-text values for every <<token>> in the NTARF 2.1 Word template (form grid + office order).
     *
     * @return array<string, string> keys are XML-entity form e.g. &lt;&lt;Name of Requester&gt;&gt;
     */
    function tarf_ntarf_all_docx_entity_values(PDO $db, array $row, array $form): array
    {
        require_once __DIR__ . '/ntarf_form_options.php';
        require_once __DIR__ . '/tarf_workflow.php';

        $opts = ntarf_get_form_options();
        $dash = tarf_official_order_nonempty(null);
        $notes = tarf_ntarf_docx_status_notes($db, $row, $form);

        $supportLines = ntarf_ntarf_support_display_lines($form, $opts);
        $supportText = $supportLines !== [] ? implode("\n", $supportLines) : $dash;

        $filedTs = !empty($form['submitted_at_server'])
            ? date('F j, Y g:i A', strtotime((string) $form['submitted_at_server']))
            : (!empty($row['created_at'])
                ? date('F j, Y g:i A', strtotime((string) $row['created_at']))
                : $dash);

        $id = (int) ($row['id'] ?? 0);
        $idDisp = $id > 0 ? (string) $id : $dash;

        $ae = trim((string) ($form['applicable_endorser'] ?? ''));
        $endName = !empty($row['endorser_endorsed_by'])
            ? tarf_display_name_for_user((int) $row['endorser_endorsed_by'], $db) : '';
        $applicableLine = $ae;
        if ($applicableLine !== '' && $endName !== '') {
            $applicableLine .= "\n" . $endName;
        } elseif ($applicableLine === '' && $endName !== '') {
            $applicableLine = $endName;
        }
        if ($applicableLine === '') {
            $applicableLine = $dash;
        }

        $dateAct = tarf_official_order_nonempty($form['date_activity_start'] ?? null);
        $dateEnd = tarf_official_order_nonempty($form['date_activity_end'] ?? null);
        $timeStart = tarf_official_order_nonempty($form['time_activity_start'] ?? null);
        $timeEnd = tarf_official_order_nonempty($form['time_activity_end'] ?? null);
        $typeInv = tarf_official_order_nonempty(ntarf_format_involvement_display($form, $opts) ?: null);
        $actReq = tarf_official_order_nonempty($form['activity_requested'] ?? null);

        $fundingCharged = !empty($form['funding_charged_to'])
            ? (string) $form['funding_charged_to'] : $dash;
        $fundingSpec = tarf_official_order_nonempty($form['funding_specifier'] ?? null);
        $totalAmt = isset($form['total_estimated_amount']) && $form['total_estimated_amount'] !== ''
            ? (string) $form['total_estimated_amount'] : $dash;
        $venueLine = tarf_official_order_nonempty(ntarf_compose_venue_display_line($form) ?: null);
        $endorseVenue = tarf_official_order_nonempty($form['endorser_venue_availability'] ?? null);
        $endorseElectric = tarf_official_order_nonempty($form['endorser_electricity'] ?? null);

        return [
            '&lt;&lt;Name of Requester&gt;&gt;' => tarf_official_order_nonempty($form['requester_name'] ?? null),
            '&lt;&lt;College/Office/Project&gt;&gt;' => tarf_official_order_nonempty($form['college_office_project'] ?? null),
            '&lt;&lt;Timestamp&gt;&gt;' => $filedTs,
            '&lt;&lt;Main Organizer&gt;&gt;' => tarf_official_order_nonempty($form['main_organizer'] ?? null),
            '&lt;&lt;Justification/Explanation&gt;&gt;' => tarf_official_order_nonempty($form['justification'] ?? null),
            '&lt;&lt;Venue&gt;&gt;' => $venueLine,
            '&lt;&lt;Involved WPU Personnel (Position)&gt;&gt;' => tarf_official_order_nonempty($form['involved_wpu_personnel'] ?? null),
            '&lt;&lt;Requested Support (Check all that apply)&gt;&gt;' => $supportText,
            '&lt;&lt;Funding Charged to&gt;&gt;' => $fundingCharged,
            '&lt;&lt;Funding Specifier&gt;&gt;' => $fundingSpec,
            '&lt;&lt;Fund Availability Certified By&gt;&gt;' => $notes['fund_cert'],
            '&lt;&lt;Total Estimated Amount&gt;&gt;' => $totalAmt,
            '&lt;&lt;Applicable Endorser\'s Name&gt;&gt;' => $applicableLine,
            '&lt;&lt;Endorser for Venue Availability&gt;&gt;' => $endorseVenue,
            '&lt;&lt;Endorser for Electricity and Generator Use&gt;&gt;' => $endorseElectric,
            '&lt;&lt;Notes from Approver or Endorser&gt;&gt;' => $notes['approver'],
            '&lt;&lt;Notes re Fund Availability&gt;&gt;' => $notes['fund'],
            '&lt;&lt;Venue or PMES Notes&gt;&gt;' => $notes['venue_pmes'],
            '&lt;&lt;Activity Requested&gt;&gt;' => $actReq,
            '&lt;&lt;Date of Activity&gt;&gt;' => $dateAct,
            '&lt;&lt;Date of Activity End&gt;&gt;' => $dateEnd,
            '&lt;&lt;Time of Activity Start&gt;&gt;' => $timeStart,
            '&lt;&lt;Time of Activity End&gt;&gt;' => $timeEnd,
            '&lt;&lt;Type of Involvement&gt;&gt;' => $typeInv,
            '&lt;&lt;Final Approval&gt;&gt;' => 'authorized',
            '&lt;&lt;NTARF #&gt;&gt;' => $idDisp,
        ];
    }
}

if (!function_exists('tarf_ntarf_format_to_recipients')) {
    /**
     * Office-order "TO:" line: requester (with college/office) plus involved WPU personnel, de-duplicated.
     */
    function tarf_ntarf_format_to_recipients(array $form): string
    {
        $segments = [];
        $name = trim((string) ($form['requester_name'] ?? ''));
        $col = trim((string) ($form['college_office_project'] ?? ''));
        if ($name !== '') {
            $segments[] = $col !== '' ? $name . ' (' . $col . ')' : $name;
        }
        $inv = trim((string) ($form['involved_wpu_personnel'] ?? ''));
        if ($inv !== '') {
            $reqPlain = preg_replace('/\s+/', ' ', $name);
            $reqWithCol = $col !== '' ? $reqPlain . ' (' . preg_replace('/\s+/', ' ', $col) . ')' : $reqPlain;
            foreach (preg_split('/\r\n|\r|\n/', $inv) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $norm = preg_replace('/\s+/', ' ', $line);
                if ($name !== '' && (strcasecmp($norm, $reqPlain) === 0 || strcasecmp($norm, $reqWithCol) === 0)) {
                    continue;
                }
                $segments[] = $line;
            }
        }
        $out = implode('; ', array_values(array_unique($segments)));

        return $out === '' ? tarf_official_order_nonempty(null) : $out;
    }
}

if (!function_exists('tarf_travel_format_to_recipients')) {
    /**
     * Office-order "TO:" line: requester (with college/office) plus persons to travel, de-duplicated.
     */
    function tarf_travel_format_to_recipients(array $form): string
    {
        $segments = [];
        $name = trim((string) ($form['requester_name'] ?? ''));
        $col = trim((string) ($form['college_office_project'] ?? ''));
        if ($name !== '') {
            $segments[] = $col !== '' ? $name . ' (' . $col . ')' : $name;
        }
        $inv = trim((string) ($form['persons_to_travel'] ?? ''));
        if ($inv !== '') {
            $reqPlain = preg_replace('/\s+/', ' ', $name);
            $reqWithCol = $col !== '' ? $reqPlain . ' (' . preg_replace('/\s+/', ' ', $col) . ')' : $reqPlain;
            foreach (preg_split('/\r\n|\r|\n/', $inv) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $norm = preg_replace('/\s+/', ' ', $line);
                if ($name !== '' && (strcasecmp($norm, $reqPlain) === 0 || strcasecmp($norm, $reqWithCol) === 0)) {
                    continue;
                }
                $segments[] = $line;
            }
        }
        $out = implode('; ', array_values(array_unique($segments)));

        return $out === '' ? tarf_official_order_nonempty(null) : $out;
    }
}

if (!function_exists('tarf_ntarf_apply_universal_xml_fill')) {
    /**
     * Fills NTARF placeholders for both root and ntarf/ Word layouts (rsid splits, table + narrative).
     * Every multi-run pattern matches from the FIRST run's opening `<w:r>` through the LAST run's
     * closing `</w:r>` so replacements stay XML-balanced.
     */
    function tarf_ntarf_apply_universal_xml_fill(string $xml, array $form, array $row): string
    {
        $id = (int) ($row['id'] ?? 0);
        $year = (int) ($row['serial_year'] ?? 0);
        $yearDisp = $year > 0 ? (string) $year : tarf_official_order_nonempty(null);
        $idDisp = $id > 0 ? (string) $id : tarf_official_order_nonempty(null);

        $toText = tarf_ntarf_format_to_recipients($form);
        $dateS = tarf_official_order_nonempty($form['date_activity_start'] ?? null);
        $dateE = tarf_official_order_nonempty($form['date_activity_end'] ?? null);
        $timeS = tarf_official_order_nonempty($form['time_activity_start'] ?? null);
        $timeE = tarf_official_order_nonempty($form['time_activity_end'] ?? null);
        require_once __DIR__ . '/ntarf_form_options.php';
        $typeInvPlain = ntarf_format_involvement_display($form, ntarf_get_form_options());
        if ($typeInvPlain === '') {
            $typeInvPlain = trim((string) ($form['type_of_involvement'] ?? ''));
        }
        $typeInv = tarf_official_order_nonempty($typeInvPlain !== '' ? $typeInvPlain : null);
        $actReq = tarf_official_order_nonempty($form['activity_requested'] ?? null);

        $esc = static function (string $s): string {
            return tarf_official_order_xml_escape($s);
        };

        // Between consecutive `<w:r>` runs Word may emit proofErr markers + whitespace.
        $GAP = '\s*(?:<w:proofErr[^/]*/>\s*)*';
        // Body of a single run (no nested `</w:r>` allowed).
        $RBODY = '(?:(?!</w:r>).)*?';

        // 1) "No. <<NTARF #>>, s. YYYY" — 3 runs (both root and ntarf templates split this).
        $xml = preg_replace(
            '~<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>No\. &lt;&lt;NTARF\s*</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>\#</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>&gt;&gt;, s\. \d{4}</w:t></w:r>~su',
            '<w:r><w:rPr><w:rFonts w:ascii="Georgia" w:eastAsia="Georgia" w:hAnsi="Georgia" w:cs="Georgia"/><w:sz w:val="30"/><w:szCs w:val="30"/></w:rPr><w:t xml:space="preserve">No. ' . $esc($idDisp) . ', s. ' . $esc($yearDisp) . '</w:t></w:r>',
            $xml,
            1
        ) ?? $xml;

        // 2) "TO: <<Involved WPU Personnel (Position)>>" — 3 runs.
        $xml = preg_replace(
            '~<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>TO: &lt;&lt;Involved WPU Personnel \(Position</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>\)</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>&gt;&gt;</w:t></w:r>~su',
            '<w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t xml:space="preserve">TO: ' . $esc($toText) . '</w:t></w:r>',
            $xml,
            1
        ) ?? $xml;

        // 3) Table layout (ntarf/): "(<<" run + "Time Start>> - <<Time End>>)" run — 2 runs.
        $xml = preg_replace(
            '~<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>\(&lt;&lt;</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>Time of Activity Start&gt;&gt; - &lt;&lt;Time of Activity End&gt;&gt;\)</w:t></w:r>~su',
            '<w:r><w:t xml:space="preserve">(' . $esc($timeS) . ' - ' . $esc($timeE) . ')</w:t></w:r>',
            $xml
        ) ?? $xml;

        // 4) Table layout variant: "(<<" + "Time Start>> TO <<Time End>>)" — 2 runs.
        $xml = preg_replace(
            '~<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>\(&lt;&lt;</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>Time of Activity Start&gt;&gt; TO &lt;&lt;Time of Activity End&gt;&gt;\)</w:t></w:r>~su',
            '<w:r><w:t xml:space="preserve">(' . $esc($timeS) . ' TO ' . $esc($timeE) . ')</w:t></w:r>',
            $xml
        ) ?? $xml;

        // 5) NTARF narrative tail: "<<Date End>> (<<" + "Time Start>> - <<Time End>>) " + " as <<" + "Type of Involvement" + ">>." (5 runs).
        $xml = preg_replace(
            '~<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>&lt;&lt;Date of Activity End&gt;&gt; \(&lt;&lt;</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>Time of Activity Start&gt;&gt; - &lt;&lt;Time of Activity End&gt;&gt;\) </w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*> as &lt;&lt;</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>Type of Involvement</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>&gt;&gt;\.</w:t></w:r>~su',
            '<w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t xml:space="preserve">' . $esc($dateE) . ' (' . $esc($timeS) . ' - ' . $esc($timeE) . ')</w:t></w:r>'
                . '<w:r><w:t xml:space="preserve"> as </w:t></w:r>'
                . '<w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t xml:space="preserve">' . $esc($typeInv) . '.</w:t></w:r>',
            $xml,
            1
        ) ?? $xml;

        // 6) Root narrative: full split from "<<Date of Activity>" through ">>." (11 runs, proofErr in between).
        $xml = preg_replace(
            '~<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*> &lt;&lt;Date of Activity&gt;</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>&gt;</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>  TO</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*> </w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>&lt;&lt;Date of Activity End&gt;&gt; \(&lt;&lt;</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>Time of Activity Start&gt;&gt; - &lt;&lt;Time of Activity End&gt;</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>&gt;\) </w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*> as</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*> &lt;&lt;</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>Type of Involvement</w:t></w:r>'
                . $GAP . '<w:r\b[^>]*>' . $RBODY . '<w:t[^>]*>&gt;&gt;\.</w:t></w:r>~su',
            '<w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t xml:space="preserve"> ' . $esc($dateS) . '</w:t></w:r>'
                . '<w:r><w:t xml:space="preserve">  TO  </w:t></w:r>'
                . '<w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t xml:space="preserve">' . $esc($dateE) . ' (' . $esc($timeS) . ' - ' . $esc($timeE) . ')</w:t></w:r>'
                . '<w:r><w:t xml:space="preserve"> as </w:t></w:r>'
                . '<w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t xml:space="preserve">' . $esc($typeInv) . '.</w:t></w:r>',
            $xml,
            1
        ) ?? $xml;

        // 7) Single-run tokens (safe str_replace — these live inside one <w:t> in the ntarf/ template).
        $replacements = [
            '&lt;&lt;Date of Activity&gt;&gt; TO &lt;&lt;Date of Activity End&gt;&gt;' => $esc($dateS) . ' TO ' . $esc($dateE),
            '&lt;&lt;Date of Activity&gt;&gt;' => $esc($dateS),
            '&lt;&lt;Date of Activity End&gt;&gt;' => $esc($dateE),
            '&lt;&lt;Time of Activity Start&gt;&gt;' => $esc($timeS),
            '&lt;&lt;Time of Activity End&gt;&gt;' => $esc($timeE),
            '&lt;&lt;Final Approval&gt;&gt;' => $esc('authorized'),
            '&lt;&lt;Activity Requested&gt;&gt;' => $esc($actReq),
            '&lt;&lt;Type of Involvement&gt;&gt;' => $esc($typeInv),
            '&lt;&lt;NTARF #&gt;&gt;' => $esc($idDisp),
        ];
        foreach ($replacements as $from => $to) {
            if ($from !== '' && strpos($xml, $from) !== false) {
                $xml = str_replace($from, $to, $xml);
            }
        }

        return $xml;
    }
}

if (!function_exists('tarf_filled_official_order_rel_path')) {
    /** Web-relative path from site root, e.g. uploads/tarf_official_orders/12_travel.docx */
    function tarf_filled_official_order_rel_path(int $requestId, string $kind): string
    {
        return 'uploads/tarf_official_orders/' . $requestId . '_' . $kind . '.docx';
    }
}

if (!function_exists('tarf_patch_official_order_docx_xml')) {
    /**
     * @param array<string, string> $entityReplacements keys like &lt;&lt;Token&gt;&gt; (XML entities) => plain text value
     */
    function tarf_patch_official_order_docx_xml(string $xml, array $blockReplacements, array $entityReplacements): string
    {
        foreach ($blockReplacements as $from => $to) {
            if ($from !== '' && strpos($xml, $from) !== false) {
                $xml = str_replace($from, $to, $xml);
            }
        }
        foreach ($entityReplacements as $from => $to) {
            if ($from !== '') {
                $xml = str_replace($from, tarf_official_order_xml_escape($to), $xml);
            }
        }
        // Catch any <<…>> still in document.xml (unmatched split runs or template edits).
        $xml = preg_replace(
            '/&lt;&lt;[^&]{0,400}?&gt;&gt;/u',
            tarf_official_order_xml_escape('—'),
            $xml
        ) ?? $xml;

        return $xml;
    }
}

if (!function_exists('tarf_official_order_merge_root_namespaces')) {
    /**
     * Ensure every xmlns:* declared on $officeDocumentXml's <w:document> root also exists on
     * $formDocumentXml's root. Prevents "undeclared prefix" XML errors (e.g. wp14:anchorId)
     * after we graft the office-order body into the form template.
     */
    function tarf_official_order_merge_root_namespaces(string $formDocumentXml, string $officeDocumentXml): string
    {
        if (!preg_match('/<w:document\b([^>]*)>/', $formDocumentXml, $fm, PREG_OFFSET_CAPTURE)) {
            return $formDocumentXml;
        }
        if (!preg_match('/<w:document\b([^>]*)>/', $officeDocumentXml, $om)) {
            return $formDocumentXml;
        }
        $formAttrs = $fm[1][0];
        $officeAttrs = $om[1];
        $formTagPos = $fm[0][1];
        $formTagLen = strlen($fm[0][0]);

        if (!preg_match_all('/\sxmlns:([A-Za-z_][\w.-]*)\s*=\s*(["\'])(.*?)\2/', $officeAttrs, $officeNs, PREG_SET_ORDER)) {
            return $formDocumentXml;
        }
        preg_match_all('/\sxmlns:([A-Za-z_][\w.-]*)\s*=/', $formAttrs, $formNs);
        $formPrefixes = array_flip($formNs[1] ?? []);

        $added = '';
        foreach ($officeNs as $m) {
            $prefix = $m[1];
            if (!isset($formPrefixes[$prefix])) {
                $added .= ' xmlns:' . $prefix . '=' . $m[2] . $m[3] . $m[2];
            }
        }
        if ($added === '') {
            return $formDocumentXml;
        }
        $newTag = '<w:document' . $formAttrs . $added . '>';

        return substr($formDocumentXml, 0, $formTagPos) . $newTag . substr($formDocumentXml, $formTagPos + $formTagLen);
    }
}

if (!function_exists('tarf_travel_merge_document_body_xml')) {
    /**
     * Concatenate two Word document.xml bodies: DISAPP travel form + page break + office order section.
     * Keeps section properties from the form (first) document. Also merges namespace declarations
     * from the office-order <w:document> root into the form root so any prefixes referenced inside
     * the grafted body (e.g. wp14:anchorId on drawings) remain declared.
     */
    function tarf_travel_merge_document_body_xml(string $formDocumentXml, string $officeDocumentXml): string
    {
        if (!preg_match('/<w:body>([\s\S]*)<\/w:body>/', $formDocumentXml, $fm)) {
            return $formDocumentXml;
        }
        if (!preg_match('/<w:body>([\s\S]*)<\/w:body>/', $officeDocumentXml, $om)) {
            return $formDocumentXml;
        }
        $formBody = $fm[1];
        $officeBody = $om[1];

        $formSect = '';
        $formContent = $formBody;
        if (preg_match('/^(.*)(<w:sectPr\b[\s\S]*<\/w:sectPr>)\s*$/', $formBody, $x)) {
            $formContent = $x[1];
            $formSect = $x[2];
        }

        $officeContent = $officeBody;
        if (preg_match('/^(.*)(<w:sectPr\b[\s\S]*<\/w:sectPr>)\s*$/', $officeBody, $x)) {
            $officeContent = $x[1];
        }

        $formDocumentXml = tarf_official_order_merge_root_namespaces($formDocumentXml, $officeDocumentXml);

        $pageBreak = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        $newBody = $formContent . $pageBreak . $officeContent . $formSect;
        $out = preg_replace('/<w:body>[\s\S]*<\/w:body>/', '<w:body>' . $newBody . '</w:body>', $formDocumentXml, 1);

        return is_string($out) ? $out : $formDocumentXml;
    }
}

if (!function_exists('tarf_official_order_signature_override_path')) {
    /**
     * Returns the absolute path to a loose signature image that should replace embedded
     * signature bytes carried over from the office-order template. Allows staff to swap
     * the President's e-signature by dropping a file next to the templates, without
     * repackaging the docx.
     */
    function tarf_official_order_signature_override_path(): ?string
    {
        $candidates = [
            dirname(__DIR__) . '/traf_docx/madam esig.jpg',
        ];
        foreach ($candidates as $p) {
            if (is_file($p) && is_readable($p)) {
                return $p;
            }
        }

        return null;
    }
}

if (!function_exists('tarf_official_order_transplant_office_media')) {
    /**
     * Copy image relationships + bytes from the office-order docx into the output docx, remap
     * their rIds so they don't collide with form-template rels, and ensure the body XML's
     * r:embed/r:link attributes reference the new rIds. Also guarantees [Content_Types].xml has
     * Default entries for every media extension we drag in (jpg/jpeg/png/gif/bmp/tif/svg).
     *
     * @return array{xml: string, ok: bool}
     */
    function tarf_official_order_transplant_office_media(
        ZipArchive $outZip,
        string $officeDocxPath,
        string $mergedBodyXml
    ): array {
        $result = ['xml' => $mergedBodyXml, 'ok' => true];
        if (!is_file($officeDocxPath) || !is_readable($officeDocxPath)) {
            return $result;
        }
        $zOff = new ZipArchive();
        if ($zOff->open($officeDocxPath) !== true) {
            return $result;
        }
        $officeRelsXml = $zOff->getFromName('word/_rels/document.xml.rels');
        if ($officeRelsXml === false) {
            $zOff->close();
            return $result;
        }
        if (!preg_match_all(
            '/<Relationship\b([^>]*)\/>/',
            $officeRelsXml,
            $relMatches,
            PREG_SET_ORDER
        )) {
            $zOff->close();
            return $result;
        }

        $formRelsXml = $outZip->getFromName('word/_rels/document.xml.rels');
        if ($formRelsXml === false) {
            $zOff->close();
            return $result;
        }

        $usedIds = [];
        if (preg_match_all('/\bId="([^"]+)"/', $formRelsXml, $idm)) {
            foreach ($idm[1] as $id) {
                $usedIds[$id] = true;
            }
        }
        $nextRidNum = 1;
        $newRid = static function () use (&$nextRidNum, &$usedIds): string {
            while (true) {
                $id = 'rId' . $nextRidNum;
                $nextRidNum++;
                if (!isset($usedIds[$id])) {
                    $usedIds[$id] = true;
                    return $id;
                }
            }
        };

        $imageTypeUri = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';
        $newRelEntries = [];
        $extensionsAdded = [];
        $sigOverride = tarf_official_order_signature_override_path();

        foreach ($relMatches as $rm) {
            $attrs = $rm[1];
            if (!preg_match('/\bType="([^"]+)"/', $attrs, $tm) || $tm[1] !== $imageTypeUri) {
                continue;
            }
            if (!preg_match('/\bId="([^"]+)"/', $attrs, $im)
                || !preg_match('/\bTarget="([^"]+)"/', $attrs, $tgm)) {
                continue;
            }
            $oldRid = $im[1];
            $targetRel = $tgm[1];
            $targetFull = 'word/' . ltrim($targetRel, '/');
            $targetFull = str_replace(['../'], '', $targetFull);
            $bytes = $zOff->getFromName($targetFull);
            if ($bytes === false) {
                continue;
            }
            $ext = strtolower(pathinfo($targetRel, PATHINFO_EXTENSION));
            if ($ext === 'jpg' || $ext === 'jpeg') {
                if ($sigOverride !== null) {
                    $override = @file_get_contents($sigOverride);
                    if ($override !== false && $override !== '') {
                        $bytes = $override;
                    }
                }
            }

            $baseName = pathinfo($targetRel, PATHINFO_BASENAME);
            $outTarget = 'media/office_' . $baseName;
            $outFull = 'word/' . $outTarget;
            $collisionIdx = 1;
            while ($outZip->locateName($outFull) !== false) {
                $outTarget = 'media/office_' . $collisionIdx . '_' . $baseName;
                $outFull = 'word/' . $outTarget;
                $collisionIdx++;
            }
            if (!$outZip->addFromString($outFull, $bytes)) {
                continue;
            }
            $newId = $newRid();
            $newRelEntries[] = '<Relationship Id="' . $newId
                . '" Type="' . $imageTypeUri
                . '" Target="' . htmlspecialchars($outTarget, ENT_QUOTES | ENT_XML1, 'UTF-8')
                . '"/>';

            if ($ext !== '') {
                $extensionsAdded[$ext] = true;
            }

            $mergedBodyXml = preg_replace(
                '/(\br:(?:embed|link)=")' . preg_quote($oldRid, '/') . '(")/',
                '${1}' . $newId . '${2}',
                $mergedBodyXml
            );
        }
        $zOff->close();

        if ($newRelEntries !== []) {
            $injection = implode('', $newRelEntries);
            $updatedRels = preg_replace(
                '/<\/Relationships>\s*$/',
                $injection . '</Relationships>',
                $formRelsXml,
                1
            );
            if (is_string($updatedRels) && $updatedRels !== $formRelsXml) {
                $outZip->deleteName('word/_rels/document.xml.rels');
                $outZip->addFromString('word/_rels/document.xml.rels', $updatedRels);
            }
        }

        if ($extensionsAdded !== []) {
            $ct = $outZip->getFromName('[Content_Types].xml');
            if ($ct !== false) {
                $extContentType = [
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'bmp' => 'image/bmp',
                    'tif' => 'image/tiff',
                    'tiff' => 'image/tiff',
                    'svg' => 'image/svg+xml',
                ];
                $defaultsToAdd = '';
                foreach (array_keys($extensionsAdded) as $ext) {
                    if (!isset($extContentType[$ext])) {
                        continue;
                    }
                    $pattern = '/<Default\b[^>]*\bExtension="' . preg_quote($ext, '/') . '"/i';
                    if (!preg_match($pattern, $ct)) {
                        $defaultsToAdd .= '<Default Extension="' . $ext
                            . '" ContentType="' . $extContentType[$ext] . '"/>';
                    }
                }
                if ($defaultsToAdd !== '') {
                    $updatedCt = preg_replace(
                        '/(<Types\b[^>]*>)/',
                        '$1' . $defaultsToAdd,
                        $ct,
                        1
                    );
                    if (is_string($updatedCt) && $updatedCt !== $ct) {
                        $outZip->deleteName('[Content_Types].xml');
                        $outZip->addFromString('[Content_Types].xml', $updatedCt);
                    }
                }
            }
        }

        $result['xml'] = $mergedBodyXml;
        return $result;
    }
}

if (!function_exists('tarf_write_filled_official_docx')) {
    /**
     * @param array{form: array, row: array}|null $ntarfUniversalContext when set, applies ntarf/ + root NTARF placeholder fills before blocks
     * @param string|null $mergeOfficeOrderDocxFrom when set, append office-order document.xml after the main template body (TARF DISAPP + 2.2)
     */
    function tarf_write_filled_official_docx(
        string $templateAbs,
        string $outAbs,
        array $blockReplacements,
        array $entityReplacements,
        ?array $ntarfUniversalContext = null,
        ?string $mergeOfficeOrderDocxFrom = null
    ): bool {
        if (!is_file($templateAbs) || !is_readable($templateAbs)) {
            error_log('tarf_write_filled_official_docx: missing template ' . $templateAbs);
            return false;
        }
        $dir = dirname($outAbs);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('tarf_write_filled_official_docx: cannot mkdir ' . $dir);
            return false;
        }
        if (!copy($templateAbs, $outAbs)) {
            error_log('tarf_write_filled_official_docx: copy failed to ' . $outAbs);
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($outAbs) !== true) {
            error_log('tarf_write_filled_official_docx: ZipArchive::open failed ' . $outAbs);
            @unlink($outAbs);
            return false;
        }
        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            error_log('tarf_write_filled_official_docx: no word/document.xml');
            @unlink($outAbs);
            return false;
        }
        if ($mergeOfficeOrderDocxFrom !== null && is_readable($mergeOfficeOrderDocxFrom)) {
            $zOff = new ZipArchive();
            if ($zOff->open($mergeOfficeOrderDocxFrom) === true) {
                $xmlOffice = $zOff->getFromName('word/document.xml');
                $zOff->close();
                if ($xmlOffice !== false) {
                    $xml = tarf_travel_merge_document_body_xml($xml, $xmlOffice);
                    // Pull media + image rels from the office docx so the embedded signature
                    // (referenced by r:embed="rId…") actually resolves in the merged output.
                    $media = tarf_official_order_transplant_office_media(
                        $zip,
                        $mergeOfficeOrderDocxFrom,
                        $xml
                    );
                    $xml = $media['xml'];
                }
            }
        }
        if ($ntarfUniversalContext !== null
            && isset($ntarfUniversalContext['form'], $ntarfUniversalContext['row'])
            && is_array($ntarfUniversalContext['form'])
            && is_array($ntarfUniversalContext['row'])) {
            $xml = tarf_ntarf_apply_universal_xml_fill(
                $xml,
                $ntarfUniversalContext['form'],
                $ntarfUniversalContext['row']
            );
        }
        $xml = tarf_patch_official_order_docx_xml($xml, $blockReplacements, $entityReplacements);
        if (!$zip->deleteName('word/document.xml') || !$zip->addFromString('word/document.xml', $xml)) {
            $zip->close();
            error_log('tarf_write_filled_official_docx: could not update document.xml');
            @unlink($outAbs);
            return false;
        }
        $zip->close();
        return true;
    }
}

if (!function_exists('tarf_travel_all_docx_entity_values')) {
    /**
     * Plain-text values for <<token>> in the DISAPP 2.2D travel form + overlapping office-order tokens.
     *
     * @return array<string, string> keys are XML-entity form e.g. &lt;&lt;Name of Requester&gt;&gt;
     */
    function tarf_travel_all_docx_entity_values(PDO $db, array $row, array $form): array
    {
        require_once __DIR__ . '/tarf_form_options.php';
        require_once __DIR__ . '/tarf_workflow.php';

        $opts = tarf_get_form_options();
        $dash = tarf_official_order_nonempty(null);
        $notes = tarf_ntarf_docx_status_notes($db, $row, $form);

        $typeTravel = tarf_official_order_join_nonempty(' — ', '—', [
            $form['travel_purpose_type'] ?? '',
            $opts['travel_request_type'][$form['travel_request_type'] ?? ''] ?? '',
        ]);

        $depN = tarf_official_order_nonempty($form['date_departure'] ?? null);
        $retN = tarf_official_order_nonempty($form['date_return'] ?? null);
        $dateLine = $depN . ' to ' . $retN;

        $filedTs = !empty($form['submitted_at_server'])
            ? date('F j, Y g:i A', strtotime((string) $form['submitted_at_server']))
            : (!empty($row['created_at'])
                ? date('F j, Y g:i A', strtotime((string) $row['created_at']))
                : $dash);

        $id = (int) ($row['id'] ?? 0);
        $year = (int) ($row['serial_year'] ?? 0);
        $yearDisp = $year > 0 ? (string) $year : tarf_official_order_nonempty(null);
        $idDisp = $id > 0 ? (string) $id : $dash;
        $tarfNumYearLine = $idDisp . ' s. ' . $yearDisp;

        $ae = trim((string) ($form['applicable_endorser'] ?? ''));
        $endName = !empty($row['endorser_endorsed_by'])
            ? tarf_display_name_for_user((int) $row['endorser_endorsed_by'], $db) : '';
        $applicableLine = $ae;
        if ($applicableLine !== '' && $endName !== '') {
            $applicableLine .= "\n" . $endName;
        } elseif ($applicableLine === '' && $endName !== '') {
            $applicableLine = $endName;
        }
        if ($applicableLine === '') {
            $applicableLine = $dash;
        }

        $cosKey = $form['cos_jo'] ?? '';
        $cosLabel = isset($opts['cos_jo_options'][$cosKey]) ? $opts['cos_jo_options'][$cosKey] : $dash;

        $supportLines = [];
        foreach ($form['publicity'] ?? [] as $k) {
            if (isset($opts['publicity_support'][$k])) {
                $supportLines[] = $opts['publicity_support'][$k];
            }
        }
        if (!empty($form['publicity_other'])) {
            $supportLines[] = 'Other: ' . trim((string) $form['publicity_other']);
        }
        foreach ($form['support_travel'] ?? [] as $k) {
            if (isset($opts['travel_support'][$k])) {
                $supportLines[] = $opts['travel_support'][$k];
            }
        }
        if (!empty($form['support_travel_other'])) {
            $supportLines[] = 'Other: ' . trim((string) $form['support_travel_other']);
        }
        $requestedSupport = $supportLines !== [] ? implode("\n", $supportLines) : $dash;

        $fundingCharged = !empty($form['funding_charged_to'])
            ? (string) $form['funding_charged_to'] : $dash;
        $fundingSpec = tarf_official_order_nonempty($form['funding_specifier'] ?? null);
        $totalAmt = isset($form['total_estimated_amount']) && $form['total_estimated_amount'] !== ''
            ? (string) $form['total_estimated_amount'] : $dash;

        return [
            '&lt;&lt;TARF #&gt;&gt; s. 2026' => $tarfNumYearLine,
            '&lt;&lt;Person/s to Travel (Position)&gt;&gt;' => tarf_official_order_nonempty($form['persons_to_travel'] ?? null),
            '&lt;&lt;Final Approval&gt;&gt;' => 'authorized',
            '&lt;&lt;Date of Departure&gt;&gt; to &lt;&lt;Date of Return&gt;&gt;' => $dateLine,
            '&lt;&lt;Date of Departure&gt;&gt;' => $depN,
            '&lt;&lt;Date of Return&gt;&gt;' => $retN,
            '&lt;&lt;Event to Attend/Purpose of Travel&gt;&gt;' => tarf_official_order_nonempty($form['event_purpose'] ?? null),
            '&lt;&lt;Type of Travel Requested&gt;&gt;' => $typeTravel,
            '&lt;&lt;Name of Requester&gt;&gt;' => tarf_official_order_nonempty($form['requester_name'] ?? null),
            '&lt;&lt;College/Office/Project&gt;&gt;' => tarf_official_order_nonempty($form['college_office_project'] ?? null),
            '&lt;&lt;Timestamp&gt;&gt;' => $filedTs,
            '&lt;&lt;Justification/Explanation&gt;&gt;' => tarf_official_order_nonempty($form['justification'] ?? null),
            '&lt;&lt;Destination/s&gt;&gt;' => tarf_official_order_nonempty($form['destination'] ?? null),
            '&lt;&lt;TARF #&gt;&gt;' => $idDisp,
            '&lt;&lt;Applicable Endorser\'s Name&gt;&gt;' => $applicableLine,
            '&lt;&lt;Are any of these Persons to Travel COS or JO Status?&gt;&gt;' => $cosLabel,
            '&lt;&lt;Fund Availability Certified By&gt;&gt;' => $notes['fund_cert'],
            '&lt;&lt;Funding Charged to&gt;&gt;' => $fundingCharged,
            '&lt;&lt;Funding Specifier&gt;&gt;' => $fundingSpec,
            '&lt;&lt;Total Estimated Amount (IOT + LIB)&gt;&gt;' => $totalAmt,
            '&lt;&lt;Requested Support&gt;&gt;' => $requestedSupport,
            '&lt;&lt;Notes from Approver or Endorser&gt;&gt;' => $notes['approver'],
            '&lt;&lt;Notes re Fund Availability&gt;&gt;' => $notes['fund'],
        ];
    }
}

if (!function_exists('tarf_build_travel_official_order_replacements')) {
    /**
     * @return array{0: array<string,string>, 1: array<string,string>} [blocks, entities]
     */
    function tarf_build_travel_official_order_replacements(PDO $db, array $row, array $form): array
    {
        $id = (int) ($row['id'] ?? 0);
        $year = (int) ($row['serial_year'] ?? 0);
        $yearDisp = $year > 0 ? (string) $year : tarf_official_order_nonempty(null);
        $idDisp = $id > 0 ? (string) $id : tarf_official_order_nonempty(null);

        $blocks = [];

        $blocks['<w:r><w:rPr><w:rFonts w:ascii="Georgia" w:eastAsia="Georgia" w:hAnsi="Georgia" w:cs="Georgia"/><w:sz w:val="30"/><w:szCs w:val="30"/></w:rPr><w:t>No. &lt;&lt;</w:t></w:r><w:r><w:rPr><w:rFonts w:ascii="Georgia" w:eastAsia="Georgia" w:hAnsi="Georgia" w:cs="Georgia"/><w:sz w:val="30"/><w:szCs w:val="30"/><w:u w:val="single"/></w:rPr><w:t>TARF #</w:t></w:r><w:r><w:rPr><w:rFonts w:ascii="Georgia" w:eastAsia="Georgia" w:hAnsi="Georgia" w:cs="Georgia"/><w:sz w:val="30"/><w:szCs w:val="30"/></w:rPr><w:t>&gt;&gt;, s. 2026</w:t></w:r>'] =
            '<w:r><w:rPr><w:rFonts w:ascii="Georgia" w:eastAsia="Georgia" w:hAnsi="Georgia" w:cs="Georgia"/><w:sz w:val="30"/><w:szCs w:val="30"/></w:rPr><w:t>No. ' . tarf_official_order_xml_escape($idDisp) . ', s. ' . tarf_official_order_xml_escape($yearDisp) . '</w:t></w:r>';

        $blocks['<w:r><w:rPr><w:b/><w:bCs/><w:u w:val="single"/></w:rPr><w:t>&lt;&lt;</w:t></w:r><w:r><w:rPr><w:b/><w:bCs/><w:sz w:val="22"/><w:szCs w:val="22"/><w:u w:val="single"/></w:rPr><w:t>Destination/s</w:t></w:r><w:r><w:rPr><w:b/><w:bCs/><w:u w:val="single"/></w:rPr><w:t>&gt;&gt;</w:t></w:r>'] =
            '<w:r><w:rPr><w:b/><w:bCs/><w:u w:val="single"/></w:rPr><w:t>' . tarf_official_order_xml_escape(tarf_official_order_nonempty($form['destination'] ?? null)) . '</w:t></w:r>';

        $entities = tarf_travel_all_docx_entity_values($db, $row, $form);

        return [$blocks, $entities];
    }
}

if (!function_exists('tarf_build_ntarf_official_order_replacements')) {
    /**
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    function tarf_build_ntarf_official_order_replacements(PDO $db, array $row, array $form): array
    {
        $id = (int) ($row['id'] ?? 0);
        $year = (int) ($row['serial_year'] ?? 0);
        $yearDisp = $year > 0 ? (string) $year : tarf_official_order_nonempty(null);
        $idDisp = $id > 0 ? (string) $id : tarf_official_order_nonempty(null);

        $blocks = [];

        $blocks['<w:r><w:rPr><w:rFonts w:ascii="Georgia" w:eastAsia="Georgia" w:hAnsi="Georgia" w:cs="Georgia"/><w:sz w:val="30"/><w:szCs w:val="30"/></w:rPr><w:t xml:space="preserve">No. &lt;&lt;NTARF </w:t></w:r><w:r><w:rPr><w:rFonts w:ascii="Georgia" w:eastAsia="Georgia" w:hAnsi="Georgia" w:cs="Georgia"/><w:sz w:val="30"/><w:szCs w:val="30"/><w:u w:val="single"/></w:rPr><w:t>#</w:t></w:r><w:r><w:rPr><w:rFonts w:ascii="Georgia" w:eastAsia="Georgia" w:hAnsi="Georgia" w:cs="Georgia"/><w:sz w:val="30"/><w:szCs w:val="30"/></w:rPr><w:t>&gt;&gt;, s. 2026</w:t></w:r>'] =
            '<w:r><w:rPr><w:rFonts w:ascii="Georgia" w:eastAsia="Georgia" w:hAnsi="Georgia" w:cs="Georgia"/><w:sz w:val="30"/><w:szCs w:val="30"/></w:rPr><w:t>No. ' . tarf_official_order_xml_escape($idDisp) . ', s. ' . tarf_official_order_xml_escape($yearDisp) . '</w:t></w:r>';

        $blocks['<w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t>TO: &lt;&lt;Involved WPU Personnel (Position</w:t></w:r><w:r><w:rPr><w:b/><w:bCs/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr><w:t>)</w:t></w:r><w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t>&gt;&gt;</w:t></w:r>'] =
            '<w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t>TO: ' . tarf_official_order_xml_escape(tarf_ntarf_format_to_recipients($form)) . '</w:t></w:r>';

        $dateAct = tarf_official_order_nonempty($form['date_activity_start'] ?? null);
        $timeStart = tarf_official_order_nonempty($form['time_activity_start'] ?? null);
        $timeEnd = tarf_official_order_nonempty($form['time_activity_end'] ?? null);
        require_once __DIR__ . '/ntarf_form_options.php';
        $typeInvPlain = ntarf_format_involvement_display($form, ntarf_get_form_options());
        if ($typeInvPlain === '') {
            $typeInvPlain = trim((string) ($form['type_of_involvement'] ?? ''));
        }
        $typeInv = tarf_official_order_nonempty($typeInvPlain !== '' ? $typeInvPlain : null);

        // Word splits some <<placeholders>> across multiple <w:r> runs; replace whole sequences.
        $blocks['<w:t xml:space="preserve"> &lt;&lt;Date of Activity&gt;</w:t></w:r><w:proofErr w:type="gramStart"/><w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t>&gt;</w:t></w:r><w:r><w:t xml:space="preserve">  TO</w:t></w:r><w:proofErr w:type="gramEnd"/>'] =
            '<w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t xml:space="preserve"> ' . tarf_official_order_xml_escape($dateAct) . '  TO</w:t></w:r>';

        $blocks[' (&lt;&lt;</w:t></w:r><w:r><w:rPr><w:rFonts w:ascii="Roboto" w:eastAsia="Roboto" w:hAnsi="Roboto" w:cs="Roboto"/><w:b/><w:bCs/></w:rPr><w:t>Time of Activity Start&gt;&gt; - &lt;&lt;Time of Activity End&gt;</w:t></w:r><w:proofErr w:type="gramStart"/><w:r><w:rPr><w:rFonts w:ascii="Roboto" w:eastAsia="Roboto" w:hAnsi="Roboto" w:cs="Roboto"/><w:b/><w:bCs/></w:rPr><w:t xml:space="preserve">&gt;) </w:t></w:r>'] =
            '<w:r><w:rPr><w:rFonts w:ascii="Roboto" w:eastAsia="Roboto" w:hAnsi="Roboto" w:cs="Roboto"/><w:b/><w:bCs/></w:rPr><w:t xml:space="preserve"> (' . tarf_official_order_xml_escape($timeStart) . ' - ' . tarf_official_order_xml_escape($timeEnd) . ') </w:t></w:r>';

        $blocks['<w:r><w:t xml:space="preserve"> &lt;&lt;</w:t></w:r><w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t>Type of Involvement</w:t></w:r><w:r><w:t>&gt;&gt;.</w:t></w:r>'] =
            '<w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t xml:space="preserve"> ' . tarf_official_order_xml_escape($typeInv) . '.</w:t></w:r>';

        $entities = tarf_ntarf_all_docx_entity_values($db, $row, $form);

        return [$blocks, $entities];
    }
}

if (!function_exists('tarf_generate_filled_official_order_docx')) {
    /**
     * Writes uploads/tarf_official_orders/{id}_{travel|ntarf}.docx when status is endorsed.
     *
     * @return string|null relative path uploads/... or null on failure / wrong state
     */
    function tarf_generate_filled_official_order_docx(PDO $db, array $row): ?string
    {
        if (($row['status'] ?? '') !== 'endorsed') {
            return null;
        }
        $form = json_decode($row['form_data'] ?? '{}', true);
        if (!is_array($form)) {
            $form = [];
        }
        $formKind = $form['form_kind'] ?? '';
        if ($formKind === 'ntarf' || $formKind === 'tarf') {
            $form = tarf_enrich_requester_form_for_docx($db, $row, $form);
        }
        $isNtarf = $formKind === 'ntarf';
        $kind = $isNtarf ? 'ntarf' : 'travel';
        $rel = tarf_filled_official_order_rel_path((int) $row['id'], $kind);
        $outAbs = dirname(__DIR__) . '/' . $rel;

        if ($isNtarf) {
            [$blocks, $entities] = tarf_build_ntarf_official_order_replacements($db, $row, $form);
            $template = tarf_official_order_template_ntarf();
            $written = tarf_write_filled_official_docx($template, $outAbs, $blocks, $entities, ['form' => $form, 'row' => $row]);
        } else {
            [$blocks, $entities] = tarf_build_travel_official_order_replacements($db, $row, $form);
            $formTpl = tarf_official_order_template_travel_form_disapp();
            $officeTpl = tarf_official_order_template_travel_office_order();
            if (is_file($formTpl)) {
                $written = tarf_write_filled_official_docx($formTpl, $outAbs, $blocks, $entities, null, $officeTpl);
            } else {
                $written = tarf_write_filled_official_docx($officeTpl, $outAbs, $blocks, $entities, null, null);
            }
        }

        if (!$written) {
            return null;
        }
        return $rel;
    }
}

if (!function_exists('tarf_ensure_filled_official_order_docx')) {
    /**
     * Returns web-relative uploads/... path if the filled doc exists or was just generated.
     */
    function tarf_ensure_filled_official_order_docx(PDO $db, array $row): ?string
    {
        if (($row['status'] ?? '') !== 'endorsed') {
            return null;
        }
        $form = json_decode($row['form_data'] ?? '{}', true);
        if (!is_array($form)) {
            $form = [];
        }
        $kind = (($form['form_kind'] ?? '') === 'ntarf') ? 'ntarf' : 'travel';
        $rel = tarf_filled_official_order_rel_path((int) $row['id'], $kind);
        $abs = dirname(__DIR__) . '/' . $rel;
        if (is_file($abs)) {
            $logicPhp = __DIR__ . '/tarf_official_order_docx.php';
            $needsRegen = is_file($logicPhp) && filemtime($logicPhp) > filemtime($abs);
            if (!$needsRegen && $kind === 'ntarf') {
                $tpl = tarf_official_order_template_ntarf();
                $needsRegen = is_file($tpl) && filemtime($tpl) > filemtime($abs);
            }
            if (!$needsRegen && $kind === 'travel') {
                $tf = tarf_official_order_template_travel_form_disapp();
                $to = tarf_official_order_template_travel_office_order();
                $mt = max(
                    is_file($tf) ? filemtime($tf) : 0,
                    is_file($to) ? filemtime($to) : 0
                );
                $needsRegen = $mt > filemtime($abs);
            }
            if (!$needsRegen) {
                return $rel;
            }
        }
        $out = tarf_generate_filled_official_order_docx($db, $row);
        if ($out !== null) {
            return $out;
        }

        return is_file($abs) ? $rel : null;
    }
}

if (!function_exists('tarf_write_filled_official_pdf_from_docx')) {
    /**
     * Convert a filled official-order DOCX to PDF using PhpWord HTML → TCPDF.
     */
    function tarf_write_filled_official_pdf_from_docx(string $docxAbs, string $pdfAbs): bool
    {
        if (!is_file($docxAbs) || !is_readable($docxAbs)) {
            return false;
        }
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            error_log('tarf_write_filled_official_pdf_from_docx: missing vendor/autoload.php');
            return false;
        }
        require_once $autoload;

        $tcpdfDir = dirname(__DIR__) . '/vendor/tecnickcom/tcpdf';
        if (!is_file($tcpdfDir . '/tcpdf.php')) {
            error_log('tarf_write_filled_official_pdf_from_docx: TCPDF not found');
            return false;
        }

        $dir = dirname($pdfAbs);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('tarf_write_filled_official_pdf_from_docx: cannot mkdir ' . $dir);
            return false;
        }

        try {
            \PhpOffice\PhpWord\Settings::setPdfRendererPath($tcpdfDir);
            \PhpOffice\PhpWord\Settings::setPdfRendererName(\PhpOffice\PhpWord\Settings::PDF_RENDERER_TCPDF);
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($docxAbs);
            $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'PDF');
            $writer->save($pdfAbs);
        } catch (\Throwable $e) {
            error_log('tarf_write_filled_official_pdf_from_docx: ' . $e->getMessage());
            if (is_file($pdfAbs)) {
                @unlink($pdfAbs);
            }

            return false;
        }

        return is_file($pdfAbs) && (int) filesize($pdfAbs) > 0;
    }
}

if (!function_exists('tarf_sync_filled_official_order_pdf_from_docx_rel')) {
    /**
     * After a relative-path DOCX is written under uploads/, build/update the matching PDF.
     */
    function tarf_sync_filled_official_order_pdf_from_docx_rel(string $relDocx): bool
    {
        if ($relDocx === '' || !preg_match('/\.docx$/i', $relDocx)) {
            return false;
        }
        $root = dirname(__DIR__);
        $absDocx = $root . '/' . str_replace('\\', '/', $relDocx);
        if (!is_file($absDocx)) {
            return false;
        }
        $relPdf = preg_replace('/\.docx$/i', '.pdf', $relDocx);
        $absPdf = $root . '/' . str_replace('\\', '/', $relPdf);

        return tarf_write_filled_official_pdf_from_docx($absDocx, $absPdf);
    }
}

if (!function_exists('tarf_ensure_filled_official_order_pdf')) {
    /**
     * Web-relative path to the filled official form as PDF (endorsed requests only).
     */
    function tarf_ensure_filled_official_order_pdf(PDO $db, array $row): ?string
    {
        $relDocx = tarf_ensure_filled_official_order_docx($db, $row);
        if ($relDocx === null || $relDocx === '') {
            return null;
        }
        $root = dirname(__DIR__);
        $absDocx = $root . '/' . str_replace('\\', '/', $relDocx);
        if (!is_file($absDocx)) {
            return null;
        }
        $relPdf = preg_replace('/\.docx$/i', '.pdf', $relDocx);
        $absPdf = $root . '/' . str_replace('\\', '/', $relPdf);
        if (is_file($absPdf) && filemtime($absPdf) >= filemtime($absDocx)) {
            return $relPdf;
        }
        if (tarf_write_filled_official_pdf_from_docx($absDocx, $absPdf)) {
            return $relPdf;
        }

        return null;
    }
}
