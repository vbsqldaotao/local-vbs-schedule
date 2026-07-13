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
 * Calendar AMD module — Tasks 3.4 (calendar page) + 3.5 (filter bar).
 *
 * Handles:
 *  - Fetching events via core/ajax → local_vbs_schedule_get_events
 *  - Rendering month / week / list views
 *  - Filter bar interactions (course, type, date range)
 *  - View-mode navigation (prev/next/today)
 *  - Persisting view preference via local_vbs_schedule_save_pref (EDGE-07)
 *
 * @module     local_vbs_schedule/calendar
 * @copyright  2026 VBS Đào tạo
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Log from 'core/log';

// ── DOM selectors ───────────────────────────────────────────────────────────
const SELECTORS = {
    ROOT:         '[data-region="vbs-schedule-calendar"]',
    CONTAINER:    '[data-region="calendar-container"]',
    PERIOD:       '[data-region="current-period"]',
    VIEW_INPUTS:  '[name="vbs-viewmode"]',
    TYPE_INPUTS:  '[name="vbs-type"]',
    COURSE:       '[data-filter="course"]',
    DATE_FROM:    '[data-filter="datefrom"]',
    DATE_TO:      '[data-filter="dateto"]',
    APPLY:        '[data-action="apply-filter"]',
    NAV_PREV:     '[data-action="nav-prev"]',
    NAV_NEXT:     '[data-action="nav-next"]',
    NAV_TODAY:    '[data-action="nav-today"]',
};

// ── Module state ────────────────────────────────────────────────────────────
/** @type {HTMLElement|null} */
let root = null;

const state = {
    viewmode:  'month',
    year:      0,
    month:     0,     // 0-indexed
    weekStart: null,  // Date — first day (Monday) of the displayed week
    courseid:  0,
    types:     ['class', 'exam'],
    datefrom:  0,     // unix timestamp — used by list view and custom filter
    dateto:    0,
};

// ── Public API ──────────────────────────────────────────────────────────────

/**
 * Initialise the calendar module.
 *
 * Called by PHP via $PAGE->requires->js_call_amd('local_vbs_schedule/calendar', 'init', [...]).
 *
 * @param {Object} options
 * @param {string}  options.viewmode  Saved view mode (month|week|list)
 * @param {boolean} options.showclass Whether class events are toggled on
 * @param {boolean} options.showexam  Whether exam events are toggled on
 */
export const init = (options) => {
    root = document.querySelector(SELECTORS.ROOT);
    if (!root) {
        return;
    }

    const now = new Date();
    state.viewmode  = options.viewmode || 'month';
    state.year      = now.getFullYear();
    state.month     = now.getMonth();
    state.weekStart = getWeekStart(now);

    // Sync type radio from saved pref.
    if (options.showclass && !options.showexam) {
        state.types = ['class'];
        checkRadio('#type-class');
    } else if (!options.showclass && options.showexam) {
        state.types = ['exam'];
        checkRadio('#type-exam');
    } else {
        state.types = ['class', 'exam'];
        checkRadio('#type-both');
    }

    // Set default date range inputs to the current month.
    initDefaultDates();

    bindEvents();
    renderCalendar();
};

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * @param {string} selector
 */
const checkRadio = (selector) => {
    const el = root.querySelector(selector);
    if (el) {
        el.checked = true;
    }
};

/**
 * Populate date inputs with start/end of the current month and update state.
 */
const initDefaultDates = () => {
    const now      = new Date();
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
    const lastDay  = new Date(now.getFullYear(), now.getMonth() + 1, 0);

    setDateInput(SELECTORS.DATE_FROM, firstDay);
    setDateInput(SELECTORS.DATE_TO,   lastDay);

    state.datefrom = toUnixStart(firstDay);
    state.dateto   = toUnixEnd(lastDay);
};

/**
 * @param {string} selector
 * @param {Date}   date
 */
const setDateInput = (selector, date) => {
    const el = root.querySelector(selector);
    if (el) {
        el.value = toDateInputValue(date);
    }
};

/**
 * Format a Date as YYYY-MM-DD for HTML date inputs.
 *
 * @param {Date} date
 * @return {string}
 */
const toDateInputValue = (date) => {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
};

/**
 * Start-of-day unix timestamp (00:00:00).
 *
 * @param {Date} date
 * @return {number}
 */
const toUnixStart = (date) => Math.floor(new Date(date.getFullYear(), date.getMonth(), date.getDate(), 0, 0, 0).getTime() / 1000);

