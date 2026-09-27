<?php
require __DIR__ . '/includes/bootstrap.php';
ensure_email_auth_schema();

$user = current_user();
if ($user) {
    redirect(role_home($user['role']));
}

$error = null;
$step = 'request'; // request | verify | password
$sentEmail = '';

// Resume password step if code already verified
if (!empty($_SESSION['pw_reset']['user_id']) && (int) ($_SESSION['pw_reset']['expires'] ?? 0) > time()) {
    if (($_GET['step'] ?? '') === 'password' || ($_POST['action'] ?? '') === 'set_password') {
        $step = 'password';
        $sentEmail = (string) ($_SESSION['pw_reset']['email'] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'request';

    if ($action === 'request') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Valid email দিন।';
        } elseif (!mail_is_configured()) {
            $error = 'Email system not ready. Ask Admin to set Gmail SMTP first.';
        } else {
            try {
                unset($_SESSION['pw_reset']);
                $stmt = db()->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $row = $stmt->fetch();
                if ($row) {
                    $sent = issue_email_code((int) $row['id'], (string) $row['email'], 'reset');
                    if (!$sent['ok']) {
                        $error = 'Email পাঠানো যায়নি: ' . ($sent['error'] ?? '');
                    } else {
                        $step = 'verify';
                        $sentEmail = $email;
                        flash('success', 'Reset code sent to ' . $email . ' — check Inbox / Spam.');
                    }
                } else {
                    $step = 'verify';
                    $sentEmail = $email;
                    flash('success', 'If that email has an account, a code was sent.');
                }
            } catch (Throwable $e) {
                $error = 'Request failed: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'verify') {
        $digits = $_POST['otp'] ?? [];
        $code = is_array($digits) ? implode('', $digits) : (string) ($_POST['code'] ?? '');
        $code = preg_replace('/\D+/', '', $code) ?? '';
        $sentEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
        $step = 'verify';

        if (!preg_match('/^\d{6}$/', $code)) {
            $error = '6-digit code দিন।';
        } else {
            $row = consume_email_code($code, 'reset');
            if (!$row) {
                $error = 'Invalid বা expired code। Resend করুন।';
            } else {
                // Optional: ensure code belongs to this email
                if ($sentEmail !== '') {
                    $u = db()->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
                    $u->execute([(int) $row['user_id']]);
                    $owner = $u->fetch();
                    if ($owner && strtolower((string) $owner['email']) !== $sentEmail) {
                        $error = 'Code does not match this email.';
                    }
                }
                if (!$error) {
                    $u = db()->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
                    $u->execute([(int) $row['user_id']]);
                    $owner = $u->fetch();
                    $_SESSION['pw_reset'] = [
                        'user_id' => (int) $row['user_id'],
                        'email' => (string) ($owner['email'] ?? $sentEmail),
                        'expires' => time() + 900,
                    ];
                    $step = 'password';
                    $sentEmail = (string) ($owner['email'] ?? $sentEmail);
                    flash('success', 'Code verified. Now set a new password.');
                }
            }
        }
    }

    if ($action === 'set_password') {
        $step = 'password';
        $sentEmail = (string) ($_SESSION['pw_reset']['email'] ?? $_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $uid = (int) ($_SESSION['pw_reset']['user_id'] ?? 0);
        $exp = (int) ($_SESSION['pw_reset']['expires'] ?? 0);

        if ($uid < 1 || $exp < time()) {
            unset($_SESSION['pw_reset']);
            $error = 'Session expired. Request a new code.';
            $step = 'request';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'New password এবং confirm password মিলছে না।';
        } else {
            try {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_BCRYPT), $uid]);
                unset($_SESSION['pw_reset']);
                flash('success', 'Password updated. এখন login করুন।');
                redirect('/teacher-traking/login.php');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Password recovery — MEDICO';
$brandSub = 'Account recovery';
$heroTitle = 'Reset your password.';
$heroText = '';
require __DIR__ . '/includes/auth_header.php';
?>
        <h2>Forgot password</h2>
        <p class="subtitle">
          <?php if ($step === 'request'): ?>
            Code will be sent to your email
          <?php elseif ($step === 'verify'): ?>
            Enter the 6-digit code from email
          <?php else: ?>
            Set your new password
          <?php endif; ?>
        </p>
        <?php if ($error): ?>
          <div class="alert alert-error show"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 'request'): ?>
          <form method="post">
            <input type="hidden" name="action" value="request" />
            <div class="form-group">
              <label>Email address</label>
              <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" placeholder="you@gmail.com" />
            </div>
            <button class="btn btn-primary" type="submit">Send code to email</button>
          </form>

        <?php elseif ($step === 'verify'): ?>
          <form method="post" id="otpForm" autocomplete="one-time-code">
            <input type="hidden" name="action" value="verify" />
            <input type="hidden" name="email" value="<?= h($sentEmail ?: ($_POST['email'] ?? '')) ?>" />
            <div class="form-group">
              <label>Code from email</label>
              <div class="otp-boxes" data-otp>
                <?php for ($i = 0; $i < 6; $i++): ?>
                  <input class="otp-digit" name="otp[]" type="text" inputmode="numeric" maxlength="1" pattern="\d" required />
                <?php endfor; ?>
              </div>
            </div>
            <button class="btn btn-primary" type="submit">Verify code</button>
          </form>
          <p class="auth-footer" style="margin-top:.75rem"><a href="forgot-password.php">Resend code</a></p>
          <script src="/teacher-traking/assets/js/otp.js" defer></script>

        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="set_password" />
            <input type="hidden" name="email" value="<?= h($sentEmail) ?>" />
            <div class="form-group">
              <label>New password (min 8)</label>
              <input type="password" name="password" minlength="8" required autocomplete="new-password" />
            </div>
            <div class="form-group">
              <label>Confirm password</label>
              <input type="password" name="password_confirm" minlength="8" required autocomplete="new-password" />
            </div>
            <button class="btn btn-primary" type="submit">Update password</button>
          </form>
        <?php endif; ?>

        <p class="auth-footer"><a href="login.php">Back to sign in</a></p>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
