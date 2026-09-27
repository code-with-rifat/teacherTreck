-- MEDICO Class & Teacher Management System
-- MySQL 8 / MariaDB compatible schema

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Core auth & roles
-- ---------------------------------------------------------------------------

CREATE TABLE users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email             VARCHAR(191) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  role              ENUM('teacher','branch_manager','admin','super_admin') NOT NULL,
  status            ENUM('pending','active','inactive','suspended') NOT NULL DEFAULT 'pending',
  email_verified_at DATETIME NULL,
  last_login_at     DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, status)
) ENGINE=InnoDB;

CREATE TABLE email_codes (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           BIGINT UNSIGNED NOT NULL,
  purpose           ENUM('verify','reset') NOT NULL,
  code_hash         CHAR(64) NOT NULL,
  expires_at        DATETIME NOT NULL,
  used_at           DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_codes_lookup (code_hash, purpose),
  KEY idx_email_codes_user (user_id, purpose),
  CONSTRAINT fk_email_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           BIGINT UNSIGNED NOT NULL,
  token_hash        CHAR(64) NOT NULL,
  expires_at        DATETIME NOT NULL,
  used_at           DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_resets_token (token_hash),
  KEY idx_password_resets_user (user_id),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Teacher profiles (Module A)
-- ---------------------------------------------------------------------------

CREATE TABLE teachers (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id               BIGINT UNSIGNED NOT NULL,
  full_name             VARCHAR(150) NOT NULL,
  phone                 VARCHAR(30) NOT NULL,
  emergency_contact     VARCHAR(30) NOT NULL,
  date_of_birth         DATE NOT NULL,
  medical_college       VARCHAR(191) NOT NULL,
  address               TEXT NOT NULL,
  avatar_path           VARCHAR(255) NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_teachers_user (user_id),
  KEY idx_teachers_name (full_name),
  CONSTRAINT fk_teachers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Branches & geofencing (Modules B, E)
-- ---------------------------------------------------------------------------

CREATE TABLE branches (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(150) NOT NULL,
  code                  VARCHAR(50) NOT NULL,
  address               TEXT NOT NULL,
  phone                 VARCHAR(30) NULL,
  email                 VARCHAR(150) NULL,
  city                  VARCHAR(100) NOT NULL DEFAULT 'Dhaka',
  is_outside_dhaka      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Triggers transport review for teachers',
  latitude              DECIMAL(10,7) NULL,
  longitude             DECIMAL(10,7) NULL,
  geofence_radius_m     INT UNSIGNED NULL COMMENT 'NULL = use global setting',
  manager_user_id       BIGINT UNSIGNED NULL,
  setup_completed       TINYINT(1) NOT NULL DEFAULT 0,
  status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branches_code (code),
  KEY idx_branches_manager (manager_user_id),
  CONSTRAINT fk_branches_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE system_settings (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key       VARCHAR(100) NOT NULL,
  setting_value     TEXT NOT NULL,
  description       VARCHAR(255) NULL,
  updated_by        BIGINT UNSIGNED NULL,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_system_settings_key (setting_key),
  CONSTRAINT fk_system_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
  ('global_checkin_radius_m', '150', 'Default geofence radius in meters for teacher check-in'),
  ('google_maps_api_key', '', 'Google Maps JavaScript API key for branch location map'),
  ('app_name', 'MEDICO', 'Application display name'),
  ('timezone', 'Asia/Dhaka', 'Default application timezone');

-- ---------------------------------------------------------------------------
-- Class scheduling (Module B)
-- ---------------------------------------------------------------------------

CREATE TABLE classes (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id             BIGINT UNSIGNED NOT NULL,
  teacher_id            BIGINT UNSIGNED NOT NULL,
  class_date            DATE NOT NULL,
  time_slot             TIME NOT NULL COMMENT 'e.g. 07:00, 10:00, 13:00, 16:00',
  expected_arrival      TIME NULL COMMENT 'When teacher is expected to arrive',
  expected_students     INT UNSIGNED NULL COMMENT 'Pre-class expected student count',
  duration_minutes      SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  course_category       ENUM('1st_timer','2nd_timer') NOT NULL,
  subject               VARCHAR(120) NULL,
  lecture_no            SMALLINT UNSIGNED NULL,
  status                ENUM('scheduled','in_progress','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
  reminder_call_made    TINYINT(1) NOT NULL DEFAULT 0,
  reminder_call_at      DATETIME NULL,
  reminder_call_by      BIGINT UNSIGNED NULL,
  notes_internal        TEXT NULL,
  created_by            BIGINT UNSIGNED NOT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_classes_branch_date (branch_id, class_date),
  KEY idx_classes_teacher_date (teacher_id, class_date),
  KEY idx_classes_status (status),
  CONSTRAINT fk_classes_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_classes_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_classes_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_classes_reminder_by FOREIGN KEY (reminder_call_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Attendance / check-in sessions (Module C)
-- ---------------------------------------------------------------------------

CREATE TABLE class_sessions (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  class_id                  BIGINT UNSIGNED NOT NULL,
  check_in_at               DATETIME NULL,
  check_out_at              DATETIME NULL,
  teacher_class_start_at    DATETIME NULL,
  teacher_class_end_at      DATETIME NULL,
  teacher_times_saved_at    DATETIME NULL,
  manager_class_start_at    DATETIME NULL,
  manager_class_end_at      DATETIME NULL,
  manager_times_saved_at    DATETIME NULL,
  check_in_latitude         DECIMAL(10,7) NULL,
  check_in_longitude        DECIMAL(10,7) NULL,
  check_in_distance_m       DECIMAL(8,2) NULL,
  check_in_allowed_radius_m INT UNSIGNED NULL,
  teacher_notes             TEXT NULL,
  student_count_teacher     INT UNSIGNED NULL,
  count_best                INT UNSIGNED NULL,
  count_good                INT UNSIGNED NULL,
  count_bad                 INT UNSIGNED NULL,
  count_repeat              INT UNSIGNED NULL,
  manager_review_saved_at   DATETIME NULL,
  quality_rating            ENUM('good','best','average','repeat_class') NULL,
  student_count_manager     INT UNSIGNED NULL,
  signature_sheet_path      VARCHAR(255) NULL,
  verified_by               BIGINT UNSIGNED NULL,
  verified_at               DATETIME NULL,
  created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_class_sessions_class (class_id),
  CONSTRAINT fk_class_sessions_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_class_sessions_verified FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Teacher branch & logistics review (Module D) → Admin
-- ---------------------------------------------------------------------------

CREATE TABLE class_reviews (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  class_id                  BIGINT UNSIGNED NOT NULL,
  teacher_id                BIGINT UNSIGNED NOT NULL,

  -- Cleanliness
  cleanliness_staircase     ENUM('excellent','good','average','poor') NOT NULL,
  cleanliness_classroom     ENUM('excellent','good','average','poor') NOT NULL,
  cleanliness_teachers_room ENUM('excellent','good','average','poor') NOT NULL,

  -- Facilities & equipment
  facility_fan              ENUM('working','issue','not_available') NOT NULL,
  facility_ac               ENUM('working','issue','not_available') NOT NULL,
  facility_sound_system     ENUM('working','issue','not_available') NOT NULL,
  facility_projector        ENUM('working','issue','not_available') NOT NULL,

  -- Staff evaluation
  staff_dress_code          ENUM('excellent','good','average','poor') NOT NULL,
  staff_grooming            ENUM('excellent','good','average','poor') NOT NULL,
  staff_behavior            ENUM('excellent','good','average','poor') NOT NULL,

  -- Operations
  reminder_call_received    TINYINT(1) NOT NULL DEFAULT 0,

  -- Conditional transport (outside Dhaka only)
  transport_applicable      TINYINT(1) NOT NULL DEFAULT 0,
  transport_rating          ENUM('excellent','good','average','poor') NULL,
  transport_comments        TEXT NULL,

  additional_comments       TEXT NULL,
  has_issue_flag            TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Auto/manual flag for admin attention',
  submitted_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_class_reviews_class (class_id),
  KEY idx_class_reviews_flags (has_issue_flag, submitted_at),
  CONSTRAINT fk_class_reviews_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_class_reviews_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Notifications
-- ---------------------------------------------------------------------------

CREATE TABLE notifications (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           BIGINT UNSIGNED NOT NULL,
  title             VARCHAR(191) NOT NULL,
  body              TEXT NOT NULL,
  type              VARCHAR(50) NOT NULL DEFAULT 'info',
  related_type      VARCHAR(50) NULL,
  related_id        BIGINT UNSIGNED NULL,
  is_read           TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Audit log (super admin / compliance)
-- ---------------------------------------------------------------------------

CREATE TABLE audit_logs (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id     BIGINT UNSIGNED NULL,
  action            VARCHAR(100) NOT NULL,
  entity_type       VARCHAR(50) NULL,
  entity_id         BIGINT UNSIGNED NULL,
  meta_json         JSON NULL,
  ip_address        VARCHAR(45) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_actor (actor_user_id),
  KEY idx_audit_entity (entity_type, entity_id),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed: Super Admin — email: superadmin@medico.local  password: Admin@123
INSERT INTO users (email, password_hash, role, status, email_verified_at) VALUES
  ('superadmin@medico.local', '$2y$10$aDe0ZF.pW5go.yD3wGr0zuz8.0wiMKDpDYJO02sfy3U8LFobeKc1W', 'super_admin', 'active', NOW());

-- Helpful views for dashboards
CREATE OR REPLACE VIEW v_teacher_stats AS
SELECT
  t.id AS teacher_id,
  t.full_name,
  COUNT(DISTINCT c.id) AS total_scheduled,
  SUM(CASE WHEN c.status = 'completed' THEN 1 ELSE 0 END) AS total_completed,
  SUM(CASE WHEN cs.check_in_at IS NOT NULL THEN 1 ELSE 0 END) AS total_checkins,
  COALESCE(SUM(cs.count_best), 0) AS rating_best,
  COALESCE(SUM(cs.count_good), 0) AS rating_good,
  COALESCE(SUM(cs.count_bad), 0) AS rating_average,
  COALESCE(SUM(cs.count_repeat), 0) AS rating_repeat
FROM teachers t
LEFT JOIN classes c ON c.teacher_id = t.id
LEFT JOIN class_sessions cs ON cs.class_id = c.id
GROUP BY t.id, t.full_name;

