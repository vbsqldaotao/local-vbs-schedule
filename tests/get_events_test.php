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
 * Covers: SEC-01, SEC-08, SEC-09, EDGE-01, CLASS-01–CLASS-04
 *
 * Schema used:
 *   facetoface_sessions         — id, facetoface, capacity, allowoverbook, details,
 *                                  datetimeknown, duration, normalcost, discountcost,
 *                                  allowcancellations, visible, timecreated, timemodified
 *   facetoface_sessions_dates   — id, sessionid, timestart, timefinish
 *   facetoface_signups          — id, sessionid, userid, mailedreminder, discountcode, notificationtype
 *   facetoface_signups_status   — id, signupid, statuscode, superceded, grade, note, advice,
 *                                  createdby, timecreated
 *
 * @package     local_vbs_schedule
 * @copyright   2026 VBS Đào tạo
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \local_vbs_schedule\external\get_events
 * @group       local_vbs_schedule
 */
final class get_events_test extends \advanced_testcase {

    /** Base time used across fixtures: 2026-07-13 00:00:00 UTC. */
    private const BASE_TIME = 1752364800;

    /**
     * Create a complete facetoface signup chain so get_events returns a class event.
     *
     * Uses the actual mod_facetoface schema:
     *   - timestart/timefinish in facetoface_sessions_dates (not facetoface_sessions)
     *   - statuscode in facetoface_signups_status (not facetoface_signups)
     *   - enrolment verified through enrol → user_enrolments tied to the course
     *
     * @param int $userid     Student user id
     * @param int $timestart  Session date start unix timestamp
     * @param int $timefinish Session date end unix timestamp
     * @param int $statuscode Signup statuscode (70 = booked / attended)
     * @return int facetoface_sessions.id
     */
    private function create_facetoface_session(int $userid, int $timestart, int $timefinish,
                                               int $statuscode = 70): int {
        global $DB;

        // Course.
        $course = self::getDataGenerator()->create_course(['fullname' => 'Test Course']);

        // Enrol the user in the course via 'manual' enrolment so the EXISTS check passes.
        $enrolid = $DB->insert_record('enrol', (object)[
            'enrol'        => 'manual',
            'status'       => 0,
            'courseid'     => $course->id,
            'timecreated'  => self::BASE_TIME,
            'timemodified' => self::BASE_TIME,
        ]);
        $DB->insert_record('user_enrolments', (object)[
            'status'       => 0,
            'enrolid'      => $enrolid,
            'userid'       => $userid,
            'timestart'    => 0,
            'timeend'      => 0,
            'modifierid'   => 0,
            'timecreated'  => self::BASE_TIME,
            'timemodified' => self::BASE_TIME,
        ]);

        // Facetoface activity.
        $ffid = $DB->insert_record('facetoface', (object)[
            'course'       => $course->id,
            'name'         => 'Test Facetoface',
            'intro'        => '',
            'introformat'  => 1,
            'timecreated'  => self::BASE_TIME,
            'timemodified' => self::BASE_TIME,
        ]);

        // Session (no timestart/timefinish here — those go in sessions_dates).
        $sessionid = $DB->insert_record('facetoface_sessions', (object)[
            'facetoface'       => $ffid,
            'capacity'         => 100,
            'allowoverbook'    => 0,
            'details'          => '',
            'datetimeknown'    => 1,
            'duration'         => $timefinish - $timestart,
            'normalcost'       => 0,
            'discountcost'     => 0,
            'allowcancellations' => 1,
            'visible'          => 1,
            'timecreated'      => self::BASE_TIME,
            'timemodified'     => self::BASE_TIME,
        ]);

        // Session date — this is where timestart/timefinish actually live.
        $DB->insert_record('facetoface_sessions_dates', (object)[
            'sessionid'  => $sessionid,
            'timestart'  => $timestart,
            'timefinish' => $timefinish,
        ]);

        // Signup.
        $signupid = $DB->insert_record('facetoface_signups', (object)[
            'sessionid'        => $sessionid,
            'userid'           => $userid,
            'mailedreminder'   => 0,
            'discountcode'     => '',
            'notificationtype' => 0,
        ]);

        // Signup status — statuscode lives here, not in facetoface_signups.
        $DB->insert_record('facetoface_signups_status', (object)[
            'signupid'    => $signupid,
            'statuscode'  => $statuscode,
            'superceded'  => 0,
            'grade'       => null,
            'note'        => '',
            'advice'      => '',
            'createdby'   => $userid,
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
     * @param string $status    planned|open|closed
     * @return int session id
     */
    private function create_exam_session(int $userid, int $starttime, int $endtime,
                                         string $status = 'planned'): int {
        global $DB;

        $groupid = $DB->insert_record('vbs_exam_group', (object)[
            'name'         => 'Group',
            'parentid'     => 0,
            'sortorder'    => 0,
            'timecreated'  => self::BASE_TIME,
            'timemodified' => self::BASE_TIME,
        ]);

        $topicid = $DB->insert_record('vbs_exam_topic', (object)[
            'groupid'      => $groupid,
            'name'         => 'Test Topic',
            'quizid'       => 0,
            'timecreated'  => self::BASE_TIME,
            'timemodified' => self::BASE_TIME,
        ]);

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

        $DB->insert_record('vbs_exam_enrolment', (object)[
            'sessionid'    => $sessionid,
            'userid'       => $userid,
            'enrolled_by'  => 0,
            'source'       => 'manual',
            'timeenrolled' => self::BASE_TIME,
        ]);

        return $sessionid;
    }

    // -------------------------------------------------------------------------
    // SEC-01: userid=0 resolves to current user
    // -------------------------------------------------------------------------

    /**
     * SEC-01: passing userid=0 returns events for the current logged-in user.
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
     * SEC-08: a plain student cannot request events for a different user.
     */
    public function test_sec08_student_cannot_view_other_user(): void {
        $this->resetAfterTest();

        $student = self::getDataGenerator()->create_user();
        $other   = self::getDataGenerator()->create_user();

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
        $to   = $from + 91 * 86400;

        $this->expectException(\invalid_parameter_exception::class);
        get_events::execute(0, $from, $to);
    }

    /**
     * SEC-09: exactly 90-day window is accepted and returns any seeded events.
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

        // Seed 1 exam event within the range to verify the range is truly accepted.
        $this->create_exam_session($student->id, $from + 3600, $from + 7200, 'planned');

        $result = get_events::execute(0, $from, $to, 0, ['exam']);
        $result = external_api::clean_returnvalue(get_events::execute_returns(), $result);

        $this->assertSame(1, $result['total'], '90-day window should accept seeded event');
    }

    // -------------------------------------------------------------------------
    // EDGE-01: empty results
    // -------------------------------------------------------------------------

    /**
     * EDGE-01: a new user with no records returns total=0 and events=[].
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
     * EDGE-01 (class-only filter): requesting only 'class' excludes exam events.
     */
    public function test_edge01_types_filter_class_only(): void {
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
    // CLASS SQL end-to-end tests (W-01 fix)
    // -------------------------------------------------------------------------

    /**
     * CLASS-01: a booked facetoface signup (statuscode=70) returns one class event
     * with the correct fields (type, title, starttime, endtime).
     * Exercises the full JOIN chain: facetoface_sessions → sessions_dates →
     * signups → signups_status → enrol → user_enrolments.
     */
    public function test_class_event_returned_for_booked_signup(): void {
        $this->resetAfterTest();

        $student = self::getDataGenerator()->create_user();
        $this->setUser($student);
        $this->getDataGenerator()->role_assign(
            $this->get_role_id('student'), $student->id, \context_system::instance()->id
        );

        $from  = self::BASE_TIME;
        $to    = $from + 7 * 86400;
        $start = $from + 3600;
        $end   = $from + 7200;

        $this->create_facetoface_session($student->id, $start, $end, 70);

        $result = get_events::execute(0, $from, $to, 0, ['class']);
        $result = external_api::clean_returnvalue(get_events::execute_returns(), $result);

        $this->assertSame(1, $result['total']);
        $event = $result['events'][0];
        $this->assertSame('class', $event['type']);
        $this->assertStringStartsWith('class_', $event['id']);
        $this->assertSame($start, $event['starttime']);
        $this->assertSame($end, $event['endtime']);
        $this->assertSame('#3b82f6', $event['color']);
    }

    /**
     * CLASS-02: a session with 2 dates in range returns 2 events with distinct IDs
     * (class_<sessionid>_<dateid1> vs class_<sessionid>_<dateid2>).
     */
    public function test_class_session_with_two_dates_returns_two_events(): void {
        global $DB;
        $this->resetAfterTest();

        $student = self::getDataGenerator()->create_user();
        $this->setUser($student);
        $this->getDataGenerator()->role_assign(
            $this->get_role_id('student'), $student->id, \context_system::instance()->id
        );

        $from = self::BASE_TIME;
        $to   = $from + 7 * 86400;

        // Create the session (adds one date automatically at $from+3600 → $from+7200).
        $sessionid = $this->create_facetoface_session($student->id, $from + 3600, $from + 7200, 70);

        // Add a second date to the same session — same range, different slot.
        $DB->insert_record('facetoface_sessions_dates', (object)[
            'sessionid'  => $sessionid,
            'timestart'  => $from + 86400,
            'timefinish' => $from + 90000,
        ]);

        $result = get_events::execute(0, $from, $to, 0, ['class']);
        $result = external_api::clean_returnvalue(get_events::execute_returns(), $result);

        $this->assertSame(2, $result['total'], 'Two dates should produce two events');
        $ids = array_column($result['events'], 'id');
        $this->assertCount(2, array_unique($ids), 'Event IDs must be distinct');
        $this->assertStringStartsWith('class_', $ids[0]);
        $this->assertStringStartsWith('class_', $ids[1]);
    }

    /**
     * CLASS-03: a signup with statuscode < 70 (e.g. 10 = pending/cancelled)
     * must NOT appear in the results — only statuscode >= 70 is considered booked.
     */
    public function test_class_signup_below_statuscode_70_excluded(): void {
        $this->resetAfterTest();

        $student = self::getDataGenerator()->create_user();
        $this->setUser($student);
        $this->getDataGenerator()->role_assign(
            $this->get_role_id('student'), $student->id, \context_system::instance()->id
        );

        $from = self::BASE_TIME;
        $to   = $from + 7 * 86400;

        // statuscode=10 = pending / not yet booked.
        $this->create_facetoface_session($student->id, $from + 3600, $from + 7200, 10);

        $result = get_events::execute(0, $from, $to, 0, ['class']);
        $result = external_api::clean_returnvalue(get_events::execute_returns(), $result);

        $this->assertSame(0, $result['total'], 'Pending signup (statuscode=10) must not appear');
    }

    /**
     * CLASS-04: a user without an active enrolment for the course must NOT see
     * the class event — verifies the EXISTS enrolment guard (BUG-03 regression).
     */
    public function test_class_event_excluded_without_course_enrolment(): void {
        global $DB;
        $this->resetAfterTest();

        $student = self::getDataGenerator()->create_user();
        $this->setUser($student);
        $this->getDataGenerator()->role_assign(
            $this->get_role_id('student'), $student->id, \context_system::instance()->id
        );

        $from = self::BASE_TIME;
        $to   = $from + 7 * 86400;

        // Create session (which also adds enrolment).
        $this->create_facetoface_session($student->id, $from + 3600, $from + 7200, 70);

        // Suspend all active enrolments to simulate "not enrolled".
        $DB->set_field('user_enrolments', 'status', 1, ['userid' => $student->id]);

        $result = get_events::execute(0, $from, $to, 0, ['class']);
        $result = external_api::clean_returnvalue(get_events::execute_returns(), $result);

        $this->assertSame(0, $result['total'], 'Suspended enrolment must not appear');
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
