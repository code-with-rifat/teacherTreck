SET @tid = (SELECT teacher_id FROM classes WHERE id = 30);

DELETE FROM class_reviews WHERE class_id = 30;

INSERT INTO class_reviews (
  class_id, teacher_id,
  cleanliness_staircase, cleanliness_classroom, cleanliness_teachers_room,
  facility_fan, facility_ac, facility_sound_system, facility_projector,
  staff_dress_code, staff_grooming, staff_behavior,
  reminder_call_received, transport_applicable, transport_rating, transport_comments,
  additional_comments, evidence_photos, issue_notes, has_issue_flag, submitted_at
) VALUES (
  30, @tid,
  'good', 'average', 'good',
  'working', 'issue', 'working', 'working',
  'good', 'good', 'excellent',
  1, 0, NULL, NULL,
  'Demo review for admin — AC was not cooling properly in the classroom.',
  '["storage/uploads/reviews/c30_demo_issue.jpg"]',
  '{"facility_ac":"AC makes noise and room stays warm after 30 minutes."}',
  1, NOW()
);

UPDATE class_sessions SET
  teacher_start_notes = 'Started on time. Students settling in.',
  teacher_notes = 'Lec 11 covered. AC issue noted for branch.',
  manager_class_start_at = '2026-09-27 12:15:00',
  manager_class_end_at = '2026-09-27 13:05:00',
  manager_times_saved_at = NOW(),
  student_count_manager = 42,
  count_best = 10,
  count_good = 22,
  count_bad = 6,
  count_repeat = 4,
  manager_review_saved_at = NOW()
WHERE class_id = 30;

SELECT id, class_id, has_issue_flag, facility_ac, evidence_photos FROM class_reviews WHERE class_id = 30;
