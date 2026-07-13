-- Performance seed: 500 events (250 class + 250 exam) across 30 days.
-- Base time: 2026-07-13 00:00:00 UTC = 1752364800
--
-- Run against the Moodle test database (phpu_ prefix) after phpunit init:
--   mysql -u <user> -p <db> < seed_perf.sql
--
-- Set @STUDENT_USERNAME to a real user in the target DB, or override @STUDENT_ID.
-- Default target: the first non-admin, non-guest user found by username sort.
--
-- Schema references (mod_facetoface db/install.xml):
--   facetoface_sessions       — NO timestart/timefinish/trainerid/location columns.
--   facetoface_sessions_dates — holds timestart, timefinish (FK: sessionid).
--   facetoface_signups        — NO statuscode column.
--   facetoface_signups_status — holds statuscode, superceded (FK: signupid).

SET @BASE_TIME  = 1752364800;
SET @DAY        = 86400;

-- Resolve student user id — pick the first non-admin, non-guest user.
SET @STUDENT_ID = (
    SELECT id FROM mdl_user
    WHERE deleted = 0 AND suspended = 0
      AND username NOT IN ('admin', 'guest')
    ORDER BY id ASC
    LIMIT 1
);

SELECT CONCAT('[seed] Using student user id = ', @STUDENT_ID) AS msg;

-- ============================================================
-- 1. Facetoface activity + 250 sessions (class events)
-- ============================================================

INSERT INTO mdl_course (fullname, shortname, category, summary, format,
                        startdate, timecreated, timemodified, visible)
VALUES ('Perf Test Course', 'PERF01', 1, '', 'topics',
        @BASE_TIME, @BASE_TIME, @BASE_TIME, 1);

SET @COURSE_ID = LAST_INSERT_ID();

-- Manual enrolment method for the course.
INSERT INTO mdl_enrol (enrol, status, courseid, timecreated, timemodified)
VALUES ('manual', 0, @COURSE_ID, @BASE_TIME, @BASE_TIME);

SET @ENROL_ID = LAST_INSERT_ID();

-- Enrol the student (active).
INSERT INTO mdl_user_enrolments (status, enrolid, userid, timestart, timeend,
                                  modifierid, timecreated, timemodified)
VALUES (0, @ENROL_ID, @STUDENT_ID, 0, 0, 0, @BASE_TIME, @BASE_TIME);

-- Facetoface activity.
INSERT INTO mdl_facetoface (course, name, intro, introformat, timecreated, timemodified)
VALUES (@COURSE_ID, 'Perf Facetoface', '', 1, @BASE_TIME, @BASE_TIME);

SET @FF_ID = LAST_INSERT_ID();

-- Numbers helper table: rows 1..250.
DROP TEMPORARY TABLE IF EXISTS tmp_nums;
CREATE TEMPORARY TABLE tmp_nums (n INT PRIMARY KEY);

INSERT INTO tmp_nums (n)
SELECT a.N + b.N * 10 + c.N * 100
FROM
  (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
   UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a
  CROSS JOIN
  (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
   UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b
  CROSS JOIN
  (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2) c
HAVING n BETWEEN 1 AND 250;

-- 250 facetoface sessions (no time columns here).
INSERT INTO mdl_facetoface_sessions
  (facetoface, capacity, allowoverbook, details, datetimeknown, duration,
   normalcost, discountcost, allowcancellations, visible, timecreated, timemodified)
SELECT
  @FF_ID, 30, 0, '', 1,
  3600,
  0, 0, 1, 1,
  @BASE_TIME, @BASE_TIME
FROM tmp_nums;

-- Capture the range of inserted session IDs.
SET @FIRST_SESSION_ID = LAST_INSERT_ID();
SET @LAST_SESSION_ID  = @FIRST_SESSION_ID + 249;

-- 250 session dates — one per session, spread across 30 days.
INSERT INTO mdl_facetoface_sessions_dates (sessionid, timestart, timefinish)
SELECT
  fs.id,
  @BASE_TIME + (ROW_NUMBER() OVER (ORDER BY fs.id) - 1) * FLOOR(30 * @DAY / 250),
  @BASE_TIME + (ROW_NUMBER() OVER (ORDER BY fs.id) - 1) * FLOOR(30 * @DAY / 250) + 3600
FROM mdl_facetoface_sessions fs
WHERE fs.id BETWEEN @FIRST_SESSION_ID AND @LAST_SESSION_ID;

-- Signups for the student (one per session).
INSERT INTO mdl_facetoface_signups (sessionid, userid, mailedreminder, discountcode, notificationtype)
SELECT fs.id, @STUDENT_ID, 0, '', 0
FROM mdl_facetoface_sessions fs
WHERE fs.id BETWEEN @FIRST_SESSION_ID AND @LAST_SESSION_ID;

-- Signup statuses (statuscode=70 = booked; superceded=0 = current status).
INSERT INTO mdl_facetoface_signups_status (signupid, statuscode, superceded, grade,
                                            note, advice, createdby, timecreated)
SELECT su.id, 70, 0, NULL, '', '', @STUDENT_ID, @BASE_TIME
FROM mdl_facetoface_signups su
     JOIN mdl_facetoface_sessions fs ON fs.id = su.sessionid
WHERE fs.id BETWEEN @FIRST_SESSION_ID AND @LAST_SESSION_ID
  AND su.userid = @STUDENT_ID;

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
  (topicid, name, starttime, endtime, location, status,
   max_attempts, timecreated, timemodified)
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

SET @FIRST_EXAM_SESSION_ID = LAST_INSERT_ID();
SET @LAST_EXAM_SESSION_ID  = @FIRST_EXAM_SESSION_ID + 249;

-- Enrol the student in all 250 exam sessions.
INSERT INTO mdl_vbs_exam_enrolment (sessionid, userid, enrolled_by, source, timeenrolled)
SELECT id, @STUDENT_ID, 0, 'manual', @BASE_TIME
FROM mdl_vbs_exam_session
WHERE id BETWEEN @FIRST_EXAM_SESSION_ID AND @LAST_EXAM_SESSION_ID;

DROP TEMPORARY TABLE IF EXISTS tmp_nums;

SELECT CONCAT('[seed] Done: 250 class + 250 exam events seeded for user id = ', @STUDENT_ID) AS msg;
-- Verify: call local_vbs_schedule_get_events with datefrom=1752364800, dateto=1752364800+(30*86400)
-- Expected: total=500
