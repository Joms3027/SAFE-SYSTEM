<?php
/**
 * HTML email bodies for Travel Activity Request Form (TARF) status notifications.
 * Used by Mailer::sendTarfRequestApprovedEmail / sendTarfRequestRejectedEmail.
 */

if (!function_exists('tarf_email_absolute_view_url')) {
    function tarf_email_absolute_view_url(int $tarfId): string
    {
        $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
        if ($base === '') {
            $base = 'http://localhost';
        }
        return $base . '/faculty/tarf_request_view.php?id=' . $tarfId;
    }
}

if (!function_exists('tarf_email_rejection_stage_label')) {
    function tarf_email_rejection_stage_label(string $stage): string
    {
        $map = [
            'supervisor' => 'your supervisor',
            'endorser' => 'the applicable endorser',
            'president' => 'the President (final approver)',
        ];
        $s = strtolower(trim($stage));
        return $map[$s] ?? 'the reviewer';
    }
}

if (!function_exists('tarf_email_wrap_layout')) {
    /**
     * @param string $accentColor CSS color for top bar / heading accent
     * @param string $title Plain title (escaped inside)
     * @param string $innerHtml Raw inner HTML (must be safe / already escaped)
     */
    function tarf_email_wrap_layout(string $accentColor, string $title, string $innerHtml): string
    {
        $site = defined('SITE_NAME') ? (string) SITE_NAME : 'WPU SAFE System';
        return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;line-height:1.55;color:#1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f1f5f9;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(15,23,42,0.08);border:1px solid #e2e8f0;">
          <tr>
            <td style="height:4px;background:' . $accentColor . ';"></td>
          </tr>
          <tr>
            <td style="padding:28px 28px 8px 28px;">
              <p style="margin:0 0 4px 0;font-size:13px;font-weight:600;letter-spacing:0.02em;color:#64748b;text-transform:uppercase;">Travel Activity Request (TARF)</p>
              <h1 style="margin:0;font-size:22px;font-weight:700;color:#0f172a;line-height:1.25;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 28px 28px 28px;font-size:15px;">
' . $innerHtml . '
            </td>
          </tr>
          <tr>
            <td style="padding:16px 28px 24px 28px;border-top:1px solid #e2e8f0;background:#f8fafc;">
              <p style="margin:0;font-size:12px;color:#64748b;line-height:1.5;">' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '<br>This is an automated message. Please do not reply to this email.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
    }
}

if (!function_exists('tarf_email_html_approved')) {
    /**
     * @param array<string, string|int|null> $vars recipient_name, tarf_id, serial_year, event_purpose, submitted_display, view_url
     */
    function tarf_email_html_approved(array $vars): string
    {
        $name = (string) ($vars['recipient_name'] ?? 'Employee');
        $id = (int) ($vars['tarf_id'] ?? 0);
        $year = isset($vars['serial_year']) ? (int) $vars['serial_year'] : 0;
        $purpose = trim((string) ($vars['event_purpose'] ?? ''));
        if ($purpose === '') {
            $purpose = '—';
        }
        $submitted = trim((string) ($vars['submitted_display'] ?? ''));
        $viewUrl = (string) ($vars['view_url'] ?? '');
        $refLine = $year > 0 ? 'TARF #' . $id . ' · Serial year ' . $year : 'TARF #' . $id;

        $inner = '
              <p style="margin:0 0 16px 0;">Hello <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
              <p style="margin:0 0 20px 0;">Your Travel Activity Request has been <strong style="color:#059669;">fully approved</strong> (final endorsement recorded). You can open the printable DISAPP-style layout from the portal anytime.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;margin-bottom:20px;">
                <tr><td style="padding:16px 18px;">
                  <p style="margin:0 0 8px 0;font-size:12px;font-weight:600;color:#166534;text-transform:uppercase;letter-spacing:0.04em;">Request summary</p>
                  <p style="margin:0 0 6px 0;"><strong>Reference:</strong> ' . htmlspecialchars($refLine, ENT_QUOTES, 'UTF-8') . '</p>
                  <p style="margin:0 0 6px 0;"><strong>Purpose / activity:</strong><br><span style="color:#334155;">' . nl2br(htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8')) . '</span></p>';
        if ($submitted !== '') {
            $inner .= '
                  <p style="margin:0;"><strong>Submitted:</strong> ' . htmlspecialchars($submitted, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $inner .= '
                </td></tr>
              </table>
              <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 8px auto;">
                <tr>
                  <td style="border-radius:8px;background:#003366;">
                    <a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">View / print request</a>
                  </td>
                </tr>
              </table>
              <p style="margin:0;font-size:13px;color:#64748b;">If the button does not work, copy this link into your browser:<br><span style="word-break:break-all;color:#475569;">' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '</span></p>';

        return tarf_email_wrap_layout('#059669', 'Your TARF has been approved', $inner);
    }
}

