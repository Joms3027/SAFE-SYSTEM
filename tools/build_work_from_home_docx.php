<?php
/**
 * One-off generator: faculty/WORK_FROM_HOME_PARDON.docx
 * Run: php tools/build_work_from_home_docx.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;

$root = __DIR__ . '/..';
$outFile = $root . '/faculty/WORK_FROM_HOME_PARDON.docx';
$imagePath = $root . '/assets/docs/work-from-home-pardon-flow.png';

$phpWord = new PhpWord();
$phpWord->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('en-US'));

$phpWord->addTitleStyle(1, ['size' => 22, 'bold' => true], ['spaceAfter' => 240]);
$phpWord->addTitleStyle(2, ['size' => 14, 'bold' => true], ['spaceBefore' => 240, 'spaceAfter' => 120]);

$phpWord->addNumberingStyle(
    'bullet',
    [
        'type'   => 'multilevel',
        'levels' => [
            ['format' => 'bullet', 'text' => '•', 'left' => 360, 'hanging' => 360, 'tabPos' => 360],
        ],
    ]
);
$phpWord->addNumberingStyle(
    'numbered',
    [
        'type'   => 'multilevel',
        'levels' => [
            ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360, 'tabPos' => 360, 'start' => 1],
        ],
    ]
);
$phpWord->addNumberingStyle(
    'numbered_cont',
    [
        'type'   => 'multilevel',
        'levels' => [
            ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360, 'tabPos' => 360, 'start' => 1],
        ],
    ]
);
$phpWord->addNumberingStyle(
    'dash_sub',
    [
        'type'   => 'multilevel',
        'levels' => [
            ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360, 'tabPos' => 360],
            ['format' => 'bullet', 'text' => '–', 'left' => 720, 'hanging' => 360, 'tabPos' => 720],
        ],
    ]
);

$section = $phpWord->addSection();

$section->addTitle('How to pardon Work from Home (WFH)', 1);

$section->addText(
    'This guide follows the SAFE Faculty workflow: you start by requesting a pardon (letter to your assigned pardon opener), '
    . 'then—after pardon is opened for your anchor date—you submit a Work from Home pardon from Attendance Logs with the times and documents HR needs.',
    ['size' => 11]
);

$section->addTextBreak(1);

if (is_readable($imagePath)) {
    $section->addText('Overview', ['bold' => true, 'size' => 11]);
    $section->addImage($imagePath, [
        'width' => \PhpOffice\PhpWord\Shared\Converter::cmToPixel(14),
        'alignment' => Jc::CENTER,
    ]);
    $section->addTextBreak(1);
}

$hr = function () use ($section): void {
    $section->addText('________________________________________________________________', ['color' => 'CCCCCC', 'size' => 8]);
    $section->addTextBreak(1);
};

$hr();
$section->addTitle('Before you begin', 2);
$section->addListItem('Request Pardon appears in the sidebar only if your account is in the pardon scope. If you do not see it, contact HR so pardon opener assignments can be set for your department or designation.', 0, null, 'bullet', ['size' => 11]);
$section->addListItem('You need a valid SAFE Employee ID on your profile.', 0, null, 'bullet', ['size' => 11]);

$hr();
$section->addTitle('Step 1 — Request a pardon (letter first)', 2);
$section->addListItem('Sign in to the Faculty portal.', 0, null, 'numbered', ['size' => 11]);
$section->addListItem('Open Request Pardon in the sidebar (file-signature icon).', 0, null, 'numbered', ['size' => 11]);
$section->addListItem('Read who will receive your request (names shown at the top).', 0, null, 'numbered', ['size' => 11]);
$section->addListItem('Optional: click Download Template and complete the official Request for Pardon document.', 0, null, 'numbered', ['size' => 11]);
$section->addListItem('Under Day to be Pardoned, choose the date you need pardon for (this is the main day your supervisor must act on).', 0, null, 'numbered', ['size' => 11]);
$section->addListItem('Upload your request letter as PDF (you can attach multiple PDFs, up to 10).', 0, null, 'numbered', ['size' => 11]);
$section->addListItem('Click Submit Request.', 0, null, 'numbered', ['size' => 11]);
$section->addTextBreak(1);
$section->addText(
    'Your assigned pardon openers are notified. They review the letter and, when appropriate, open pardon for that date so you can complete the DTR pardon request.',
    ['size' => 11]
);
$section->addTextBreak(1);
$section->addText(
    'Statuses you may see: Pending → Opened (you can proceed) / Rejected (read the reason and contact your opener if needed).',
    ['size' => 11, 'bold' => true]
);

$hr();
$section->addTitle('Step 2 — Wait until pardon is opened for that date', 2);
$section->addListItem('Your immediate supervisor / pardon opener must open pardon for the same date you chose in Step 1 (the row you will use in Attendance Logs).', 0, null, 'bullet', ['size' => 11]);
$section->addListItem('Extra days: If your Work from Home request will cover more than one calendar day, your opener only needs to open pardon for the one day you start from (the “anchor” day). Other dates can be added in the calendar in Step 3, as explained on screen.', 0, null, 'bullet', ['size' => 11]);
$section->addTextBreak(1);
$section->addText(
    'Until pardon is opened for that date, the action button on that day’s log stays disabled (tooltip explains that your supervisor must open pardon first).',
    ['size' => 11]
);

$hr();
$section->addTitle('Step 3 — Submit Work from Home on Attendance Logs', 2);
$section->addListItem('Open Attendance Logs in the sidebar.', 0, null, 'numbered_cont', ['size' => 11]);
$section->addListItem('Find the date that has pardon opened (same as your letter, unless you were told otherwise).', 0, null, 'numbered_cont', ['size' => 11]);
$section->addListItem('Click the Submit Pardon Request control on that row (when pardon is open, it is enabled).', 0, null, 'numbered_cont', ['size' => 11]);
$section->addListItem('In Submit Pardon Request:', 0, null, 'numbered_cont', ['size' => 11]);
$section->addListItem('Type of Pardon: choose Work from Home (dropdown on desktop, or the Work from Home button on small screens).', 1, null, 'dash_sub', ['size' => 11]);
$section->addListItem('Time In and Time Out are required. Enter Lunch Out and Lunch In if they apply. These are the times that will apply when the request is approved; for multiple days, the same times apply to every selected day.', 1, null, 'dash_sub', ['size' => 11]);
$section->addListItem('Days included: use the calendar to include every date this WFH request should cover. The day you opened from is highlighted in blue and stays included. You cannot select dates that already have another pending or approved pardon.', 1, null, 'dash_sub', ['size' => 11]);
$section->addListItem('Justification: required—explain why you need this pardon.', 1, null, 'dash_sub', ['size' => 11]);
$section->addListItem('Supporting files: at least one file is required (PDF, DOC, DOCX, JPG, or PNG; max 5 MB per file).', 1, null, 'dash_sub', ['size' => 11]);
$section->addListItem('Click Submit Request.', 0, null, 'numbered_cont', ['size' => 11]);
$section->addTextBreak(1);
$section->addText(
    'Admin/HR reviews the request. Your DTR updates only after approval.',
    ['size' => 11]
);

$hr();
$section->addTitle('Quick reference', 2);
$table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999']);
$table->addRow(null, ['tblHeader' => true]);
foreach (['Stage', 'Where in SAFE', 'What you do'] as $h) {
    $table->addCell(2200)->addText($h, ['bold' => true, 'size' => 10]);
}
$rows = [
    ['1', 'Request Pardon', 'PDF letter + date → submit'],
    ['2', '(Supervisor)', 'Opens pardon for your anchor date'],
    ['3', 'Attendance Logs → Submit Pardon Request', 'Type = Work from Home, times, days, justification, attachments'],
];
foreach ($rows as $r) {
    $table->addRow();
    foreach ($r as $cell) {
        $table->addCell(2200)->addText($cell, ['size' => 10]);
    }
}

$hr();
$section->addTitle('Troubleshooting', 2);
$section->addListItem('“Your supervisor must open pardon for this date” — Step 2 is not done yet for that log date; follow up with your pardon opener.', 0, null, 'bullet', ['size' => 11]);
$section->addListItem('Cannot add a date on the calendar — It may already have a pending or approved pardon, or the UI is preventing a conflict; pick eligible days only.', 0, null, 'bullet', ['size' => 11]);
$section->addListItem('Time In / Time Out required — Work from Home always requires both, unlike pure leave types.', 0, null, 'bullet', ['size' => 11]);
$section->addTextBreak(1);
$section->addText(
    'For system setup issues (missing Request Pardon feature, database messages), contact your administrator.',
    ['size' => 11]
);

$objWriter = IOFactory::createWriter($phpWord, 'Word2007');
$objWriter->save($outFile);

echo "Wrote: $outFile\n";
