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
 * Vietnamese language strings for local_vbs_schedule.
 *
 * @package     local_vbs_schedule
 * @copyright   2026 VBS Đào tạo
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Lịch học – thi VBS';

// Capabilities.
$string['vbs_schedule:view']    = 'Xem lịch cá nhân';
$string['vbs_schedule:viewall'] = 'Xem lịch của bất kỳ người dùng nào';

// Page.
$string['pagetitle']    = 'Lịch của tôi';
$string['nocapability'] = 'Bạn không có quyền xem lịch này.';

// API errors.
$string['err_daterange']      = 'Khoảng thời gian không được vượt quá 90 ngày.';
$string['err_dateinvalid']    = 'dateto phải lớn hơn hoặc bằng datefrom.';
$string['err_timestamprange'] = 'Timestamp phải là số nguyên dương.';

// Privacy.
$string['privacy:metadata']                           = 'Plugin VBS Schedule lưu trữ cài đặt hiển thị của người dùng (chế độ xem, bộ lọc loại sự kiện).';
$string['privacy:metadata:vbs_schedule_pref']         = 'Cài đặt hiển thị lịch của từng người dùng';
$string['privacy:metadata:vbs_schedule_pref:userid']  = 'Người dùng sở hữu cài đặt này';
$string['privacy:metadata:vbs_schedule_pref:view_mode']    = 'Chế độ xem ưa thích (month/week/list)';
$string['privacy:metadata:vbs_schedule_pref:show_class']   = 'Hiện hay ẩn sự kiện lớp học';
$string['privacy:metadata:vbs_schedule_pref:show_exam']    = 'Hiện hay ẩn sự kiện thi';
$string['privacy:metadata:vbs_schedule_pref:timemodified'] = 'Thời điểm cập nhật cài đặt lần cuối';
