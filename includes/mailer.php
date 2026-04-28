<?php
/**
 * Simple Mailer compatibility shim.
 *
 * Provides a minimal `Mailer` class so existing code that expects
 * `$mailer = new Mailer()` continues to work. It will try to use
 * PHPMailer (via Composer autoload) if available, otherwise fall back
 * to PHP's mail() and logging.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class Mailer {
    private $usePHPMailer = false;
    private $logPath;
    public $lastError = '';

    public function __construct() {
        $this->logPath = dirname(__DIR__) . '/storage/logs/mailer.log';

        // Try to manually load PHPMailer if not already loaded
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $phpmailerPath = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
            if (file_exists($phpmailerPath . 'PHPMailer.php')) {
                require_once $phpmailerPath . 'PHPMailer.php';
                require_once $phpmailerPath . 'SMTP.php';
                require_once $phpmailerPath . 'Exception.php';
            } else {
                // Try Composer autoload as fallback
                $autoload = __DIR__ . '/../vendor/autoload.php';
                if (file_exists($autoload)) {
                    require_once $autoload;
                }
            }
        }
        
        // Check if PHPMailer is now available
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $this->usePHPMailer = true;
        }
    }

    private function log($level, $message, $context = []) {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $entry = [
            'ts' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
        file_put_contents($this->logPath, json_encode($entry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Send a generic email. Returns true on success, false on failure.
     */
    public function sendMail($to, $toName, $subject, $body, $isHtml = false) {
        // Check if system is in offline mode
        if (defined('OFFLINE_MODE') && OFFLINE_MODE === true) {
            $this->log('info', 'Email sending disabled (OFFLINE_MODE=true); email dropped', ['to' => $to, 'subject' => $subject]);
            return false;
        }
        
        // Respect global ENABLE_MAIL flag in config. If disabled, do not attempt to send via PHP mail() or PHPMailer.
        if (!defined('ENABLE_MAIL') || ENABLE_MAIL !== true) {
            $this->log('info', 'Email sending disabled (ENABLE_MAIL=false); email dropped', ['to' => $to, 'subject' => $subject]);
            return false;
        }

        // Try PHPMailer if available and ENABLE_MAIL is true
        if ($this->usePHPMailer) {
            try {
                $mail = new PHPMailer(true);
                if (defined('SMTP_HOST') && SMTP_HOST) {
                    $mail->isSMTP();
                    $mail->Host = SMTP_HOST;
                    $mail->Port = SMTP_PORT ?? 587;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    if (defined('SMTP_USER') && SMTP_USER) {
                        $mail->SMTPAuth = true;
                        $mail->Username = SMTP_USER;
                        $mail->Password = SMTP_PASS;
                    }
                    $mail->Timeout = 30;
                }

                $smtpDebug = '';
                $mail->SMTPDebug = 2;
                $mail->Debugoutput = function ($str, $level) use (&$smtpDebug) {
                    $smtpDebug .= trim($str) . "\n";
                };

                $mail->setFrom(defined('SMTP_FROM') ? SMTP_FROM : 'noreply@example.com', defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'WPU System');
                $mail->addAddress($to, $toName ?: '');
                $mail->Subject = $subject;
                if ($isHtml) {
                    $mail->isHTML(true);
                    $mail->Body = $body;
                    $mail->AltBody = strip_tags($body);
                } else {
                    $mail->Body = $body;
                }
                $mail->send();
                $this->log('info', 'Email sent via PHPMailer', ['to' => $to, 'subject' => $subject]);
                return true;
            } catch (PHPMailerException $e) {
                $debugTail = $smtpDebug ? substr($smtpDebug, -1000) : '';
                $this->log('error', 'PHPMailer send failed: ' . $e->getMessage(), ['to' => $to, 'smtp_debug' => $debugTail]);
                $this->lastError = $e->getMessage();
                return false;
            } catch (\Exception $e) {
                $debugTail = isset($smtpDebug) ? substr($smtpDebug, -1000) : '';
                $this->log('error', 'PHPMailer unexpected error: ' . $e->getMessage(), ['to' => $to, 'smtp_debug' => $debugTail]);
                $this->lastError = $e->getMessage();
                return false;
            }
        }

        // Fallback: Try to use PHP's built-in mail() function with SMTP
        // This requires XAMPP's sendmail or similar to be configured
        try {
            $headers = "From: " . (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'WPU System') . " <" . (defined('SMTP_FROM') ? SMTP_FROM : 'noreply@example.com') . ">\r\n";
            $headers .= "Reply-To: " . (defined('SMTP_FROM') ? SMTP_FROM : 'noreply@example.com') . "\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
            
            if ($isHtml) {
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            } else {
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            }
            
            $success = mail($to, $subject, $body, $headers);
            
            if ($success) {
                $this->log('info', 'Email sent via PHP mail()', ['to' => $to, 'subject' => $subject]);
                return true;
            } else {
                $this->log('error', 'PHP mail() function returned false', ['to' => $to, 'subject' => $subject]);
                return false;
            }
        } catch (Exception $e) {
            $this->log('error', 'Failed to send email: ' . $e->getMessage(), ['to' => $to, 'subject' => $subject]);
            return false;
        }
    }

    /**
     * Send requirement notification (used by admin/requirements.php)
     */
    public function sendRequirementNotification($email, $name, $title, $deadline) {
        $subject = "New Requirement Assigned: " . $title;
        
        $displayName = $name ?: 'Faculty';
        $displayDeadline = $deadline ? date('F j, Y g:i A', strtotime($deadline)) : 'No deadline specified';
        
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">New Requirement Assigned</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>A new requirement has been assigned to you:</p>
    <p><strong>Requirement:</strong> ' . htmlspecialchars($title) . '</p>
    <p><strong>Deadline:</strong> ' . htmlspecialchars($displayDeadline) . '</p>
    <p>Please login to the faculty portal to submit the requirement.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE Staff System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';

        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send deadline reminder notification
     */
    public function sendDeadlineReminder($email, $name, $title, $deadline, $daysLeft) {
        $subject = "Reminder: Upcoming Deadline - " . $title;
        
        $displayName = $name ?: 'Faculty';
        $displayDeadline = date('F j, Y g:i A', strtotime($deadline));
        
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">Deadline Reminder</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>This is a reminder about your upcoming deadline:</p>
    <p><strong>Requirement:</strong> ' . htmlspecialchars($title) . '</p>
    <p><strong>Deadline:</strong> ' . htmlspecialchars($displayDeadline) . '</p>
    <p><strong>Time Remaining:</strong> ' . htmlspecialchars($daysLeft) . ' day(s)</p>
    <p>Please ensure you submit your requirement before the deadline.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE Staff System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';

        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send overdue notice notification
     */
    public function sendOverdueNotice($email, $name, $title, $deadline) {
        $subject = "Overdue Requirement: " . $title;
        
        $displayName = $name ?: 'Faculty';
        $displayDeadline = date('F j, Y g:i A', strtotime($deadline));
        
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">Overdue Requirement</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>This is a notice that the following requirement is now overdue:</p>
    <p><strong>Requirement:</strong> ' . htmlspecialchars($title) . '</p>
    <p><strong>Original Deadline:</strong> ' . htmlspecialchars($displayDeadline) . '</p>
    <p>Please submit this requirement as soon as possible.</p>
    <p style="color: #666; font-style: italic;">If you have already submitted this requirement, please disregard this message.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE Staff System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';

        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send DTR submission reminder on 10th and 25th (only days when DTR can be submitted)
     * @param string $email Recipient email
     * @param string $name Recipient name
     * @param string $periodLabel e.g. "1st–15th" or "16th–25th"
     * @param string $submitDate e.g. "February 10" or "February 25"
     */
    public function sendDTRReminderEmail($email, $name, $periodLabel, $submitDate) {
        $subject = "DTR Submission Deadline Today – " . $submitDate;
        $displayName = $name ?: 'Employee';
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">DTR Submission Deadline – Today</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>This is a reminder that <strong>today (' . htmlspecialchars($submitDate) . ') is the deadline</strong> for submitting your Daily Time Record (DTR) to the Dean and Admin.</p>
    <p><strong>Period:</strong> ' . htmlspecialchars($periodLabel) . ' of the current month</p>
    <p><strong>Deadline:</strong> ' . htmlspecialchars($submitDate) . ' (today)</p>
    <p>Please log in to the faculty portal and submit your DTR from the View Attendance Logs page. If you miss today\'s deadline, you will no longer be able to submit for this period.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE Staff System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';
        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send DTR deadline advance notice (day before submission date: 9th and 24th)
     * @param string $email Recipient email
     * @param string $name Recipient name
     * @param string $periodLabel e.g. "1st–15th" or "16th–25th"
     * @param string $deadlineDate e.g. "February 10" or "February 25"
     */
    public function sendDTRDeadlineReminderEmail($email, $name, $periodLabel, $deadlineDate) {
        $subject = "Reminder: DTR Submission Deadline Tomorrow – " . $deadlineDate;
        $displayName = $name ?: 'Employee';
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">DTR Submission Deadline Reminder</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>This is a reminder that <strong>tomorrow (' . htmlspecialchars($deadlineDate) . ') is the deadline</strong> for submitting your Daily Time Record (DTR) to the Dean and Admin.</p>
    <p><strong>Period:</strong> ' . htmlspecialchars($periodLabel) . ' of the current month</p>
    <p><strong>Deadline:</strong> ' . htmlspecialchars($deadlineDate) . ' (tomorrow)</p>
    <p>Please log in to the faculty portal tomorrow and submit your DTR from the View Attendance Logs page. DTR can only be submitted on the 10th and 25th of each month. If you miss the deadline, you will no longer be able to submit for this period.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE Staff System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';
        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Build the HTML body for announcement notification (for batch/keepAlive use).
     */
    public function buildAnnouncementEmailBody($name, $title, $content) {
        $displayName = $name ?: 'Faculty';
        $cleanContent = strip_tags($content);
        $formattedContent = nl2br(htmlspecialchars($cleanContent));
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">New Announcement</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>A new announcement has been posted:</p>
    <p><strong>' . htmlspecialchars($title) . '</strong></p>
    <div style="margin: 15px 0;">' . $formattedContent . '</div>
    <p>Please login to the faculty portal to view full details.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';
    }

    /**
     * Send announcement notification
     */
    public function sendAnnouncementNotification($email, $name, $title, $content) {
        $subject = "New Announcement: " . $title;
        $body = $this->buildAnnouncementEmailBody($name, $title, $content);
        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send submission status notification
     */
    public function sendSubmissionStatusNotification($email, $name, $title, $status, $remarks = '') {
        $statusText = ucfirst($status);
        $subject = "Submission " . $statusText . ": " . $title;
        
        $displayName = $name ?: 'Faculty';
        
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">Submission Reviewed</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>Your submission has been reviewed:</p>
    <p><strong>Submission:</strong> ' . htmlspecialchars($title) . '</p>
    <p><strong>Status:</strong> ' . htmlspecialchars($statusText) . '</p>';
        
        if ($remarks) {
            $body .= '
    <p><strong>Remarks:</strong></p>
    <p style="white-space: pre-wrap;">' . htmlspecialchars($remarks) . '</p>';
        }
        
        $body .= '
    <p>Please login to the faculty portal to view more details.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';

        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Build the HTML body for the account creation / credentials email.
     * Extracted so it can be reused for both single sends and bulk keepAlive sends.
     */
    public function buildAccountCreationEmailBody($email, $name, $accountType, $employeeId, $password) {
        $displayName     = $name        ?: 'Faculty/Staff';
        $accountTypeLabel = ucfirst($accountType ?: 'faculty');

        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">Welcome to WPU SAFE SYSTEM</h2>
    <p style="margin: 15px 0; line-height: 1.8;">The WPU SAFE System (Staff and Faculty E-Systems) is digital platform designed to modernize and streamline the administrative and academic operations of Western Philippines University (WPU). It aims to support the university\'s commitment to sustainable development by transforming manual, paper-based processes into secure, efficient, and automated electronic systems.</p>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>Your ' . htmlspecialchars($accountTypeLabel) . ' account has been successfully created. Below are your login credentials:</p>
    <div style="background-color: #f5f5f5; padding: 15px; margin: 20px 0; border: 1px solid #ddd;">
        <p><strong>Email Address:</strong><br>' . htmlspecialchars($email) . '</p>
        <p><strong>Password:</strong><br>' . htmlspecialchars($password) . '</p>
        <p><strong>Safe Employee ID:</strong><br>' . htmlspecialchars($employeeId) . '</p>
    </div>
    <div style="text-align: center; margin: 30px 0;">
        <a href="http://safe.wpu.edu.ph/login" style="display: inline-block; background-color: #007bff; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 5px; font-weight: bold; font-size: 16px;">Access WPU SAFE SYSTEM</a>
    </div>
    <p style="background-color: #fff3cd; padding: 10px; border-left: 3px solid #ffc107; margin: 20px 0;"><strong>Security Notice:</strong> For security reasons, we strongly recommend changing your password after your first login.</p>
    <p>If you have any questions or need assistance, please contact the system administrator.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';
    }

    /**
     * Send account creation notification with credentials
     */
    public function sendAccountCreationEmail($email, $name, $accountType, $employeeId, $password, $loginUrl) {
        $subject = "Welcome to WPU SAFE SYSTEM - Your Account Credentials";
        $body    = $this->buildAccountCreationEmailBody($email, $name, $accountType, $employeeId, $password);
        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send multiple emails over a single persistent SMTP connection.
     *
     * Each element of $messages must have:
     *   'to'      string  recipient email
     *   'toName'  string  recipient display name
     *   'subject' string
     *   'body'    string  HTML or plain body
     *   'isHtml'  bool    (optional, default false)
     *
     * Returns ['sent' => [emails...], 'failed' => [emails...]]
     */
    public function sendMailKeepAlive(array $messages) {
        $sent   = [];
        $failed = [];

        if (empty($messages)) {
            return ['sent' => $sent, 'failed' => $failed];
        }

        if (defined('OFFLINE_MODE') && OFFLINE_MODE === true) {
            foreach ($messages as $m) {
                $this->log('info', 'Email dropped (OFFLINE_MODE)', ['to' => $m['to']]);
                $failed[] = $m['to'];
            }
            return ['sent' => $sent, 'failed' => $failed];
        }

        if (!defined('ENABLE_MAIL') || ENABLE_MAIL !== true) {
            foreach ($messages as $m) {
                $this->log('info', 'Email dropped (ENABLE_MAIL=false)', ['to' => $m['to']]);
                $failed[] = $m['to'];
            }
            return ['sent' => $sent, 'failed' => $failed];
        }

        // PHPMailer keepAlive path
        if ($this->usePHPMailer) {
            try {
                $mail = new PHPMailer(true);
                if (defined('SMTP_HOST') && SMTP_HOST) {
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->Port       = SMTP_PORT ?? 587;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    if (defined('SMTP_USER') && SMTP_USER) {
                        $mail->SMTPAuth = true;
                        $mail->Username = SMTP_USER;
                        $mail->Password = SMTP_PASS;
                    }
                    $mail->Timeout = 10;
                }
                $mail->SMTPKeepAlive = true;
                $mail->SMTPDebug     = 0; // suppress per-message noise; errors go to $this->log
                $mail->setFrom(
                    defined('SMTP_FROM')      ? SMTP_FROM      : 'noreply@example.com',
                    defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'WPU System'
                );

                foreach ($messages as $m) {
                    try {
                        $mail->clearAddresses();
                        $mail->clearReplyTos();
                        $mail->addAddress($m['to'], $m['toName'] ?? '');
                        $mail->Subject = $m['subject'];
                        $isHtml = $m['isHtml'] ?? false;
                        if ($isHtml) {
                            $mail->isHTML(true);
                            $mail->Body    = $m['body'];
                            $mail->AltBody = strip_tags($m['body']);
                        } else {
                            $mail->isHTML(false);
                            $mail->Body = $m['body'];
                        }
                        $mail->send();
                        $this->log('info', 'Email sent via keepAlive', ['to' => $m['to'], 'subject' => $m['subject']]);
                        $sent[] = $m['to'];
                    } catch (PHPMailerException $e) {
                        $this->log('error', 'keepAlive send failed: ' . $e->getMessage(), ['to' => $m['to']]);
                        $this->lastError = $e->getMessage();
                        $failed[] = $m['to'];
                    }
                }

                $mail->smtpClose();

            } catch (PHPMailerException $e) {
                // Connection-level failure — mark everything failed
                $this->log('error', 'keepAlive SMTP connection failed: ' . $e->getMessage());
                $this->lastError = $e->getMessage();
                foreach ($messages as $m) {
                    $failed[] = $m['to'];
                }
            }

            return ['sent' => $sent, 'failed' => $failed];
        }

        // Fallback: no PHPMailer — send one-by-one via mail()
        foreach ($messages as $m) {
            $ok = $this->sendMail($m['to'], $m['toName'] ?? '', $m['subject'], $m['body'], $m['isHtml'] ?? false);
            if ($ok) {
                $sent[] = $m['to'];
            } else {
                $failed[] = $m['to'];
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Send PDS status notification
     */
    public function sendPDSStatusNotification($email, $name, $status, $remarks = '') {
        $statusText = ucfirst($status);
        $subject = "PDS Review " . $statusText;
        
        $displayName = $name ?: 'Faculty';
        
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">PDS Review Update</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>Your Personal Data Sheet (PDS) has been reviewed:</p>
    <p><strong>Review Status:</strong> ' . htmlspecialchars($statusText) . '</p>';
        
        if ($remarks) {
            $body .= '
    <p><strong>Remarks:</strong></p>
    <p style="white-space: pre-wrap;">' . htmlspecialchars($remarks) . '</p>';
        }
        
        $body .= '
    <p>Please login to the faculty portal to view more details about your PDS.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE Staff System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';

        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send timekeeper account creation notification with credentials
     */
    public function sendTimekeeperAccountCreationEmail($email, $name, $employeeId, $password, $stationName, $departmentName, $loginUrl) {
        $subject = "Welcome to WPU Timekeeper System - Your Account Credentials";
        
        $displayName = $name ?: 'Timekeeper';
        
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">Welcome to WPU Timekeeper System</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>Your Timekeeper account has been successfully created. Below are your login credentials:</p>
    <div style="background-color: #f5f5f5; padding: 15px; margin: 20px 0; border: 1px solid #ddd;">
        <p><strong>Safe Employee ID:</strong><br>' . htmlspecialchars($employeeId) . '</p>
        <p><strong>Password:</strong><br>' . htmlspecialchars($password) . '</p>
        <p><strong>Assigned Station:</strong><br>' . htmlspecialchars($stationName) . '</p>
        <p><strong>Department:</strong><br>' . htmlspecialchars($departmentName) . '</p>
    </div>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE Staff System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';

        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send calendar event notification
     */
    public function sendCalendarEventNotification($email, $name, $eventTitle, $eventDate, $eventTime = null, $endTime = null, $location = null, $description = null, $category = null) {
        $subject = "New Calendar Event: " . $eventTitle;
        
        $displayName = $name ?: 'Faculty/Staff';
        
        // Format date
        $dateObj = new DateTime($eventDate);
        $formattedDate = $dateObj->format('F j, Y');
        $dayOfWeek = $dateObj->format('l');
        
        // Format time
        $timeDisplay = '';
        if ($eventTime) {
            $timeDisplay = date('g:i A', strtotime($eventTime));
            if ($endTime) {
                $timeDisplay .= ' - ' . date('g:i A', strtotime($endTime));
            }
        }
        
        // Format description
        $descriptionDisplay = '';
        if ($description) {
            $cleanDescription = strip_tags($description);
            $descriptionDisplay = nl2br(htmlspecialchars($cleanDescription));
        }
        
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">New Calendar Event</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>A new event has been added to the university calendar:</p>
    <p><strong>Event Title:</strong> ' . htmlspecialchars($eventTitle) . '</p>
    <p><strong>Date:</strong> ' . htmlspecialchars($dayOfWeek . ', ' . $formattedDate) . '</p>';
        
        if ($timeDisplay) {
            $body .= '
    <p><strong>Time:</strong> ' . htmlspecialchars($timeDisplay) . '</p>';
        }
        
        if ($location) {
            $body .= '
    <p><strong>Location:</strong> ' . htmlspecialchars($location) . '</p>';
        }
        
        if ($category) {
            $body .= '
    <p><strong>Category:</strong> ' . htmlspecialchars($category) . '</p>';
        }
        
        if ($descriptionDisplay) {
            $body .= '
    <p><strong>Description:</strong></p>
    <div style="margin: 10px 0;">' . $descriptionDisplay . '</div>';
        }
        
        $body .= '
    <p>Please login to the faculty portal to view the full calendar and event details.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE Staff System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';

        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send admin account creation notification with credentials
     */
    public function sendAdminAccountCreationEmail($email, $firstName, $lastName, $password) {
        $subject = "Welcome to WPU SAFE System - Your Admin Account Credentials";
        
        $displayName = trim($firstName . ' ' . $lastName) ?: 'Administrator';
        $loginUrl = defined('SITE_URL') ? SITE_URL . '/login.php' : 'http://safe.wpu.edu.ph/login.php';
        
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">Welcome to WPU SAFE System</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>Your Administrator account has been successfully created. Below are your login credentials:</p>
    <div style="background-color: #f5f5f5; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 5px;">
        <p style="margin: 10px 0;"><strong>Email Address:</strong><br><span style="font-family: monospace; font-size: 14px;">' . htmlspecialchars($email) . '</span></p>
        <p style="margin: 10px 0;"><strong>Password:</strong><br><span style="font-family: monospace; font-size: 14px; background-color: #fff; padding: 5px 10px; border: 1px solid #ccc; border-radius: 3px; display: inline-block;">' . htmlspecialchars($password) . '</span></p>
    </div>
    <div style="text-align: center; margin: 30px 0;">
        <a href="' . htmlspecialchars($loginUrl) . '" style="display: inline-block; background-color: #007bff; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 5px; font-weight: bold; font-size: 16px;">Access Admin Dashboard</a>
    </div>
    <p style="background-color: #fff3cd; padding: 10px; border-left: 3px solid #ffc107; margin: 20px 0;"><strong>Security Notice:</strong> For security reasons, we strongly recommend changing your password after your first login.</p>
    <p>If you have any questions or need assistance, please contact the system administrator.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';

        return $this->sendMail($email, $displayName, $subject, $body, true);
    }

    /**
     * Send password reset email with verification link
     */
    public function sendPasswordResetEmail($email, $name, $resetToken, $resetUrl) {
        $subject = "Password Reset Request - WPU SAFE System";
        
        $displayName = $name ?: 'User';
        
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #333; margin-top: 0;">Password Reset Request</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>We received a request to reset your password for your WPU SAFE System account.</p>
    <p>To verify your email and proceed with resetting your password, please click the link below:</p>
    <div style="text-align: center; margin: 30px 0;">
        <a href="' . htmlspecialchars($resetUrl) . '" style="display: inline-block; background-color: #1e293b; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: bold; font-size: 16px;">Verify Email & Reset Password</a>
    </div>
    <p style="color: #666; font-size: 14px;">Or copy and paste this link into your browser:</p>
    <p style="color: #666; font-size: 12px; word-break: break-all; background-color: #f5f5f5; padding: 10px; border-radius: 4px;">' . htmlspecialchars($resetUrl) . '</p>
    <p style="background-color: #fff3cd; padding: 10px; border-left: 3px solid #ffc107; margin: 20px 0;"><strong>Security Notice:</strong> This link will expire in 1 hour. If you did not request a password reset, please ignore this email.</p>
    <p>If you have any questions or need assistance, please contact the system administrator.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';

        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send pardon request approved notification to employee
     * @param string $email Recipient email
     * @param string $name Recipient name
     * @param string $pardonDate Date that was pardoned (Y-m-d)
     */
    public function sendPardonApprovedEmail($email, $name, $pardonDate) {
        $subject = "Pardon Request Approved – WPU SAFE System";
        $displayName = $name ?: 'Employee';
        $formattedDate = date('F j, Y', strtotime($pardonDate));
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #198754; margin-top: 0;">Pardon Request Approved</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>Your pardon request for <strong>' . htmlspecialchars($formattedDate) . '</strong> has been <strong>approved</strong>.</p>
    <p>You can now submit your pardon from the View Logs page in the SAFE portal.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';
        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send pardon request rejected notification to employee
     * @param string $email Recipient email
     * @param string $name Recipient name
     * @param string $pardonDate Date that was requested (Y-m-d)
     * @param string $rejectionComment Optional reason/comment from the pardon opener
     */
    public function sendPardonRejectedEmail($email, $name, $pardonDate, $rejectionComment = '') {
        $subject = "Pardon Request Rejected – WPU SAFE System";
        $displayName = $name ?: 'Employee';
        $formattedDate = date('F j, Y', strtotime($pardonDate));
        $commentBlock = '';
        if (!empty(trim($rejectionComment ?? ''))) {
            $commentBlock = '<p><strong>Reason:</strong> ' . nl2br(htmlspecialchars(trim($rejectionComment))) . '</p>';
        }
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #dc3545; margin-top: 0;">Pardon Request Rejected</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>Your pardon request for <strong>' . htmlspecialchars($formattedDate) . '</strong> has been <strong>rejected</strong>.</p>
    ' . $commentBlock . '
    <p>You may submit a new pardon request through the SAFE portal if needed.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';
        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send official time approved notification
     * @param string $email Recipient email
     * @param string $name Recipient name
     * @param string $summary Brief summary (e.g. "3 day(s): Mon, Tue, Wed")
     */
    public function sendOfficialTimeApprovedEmail($email, $name, $summary = '') {
        $subject = "Official Time Request Approved – WPU SAFE System";
        $displayName = $name ?: 'Employee';
        $summaryLine = $summary ? "<p><strong>Approved:</strong> " . htmlspecialchars($summary) . "</p>" : '';
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #198754; margin-top: 0;">Official Time Request Approved</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>Your official time request has been <strong>approved</strong> by HR. It is now your working time.</p>
    ' . $summaryLine . '
    <p>Please log in to the faculty portal to view your official time schedule.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';
        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Send official time rejected notification
     * @param string $email Recipient email
     * @param string $name Recipient name
     * @param string $reason Optional rejection reason
     * @param string $rejectedBy Who rejected (e.g. "HR" or "your Dean")
     */
    public function sendOfficialTimeRejectedEmail($email, $name, $reason = '', $rejectedBy = 'HR') {
        $subject = "Official Time Request Rejected – WPU SAFE System";
        $displayName = $name ?: 'Employee';
        $reasonBlock = '';
        if ($reason !== '') {
            $reasonBlock = '<p><strong>Reason:</strong></p><p style="background-color: #f8f9fa; padding: 10px; border-left: 3px solid #dc3545;">' . htmlspecialchars($reason) . '</p>';
        }
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #dc3545; margin-top: 0;">Official Time Request Rejected</h2>
    <p>Hello ' . htmlspecialchars($displayName) . ',</p>
    <p>Your official time request has been <strong>rejected</strong> by ' . htmlspecialchars($rejectedBy) . '.</p>
    ' . $reasonBlock . '
    <p>You may submit a new official time request through the faculty portal if needed.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU SAFE System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';
        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Notify requester that their TARF was fully approved (final endorsement).
     *
     * @param string      $email
     * @param string      $name            Requester display name
     * @param int         $tarfId
     * @param int|null    $serialYear      From tarf_requests.serial_year
     * @param string      $eventPurpose    From form (may be empty)
     * @param string|null $createdAt       tarf_requests.created_at
     */
    public function sendTarfRequestApprovedEmail($email, $name, $tarfId, $serialYear, $eventPurpose, $createdAt = null) {
        require_once __DIR__ . '/email_templates/tarf_request_emails.php';
        $submitted = '';
        if ($createdAt !== null && $createdAt !== '') {
            $ts = strtotime((string) $createdAt);
            if ($ts) {
                $submitted = date('F j, Y g:i A', $ts);
            }
        }
        $body = tarf_email_html_approved([
            'recipient_name' => $name ?: 'Employee',
            'tarf_id' => (int) $tarfId,
            'serial_year' => $serialYear !== null && $serialYear !== '' ? (int) $serialYear : 0,
            'event_purpose' => (string) $eventPurpose,
            'submitted_display' => $submitted,
            'view_url' => tarf_email_absolute_view_url((int) $tarfId),
        ]);
        $subject = 'TARF approved — Request #' . (int) $tarfId;
        return $this->sendMail($email, $name, $subject, $body, true);
    }

    /**
     * Notify requester that their TARF was rejected at supervisor, endorser, or President stage.
     *
     * @param string      $email
     * @param string      $name
     * @param int         $tarfId
     * @param int|null    $serialYear
     * @param string      $eventPurpose
     * @param string|null $createdAt
     * @param string      $rejectionReason Required human-readable reason
     * @param string      $rejectionStage  supervisor|endorser|president
     */
    public function sendTarfRequestRejectedEmail($email, $name, $tarfId, $serialYear, $eventPurpose, $createdAt = null, $rejectionReason = '', $rejectionStage = 'supervisor') {
        require_once __DIR__ . '/email_templates/tarf_request_emails.php';
        $submitted = '';
        if ($createdAt !== null && $createdAt !== '') {
            $ts = strtotime((string) $createdAt);
            if ($ts) {
                $submitted = date('F j, Y g:i A', $ts);
            }
        }
        $body = tarf_email_html_rejected([
            'recipient_name' => $name ?: 'Employee',
            'tarf_id' => (int) $tarfId,
            'serial_year' => $serialYear !== null && $serialYear !== '' ? (int) $serialYear : 0,
            'event_purpose' => (string) $eventPurpose,
            'submitted_display' => $submitted,
            'view_url' => tarf_email_absolute_view_url((int) $tarfId),
            'rejection_reason' => (string) $rejectionReason,
            'rejection_stage' => (string) $rejectionStage,
        ]);
        $subject = 'TARF not approved — Request #' . (int) $tarfId;
        return $this->sendMail($email, $name, $subject, $body, true);
    }
}

?>