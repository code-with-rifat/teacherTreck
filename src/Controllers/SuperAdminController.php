<?php

declare(strict_types=1);

namespace Medico\Controllers;

use Medico\Http\Request;
use Medico\Http\Response;
use PDO;

final class SuperAdminController
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function createUser(Request $request): void
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $role = (string) $request->input('role', '');

        $allowed = ['teacher', 'branch_manager', 'admin', 'super_admin'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !in_array($role, $allowed, true)) {
            Response::error('Valid email, password (8+), and role required', 422);
        }

        $exists = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            Response::error('Email already exists', 409);
        }

        $this->db->prepare(
            'INSERT INTO users (email, password_hash, role, status) VALUES (?, ?, ?, ?)'
        )->execute([
            $email,
            password_hash($password, PASSWORD_BCRYPT),
            $role,
            'active',
        ]);

        Response::ok(['id' => (int) $this->db->lastInsertId()], 'User created');
    }

    public function assignManager(Request $request): void
    {
        $branchId = (int) $request->input('branch_id', 0);
        $userId = (int) $request->input('user_id', 0);

        $user = $this->db->prepare('SELECT id, role FROM users WHERE id = ?');
        $user->execute([$userId]);
        $u = $user->fetch();
        if (!$u || $u['role'] !== 'branch_manager') {
            Response::error('User must have branch_manager role', 422);
        }

        $stmt = $this->db->prepare('UPDATE branches SET manager_user_id = ? WHERE id = ?');
        $stmt->execute([$userId, $branchId]);
        if ($stmt->rowCount() === 0) {
            Response::error('Branch not found', 404);
        }

        Response::ok(null, 'Manager assigned');
    }

    public function systemSettings(Request $request): void
    {
        if ($request->method === 'GET') {
            $rows = $this->db->query('SELECT setting_key, setting_value, description, updated_at FROM system_settings')->fetchAll();
            Response::ok($rows);
        }

        $key = (string) $request->input('setting_key', '');
        $value = $request->input('setting_value');
        if ($key === '' || $value === null) {
            Response::error('setting_key and setting_value required', 422);
        }

        $this->db->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, (string) $value]);

        Response::ok(null, 'Setting saved');
    }

    public function auditLogs(Request $request): void
    {
        $limit = min(500, max(1, (int) $request->input('limit', 100)));
        $stmt = $this->db->prepare(
            'SELECT a.*, u.email AS actor_email
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_user_id
             ORDER BY a.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        Response::ok($stmt->fetchAll());
    }
}
