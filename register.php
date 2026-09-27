<?php
require __DIR__ . '/includes/bootstrap.php';
ensure_email_auth_schema();

$user = current_user();
if ($user) {
    redirect(role_home($user['role']));
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'full_name', 'phone', 'emergency_contact', 'date_of_birth',
        'medical_college', 'email', 'address', 'password',
    ];
    $data = [];
    foreach ($fields as $f) {
        $data[$f] = trim((string) ($_POST[$f] ?? ''));
        if ($data[$f] === '') {
            $error = 'All fields are required.';
            break;
        }
    }

    if (!$error) {
        $email = strtolower($data['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (strlen($data['password']) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            try {
                $pdo = db();
                $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $exists->execute([$email]);
                if ($exists->fetch()) {
                    $error = 'Email already registered. Sign in instead.';
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare(
                        'INSERT INTO users (email, password_hash, role, status, email_verified_at) VALUES (?, ?, ?, ?, NOW())'
                    )->execute([
                        $email,
                        password_hash($data['password'], PASSWORD_BCRYPT),
                        'teacher',
                        'active',
                    ]);
                    $userId = (int) $pdo->lastInsertId();
                    $pdo->prepare(
                        'INSERT INTO teachers (user_id, full_name, phone, emergency_contact, date_of_birth, medical_college, address)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $userId,
                        $data['full_name'],
                        $data['phone'],
                        $data['emergency_contact'],
                        $data['date_of_birth'],
                        $data['medical_college'],
                        $data['address'],
                    ]);
                    $pdo->commit();
                    flash('success', 'Account created. You can sign in now.');
                    redirect('/teacherTreck/login.php');
                }
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    try { $pdo->rollBack(); } catch (Throwable $ignore) {}
                }
                $msg = $e->getMessage();
                if (stripos($msg, 'gone away') !== false
                    || stripos($msg, "Can't connect") !== false
                    || stripos($msg, 'refused') !== false
                ) {
                    $error = 'MySQL is not running. Start MySQL in XAMPP, then try again.';
                } else {
                    $error = 'Could not register: ' . $msg;
                }
            }
        }
    }
}

$pageTitle = 'Register — MEDICO';
$brandSub = 'Teacher Onboarding';
$heroTitle = 'Join the teaching roster.';
$heroText = '';
$wideCard = true;
require __DIR__ . '/includes/auth_header.php';
?>
        <h2>Teacher registration</h2>
        <p class="subtitle">All fields are required</p>
        <?php if ($error): ?>
          <div class="alert alert-error show"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" action="">
          <div class="form-group">
            <label>Full name</label>
            <input name="full_name" required value="<?= h($_POST['full_name'] ?? '') ?>" />
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Phone</label>
              <input name="phone" required value="<?= h($_POST['phone'] ?? '') ?>" />
            </div>
            <div class="form-group">
              <label>Emergency contact</label>
              <input name="emergency_contact" required value="<?= h($_POST['emergency_contact'] ?? '') ?>" />
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Date of birth</label>
              <input type="date" name="date_of_birth" required value="<?= h($_POST['date_of_birth'] ?? '') ?>" />
            </div>
            <div class="form-group">
              <label>Medical college</label>
              <input name="medical_college" required value="<?= h($_POST['medical_college'] ?? '') ?>" />
            </div>
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" placeholder="you@gmail.com" />
          </div>
          <div class="form-group">
            <label>Address</label>
            <textarea name="address" required><?= h($_POST['address'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label>Password (min 8)</label>
            <input type="password" name="password" minlength="8" required />
          </div>
          <button class="btn btn-primary" type="submit">Create account</button>
        </form>
        <p class="auth-footer">Already registered? <a href="login.php">Sign in</a></p>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
