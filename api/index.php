<?php
/**
 * MEDICO CMS — API entrypoint
 *
 * Base: /api
 */

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$config = require __DIR__ . '/bootstrap.php';

use Medico\Auth\AuthMiddleware;
use Medico\Controllers\AdminController;
use Medico\Controllers\AuthController;
use Medico\Controllers\BranchManagerController;
use Medico\Controllers\SuperAdminController;
use Medico\Controllers\TeacherController;
use Medico\Database\Connection;
use Medico\Http\Request;
use Medico\Http\Response;

try {
    $db = Connection::get($config['db']);
} catch (Throwable $e) {
    Response::error('Database unavailable. Import database/schema.sql first.', 503, [
        'detail' => $config['debug'] ? $e->getMessage() : null,
    ]);
}

$request = new Request();
$authMw = new AuthMiddleware($db, $config['jwt']);

$authCtrl = new AuthController($db, $config);
$teacherCtrl = new TeacherController($db, $config);
$managerCtrl = new BranchManagerController($db, $config);
$adminCtrl = new AdminController($db, $config);
$superCtrl = new SuperAdminController($db);

$method = $request->method;
$path = $request->path;

// ---------------------------------------------------------------------------
// Public auth
// ---------------------------------------------------------------------------
if ($method === 'POST' && $path === '/auth/register') {
    $authCtrl->register($request);
}
if ($method === 'POST' && $path === '/auth/login') {
    $authCtrl->login($request);
}
if ($method === 'POST' && $path === '/auth/forgot-password') {
    $authCtrl->forgotPassword($request);
}
if ($method === 'POST' && $path === '/auth/reset-password') {
    $authCtrl->resetPassword($request);
}

// ---------------------------------------------------------------------------
// Authenticated
// ---------------------------------------------------------------------------
if ($method === 'GET' && $path === '/auth/me') {
    $user = $authMw->requireUser($request);
    $authCtrl->me($user);
}

// Teacher
if ($method === 'GET' && $path === '/teacher/dashboard') {
    $user = $authMw->requireUser($request, ['teacher']);
    $teacherCtrl->dashboard($user);
}
if ($method === 'GET' && $path === '/teacher/classes') {
    $user = $authMw->requireUser($request, ['teacher']);
    $teacherCtrl->myClasses($user, $request);
}
if ($method === 'POST' && preg_match('#^/teacher/classes/(\d+)/check-in$#', $path, $m)) {
    $user = $authMw->requireUser($request, ['teacher']);
    $teacherCtrl->checkIn($user, $request, (int) $m[1]);
}
if ($method === 'POST' && preg_match('#^/teacher/classes/(\d+)/check-out$#', $path, $m)) {
    $user = $authMw->requireUser($request, ['teacher']);
    $teacherCtrl->checkOut($user, $request, (int) $m[1]);
}
if ($method === 'POST' && preg_match('#^/teacher/classes/(\d+)/review$#', $path, $m)) {
    $user = $authMw->requireUser($request, ['teacher']);
    $teacherCtrl->submitReview($user, $request, (int) $m[1]);
}

// Branch Manager
if ($method === 'GET' && $path === '/manager/dashboard') {
    $user = $authMw->requireUser($request, ['branch_manager']);
    $managerCtrl->dashboard($user);
}
if ($method === 'POST' && $path === '/manager/branch/location') {
    $user = $authMw->requireUser($request, ['branch_manager']);
    $managerCtrl->setupLocation($user, $request);
}
if ($method === 'GET' && $path === '/manager/teachers') {
    $user = $authMw->requireUser($request, ['branch_manager']);
    $managerCtrl->listTeachers($user);
}
if ($method === 'GET' && $path === '/manager/classes') {
    $user = $authMw->requireUser($request, ['branch_manager']);
    $managerCtrl->listClasses($user, $request);
}
if ($method === 'POST' && $path === '/manager/classes') {
    $user = $authMw->requireUser($request, ['branch_manager']);
    $managerCtrl->createClass($user, $request);
}
if ($method === 'POST' && preg_match('#^/manager/classes/(\d+)/reminder$#', $path, $m)) {
    $user = $authMw->requireUser($request, ['branch_manager']);
    $managerCtrl->markReminder($user, (int) $m[1]);
}
if ($method === 'POST' && preg_match('#^/manager/classes/(\d+)/verify$#', $path, $m)) {
    $user = $authMw->requireUser($request, ['branch_manager']);
    $managerCtrl->verifyAttendance($user, $request, (int) $m[1]);
}

// Admin
if ($method === 'GET' && $path === '/admin/dashboard') {
    $user = $authMw->requireUser($request, ['admin', 'super_admin']);
    $adminCtrl->dashboard($user);
}
if ($method === 'GET' && $path === '/admin/geofence') {
    $user = $authMw->requireUser($request, ['admin', 'super_admin']);
    $adminCtrl->getGeofenceSettings();
}
if ($method === 'PUT' && $path === '/admin/geofence') {
    $user = $authMw->requireUser($request, ['admin', 'super_admin']);
    $adminCtrl->updateGeofence($request);
}
if ($method === 'GET' && $path === '/admin/quality-reports') {
    $user = $authMw->requireUser($request, ['admin', 'super_admin']);
    $adminCtrl->qualityReports($request);
}
if ($method === 'GET' && $path === '/admin/teachers') {
    $user = $authMw->requireUser($request, ['admin', 'super_admin']);
    $adminCtrl->listTeachers($request);
}
if ($method === 'PATCH' && preg_match('#^/admin/users/(\d+)/status$#', $path, $m)) {
    $user = $authMw->requireUser($request, ['admin', 'super_admin']);
    $adminCtrl->setUserStatus($request, (int) $m[1]);
}
if ($method === 'GET' && $path === '/admin/branches') {
    $user = $authMw->requireUser($request, ['admin', 'super_admin']);
    $adminCtrl->listBranches();
}
if ($method === 'POST' && $path === '/admin/branches') {
    $user = $authMw->requireUser($request, ['admin', 'super_admin']);
    $adminCtrl->createBranch($request);
}

// Super Admin
if ($method === 'POST' && $path === '/super/users') {
    $user = $authMw->requireUser($request, ['super_admin']);
    $superCtrl->createUser($request);
}
if ($method === 'POST' && $path === '/super/assign-manager') {
    $user = $authMw->requireUser($request, ['super_admin']);
    $superCtrl->assignManager($request);
}
if (($method === 'GET' || $method === 'POST') && $path === '/super/settings') {
    $user = $authMw->requireUser($request, ['super_admin']);
    $superCtrl->systemSettings($request);
}
if ($method === 'GET' && $path === '/super/audit-logs') {
    $user = $authMw->requireUser($request, ['super_admin']);
    $superCtrl->auditLogs($request);
}

if ($method === 'GET' && $path === '/health') {
    Response::ok(['app' => $config['app_name'], 'time' => date('c')], 'healthy');
}

Response::error('Endpoint not found', 404, ['path' => $path, 'method' => $method]);
