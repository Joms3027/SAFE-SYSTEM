<?php
/**
 * Map each "Applicable endorser" form label to a portal user ID (users.id).
 * Required for TARF routing after supervisor endorsement.
 *
 * Example: 'Dr. Romeo R. Lerom, VPAA' => 42
 * Use null until the account exists; submission will be blocked for that endorser.
 */
return [
    // Keys must match includes/tarf_form_options.php $endorsers exactly (submitted as applicable_endorser).
    'Dr. Romeo R. Lerom, VPAA' => 346,
    'Dr. Ria S. Sariego, VPAF' => 528,
    'Dr. Lota A. Creencia, VPRIDE' => 55,
    'Dr. Lita B. Sopsop, VPSAS' => 491,
    'Dr. Amabel S. Liao, University President' => 349,
];
