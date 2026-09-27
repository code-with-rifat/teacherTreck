<?php

declare(strict_types=1);

namespace Medico\Controllers;

use Medico\Http\Request;
use Medico\Http\Response;
use PDO;

final class AdminController
{
    /** @var PDO */
    private $db;
    /** @var array */
    private $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function dashboard(array $authUser): void
    {
        $metrics = $this->db->query(
            'SELECT
               (SELECT COUNT(*) FROM teachers t JOIN users u ON u.id = t.user_id WHERE u.status = \'active\') AS active_teachers,
               (SELECT COUNT(*) FROM branches WHERE status = \'active\') AS active_branches,
               (SELECT COUNT(*) FROM classes WHERE class_date = CURDATE()) AS classes_today,
               (SELECT COUNT(*) FROM class_reviews WHERE has_issue_flag = 1 AND submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS open_issues_7d,
               (SELECT COUNT(*) FROM users WHERE status = \'pending\' AND role = \'teacher\') AS pending_teachers'
        )->fetch();

        $recentIssues = $this->db->query(
            'SELECT r.*, t.full_name AS teacher_name, b.name AS branch_name, c.class_date, c.time_slot
             FROM class_reviews r
             JOIN teachers t ON t.id = r.teacher_id
             JOIN classes c ON c.id = r.class_id
             JOIN branches b ON b.id = c.branch_id
             WHERE r.has_issue_flag = 1
             ORDER BY r.submitted_at DESC
             LIMIT 15'
        )->fetchAll();

        Response::ok([
            'metrics'       => $metrics,
            'recent_issues' => $recentIssues,
        ]);
    }

    public function getGeofenceSettings(): void
    {
        $global = $this->db->query(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'global_checkin_radius_m'"
        )->fetchColumn();

        $branches = $this->db->query(
            'SELECT id, name, code, latitude, longitude, geofence_radius_m, city, is_outside_dhaka
             FROM branches ORDER BY name'
        )->fetchAll();

        Response::ok([
            'global_checkin_radius_m' => (int) $global,
            'branches' => $branches,
        ]);
    }

    public function updateGeofence(Request $request): void
    {
        $global = $request->input('global_checkin_radius_m');
        if ($global !== null) {
            $radius = (int) $global;
            if ($radius < 20 || $radius > 5000) {
                Response::error('Radius must be between 20 and 5000 meters', 422);
            }
            $this->db->prepare(
                "UPDATE system_settings SET setting_value = ? WHERE setting_key = 'global_checkin_radius_m'"
            )->execute([(string) $radius]);
        }

        $branchUpdates = $request->input('branches', []);
        if (is_array($branchUpdates)) {
            $stmt = $this->db->prepare('UPDATE branches SET geofence_radius_m = ? WHERE id = ?');
            foreach ($branchUpdates as $b) {
                if (!isset($b['id'])) {
                    continue;
                }
                $r = $b['geofence_radius_m'] ?? null;
                $stmt->execute([$r === null || $r === '' ? null : (int) $r, (int) $b['id']]);
            }
        }

        Response::ok(null, 'Geofence settings updated');
    }

    public function qualityReports(Request $request): void
    {
        $from = $request->input('from', date('Y-m-d', strtotime('-30 days')));
        $to = $request->input('to', date('Y-m-d'));
        $flaggedOnly = (bool) $request->input('flagged_only', false);

        $sql = 'SELECT r.*, t.full_name AS teacher_name, b.name AS branch_name,
                       c.class_date, c.time_slot, c.course_category
                FROM class_reviews r
                JOIN teachers t ON t.id = r.teacher_id
                JOIN classes c ON c.id = r.class_id
                JOIN branches b ON b.id = c.branch_id
                WHERE DATE(r.submitted_at) BETWEEN ? AND ?';
        $params = [$from, $to];

        if ($flaggedOnly) {
            $sql .= ' AND r.has_issue_flag = 1';
        }

        $sql .= ' ORDER BY r.submitted_at DESC LIMIT 200';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        Response::ok($stmt->fetchAll());
    }

    public function listTeachers(Request $request): void
    {
        $status = $request->input('status');
        $sql = 'SELECT t.*, u.email, u.status AS user_status, u.created_at AS registered_at
                FROM teachers t JOIN users u ON u.id = t.user_id WHERE 1=1';
        $params = [];
        if ($status) {
            $sql .= ' AND u.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY t.full_name ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        Response::ok($stmt->fetchAll());
    }

    public function setUserStatus(Request $request, int $userId): void
    {
        $status = (string) $request->input('status', '');
        if (!in_array($status, ['pending', 'active', 'inactive', 'suspended'], true)) {
            Response::error('Invalid status', 422);
        }

        $stmt = $this->db->prepare('UPDATE users SET status = ? WHERE id = ? AND role != \'super_admin\'');
        $stmt->execute([$status, $userId]);
        if ($stmt->rowCount() === 0) {
            Response::error('User not found or cannot be modified', 404);
        }

        Response::ok(null, 'User status updated');
    }

    public function listBranches(): void
    {
        $stmt = $this->db->query(
            'SELECT b.*, u.email AS manager_email
             FROM branches b
             LEFT JOIN users u ON u.id = b.manager_user_id
             ORDER BY b.name'
        );
        Response::ok($stmt->fetchAll());
    }

    public function createBranch(Request $request): void
    {
        $name = trim((string) $request->input('name', ''));
        $code = trim((string) $request->input('code', ''));
        $address = trim((string) $request->input('address', ''));
        $city = trim((string) $request->input('city', 'Dhaka'));
        $outside = (int) (bool) $request->input('is_outside_dhaka', false);
        $managerId = $request->input('manager_user_id');

        if ($name === '' || $code === '' || $address === '') {
            Response::error('name, code, address required', 422);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO branches (name, code, address, city, is_outside_dhaka, manager_user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $code, $address, $city, $outside, $managerId ? (int) $managerId : null]);

        Response::ok(['id' => (int) $this->db->lastInsertId()], 'Branch created');
    }
}
