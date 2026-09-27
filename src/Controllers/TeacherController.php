<?php

declare(strict_types=1);

namespace Medico\Controllers;

use Medico\Http\Request;
use Medico\Http\Response;
use Medico\Support\Geo;
use PDO;

final class TeacherController
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
        $teacher = $this->requireTeacher($authUser);

        $upcoming = $this->db->prepare(
            'SELECT c.*, b.name AS branch_name, b.address AS branch_address, b.city,
                    b.is_outside_dhaka, b.latitude, b.longitude,
                    COALESCE(b.geofence_radius_m, (
                      SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key = \'global_checkin_radius_m\'
                    )) AS checkin_radius_m,
                    cs.check_in_at, cs.check_out_at, cs.quality_rating
             FROM classes c
             JOIN branches b ON b.id = c.branch_id
             LEFT JOIN class_sessions cs ON cs.class_id = c.id
             WHERE c.teacher_id = ? AND c.class_date >= CURDATE() AND c.status IN (\'scheduled\',\'in_progress\')
             ORDER BY c.class_date ASC, c.time_slot ASC
             LIMIT 20'
        );
        $upcoming->execute([(int) $teacher['id']]);

        $stats = $this->db->prepare('SELECT * FROM v_teacher_stats WHERE teacher_id = ?');
        $stats->execute([(int) $teacher['id']]);

        Response::ok([
            'teacher'  => $teacher,
            'upcoming' => $upcoming->fetchAll(),
            'stats'    => $stats->fetch() ?: null,
        ]);
    }

    public function myClasses(array $authUser, Request $request): void
    {
        $teacher = $this->requireTeacher($authUser);
        $status = $request->input('status');

        $sql = 'SELECT c.*, b.name AS branch_name, b.is_outside_dhaka,
                       cs.check_in_at, cs.check_out_at, cs.student_count_teacher, cs.quality_rating
                FROM classes c
                JOIN branches b ON b.id = c.branch_id
                LEFT JOIN class_sessions cs ON cs.class_id = c.id
                WHERE c.teacher_id = ?';
        $params = [(int) $teacher['id']];

        if ($status) {
            $sql .= ' AND c.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY c.class_date DESC, c.time_slot DESC LIMIT 100';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        Response::ok($stmt->fetchAll());
    }

    public function checkIn(array $authUser, Request $request, int $classId): void
    {
        $teacher = $this->requireTeacher($authUser);
        $lat = (float) $request->input('latitude', 0);
        $lng = (float) $request->input('longitude', 0);

        if ($lat === 0.0 && $lng === 0.0) {
            Response::error('GPS coordinates required', 422);
        }

        $class = $this->getTeacherClass($classId, (int) $teacher['id']);
        if (!$class) {
            Response::error('Class not found', 404);
        }
        if (!in_array($class['status'], ['scheduled', 'in_progress'], true)) {
            Response::error('Class is not available for check-in', 400);
        }
        if (empty($class['latitude']) || empty($class['longitude'])) {
            Response::error('Branch location not configured', 400);
        }

        $radius = (float) ($class['checkin_radius_m'] ?? $this->config['geofence']['default_radius_m']);
        $distance = Geo::distanceMeters(
            $lat,
            $lng,
            (float) $class['latitude'],
            (float) $class['longitude']
        );

        if ($distance > $radius) {
            Response::error('You are outside the check-in radius', 403, [
                'distance_m' => round($distance, 2),
                'allowed_m'  => $radius,
            ]);
        }

        $existing = $this->db->prepare('SELECT id, check_in_at FROM class_sessions WHERE class_id = ?');
        $existing->execute([$classId]);
        $session = $existing->fetch();

        if ($session && $session['check_in_at']) {
            Response::error('Already checked in', 409);
        }

        $this->db->beginTransaction();
        try {
            if ($session) {
                $this->db->prepare(
                    'UPDATE class_sessions SET check_in_at = NOW(), check_in_latitude = ?, check_in_longitude = ?,
                     check_in_distance_m = ?, check_in_allowed_radius_m = ? WHERE id = ?'
                )->execute([$lat, $lng, round($distance, 2), (int) $radius, (int) $session['id']]);
            } else {
                $this->db->prepare(
                    'INSERT INTO class_sessions
                     (class_id, check_in_at, check_in_latitude, check_in_longitude, check_in_distance_m, check_in_allowed_radius_m)
                     VALUES (?, NOW(), ?, ?, ?, ?)'
                )->execute([$classId, $lat, $lng, round($distance, 2), (int) $radius]);
            }

            $this->db->prepare('UPDATE classes SET status = \'in_progress\' WHERE id = ?')
                ->execute([$classId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Response::error('Check-in failed', 500);
        }

        Response::ok([
            'distance_m' => round($distance, 2),
            'allowed_m'  => $radius,
            'checked_in' => true,
        ], 'Check-in successful');
    }

    public function checkOut(array $authUser, Request $request, int $classId): void
    {
        $teacher = $this->requireTeacher($authUser);
        $class = $this->getTeacherClass($classId, (int) $teacher['id']);
        if (!$class) {
            Response::error('Class not found', 404);
        }

        $notes = trim((string) $request->input('teacher_notes', ''));
        $studentCount = $request->input('student_count_teacher');
        $quality = $request->input('quality_rating');

        $allowedRatings = ['good', 'best', 'average', 'repeat_class'];
        if ($quality !== null && !in_array($quality, $allowedRatings, true)) {
            Response::error('Invalid quality rating', 422);
        }

        $sessionStmt = $this->db->prepare('SELECT * FROM class_sessions WHERE class_id = ?');
        $sessionStmt->execute([$classId]);
        $session = $sessionStmt->fetch();

        if (!$session || !$session['check_in_at']) {
            Response::error('Check-in required before check-out', 400);
        }
        if ($session['check_out_at']) {
            Response::error('Already checked out', 409);
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE class_sessions SET check_out_at = NOW(), teacher_notes = ?,
                 student_count_teacher = ?, quality_rating = ? WHERE id = ?'
            )->execute([
                $notes ?: null,
                $studentCount !== null ? (int) $studentCount : null,
                $quality,
                (int) $session['id'],
            ]);

            $this->db->prepare('UPDATE classes SET status = \'completed\' WHERE id = ?')
                ->execute([$classId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Response::error('Check-out failed', 500);
        }

        Response::ok([
            'requires_review'     => true,
            'transport_applicable'=> (bool) $class['is_outside_dhaka'],
        ], 'Class ended successfully');
    }

    public function submitReview(array $authUser, Request $request, int $classId): void
    {
        $teacher = $this->requireTeacher($authUser);
        $class = $this->getTeacherClass($classId, (int) $teacher['id']);
        if (!$class) {
            Response::error('Class not found', 404);
        }

        $fields = [
            'cleanliness_staircase', 'cleanliness_classroom', 'cleanliness_teachers_room',
            'facility_fan', 'facility_ac', 'facility_sound_system', 'facility_projector',
            'staff_dress_code', 'staff_grooming', 'staff_behavior',
        ];
        $data = [];
        foreach ($fields as $f) {
            $v = $request->input($f);
            if ($v === null || $v === '') {
                Response::error("Missing field: {$f}", 422);
            }
            $data[$f] = $v;
        }

        $reminderReceived = (int) (bool) $request->input('reminder_call_received', false);
        $outsideDhaka = (bool) $class['is_outside_dhaka'];
        $transportRating = $request->input('transport_rating');
        $transportComments = trim((string) $request->input('transport_comments', ''));

        if ($outsideDhaka && !$transportRating) {
            Response::error('Transport review required for classes outside Dhaka', 422);
        }

        $dup = $this->db->prepare('SELECT id FROM class_reviews WHERE class_id = ?');
        $dup->execute([$classId]);
        if ($dup->fetch()) {
            Response::error('Review already submitted', 409);
        }

        $issueFlag = $this->detectIssueFlag($data, $transportRating);

        $stmt = $this->db->prepare(
            'INSERT INTO class_reviews (
                class_id, teacher_id,
                cleanliness_staircase, cleanliness_classroom, cleanliness_teachers_room,
                facility_fan, facility_ac, facility_sound_system, facility_projector,
                staff_dress_code, staff_grooming, staff_behavior,
                reminder_call_received, transport_applicable, transport_rating, transport_comments,
                additional_comments, has_issue_flag
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $stmt->execute([
            $classId,
            (int) $teacher['id'],
            $data['cleanliness_staircase'],
            $data['cleanliness_classroom'],
            $data['cleanliness_teachers_room'],
            $data['facility_fan'],
            $data['facility_ac'],
            $data['facility_sound_system'],
            $data['facility_projector'],
            $data['staff_dress_code'],
            $data['staff_grooming'],
            $data['staff_behavior'],
            $reminderReceived,
            (int) $outsideDhaka,
            $outsideDhaka ? $transportRating : null,
            $outsideDhaka ? ($transportComments ?: null) : null,
            trim((string) $request->input('additional_comments', '')) ?: null,
            (int) $issueFlag,
        ]);

        Response::ok(null, 'Branch & logistics review submitted');
    }

    private function detectIssueFlag(array $data, ?string $transportRating): bool
    {
        $poorClean = ['poor'];
        $facilityIssue = ['issue', 'not_available'];
        $poorStaff = ['poor'];

        foreach (['cleanliness_staircase', 'cleanliness_classroom', 'cleanliness_teachers_room'] as $k) {
            if (in_array($data[$k], $poorClean, true)) {
                return true;
            }
        }
        foreach (['facility_fan', 'facility_ac', 'facility_sound_system', 'facility_projector'] as $k) {
            if (in_array($data[$k], $facilityIssue, true)) {
                return true;
            }
        }
        foreach (['staff_dress_code', 'staff_grooming', 'staff_behavior'] as $k) {
            if (in_array($data[$k], $poorStaff, true)) {
                return true;
            }
        }
        if ($transportRating === 'poor') {
            return true;
        }
        return false;
    }

    private function requireTeacher(array $authUser): array
    {
        $stmt = $this->db->prepare('SELECT * FROM teachers WHERE user_id = ?');
        $stmt->execute([(int) $authUser['id']]);
        $teacher = $stmt->fetch();
        if (!$teacher) {
            Response::error('Teacher profile not found', 404);
        }
        return $teacher;
    }

    private function getTeacherClass(int $classId, int $teacherId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, b.latitude, b.longitude, b.is_outside_dhaka,
                    COALESCE(b.geofence_radius_m, (
                      SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key = \'global_checkin_radius_m\'
                    )) AS checkin_radius_m
             FROM classes c
             JOIN branches b ON b.id = c.branch_id
             WHERE c.id = ? AND c.teacher_id = ?'
        );
        $stmt->execute([$classId, $teacherId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
