<?php
/**
 * MEDICO CMS — Application configuration
 */

declare(strict_types=1);

return [
    'app_name'   => 'MEDICO',
    'app_tagline'=> 'Class & Teacher Management',
    // Live: public_html/teacher-traking  |  Local XAMPP: '/teacherTreck'
    'base_url'   => '/teacher-traking',
    'api_prefix' => '/teacher-traking/api',
    'timezone'   => 'Asia/Dhaka',
    'debug'      => true,

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'medicoweb_teacherTraking',
        'user'    => 'medicoweb_teacher_traking',
        'pass'    => 'Rifatmm45@',
        'charset' => 'utf8mb4',
    ],

    'jwt' => [
        // Change in production
        'secret'  => 'medico-change-me-in-production-32chars!',
        'ttl'     => 86400 * 7, // 7 days
        'issuer'  => 'medico-cms',
    ],

    'upload' => [
        'signature_sheets' => __DIR__ . '/../teacher-traking/storage/uploads/signatures',
        'avatars'          => __DIR__ . '/../teacher-traking/storage/uploads/avatars',
        'max_bytes'        => 5 * 1024 * 1024,
        'allowed_mimes'    => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    ],

    'geofence' => [
        'default_radius_m' => 150,
    ],

    // Google Maps JavaScript API key (Maps + Geolocation). Also editable in Admin → Dashboard.
    'google_maps_api_key' => '',

    /*
     | Mail — password reset codes
     | Gmail: enable 2FA → create App Password → put below.
     | smtp_user / smtp_pass খালি রাখলে email যাবে না।
     */
    'mail' => [
        'driver'           => 'smtp', // smtp | mail | log
        'from_email'       => 'noreply@medico.local',
        'from_name'        => 'MEDICO',
        'smtp_host'        => 'smtp.gmail.com',
        'smtp_port'        => 587,
        'smtp_encryption'  => 'tls', // tls | ssl
        'smtp_user'        => '', // e.g. your@gmail.com
        'smtp_pass'        => '', // Gmail App Password
    ],
];
