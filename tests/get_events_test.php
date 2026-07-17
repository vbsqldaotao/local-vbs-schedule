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

namespace local_vbs_schedule;

use local_vbs_schedule\external\get_events;
use core_external\external_api;

/**
 * PHPUnit tests for local_vbs_schedule\external\get_events.
 *
 * Covers: SEC-01, SEC-08, SEC-09, EDGE-01
 *
 * @package     local_vbs_schedule
 * @copyright   2026 VBS Đào tạo
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \local_vbs_schedule\external\get_events
 * @group       local_vbs_schedule
 */
final class get_events_test extends \advanced_testcase {

    /** Base time used across fixtures. 2026-07-13 00:00:00 UTC. */
    private const BASE_TIME = 1752364800;

    /**
     * Create a minimal facetoface signup chain so get_events returns a class event.
     *
     * @param int $userid    Student user id
     * @param int $timestart Session start unix timestamp
     * @param int $timefinish Session end unix timestamp
     * @param int $statuscode Signup statuscode (default 70 = booked)
     * @return int facetoface_sessions.id
     */
    private function create_facetoface_session(int $userid, int $timestart, int $timefinish,
                                               int $statuscode = 70): int {
        global $DB;

        // Course.
        $course = self::getDataGenerator()->create_course(['fullname' => 'Test Course']);

        // Facetoface activity.
        $ffid = $DB->insert_record('facetoface', (object)[
            'course'         => $course->id,
            'name'           => $course->fullname,
            'intro'          => '',
            'introformat'    => 1,
            'timecreated'    => self::BASE_TIME,
            'timemodified'   => self::BASE_TIME,
        ]);

        // Session.
        $sessionid = $DB->insert_record('facetoface_sessions', (object)[
            'facetoface'   => $ffid,
            'capacity'     => 100,
            'allowoverbook' => 0,
            'duration'     => $timefinish - $timestart,
            'normalcost'   => 0,
            'discountcost' => 0,
            'timecreated'  => self::BASE_TIME,
            'timemodified' => self::BASE_TIME,
        ]);

        // Session date (timestart/timefinish live in facetoface_sessions_dates, not facetoface_sessions).
        $DB->insert_record('facetoface_sessions_dates', (object)[
            'sessionid'  => $sessionid,
            'timestart'  => $timestart,
            'timefinish' => $timefinish,
        ]);

        // Enrolment (active).
        $DB->insert_record('user_enrolments', (object)[
            'status'        => 0,
            'enrolid'       => 0,
            'userid'        => $userid,
            'timestart'     => 0,
            'timeend'       => 0,
            'modifierid'    => 0,
            'timecreated'   => self::BASE_TIME,
            'timemodified'  => self::BASE_TIME,
        ]);

        // Signup (statuscode lives in facetoface_signups_status, not facetoface_signups).
        $signupid = $DB->insert_record('facetoface_signups', (object)[
            'sessionid'        => $sessionid,
            'userid'           => $userid,
            'mailedreminder'   => 0,
            'discountcode'     => '',
            'notificationtype' => 0,
        ]);

        $DB->insert_record('facetoface_signups_status', (object)[
            'signupid'    => $signupid,
            'statuscode'  => $statuscode,
            'superceded'  => 0,
            'createdby'   => 0,
            'timecreated' => self::BASE_TIME,
        ]);

        return $sessionid;
    }

    /**
     * Create a vbs_exam_session with an enrolment for $userid.
     *
     * @param int    $userid
     * @param int    $starttime
     * @param int    $endtime
     * @param string $status planned|open|closed
     * @return int session id
     */
    private function create_exam_session(int $userid, int $starttime, int $endtime,
                                         string $status = 'planned'): int {
        global $DB;

        // Exam group (required by topic FK).
        $groupid = $DB->insert_record('vbs_exam_group', (object)[
            'name'         => 'Group',
            'parentid'     => 0,
            'sortorder'    => 0,
            'timecreated'  => self::BASE_TIME,
            'timemodified' => self::BASE_TIME,
        ]);

        // Exam topic.
        $topicid = $DB->insert_record('vbs_exam_topic', (object)[
            'groupid'      => $groupid,
            'name'         => 'Test Topic',
            'quizid'       => 0,
            'timecreated'  => self::BASE_TIME,
            'timemodified' => self::BASE_TIME,
        ]);

        // Exam session.
        $sessionid = $DB->insert_record('vbs_exam_session', (object)[
            'topicid'      => $topicid,
            'name'         => 'Session ' . $status,
            'starttime'    => $starttime,
            'endtime'      => $endtime,
            'location'     => 'Hall B',
            'status'       => $status,
            'max_attempts' => 1,
            'timecreated'  => self::BASE_TIME,
            'timemodified' => self::BASE_TIME,
        ]);

        // Enrolment.
        $DB->insert_record('vbs_exam_enrolment', (object)[
            'sessionid'   => $sessionid,
            'userid'      => $userid,
            'enrolled_by' => 0,
            'source'      => 'manual',
            'timeenrolled' => self::BASE_TIME,
        ]);

        return $sessionid;
    }

    // -------------------------------------------------------------------------
    // SEC-01: userid=0 resolves to current user
    // -------------------------------------------------------------------------

    /**
     * SEC-01: passing userid=0 returns events for the current logged-in user,
     * not an empty or error result.
     */
    public function test_sec01_userid_zero_resolves_to_current_user(): void {
        $this->resetAfterTest();

        $student = self::getDataGenerator()->create_user();
        $this->setUser($student);
        $this->getDataGenerator()->role_assign(
            $this->get_role_id('student'), $student->id, \context_system::instance()->id
        );

        $from = self::BASE_TIME;
        $to   = $from + 7 * 86400;
        $this->create_exam_session($student->id, $from + 3600, $from + 7200, 'planned');

        $result = get_events::execute(0, $from, $to, 0, ['exam']);
        $result = external_api::clean_returnvalue(get_events::execute_returns(), $result);

        $this->assertSame(1, $result['total']);
        $this->assertStringStartsWith('exam_', $result['events'][0]['id']);
    }

