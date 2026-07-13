<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_vbs_schedule\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_system;

/**
 * External function: get_events
 *
 * Returns combined class (facetoface) and exam events for a given user
 * within the specified date range.
 *
 * Schema notes (mod_facetoface):
 *   - timestart/timefinish live in {facetoface_sessions_dates}, not {facetoface_sessions}.
 *   - statuscode lives in {facetoface_signups_status} (superceded=0), not in {facetoface_signups}.
 *   - location lives in {facetoface_session_data} keyed via {facetoface_session_field}.shortname='location'.
 *   - trainers live in {facetoface_session_roles}; a session may have multiple trainers.
 *   - enrolment must be verified through {enrol} → {user_enrolments} tied to the specific course.
 *
 * Security rules enforced: SEC-01, SEC-08, SEC-09, SEC-12, SEC-13, SEC-15, SEC-16.
 *
 * @package     local_vbs_schedule
 * @copyright   2026 VBS Đào tạo
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_events extends external_api {

    /** Maximum date range allowed (90 days in seconds). */
    const MAX_RANGE_SECONDS = 90 * 86400;

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid'   => new external_value(PARAM_INT, 'User ID; 0 = current user', VALUE_DEFAULT, 0),
            'datefrom' => new external_value(PARAM_INT, 'Start of range (unix timestamp)'),
            'dateto'   => new external_value(PARAM_INT, 'End of range (unix timestamp)'),
            'courseid' => new external_value(PARAM_INT, 'Filter by course; 0 = all (ignored for exam type)', VALUE_DEFAULT, 0),
            'types'    => new external_multiple_structure(
                new external_value(PARAM_ALPHA, 'Event type: class or exam'),
                'Event types to include',
                VALUE_DEFAULT,
                ['class', 'exam']
            ),
        ]);
    }

    /**
     * Return schedule events for the requested user and date range.
     *
     * @param int   $userid   0 = current user
     * @param int   $datefrom Unix timestamp
     * @param int   $dateto   Unix timestamp
     * @param int   $courseid 0 = all courses (exam events ignore this filter — see docblock)
     * @param array $types    Subset of ['class','exam']
     * @return array
     */
    public static function execute(int $userid, int $datefrom, int $dateto,
                                   int $courseid = 0, array $types = ['class', 'exam']): array {
        global $DB, $USER;

        // Validate and clean parameters (SEC-12, SEC-13).
        [
            'userid'   => $userid,
            'datefrom' => $datefrom,
            'dateto'   => $dateto,
            'courseid' => $courseid,
            'types'    => $types,
        ] = self::validate_parameters(self::execute_parameters(), [
            'userid'   => $userid,
            'datefrom' => $datefrom,
            'dateto'   => $dateto,
            'courseid' => $courseid,
            'types'    => $types,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        // Resolve userid (SEC-01).
        if ($userid === 0) {
            $userid = (int) $USER->id;
        }

        // SEC-08: viewing another user's schedule requires viewall capability.
        if ($userid !== (int) $USER->id) {
            require_capability('local/vbs_schedule:viewall', $context);
        } else {
            require_capability('local/vbs_schedule:view', $context);
        }

        // SEC-13/SEC-14: timestamp sanity checks.
        if ($datefrom < 0 || $dateto < 0) {
            throw new \invalid_parameter_exception(get_string('err_timestamprange', 'local_vbs_schedule'));
        }

        // SEC-15: dateto must be >= datefrom.
        if ($dateto < $datefrom) {
            throw new \invalid_parameter_exception(get_string('err_dateinvalid', 'local_vbs_schedule'));
        }

        // SEC-16: maximum 90-day window.
        if (($dateto - $datefrom) > self::MAX_RANGE_SECONDS) {
            throw new \invalid_parameter_exception(get_string('err_daterange', 'local_vbs_schedule'));
        }

        $events = [];

        // --- Class events (facetoface) ---
        if (in_array('class', $types, true)) {
            $events = array_merge($events, self::fetch_class_events($DB, $userid, $datefrom, $dateto, $courseid));
        }

        // --- Exam events (vbs_exam) ---
        // Note: vbs_exam schema has no FK to mdl_course, so courseid filter does not apply.
        // Schema contract with local_vbs_exam (R-03 / VBS-415):
        //   vbs_exam_session: id, topicid, name, starttime, endtime, location, status
        //   vbs_exam_enrolment: sessionid, userid
        //   vbs_exam_topic: id, name
        // If local_vbs_exam changes these columns, update the SQL here.
        if (in_array('exam', $types, true)) {
            $events = array_merge($events, self::fetch_exam_events($DB, $userid, $datefrom, $dateto));
        }

        // Sort combined list by starttime ascending.
        usort($events, fn($a, $b) => $a['starttime'] <=> $b['starttime']);

        return ['events' => $events, 'total' => count($events)];
    }

    /**
     * Fetch class events from mod_facetoface using the correct schema.
     *
     * Key schema facts:
     *   - {facetoface_sessions_dates} holds timestart/timefinish (BUG-01).
     *   - {facetoface_signups_status} holds statuscode with superceded=0 (BUG-02).
     *   - Active enrolment verified through {enrol} → {user_enrolments} scoped to the course (BUG-03).
     *   - location is a custom field in {facetoface_session_data}/{facetoface_session_field}.
     *   - trainers are in {facetoface_session_roles}; fetched via correlated subquery (1-N safe).
     *
     * @param \moodle_database $DB
     * @param int $userid
     * @param int $datefrom
     * @param int $dateto
     * @param int $courseid 0 = all
     * @return array
     */
    private static function fetch_class_events(\moodle_database $DB, int $userid,
                                               int $datefrom, int $dateto, int $courseid): array {
        $params = [
            'userid'      => $userid,
            'enroluserid' => $userid, // same value — named param cannot be reused in one query.
            'datefrom'    => $datefrom,
            'dateto'      => $dateto,
        ];

        $coursesql = '';
        if ($courseid > 0) {
            $coursesql = 'AND c.id = :courseid';
            $params['courseid'] = $courseid;
        }

        // Correlated subqueries for location and instructor avoid duplicate rows from 1-N joins.
        $sql = "SELECT CONCAT('class_', fs.id, '_', fsd.id) AS event_id,
                       'class'                               AS event_type,
                       ff.name                               AS course_name,
                       c.id                                  AS courseid,
                       fsd.timestart,
                       fsd.timefinish                        AS endtime,
                       (SELECT sd.data
                          FROM {facetoface_session_data} sd
                          JOIN {facetoface_session_field} sf ON sf.id = sd.fieldid
                                                            AND sf.shortname = 'location'
                         WHERE sd.sessionid = fs.id
                         LIMIT 1)                            AS location,
                       (SELECT CONCAT(u2.firstname, ' ', u2.lastname)
                          FROM {facetoface_session_roles} sr
                          JOIN {user} u2 ON u2.id = sr.userid
                         WHERE sr.sessionid = fs.id
                         ORDER BY sr.id ASC
                         LIMIT 1)                            AS instructor
                  FROM {facetoface_sessions} fs
                  JOIN {facetoface_sessions_dates} fsd ON fsd.sessionid = fs.id
                  JOIN {facetoface} ff                 ON ff.id = fs.facetoface
                  JOIN {course} c                      ON c.id = ff.course
                  JOIN {facetoface_signups} fsi        ON fsi.sessionid = fs.id
                                                      AND fsi.userid    = :userid
                  JOIN {facetoface_signups_status} fss ON fss.signupid  = fsi.id
                                                      AND fss.superceded = 0
                                                      AND fss.statuscode >= 70
                 WHERE fsd.timestart  >= :datefrom
                   AND fsd.timefinish <= :dateto
                   AND EXISTS (
                         SELECT 1
                           FROM {enrol} e
                           JOIN {user_enrolments} ue ON ue.enrolid = e.id
                          WHERE e.courseid  = c.id
                            AND e.status    = 0
                            AND ue.userid   = :enroluserid
                            AND ue.status   = 0
                       )
                   $coursesql
                 ORDER BY fsd.timestart ASC";

        $rows   = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'         => $row->event_id,
                'type'       => 'class',
                'title'      => $row->course_name,
                'courseid'   => (int) $row->courseid,
                'coursename' => $row->course_name,
                'starttime'  => (int) $row->timestart,
                'endtime'    => (int) $row->endtime,
                'location'   => $row->location ?? '',
                'instructor' => $row->instructor ?? '',
                'color'      => '#3b82f6',
                'status'     => self::resolve_class_status((int) $row->timestart, (int) $row->endtime),
            ];
        }
        return $result;
    }

    /**
     * Fetch exam events from local_vbs_exam tables.
     *
     * Note: vbs_exam schema has no direct FK to mdl_course; courseid filter is not applied here.
     *
     * @param \moodle_database $DB
     * @param int $userid
     * @param int $datefrom
     * @param int $dateto
     * @return array
     */
    private static function fetch_exam_events(\moodle_database $DB, int $userid,
                                              int $datefrom, int $dateto): array {
        $sql = "SELECT CONCAT('exam_', es.id) AS event_id,
                       'exam'                  AS event_type,
                       et.name                 AS topic_name,
                       es.name                 AS session_name,
                       es.starttime,
                       es.endtime,
                       es.location,
                       es.status
                  FROM {vbs_exam_session} es
                  JOIN {vbs_exam_topic} et      ON et.id = es.topicid
                  JOIN {vbs_exam_enrolment} ee  ON ee.sessionid = es.id
                                               AND ee.userid    = :userid
                 WHERE es.starttime >= :datefrom
                   AND es.endtime   <= :dateto
                 ORDER BY es.starttime ASC";

        $rows   = $DB->get_records_sql($sql, ['userid' => $userid, 'datefrom' => $datefrom, 'dateto' => $dateto]);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'         => $row->event_id,
                'type'       => 'exam',
                'title'      => $row->topic_name . ' — ' . $row->session_name,
                'courseid'   => 0,
                'coursename' => $row->topic_name,
                'starttime'  => (int) $row->starttime,
                'endtime'    => (int) $row->endtime,
                'location'   => $row->location ?? '',
                'instructor' => '',
                'color'      => '#ef4444',
                'status'     => $row->status,
            ];
        }
        return $result;
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'events' => new external_multiple_structure(
                new external_single_structure([
                    'id'         => new external_value(PARAM_TEXT, 'Unique event ID, e.g. class_101_3 or exam_5'),
                    'type'       => new external_value(PARAM_ALPHA, 'class or exam'),
                    'title'      => new external_value(PARAM_TEXT, 'Display title'),
                    'courseid'   => new external_value(PARAM_INT,  'Moodle course ID (0 for exams)'),
                    'coursename' => new external_value(PARAM_TEXT, 'Course or topic name'),
                    'starttime'  => new external_value(PARAM_INT,  'Start unix timestamp'),
                    'endtime'    => new external_value(PARAM_INT,  'End unix timestamp'),
                    'location'   => new external_value(PARAM_TEXT, 'Venue / location', VALUE_OPTIONAL),
                    'instructor' => new external_value(PARAM_TEXT, 'Instructor full name (class only)', VALUE_OPTIONAL),
                    'color'      => new external_value(PARAM_TEXT, 'Hex colour for calendar display'),
                    'status'     => new external_value(PARAM_TEXT, 'upcoming / ongoing / past / planned / open / closed'),
                ])
            ),
            'total' => new external_value(PARAM_INT, 'Total number of events returned'),
        ]);
    }

    /**
     * Derive a human-readable status for a class session based on timestamps.
     *
     * @param int $start Unix timestamp
     * @param int $end   Unix timestamp
     * @return string upcoming|ongoing|past
     */
    private static function resolve_class_status(int $start, int $end): string {
        $now = time();
        if ($now < $start) {
            return 'upcoming';
        }
        if ($now <= $end) {
            return 'ongoing';
        }
        return 'past';
    }
}
