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
 * within the specified date range. Enforces SEC-01 through SEC-16 security rules.
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
            'courseid' => new external_value(PARAM_INT, 'Filter by course; 0 = all', VALUE_DEFAULT, 0),
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
     * @param int    $userid   0 = current user
     * @param int    $datefrom Unix timestamp
     * @param int    $dateto   Unix timestamp
     * @param int    $courseid 0 = all courses
     * @param array  $types    Subset of ['class','exam']
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
            $classparams = [
                'userid'   => $userid,
                'datefrom' => $datefrom,
                'dateto'   => $dateto,
            ];

            $coursesql = '';
            if ($courseid > 0) {
                $coursesql = 'AND c.id = :courseid';
                $classparams['courseid'] = $courseid;
            }

            $sql = "SELECT CONCAT('class_', fs.id) AS event_id,
                           'class'                  AS event_type,
                           ff.name                  AS course_name,
                           c.id                     AS courseid,
                           fs.timestart,
                           fs.timefinish            AS endtime,
                           fs.location,
                           u.firstname,
                           u.lastname
                      FROM {facetoface_sessions} fs
                      JOIN {facetoface} ff       ON ff.id  = fs.facetoface
                      JOIN {course} c            ON c.id   = ff.course
                      JOIN {facetoface_signups} fsi
                                                 ON fsi.sessionid = fs.id
                                                AND fsi.userid    = :userid
                                                AND fsi.statuscode >= 70
                      JOIN {user_enrolments} ue  ON ue.userid = fsi.userid
                                                AND ue.status  = 0
                      LEFT JOIN {user} u         ON u.id = fs.trainerid
                     WHERE fs.timestart  >= :datefrom
                       AND fs.timefinish <= :dateto
                       $coursesql
                     ORDER BY fs.timestart ASC";

            $rows = $DB->get_records_sql($sql, $classparams);
            foreach ($rows as $row) {
                $instructor = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
                $events[] = [
                    'id'         => $row->event_id,
                    'type'       => 'class',
                    'title'      => $row->course_name,
                    'courseid'   => (int) $row->courseid,
                    'coursename' => $row->course_name,
                    'starttime'  => (int) $row->timestart,
                    'endtime'    => (int) $row->endtime,
                    'location'   => $row->location ?? '',
                    'instructor' => $instructor,
                    'color'      => '#3b82f6',
                    'status'     => self::resolve_class_status((int) $row->timestart, (int) $row->endtime),
                ];
            }
        }

        // --- Exam events (vbs_exam) ---
        if (in_array('exam', $types, true)) {
            $rows = \local_vbs_exam\session::get_user_events($userid, $datefrom, $dateto);
            foreach ($rows as $row) {
                $events[] = [
                    'id'         => 'exam_' . $row->id,
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
        }

        // Sort combined list by starttime ascending.
        usort($events, fn($a, $b) => $a['starttime'] <=> $b['starttime']);

        return ['events' => $events, 'total' => count($events)];
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
                    'id'         => new external_value(PARAM_TEXT, 'Unique event ID, e.g. class_101 or exam_5'),
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
     * @return string
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
