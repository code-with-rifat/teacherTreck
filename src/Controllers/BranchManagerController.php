<?php

declare(strict_types=1);

namespace Medico\Controllers;

use Medico\Http\Request;
use Medico\Http\Response;
use PDO;

final class BranchManagerController
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
        $branch = $this->requireBranch($authUser);

        $today = $this->db->prepare(
            'SELECT c.*, t.full_name AS teacher_name, t.phone AS teacher_phone,
                    cs.check_in_at, cs.check_out_at, cs.student_count_teacher, cs.student_count_manager,
                    cs.signature_sheet_path
             FROM classes c
             JOIN teachers t ON t.id = c.teacher_id
             LEFT JOIN class_sessions cs ON cs.class_id = c.id
             WHERE c.branch_id = ? AND c.class_date = CURDATE()
             ORDER BY c.time_slot ASC'
        );
        $today->execute([(int) $branch['id']]);

        $counts = $this->db->prepare(
            'SELECT
                SUM(CASE WHEN class_date = CURDATE() THEN 1 ELSE 0 END) AS today_total,
                SUM(CASE WHEN class_date = CURDATE() AND status = \'completed\' THEN 1 ELSE 0 END) AS today_done,
                SUM(CASE WHEN reminder_call_made = 0 AND class_date = CURDATE() AND status = \'scheduled\' THEN 1 ELSE 0 END) AS pending_reminders
             FROM classes WHERE branch_id = ?'
        );
        $counts->execute([(int) $branch['id']]);

        Response::ok([
            'branch'  => $branch,
            'today'   => $today->fetchAll(),
            'metrics' => $counts->fetch() ?: [],
        ]);
    }

    public function setupLocation(array $authUser, Request $request): void
    {
        $branch = $this->requireBranch($authUser);
        $lat = $request->input('latitude');
        $lng = $request->input('longitude');
        $address = trim((string) $request->input('address', ''));

        if ($lat === null || $lng === null) {
            Response::error('Latitude and longitude required', 422);
        }

        $this->db->prepare(
            'UPDATE branches SET latitude = ?, longitude = ?, address = COALESCE(NULLIF(?, \'\'), address),
             setup_completed = 1 WHERE id = ?'
        )->execute([(float) $lat, (float) $lng, $address, (int) $branch['id']]);

        Response::ok(null, 'Branch location saved');
    }

    public function listTeachers(array $authUser): void
    {
        $this->requireBranch($authUser);
        $stmt = $this->db->query(
            'SELECT t.id, t.full_name, t.phone, t.medical_college, u.email, u.status
             FROM teachers t
             JOIN users u ON u.id = t.user_id
             WHERE u.status = \'active\'
             ORDER BY t.full_name ASC'
        );
        Response::ok($stmt->fetchAll());
    }

    public function createClass(array $authUser, Request $request): void
    {
        $branch = $this->requireBranch($authUser);

        $teacherId = (int) $request->input('teacher_id', 0);
        $classDate = (string) $request->input('class_date', '');
        $timeSlot = (string) $request->input('time_slot', '');
        $category = (string) $request->input('course_category', '');
        $duration = (int) $request->input('duration_minutes', 120);

        if (!$teacherId || !$classDate || !$timeSlot || !in_array($category, ['1st_timer', '2nd_timer'], true)) {
            Response::error('teacher_id, class_date, time_slot, course_category required', 422);
        }

        $teacherOk = $this->db->prepare(
            'SELECT t.id FROM teachers t JOIN users u ON u.id = t.user_id WHERE t.id = ? AND u.status = \'active\''
        );
        $teacherOk->execute([$teacherId]);
        if (!$teacherOk->fetch()) {
            Response::error('Teacher not found or inactive', 404);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO classes (branch_id, teacher_id, class_date, time_slot, duration_minutes, course_category, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $branch['id'],
            $teacherId,
            $classDate,
            $timeSlot,
            $duration,
            $category,
            (int) $authUser['id'],
        ]);

        $classId = (int) $this->db->lastInsertId();

        // Notify teacher
        $tUser = $this->db->prepare('SELECT user_id FROM teachers WHERE id = ?');
        $tUser->execute([$teacherId]);
        $tu = $tUser->fetch();
        if ($tu) {
            $this->db->prepare(
                'INSERT INTO notifications (user_id, title, body, type, related_type, related_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                (int) $tu['user_id'],
                'New class assigned',
                "You have a new class on {$classDate} at {$timeSlot} ({$branch['name']}).",
                'class',
                'class',
                $classId,
            ]);
        }

        Response::ok(['id' => $classId], 'Class scheduled');
    }

    public function markReminder(array $authUser, int $classId): void
    {
        $branch = $this->requireBranch($authUser);
        $stmt = $this->db->prepare('SELECT id FROM classes WHERE id = ? AND branch_id = ?');
        $stmt->execute([$classId, (int) $branch['id']]);
        if (!$stmt->fetch()) {
            Response::error('Class not found', 404);
        }

        $this->db->prepare(
            'UPDATE classes SET reminder_call_made = 1, reminder_call_at = NOW(), reminder_call_by = ? WHERE id = ?'
        )->execute([(int) $authUser['id'], $classId]);

        Response::ok(null, 'Reminder call logged');
    }

    public function verifyAttendance(array $authUser, Request $request, int $classId): void
    {
        $branch = $this->requireBranch($authUser);
        $classStmt = $this->db->prepare('SELECT id FROM classes WHERE id = ? AND branch_id = ?');
        $classStmt->execute([$classId, (int) $branch['id']]);
        if (!$classStmt->fetch()) {
            Response::error('Class not found', 404);
        }

        $count = $request->input('student_count_manager');
        if ($count === null) {
            Response::error('student_count_manager required', 422);
        }

        $path = null;
        if (!empty($_FILES['signature_sheet']) && $_FILES['signature_sheet']['error'] === UPLOAD_ERR_OK) {
            $path = $this->storeSignature($_FILES['signature_sheet'], $classId);
        } elseif ($request->input('signature_sheet_path')) {
            $path = (string) $request->input('signature_sheet_path');
        }

        $session = $this->db->prepare('SELECT id FROM class_sessions WHERE class_id = ?');
        $session->execute([$classId]);
        $row = $session->fetch();

        if ($row) {
            $this->db->prepare(
                'UPDATE class_sessions SET student_count_manager = ?, signature_sheet_path = COALESCE(?, signature_sheet_path),
                 verified_by = ?, verified_at = NOW() WHERE id = ?'
            )->execute([(int) $count, $path, (int) $authUser['id'], (int) $row['id']]);
        } else {
            $this->db->prepare(
                'INSERT INTO class_sessions (class_id, student_count_manager, signature_sheet_path, verified_by, verified_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([$classId, (int) $count, $path, (int) $authUser['id']]);
        }

        Response::ok(['signature_sheet_path' => $path], 'Attendance verified');
    }

    public function listClasses(array $authUser, Request $request): void
    {
        $branch = $this->requireBranch($authUser);
        $from = $request->input('from', date('Y-m-d', strtotime('-7 days')));
        $to = $request->input('to', date('Y-m-d', strtotime('+14 days')));

        $stmt = $this->db->prepare(
            'SELECT c.*, t.full_name AS teacher_name, cs.check_in_at, cs.check_out_at,
                    cs.student_count_teacher, cs.student_count_manager, cs.signature_sheet_path
             FROM classes c
             JOIN teachers t ON t.id = c.teacher_id
             LEFT JOIN class_sessions cs ON cs.class_id = c.id
             WHERE c.branch_id = ? AND c.class_date BETWEEN ? AND ?
             ORDER BY c.class_date DESC, c.time_slot ASC'
        );
        $stmt->execute([(int) $branch['id'], $from, $to]);
        Response::ok($stmt->fetchAll());
    }

    private function storeSignature(array $file, int $classId): string
    {
        $dir = $this->config['upload']['signature_sheets'];
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $this->config['upload']['allowed_mimes'], true)) {
            Response::error('Invalid file type for signature sheet', 422);
        }
        if ($file['size'] > $this->config['upload']['max_bytes']) {
            Response::error('File too large', 422);
        }

        switch ($mime) {
            case 'image/jpeg':
                $ext = 'jpg';
                break;
            case 'image/png':
                $ext = 'png';
                break;
            case 'image/webp':
                $ext = 'webp';
                break;
            case 'application/pdf':
                $ext = 'pdf';
                break;
            default:
                $ext = 'bin';
                break;
        }

        $name = 'sig_class_' . $classId . '_' . time() . '.' . $ext;
        $dest = $dir . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Response::error('Upload failed', 500);
        }

        return 'storage/uploads/signatures/' . $name;
    }

    private function requireBranch(array $authUser): array
    {
        $stmt = $this->db->prepare('SELECT * FROM branches WHERE manager_user_id = ? AND status = \'active\'');
        $stmt->execute([(int) $authUser['id']]);
        $branch = $stmt->fetch();
        if (!$branch) {
            Response::error('No branch assigned to this manager', 403);
        }
        return $branch;
    }
}
