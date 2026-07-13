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
 * English language strings for local_vbs_schedule.
 *
 * @package     local_vbs_schedule
 * @copyright   2026 VBS Đào tạo
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'VBS Schedule';

// Capabilities.
$string['vbs_schedule:view']    = 'View personal schedule';
$string['vbs_schedule:viewall'] = 'View schedule for any user';

// Page.
$string['pagetitle']   = 'My Schedule';
$string['nocapability'] = 'You do not have permission to view this schedule.';

// API errors.
$string['err_daterange']      = 'Date range must not exceed 90 days.';
$string['err_dateinvalid']    = 'dateto must be greater than or equal to datefrom.';
$string['err_timestamprange'] = 'Timestamps must be positive integers.';

// Filter bar (Task 3.5).
$string['filtercourse']  = 'Course';
$string['allcourses']    = 'All courses';
$string['filtertype']    = 'Event type';
$string['typeboth']      = 'Both';
$string['typeclass']     = 'Classes';
$string['typeexam']      = 'Exams';
$string['datefrom']      = 'From';
$string['dateto']        = 'To';
$string['applyfilter']   = 'Apply filter';

// View toggle (Task 3.4).
$string['viewmode']      = 'View';
$string['viewmonth']     = 'Month';
$string['viewweek']      = 'Week';
$string['viewlist']      = 'List';
$string['navprev']       = 'Previous period';
$string['navnext']       = 'Next period';
$string['navtoday']      = 'Today';

// CBQL view (Task 3.6).
$string['instructortitle']        = 'Instructor Schedule (CBQL)';
$string['cbqlview']               = 'CBQL View';
$string['instructor']             = 'Instructor';
$string['chooseinstructor']       = '— Choose an instructor —';
$string['noinstructorsessions']   = 'No teaching sessions found for this instructor in the selected date range.';
$string['date']                   = 'Date';
$string['location']               = 'Location';
$string['backtocalendar']         = 'Back to My Schedule';

// Privacy.
$string['privacy:metadata']                           = 'The VBS Schedule plugin stores user preferences (view mode, event type toggles).';
$string['privacy:metadata:vbs_schedule_pref']         = 'Per-user schedule display preferences';
$string['privacy:metadata:vbs_schedule_pref:userid']  = 'The user whose preferences are stored';
$string['privacy:metadata:vbs_schedule_pref:view_mode']   = 'Preferred calendar view (month/week/list)';
$string['privacy:metadata:vbs_schedule_pref:show_class']  = 'Whether class events are shown';
$string['privacy:metadata:vbs_schedule_pref:show_exam']   = 'Whether exam events are shown';
$string['privacy:metadata:vbs_schedule_pref:timemodified'] = 'When the preference was last modified';
