<?php
/**
 * Options for [NON-TRAVEL] Activity Request Form (NTARF) — aligns with traf_docx NTARF template / Google Form 3.1.
 */
if (!function_exists('ntarf_get_form_options')) {
    function ntarf_get_form_options(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        require_once __DIR__ . '/tarf_form_options.php';
        $tarf = tarf_get_form_options();
        // Same checkbox set as “Requested Support” on the official form (check all that apply).
        $cache = [
            'colleges' => $tarf['colleges'],
            'endorsers' => $tarf['endorsers'],
            'funding_charged' => $tarf['funding_charged'],
            'funding_specifiers' => $tarf['funding_specifiers'],
            'fund_endorser_role' => $tarf['fund_endorser_role'],
            'requested_support' => $tarf['travel_support'],
        ];
        return $cache;
    }
}
