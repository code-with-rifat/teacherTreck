# MEDICO API Reference

Base URL: `/teacherTreck/api`  
Auth: `Authorization: Bearer <jwt>`

## Public

| Method | Path | Description |
|--------|------|-------------|
| POST | `/auth/register` | Teacher sign-up |
| POST | `/auth/login` | Login → JWT |
| POST | `/auth/forgot-password` | Password recovery |
| POST | `/auth/reset-password` | Reset with token |
| GET | `/health` | Health check |

### Register body
`full_name`, `phone`, `emergency_contact`, `date_of_birth`, `medical_college`, `email`, `address`, `password`

### Login body
`email`, `password`

---

## Teacher (`role: teacher`)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/teacher/dashboard` | Upcoming classes + stats |
| GET | `/teacher/classes` | Class history (`?status=`) |
| POST | `/teacher/classes/{id}/check-in` | Geofenced check-in |
| POST | `/teacher/classes/{id}/check-out` | End class + counts/rating |
| POST | `/teacher/classes/{id}/review` | Branch & logistics review |

### Check-in body
`latitude`, `longitude`

### Check-out body
`student_count_teacher`, `quality_rating` (`good|best|average|repeat_class`), `teacher_notes`

### Review body
Cleanliness (`excellent|good|average|poor`): `cleanliness_staircase`, `cleanliness_classroom`, `cleanliness_teachers_room`  
Facilities (`working|issue|not_available`): `facility_fan`, `facility_ac`, `facility_sound_system`, `facility_projector`  
Staff: `staff_dress_code`, `staff_grooming`, `staff_behavior`  
Ops: `reminder_call_received`  
Transport (required if branch `is_outside_dhaka`): `transport_rating`, `transport_comments`  
Optional: `additional_comments`

---

## Branch Manager (`role: branch_manager`)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/manager/dashboard` | Today’s board + metrics |
| POST | `/manager/branch/location` | Set geofence coordinates |
| GET | `/manager/teachers` | Active teacher pool |
| GET | `/manager/classes` | Classes (`?from=&to=`) |
| POST | `/manager/classes` | Create schedule |
| POST | `/manager/classes/{id}/reminder` | Log reminder call |
| POST | `/manager/classes/{id}/verify` | Student count + signature sheet |

### Create class body
`teacher_id`, `class_date`, `time_slot` (`HH:MM:SS`), `course_category` (`1st_timer|2nd_timer`), optional `duration_minutes`

### Verify
`multipart/form-data`: `student_count_manager`, `signature_sheet` (file)

---

## Admin (`role: admin` or `super_admin`)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/admin/dashboard` | Network metrics + issues |
| GET | `/admin/geofence` | Global + branch radii |
| PUT | `/admin/geofence` | Update radii |
| GET | `/admin/quality-reports` | Logistics reviews (`?flagged_only=1`) |
| GET | `/admin/teachers` | Teacher list (`?status=`) |
| PATCH | `/admin/users/{id}/status` | Activate / suspend |
| GET | `/admin/branches` | List branches |
| POST | `/admin/branches` | Create branch |

---

## Super Admin (`role: super_admin`)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/super/users` | Create any role |
| POST | `/super/assign-manager` | Bind manager → branch |
| GET/POST | `/super/settings` | Read / upsert settings |
| GET | `/super/audit-logs` | Audit trail |

---

## Role matrix

| Capability | Teacher | Manager | Admin | Super |
|------------|:-------:|:-------:|:-----:|:-----:|
| Geofenced check-in | ✓ | | | |
| Class logistics review | ✓ | | view | view |
| Schedule classes (own branch) | | ✓ | | |
| Verify signature sheets | | ✓ | | |
| Configure geofence radius | | | ✓ | ✓ |
| Activate teachers | | | ✓ | ✓ |
| Create roles / assign managers | | | | ✓ |
