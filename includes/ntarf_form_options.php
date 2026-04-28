<?php
/**
 * Options for [NON-TRAVEL] Activity Request Form (NTARF) — Google Form 3.1.
 */
if (!function_exists('ntarf_get_form_options')) {
    function ntarf_get_form_options(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        require_once __DIR__ . '/tarf_form_options.php';
        require_once __DIR__ . '/ntarf_google_form_choices.php';
        $tarf = tarf_get_form_options();

        $operationalSupport = [
            'approval' => 'Approval',
            'funding' => 'Funding',
            'venue_electricity_free' => 'Free - Use of Venue, Equipment, & Electricity',
            'venue_electricity_fees' => 'With Fees - Use of Venue, Equipment, & Electricity',
        ];

        $publicitySupport = [
            'na' => 'N/A',
            'publicity_material' => 'Creation and Publication of Publicity Material',
            'activity_coverage' => 'Activity Coverage and Publication (Please include 1 Info Staff in Travel Arrangements)',
            'publication_web' => 'Publication in the University Website and Social Media',
            'president_message' => 'Presence/Message from the President',
            'livestreaming' => 'Livestreaming',
        ];

        $involvementTypes = [
            'attendee' => 'Attendee',
            'organizer_facilitator' => 'Organizer/Facilitator',
            'resource_speaker' => 'Resource Speaker',
            'adviser' => 'Adviser',
            'presenter' => 'Presenter',
        ];

        $ntarfFundingSpecifiers = [
            'GASS',
            'Auxiliary',
            'Higher Ed',
            'Advanced Ed',
            'Research',
            'Extension',
        ];

        $cache = [
            'colleges' => $tarf['colleges'],
            'endorsers' => $tarf['endorsers'],
            'funding_charged' => $tarf['funding_charged'],
            'funding_specifiers' => $ntarfFundingSpecifiers,
            'fund_endorser_role' => $tarf['fund_endorser_role'],
            'activity_campuses' => ntarf_google_activity_campuses(),
            'venue_sites' => ntarf_google_venue_sites(),
            'endorser_venue_availability' => ntarf_google_endorser_venue_availability(),
            'endorser_electricity' => ntarf_google_endorser_electricity(),
            'involvement_types' => $involvementTypes,
            /** Google Q15 — operational (check all that apply). */
            'requested_support' => $operationalSupport,
            /** Google Q16 — support requested / publicity (check all that apply). */
            'publicity_support' => $publicitySupport,
            /**
             * Legacy travel-form keys stored on older NTARF rows (before Google 3.1 alignment).
             *
             * @var array<string, string>
             */
            'requested_support_legacy' => $tarf['travel_support'],
        ];

        return $cache;
    }
}

if (!function_exists('ntarf_total_amount_requires_funding_detail')) {
    /**
     * Legacy helper: infer funding detail from amount alone (submissions without university_funding_requested).
     */
    function ntarf_total_amount_requires_funding_detail(string $raw): bool
    {
        $t = trim($raw);
        if ($t === '') {
            return false;
        }
        if (preg_match('/^0+(?:\.0+)?$/', $t)) {
            return false;
        }
        if (preg_match('/^(n\/a|n\.a\.|na|none)$/i', $t)) {
            return false;
        }
        if ($t === '—' || $t === '-') {
            return false;
        }

        return true;
    }
}

if (!function_exists('ntarf_compose_venue_display_line')) {
    /**
     * Single line for Word / DISAPP “Venue” from structured fields (or legacy string).
     */
    function ntarf_compose_venue_display_line(array $form): string
    {
        if (!empty($form['venue_site']) || !empty($form['activity_campus'])) {
            $parts = [];
            $campus = trim((string) ($form['activity_campus'] ?? ''));
            if ($campus !== '') {
                if ($campus === 'OUTSIDE THE CAMPUS') {
                    $co = trim((string) ($form['activity_campus_other'] ?? ''));
                    $parts[] = $co !== '' ? ($campus . ' — ' . $co) : $campus;
                } else {
                    $parts[] = $campus;
                }
            }
            $vs = trim((string) ($form['venue_site'] ?? ''));
            if ($vs === '__other__') {
                $vo = trim((string) ($form['venue_site_other'] ?? ''));
                if ($vo !== '') {
                    $parts[] = $vo;
                }
            } elseif ($vs !== '') {
                $parts[] = $vs;
            }

            return implode(' · ', array_filter($parts));
        }

        return trim((string) ($form['venue'] ?? ''));
    }
}

if (!function_exists('ntarf_format_involvement_display')) {
    function ntarf_format_involvement_display(array $form, array $opts): string
    {
        $lines = [];
        $keys = $form['involvement_types'] ?? [];
        if (is_array($keys) && $keys !== []) {
            $map = $opts['involvement_types'] ?? [];
            foreach ($keys as $k) {
                $k = (string) $k;
                if (isset($map[$k])) {
                    $lines[] = $map[$k];
                }
            }
            if (!empty($form['involvement_other'])) {
                $lines[] = 'Other: ' . trim((string) $form['involvement_other']);
            }

            return implode(', ', array_filter($lines));
        }

        return trim((string) ($form['type_of_involvement'] ?? ''));
    }
}

if (!function_exists('ntarf_ntarf_support_display_lines')) {
    /**
     * Human-readable lines for operational + publicity supports (legacy-aware).
     *
     * @return list<string>
     */
    function ntarf_ntarf_support_display_lines(array $form, array $opts): array
    {
        $lines = [];
        foreach ($form['ntarf_support'] ?? [] as $k) {
            $k = (string) $k;
            if (isset($opts['requested_support'][$k])) {
                $lines[] = $opts['requested_support'][$k];
            } elseif (isset($opts['requested_support_legacy'][$k])) {
                $lines[] = $opts['requested_support_legacy'][$k];
            }
        }
        if (!empty($form['ntarf_support_other'])) {
            $lines[] = 'Other: ' . trim((string) $form['ntarf_support_other']);
        }
        foreach ($form['publicity'] ?? [] as $k) {
            $k = (string) $k;
            if (isset($opts['publicity_support'][$k])) {
                $lines[] = $opts['publicity_support'][$k];
            }
        }
        if (!empty($form['publicity_other'])) {
            $lines[] = 'Other: ' . trim((string) $form['publicity_other']);
        }

        return $lines;
    }
}