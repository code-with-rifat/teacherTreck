<?php
require __DIR__ . '/includes/bootstrap.php';
logout_user();
flash('success', 'Signed out successfully.');
redirect('/teacher-traking/login.php');
