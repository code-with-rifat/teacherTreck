<?php

declare(strict_types=1);

namespace Medico\Controllers;

use Medico\Auth\Jwt;
use Medico\Http\Request;
use Medico\Http\Response;
use PDO;

final class AuthController
{
    public function __construct(
        private PDO $db,
        private array $config
    ) {}

    public function register(Request $request): void
    {
        $required = [
            'full_name', 'phone', 'emergency_contact', 'date_of_birth',
            'medical_college', 'email', 'address', 'password',
        ];

        foreach ($required as $field) {
            if (trim((string) $request->input($field, '')) === '') {
                Response::error("Missing field: {$field}", 422);
            }
        }

        $email = strtolower(trim((string) $request->input('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address', 422);
        }

        $password = (string) $request->input('password');
        if (strlen($password) < 8) {
            Response::error('Password must be at least 8 characters', 422);
        }

        $exists = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            Response::error('Email already registered', 409);
        }

        $this->db->beginTransaction();
        try {
            $userStmt = $this->db->prepare(
                'INSERT INTO users (email, password_hash, role, status) VALUES (?, ?, ?, ?)'
            );
            $userStmt->execute([
                $email,
                password_hash($password, PASSWORD_BCRYPT),
                'teacher',
                'pending',
            ]);
            $userId = (int) $this->db->lastInsertId();

            $teacherStmt = $this->db->prepare(
                'INSERT INTO teachers
                 (user_id, full_name, phone, emergency_contact, date_of_birth, medical_college, address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $teacherStmt->execute([
                $userId,
                trim((string) $request->input('full_name')),
                trim((string) $request->input('phone')),
                trim((string) $request->input('emergency_contact')),
                (string) $request->input('date_of_birth'),
                trim((string) $request->input('medical_college')),
                trim((string) $request->input('address')),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Response::error('Registration failed', 500, ['detail' => $e->getMessage()]);
        }

        Response::ok(null, 'Registration successful. Await admin activation.');
    }

    public function login(Request $request): void
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');

        if ($email === '' || $password === '') {
            Response::error('Email and password required', 422);
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::error('Invalid credentials', 401);
        }

        if ($user['status'] !== 'active') {
            Response::error('Account is not active. Contact admin.', 403);
        }

        $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
            ->execute([(int) $user['id']]);

        $profile = $this->loadProfile((int) $user['id'], $user['role']);

        $token = Jwt::encode(
            [
                'sub'  => (int) $user['id'],
                'role' => $user['role'],
                'email'=> $user['email'],
            ],
            $this->config['jwt']['secret'],
            $this->config['jwt']['ttl'],
            $this->config['jwt']['issuer']
        );

        Response::ok([
            'token'   => $token,
            'user'    => [
                'id'    => (int) $user['id'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
            'profile' => $profile,
        ], 'Login successful');
    }

    public function me(array $authUser): void
    {
        $profile = $this->loadProfile((int) $authUser['id'], $authUser['role']);
        Response::ok([
            'user'    => $authUser,
            'profile' => $profile,
        ]);
    }

    public function forgotPassword(Request $request): void
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        if ($email === '') {
            Response::error('Email required', 422);
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always succeed to avoid email enumeration
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $this->db->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
            )->execute([(int) $user['id'], $hash]);

            // In production: send email. Dev returns token when debug=true.
            if (!empty($this->config['debug'])) {
                Response::ok(['reset_token' => $token], 'Password reset token generated (debug mode)');
            }
        }

        Response::ok(null, 'If the email exists, a reset link has been sent.');
    }

    public function resetPassword(Request $request): void
    {
        $token = (string) $request->input('token', '');
        $password = (string) $request->input('password', '');

        if ($token === '' || strlen($password) < 8) {
            Response::error('Valid token and password (8+ chars) required', 422);
        }

        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Invalid or expired reset token', 400);
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_BCRYPT), (int) $row['user_id']]);
            $this->db->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
                ->execute([(int) $row['id']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Response::error('Reset failed', 500);
        }

        Response::ok(null, 'Password updated successfully');
    }

    private function loadProfile(int $userId, string $role): ?array
    {
        if ($role === 'teacher') {
            $stmt = $this->db->prepare('SELECT * FROM teachers WHERE user_id = ?');
            $stmt->execute([$userId]);
            return $stmt->fetch() ?: null;
        }

        if ($role === 'branch_manager') {
            $stmt = $this->db->prepare('SELECT * FROM branches WHERE manager_user_id = ?');
            $stmt->execute([$userId]);
            return $stmt->fetch() ?: null;
        }

        return null;
    }
}
