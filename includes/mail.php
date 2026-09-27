<?php
/**
 * Simple mail helper (SMTP / PHP mail / log fallback)
 */

declare(strict_types=1);

function mail_config(): array
{
    $app = require __DIR__ . '/../config/app.php';
    $cfg = $app['mail'] ?? [];
    try {
        $keys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption', 'from_email', 'from_name'];
        foreach ($keys as $k) {
            $st = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
            $st->execute(['mail_' . $k]);
            $v = $st->fetchColumn();
            if (is_string($v) && trim($v) !== '') {
                $cfg[$k] = $k === 'smtp_port' ? (int) $v : trim($v);
            }
        }
    } catch (Throwable $e) {
        // ignore before schema ready
    }
    return $cfg;
}

function send_app_mail(string $to, string $subject, string $html, string $text = ''): array
{
    $cfg = mail_config();
    $fromEmail = (string) ($cfg['from_email'] ?? 'noreply@medico.local');
    $fromName = (string) ($cfg['from_name'] ?? 'MEDICO');
    $text = $text !== '' ? $text : trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));

    $driver = (string) ($cfg['driver'] ?? 'smtp');
    if ($driver === 'log' || empty($cfg['smtp_user']) || empty($cfg['smtp_pass'])) {
        return mail_log_fallback($to, $subject, $text, 'SMTP not configured — Admin → Dashboard → Email (SMTP) এ Gmail সেট করুন।');
    }

    if ($driver === 'mail') {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . mail_encode_address($fromName, $fromEmail),
            'Reply-To: ' . $fromEmail,
            'X-Mailer: MEDICO-PHP',
        ];
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers));
        return $ok
            ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => 'PHP mail() failed. Use SMTP in config.'];
    }

    return mail_send_smtp($to, $subject, $html, $text, $cfg, $fromEmail, $fromName);
}

function mail_encode_address(string $name, string $email): string
{
    if ($name === '') {
        return $email;
    }
    return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
}

function mail_log_fallback(string $to, string $subject, string $text, string $reason): array
{
    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . "] TO={$to} SUBJECT={$subject}\n{$text}\nREASON={$reason}\n---\n";
    @file_put_contents($dir . '/mail.log', $line, FILE_APPEND);
    return ['ok' => false, 'error' => $reason];
}

