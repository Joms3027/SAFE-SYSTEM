<?php
/**
 * Generates docs/Employee_Feedback_User_Manual.pdf
 * Run from project root: php tools/generate_feedback_manual_pdf.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('WPU Safe System');
$pdf->SetAuthor('WPU Safe System');
$pdf->SetTitle('Employee Feedback — Employee Manual');
$pdf->SetSubject('How to use the employee feedback feature');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(18, 18, 18);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();
$pdf->SetFont('dejavusans', '', 10);

$html = <<<'HTML'
<style>
  h1 { font-size: 18pt; color: #0a2540; margin-bottom: 6px; }
  .sub { color: #555; font-size: 10pt; margin-bottom: 14px; }
  h2 { font-size: 12pt; color: #003366; margin-top: 12px; margin-bottom: 4px; }
  p { margin: 4px 0; text-align: justify; }
  ul { margin: 4px 0 8px 1em; }
  li { margin-bottom: 3px; }
  .note { background: #f4f7fa; border-left: 3px solid #0066cc; padding: 6px 8px; margin: 8px 0; font-size: 9.5pt; }
  .fig { border: 1px solid #b8c7d8; border-radius: 4px; padding: 8px; margin: 8px 0 10px; }
  .fig-title { font-size: 9.5pt; color: #003366; font-weight: bold; margin-bottom: 5px; }
  .screen { border: 1px solid #d8e1ea; background: #fbfdff; padding: 7px; }
  .row { margin-bottom: 4px; }
  .icon { font-weight: bold; color: #0a2540; }
  .mini { color: #555; font-size: 9pt; }
  .box { border: 1px dashed #9ab0c6; padding: 3px 5px; margin-top: 3px; }
</style>

<h1>Employee Feedback Feature</h1>
<div class="sub"><b>Employee-only manual</b> — WPU Safe System Faculty/Staff Portal</div>

<p>This guide explains how employees (faculty/staff) can submit feedback using the in-account feedback module.</p>

<h2>1. Open the feedback page</h2>
<ol>
  <li>Sign in to the WPU Safe System with your employee account.</li>
  <li>In the sidebar, under <b>Home</b>, click <b>Employee feedback</b>.</li>
</ol>

<div class="fig">
  <div class="fig-title">Figure 1 — Sidebar navigation</div>
  <div class="screen mini">
    <div class="row"><span class="icon">⌂</span> Dashboard</div>
    <div class="row"><span class="icon">📢</span> Announcements</div>
    <div class="row"><span class="icon">📅</span> Calendar</div>
    <div class="row"><span class="icon">💬</span> <b>Employee feedback</b></div>
  </div>
</div>

<h2>2. Fill up the form</h2>
<ol>
  <li>Complete all required fields:
    <ul>
      <li><b>👤 Your name</b> — Optional. Clear this if you want to submit anonymously.</li>
      <li><b>⭐ Satisfaction rating</b> — Required. Select a rating from <b>1</b> to <b>5</b>.</li>
      <li><b>🏢 Department</b> — Required. Choose the related department.</li>
      <li><b>📝 Feedback</b> — Required. Enter your message (up to 10,000 characters).</li>
    </ul>
  </li>
  <li>Review your details before submitting.</li>
</ol>

<div class="fig">
  <div class="fig-title">Figure 2 — Feedback form layout</div>
  <div class="screen mini">
    <div class="box"><b>👤 Your name (optional)</b></div>
    <div class="box"><b>⭐ Satisfaction rating</b>: 1 2 3 4 5</div>
    <div class="box"><b>🏢 Department</b>: [ Select department ]</div>
    <div class="box"><b>📝 Feedback</b>: [ Write your message here ... ]</div>
    <div class="box"><b>➤ Submit feedback</b></div>
  </div>
</div>

<h2>3. Submit and confirmation</h2>
<ol>
  <li>Click <b>Submit feedback</b>.</li>
  <li>Wait for the success alert: <i>“Thank you. Your feedback has been submitted.”</i></li>
  <li>If you need to send another one, click <b>Submit another</b>.</li>
</ol>

<div class="fig">
  <div class="fig-title">Figure 3 — Successful submission</div>
  <div class="screen mini">
    <div class="box">✅ Thank you. Your feedback has been submitted.</div>
    <div class="box">[ Submit another ]</div>
  </div>
</div>

<div class="note"><b>Note:</b> If no departments are shown, contact your administrator to update the department master list. If submission fails due to system/database issues, contact IT support.</div>

<h2>4. Quick tips</h2>
<ul>
  <li>Use clear and specific feedback so your concern is easy to route.</li>
  <li>Choose a rating that best matches your overall satisfaction.</li>
  <li>Leave the name blank if you prefer anonymous feedback.</li>
</ul>

<p style="margin-top:16px;font-size:9pt;color:#666;">Document generated for the WPU Safe System employee feedback module.</p>
HTML;

$pdf->writeHTML($html, true, false, true, false, '');

$docsDir = $root . DIRECTORY_SEPARATOR . 'docs';
if (!is_dir($docsDir)) {
    if (!mkdir($docsDir, 0755, true) && !is_dir($docsDir)) {
        fwrite(STDERR, "Cannot create directory: $docsDir\n");
        exit(1);
    }
}

$path = $docsDir . DIRECTORY_SEPARATOR . 'Employee_Feedback_User_Manual.pdf';
$pdf->Output($path, 'F');

echo "Wrote: $path\n";
