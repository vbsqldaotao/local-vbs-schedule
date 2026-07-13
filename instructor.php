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

/**
 * CBQL (instructor) schedule browser (Task 3.6).
 *
 * @package     local_vbs_schedule
 * @copyright   2026 VBS Đào tạo
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/vbs_schedule:viewall', context_system::instance());

global $DB, $OUTPUT, $PAGE;

$PAGE->set_url(new moodle_url('/local/vbs_schedule/instructor.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('instructortitle', 'local_vbs_schedule'));
$PAGE->set_heading(get_string('instructortitle', 'local_vbs_schedule'));
$PAGE->requires->css('/local/vbs_schedule/styles.css');

// Input params (date strings from HTML date inputs).
$instructorid  = optional_param('instructorid', 0, PARAM_INT);
$datefromstr   = optional_param('datefrom', date('Y-m-d', strtotime('first day of this month')), PARAM_TEXT);
$datetostr     = optional_param('dateto', date('Y-m-d', strtotime('last day of this month')), PARAM_TEXT);

// Convert to unix timestamps (start/end of day).
$datefrom = strtotime($datefromstr . ' 00:00:00');
$dateto   = strtotime($datetostr  . ' 23:59:59');

if ($datefrom === false || $dateto === false || $dateto < $datefrom) {
    $datefrom = strtotime('first day of this month 00:00:00');
    $dateto   = strtotime('last day of this month 23:59:59');
    $datefromstr = date('Y-m-d', $datefrom);
    $datetostr   = date('Y-m-d', $dateto);
}

// Build instructor dropdown from role assignments.
$sql = "SELECT DISTINCT u.id, u.firstname, u.lastname
          FROM {user} u
          JOIN {role_assignments} ra ON ra.userid = u.id
          JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('teacher', 'editingteacher')
         WHERE u.deleted = 0
         ORDER BY u.lastname ASC, u.firstname ASC";
$instructors = $DB->get_records_sql($sql);

$instructoroptions = [];
foreach ($instructors as $inst) {
    $instructoroptions[] = [
        'id'       => $inst->id,
        'name'     => fullname($inst),
        'selected' => ($inst->id == $instructorid),
    ];
}

// Fetch sessions for the chosen instructor.
$sessions  = [];
$noevents  = false;

if ($instructorid > 0) {
    $sql = "SELECT fs.id,
                   ff.name     AS coursename,
                   c.shortname AS courseshort,
                   fs.timestart,
                   fs.timefinish,
                   fs.location
              FROM {facetoface_sessions} fs
              JOIN {facetoface} ff ON ff.id = fs.facetoface
              JOIN {course} c      ON c.id  = ff.course
             WHERE fs.trainerid  = :trainerid
               AND fs.timestart  >= :datefrom
               AND fs.timefinish <= :dateto
             ORDER BY fs.timestart ASC";

    $rows = $DB->get_records_sql($sql, [
        'trainerid' => $instructorid,
        'datefrom'  => $datefrom,
        'dateto'    => $dateto,
    ]);

    foreach ($rows as $row) {
        $sessions[] = [
            'coursename'  => $row->coursename,
            'courseshort' => $row->courseshort,
            'date'        => userdate($row->timestart, get_string('strftimedatefullshort', 'core_langconfig')),
            'timestart'   => userdate($row->timestart, '%H:%M'),
            'timefinish'  => userdate($row->timefinish, '%H:%M'),
            'location'    => $row->location ?? '',
        ];
    }

    $noevents = empty($sessions);
}

$templatectx = [
    'instructoroptions' => $instructoroptions,
    'sessions'          => $sessions,
    'noevents'          => $noevents,
    'hasinstructor'     => ($instructorid > 0),
    'datefrom'          => $datefromstr,
    'dateto'            => $datetostr,
    'formaction'        => (new moodle_url('/local/vbs_schedule/instructor.php'))->out(false),
    'calendarurl'       => (new moodle_url('/local/vbs_schedule/index.php'))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_vbs_schedule/instructor', $templatectx);
echo $OUTPUT->footer();