/**
 * End-of-day unix timestamp (23:59:59).
 *
 * @param {Date} date
 * @return {number}
 */
const toUnixEnd = (date) => Math.floor(new Date(date.getFullYear(), date.getMonth(), date.getDate(), 23, 59, 59).getTime() / 1000);

/**
 * Get the Monday of the week containing `date`.
 *
 * @param {Date} date
 * @return {Date}
 */
const getWeekStart = (date) => {
    const d   = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const dow = d.getDay(); // 0=Sun
    const diff = (dow === 0) ? -6 : 1 - dow;
    d.setDate(d.getDate() + diff);
    return d;
};

/**
 * HH:MM from a unix timestamp.
 *
 * @param {number} timestamp
 * @return {string}
 */
const formatTime = (timestamp) => {
    const d = new Date(timestamp * 1000);
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

/**
 * DD/MM/YYYY from a Date object.
 *
 * @param {Date} date
 * @return {string}
 */
const formatDate = (date) => `${date.getDate()}/${date.getMonth() + 1}/${date.getFullYear()}`;

/**
 * Minimal HTML escape.
 *
 * @param {string} str
 * @return {string}
 */
const escHtml = (str) => {
    if (!str) {
        return '';
    }
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
};

// ── Event binding ────────────────────────────────────────────────────────────

const bindEvents = () => {
    // View-mode radio buttons.
    root.querySelectorAll(SELECTORS.VIEW_INPUTS).forEach(input => {
        input.addEventListener('change', () => {
            state.viewmode = input.value;
            savePref();
            renderCalendar();
        });
    });

    // Event-type radio buttons.
    root.querySelectorAll(SELECTORS.TYPE_INPUTS).forEach(input => {
        input.addEventListener('change', () => {
            if (input.value === 'both') {
                state.types = ['class', 'exam'];
            } else {
                state.types = [input.value];
            }
        });
    });

    // Course dropdown (updates state; re-fetch on Apply).
    const courseEl = root.querySelector(SELECTORS.COURSE);
    if (courseEl) {
        courseEl.addEventListener('change', () => {
            state.courseid = parseInt(courseEl.value, 10) || 0;
        });
    }

    // Apply filter button.
    const applyBtn = root.querySelector(SELECTORS.APPLY);
    if (applyBtn) {
        applyBtn.addEventListener('click', applyFilter);
    }

    // Navigation.
    root.querySelector(SELECTORS.NAV_PREV)?.addEventListener('click', navPrev);
    root.querySelector(SELECTORS.NAV_NEXT)?.addEventListener('click', navNext);
    root.querySelector(SELECTORS.NAV_TODAY)?.addEventListener('click', navToday);
};

const applyFilter = () => {
    const fromEl = root.querySelector(SELECTORS.DATE_FROM);
    const toEl   = root.querySelector(SELECTORS.DATE_TO);
    if (fromEl?.value) {
        state.datefrom = toUnixStart(new Date(fromEl.value));
    }
    if (toEl?.value) {
        state.dateto = toUnixEnd(new Date(toEl.value));
    }
    renderCalendar();
};

// ── Navigation ───────────────────────────────────────────────────────────────

const navPrev = () => {
    if (state.viewmode === 'month') {
        if (state.month === 0) { state.month = 11; state.year--; }
        else { state.month--; }
    } else if (state.viewmode === 'week') {
        const d = new Date(state.weekStart);
        d.setDate(d.getDate() - 7);
        state.weekStart = d;
    } else {
        const span = state.dateto - state.datefrom;
        state.datefrom -= (span + 1);
        state.dateto   -= (span + 1);
        syncDateInputsFromState();
    }
    renderCalendar();
};

const navNext = () => {
    if (state.viewmode === 'month') {
        if (state.month === 11) { state.month = 0; state.year++; }
        else { state.month++; }
    } else if (state.viewmode === 'week') {
        const d = new Date(state.weekStart);
        d.setDate(d.getDate() + 7);
        state.weekStart = d;
    } else {
        const span = state.dateto - state.datefrom;
        state.datefrom += (span + 1);
        state.dateto   += (span + 1);
        syncDateInputsFromState();
    }
    renderCalendar();
};

const navToday = () => {
    const now = new Date();
    state.year      = now.getFullYear();
    state.month     = now.getMonth();
    state.weekStart = getWeekStart(now);
    initDefaultDates();
    renderCalendar();
};

const syncDateInputsFromState = () => {
    setDateInput(SELECTORS.DATE_FROM, new Date(state.datefrom * 1000));
    setDateInput(SELECTORS.DATE_TO,   new Date(state.dateto   * 1000));
};

// ── Period label ─────────────────────────────────────────────────────────────

const updatePeriodLabel = () => {
    const el = root.querySelector(SELECTORS.PERIOD);
    if (!el) {
        return;
    }
    const monthNames = [
        'Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4',
        'Tháng 5', 'Tháng 6', 'Tháng 7', 'Tháng 8',
        'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12',
    ];
    if (state.viewmode === 'month') {
        el.textContent = `${monthNames[state.month]} ${state.year}`;
    } else if (state.viewmode === 'week') {
        const end = new Date(state.weekStart);
        end.setDate(end.getDate() + 6);
        el.textContent = `${formatDate(state.weekStart)} – ${formatDate(end)}`;
    } else {
        el.textContent = `${formatDate(new Date(state.datefrom * 1000))} – ${formatDate(new Date(state.dateto * 1000))}`;
    }
};

// ── Date range for API call ───────────────────────────────────────────────────

const getApiDateRange = () => {
    if (state.viewmode === 'month') {
        const first = new Date(state.year, state.month, 1);
        const last  = new Date(state.year, state.month + 1, 0);
        return {datefrom: toUnixStart(first), dateto: toUnixEnd(last)};
    }
    if (state.viewmode === 'week') {
        const end = new Date(state.weekStart);
        end.setDate(end.getDate() + 6);
        return {datefrom: toUnixStart(state.weekStart), dateto: toUnixEnd(end)};
    }
    return {datefrom: state.datefrom, dateto: state.dateto};
};

// ── Fetch ────────────────────────────────────────────────────────────────────

/**
 * @param {number} datefrom
 * @param {number} dateto
 * @return {Promise<{events: Array, total: number}>}
 */
const fetchEvents = (datefrom, dateto) => Ajax.call([{
    methodname: 'local_vbs_schedule_get_events',
    args: {
        userid:   0,
        datefrom,
        dateto,
        courseid: state.courseid,
        types:    state.types,
    },
}])[0];

// ── Main render dispatcher ───────────────────────────────────────────────────

const renderCalendar = async () => {
    const pending   = new Pending('local_vbs_schedule/calendar:render');
    const container = root.querySelector(SELECTORS.CONTAINER);

    showSpinner(container);
    updatePeriodLabel();

    const {datefrom, dateto} = getApiDateRange();

    try {
        const result = await fetchEvents(datefrom, dateto);
        const events = result.events || [];

        if (state.viewmode === 'month') {
            renderMonth(container, events);
        } else if (state.viewmode === 'week') {
            renderWeek(container, events);
        } else {
            renderList(container, events);
        }
    } catch (err) {
        Notification.exception(err);
    } finally {
        pending.resolve();
    }
};

const showSpinner = (container) => {
    container.innerHTML = `
        <div class="d-flex justify-content-center p-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading…</span>
            </div>
        </div>`;
};

// ── Month view ───────────────────────────────────────────────────────────────

const renderMonth = (container, events) => {
    const firstDay    = new Date(state.year, state.month, 1);
    const daysInMonth = new Date(state.year, state.month + 1, 0).getDate();

    // Build event index: "year-month-day" → events[].
    const byDate = {};
    events.forEach(ev => {
        const d   = new Date(ev.starttime * 1000);
        const key = `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
        (byDate[key] = byDate[key] || []).push(ev);
    });

    const today    = new Date();
    const todayKey = `${today.getFullYear()}-${today.getMonth()}-${today.getDate()}`;

    const dayHeaders = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
    // Monday-based leading empty cells.
    const dow     = firstDay.getDay(); // 0=Sun
    const leading = (dow === 0) ? 6 : dow - 1;

    let cells = '';

    for (let i = 0; i < leading; i++) {
        cells += `<div class="vbs-month-cell vbs-month-cell--other"></div>`;
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const key      = `${state.year}-${state.month}-${day}`;
        const isToday  = (key === todayKey);
        const evList   = byDate[key] || [];
        const dayClass = `vbs-month-cell${isToday ? ' vbs-month-cell--today' : ''}`;
        const numClass = isToday ? 'vbs-month-cell-day vbs-today-badge' : 'vbs-month-cell-day';

        const pills = evList.slice(0, 3).map(ev =>
            `<div class="vbs-event-pill" style="background:${ev.color}" title="${escHtml(ev.title)}">
                <span class="vbs-event-pill-text">${escHtml(ev.title)}</span>
            </div>`
        ).join('');

        const more = evList.length > 3
            ? `<div class="vbs-event-more">+${evList.length - 3} thêm</div>`
            : '';

        cells += `<div class="${dayClass}">
            <div class="${numClass}">${day}</div>
            ${pills}${more}
        </div>`;
    }

    container.innerHTML = `
        <div class="vbs-month-view">
            <div class="vbs-month-header">
                ${dayHeaders.map(h => `<div class="vbs-month-dayname">${h}</div>`).join('')}
            </div>
            <div class="vbs-month-grid">${cells}</div>
        </div>`;
};

// ── Week view ────────────────────────────────────────────────────────────────

const renderWeek = (container, events) => {
    const days = [];
    for (let i = 0; i < 7; i++) {
        const d = new Date(state.weekStart);
        d.setDate(d.getDate() + i);
        days.push(d);
    }

    const today    = new Date();
    const todayKey = `${today.getFullYear()}-${today.getMonth()}-${today.getDate()}`;

    const byDate = {};
    events.forEach(ev => {
        const d   = new Date(ev.starttime * 1000);
        const key = `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
        (byDate[key] = byDate[key] || []).push(ev);
    });

    const dayNames = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

    const headerCols = days.map((d, i) => {
        const key     = `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
        const isToday = (key === todayKey);
        return `<div class="vbs-week-col-header${isToday ? ' vbs-today-header' : ''}">
            <div class="vbs-week-dayname">${dayNames[i]}</div>
            <div class="vbs-week-daynum${isToday ? ' vbs-today-badge' : ''}">${d.getDate()}</div>
        </div>`;
    }).join('');

    const bodyCols = days.map(d => {
        const key     = `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
        const evList  = byDate[key] || [];
        const evHtml  = evList.map(ev =>
            `<div class="vbs-week-event" style="border-left:4px solid ${ev.color}">
                <div class="vbs-week-event-time">${formatTime(ev.starttime)}</div>
                <div class="vbs-week-event-title">${escHtml(ev.title)}</div>
                ${ev.location ? `<div class="vbs-week-event-loc">${escHtml(ev.location)}</div>` : ''}
            </div>`
        ).join('');
        return `<div class="vbs-week-col">${evHtml}</div>`;
    }).join('');

    container.innerHTML = `
        <div class="vbs-week-view">
            <div class="vbs-week-header">${headerCols}</div>
            <div class="vbs-week-body">${bodyCols}</div>
        </div>`;
};

// ── List view ────────────────────────────────────────────────────────────────

const renderList = (container, events) => {
    if (events.length === 0) {
        container.innerHTML = `
            <div class="vbs-empty-state text-center py-5">
                <i class="fa fa-calendar-times fa-3x text-muted mb-3" aria-hidden="true"></i>
                <p class="text-muted mb-0">Không có sự kiện trong khoảng thời gian này.</p>
            </div>`;
        return;
    }

    const rows = events.map(ev => {
        const start   = new Date(ev.starttime * 1000);
        const badge   = ev.type === 'class' ? 'primary' : 'danger';
        const typeStr = ev.type === 'class' ? 'Lịch học' : 'Lịch thi';
        return `<tr>
            <td>
                <div class="fw-semibold">${formatDate(start)}</div>
                <small class="text-muted">${formatTime(ev.starttime)} – ${formatTime(ev.endtime)}</small>
            </td>
            <td>
                <span class="badge bg-${badge} me-1">${typeStr}</span>
                ${escHtml(ev.title)}
                ${ev.instructor ? `<div><small class="text-muted">GV: ${escHtml(ev.instructor)}</small></div>` : ''}
            </td>
            <td><small>${escHtml(ev.location || '—')}</small></td>
            <td><span class="badge bg-secondary text-capitalize">${escHtml(ev.status)}</span></td>
        </tr>`;
    }).join('');

    container.innerHTML = `
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Thời gian</th>
                        <th>Sự kiện</th>
                        <th>Địa điểm</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
};

// ── Persist preference (EDGE-07) ─────────────────────────────────────────────

const savePref = () => {
    Ajax.call([{
        methodname: 'local_vbs_schedule_save_pref',
        args: {
            view_mode:  state.viewmode,
            show_class: state.types.includes('class'),
            show_exam:  state.types.includes('exam'),
        },
    }])[0].catch(err => Log.error('vbs_schedule: failed to save pref', err));
};
