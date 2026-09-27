<?php
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if ($user) {
    redirect(role_home($user['role']));
}
redirect('/teacherTreck/login.php');