if (!function_exists('tarf_email_html_rejected')) {
    /**
     * @param array<string, string|int|null> $vars recipient_name, tarf_id, serial_year, event_purpose, submitted_display, view_url, rejection_reason, rejection_stage
     */
    function tarf_email_html_rejected(array $vars): string
    {
        $name = (string) ($vars['recipient_name'] ?? 'Employee');
        $id = (int) ($vars['tarf_id'] ?? 0);
        $year = isset($vars['serial_year']) ? (int) $vars['serial_year'] : 0;
        $purpose = trim((string) ($vars['event_purpose'] ?? ''));
        if ($purpose === '') {
            $purpose = '—';
        }
        $submitted = trim((string) ($vars['submitted_display'] ?? ''));
        $viewUrl = (string) ($vars['view_url'] ?? '');
        $reason = trim((string) ($vars['rejection_reason'] ?? ''));
        $stage = (string) ($vars['rejection_stage'] ?? '');
        $who = tarf_email_rejection_stage_label($stage);
        $refLine = $year > 0 ? 'TARF #' . $id . ' · Serial year ' . $year : 'TARF #' . $id;

        $reasonBlock = '';
        if ($reason !== '') {
            $reasonBlock = '
                  <p style="margin:12px 0 0 0;padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:14px;"><strong>Reason provided:</strong><br>' . nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        $inner = '
              <p style="margin:0 0 16px 0;">Hello <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
              <p style="margin:0 0 20px 0;">Your Travel Activity Request was <strong style="color:#dc2626;">not approved</strong>. It was declined by <strong>' . htmlspecialchars($who, ENT_QUOTES, 'UTF-8') . '</strong>.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;margin-bottom:16px;">
                <tr><td style="padding:16px 18px;">
                  <p style="margin:0 0 8px 0;font-size:12px;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:0.04em;">Request summary</p>
                  <p style="margin:0 0 6px 0;"><strong>Reference:</strong> ' . htmlspecialchars($refLine, ENT_QUOTES, 'UTF-8') . '</p>
                  <p style="margin:0 0 6px 0;"><strong>Purpose / activity:</strong><br><span style="color:#334155;">' . nl2br(htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8')) . '</span></p>';
        if ($submitted !== '') {
            $inner .= '
                  <p style="margin:0;"><strong>Submitted:</strong> ' . htmlspecialchars($submitted, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $inner .= $reasonBlock . '
                </td></tr>
              </table>
              <p style="margin:0 0 18px 0;">You may submit a new TARF through the faculty portal if you still need to request travel activity clearance.</p>
              <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 8px auto;">
                <tr>
                  <td style="border-radius:8px;background:#003366;">
                    <a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">Open request record</a>
                  </td>
                </tr>
              </table>
              <p style="margin:0;font-size:13px;color:#64748b;">If the button does not work, copy this link into your browser:<br><span style="word-break:break-all;color:#475569;">' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '</span></p>';

        return tarf_email_wrap_layout('#dc2626', 'Update on your TARF request', $inner);
    }
}
