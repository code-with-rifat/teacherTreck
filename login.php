<?php
require __DIR__ . '/includes/bootstrap.php';
ensure_email_auth_schema();

$user = current_user();
if ($user) {
    redirect(role_home($user['role']));
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    try {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            $error = 'Invalid email or password.';
        } elseif ($row['status'] !== 'active') {
            $error = 'Account is not active. Ask admin to activate.';
        } else {
            db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
            login_user($row);
            redirect(role_home($row['role']));
        }
    } catch (Throwable $e) {
        $error = 'Database error. Start MySQL in XAMPP. ' . $e->getMessage();
    }
}

$pageTitle = 'Sign in — MEDICO';
$heroTitle = 'Classes. Teachers. Presence.';
$heroText = '';
require __DIR__ . '/includes/auth_header.php';
?>
        <header class="auth-card-head">
          <h2>Sign in</h2>
          <p class="subtitle">Use your MEDICO email</p>
        </header>
        <?php if ($error): ?>
          <div class="alert alert-error show"><?= h($error) ?></div>
        <?php endif; ?>
        <form class="auth-form" method="post" action="">
          <div class="form-group">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required autocomplete="username" value="<?= h($_POST['email'] ?? '') ?>" placeholder="you@gmail.com" />
          </div>
          <div class="form-group">
            <div class="auth-label-row">
              <label for="password">Password</label>
              <a class="auth-text-link" href="forgot-password.php">Forgot?</a>
            </div>
            <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="••••••••" />
          </div>
          <button class="btn btn-primary auth-submit" type="submit">Sign in</button>
        </form>
        <p class="auth-footer">New teacher? <a href="register.php">Create an account</a></p>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>