function mail_send_smtp(
    string $to,
    string $subject,
    string $html,
    string $text,
    array $cfg,
    string $fromEmail,
    string $fromName
): array {
    $host = (string) ($cfg['smtp_host'] ?? 'smtp.gmail.com');
    $port = (int) ($cfg['smtp_port'] ?? 587);
    $user = (string) ($cfg['smtp_user'] ?? '');
    $pass = (string) ($cfg['smtp_pass'] ?? '');
    $enc = strtolower((string) ($cfg['smtp_encryption'] ?? 'tls'));

    if ($user === '' || $pass === '') {
        return mail_log_fallback($to, $subject, $text, 'SMTP username/password missing in config/app.php (mail section).');
    }

    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return ['ok' => false, 'error' => "SMTP connect failed: {$errstr} ({$errno})"];
    }
    stream_set_timeout($fp, 20);

    $read = static function () use ($fp): string {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = static function (string $line) use ($fp, $read): string {
        fwrite($fp, $line . "\r\n");
        return $read();
    };

    try {
        $banner = $read();
        if (strpos($banner, '220') !== 0) {
            throw new RuntimeException('Bad SMTP banner: ' . trim($banner));
        }

        $ehloHost = 'localhost';
        $r = $cmd('EHLO ' . $ehloHost);
        if (strpos($r, '250') !== 0) {
            $r = $cmd('HELO ' . $ehloHost);
        }

        if ($enc === 'tls') {
            $r = $cmd('STARTTLS');
            if (strpos($r, '220') !== 0) {
                throw new RuntimeException('STARTTLS failed: ' . trim($r));
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS handshake failed');
            }
            $r = $cmd('EHLO ' . $ehloHost);
            if (strpos($r, '250') !== 0) {
                throw new RuntimeException('EHLO after TLS failed');
            }
        }

        $r = $cmd('AUTH LOGIN');
        if (strpos($r, '334') !== 0) {
            throw new RuntimeException('AUTH not accepted: ' . trim($r));
        }
        $r = $cmd(base64_encode($user));
        if (strpos($r, '334') !== 0) {
            throw new RuntimeException('SMTP user rejected');
        }
        $r = $cmd(base64_encode($pass));
        if (strpos($r, '235') !== 0) {
            throw new RuntimeException('SMTP password rejected — use Gmail App Password');
        }

        $r = $cmd('MAIL FROM:<' . $fromEmail . '>');
        if (strpos($r, '250') !== 0) {
            throw new RuntimeException('MAIL FROM failed: ' . trim($r));
        }
        $r = $cmd('RCPT TO:<' . $to . '>');
        if (strpos($r, '250') !== 0 && strpos($r, '251') !== 0) {
            throw new RuntimeException('RCPT TO failed: ' . trim($r));
        }
        $r = $cmd('DATA');
        if (strpos($r, '354') !== 0) {
            throw new RuntimeException('DATA failed: ' . trim($r));
        }

        $boundary = 'b_' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . mail_encode_address($fromName, $fromEmail),
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n" . $text . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n" . $html . "\r\n";
        $body .= '--' . $boundary . "--\r\n";
        $body = preg_replace('/^\./m', '..', $body) ?? $body;

        fwrite($fp, $body . "\r\n.\r\n");
        $r = $read();
        if (strpos($r, '250') !== 0) {
            throw new RuntimeException('Message not accepted: ' . trim($r));
        }
        $cmd('QUIT');
        fclose($fp);
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        fclose($fp);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function send_password_reset_code(string $toEmail, string $code): array
{
    return send_otp_email($toEmail, $code, 'reset');
}

function send_email_verification_code(string $toEmail, string $code): array
{
    return send_otp_email($toEmail, $code, 'verify');
}

/** @param 'verify'|'reset' $purpose */
function send_otp_email(string $toEmail, string $code, string $purpose): array
{
    $isVerify = $purpose === 'verify';
    $subject = $isVerify ? 'MEDICO verification code' : 'MEDICO password reset code';
    $title = $isVerify ? 'Verify your email' : 'Password reset';
    $intro = $isVerify
        ? 'Enter this code to verify your MEDICO account email:'
        : 'Enter this code to reset your MEDICO password:';
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;background:#fff">'
        . '<div style="font-size:13px;font-weight:700;letter-spacing:.08em;color:#c5221f;margin-bottom:16px">MEDICO</div>'
        . '<h2 style="color:#1a1a1a;margin:0 0 12px;font-size:22px">' . $title . '</h2>'
        . '<p style="color:#555;line-height:1.5;margin:0 0 8px">' . $intro . '</p>'
        . '<p style="font-size:32px;letter-spacing:8px;font-weight:700;color:#1a1a1a;margin:20px 0;font-family:Consolas,monospace">'
        . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
        . '</p>'
        . '<p style="color:#777;font-size:13px;line-height:1.45;margin:0">This code expires in <strong>15 minutes</strong>. '
        . 'If you didn\'t request this, you can ignore this email.</p>'
        . '</div>';
    $text = "{$title}\n\n{$intro}\n\n{$code}\n\nExpires in 15 minutes.";
    return send_app_mail($toEmail, $subject, $html, $text);
}

/**
 * Create 6-digit code, store hash, email it.
 * @param 'verify'|'reset' $purpose
 * @return array{ok:bool,error:?string,code:?string}
 */
function issue_email_code(int $userId, string $email, string $purpose): array
{
    ensure_email_auth_schema();
    $purpose = $purpose === 'reset' ? 'reset' : 'verify';
    $code = (string) random_int(100000, 999999);
    $hash = hash('sha256', $code . '|' . $purpose);

    $pdo = db();
    $pdo->prepare(
        'UPDATE email_codes SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL'
    )->execute([$userId, $purpose]);
    $pdo->prepare(
        'INSERT INTO email_codes (user_id, purpose, code_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))'
    )->execute([$userId, $purpose, $hash]);

    $mail = $purpose === 'reset'
        ? send_password_reset_code($email, $code)
        : send_email_verification_code($email, $code);

    if (!$mail['ok']) {
        return ['ok' => false, 'error' => $mail['error'] ?? 'Email send failed', 'code' => null];
    }
    return ['ok' => true, 'error' => null, 'code' => null];
}

/**
 * @param 'verify'|'reset' $purpose
 * @return array|null email_codes row
 */
function consume_email_code(string $code, string $purpose): ?array
{
    ensure_email_auth_schema();
    $purpose = $purpose === 'reset' ? 'reset' : 'verify';
    $code = preg_replace('/\s+/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $hash = hash('sha256', $code . '|' . $purpose);
    $st = db()->prepare(
        'SELECT * FROM email_codes
         WHERE code_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1'
    );
    $st->execute([$hash, $purpose]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    db()->prepare('UPDATE email_codes SET used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
    return $row;
}

function mail_is_configured(): bool
{
    $cfg = mail_config();
    return !empty($cfg['smtp_user']) && !empty($cfg['smtp_pass']);
}
