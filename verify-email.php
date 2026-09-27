<?php
/**
 * Email verification — 6-digit code (Gmail-style)
 */
require __DIR__ . '/includes/bootstrap.php';
ensure_email_auth_schema();

$user = current_user();
if ($user) {
    redirect(role_home($user['role']));
}

$error = null;
$email = strtolower(trim((string) ($_GET['email'] ?? $_POST['email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'verify');
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Valid email required.';
    } elseif ($action === 'resend') {
        $st = db()->prepare('SELECT id, email, email_verified_at FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $row = $st->fetch();
        if (!$row) {
            flash('success', 'If that email is registered, a new code was sent.');
        } elseif (!empty($row['email_verified_at'])) {
            flash('success', 'Email already verified. You can sign in (after admin activation).');
            redirect('/teacherTreck/login.php');
        } else {
            $sent = issue_email_code((int) $row['id'], (string) $row['email'], 'verify');
            if (!$sent['ok']) {
                $error = 'Could not send email: ' . ($sent['error'] ?? 'SMTP error');
            } else {
                flash('success', 'New code sent to ' . $email);
            }
        }
    } else {
        // digits from otp inputs or single field
        $digits = $_POST['otp'] ?? [];
        if (is_array($digits)) {
            $code = implode('', array_map('strval', $digits));
        } else {
            $code = (string) ($_POST['code'] ?? '');
        }
        $code = preg_replace('/\D+/', '', $code) ?? '';

        $row = consume_email_code($code, 'verify');
        if (!$row) {
            $error = 'Invalid or expired code. Check your email or resend.';
        } else {
            $u = db()->prepare('SELECT id, email FROM users WHERE id = ? AND email = ? LIMIT 1');
            $u->execute([(int) $row['user_id'], $email]);
            $match = $u->fetch();
            if (!$match) {
                $error = 'Email does not match this code.';
            } else {
                db()->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')
                    ->execute([(int) $match['id']]);
                flash('success', 'Email verified. Wait for admin activation, then sign in.');
                redirect('/teacherTreck/login.php');
            }
        }
    }
}

$pageTitle = 'Verify email — MEDICO';
$brandSub = 'Email verification';
$heroTitle = 'Check your inbox.';
$heroText = 'We sent a 6-digit code — just like Gmail & other apps.';
require __DIR__ . '/includes/auth_header.php';
?>
        <h2>Verify your email</h2>
        <p class="subtitle">
          <?php if ($email): ?>
            Code sent to <strong><?= h($email) ?></strong>
          <?php else: ?>
            Enter the 6-digit code from your email
          <?php endif; ?>
        </p>
        <?php if ($error): ?>
          <div class="alert alert-error show"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" id="otpForm" autocomplete="one-time-code">
          <input type="hidden" name="action" value="verify" />
          <input type="hidden" name="email" value="<?= h($email) ?>" />
          <?php if ($email === ''): ?>
            <div class="form-group">
              <label>Email</label>
              <input type="email" name="email" required />
            </div>
          <?php endif; ?>

          <div class="form-group">
            <label>Verification code</label>
            <div class="otp-boxes" data-otp>
              <?php for ($i = 0; $i < 6; $i++): ?>
                <input class="otp-digit" name="otp[]" type="text" inputmode="numeric" maxlength="1"
                       pattern="\d" aria-label="Digit <?= $i + 1 ?>" required />
              <?php endfor; ?>
            </div>
          </div>
          <button class="btn btn-primary" type="submit">Verify email</button>
        </form>

        <form method="post" style="margin-top:.85rem">
          <input type="hidden" name="action" value="resend" />
          <input type="hidden" name="email" value="<?= h($email) ?>" />
          <button class="btn btn-ghost btn-sm" type="submit" style="width:auto;padding:0" <?= $email === '' ? 'disabled' : '' ?>>
            Resend code
          </button>
        </form>
        <p class="auth-footer"><a href="login.php">Back to sign in</a></p>
        <script src="/teacherTreck/assets/js/otp.js" defer></script>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
