-- Performance seed: 500 events (250 class + 250 exam) across 30 days
-- Base time: 2026-07-13 00:00:00 UTC = 1752364800
-- Run against the Moodle test database (phpu_ prefix) after phpunit init.
-- Usage: mysql -u <user> -p <db> < seed_perf.sql

-- ============================================================
-- Shared setup: test users
-- ============================================================
-- We assume a Moodle user with id=2 (admin) exists.
-- Adjust @STUDENT_ID if running on a fresh install.

SET @BASE_TIME  = 1752364800;
SET @DAY        = 86400;
SET @STUDENT_ID = 2;  -- admin user as stand-in; replace with a real student id.

-- ============================================================
-- 1. Facetoface activity + 250 sessions (class events)
-- ============================================================

INSERT INTO mdl_course (fullname, shortname, category, summary, format, startdate, timecreated, timemodified, visible)
VALUES ('Perf Test Course', 'PERF01', 1, '', 'topics', @BASE_TIME, @BASE_TIME, @BASE_TIME, 1);

SET @COURSE_ID = LAST_INSERT_ID();

INSERT INTO mdl_facetoface (course, name, intro, introformat, timecreated, timemodified)
VALUES (@COURSE_ID, 'Perf Facetoface', '', 1, @BASE_TIME, @BASE_TIME);

SET @FF_ID = LAST_INSERT_ID();

-- Active user enrolment so the JOIN in get_events passes.
INSERT INTO mdl_enrol (enrol, status, courseid, timecreated, timemodified)
VALUES ('manual', 0, @COURSE_ID, @BASE_TIME, @BASE_TIME);

SET @ENROL_ID = LAST_INSERT_ID();

INSERT INTO mdl_user_enrolments (status, enrolid, userid, timecreated, timemodified)
VALUES (0, @ENROL_ID, @STUDENT_ID, @BASE_TIME, @BASE_TIME);

-- Generate 250 facetoface sessions (one every ~2.88 hours across 30 days).
-- MySQL does not have generate_series; use a numbers table approach.

DROP TEMPORARY TABLE IF EXISTS tmp_nums;
CREATE TEMPORARY TABLE tmp_nums (n INT);

INSERT INTO tmp_nums (n)
SELECT a.N + b.N * 10 + c.N * 100
FROM
  (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
   UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
  (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
   UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b,
  (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2) c
HAVING n BETWEEN 1 AND 250;

INSERT INTO mdl_facetoface_sessions
  (facetoface, capacity, allowoverbook, waitlisteveryone, duration,
   normalcost, discountcost, timestart, timefinish, trainerid, location,
   timecreated, timemodified)
SELECT
  @FF_ID,
  30,
  0,
  0,
  3600,
  0,
  0,
  @BASE_TIME + (n - 1) * FLOOR(30 * @DAY / 250),
  @BASE_TIME + (n - 1) * FLOOR(30 * @DAY / 250) + 3600,
  0,
  CONCAT('Room ', n),
  @BASE_TIME,
  @BASE_TIME
FROM tmp_nums;

-- Signup the student for all 250 sessions (statuscode=70 = booked).
INSERT INTO mdl_facetoface_signups (sessionid, userid, mailedreminder, discountcode,
    notificationtype, statuscode, timecreated, timemodified)
SELECT id, @STUDENT_ID, 0, '', 0, 70, @BASE_TIME, @BASE_TIME
FROM mdl_facetoface_sessions
WHERE facetoface = @FF_ID;

-- ============================================================
-- 2. Exam sessions (250 exam events)
-- ============================================================

INSERT INTO mdl_vbs_exam_group (name, parentid, sortorder, timecreated, timemodified)
VALUES ('Perf Group', 0, 0, @BASE_TIME, @BASE_TIME);

SET @GROUP_ID = LAST_INSERT_ID();

INSERT INTO mdl_vbs_exam_topic (groupid, name, quizid, timecreated, timemodified)
VALUES (@GROUP_ID, 'Perf Topic', 0, @BASE_TIME, @BASE_TIME);

SET @TOPIC_ID = LAST_INSERT_ID();

INSERT INTO mdl_vbs_exam_session
  (topicid, name, starttime, endtime, location, status, max_attempts, timecreated, timemodified)
SELECT
  @TOPIC_ID,
  CONCAT('Exam session ', n),
  @BASE_TIME + (n - 1) * FLOOR(30 * @DAY / 250),
  @BASE_TIME + (n - 1) * FLOOR(30 * @DAY / 250) + 5400,
  CONCAT('Hall ', n),
  'planned',
  1,
  @BASE_TIME,
  @BASE_TIME
FROM tmp_nums;

-- Enrol the student in all 250 exam sessions.
INSERT INTO mdl_vbs_exam_enrolment (sessionid, userid, enrolled_by, source, timeenrolled)
SELECT id, @STUDENT_ID, 0, 'manual', @BASE_TIME
FROM mdl_vbs_exam_session
WHERE topicid = @TOPIC_ID;

DROP TEMPORARY TABLE IF EXISTS tmp_nums;

-- Done: 250 class + 250 exam = 500 events for @STUDENT_ID across 30 days.
