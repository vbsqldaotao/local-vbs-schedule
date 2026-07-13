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
use core_external\external_single_structure;
use core_external\external_value;
use context_system;

/**
 * External function: save_pref
 *
 * Persists the current user's calendar view preference to mdl_vbs_schedule_pref.
 * Supports EDGE-07: view_mode persists after page reload.
 *
 * @package     local_vbs_schedule
 * @copyright   2026 VBS Đào tạo
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_pref extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'view_mode'  => new external_value(PARAM_ALPHA, 'View mode: month, week, or list', VALUE_DEFAULT, 'month'),
            'show_class' => new external_value(PARAM_BOOL,  'Show class (facetoface) events',  VALUE_DEFAULT, true),
            'show_exam'  => new external_value(PARAM_BOOL,  'Show exam events',                VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Upsert the user's calendar preference.
     *
     * @param string $view_mode  month | week | list
     * @param bool   $show_class
     * @param bool   $show_exam
     * @return array
     */
    public static function execute(string $view_mode = 'month', bool $show_class = true, bool $show_exam = true): array {
        global $DB, $USER;

        [
            'view_mode'  => $view_mode,
            'show_class' => $show_class,
            'show_exam'  => $show_exam,
        ] = self::validate_parameters(self::execute_parameters(), [
            'view_mode'  => $view_mode,
            'show_class' => $show_class,
            'show_exam'  => $show_exam,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/vbs_schedule:view', $context);

        // Whitelist allowed values — reject anything else silently.
        if (!in_array($view_mode, ['month', 'week', 'list'], true)) {
            $view_mode = 'month';
        }

        $now      = time();
        $existing = $DB->get_record('vbs_schedule_pref', ['userid' => $USER->id]);

        if ($existing) {
            $existing->view_mode    = $view_mode;
            $existing->show_class   = (int) $show_class;
            $existing->show_exam    = (int) $show_exam;
            $existing->timemodified = $now;
            $DB->update_record('vbs_schedule_pref', $existing);
        } else {
            $DB->insert_record('vbs_schedule_pref', (object) [
                'userid'       => $USER->id,
                'view_mode'    => $view_mode,
                'show_class'   => (int) $show_class,
                'show_exam'    => (int) $show_exam,
                'timemodified' => $now,
            ]);
        }

        return ['success' => true];
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the preference was saved successfully'),
        ]);
    }
}
