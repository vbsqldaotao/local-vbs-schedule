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
 * Entry point for local_vbs_schedule — Calendar Page (Task 3.4).
 *
 * @package     local_vbs_schedule
 * @copyright   2026 VBS Đào tạo
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/vbs_schedule:view', context_system::instance());

global $DB, $USER, $OUTPUT, $PAGE;

$PAGE->set_url(new moodle_url('/local/vbs_schedule/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('pagetitle', 'local_vbs_schedule'));
$PAGE->set_heading(get_string('pagetitle', 'local_vbs_schedule'));
$PAGE->requires->css('/local/vbs_schedule/styles.css');

// Read saved view preference.
$pref = $DB->get_record('vbs_schedule_pref', ['userid' => $USER->id]);
$viewmode  = $pref ? $pref->view_mode  : 'month';
$showclass = $pref ? (bool) $pref->show_class : true;
$showexam  = $pref ? (bool) $pref->show_exam  : true;

// Enrolled courses for filter dropdown.
$courses = enrol_get_my_courses(['id', 'fullname'], 'fullname ASC');
$courseoptions = [];
foreach ($courses as $c) {
    $courseoptions[] = ['id' => $c->id, 'name' => $c->fullname];
}

$templatectx = [
    'viewmode'      => $viewmode,
    'viewmonth'     => ($viewmode === 'month'),
    'viewweek'      => ($viewmode === 'week'),
    'viewlist'      => ($viewmode === 'list'),
    'showclass'     => $showclass,
    'showexam'      => $showexam,
    'typeboth'      => ($showclass && $showexam),
    'typeclass'     => ($showclass && !$showexam),
    'typeexam'      => (!$showclass && $showexam),
    'courseoptions' => $courseoptions,
    'userid'        => $USER->id,
    'hascbql'       => has_capability('local/vbs_schedule:viewall', context_system::instance()),
    'instructorurl' => (new moodle_url('/local/vbs_schedule/instructor.php'))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_vbs_schedule/calendar', $templatectx);
$PAGE->requires->js_call_amd('local_vbs_schedule/calendar', 'init', [[
    'viewmode'  => $viewmode,
    'showclass' => $showclass,
    'showexam'  => $showexam,
]]);
echo $OUTPUT->footer();
