<?php
/**
 * President (key official) — final TARF approval. Workflow: supervisor → applicable endorser
 * → pending_president → this user approves or rejects. employee_key_official_labels still scopes
 * extra rows on the president queue for reference (case-insensitive match on faculty_profiles.key_official).
 */
return [
    'viewer_user_id' => 26,
    'employee_key_official_labels' => [
        'PRESIDENT',
    ],
];