    // -------------------------------------------------------------------------
    // SEC-08: viewing another user requires viewall capability
    // -------------------------------------------------------------------------

    /**
     * SEC-08: a plain student cannot request events for a different user — must
     * receive required_capability_exception.
     */
    public function test_sec08_student_cannot_view_other_user(): void {
        $this->resetAfterTest();

        $student  = self::getDataGenerator()->create_user();
        $other    = self::getDataGenerator()->create_user();

        $this->setUser($student);
        $this->getDataGenerator()->role_assign(
            $this->get_role_id('student'), $student->id, \context_system::instance()->id
        );

        $this->expectException(\required_capability_exception::class);
        get_events::execute($other->id, self::BASE_TIME, self::BASE_TIME + 86400, 0, ['class', 'exam']);
    }

    /**
     * SEC-08: a manager with viewall capability CAN request events for another user.
     */
    public function test_sec08_manager_can_view_other_user(): void {
        $this->resetAfterTest();

        $manager = self::getDataGenerator()->create_user();
        $student = self::getDataGenerator()->create_user();

        $this->setUser($manager);
        $ctx = \context_system::instance();
        $this->getDataGenerator()->role_assign($this->get_role_id('manager'), $manager->id, $ctx->id);

        $from = self::BASE_TIME;
        $to   = $from + 7 * 86400;
        $this->create_exam_session($student->id, $from + 3600, $from + 7200, 'planned');

        $result = get_events::execute($student->id, $from, $to, 0, ['exam']);
        $result = external_api::clean_returnvalue(get_events::execute_returns(), $result);

        $this->assertSame(1, $result['total']);
    }

    // -------------------------------------------------------------------------
    // SEC-09: date range > 90 days is rejected
    // -------------------------------------------------------------------------

    /**
     * SEC-09: a range exceeding 90 days throws invalid_parameter_exception.
     */
    public function test_sec09_date_range_exceeds_90_days(): void {
        $this->resetAfterTest();

        $student = self::getDataGenerator()->create_user();
        $this->setUser($student);
        $this->getDataGenerator()->role_assign(
            $this->get_role_id('student'), $student->id, \context_system::instance()->id
        );

        $from = self::BASE_TIME;
        $to   = $from + 91 * 86400; // 91 days — exceeds limit.

        $this->expectException(\invalid_parameter_exception::class);
        get_events::execute(0, $from, $to);
    }

    /**
     * SEC-09: exactly 90-day window is accepted without exception.
     */
    public function test_sec09_date_range_exactly_90_days_is_allowed(): void {
        $this->resetAfterTest();

        $student = self::getDataGenerator()->create_user();
        $this->setUser($student);
        $this->getDataGenerator()->role_assign(
            $this->get_role_id('student'), $student->id, \context_system::instance()->id
        );

        $from = self::BASE_TIME;
        $to   = $from + 90 * 86400;

        $result = get_events::execute(0, $from, $to);
        $result = external_api::clean_returnvalue(get_events::execute_returns(), $result);

        $this->assertSame(0, $result['total']); // No fixtures — just checking no exception.
    }

    // -------------------------------------------------------------------------
    // EDGE-01: user with no events in range returns empty list
    // -------------------------------------------------------------------------

    /**
     * EDGE-01: a new user with no facetoface or exam records returns total=0
     * and events=[].
     */
    public function test_edge01_new_user_no_records(): void {
        $this->resetAfterTest();

        $newuser = self::getDataGenerator()->create_user();
        $this->setUser($newuser);
        $this->getDataGenerator()->role_assign(
            $this->get_role_id('student'), $newuser->id, \context_system::instance()->id
        );

        $from = self::BASE_TIME;
        $to   = $from + 30 * 86400;

        $result = get_events::execute(0, $from, $to, 0, ['class', 'exam']);
        $result = external_api::clean_returnvalue(get_events::execute_returns(), $result);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['events']);
    }

    /**
     * EDGE-01 (optional types): requesting only 'class' returns no exam events.
     */
    public function test_edge01_types_filter_exam_only(): void {
        $this->resetAfterTest();

        $student = self::getDataGenerator()->create_user();
        $this->setUser($student);
        $this->getDataGenerator()->role_assign(
            $this->get_role_id('student'), $student->id, \context_system::instance()->id
        );

        $from = self::BASE_TIME;
        $to   = $from + 7 * 86400;
        $this->create_exam_session($student->id, $from + 3600, $from + 7200, 'open');

        // Request only 'class' — the exam session must NOT appear.
        $result = get_events::execute(0, $from, $to, 0, ['class']);
        $result = external_api::clean_returnvalue(get_events::execute_returns(), $result);

        $this->assertSame(0, $result['total']);
    }

    /**
     * EDGE-01: dateto < datefrom throws invalid_parameter_exception.
     */
    public function test_edge01_dateto_before_datefrom_rejected(): void {
        $this->resetAfterTest();

        $student = self::getDataGenerator()->create_user();
        $this->setUser($student);
        $this->getDataGenerator()->role_assign(
            $this->get_role_id('student'), $student->id, \context_system::instance()->id
        );

        $this->expectException(\invalid_parameter_exception::class);
        get_events::execute(0, self::BASE_TIME + 86400, self::BASE_TIME);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Return the id of a built-in role by shortname.
     *
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }
}
