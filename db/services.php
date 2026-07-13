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
 * Web service function definitions for local_vbs_schedule.
 *
 * @package     local_vbs_schedule
 * @copyright   2026 VBS Đào tạo
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_vbs_schedule_get_events' => [
        'classname'   => 'local_vbs_schedule\external\get_events',
        'methodname'  => 'execute',
        'description' => 'Return class and exam events for a given user and date range.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'local/vbs_schedule:view',
    ],
];

$services = [
    'local_vbs_schedule_get_events' => [
        'functions' => ['local_vbs_schedule_get_events'],
        'restrictedusers' => 0,
        'enabled'         => 1,
        'shortname'       => 'local_vbs_schedule_get_events',
    ],
];
