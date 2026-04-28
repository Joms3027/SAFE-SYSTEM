// Employee Logs JavaScript - Extracted for better performance and maintainability

// Make sure functions are available globally
window.employeeLogsLoaded = false;

// Ensure functions are available immediately on script load
(function() {
    'use strict';
    
    // Mark script as loading
    if (typeof window.employeeLogsScriptLoading === 'undefined') {
        window.employeeLogsScriptLoading = true;
    }
})();

// Automatic filtering with debounce
(function() {
    let searchTimeout;
    const searchInput = document.getElementById('search');
    const positionSelect = document.getElementById('position');
    const filterForm = document.getElementById('filterForm');
    const searchLoading = document.getElementById('searchLoading');
    
    // Function to show loading indicator
    function showSearchLoading() {
        if (searchLoading) {
            searchLoading.classList.add('active');
        }
    }
    
    // Function to hide loading indicator
    function hideSearchLoading() {
        if (searchLoading) {
            searchLoading.classList.remove('active');
        }
    }
    
    // Function to apply filters via AJAX (no page reload)
    function autoFilter() {
        if (!filterForm) return;
        showSearchLoading();
        const formData = new FormData(filterForm);
        const params = new URLSearchParams(formData);
        params.set('ajax', '1');
        const url = 'employee_logs.php?' + params.toString();
        fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            cache: 'no-store'
        })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    const resultsEl = document.getElementById('employeeLogsResults');
                    const badgesEl = document.getElementById('employeeLogsBadges');
                    if (resultsEl) resultsEl.innerHTML = data.resultsHtml;
                    if (badgesEl) badgesEl.innerHTML = data.cardHeaderHtml;
                    // Update URL without reload (exclude ajax param from visible URL)
                    params.delete('ajax');
                    const qs = params.toString();
                    const newUrl = (window.location.pathname.split('?')[0]) + (qs ? '?' + qs : '');
                    if (typeof history.replaceState === 'function') {
                        history.replaceState({}, '', newUrl);
                    }
                    // Re-bind pagination link clicks for AJAX
                    bindPaginationClicks();
                }
            })
            .catch(function(err) { console.error('Filter error:', err); })
            .finally(function() { hideSearchLoading(); });
    }

    // Intercept pagination link clicks to use AJAX
    function bindPaginationClicks() {
        const container = document.getElementById('employeeLogsResults');
        if (!container) return;
        const links = container.querySelectorAll('.employee-logs-pagination .page-link');
        links.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const href = link.getAttribute('href');
                if (href && href.indexOf('page=') !== -1) {
                    const match = href.match(/page=(\d+)/);
                    if (match) {
                        const page = match[1];
                        const formData = new FormData(filterForm);
                        formData.set('page', page);
                        formData.set('ajax', '1');
                        const params = new URLSearchParams(formData);
                        const url = 'employee_logs.php?' + params.toString();
                        showSearchLoading();
                        fetch(url, {
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            cache: 'no-store'
                        })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    const resultsEl = document.getElementById('employeeLogsResults');
                                    const badgesEl = document.getElementById('employeeLogsBadges');
                                    if (resultsEl) resultsEl.innerHTML = data.resultsHtml;
                                    if (badgesEl) badgesEl.innerHTML = data.cardHeaderHtml;
                                    params.delete('ajax');
                                    const qs = params.toString();
                                    const url = (window.location.pathname.split('?')[0]) + (qs ? '?' + qs : '');
                                    history.replaceState && history.replaceState({}, '', url);
                                    bindPaginationClicks();
                                }
                            })
                            .catch(function(err) { console.error('Pagination error:', err); })
                            .finally(function() { hideSearchLoading(); });
                    }
                }
            });
        });
    }
    
    // Debounced search input handler
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            showSearchLoading();
            searchTimeout = setTimeout(function() {
                autoFilter();
            }, 500); // Wait 500ms after user stops typing
        });
        
        // Hide loading when input loses focus (in case form doesn't submit)
        searchInput.addEventListener('blur', function() {
            setTimeout(hideSearchLoading, 300);
        });
    }
    
    // Immediate position change handler
    if (positionSelect) {
        positionSelect.addEventListener('change', function() {
            clearTimeout(searchTimeout); // Cancel any pending search
            autoFilter();
        });
    }
    
    // Immediate department change handler
    const departmentSelect = document.getElementById('department');
    if (departmentSelect) {
        departmentSelect.addEventListener('change', function() {
            clearTimeout(searchTimeout); // Cancel any pending search
            autoFilter();
        });
    }
    
    // Immediate employment status change handler
    const employmentStatusSelect = document.getElementById('employment_status');
    if (employmentStatusSelect) {
        employmentStatusSelect.addEventListener('change', function() {
            clearTimeout(searchTimeout); // Cancel any pending search
            autoFilter();
        });
    }
    
    // Prevent form submit (use AJAX instead)
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            autoFilter();
        });
    }

    // Bind pagination clicks on initial load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            hideSearchLoading();
            bindPaginationClicks();
            bindOpenPardonClicks();
        });
    } else {
        hideSearchLoading();
        bindPaginationClicks();
        bindOpenPardonClicks();
    }
    
    // Mark as loaded
    window.employeeLogsLoaded = true;
})();

// Toast helper
function showToast(message, type = 'info') {
    // Use Bootstrap toast if available, otherwise alert
    if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
        // Create toast element
        const toastHtml = `
            <div class="toast align-items-center text-white bg-${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        const toastContainer = document.getElementById('toastContainer') || (() => {
            const container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
            return container;
        })();
        toastContainer.innerHTML = toastHtml;
        const toastElement = toastContainer.querySelector('.toast');
        const toast = new bootstrap.Toast(toastElement);
        toast.show();
    } else {
        alert(message);
    }
}

let currentEmployeeId = '';

// Default official times constants
const DEFAULT_OFFICIAL_TIMES = {
    time_in: '08:00:00',
    lunch_out: '12:00:00',
    lunch_in: '13:00:00',
    time_out: '17:00:00'
};

// Cache for employee official times
const employeeOfficialTimesCache = {};

/**
 * Convert hours to day fraction based on 8-hour workday
 * Uses the conversion table from Table IV
 * @param {number} hours - Total hours (can be decimal)
 * @returns {number} - Day fraction (can exceed 1.000 for hours > 8)
 * 
 * Examples:
 * - 10 hours = 1.250 days (8 hours = 1.000 + 2 hours = 0.250)
 * - 16 hours = 2.000 days (8 hours = 1.000 + 8 hours = 1.000)
 * - 18 hours = 2.250 days (8 hours = 1.000 + 8 hours = 1.000 + 2 hours = 0.250)
 */
function hoursToDayFraction(hours) {
    if (!hours || hours <= 0) return 0.000;
    
    // Conversion table for hours (1-8)
    const hoursTable = {
        1: 0.125, 2: 0.250, 3: 0.375, 4: 0.500,
        5: 0.625, 6: 0.750, 7: 0.875, 8: 1.000
    };
    
    // Conversion table for minutes (1-59)
    // Based on Table IV: minutes / 480 (8 hours = 480 minutes = 1.0 day)
    const minutesTable = {
        1: 0.002, 2: 0.004, 3: 0.006, 4: 0.008, 5: 0.010,
        6: 0.012, 7: 0.015, 8: 0.017, 9: 0.019, 10: 0.021,
        11: 0.023, 12: 0.025, 13: 0.027, 14: 0.029, 15: 0.031,
        16: 0.033, 17: 0.035, 18: 0.037, 19: 0.040, 20: 0.042,
        21: 0.044, 22: 0.046, 23: 0.048, 24: 0.050, 25: 0.052,
        26: 0.054, 27: 0.056, 28: 0.058, 29: 0.060, 30: 0.062,
        31: 0.065, 32: 0.067, 33: 0.069, 34: 0.071, 35: 0.073,
        36: 0.075, 37: 0.077, 38: 0.079, 39: 0.081, 40: 0.083,
        41: 0.085, 42: 0.087, 43: 0.090, 44: 0.092, 45: 0.094,
        46: 0.096, 47: 0.098, 48: 0.100, 49: 0.102, 50: 0.104,
        51: 0.106, 52: 0.108, 53: 0.110, 54: 0.112, 55: 0.115,
        56: 0.117, 57: 0.119, 58: 0.121, 59: 0.123
    };
    
    // Calculate full 8-hour days (each 8 hours = 1.000 day)
    const fullDays = Math.floor(hours / 8);
    const fullDaysFraction = fullDays * 1.000;
    
    // Calculate remaining hours after full 8-hour days
    const remainingHours = hours % 8;
    
    // Convert remaining hours to whole hours and minutes
    const wholeHours = Math.floor(remainingHours);
    const decimalMinutes = (remainingHours - wholeHours) * 60;
    const wholeMinutes = Math.round(decimalMinutes);
    
    let remainingFraction = 0.0;
    
    // Add hours fraction from remaining hours (1-8)
    if (wholeHours > 0 && wholeHours <= 8) {
        remainingFraction += hoursTable[wholeHours] || 0;
    }
    
    // Add minutes fraction
    if (wholeMinutes > 0 && wholeMinutes <= 59) {
        remainingFraction += minutesTable[wholeMinutes] || 0;
    }
    
    // Total = full days + remaining fraction
    const totalFraction = fullDaysFraction + remainingFraction;
    
    // Round to 3 decimal places
    return Math.round(totalFraction * 1000) / 1000;
}

// Helper function to get week number
function getWeekNumber(date) {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const dayNum = d.getUTCDay() || 7;
    d.setUTCDate(d.getUTCDate() + 4 - dayNum);
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
}

// Helper function to get weekday name from date
function getWeekdayName(dateStr) {
    const date = new Date(dateStr + 'T00:00:00');
    const weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return weekdays[date.getDay()];
}

// Get official times for an employee for a specific date (based on weekday)
async function getOfficialTimesForDate(employeeId, logDate) {
    if (!employeeId || !logDate) {
        return { found: false, times: DEFAULT_OFFICIAL_TIMES };
    }
    
    const weekday = getWeekdayName(logDate);
    const cacheKey = `${employeeId}_${logDate}_${weekday}`;
    
    // Check cache first
    if (employeeOfficialTimesCache[cacheKey]) {
        return employeeOfficialTimesCache[cacheKey];
    }
    
    try {
        // Get official times that apply to this date and weekday
        const response = await fetch(`manage_official_times_api.php?action=get_by_date&employee_id=${encodeURIComponent(employeeId)}&date=${encodeURIComponent(logDate)}&weekday=${encodeURIComponent(weekday)}`);
        const data = await response.json();
        
        if (data.success && data.official_time && data.official_time.found) {
            const hasLunch = !!(data.official_time.lunch_out && data.official_time.lunch_in);
            const officialTimes = {
                found: true,
                weekday: weekday,
                hasLunch: hasLunch,
                times: {
                    time_in: data.official_time.time_in + ':00',
                    lunch_out: hasLunch ? (data.official_time.lunch_out + ':00') : null,
                    lunch_in: hasLunch ? (data.official_time.lunch_in + ':00') : null,
                    time_out: data.official_time.time_out + ':00'
                }
            };
            
            // Cache it
            employeeOfficialTimesCache[cacheKey] = officialTimes;
            return officialTimes;
        } else {
            // No official time found
            const result = {
                found: false,
                weekday: weekday,
                times: DEFAULT_OFFICIAL_TIMES
            };
            employeeOfficialTimesCache[cacheKey] = result;
            return result;
        }
    } catch (error) {
        console.error('Error fetching official times:', error);
        return { found: false, times: DEFAULT_OFFICIAL_TIMES };
    }
}

// Parse time string to minutes from midnight
function parseTime(timeStr) {
    if (!timeStr) return null;
    const parts = timeStr.split(':');
    if (parts.length >= 2) {
        const hours = parseInt(parts[0], 10) || 0;
        const minutes = parseInt(parts[1], 10) || 0;
        return hours * 60 + minutes;
    }
    return null;
}

// Convert 24h time (HH:mm or HH:mm:ss) to 12-hour format for HR display (e.g. "8:00 AM", "1:00 PM")
// Treats 00:00 / 00:00:00 as blank (returns '-') since these represent empty/unlogged times
function formatTimeTo12h(timeStr) {
    if (!timeStr || timeStr === '-' || timeStr === '—') return '-';
    const trimmed = String(timeStr).trim();
    if (!trimmed) return '-';
    // Treat midnight (00:00) as blank - DB/API often use this for empty time fields
    if (trimmed === '00:00' || trimmed === '00:00:00' || trimmed === '0:00' || trimmed === '0:00:00') return '-';
    const parts = trimmed.split(':');
    if (parts.length < 2) return timeStr;
    let hours = parseInt(parts[0], 10) || 0;
    const minutes = parseInt(parts[1], 10) || 0;
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    if (hours === 0) hours = 12;
    const mins = String(minutes).padStart(2, '0');
    return `${hours}:${mins} ${ampm}`;
}
window.formatTimeTo12h = formatTimeTo12h;

// Convert minutes to hh:mm:ss format
function minutesToTimeFormat(totalMinutes) {
    if (totalMinutes <= 0) return '00:00:00';
    const hours = Math.floor(totalMinutes / 60);
    const minutes = Math.floor(totalMinutes % 60);
    const seconds = Math.floor((totalMinutes % 1) * 60);
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

// Helper function to check if a time is actually logged (not empty and not 00:00)
function isTimeLogged(time) {
    if (!time) return false;
    const trimmed = String(time).trim();
    const up = trimmed.toUpperCase();
    if (up === 'HOLIDAY' || up === 'LEAVE') return false;
    // Treat 00:00, 00:00:00, or any variation as "not logged"
    return trimmed !== '00:00' && trimmed !== '00:00:00' && trimmed !== '0:00' && trimmed !== '0:00:00';
}

/**
 * Half-day holiday: only the declared half is credited from official time (AM or PM segment).
 * The other half is a normal work segment: actual times count; missing segment → tardiness/undertime.
 */
function calculateHalfDayHolidayHours(log, officialTimesData, holidayStatusBadge, halfDayPeriod) {
    const official = officialTimesData.times;
    const officialHasLunch = (officialTimesData.hasLunch === true) || (officialTimesData.found === false);
    const officialInMin = parseTime(official.time_in);
    const officialOutMin = parseTime(official.time_out);
    const officialLunchOutMin = parseTime(official.lunch_out);
    const officialLunchInMin = parseTime(official.lunch_in);

    let officialMorningMinutes = 0;
    let officialAfternoonMinutes = 0;
    if (officialHasLunch && officialInMin !== null && officialOutMin !== null
        && officialLunchOutMin !== null && officialLunchInMin !== null) {
        officialMorningMinutes = Math.max(0, officialLunchOutMin - officialInMin);
        officialAfternoonMinutes = Math.max(0, officialOutMin - officialLunchInMin);
    } else if (officialInMin !== null && officialOutMin !== null) {
        const total = Math.max(0, officialOutMin - officialInMin);
        officialMorningMinutes = total / 2;
        officialAfternoonMinutes = total / 2;
    }

    const isHol = function (v) { return v === 'HOLIDAY'; };
    const hasTimeIn = isTimeLogged(log.time_in);
    const hasLunchOut = isTimeLogged(log.lunch_out);
    const hasLunchIn = isTimeLogged(log.lunch_in);
    const hasTimeOut = isTimeLogged(log.time_out);

    let hours = 0;
    let lateMinutes = 0;
    let undertimeMinutes = 0;

    if (halfDayPeriod === 'afternoon') {
        // PM holiday: morning is work; afternoon is holiday credit (official PM segment)
        if (hasTimeIn && hasLunchOut && !isHol(log.time_in) && !isHol(log.lunch_out)) {
            const aIn = parseTime(log.time_in);
            const aLo = parseTime(log.lunch_out);
            if (aIn !== null && aLo !== null) {
                hours += (aLo - aIn) / 60;
                if (officialInMin !== null && aIn > officialInMin) lateMinutes += (aIn - officialInMin);
                if (officialLunchOutMin !== null && aLo < officialLunchOutMin) undertimeMinutes += (officialLunchOutMin - aLo);
            }
        } else {
            lateMinutes += officialMorningMinutes;
        }
        if (isHol(log.lunch_in) && isHol(log.time_out)) {
            hours += officialAfternoonMinutes / 60;
        } else if (hasLunchIn && hasTimeOut && !isHol(log.lunch_in) && !isHol(log.time_out)) {
            const li = parseTime(log.lunch_in);
            const to = parseTime(log.time_out);
            if (li !== null && to !== null) {
                hours += (to - li) / 60;
                if (officialLunchInMin !== null && li > officialLunchInMin) lateMinutes += (li - officialLunchInMin);
                if (officialOutMin !== null && to < officialOutMin) undertimeMinutes += (officialOutMin - to);
            }
        } else {
            hours += officialAfternoonMinutes / 60;
        }
    } else {
        // AM holiday: morning is holiday credit; afternoon is work
        if (isHol(log.time_in) && isHol(log.lunch_out)) {
            hours += officialMorningMinutes / 60;
        } else if (hasTimeIn && hasLunchOut && !isHol(log.time_in) && !isHol(log.lunch_out)) {
            const aIn = parseTime(log.time_in);
            const aLo = parseTime(log.lunch_out);
            if (aIn !== null && aLo !== null) {
                hours += (aLo - aIn) / 60;
                if (officialInMin !== null && aIn > officialInMin) lateMinutes += (aIn - officialInMin);
                if (officialLunchOutMin !== null && aLo < officialLunchOutMin) undertimeMinutes += (officialLunchOutMin - aLo);
            }
        } else {
            hours += officialMorningMinutes / 60;
        }
        if (hasLunchIn && hasTimeOut && !isHol(log.lunch_in) && !isHol(log.time_out)) {
            const li = parseTime(log.lunch_in);
            const to = parseTime(log.time_out);
            if (li !== null && to !== null) {
                hours += (to - li) / 60;
                if (officialLunchInMin !== null && li > officialLunchInMin) lateMinutes += (li - officialLunchInMin);
                if (officialOutMin !== null && to < officialOutMin) undertimeMinutes += (officialOutMin - to);
            }
        } else {
            undertimeMinutes += officialAfternoonMinutes;
        }
    }

    const tardinessUndertimeHours = (lateMinutes + undertimeMinutes) / 60;
    return {
        hours: hours,
        lateMinutes: lateMinutes,
        undertimeMinutes: undertimeMinutes,
        overtimeMinutes: 0,
        otIn: null,
        otOut: null,
        statusBadge: holidayStatusBadge,
        hasOfficialTime: true,
        absentHours: 0,
        absentPeriod: '',
        tardinessUndertimeHours: tardinessUndertimeHours
    };
}

// Calculate hours from log entry
async function calculateLogHours(log, officialTimesData = null) {
    let hours = 0;
    let lateMinutes = 0;
    let undertimeMinutes = 0;
    let overtimeMinutes = 0;
    let otIn = null;
    let otOut = null;
    let absentHours = 0;
    let absentPeriod = ''; // 'morning', 'afternoon', 'full', or ''
    let statusBadge = '<span class="badge bg-secondary">Incomplete</span>';
    let hasOfficialTime = false;

    // Holiday with no actual attendance: credit full official day when official times exist (half-day holiday does not reduce credited hours)
    const remarksHoliday = (log.remarks && String(log.remarks).trim()) || '';
    const isHalfDayHoliday = Number(log.holiday_is_half_day) === 1
        || remarksHoliday.indexOf('Holiday (Half-day') === 0;
    let halfDayPeriod = 'morning';
    if (Number(log.holiday_is_half_day) === 1) {
        halfDayPeriod = (log.holiday_half_day_period || 'morning') === 'afternoon' ? 'afternoon' : 'morning';
    } else if (remarksHoliday.indexOf('Holiday (Half-day PM):') === 0) {
        halfDayPeriod = 'afternoon';
    }
    const holidayStatusBadge = isHalfDayHoliday
        ? '<span class="badge bg-warning text-dark">' + (halfDayPeriod === 'afternoon' ? 'Half-day PM' : 'Half-day AM') + '</span>'
        : '<span class="badge bg-secondary">Holiday</span>';

    // Half-day holiday: segment credit + work half tardiness/undertime (not full-day hours)
    if (log.is_holiday && isHalfDayHoliday) {
        if (!officialTimesData || !officialTimesData.times) {
            return {
                hours: 0,
                lateMinutes: 0,
                undertimeMinutes: 0,
                overtimeMinutes: 0,
                otIn: null,
                otOut: null,
                statusBadge: holidayStatusBadge,
                hasOfficialTime: false,
                absentHours: 0,
                absentPeriod: '',
                tardinessUndertimeHours: 0
            };
        }
        return calculateHalfDayHolidayHours(log, officialTimesData, holidayStatusBadge, halfDayPeriod);
    }

    // Full-day holiday only (no actual punches): credit full official base
    if (log.is_holiday && !log.has_holiday_attendance) {
        if (!officialTimesData || !officialTimesData.found || !officialTimesData.times) {
            return {
                hours: 0,
                lateMinutes: 0,
                undertimeMinutes: 0,
                overtimeMinutes: 0,
                otIn: null,
                otOut: null,
                statusBadge: holidayStatusBadge,
                hasOfficialTime: false,
                absentHours: 0,
                absentPeriod: '',
                tardinessUndertimeHours: 0
            };
        }
        const official = officialTimesData.times;
        const officialInMin = parseTime(official.time_in);
        const officialOutMin = parseTime(official.time_out);
        if (officialInMin !== null && officialOutMin !== null) {
            const officialHasLunch = !!(official.lunch_out && official.lunch_in);
            let officialBaseHours;
            if (officialHasLunch && official.lunch_out && official.lunch_in) {
                const officialLunchOutMin = parseTime(official.lunch_out);
                const officialLunchInMin = parseTime(official.lunch_in);
                if (officialLunchOutMin !== null && officialLunchInMin !== null) {
                    officialBaseHours = (officialLunchOutMin - officialInMin) / 60 + (officialOutMin - officialLunchInMin) / 60;
                } else {
                    officialBaseHours = (officialOutMin - officialInMin) / 60;
                }
            } else {
                officialBaseHours = (officialOutMin - officialInMin) / 60;
            }
            return {
                hours: officialBaseHours,
                lateMinutes: 0,
                undertimeMinutes: 0,
                overtimeMinutes: 0,
                otIn: null,
                otOut: null,
                statusBadge: holidayStatusBadge,
                hasOfficialTime: false,
                absentHours: 0,
                absentPeriod: '',
                tardinessUndertimeHours: 0
            };
        }
        return {
            hours: 0,
            lateMinutes: 0,
            undertimeMinutes: 0,
            overtimeMinutes: 0,
            otIn: null,
            otOut: null,
            statusBadge: holidayStatusBadge,
            hasOfficialTime: false,
            absentHours: 0,
            absentPeriod: '',
            tardinessUndertimeHours: 0
        };
    }
    
    // Approved leave pardon: API shows LEAVE in time cells; credit rendered hours from official schedule (no tardiness/undertime)
    const remarksUpper = (log.remarks && String(log.remarks).trim().toUpperCase()) || '';
    const isLeaveRow = remarksUpper === 'LEAVE' || ['time_in', 'lunch_out', 'lunch_in', 'time_out'].some(function (k) {
        const v = log[k];
        return v && String(v).trim().toUpperCase() === 'LEAVE';
    });
    if (isLeaveRow) {
        if (!officialTimesData || !officialTimesData.times) {
            return {
                hours: 0,
                lateMinutes: 0,
                undertimeMinutes: 0,
                overtimeMinutes: 0,
                otIn: null,
                otOut: null,
                statusBadge: '<span class="badge bg-danger">LEAVE</span>',
                hasOfficialTime: false,
                absentHours: 0,
                absentPeriod: '',
                tardinessUndertimeHours: 0
            };
        }
        const official = officialTimesData.times;
        const officialInMin = parseTime(official.time_in);
        const officialOutMin = parseTime(official.time_out);
        if (officialInMin !== null && officialOutMin !== null) {
            const officialHasLunch = !!(official.lunch_out && official.lunch_in);
            let officialBaseHours;
            if (officialHasLunch && official.lunch_out && official.lunch_in) {
                const officialLunchOutMin = parseTime(official.lunch_out);
                const officialLunchInMin = parseTime(official.lunch_in);
                if (officialLunchOutMin !== null && officialLunchInMin !== null) {
                    officialBaseHours = (officialLunchOutMin - officialInMin) / 60 + (officialOutMin - officialLunchInMin) / 60;
                } else {
                    officialBaseHours = (officialOutMin - officialInMin) / 60;
                }
            } else {
                officialBaseHours = (officialOutMin - officialInMin) / 60;
            }
            return {
                hours: officialBaseHours,
                lateMinutes: 0,
                undertimeMinutes: 0,
                overtimeMinutes: 0,
                otIn: null,
                otOut: null,
                statusBadge: '<span class="badge bg-danger">LEAVE</span>',
                hasOfficialTime: true,
                absentHours: 0,
                absentPeriod: '',
                tardinessUndertimeHours: 0
            };
        }
        return {
            hours: 0,
            lateMinutes: 0,
            undertimeMinutes: 0,
            overtimeMinutes: 0,
            otIn: null,
            otOut: null,
            statusBadge: '<span class="badge bg-danger">LEAVE</span>',
            hasOfficialTime: false,
            absentHours: 0,
            absentPeriod: '',
            tardinessUndertimeHours: 0
        };
    }
    
    // Approved TARF: no official schedule for that day — credit hours only (no clock cells); not absent
    const remarksForTarfCredit = (log.remarks && String(log.remarks).trim()) || '';
    const tarfCreditMatch = remarksForTarfCredit.match(/TARF_HOURS_CREDIT:([\d.]+)/);
    if (tarfCreditMatch && (log.tarf_id || remarksForTarfCredit.indexOf('TARF:') === 0)) {
        const creditH = parseFloat(tarfCreditMatch[1], 10);
        const h = !isNaN(creditH) && creditH > 0 ? creditH : 8;
        return {
            hours: h,
            lateMinutes: 0,
            undertimeMinutes: 0,
            overtimeMinutes: 0,
            otIn: null,
            otOut: null,
            statusBadge: '<span class="badge bg-info text-dark">TRAVEL</span>',
            hasOfficialTime: false,
            absentHours: 0,
            absentPeriod: '',
            tardinessUndertimeHours: 0
        };
    }
    
    // Check if official time exists (from DB) OR we have default times to use for calculation.
    // When no employee_official_times record exists, use DEFAULT_OFFICIAL_TIMES (08:00-12:00, 13:00-17:00)
    // so that tardiness and undertime are computed for all employees, not just those with custom schedules.
    if (officialTimesData && officialTimesData.times) {
        hasOfficialTime = true;
    }
    
    // Check if log entries are actually logged (not empty and not 00:00)
    const hasTimeIn = isTimeLogged(log.time_in);
    const hasLunchOut = isTimeLogged(log.lunch_out);
    const hasLunchIn = isTimeLogged(log.lunch_in);
    const hasTimeOut = isTimeLogged(log.time_out);
    
    // Official time may have no lunch (half-day workers) - single shift: time_in to time_out
    // When no official time found, we use DEFAULT_OFFICIAL_TIMES which has lunch
    const officialHasLunch = (officialTimesData && officialTimesData.hasLunch === true) || (officialTimesData && officialTimesData.found === false);
    const morningShiftComplete = officialHasLunch ? (hasTimeIn && hasLunchOut) : (hasTimeIn && hasTimeOut);
    const afternoonShiftComplete = officialHasLunch ? (hasLunchIn && hasTimeOut) : false;
    const halfDayComplete = !officialHasLunch && hasTimeIn && hasTimeOut;
    
    // Employee is absent if they did not log in for a shift
    // Full-day: morning absent = no time_in, afternoon absent = no lunch_in
    // Half-day: absent = no time_in (single shift)
    const morningShiftAbsent = !hasTimeIn;
    const afternoonShiftAbsent = officialHasLunch ? !hasLunchIn : false;
    // Is absent if either shift is absent
    const isAbsent = morningShiftAbsent || afternoonShiftAbsent;
    
    // Determine which part of the day is absent
    if (isAbsent) {
        if (morningShiftAbsent && afternoonShiftAbsent) {
            absentPeriod = 'full';
        } else if (morningShiftAbsent) {
            absentPeriod = 'morning';
        } else if (afternoonShiftAbsent) {
            absentPeriod = 'afternoon';
        }
    }
    
    // Calculate absent hours first (even if no shifts are complete)
    // This ensures absent hours are calculated for full-day absences
    if (isAbsent) {
        if (hasOfficialTime) {
            const official = officialTimesData.times;
            const officialInMinutes = parseTime(official.time_in);
            const officialOutMinutes = parseTime(official.time_out);
            const officialLunchOutMinutes = parseTime(official.lunch_out);
            const officialLunchInMinutes = parseTime(official.lunch_in);
            
            if (officialHasLunch && officialInMinutes !== null && officialOutMinutes !== null &&
                officialLunchOutMinutes !== null && officialLunchInMinutes !== null) {
                // Full-day schedule: calculate expected hours with lunch break
                const lunchBreakMinutes = officialLunchInMinutes - officialLunchOutMinutes;
                const expectedTotalMinutes = (officialOutMinutes - officialInMinutes) - lunchBreakMinutes;
                const officialMorningMinutes = officialLunchOutMinutes - officialInMinutes;
                const officialAfternoonMinutes = officialOutMinutes - officialLunchInMinutes;
                if (morningShiftAbsent && afternoonShiftAbsent) absentHours = expectedTotalMinutes / 60;
                else if (morningShiftAbsent) absentHours = officialMorningMinutes / 60;
                else if (afternoonShiftAbsent) absentHours = officialAfternoonMinutes / 60;
            } else if (!officialHasLunch && officialInMinutes !== null && officialOutMinutes !== null) {
                // Half-day schedule: single shift, no lunch
                const expectedMinutes = officialOutMinutes - officialInMinutes;
                if (morningShiftAbsent && afternoonShiftAbsent) absentHours = expectedMinutes / 60;
            }
        }
        // When no official time: absent hours stay 0 (absent hours must be based on official time)
        
        // DTR Business Rule: Morning absent → combine with LATE; Afternoon absent → combine with UNDERTIME
        // Whole day absent stays in ABSENT column only
        if (absentPeriod === 'morning') {
            lateMinutes += absentHours * 60; // Treat morning absent as late
            absentHours = 0; // Don't show in Absent column
        } else if (absentPeriod === 'afternoon') {
            undertimeMinutes += absentHours * 60; // Treat afternoon absent as undertime
            absentHours = 0; // Don't show in Absent column
        }
        // absentPeriod === 'full': keep absentHours for Absent column
    }
    
    // Missing time_in or lunch_out → Tardiness for the whole morning (only when official time has lunch)
    if (officialHasLunch && hasOfficialTime && officialTimesData && officialTimesData.times && hasTimeIn && !hasLunchOut) {
        const official = officialTimesData.times;
        const officialInMinutes = parseTime(official.time_in);
        const officialLunchOutMinutes = parseTime(official.lunch_out);
        if (officialInMinutes !== null && officialLunchOutMinutes !== null) {
            lateMinutes += (officialLunchOutMinutes - officialInMinutes);
        }
    }
    
    // Calculate hours only for complete shifts
    // Morning shift requires: time_in AND lunch_out
    // Afternoon shift requires: lunch_in AND time_out
    if (morningShiftComplete || afternoonShiftComplete) {
        try {
            let morningMinutes = 0;
            let afternoonMinutes = 0;
            
            // Calculate morning shift hours: full-day = time_in to lunch_out; half-day = time_in to time_out
            if (morningShiftComplete) {
                const actualInMinutes = parseTime(log.time_in);
                if (officialHasLunch) {
                    const actualLunchOutMinutes = parseTime(log.lunch_out);
                    if (actualInMinutes !== null && actualLunchOutMinutes !== null) {
                        morningMinutes = Math.max(0, actualLunchOutMinutes - actualInMinutes);
                    }
                } else {
                    const actualOutMinutes = parseTime(log.time_out);
                    if (actualInMinutes !== null && actualOutMinutes !== null) {
                        morningMinutes = Math.max(0, actualOutMinutes - actualInMinutes);
                    }
                }
            }
            
            // Calculate afternoon shift hours only if complete (full-day: lunch_in AND time_out)
            if (afternoonShiftComplete) {
                const actualLunchInMinutes = parseTime(log.lunch_in);
                const actualOutMinutes = parseTime(log.time_out);
                if (actualLunchInMinutes !== null && actualOutMinutes !== null) {
                    afternoonMinutes = Math.max(0, actualOutMinutes - actualLunchInMinutes);
                }
            }
            
            // Total hours = morning + afternoon (only from complete shifts)
            hours = (morningMinutes + afternoonMinutes) / 60;
            
            // Get actualOutMinutes for late/undertime calculations
            const actualOutMinutes = hasTimeOut ? parseTime(log.time_out) : null;
            
            // Only calculate late/undertime if we have official times
            if (hasOfficialTime) {
                const official = officialTimesData.times;
                const officialInMinutes = parseTime(official.time_in);
                const officialOutMinutes = parseTime(official.time_out);
                const officialLunchOutMinutes = parseTime(official.lunch_out);
                const officialLunchInMinutes = parseTime(official.lunch_in);
                
                if (officialHasLunch && officialInMinutes !== null && officialOutMinutes !== null &&
                    officialLunchOutMinutes !== null && officialLunchInMinutes !== null) {
                    // Full-day schedule: late/undertime with lunch
                    if (morningShiftComplete) {
                        const actualInMinutes = parseTime(log.time_in);
                        if (actualInMinutes !== null && actualInMinutes > officialInMinutes) {
                            lateMinutes = actualInMinutes - officialInMinutes;
                        }
                    }
                    if (afternoonShiftComplete) {
                        const actualLunchInMinutes = parseTime(log.lunch_in);
                        if (actualLunchInMinutes !== null && actualLunchInMinutes > officialLunchInMinutes) {
                            lateMinutes += (actualLunchInMinutes - officialLunchInMinutes);
                        }
                    }
                    if (morningShiftComplete) {
                        const actualLunchOutMinutes = parseTime(log.lunch_out);
                        if (actualLunchOutMinutes !== null && actualLunchOutMinutes < officialLunchOutMinutes) {
                            undertimeMinutes += (officialLunchOutMinutes - actualLunchOutMinutes);
                        }
                    }
                    if (afternoonShiftComplete && actualOutMinutes !== null && actualOutMinutes < officialOutMinutes) {
                        undertimeMinutes += (officialOutMinutes - actualOutMinutes);
                    }
                    if (morningShiftComplete && hasLunchIn && !hasTimeOut) {
                        undertimeMinutes += (officialOutMinutes - officialLunchInMinutes);
                    }
                } else if (!officialHasLunch && officialInMinutes !== null && officialOutMinutes !== null) {
                    // Half-day schedule: late from time_in, undertime from time_out
                    if (halfDayComplete) {
                        const actualInMinutes = parseTime(log.time_in);
                        const actualOutMinutes = parseTime(log.time_out);
                        if (actualInMinutes !== null && actualInMinutes > officialInMinutes) {
                            lateMinutes = actualInMinutes - officialInMinutes;
                        }
                        if (actualOutMinutes !== null && actualOutMinutes < officialOutMinutes) {
                            undertimeMinutes = officialOutMinutes - actualOutMinutes;
                        }
                    }
                }
            }
            
            // Overtime should ONLY be calculated from explicit OT in and OT out fields
            // Not from overstaying beyond official time_out (can be calculated even without official times)
            if (log.ot_in && log.ot_out) {
                const otInMinutes = parseTime(log.ot_in);
                const otOutMinutes = parseTime(log.ot_out);
                
                if (otInMinutes !== null && otOutMinutes !== null && otOutMinutes > otInMinutes) {
                    // Calculate overtime from OT in to OT out
                    overtimeMinutes = otOutMinutes - otInMinutes;
                    otIn = log.ot_in;
                    otOut = log.ot_out;
                }
            }
            
            // When no OT is logged, cap hours at official base time so DTR shows only base
            if (hasOfficialTime && overtimeMinutes === 0 && hours > 0 && officialTimesData && officialTimesData.times) {
                const official = officialTimesData.times;
                const officialInMin = parseTime(official.time_in);
                const officialOutMin = parseTime(official.time_out);
                if (officialInMin !== null && officialOutMin !== null) {
                    let officialBaseHours;
                    if (officialHasLunch && official.lunch_out && official.lunch_in) {
                        const officialLunchOutMin = parseTime(official.lunch_out);
                        const officialLunchInMin = parseTime(official.lunch_in);
                        if (officialLunchOutMin !== null && officialLunchInMin !== null) {
                            officialBaseHours = (officialLunchOutMin - officialInMin) / 60 + (officialOutMin - officialLunchInMin) / 60;
                        } else {
                            officialBaseHours = (officialOutMin - officialInMin) / 60;
                        }
                    } else {
                        officialBaseHours = (officialOutMin - officialInMin) / 60;
                    }
                    if (hours > officialBaseHours) {
                        hours = officialBaseHours;
                    }
                }
            }
            
            // Determine status badge
            // Full-day: both shifts complete; Half-day: time_in and time_out (no lunch)
            const isComplete = officialHasLunch ? (morningShiftComplete && afternoonShiftComplete) : halfDayComplete;
            
            if (hasOfficialTime) {
                const lateHours = lateMinutes / 60;
                const undertimeHours = undertimeMinutes / 60;
                const overtimeHours = overtimeMinutes / 60;
                
                if (!isComplete && undertimeHours > 0) {
                    // Incomplete (e.g. missing time_out) but has undertime → show Undertime
                    statusBadge = lateHours > 0 ? '<span class="badge bg-warning">Late & Undertime</span>' : '<span class="badge bg-warning">Undertime</span>';
                } else if (!isComplete && lateHours > 0) {
                    // Incomplete (e.g. missing time_in or lunch_out) but has tardiness → show Late
                    statusBadge = '<span class="badge bg-danger">Late</span>';
                } else if (!isComplete) {
                    // Incomplete log - show incomplete status even with official time
                    statusBadge = '<span class="badge bg-secondary">Incomplete</span>';
                } else if (lateHours > 0 && undertimeHours > 0) {
                    statusBadge = '<span class="badge bg-warning">Late & Undertime</span>';
                } else if (lateHours > 0) {
                    statusBadge = '<span class="badge bg-danger">Late</span>';
                } else if (undertimeHours > 0) {
                    statusBadge = '<span class="badge bg-warning">Undertime</span>';
                } else if (overtimeHours > 0) {
                    statusBadge = '<span class="badge bg-success">Overtime</span>';
                } else {
                    statusBadge = '<span class="badge bg-success">Complete</span>';
                }
            } else if (hours > 0) {
                // If we have hours but no official time, show as incomplete
                statusBadge = '<span class="badge bg-secondary">Incomplete</span>';
            }
        } catch (e) {
            console.error('Error calculating hours for log:', log, e);
        }
    }
    
    // Full-day absent with official time: show Absent status (not Incomplete)
    if (hasOfficialTime && isAbsent && absentPeriod === 'full') {
        statusBadge = '<span class="badge bg-danger">Absent</span>';
    }
    
    // Calculate combined tardiness and undertime hours
    const tardinessUndertimeHours = (lateMinutes + undertimeMinutes) / 60;
    
    return { hours, lateMinutes, undertimeMinutes, overtimeMinutes, otIn, otOut, statusBadge, hasOfficialTime, absentHours, absentPeriod, tardinessUndertimeHours };
}

// Store current employee data for DTR printing
window.currentLogsEmployeeId = '';
window.currentLogsEmployeeName = '';
window.currentLogsPardonOpenDates = [];

function buildPardonCell(log, empId) {
    if (log.pardon_open) {
        return '<span class="badge bg-success">Opened</span> <button type="button" class="btn btn-sm btn-outline-secondary close-pardon-btn" data-date="' + (log.log_date || '') + '" data-emp-id="' + (empId || '') + '" title="Undo accidental open">Close</button>';
    }
    // Don't show Open for holiday credit rows (auto-credited; includes half-day holiday)
    if (log.is_holiday && !log.has_holiday_attendance) return '<span class="text-muted">—</span>';
    return '<button type="button" class="btn btn-sm btn-outline-primary open-pardon-btn" data-date="' + (log.log_date || '') + '" data-emp-id="' + (empId || '') + '" title="Allow staff to submit pardon for this date">Open</button>';
}

function bindOpenPardonClicks() {
    if (window.openPardonClicksBound) return;
    window.openPardonClicksBound = true;
    document.addEventListener('click', function(e) {
        const openBtn = e.target.closest('.open-pardon-btn');
        if (openBtn && !openBtn.disabled) {
            const dateKey = openBtn.getAttribute('data-date');
            const empId = openBtn.getAttribute('data-emp-id');
            if (dateKey && empId) {
                openBtn.disabled = true;
                const formData = new FormData();
                formData.append('employee_id', empId);
                formData.append('log_date', dateKey);
                fetch('open_pardon_api.php', { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(function(res) {
                        if (res.success) {
                            if (typeof showToast === 'function') showToast(res.message, 'success');
                            else alert(res.message);
                            openBtn.outerHTML = '<span class="badge bg-success">Opened</span> <button type="button" class="btn btn-sm btn-outline-secondary close-pardon-btn" data-date="' + dateKey + '" data-emp-id="' + empId + '" title="Undo accidental open">Close</button>';
                            if (res.pardon_open_dates) window.currentLogsPardonOpenDates = res.pardon_open_dates;
                        } else {
                            if (typeof showToast === 'function') showToast(res.message || 'Failed to open pardon', 'error');
                            else alert(res.message || 'Failed to open pardon');
                            openBtn.disabled = false;
                        }
                    })
                    .catch(function(err) {
                        console.error('Open pardon error:', err);
                        if (typeof showToast === 'function') showToast('Error opening pardon. Please try again.', 'error');
                        else alert('Error opening pardon. Please try again.');
                        openBtn.disabled = false;
                    });
                return;
            }
        }
        const closeBtn = e.target.closest('.close-pardon-btn');
        if (closeBtn && !closeBtn.disabled) {
            const dateKey = closeBtn.getAttribute('data-date');
            const empId = closeBtn.getAttribute('data-emp-id');
            if (dateKey && empId) {
                closeBtn.disabled = true;
                const formData = new FormData();
                formData.append('employee_id', empId);
                formData.append('log_date', dateKey);
                fetch('close_pardon_api.php', { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(function(res) {
                        if (res.success) {
                            if (typeof showToast === 'function') showToast(res.message, 'success');
                            else alert(res.message);
                            var cell = closeBtn.closest('td');
                            if (cell) cell.innerHTML = '<button type="button" class="btn btn-sm btn-outline-primary open-pardon-btn" data-date="' + dateKey + '" data-emp-id="' + empId + '" title="Allow staff to submit pardon for this date">Open</button>';
                            if (res.pardon_open_dates) window.currentLogsPardonOpenDates = res.pardon_open_dates;
                        } else {
                            if (typeof showToast === 'function') showToast(res.message || 'Failed to close pardon', 'error');
                            else alert(res.message || 'Failed to close pardon');
                            closeBtn.disabled = false;
                        }
                    })
                    .catch(function(err) {
                        console.error('Close pardon error:', err);
                        if (typeof showToast === 'function') showToast('Error closing pardon. Please try again.', 'error');
                        else alert('Error closing pardon. Please try again.');
                        closeBtn.disabled = false;
                    });
            }
        }
    });
}

async function viewLogs(empId, empName) {
    currentEmployeeId = empId;
    window.currentLogsEmployeeId = empId;
    window.currentLogsEmployeeName = empName;
    // Also set currentOfficialTimesEmpId so TIME tab works when switching tabs
    window.currentOfficialTimesEmpId = empId;
    
    // Update modal title and employee name
    const modalTitle = document.getElementById('employeeManagementModalTitle');
    const modalEmployeeName = document.getElementById('employeeManagementEmployeeName');
    if (modalTitle) modalTitle.textContent = 'Employee Management - ' + empName;
    if (modalEmployeeName) modalEmployeeName.textContent = 'Safe Employee ID: ' + empId;
    
    // Reset date filters
    const filterDateFrom = document.getElementById('filterDateFrom');
    const filterDateTo = document.getElementById('filterDateTo');
    if (filterDateFrom) filterDateFrom.value = '';
    if (filterDateTo) filterDateTo.value = '';
    
    // Show loading state
    const tbody = document.getElementById('logsTableBody');
    tbody.innerHTML = '<tr><td colspan="18" class="text-center text-muted py-4">Loading attendance logs...</td></tr>';
    const logsCount = document.getElementById('logsCount');
    if (logsCount) logsCount.textContent = 'Loading...';
    
    // Disable print button initially (only if it exists)
    const printDTRBtn = document.getElementById('printDTRBtn');
    const printDTRBtnFooter = document.getElementById('printDTRBtnFooter');
    if (printDTRBtn) printDTRBtn.disabled = true;
    if (printDTRBtnFooter) printDTRBtnFooter.disabled = true;
    
    // Fetch logs from API
    await loadLogsWithFilters(empId);
    
    // Show DTR tab and hide TIME tab
    const dtrTab = document.getElementById('dtr-tab');
    const timeTab = document.getElementById('time-tab');
    const dtrPane = document.getElementById('dtr-pane');
    const timePane = document.getElementById('time-pane');
    const dtrFooter = document.getElementById('dtr-footer-buttons');
    const timeFooter = document.getElementById('time-footer-buttons');
    
    if (dtrTab && timeTab && dtrPane && timePane) {
        // Activate DTR tab
        dtrTab.classList.add('active');
        timeTab.classList.remove('active');
        dtrPane.classList.add('show', 'active');
        timePane.classList.remove('show', 'active');
    }
    
    // Show DTR footer buttons, hide TIME footer buttons
    if (dtrFooter) dtrFooter.style.display = '';
    if (timeFooter) timeFooter.style.display = 'none';
    
    // Open modal
    const modal = new bootstrap.Modal(document.getElementById('employeeManagementModal'));
    modal.show();
}

async function loadLogsWithFilters(empId) {
    const filterDateFrom = document.getElementById('filterDateFrom');
    const filterDateTo = document.getElementById('filterDateTo');
    const dateFrom = filterDateFrom ? filterDateFrom.value : '';
    const dateTo = filterDateTo ? filterDateTo.value : '';
    
    let url = 'fetch_logs_api.php?employee_id=' + encodeURIComponent(empId) + '&simple=1';
    if (dateFrom) {
        url += '&date_from=' + encodeURIComponent(dateFrom);
    }
    if (dateTo) {
        url += '&date_to=' + encodeURIComponent(dateTo);
    }
    
    const tbody = document.getElementById('logsTableBody');
    tbody.innerHTML = '<tr><td colspan="18" class="text-center text-muted py-4">Loading attendance logs...</td></tr>';
    const logsCount = document.getElementById('logsCount');
    if (logsCount) logsCount.textContent = 'Loading...';
    
    fetch(url, {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    })
        .then(response => {
            // Check if response is ok
            if (!response.ok) {
                // If 401 or 403, try to parse as JSON first (might be auth error)
                if (response.status === 401 || response.status === 403) {
                    return response.text().then(text => {
                        try {
                            const jsonData = JSON.parse(text);
                            if (jsonData.success === false) {
                                // It's a JSON error response, handle it
                                throw new Error(jsonData.message || 'Unauthorized');
                            }
                        } catch (e) {
                            // Not JSON, treat as HTML redirect
                            throw new Error('Session expired. Please refresh the page and log in again.');
                        }
                        throw new Error('Unauthorized');
                    });
                }
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            // Check if response is JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Non-JSON response:', text);
                    throw new Error('Server returned non-JSON response');
                });
            }
            return response.json();
        })
        .then(async data => {
            console.log('Logs API response:', data);
            tbody.innerHTML = '';
            
            // Show Pardon column for super_admin when viewing staff (super_admin opens pardon for staff)
            const showPardonColumn = (typeof window.isSuperAdmin !== 'undefined' && window.isSuperAdmin) && 
                (data.employee_user_type === 'staff');
            const pardonHeader = document.getElementById('pardonColumnHeader');
            if (pardonHeader) pardonHeader.style.display = showPardonColumn ? 'table-cell' : 'none';
            
            // Check if API returned an error
            if (!data.success) {
                const errorMsg = data.message || 'Unknown error occurred';
                tbody.innerHTML = `<tr><td colspan="19" class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle me-2"></i>Error: ${errorMsg}</td></tr>`;
                document.getElementById('logsCount').textContent = 'Error loading logs';
                document.getElementById('logsCount').className = 'badge bg-danger';
                return;
            }
            
            if (data.logs && data.logs.length > 0) {
                let counter = 1;
                let totalHours = 0;
                let totalLate = 0;
                let totalUndertime = 0;
                let totalOvertime = 0;
                let totalOTMinutes = 0;
                let totalAbsentHours = 0;
                let totalTardinessUndertimeHours = 0;
                
                // Process logs with employee-specific official times
                for (const log of data.logs) {
                    const remarksHalfDay = ((log.remarks || '').trim().indexOf('Holiday (Half-day') === 0);
                    const isHalfDayHolidayRow = Number(log.holiday_is_half_day) === 1 || remarksHalfDay;
                    const row = document.createElement('tr');
                    // Worked on a holiday: red only, no "holiday row" styling / no HOLIDAY badge
                    if (log.has_holiday_attendance) {
                        row.classList.add('dtr-row-holiday-attendance');
                    } else if (log.is_holiday) {
                        if (isHalfDayHolidayRow) {
                            row.classList.add('dtr-row-half-day-holiday');
                        } else {
                            row.classList.add('dtr-row-holiday');
                        }
                    }
                    const logDate = log.log_date ? new Date(log.log_date + 'T00:00:00').toLocaleDateString('en-US', { 
                        year: 'numeric', 
                        month: 'short', 
                        day: 'numeric',
                        weekday: 'short'
                    }) : '-';
                    
                    row.setAttribute('data-log-date', log.log_date || '');
                    
                    // Get official times for this log date
                    const officialTimesData = await getOfficialTimesForDate(empId, log.log_date);
                    const calc = await calculateLogHours(log, officialTimesData);
                    
                    // Always add hours to totals (even without official time)
                    totalHours += calc.hours;
                    
                    // Add absent hours to totals
                    totalAbsentHours += calc.absentHours || 0;
                    
                    // Add tardiness and undertime hours to totals
                    totalTardinessUndertimeHours += calc.tardinessUndertimeHours || 0;
                    
                    // Add late/undertime to totals (includes morning absent→LATE, afternoon absent→UNDERTIME)
                    if (calc.lateMinutes > 0) {
                        totalLate += calc.lateMinutes / 60; // Convert minutes to hours for totals
                    }
                    if (calc.undertimeMinutes > 0) {
                        totalUndertime += calc.undertimeMinutes / 60; // Convert minutes to hours for totals
                    }
                    if (calc.hasOfficialTime && calc.overtimeMinutes > 0) {
                        totalOvertime += calc.overtimeMinutes / 60;
                    }
                    
                    // Overtime can be calculated from OT fields even without official time
                    if (calc.overtimeMinutes > 0 && !calc.hasOfficialTime) {
                        totalOvertime += calc.overtimeMinutes / 60; // Convert minutes to hours for totals
                    }
                    
                    // Format late and undertime in hh:mm:ss (includes morning absent→LATE, afternoon absent→UNDERTIME)
                    const lateTimeFormat = calc.lateMinutes > 0 ? minutesToTimeFormat(calc.lateMinutes) : '00:00:00';
                    const undertimeTimeFormat = calc.undertimeMinutes > 0 ? minutesToTimeFormat(calc.undertimeMinutes) : '00:00:00';
                    
                    // Use TOTAL OT from database, or calculate if not available
                    let totalOtFormat = '00:00:00';
                    let otMinutesForTotal = 0;
                    
                    if (log.total_ot) {
                        // Use total_ot from database
                        totalOtFormat = log.total_ot;
                        // Parse total_ot (HH:MM:SS) to minutes for total calculation
                        const otParts = log.total_ot.split(':');
                        if (otParts.length >= 2) {
                            const hours = parseInt(otParts[0], 10) || 0;
                            const minutes = parseInt(otParts[1], 10) || 0;
                            const seconds = parseInt(otParts[2], 10) || 0;
                            otMinutesForTotal = hours * 60 + minutes + (seconds / 60);
                        }
                    } else if (log.ot_in && log.ot_out) {
                        // Calculate OT from ot_in and ot_out if total_ot not available
                        const otInMinutes = parseTime(log.ot_in);
                        const otOutMinutes = parseTime(log.ot_out);
                        if (otInMinutes !== null && otOutMinutes !== null && otOutMinutes > otInMinutes) {
                            otMinutesForTotal = otOutMinutes - otInMinutes;
                            totalOtFormat = minutesToTimeFormat(otMinutesForTotal);
                        }
                    } else if (calc.hasOfficialTime && calc.overtimeMinutes > 0) {
                        // Use calculated overtime as fallback
                        otMinutesForTotal = calc.overtimeMinutes;
                        totalOtFormat = minutesToTimeFormat(calc.overtimeMinutes);
                    }
                    
                    // Add to total OT
                    totalOTMinutes += otMinutesForTotal;
                    
                    // Format absent hours (whole day only; morning→LATE, afternoon→UNDERTIME)
                    // Include day info when showing full-day absent
                    let absentHoursFormat = '<span class="text-muted">-</span>';
                    if (calc.absentHours > 0 && calc.absentPeriod === 'full') {
                        const hoursText = calc.absentHours.toFixed(2);
                        const absentDaysStr = hoursToDayFraction(calc.absentHours).toFixed(3);
                        const dayInfo = logDate !== '-' ? ' <small class="text-muted">(' + logDate + ')</small>' : ' <small class="text-muted">(Full Day)</small>';
                        absentHoursFormat = hoursText + ' h <small class="text-muted">(' + absentDaysStr + ' d)</small>' + dayInfo;
                    }
                    const tardinessUndertimeFormat = calc.tardinessUndertimeHours > 0 ? minutesToTimeFormat(calc.tardinessUndertimeHours * 60) : '<span class="text-muted">-</span>';
                    
                    // Calculate day conversions
                    const hoursInDays = calc.hours > 0 ? hoursToDayFraction(calc.hours) : 0;
                    const tardinessAbsentLateHours = (calc.absentHours || 0) + (calc.tardinessUndertimeHours || 0);
                    const tardinessAbsentLateInDays = tardinessAbsentLateHours > 0 ? hoursToDayFraction(tardinessAbsentLateHours) : 0;
                    
                    let halfDayLabel = 'Half-day AM';
                    if (isHalfDayHolidayRow) {
                        if (Number(log.holiday_is_half_day) === 1) {
                            halfDayLabel = (log.holiday_half_day_period || 'morning') === 'afternoon' ? 'Half-day PM' : 'Half-day AM';
                        } else if ((log.remarks || '').trim().indexOf('Holiday (Half-day PM):') === 0) {
                            halfDayLabel = 'Half-day PM';
                        }
                    }
                    const dayCellText = (log.is_holiday && !log.has_holiday_attendance)
                        ? (isHalfDayHolidayRow
                            ? `${logDate} <span class="badge bg-warning text-dark ms-1">${halfDayLabel}</span>`
                            : `${logDate} <span class="badge bg-danger ms-1">Holiday</span>`)
                        : logDate;
                    const remarksTrim = String(log.remarks || '').trim();
                    const showTarfInTimeCells = (remarksTrim.indexOf('TARF_HOURS_CREDIT:') !== -1
                            && (log.tarf_id || remarksTrim.indexOf('TARF:') === 0))
                        || (Number(log.tarf_id) > 0 && remarksTrim.indexOf('TARF:') === 0);
                    const tarfStatusBadge = '<span class="badge bg-info text-dark">TRAVEL</span>';
                    const statusBadgeForDtr = showTarfInTimeCells
                        ? tarfStatusBadge
                        : ((log.is_holiday && !log.has_holiday_attendance)
                            ? calc.statusBadge
                            : (calc.absentPeriod === 'full'
                                ? calc.statusBadge
                                : (calc.hasOfficialTime ? calc.statusBadge : '<span class="badge bg-secondary">No official time yet</span>')));
                    // Holiday/LEAVE: show literal label in time cells instead of parsing as time
                    const timeCell = (val) => {
                        if (showTarfInTimeCells) {
                            return '<span class="badge bg-info text-dark">TRAVEL</span>';
                        }
                        if (val === 'HOLIDAY') {
                            return isHalfDayHolidayRow
                                ? '<span class="badge bg-warning text-dark">Holiday</span>'
                                : '<span class="badge bg-danger">Holiday</span>';
                        }
                        if (val === 'LEAVE') return `<span class="badge bg-danger">${val}</span>`;
                        return val ? formatTimeTo12h(val) : '<span class="text-muted">-</span>';
                    };
                    row.innerHTML = `
                        <td>${counter++}</td>
                        <td class="fw-medium">${dayCellText}</td>
                        <td>${timeCell(log.time_in)}</td>
                        <td>${timeCell(log.lunch_out)}</td>
                        <td>${timeCell(log.lunch_in)}</td>
                        <td>${timeCell(log.time_out)}</td>
                        <td class="fw-semibold">
                            ${calc.hours > 0 ? calc.hours.toFixed(2) : '<span class="text-muted">-</span>'}
                        </td>
                        <td class="fw-semibold ${calc.hours > 0 ? 'text-primary' : 'text-muted'}">
                            ${calc.hours > 0 ? hoursInDays.toFixed(3) : '<span class="text-muted">-</span>'}
                        </td>
                        <td class="fw-semibold ${calc.lateMinutes > 0 ? 'text-danger' : 'text-muted'}">
                            ${calc.lateMinutes > 0 ? lateTimeFormat + ' <small class="text-muted">(' + hoursToDayFraction(calc.lateMinutes / 60).toFixed(3) + ' d)</small>' : '<span class="text-muted">-</span>'}
                        </td>
                        <td class="fw-semibold ${calc.undertimeMinutes > 0 ? 'text-warning' : 'text-muted'}">
                            ${calc.undertimeMinutes > 0 ? undertimeTimeFormat + ' <small class="text-muted">(' + hoursToDayFraction(calc.undertimeMinutes / 60).toFixed(3) + ' d)</small>' : '<span class="text-muted">-</span>'}
                        </td>
                        <td class="fw-semibold ${calc.absentHours > 0 ? 'text-danger' : 'text-muted'}">
                            ${absentHoursFormat}
                        </td>
                        <td class="fw-semibold ${calc.tardinessUndertimeHours > 0 ? 'text-warning' : 'text-muted'}">
                            ${tardinessUndertimeFormat}
                        </td>
                        <td class="fw-semibold ${tardinessAbsentLateHours > 0 ? 'text-warning' : 'text-muted'}">
                            ${tardinessAbsentLateHours > 0 ? tardinessAbsentLateInDays.toFixed(3) : '<span class="text-muted">-</span>'}
                        </td>
                        <td class="fw-semibold ${log.ot_in ? 'text-success' : 'text-muted'}">
                            ${log.ot_in ? formatTimeTo12h(log.ot_in) : '<span class="text-muted">-</span>'}
                        </td>
                        <td class="fw-semibold ${log.ot_out ? 'text-success' : 'text-muted'}">
                            ${log.ot_out ? formatTimeTo12h(log.ot_out) : '<span class="text-muted">-</span>'}
                        </td>
                        <td class="fw-semibold ${calc.hasOfficialTime && calc.overtimeMinutes > 0 ? 'text-success' : 'text-muted'}">
                            ${calc.hasOfficialTime ? totalOtFormat : '<span class="text-muted">-</span>'}
                        </td>
                        <td>
                            ${statusBadgeForDtr}
                        </td>
                        <td>
                            ${log.dean_verified ? '<span class="badge bg-success" title="Verified by supervisor"><i class="fas fa-check-circle me-1"></i>Yes</span>' : '<span class="badge bg-secondary">—</span>'}
                        </td>
                        ${showPardonColumn ? `<td class="dtr-pardon small">${buildPardonCell(log, empId)}</td>` : ''}
                    `;
                    tbody.appendChild(row);
                }
                
                // Calculate and display day conversion totals
                const totalHoursInDays = hoursToDayFraction(totalHours);
                const totalTardinessAbsentLateHours = totalAbsentHours + totalTardinessUndertimeHours;
                const totalTardinessAbsentLateInDays = hoursToDayFraction(totalTardinessAbsentLateHours);
                
                // Add totals row to table
                const totalLateMinutes = totalLate * 60;
                const totalUndertimeMinutes = totalUndertime * 60;
                const totalTardinessUndertimeMinutes = totalTardinessUndertimeHours * 60;
                const totalOtFormat = totalOTMinutes > 0 ? minutesToTimeFormat(totalOTMinutes) : '00:00:00';
                const totalLateFormat = totalLateMinutes > 0 ? minutesToTimeFormat(totalLateMinutes) : '00:00:00';
                const totalUndertimeFormat = totalUndertimeMinutes > 0 ? minutesToTimeFormat(totalUndertimeMinutes) : '00:00:00';
                const totalTardinessUndertimeFormat = totalTardinessUndertimeMinutes > 0 ? minutesToTimeFormat(totalTardinessUndertimeMinutes) : '00:00:00';
                const totalAbsentFormat = totalAbsentHours > 0
                    ? totalAbsentHours.toFixed(2) + ' h <small class="text-muted">(' + hoursToDayFraction(totalAbsentHours).toFixed(3) + ' d)</small>'
                    : '-';
                
                const totalsRow = document.createElement('tr');
                totalsRow.className = 'table-light fw-bold';
                totalsRow.innerHTML = `
                    <td colspan="6" class="text-end">TOTAL</td>
                    <td class="text-primary">${totalHours > 0 ? totalHours.toFixed(2) : '-'}</td>
                    <td class="text-primary">${totalHours > 0 ? totalHoursInDays.toFixed(3) : '-'}</td>
                    <td class="text-danger">${totalLateMinutes > 0 ? totalLateFormat + ' <small class="text-muted">(' + hoursToDayFraction(totalLate).toFixed(3) + ' d)</small>' : '-'}</td>
                    <td class="text-warning">${totalUndertimeMinutes > 0 ? totalUndertimeFormat + ' <small class="text-muted">(' + hoursToDayFraction(totalUndertime).toFixed(3) + ' d)</small>' : '-'}</td>
                    <td class="text-danger">${totalAbsentFormat}</td>
                    <td class="text-warning">${totalTardinessUndertimeMinutes > 0 ? totalTardinessUndertimeFormat : '-'}</td>
                    <td class="text-warning">${totalTardinessAbsentLateHours > 0 ? totalTardinessAbsentLateInDays.toFixed(3) : '-'}</td>
                    <td><span class="text-muted">-</span></td>
                    <td><span class="text-muted">-</span></td>
                    <td class="text-success">${totalOTMinutes > 0 ? totalOtFormat : '-'}</td>
                    <td></td>
                    <td></td>
                    ${showPardonColumn ? '<td></td>' : ''}
                `;
                tbody.appendChild(totalsRow);
                
                // Update day conversion totals in summary
                const totalHoursDaysEl = document.getElementById('totalHoursDays');
                if (totalHoursDaysEl) {
                    totalHoursDaysEl.textContent = totalHoursInDays.toFixed(3);
                }
                
                const totalTardinessAbsentLateDaysEl = document.getElementById('totalTardinessAbsentLateDays');
                if (totalTardinessAbsentLateDaysEl) {
                    totalTardinessAbsentLateDaysEl.textContent = totalTardinessAbsentLateInDays.toFixed(3);
                }
                
                const logsCount = document.getElementById('logsCount');
                if (logsCount) {
                    logsCount.textContent = `${data.logs.length} log${data.logs.length !== 1 ? 's' : ''} found`;
                    logsCount.className = 'badge bg-success';
                }
                // Enable print button when logs are loaded
                const printDTRBtn = document.getElementById('printDTRBtn');
                const printDTRBtnFooter = document.getElementById('printDTRBtnFooter');
                if (printDTRBtn) printDTRBtn.disabled = false;
                if (printDTRBtnFooter) printDTRBtnFooter.disabled = false;
            } else {
                tbody.innerHTML = '<tr><td colspan="19" class="text-center text-muted py-4"><i class="fas fa-info-circle me-2"></i>No attendance logs found for this employee</td></tr>';
                const logsCount = document.getElementById('logsCount');
                if (logsCount) {
                    logsCount.textContent = '0 logs found';
                    logsCount.className = 'badge bg-warning';
                }
                // Still enable print button even if no logs (will print empty DTR)
                const printDTRBtn = document.getElementById('printDTRBtn');
                const printDTRBtnFooter = document.getElementById('printDTRBtnFooter');
                if (printDTRBtn) printDTRBtn.disabled = false;
                if (printDTRBtnFooter) printDTRBtnFooter.disabled = false;
            }
            
            // Store pardon_open_dates for potential refresh (from open pardon API)
            window.currentLogsPardonOpenDates = data.pardon_open_dates || [];
        })
        .catch(error => {
            console.error('Error fetching logs:', error);
            console.error('Error details:', {
                message: error.message,
                stack: error.stack,
                employeeId: empId
            });
            const errorMessage = error.message || 'Unknown error occurred';
            tbody.innerHTML = `<tr><td colspan="18" class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle me-2"></i>Error loading logs: ${errorMessage}<br><small class="text-muted">Check browser console for details</small></td></tr>`;
            const logsCount = document.getElementById('logsCount');
            if (logsCount) {
                logsCount.textContent = 'Error loading logs';
                logsCount.className = 'badge bg-danger';
            }
            // Keep print button disabled on error
            const printDTRBtn = document.getElementById('printDTRBtn');
            const printDTRBtnFooter = document.getElementById('printDTRBtnFooter');
            if (printDTRBtn) printDTRBtn.disabled = true;
            if (printDTRBtnFooter) printDTRBtnFooter.disabled = true;
        });
}

function filterLogs() {
    const selectedMonth = document.getElementById('filterMonth').value;
    const rows = document.getElementById('logsTableBody').getElementsByTagName('tr');
    let visibleCount = 0;
    let totalHours = 0;
    let totalLate = 0;
    let totalUndertime = 0;
    let totalOvertime = 0;
    let totalOTMinutes = 0;
    
    const monthNames = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    
    Array.from(rows).forEach(row => {
        if (row.cells.length < 2) return;
        
        const dateCell = row.cells[1] ? row.cells[1].textContent : '';
        const logDateAttr = row.getAttribute('data-log-date') || '';
        
        let matchesMonth = true;
        if (selectedMonth) {
            const currentYear = new Date().getFullYear();
            const monthPattern = new RegExp(`${currentYear}-${selectedMonth}|-${selectedMonth}-`, 'i');
            const monthIndex = parseInt(selectedMonth) - 1;
            matchesMonth = monthPattern.test(logDateAttr) || 
                           dateCell.toLowerCase().includes(monthNames[monthIndex] || '');
        }
        
        if (matchesMonth) {
            row.style.display = '';
            visibleCount++;
            
            const timeInCell = row.cells[2] ? row.cells[2].textContent.trim() : '';
            const timeOutCell = row.cells[5] ? row.cells[5].textContent.trim() : '';
            const lunchOutCell = row.cells[3] ? row.cells[3].textContent.trim() : '';
            const lunchInCell = row.cells[4] ? row.cells[4].textContent.trim() : '';
            
            if (timeInCell && timeOutCell && timeInCell !== '-' && timeOutCell !== '-') {
                const actualInMinutes = parseTime(timeInCell);
                const actualOutMinutes = parseTime(timeOutCell);
                const actualLunchOutMinutes = lunchOutCell && lunchOutCell !== '-' ? parseTime(lunchOutCell) : parseTime(DEFAULT_OFFICIAL_TIMES.lunch_out);
                const actualLunchInMinutes = lunchInCell && lunchInCell !== '-' ? parseTime(lunchInCell) : parseTime(DEFAULT_OFFICIAL_TIMES.lunch_in);
                const officialInMinutes = parseTime(DEFAULT_OFFICIAL_TIMES.time_in);
                
                if (actualInMinutes !== null && actualOutMinutes !== null) {
                    const morningMinutes = actualLunchOutMinutes - actualInMinutes;
                    const afternoonMinutes = actualOutMinutes - actualLunchInMinutes;
                    const hours = Math.max(0, (morningMinutes + afternoonMinutes) / 60);
                    
                    totalHours += hours;
                    
                    if (actualInMinutes > officialInMinutes) {
                        totalLate += (actualInMinutes - officialInMinutes) / 60;
                    }
                    
                    if (hours < 8) {
                        totalUndertime += (8 - hours);
                    } else if (hours > 8) {
                        totalOvertime += (hours - 8);
                    }
                }
            }
            
            // Extract TOTAL OT from cell (index 11)
            const totalOTCell = row.cells[11] ? row.cells[11].textContent.trim() : '';
            if (totalOTCell && totalOTCell !== '-' && totalOTCell !== '00:00:00') {
                // Parse HH:MM:SS format to minutes
                const otParts = totalOTCell.split(':');
                if (otParts.length >= 2) {
                    const hours = parseInt(otParts[0], 10) || 0;
                    const minutes = parseInt(otParts[1], 10) || 0;
                    const seconds = parseInt(otParts[2], 10) || 0;
                    totalOTMinutes += hours * 60 + minutes + (seconds / 60);
                }
            }
        } else {
            row.style.display = 'none';
        }
    });
    
    const logsCount = document.getElementById('logsCount');
    if (logsCount) logsCount.textContent = `${visibleCount} log${visibleCount !== 1 ? 's' : ''} shown`;
}

function resetFilters() {
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    if (window.currentLogsEmployeeId) {
        loadLogsWithFilters(window.currentLogsEmployeeId);
    }
}

function applyDateRangeFilter() {
    if (window.currentLogsEmployeeId) {
        loadLogsWithFilters(window.currentLogsEmployeeId);
    }
}

function refreshLogs() {
    if (window.currentLogsEmployeeId) {
        loadLogsWithFilters(window.currentLogsEmployeeId);
    }
}

function printDTR() {
    if (!window.currentLogsEmployeeId) {
        alert('No employee selected. Please open attendance logs first.');
        return;
    }
    
    const dateFrom = document.getElementById('filterDateFrom').value || '';
    const dateTo = document.getElementById('filterDateTo').value || '';
    
    let url = 'print_dtr.php?employee_id=' + encodeURIComponent(window.currentLogsEmployeeId);
    if (dateFrom) {
        url += '&date_from=' + encodeURIComponent(dateFrom);
    }
    if (dateTo) {
        url += '&date_to=' + encodeURIComponent(dateTo);
    }
    
    // Open in new window for printing
    const printWindow = window.open(url, '_blank');
    if (!printWindow) {
        alert('Please allow popups to print DTR.');
    }
}

function viewSalaryReports() {
    if (!window.currentLogsEmployeeId) {
        alert('No employee selected. Please open attendance logs first.');
        return;
    }
    
    const empName = window.currentLogsEmployeeName || '';
    
    // Check if viewSalary function exists
    if (typeof window.viewSalary === 'function') {
        // Fetch employee info to get position
        fetch('get_employee_info_api.php?employee_id=' + encodeURIComponent(window.currentLogsEmployeeId))
            .then(response => response.json())
            .then(data => {
                if (data.success && data.employee && data.employee.position) {
                    window.viewSalary(window.currentLogsEmployeeId, empName, data.employee.position);
                    // Close employee management modal
                    const employeeModal = bootstrap.Modal.getInstance(document.getElementById('employeeManagementModal'));
                    if (employeeModal) {
                        employeeModal.hide();
                    }
                } else {
                    alert('Could not load employee information. Please use the Salary button from the main employee list.');
                }
            })
            .catch(error => {
                console.error('Error fetching employee info:', error);
                alert('Could not load employee information. Please use the Salary button from the main employee list.');
            });
    } else {
        alert('Salary view is not available. Please use the Salary button from the main employee list.');
    }
}

function manageDeductions(empId, empName, position, employmentType) {
    document.getElementById('deductionsModalTitle').textContent = 'Employee Deductions - ' + empName;
    document.getElementById('deductionsEmployeeName').textContent = 'Safe Employee ID: ' + empId;
    window.currentDeductionEmpId = empId;
    window.currentDeductionPosition = position || '';
    window.currentDeductionEmploymentType = (employmentType || '').toUpperCase().trim();
    
    // Check if PhilHealth button should be enabled based on employment type
    const philHealthButton = document.getElementById('philHealthButton');
    // PhilHealth is available for: PERMANENT, TEMPORARY, CONTRACT, JOB ORDER, CASUAL
    // Note: JOB ORDER and CASUAL are typically eligible for PhilHealth contributions
    const allowedEmploymentTypes = ['PERMANENT', 'TEMPORARY', 'CONTRACT', 'JOB ORDER', 'CASUAL', 'COS'];
    const isAllowed = allowedEmploymentTypes.includes(window.currentDeductionEmploymentType);
    
    // Auto-apply PhilHealth only for: TEMPORARY, PERMANENT, CONTRACT
    const autoApplyEmploymentTypes = ['TEMPORARY', 'PERMANENT', 'CONTRACT'];
    const shouldAutoApply = autoApplyEmploymentTypes.includes(window.currentDeductionEmploymentType);
    
    if (philHealthButton) {
        if (isAllowed) {
            philHealthButton.disabled = false;
            philHealthButton.style.opacity = '1';
            philHealthButton.style.cursor = 'pointer';
            philHealthButton.title = 'Add PhilHealth Deduction';
        } else {
            philHealthButton.disabled = true;
            philHealthButton.style.opacity = '0.5';
            philHealthButton.style.cursor = 'not-allowed';
            philHealthButton.title = 'PhilHealth is only available for PERMANENT, TEMPORARY, CONTRACT, JOB ORDER, and CASUAL employees';
        }
    }
    
    loadDeductionOptions();
    
    // Automatically check and add PhilHealth deduction if employee is eligible for auto-application
    if (shouldAutoApply) {
        checkAndAddPhilHealthMonthly(empId, position);
    } else {
        // Just load deductions without auto-adding
        loadEmployeeDeductions(empId);
    }
    
    const modal = new bootstrap.Modal(document.getElementById('deductionsModal'));
    modal.show();
}

function checkAndAddPhilHealthMonthly(empId, position) {
    // First, load existing deductions to check if PhilHealth exists for current month
    fetch('get_employee_deductions_api.php?employee_id=' + encodeURIComponent(empId))
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error('Error loading deductions:', data.message);
                loadEmployeeDeductions(empId);
                return;
            }
            
            // Get current month's first day (YYYY-MM-01)
            const now = new Date();
            const currentYear = now.getFullYear();
            const currentMonth = String(now.getMonth() + 1).padStart(2, '0');
            const currentMonthStart = `${currentYear}-${currentMonth}-01`;
            
            // Check if PhilHealth deduction already exists for current month
            const hasPhilHealthThisMonth = data.deductions && data.deductions.some(deduction => {
                const itemName = (deduction.item_name || '').toLowerCase();
                const isPhilHealth = itemName.includes('philhealth') || itemName.includes('phil health');
                const deductionStartDate = deduction.start_date || '';
                // Check if start_date is within current month
                return isPhilHealth && deductionStartDate.startsWith(`${currentYear}-${currentMonth}`);
            });
            
            if (hasPhilHealthThisMonth) {
                // PhilHealth already exists for this month, just load deductions
                loadEmployeeDeductions(empId);
            } else {
                // PhilHealth doesn't exist for current month, add it automatically
                // Show notification that we're auto-applying
                if (typeof showToast === 'function') {
                    showToast('Auto-applying PhilHealth deduction for this month...', 'info');
                }
                autoAddPhilHealthMonthly(empId, position, currentMonthStart);
            }
        })
        .catch(error => {
            console.error('Error checking PhilHealth deduction:', error);
            loadEmployeeDeductions(empId);
        });
}

function autoAddPhilHealthMonthly(empId, position, startDate) {
    // Get monthly salary from position using salary calculation API
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    
    fetch(`calculate_salary_api.php?employee_id=${encodeURIComponent(empId)}&position=${encodeURIComponent(position)}&year=${year}&month=${month}&period=full`)
        .then(response => response.json())
        .then(salaryData => {
            if (!salaryData.success) {
                console.error('Failed to get salary information:', salaryData.message);
                loadEmployeeDeductions(empId);
                return;
            }
            
            const monthlySalary = parseFloat(salaryData.monthly_rate) || 0;
            
            if (monthlySalary <= 0) {
                console.warn('Monthly salary not found. Cannot add PhilHealth automatically.');
                loadEmployeeDeductions(empId);
                return;
            }
            
            // Calculate PhilHealth contribution
            const philHealthCalc = calculatePhilHealthContribution(monthlySalary);
            
            // Get PhilHealth deduction ID directly from API
            fetch('get_deductions_list_api.php')
                .then(response => response.json())
                .then(deductionData => {
                    if (!deductionData.success || !deductionData.deductions) {
                        throw new Error('Failed to load deduction options');
                    }
                    
                    // Find PhilHealth deduction from the list
                    let philHealthDeductionId = null;
                    const philHealthDeduction = deductionData.deductions.find(deduction => {
                        const itemName = (deduction.item_name || '').toLowerCase();
                        return itemName.includes('philhealth') || itemName.includes('phil health');
                    });
                    
                    if (philHealthDeduction && philHealthDeduction.id) {
                        philHealthDeductionId = philHealthDeduction.id;
                    }
                    
                    if (!philHealthDeductionId) {
                        console.warn('PhilHealth deduction item not found in database. Cannot add automatically.');
                        if (typeof showToast === 'function') {
                            showToast('PhilHealth deduction item not found. Please add it manually.', 'warning');
                        }
                        loadEmployeeDeductions(empId);
                        return;
                    }
                    
                    // Prepare form data for automatic submission
                    const formData = new FormData();
                    formData.append('employee_id', empId);
                    formData.append('deduction_id', philHealthDeductionId);
                    formData.append('amount', philHealthCalc.employeeShare.toFixed(2));
                    formData.append('start_date', startDate);
                    formData.append('end_date', ''); // No end date for monthly deductions
                    formData.append('remarks', `PhilHealth Contribution (Employee Share) - Auto-added monthly\n` +
                        `Monthly Salary: ₱${philHealthCalc.monthlySalary.toFixed(2)}\n` +
                        `Adjusted Salary (for calculation): ₱${philHealthCalc.adjustedSalary.toFixed(2)}\n` +
                        `Total Monthly Premium (5%): ₱${philHealthCalc.totalPremium.toFixed(2)}\n` +
                        `Employee Share (50%): ₱${philHealthCalc.employeeShare.toFixed(2)}\n` +
                        `Employer Share (50%): ₱${philHealthCalc.employerShare.toFixed(2)}`);
                    formData.append('is_tardiness', '0');
                    formData.append('add_employee_deduction', '1');
                    formData.append('ajax', '1');
                    
                    console.log('Submitting PhilHealth deduction:', {
                        employee_id: empId,
                        deduction_id: philHealthDeductionId,
                        amount: philHealthCalc.employeeShare.toFixed(2),
                        start_date: startDate
                    });
                    
                    // Submit the form automatically
                    fetch('employee_logs.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            console.log('PhilHealth deduction added automatically for current month', data);
                            // Show success notification
                            if (typeof showToast === 'function') {
                                showToast('PhilHealth deduction automatically applied for this month', 'success');
                            }
                            // Small delay to ensure database is updated, then reload deductions
                            setTimeout(() => {
                                loadEmployeeDeductions(empId);
                            }, 500);
                        } else {
                            console.error('Error adding PhilHealth automatically:', data.message, data);
                            if (typeof showToast === 'function') {
                                showToast('Failed to auto-apply PhilHealth: ' + (data.message || 'Unknown error'), 'error');
                            }
                            loadEmployeeDeductions(empId);
                        }
                    })
                    .catch(error => {
                        console.error('Error adding PhilHealth deduction:', error);
                        if (typeof showToast === 'function') {
                            showToast('Error auto-applying PhilHealth deduction', 'error');
                        }
                        loadEmployeeDeductions(empId);
                    });
                })
                .catch(error => {
                    console.error('Error fetching deduction options:', error);
                    if (typeof showToast === 'function') {
                        showToast('Error loading deduction options', 'error');
                    }
                    loadEmployeeDeductions(empId);
                });
        })
        .catch(error => {
            console.error('Error calculating PhilHealth:', error);
            loadEmployeeDeductions(empId);
        });
}

function loadDeductionOptions() {
    const select = document.getElementById('deduction_id_select');
    if (!select) {
        console.error('Deduction select element not found');
        return Promise.resolve();
    }
    
    // Clear and add default option first
    select.innerHTML = '<option value="">Choose a deduction...</option>';
    
    // Return a promise so we can wait for it to complete
    return fetch('get_deductions_list_api.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.deductions) {
                data.deductions.forEach(deduction => {
                    const option = document.createElement('option');
                    option.value = deduction.id;
                    option.textContent = deduction.item_name + ' (' + deduction.type + ')';
                    select.appendChild(option);
                });
            }
            return data;
        })
        .catch(error => {
            console.error('Error loading deductions:', error);
            return { success: false, error: error };
        });
}

function loadEmployeeDeductions(empId) {
    if (!empId) {
        console.error('loadEmployeeDeductions: Employee ID is required');
        return;
    }
    
    fetch('get_employee_deductions_api.php?employee_id=' + encodeURIComponent(empId))
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            const tbody = document.getElementById('employeeDeductionsBody');
            if (!tbody) {
                console.error('loadEmployeeDeductions: Table body element not found');
                return;
            }
            
            tbody.innerHTML = '';
            
            if (data.success && data.deductions && data.deductions.length > 0) {
                data.deductions.forEach(deduction => {
                    const isActive = deduction.is_active == 1;
                    const today = new Date().toISOString().split('T')[0];
                    const isCurrentlyActive = isActive && deduction.start_date <= today && (!deduction.end_date || deduction.end_date >= today);
                    
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td class="fw-medium">${deduction.item_name}</td>
                        <td>${deduction.type}</td>
                        <td class="text-end text-success fw-semibold">₱ ${parseFloat(deduction.amount).toFixed(2)}</td>
                        <td>${deduction.start_date}</td>
                        <td>${deduction.end_date || '-'}</td>
                        <td class="text-center">
                            <span class="badge bg-${isCurrentlyActive ? 'success' : 'secondary'}">
                                ${isCurrentlyActive ? '✓ Active' : '○ Inactive'}
                            </span>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button onclick="editEmployeeDeduction(${deduction.ed_id}, '${deduction.item_name}', ${deduction.deduction_id}, ${deduction.amount}, '${deduction.start_date}', '${deduction.end_date || ''}', '${(deduction.remarks || '').replace(/'/g, "\\'")}')" class="btn btn-warning btn-sm">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="deleteEmployeeDeduction(${deduction.ed_id}, '${deduction.item_name.replace(/'/g, "\\'")}')" class="btn btn-danger btn-sm">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No deductions assigned to this employee</td></tr>';
            }
        })
        .catch(error => {
            console.error('Error loading employee deductions:', error);
            document.getElementById('employeeDeductionsBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Error loading deductions</td></tr>';
        });
}

function openAddDeductionForm(deductionType = null) {
    document.getElementById('addDeductionForm').reset();
    document.getElementById('deductionEmployeeId').value = window.currentDeductionEmpId;
    document.getElementById('deductionEdId').value = 0;
    document.getElementById('deductionStartDate').valueAsDate = new Date();
    
    // Get field containers
    const tardinessFields = document.getElementById('tardinessFields');
    const otherDeductionFields = document.getElementById('otherDeductionFields');
    const isTardinessField = document.getElementById('is_tardiness');
    const deductionIdField = document.getElementById('deduction_id');
    
    // Update modal title based on deduction type
    const modalTitle = document.getElementById('addDeductionModalTitle');
    if (modalTitle) {
        if (deductionType === 'tardiness') {
            modalTitle.textContent = 'Add Tardiness Deduction';
        } else {
            modalTitle.textContent = 'Add Deduction to Employee';
        }
    }
    
    document.getElementById('deductionSubmitButton').textContent = 'Add Deduction';
    
    // Get the form fields that have required attribute
    const deductionIdSelect = document.getElementById('deduction_id_select');
    const deductionAmountOther = document.getElementById('deductionAmountOther');
    const deductionAmountTardiness = document.getElementById('deductionAmount');
    
    // Show/hide appropriate fields
    if (deductionType === 'tardiness') {
        // Show tardiness fields, hide other deduction fields
        if (tardinessFields) tardinessFields.style.display = 'block';
        if (otherDeductionFields) otherDeductionFields.style.display = 'none';
        if (isTardinessField) isTardinessField.value = '1';
        if (deductionIdField) deductionIdField.value = 'tardiness';
        
        // Toggle required attributes - remove from hidden fields, add to visible ones
        if (deductionIdSelect) deductionIdSelect.removeAttribute('required');
        if (deductionAmountOther) deductionAmountOther.removeAttribute('required');
        if (deductionAmountTardiness) deductionAmountTardiness.setAttribute('required', '');
        
        // Clear tardiness input fields
        const clearField = (id) => {
            const el = document.getElementById(id);
            if (el) el.value = '0';
        };
        
        clearField('tardinessHoursInput');
        clearField('tardinessMinutesInput');
        clearField('tardinessSecondsInput');
        clearField('deductionAmount');
        
        // Update hidden field
        const hiddenField = document.getElementById('tardinessHours');
        if (hiddenField) hiddenField.value = '00:00:00';
        
        // Load rates for calculations
        setTimeout(() => {
            loadEmployeeHourlyRate(window.currentDeductionEmpId);
        }, 100);
    } else {
        // Show other deduction fields, hide tardiness fields
        if (tardinessFields) tardinessFields.style.display = 'none';
        if (otherDeductionFields) otherDeductionFields.style.display = 'block';
        if (isTardinessField) isTardinessField.value = '0';
        if (deductionIdField) deductionIdField.value = '';
        
        // Toggle required attributes - add to visible fields, remove from hidden ones
        if (deductionIdSelect) deductionIdSelect.setAttribute('required', '');
        if (deductionAmountOther) deductionAmountOther.setAttribute('required', '');
        if (deductionAmountTardiness) deductionAmountTardiness.removeAttribute('required');
        
        // Load deduction options for dropdown
        loadDeductionOptions();
    }
    
    const modal = new bootstrap.Modal(document.getElementById('addDeductionFormModal'));
    modal.show();
}

function calculatePhilHealthContribution(monthlySalary) {
    // PhilHealth contribution calculation for 2024-2025
    // Formula: Total Monthly Premium = Monthly Basic Salary × 5%
    // Subject to floor: ₱10,000 (minimum monthly premium is ₱500)
    // Subject to ceiling: ₱100,000 (maximum monthly premium is ₱5,000)
    // Employee Share = Total Monthly Premium / 2
    
    const SALARY_FLOOR = 10000;
    const SALARY_CEILING = 100000;
    const PREMIUM_RATE = 0.05; // 5%
    
    // Apply floor and ceiling to salary
    let adjustedSalary = monthlySalary;
    if (monthlySalary < SALARY_FLOOR) {
        adjustedSalary = SALARY_FLOOR;
    } else if (monthlySalary > SALARY_CEILING) {
        adjustedSalary = SALARY_CEILING;
    }
    
    // Calculate total monthly premium (5% of adjusted salary)
    const totalPremium = adjustedSalary * PREMIUM_RATE;
    
    // Employee share is half of total premium (shared equally between employee and employer)
    const employeeShare = totalPremium / 2;
    
    return {
        monthlySalary: monthlySalary,
        adjustedSalary: adjustedSalary,
        totalPremium: totalPremium,
        employeeShare: employeeShare,
        employerShare: employeeShare
    };
}

function openAddPhilHealthDeduction() {
    const empId = window.currentDeductionEmpId;
    const position = window.currentDeductionPosition || '';
    const employmentType = (window.currentDeductionEmploymentType || '').toUpperCase().trim();
    
    if (!empId) {
        alert('Safe Employee ID not found. Please try again.');
        return;
    }
    
    if (!position) {
        alert('Employee position not found. Cannot calculate PhilHealth contribution.');
        return;
    }
    
    // Check if employment type is allowed for PhilHealth
    // PhilHealth is available for: PERMANENT, TEMPORARY, CONTRACT, JOB ORDER, CASUAL
    const allowedEmploymentTypes = ['PERMANENT', 'TEMPORARY', 'CONTRACT', 'JOB ORDER', 'CASUAL', 'COS'];
    if (!allowedEmploymentTypes.includes(employmentType)) {
        alert('PhilHealth deduction is only available for PERMANENT, TEMPORARY, CONTRACT, JOB ORDER, and CASUAL employees.\n\nCurrent employment type: ' + (employmentType || 'Not specified'));
        return;
    }
    
    // Get monthly salary from position using salary calculation API
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    
    // Show loading state
    showToast('Calculating PhilHealth contribution...', 'info');
    
    fetch(`calculate_salary_api.php?employee_id=${encodeURIComponent(empId)}&position=${encodeURIComponent(position)}&year=${year}&month=${month}&period=full`)
        .then(response => response.json())
        .then(salaryData => {
            if (!salaryData.success) {
                throw new Error(salaryData.message || 'Failed to get salary information');
            }
            
            const monthlySalary = parseFloat(salaryData.monthly_rate) || 0;
            
            if (monthlySalary <= 0) {
                alert('Monthly salary not found. Cannot calculate PhilHealth contribution.');
                return;
            }
            
            // Calculate PhilHealth contribution
            const philHealthCalc = calculatePhilHealthContribution(monthlySalary);
            
            // Reset and prepare the form
            document.getElementById('addDeductionForm').reset();
            document.getElementById('deductionEmployeeId').value = empId;
            document.getElementById('deductionEdId').value = 0;
            document.getElementById('deductionStartDate').valueAsDate = new Date();
            document.getElementById('is_tardiness').value = '0';
            document.getElementById('deduction_id').value = '';
            
            // Hide tardiness fields, show other deduction fields
            const tardinessFields = document.getElementById('tardinessFields');
            const otherDeductionFields = document.getElementById('otherDeductionFields');
            if (tardinessFields) tardinessFields.style.display = 'none';
            if (otherDeductionFields) otherDeductionFields.style.display = 'block';
            
            // Toggle required attributes - add to visible fields, remove from hidden ones
            const deductionIdSelect = document.getElementById('deduction_id_select');
            const deductionAmountOther = document.getElementById('deductionAmountOther');
            const deductionAmountTardiness = document.getElementById('deductionAmount');
            if (deductionIdSelect) deductionIdSelect.setAttribute('required', '');
            if (deductionAmountOther) deductionAmountOther.setAttribute('required', '');
            if (deductionAmountTardiness) deductionAmountTardiness.removeAttribute('required');
            
            // Update modal title
            const modalTitle = document.getElementById('addDeductionModalTitle');
            if (modalTitle) {
                modalTitle.textContent = 'Add PhilHealth Deduction';
            }
            
            document.getElementById('deductionSubmitButton').textContent = 'Add Deduction';
            
            // Load deduction options and then select PhilHealth
            loadDeductionOptions().then(() => {
                // Try to find PhilHealth in the dropdown
                const select = document.getElementById('deduction_id_select');
                let philHealthDeductionId = null;
                
                if (select) {
                    // Find option that contains "philhealth" or "phil health" (case-insensitive)
                    const option = Array.from(select.options).find(opt => {
                        const text = opt.textContent.toLowerCase();
                        return text.includes('philhealth') || text.includes('phil health');
                    });
                    
                    if (option && option.value) {
                        select.value = option.value;
                        philHealthDeductionId = option.value;
                    } else {
                        // If not found, show modal for manual selection
                        console.warn('PhilHealth deduction item not found in dropdown');
                        showToast('PhilHealth deduction item not found. Please select it manually from the dropdown.', 'warning');
                        const modal = new bootstrap.Modal(document.getElementById('addDeductionFormModal'));
                        modal.show();
                        return;
                    }
                }
                
                // Set the calculated amount
                const amountField = document.getElementById('deductionAmountOther');
                if (amountField) {
                    amountField.value = philHealthCalc.employeeShare.toFixed(2);
                }
                
                // Add calculation details to remarks
                const remarksField = document.getElementById('deductionRemarks');
                if (remarksField) {
                    const remarks = `PhilHealth Contribution (Employee Share)\n` +
                                   `Monthly Salary: ₱${philHealthCalc.monthlySalary.toFixed(2)}\n` +
                                   `Adjusted Salary (for calculation): ₱${philHealthCalc.adjustedSalary.toFixed(2)}\n` +
                                   `Total Monthly Premium (5%): ₱${philHealthCalc.totalPremium.toFixed(2)}\n` +
                                   `Employee Share (50%): ₱${philHealthCalc.employeeShare.toFixed(2)}\n` +
                                   `Employer Share (50%): ₱${philHealthCalc.employerShare.toFixed(2)}`;
                    remarksField.value = remarks;
                }
                
                // Automatically submit the form since employment type is allowed
                if (philHealthDeductionId) {
                    const form = document.getElementById('addDeductionForm');
                    const formData = new FormData(form);
                    
                    // Set required fields
                    formData.set('deduction_id', philHealthDeductionId);
                    formData.set('amount', philHealthCalc.employeeShare.toFixed(2));
                    formData.set('is_tardiness', '0');
                    
                    // Submit the form automatically
                    showToast('Adding PhilHealth deduction...', 'info');
                    
                    fetch('employee_logs.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast('PhilHealth deduction added successfully!', 'success');
                            loadEmployeeDeductions(empId);
                        } else {
                            showToast(data.message || 'Error adding PhilHealth deduction', 'error');
                            // Show the modal so user can fix any issues
                            const modal = new bootstrap.Modal(document.getElementById('addDeductionFormModal'));
                            modal.show();
                        }
                    })
                    .catch(error => {
                        console.error('Error adding PhilHealth deduction:', error);
                        showToast('Error adding PhilHealth deduction: ' + error.message, 'error');
                        // Show the modal so user can try again
                        const modal = new bootstrap.Modal(document.getElementById('addDeductionFormModal'));
                        modal.show();
                    });
                }
            });
        })
        .catch(error => {
            console.error('Error calculating PhilHealth:', error);
            alert('Error calculating PhilHealth contribution: ' + error.message);
        });
}

// Make function globally accessible
window.openAddPhilHealthDeduction = openAddPhilHealthDeduction;
window.calculatePhilHealthContribution = calculatePhilHealthContribution;

function loadEmployeeHourlyRate(empId) {
    // Get employee position (stored when manageDeductions was called) and calculate rates
    const position = window.currentDeductionPosition || '';
    
    if (!position) {
        const updateRateField = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.value = value;
        };
        updateRateField('tardinessHourlyRate', 'Position not available');
        window.currentEmployeeHourlyRate = 0;
        window.currentEmployeeDailyRate = 0;
        return;
    }
    
    // Calculate rates from position using salary calculation API
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    
    fetch(`calculate_salary_api.php?employee_id=${encodeURIComponent(empId)}&position=${encodeURIComponent(position)}&year=${year}&month=${month}&period=full`)
        .then(response => response.json())
        .then(salaryData => {
            if (salaryData.success) {
                const hourlyRate = parseFloat(salaryData.hourly_rate) || 0;
                const dailyRate = parseFloat(salaryData.daily_rate) || 0;
                
                // Update rate field
                const updateRateField = (id, value) => {
                    const el = document.getElementById(id);
                    if (el) el.value = value.toFixed(2);
                };
                
                updateRateField('tardinessHourlyRate', hourlyRate);
                
                window.currentEmployeeHourlyRate = hourlyRate;
                
                // Recalculate if values already entered
                if (document.getElementById('tardinessHours') && document.getElementById('tardinessHours').value) {
                    calculateTardinessDeduction();
                }
            } else {
                const updateRateField = (id, value) => {
                    const el = document.getElementById(id);
                    if (el) el.value = value;
                };
                updateRateField('tardinessHourlyRate', 'Unable to fetch');
                window.currentEmployeeHourlyRate = 0;
            }
        })
        .catch(error => {
            console.error('Error loading rates:', error);
            const updateRateField = (id, value) => {
                const el = document.getElementById(id);
                if (el) el.value = value;
            };
            updateRateField('tardinessHourlyRate', 'Error loading');
            window.currentEmployeeHourlyRate = 0;
        });
}

// handleDeductionTypeChange function no longer needed - form is always for tardiness

// Helper function to update the hidden time field from separate inputs
function updateTardinessTime() {
    const hours = parseInt(document.getElementById('tardinessHoursInput').value) || 0;
    const minutes = parseInt(document.getElementById('tardinessMinutesInput').value) || 0;
    const seconds = parseInt(document.getElementById('tardinessSecondsInput').value) || 0;
    
    // Format as HH:MM:SS
    const formattedTime = String(hours).padStart(2, '0') + ':' + 
                         String(minutes).padStart(2, '0') + ':' + 
                         String(seconds).padStart(2, '0');
    
    const hiddenField = document.getElementById('tardinessHours');
    if (hiddenField) {
        hiddenField.value = formattedTime;
    }
}

// Helper function to convert HH:MM:SS to decimal hours
function timeToHours(timeStr) {
    if (!timeStr || timeStr.trim() === '') return 0;
    
    // Remove any whitespace
    timeStr = timeStr.trim();
    
    // Parse HH:MM:SS format
    const parts = timeStr.split(':');
    if (parts.length >= 2) {
        const hours = parseInt(parts[0], 10) || 0;
        const minutes = parseInt(parts[1], 10) || 0;
        const seconds = parseInt(parts[2], 10) || 0;
        
        // Convert to decimal hours
        return hours + (minutes / 60) + (seconds / 3600);
    }
    
    return 0;
}

function calculateTardinessDeduction() {
    // Get values from separate inputs
    const hoursInput = document.getElementById('tardinessHoursInput');
    const minutesInput = document.getElementById('tardinessMinutesInput');
    const secondsInput = document.getElementById('tardinessSecondsInput');
    
    const hours = parseInt(hoursInput ? hoursInput.value : 0) || 0;
    const minutes = parseInt(minutesInput ? minutesInput.value : 0) || 0;
    const seconds = parseInt(secondsInput ? secondsInput.value : 0) || 0;
    
    // Convert to decimal hours
    const totalHours = hours + (minutes / 60) + (seconds / 3600);
    const hourlyRate = window.currentEmployeeHourlyRate || 0;
    
    if (totalHours > 0 && hourlyRate > 0) {
        // Multiply hours by 2, then multiply by hourly rate
        const deduction = (totalHours * 2) * hourlyRate;
        const amountEl = document.getElementById('deductionAmount');
        if (amountEl) amountEl.value = deduction.toFixed(2);
    } else {
        const amountEl = document.getElementById('deductionAmount');
        if (amountEl) amountEl.value = '0.00';
    }
}


function editEmployeeDeduction(edId, itemName, deductionId, amount, startDate, endDate, remarks) {
    document.getElementById('deductionEmployeeId').value = window.currentDeductionEmpId;
    document.getElementById('deductionEdId').value = edId;
    document.getElementById('deductionStartDate').value = startDate;
    document.getElementById('deductionEndDate').value = endDate || '';
    document.getElementById('deductionRemarks').value = remarks || '';
    
    // Check if this is a tardiness deduction
    const isTardiness = itemName.toLowerCase() === 'tardiness';
    const tardinessFields = document.getElementById('tardinessFields');
    const otherDeductionFields = document.getElementById('otherDeductionFields');
    const isTardinessField = document.getElementById('is_tardiness');
    const deductionIdField = document.getElementById('deduction_id');
    const modalTitle = document.getElementById('addDeductionModalTitle');
    
    // Get the form fields that have required attribute
    const deductionIdSelect = document.getElementById('deduction_id_select');
    const deductionAmountOther = document.getElementById('deductionAmountOther');
    const deductionAmountTardiness = document.getElementById('deductionAmount');
    
    if (isTardiness) {
        // Show tardiness fields
        if (tardinessFields) tardinessFields.style.display = 'block';
        if (otherDeductionFields) otherDeductionFields.style.display = 'none';
        if (isTardinessField) isTardinessField.value = '1';
        if (deductionIdField) deductionIdField.value = 'tardiness';
        if (modalTitle) modalTitle.textContent = 'Edit Tardiness Deduction';
        
        // Toggle required attributes - remove from hidden fields, add to visible ones
        if (deductionIdSelect) deductionIdSelect.removeAttribute('required');
        if (deductionAmountOther) deductionAmountOther.removeAttribute('required');
        if (deductionAmountTardiness) deductionAmountTardiness.setAttribute('required', '');
        
        // Parse remarks to extract time if available
        const timeMatch = remarks.match(/Time of tardiness:\s*(\d{2}:\d{2}:\d{2})/);
        if (timeMatch) {
            const timeParts = timeMatch[1].split(':');
            document.getElementById('tardinessHoursInput').value = parseInt(timeParts[0]) || 0;
            document.getElementById('tardinessMinutesInput').value = parseInt(timeParts[1]) || 0;
            document.getElementById('tardinessSecondsInput').value = parseInt(timeParts[2]) || 0;
        }
        
        document.getElementById('deductionAmount').value = amount;
        
        // Load rates and recalculate
        setTimeout(() => {
            loadEmployeeHourlyRate(window.currentDeductionEmpId);
            calculateTardinessDeduction();
        }, 100);
    } else {
        // Show other deduction fields
        if (tardinessFields) tardinessFields.style.display = 'none';
        if (otherDeductionFields) otherDeductionFields.style.display = 'block';
        if (isTardinessField) isTardinessField.value = '0';
        if (deductionIdField) deductionIdField.value = deductionId;
        if (modalTitle) modalTitle.textContent = 'Edit Deduction';
        
        // Toggle required attributes - add to visible fields, remove from hidden ones
        if (deductionIdSelect) deductionIdSelect.setAttribute('required', '');
        if (deductionAmountOther) deductionAmountOther.setAttribute('required', '');
        if (deductionAmountTardiness) deductionAmountTardiness.removeAttribute('required');
        
        // Load deduction options and set selected value
        loadDeductionOptions();
        setTimeout(() => {
            const select = document.getElementById('deduction_id_select');
            if (select) {
                select.value = deductionId;
            }
            document.getElementById('deductionAmountOther').value = amount;
        }, 200);
    }
    document.getElementById('deductionSubmitButton').textContent = 'Save Changes';
    
    const editModal = new bootstrap.Modal(document.getElementById('addDeductionFormModal'));
    editModal.show();
}

function deleteEmployeeDeduction(edId, itemName) {
    if (confirm('Are you sure you want to remove "' + itemName + '" deduction from this employee?')) {
        const formData = new FormData();
        formData.append('delete_employee_deduction', edId);
        formData.append('employee_id', window.currentDeductionEmpId);
        formData.append('ajax', '1');

        fetch('employee_logs.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || 'Deduction removed successfully!', 'success');
                loadEmployeeDeductions(window.currentDeductionEmpId);
            } else {
                showToast(data.message || 'Error removing deduction', 'error');
            }
        })
        .catch(error => {
            console.error('Error deleting deduction:', error);
            showToast('Error removing deduction', 'error');
        });
    }
}

function submitDeductionForm(event) {
    event.preventDefault();
    const form = document.getElementById('addDeductionForm');
    const formData = new FormData(form);
    
    const isTardiness = document.getElementById('is_tardiness').value === '1';
    
    if (isTardiness) {
        // Handle tardiness deduction
        const hoursInput = document.getElementById('tardinessHoursInput');
        const minutesInput = document.getElementById('tardinessMinutesInput');
        const secondsInput = document.getElementById('tardinessSecondsInput');
        
        const hours = parseInt(hoursInput ? hoursInput.value : 0) || 0;
        const minutes = parseInt(minutesInput ? minutesInput.value : 0) || 0;
        const seconds = parseInt(secondsInput ? secondsInput.value : 0) || 0;
        
        // Convert to decimal hours
        const totalHours = hours + (minutes / 60) + (seconds / 3600);
        
        if (totalHours <= 0) {
            alert('Please enter hours of tardiness (at least 1 hour, minute, or second)');
            return false;
        }
        
        // Format time string for remarks
        const timeString = String(hours).padStart(2, '0') + ':' + 
                          String(minutes).padStart(2, '0') + ':' + 
                          String(seconds).padStart(2, '0');
        
        formData.append('is_tardiness', '1');
        formData.append('tardiness_hours', totalHours);
        formData.append('tardiness_time', timeString); // Send the time string for remarks
    } else {
        // Handle other deduction
        const deductionIdSelect = document.getElementById('deduction_id_select');
        const amountOther = document.getElementById('deductionAmountOther');
        
        if (!deductionIdSelect || !deductionIdSelect.value) {
            alert('Please select a deduction item');
            return false;
        }
        
        if (!amountOther || !amountOther.value || parseFloat(amountOther.value) <= 0) {
            alert('Please enter a valid deduction amount');
            return false;
        }
        
        // Set the deduction_id from the select
        formData.set('deduction_id', deductionIdSelect.value);
        formData.set('amount', amountOther.value);
        formData.set('is_tardiness', '0');
    }

    fetch('employee_logs.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message || 'Saved successfully', 'success');
            bootstrap.Modal.getInstance(document.getElementById('addDeductionFormModal')).hide();
            loadEmployeeDeductions(window.currentDeductionEmpId);
        } else {
            showToast(data.message || 'Error saving deduction', 'error');
        }
    })
    .catch(error => {
        console.error('Error saving deduction:', error);
        showToast('Error saving deduction', 'error');
    });

    return false;
}

function viewSalary(empId, empName, position) {
    // Safe element update helper
    const updateElement = (id, value, isValue = false) => {
        try {
            const el = document.getElementById(id);
            if (!el) {
                console.warn(`Element '${id}' not found in viewSalary`);
                return false;
            }
            if (isValue) {
                el.value = String(value || '');
            } else {
                if (typeof el.textContent !== 'undefined') {
                    el.textContent = String(value || '');
                } else {
                    console.warn(`Element '${id}' does not support textContent`);
                    return false;
                }
            }
            return true;
        } catch (error) {
            console.error(`Error updating element '${id}' in viewSalary:`, error);
            return false;
        }
    };
    
    updateElement('salaryModalTitle', 'Salary Information - ' + empName);
    updateElement('salaryEmpName', empName);
    updateElement('salaryPosition', position);
    
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const firstDay = year + '-' + month + '-01';
    const lastDay = year + '-' + month + '-' + new Date(year, now.getMonth() + 1, 0).getDate();
    updateElement('salaryDateFrom', firstDay, true);
    updateElement('salaryDateTo', lastDay, true);
    
    window.currentSalaryEmpId = empId;
    window.currentSalaryPosition = position;
    
    const salaryModal = document.getElementById('salaryModal');
    if (salaryModal) {
        // Store employee info as data attributes on the modal for persistence
        salaryModal.setAttribute('data-employee-id', empId);
        salaryModal.setAttribute('data-employee-position', position);
        
        const modal = new bootstrap.Modal(salaryModal);
        
        // Calculate salary after modal is fully shown and elements are rendered
        salaryModal.addEventListener('shown.bs.modal', function calculateAfterShow() {
            // Wait a bit more to ensure all elements are fully rendered
            setTimeout(function() {
                // Verify elements exist before calculating
                const requiredElements = ['totalHours', 'totalLate', 'totalUndertime', 'annualSalary', 'monthlyRate', 
                                         'weeklyRate', 'dailyRate', 'hourlyRate', 'grossSalary', 
                                         'totalDeductionsBox', 'netIncome', 'salaryDateFrom', 'salaryDateTo'];
                
                let allElementsExist = true;
                const missing = [];
                requiredElements.forEach(id => {
                    if (!document.getElementById(id)) {
                        allElementsExist = false;
                        missing.push(id);
                    }
                });
                
                if (allElementsExist) {
                    calculateSalary();
                } else {
                    console.warn('Some elements not ready yet, missing:', missing);
                    // Retry after a short delay
                    setTimeout(function() {
                        calculateSalary();
                    }, 200);
                }
            }, 100);
        }, { once: true });
        
        modal.show();
    } else {
        // Fallback: if modal doesn't exist, try to calculate anyway
        console.warn('Salary modal not found, calculating salary anyway');
        calculateSalary();
    }
}

// Retry counter to prevent infinite loops
let calculateSalaryRetryCount = 0;
const MAX_RETRY_ATTEMPTS = 5;

function calculateSalary() {
    // Check if modal exists and is accessible first
    const salaryModal = document.getElementById('salaryModal');
    if (!salaryModal) {
        console.error('Salary modal not found - cannot calculate salary');
        return;
    }
    
    // Try to get employee ID and position from global variables first
    let empId = window.currentSalaryEmpId;
    let position = window.currentSalaryPosition;
    
    // If global variables are missing, try to get from modal data attributes
    if (!empId || !position) {
        const modalEmpId = salaryModal.getAttribute('data-employee-id');
        const modalPosition = salaryModal.getAttribute('data-employee-position');
        
        if (modalEmpId && modalPosition) {
            empId = modalEmpId;
            position = modalPosition;
            // Restore global variables for consistency
            window.currentSalaryEmpId = empId;
            window.currentSalaryPosition = position;
            console.log('Recovered employee info from modal data attributes:', { empId, position });
        }
    }
    
    // Reset retry counter if we have valid data
    if (empId && position) {
        calculateSalaryRetryCount = 0;
    }
    if (!salaryModal) {
        console.error('Salary modal not found - cannot calculate salary');
        return;
    }
    
    // Check if modal is visible (in DOM and shown)
    const isModalVisible = salaryModal.classList.contains('show') || 
                          salaryModal.style.display !== 'none' ||
                          document.body.classList.contains('modal-open');
    
    if (!isModalVisible) {
        if (calculateSalaryRetryCount < MAX_RETRY_ATTEMPTS) {
            calculateSalaryRetryCount++;
            console.warn(`Salary modal is not visible yet - retry ${calculateSalaryRetryCount}/${MAX_RETRY_ATTEMPTS}`);
            // Modal might be opening, wait a bit and try again
            setTimeout(function() {
                if (salaryModal && salaryModal.classList.contains('show')) {
                    calculateSalary();
                }
            }, 100);
        } else {
            console.error('Max retry attempts reached. Modal may not be loading properly.');
        }
        return;
    }
    
    // Safe element getter
    const getElement = (id) => {
        const el = document.getElementById(id);
        if (!el) {
            console.warn(`Element with id '${id}' not found in salary modal`);
        }
        return el;
    };
    
    const dateFromEl = getElement('salaryDateFrom');
    const dateToEl = getElement('salaryDateTo');
    
    if (!dateFromEl || !dateToEl) {
        if (calculateSalaryRetryCount < MAX_RETRY_ATTEMPTS) {
            calculateSalaryRetryCount++;
            console.warn(`Salary date inputs not found - retry ${calculateSalaryRetryCount}/${MAX_RETRY_ATTEMPTS}`);
            setTimeout(function() {
                if (document.getElementById('salaryDateFrom') && document.getElementById('salaryDateTo')) {
                    calculateSalary();
                }
            }, 200);
        } else {
            console.error('Max retry attempts reached. Salary date inputs not found.');
        }
        return;
    }
    
    // Verify all required display elements exist before making API call
    const requiredElements = ['totalHours', 'totalLate', 'totalUndertime', 'annualSalary', 'monthlyRate', 
                             'weeklyRate', 'dailyRate', 'hourlyRate', 'grossSalary', 
                             'totalDeductionsBox', 'netIncome'];
    
    const missingElements = requiredElements.filter(id => !getElement(id));
    if (missingElements.length > 0) {
        if (calculateSalaryRetryCount < MAX_RETRY_ATTEMPTS) {
            calculateSalaryRetryCount++;
            console.warn(`Missing required elements (retry ${calculateSalaryRetryCount}/${MAX_RETRY_ATTEMPTS}):`, missingElements);
            // Retry after a short delay
            setTimeout(function() {
                calculateSalary();
            }, 200);
        } else {
            console.error('Max retry attempts reached. Missing elements:', missingElements);
        }
        return;
    }
    
    // Reset retry counter on successful validation
    calculateSalaryRetryCount = 0;
    
    const dateFrom = dateFromEl.value;
    const dateTo = dateToEl.value;
    
    if (!dateFrom || !dateTo) {
        console.warn('Please select both Date From and Date To');
        return;
    }
    if (dateFrom > dateTo) {
        console.warn('Date From must be before or equal to Date To');
        return;
    }
    
    // Validate that employee ID and position are available
    if (!empId || !position) {
        console.error('Cannot calculate salary: missing employee ID or position', {
            empId: empId,
            position: position
        });
        // Show user-friendly error message
        const salaryModal = document.getElementById('salaryModal');
        if (salaryModal) {
            const errorMsg = document.getElementById('netIncome') || document.getElementById('grossSalary');
            if (errorMsg) {
                errorMsg.textContent = 'Error: Missing employee information. Please close and reopen the salary modal.';
                errorMsg.style.color = 'red';
            }
        }
        return;
    }
    
    fetch('calculate_salary_api.php?employee_id=' + encodeURIComponent(empId)
        + '&position=' + encodeURIComponent(position)
        + '&date_from=' + encodeURIComponent(dateFrom)
        + '&date_to=' + encodeURIComponent(dateTo))
        .then(response => {
            // Check if response is JSON first to extract error message
            const contentType = response.headers.get('content-type');
            const isJson = contentType && contentType.includes('application/json');
            
            if (!response.ok) {
                // Try to extract error message from JSON response
                if (isJson) {
                    return response.json().then(data => {
                        const errorMsg = data.message || 'Network response was not ok';
                        console.error('API error response:', data);
                        throw new Error(errorMsg);
                    });
                } else {
                    return response.text().then(text => {
                        console.error('Non-JSON error response received:', text);
                        throw new Error('Server error: ' + (text || 'Network response was not ok'));
                    });
                }
            }
            
            // Success response - check if it's JSON
            if (!isJson) {
                return response.text().then(text => {
                    console.error('Non-JSON response received:', text);
                    throw new Error('Server returned non-JSON response. Please check the console for details.');
                });
            }
            return response.json();
        })
        .then(data => {
            // Wrap everything in try-catch to catch any null reference errors
            try {
                if (data.success) {
                    // First, verify modal is still visible and all elements exist
                    const salaryModal = document.getElementById('salaryModal');
                    if (!salaryModal) {
                        console.error('Salary modal was removed from DOM during calculation');
                        return;
                    }
                    
                    // Verify modal is still shown
                    if (!salaryModal.classList.contains('show') && !document.body.classList.contains('modal-open')) {
                        console.warn('Salary modal was closed during calculation');
                        return;
                    }
                
                const totalHours = parseFloat(data.total_hours) || 0;
                const totalLate = parseFloat(data.total_late) || 0;
                const totalUndertime = parseFloat(data.total_undertime) || 0;
                // Overtime removed - now tracked as COC (Credits of Compensation) in employee profile
                
                // Update elements with null checks to prevent errors
                // Use a more defensive approach - check element existence immediately before use
                const updateElement = (id, value) => {
                    // Always return early if modal doesn't exist
                    if (!salaryModal) {
                        console.error(`[calculateSalary] Modal doesn't exist when updating '${id}'`);
                        return false;
                    }
                    
                    try {
                        // Use querySelector within modal for more reliable access
                        let el = salaryModal.querySelector('#' + id);
                        if (!el) {
                            // Fallback to global getElementById
                            el = document.getElementById(id);
                        }
                        
                        // If still not found, log and return
                        if (!el) {
                            console.error(`[calculateSalary] Element '${id}' not found anywhere`);
                            // Log available elements for debugging
                            const allIds = Array.from(salaryModal.querySelectorAll('[id]')).map(e => e.id);
                            console.error(`[calculateSalary] Available IDs in modal:`, allIds.join(', '));
                            return false;
                        }
                        
                        // Verify element has textContent property
                        if (typeof el.textContent === 'undefined') {
                            console.error(`[calculateSalary] Element '${id}' doesn't support textContent`);
                            return false;
                        }
                        
                        // Get element one more time right before setting (defensive)
                        el = salaryModal.querySelector('#' + id) || document.getElementById(id);
                        
                        // Final null check
                        if (!el) {
                            console.error(`[calculateSalary] Element '${id}' is null before setting`);
                            return false;
                        }
                        
                        // Set value - this is where the error would occur if el is null
                        // Final null check right before assignment - get element one more time
                        el = salaryModal.querySelector('#' + id) || document.getElementById(id);
                        
                        if (!el) {
                            console.error(`[calculateSalary] Element '${id}' is null at assignment time`);
                            return false;
                        }
                        
                        // Verify element is still in DOM
                        if (!document.body.contains(el) && !salaryModal.contains(el)) {
                            console.error(`[calculateSalary] Element '${id}' not in DOM`);
                            return false;
                        }
                        
                        const stringValue = String(value || '0.00');
                        
                        // Final safety check - verify element and property exist
                        if (!el || typeof el.textContent === 'undefined') {
                            console.error(`[calculateSalary] Element '${id}' invalid or no textContent property`);
                            return false;
                        }
                        
                        // Set the value with final try-catch
                        try {
                            el.textContent = stringValue;
                            return true;
                        } catch (finalError) {
                            console.error(`[calculateSalary] Final error setting textContent on '${id}':`, finalError);
                            console.error(`[calculateSalary] Element at error time:`, el);
                            return false;
                        }
                        
                    } catch (error) {
                        // Catch any error including "Cannot set properties of null"
                        console.error(`[calculateSalary] Exception updating '${id}':`, error.message);
                        console.error(`[calculateSalary] Error stack:`, error.stack);
                        console.error(`[calculateSalary] Attempted value:`, value);
                        
                        // Try to find the element one more time
                        try {
                            const el = salaryModal.querySelector('#' + id) || document.getElementById(id);
                            if (el && typeof el.textContent !== 'undefined') {
                                console.log(`[calculateSalary] Element '${id}' exists, retrying...`);
                                el.textContent = String(value || '0.00');
                                return true;
                            }
                        } catch (retryError) {
                            console.error(`[calculateSalary] Retry also failed for '${id}':`, retryError);
                        }
                        
                        return false;
                    }
                };
                
                // Safely format numbers - ensure they're valid numbers before calling toFixed
                const safeFormat = (value, decimals = 2) => {
                    const num = parseFloat(value);
                    if (isNaN(num)) {
                        console.warn('Invalid number value:', value);
                        return '0.00';
                    }
                    return num.toFixed(decimals);
                };
                
                // Update elements with error tracking - wrap each call to catch which one fails
                try {
                    updateElement('totalHours', safeFormat(totalHours));
                } catch (e) { console.error('[calculateSalary] Error updating totalHours:', e); }
                const totalLateNum = parseFloat(totalLate) || 0;
                const totalUndertimeNum = parseFloat(totalUndertime) || 0;
                try {
                    updateElement('totalLate', totalLateNum > 0 ? safeFormat(totalLateNum) + ' h / ' + hoursToDayFraction(totalLateNum).toFixed(3) + ' d' : '0.00');
                } catch (e) { console.error('[calculateSalary] Error updating totalLate:', e); }
                try {
                    updateElement('totalUndertime', totalUndertimeNum > 0 ? safeFormat(totalUndertimeNum) + ' h / ' + hoursToDayFraction(totalUndertimeNum).toFixed(3) + ' d' : '0.00');
                } catch (e) { console.error('[calculateSalary] Error updating totalUndertime:', e); }
                try {
                    // Use annual_salary directly from API response, fallback to monthly_rate * 12 for backward compatibility
                    const annualSalary = parseFloat(data.annual_salary || 0) || (parseFloat(data.monthly_rate || 0) * 12);
                    updateElement('annualSalary', safeFormat(annualSalary));
                } catch (e) { console.error('[calculateSalary] Error updating annualSalary:', e); }
                try {
                    // Monthly rate = annual_salary / 12 (from API response)
                    const monthlyRate = parseFloat(data.monthly_rate || 0);
                    updateElement('monthlyRate', safeFormat(monthlyRate));
                } catch (e) { console.error('[calculateSalary] Error updating monthlyRate:', e); }
                try {
                    updateElement('weeklyRate', safeFormat(data.weekly_rate || 0));
                } catch (e) { console.error('[calculateSalary] Error updating weeklyRate:', e); }
                try {
                    updateElement('dailyRate', safeFormat(data.daily_rate));
                } catch (e) { console.error('[calculateSalary] Error updating dailyRate:', e); }
                try {
                    updateElement('hourlyRate', safeFormat(data.hourly_rate));
                } catch (e) { console.error('[calculateSalary] Error updating hourlyRate:', e); }
                // All deductions (tardiness, undertime, absence) are now added manually in the deduction modal
                // They will appear in the additional deductions section
                
                // Total Deductions should only show the sum of deduction amounts (not additions)
                const totalDeductionsVal = parseFloat(data.total_deductions_only || data.additional_deductions_total || 0) || 0;
                const totalAdditions = parseFloat(data.total_additions || 0) || 0;
                
                window.currentSalaryCalc = {
                    employeeId: window.currentSalaryEmpId,
                    position: window.currentSalaryPosition,
                    year: data.year || dateFrom.split('-')[0],
                    month: data.month || dateFrom.split('-')[1],
                    period: 'full',
                    dateFrom: dateFrom,
                    dateTo: dateTo,
                    grossSalary: parseFloat(data.gross_salary) || 0,
                    adjustedGrossSalary: parseFloat(data.adjusted_gross_salary || data.gross_salary) || 0,  // Gross + Additions
                    netIncome: parseFloat(data.net_income) || 0,
                    // Total Deductions: sum of only deduction amounts (positive)
                    totalDeductions: totalDeductionsVal,
                    totalAdditions: totalAdditions,
                    additionalDeductions: data.additional_deductions || [],
                    hourlyRate: parseFloat(data.hourly_rate) || 0, // Store hourly rate for calculations
                    dailyRate: parseFloat(data.daily_rate) || 0 // Store daily rate for absence calculation
                };

                const additionalContainer = document.getElementById('additionalDeductionsContainer');
                if (additionalContainer) {
                    additionalContainer.innerHTML = '';
                    
                    if (data.additional_deductions && data.additional_deductions.length > 0) {
                        data.additional_deductions.forEach(deduction => {
                            const amount = parseFloat(deduction.amount);
                            const isAddition = deduction.type === 'Add' || deduction.dr_cr === 'Cr';
                            const color = isAddition ? 'text-success' : 'text-danger';
                            const sign = isAddition ? '+' : '-';
                            
                            const deductionDiv = document.createElement('div');
                            deductionDiv.className = 'd-flex justify-content-between mb-2';
                            deductionDiv.innerHTML = `
                                <div>
                                    <span class="text-muted">${deduction.item_name}</span>
                                    <p class="small text-muted mb-0">${deduction.type} (${deduction.dr_cr})</p>
                                </div>
                                <span class="fw-semibold ${color}">${sign}₱ ${amount.toFixed(2)}</span>
                            `;
                            additionalContainer.appendChild(deductionDiv);
                        });
                    }
                }
                
                try {
                    updateElement('grossSalary', safeFormat(data.gross_salary));
                } catch (e) { console.error('[calculateSalary] Error updating grossSalary:', e); }
                
                // Total Deductions: sum of only deduction amounts (positive value)
                const totalDeductions = parseFloat(data.total_deductions_only || data.additional_deductions_total || 0) || 0;
                
                try {
                    updateElement('totalDeductionsBox', '₱ ' + safeFormat(totalDeductions));
                } catch (e) { console.error('[calculateSalary] Error updating totalDeductionsBox:', e); }
                try {
                    updateElement('netIncome', safeFormat(data.net_income));
                } catch (e) { console.error('[calculateSalary] Error updating netIncome:', e); }
                } else {
                    console.error('API returned error:', data);
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
            } catch (error) {
                // Catch any error in the entire data processing block
                console.error('[calculateSalary] Fatal error processing salary data:', error);
                console.error('[calculateSalary] Error message:', error.message);
                console.error('[calculateSalary] Error stack:', error.stack);
                
                // Try to show a user-friendly error
                const salaryModal = document.getElementById('salaryModal');
                if (salaryModal && salaryModal.classList.contains('show')) {
                    alert('Error calculating salary. Please check the console for details and try again.');
                }
            }
        })
        .catch(error => {
            console.error('Error calculating salary:', error);
            console.error('Error stack:', error.stack);
            console.error('Error details:', {
                message: error.message,
                name: error.name,
                stack: error.stack
            });
            
            let errorMessage = 'Error calculating salary';
            if (error.message) {
                errorMessage += ': ' + error.message;
            } else if (error.toString && error.toString() !== '[object Object]') {
                errorMessage += ': ' + error.toString();
            } else {
                errorMessage += ': Unknown error occurred';
            }
            
            if (error.message && error.message.includes('JSON')) {
                errorMessage += '\n\nThis usually means there was a PHP error. Please check the server logs or contact the administrator.';
            }
            
            // Show error in console and alert
            alert(errorMessage);
        });
}

function generatePayslip() {
    const empName = document.getElementById('salaryEmpName').textContent || '-';
    const position = document.getElementById('salaryPosition').textContent || '-';
    const dateFromInput = document.getElementById('salaryDateFrom') && document.getElementById('salaryDateFrom').value;
    const dateToInput = document.getElementById('salaryDateTo') && document.getElementById('salaryDateTo').value;

    if (!dateFromInput || !dateToInput) {
        alert('Please select Date From and Date To first.');
        return;
    }

    if (!window.currentSalaryCalc || window.currentSalaryCalc.employeeId !== window.currentSalaryEmpId) {
        alert('Please recalculate salary first before generating payslip.');
        return;
    }

    // Format for display: YYYY-MM-DD -> Mon-DD-YYYY
    const formatDisplayDate = (ymd) => {
        const [y, m, d] = ymd.split('-');
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return `${monthNames[parseInt(m) - 1]}-${d}-${y}`;
    };
    const firstDay = formatDisplayDate(dateFromInput);
    const lastDay = formatDisplayDate(dateToInput);
    const [year, month] = dateFromInput.split('-');

    const gross = parseFloat(window.currentSalaryCalc.grossSalary || 0);
    const totalDeductions = parseFloat(window.currentSalaryCalc.totalDeductions || 0);
    const net = parseFloat(window.currentSalaryCalc.netIncome || 0);
    const adjustedGross = parseFloat(window.currentSalaryCalc.adjustedGrossSalary || gross);

    const firstQuincena = (net / 2).toFixed(2);
    const secondQuincena = (net - parseFloat(firstQuincena)).toFixed(2);

    // Separate compensations and deductions
    const compensations = [];
    const deductions = [];
    const compensationMap = {}; // To handle duplicate names

    // Add base salary as compensation only if > 0
    if (gross > 0) {
        compensationMap['SALARY'] = gross;
    }

    const addl = window.currentSalaryCalc.additionalDeductions || [];
    addl.forEach(deduction => {
        const amount = parseFloat(deduction.amount) || 0;
        if (amount === 0) return; // Skip zero amounts
        
        const isAddition = deduction.type === 'Add' || deduction.dr_cr === 'Cr';
        const itemName = deduction.item_name.toUpperCase();
        
        if (isAddition) {
            // If item name already exists, combine amounts
            if (compensationMap[itemName]) {
                compensationMap[itemName] += amount;
            } else {
                compensationMap[itemName] = amount;
            }
        } else {
            deductions.push({ name: itemName, amount: amount });
        }
    });

    // Convert map to array
    Object.keys(compensationMap).forEach(name => {
        compensations.push({ name: name, amount: compensationMap[name] });
    });

    // Calculate total compensations from all compensation items
    const totalCompensations = compensations.reduce((sum, item) => sum + item.amount, 0);
    
    // GROSS AMOUNT should equal TOTAL COMPENSATIONS
    const finalGrossAmount = totalCompensations;

    // Generate unique IDs
    const payrollId = `PAYROLL-01-${year}-${month}-${String(Math.floor(Math.random() * 10000)).padStart(4, '0')}`;
    const internalId = crypto.randomUUID ? crypto.randomUUID() : 'ID:' + Date.now().toString(36);

    // Format employee name (LAST, FIRST MIDDLE)
    const nameParts = empName.split(' ');
    const formattedName = nameParts.length > 1 
        ? `${nameParts[nameParts.length - 1].toUpperCase()}, ${nameParts.slice(0, -1).join(' ').toUpperCase()}`
        : empName.toUpperCase();

    // Build HTML payslip with proper image paths
    // Calculate base path - get the root of the application
    let pathname = window.location.pathname;
    let basePath = '';
    
    // Remove /admin or /faculty from path to get base
    if (pathname.includes('/admin')) {
        basePath = pathname.substring(0, pathname.indexOf('/admin'));
    } else if (pathname.includes('/faculty')) {
        basePath = pathname.substring(0, pathname.indexOf('/faculty'));
    } else {
        // If no subdirectory, try to get from path segments
        const segments = pathname.split('/').filter(s => s);
        if (segments.length > 0) {
            // Assume the first segment is the base (e.g., /SAFE_SYSTEM/FP)
            basePath = '/' + segments[0];
        }
    }
    
    // If basePath is empty, use root
    if (!basePath || basePath === '/') {
        basePath = '';
    }
    
    // Ensure basePath doesn't end with /
    basePath = basePath.replace(/\/$/, '');
    
    const baseUrl = window.location.origin + basePath;
    const logoPath = baseUrl + '/assets/logo.png';
    const sealPath = baseUrl + '/assets/img/seal.png';
    
    const payslipHTML = `
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payslip - ${formattedName}</title>
    <style>
        @media print {
            @page { margin: 0.5cm; size: letter; }
            body { margin: 0; }
            .no-print { display: none !important; }
        }
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: white;
            position: relative;
        }
        .payslip-container {
            position: relative;
            background: white;
            z-index: 10;
        }
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            opacity: 0.03;
            z-index: 1;
            pointer-events: none;
            text-align: center;
            font-size: 120px;
            font-weight: bold;
            color: #000;
            line-height: 1.2;
            white-space: nowrap;
            width: 100%;
        }
        .watermark-seal {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.03;
            z-index: 1;
            pointer-events: none;
            max-width: 600px;
            max-height: 600px;
        }
        .header {
            display: flex;
            margin-bottom: 20px;
            align-items: center;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            position: relative;
            z-index: 10;
            background: white;
        }
        .logo {
            height: 72px;
            margin-right: 15px;
        }
        .header-text {
            flex: 1;
        }
        .university-name {
            font-size: 18px;
            font-weight: bold;
            margin: 0;
        }
        .university-location {
            font-size: 14px;
            color: #666;
            margin: 0;
        }
        .payslip-title {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            margin: 20px 0;
            position: relative;
            z-index: 10;
            background: white;
        }
        .employee-info {
            margin-bottom: 20px;
            position: relative;
            z-index: 10;
            background: white;
        }
        .employee-info p {
            margin: 5px 0;
            font-size: 14px;
        }
        .employee-info strong {
            font-weight: bold;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            position: relative;
            z-index: 10;
            background: white;
        }
        .items-table th,
        .items-table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .items-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .items-table td:last-child {
            text-align: right;
        }
        .summary {
            margin-top: 20px;
            margin-bottom: 20px;
            position: relative;
            z-index: 10;
            background: white;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #ddd;
        }
        .summary-row.total {
            font-weight: bold;
            border-bottom: 2px solid #333;
            padding-top: 10px;
        }
        .summary-label {
            flex: 1;
        }
        .summary-value {
            text-align: right;
            min-width: 150px;
        }
        .remarks {
            margin-top: 30px;
            margin-bottom: 20px;
            position: relative;
            z-index: 10;
            background: white;
        }
        .remarks h4 {
            margin-bottom: 10px;
            font-size: 14px;
        }
        .remarks p {
            margin: 5px 0;
            font-size: 12px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 11px;
            color: #666;
            position: relative;
            z-index: 10;
            background: white;
        }
        .footer p {
            margin: 3px 0;
        }
        .footer-ids {
            margin-top: 10px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="watermark">WESTERN PHILIPPINES UNIVERSITY<br>1910</div>
    <img src="${sealPath}" alt="WPU Seal" class="watermark-seal" onerror="this.style.display='none'">
    <div class="payslip-container">
        <div class="header">
            <img src="${logoPath}" alt="WPU Logo" class="logo" onerror="this.style.display='none'">
            <div class="header-text">
                <p class="university-name">Western Philippines University</p>
                <p class="university-location">Aborlan, Palawan</p>
            </div>
        </div>

        <div class="payslip-title">PAYSLIP</div>

        <div class="employee-info">
            <p><strong>Employee Name:</strong> ${formattedName}</p>
            <p><strong>Position:</strong> ${position.toUpperCase()}</p>
            <p><strong>Payslip for:</strong> ${firstDay} to ${lastDay}</p>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                ${compensations.map(c => `<tr><td>${c.name}</td><td>${c.amount.toFixed(2)}</td></tr>`).join('')}
                ${deductions.map(d => `<tr><td>${d.name}</td><td>${d.amount.toFixed(2)}</td></tr>`).join('')}
            </tbody>
        </table>

        <div class="summary">
            <div class="summary-row">
                <span class="summary-label">TOTAL COMPENSATIONS:</span>
                <span class="summary-value">${totalCompensations.toFixed(2)}</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">TOTAL LESS:</span>
                <span class="summary-value">0.00</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">GROSS AMOUNT:</span>
                <span class="summary-value">${finalGrossAmount.toFixed(2)}</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">TOTAL DEDUCTIONS:</span>
                <span class="summary-value">${totalDeductions.toFixed(2)}</span>
            </div>
            <div class="summary-row total">
                <span class="summary-label">NET AMOUNT:</span>
                <span class="summary-value">${net.toFixed(2)}</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">FIRST QUINCENA:</span>
                <span class="summary-value">${firstQuincena}</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">SECOND QUINCENA:</span>
                <span class="summary-value">${secondQuincena}</span>
            </div>
        </div>

        <div class="remarks">
            <h4>Remarks:</h4>
        </div>

        <div class="footer">
            <p>This payslip is generated by WPU HRIS.</p>
            <p>You may direct your questions/clarifications to the HRMD Office.</p>
            <p><strong>Email:</strong> hrmo@wpu.edu.ph</p>
            <p><strong>Mobile:</strong> +63 910 288 4099</p>
            <div class="footer-ids">
                <p>${payrollId}</p>
                <p>ID: ${internalId}</p>
            </div>
        </div>
    </div>
</body>
</html>`;

    // Extract body content for modal preview
    // Use DOMParser to properly parse the HTML document
    const parser = new DOMParser();
    const doc = parser.parseFromString(payslipHTML, 'text/html');
    const bodyContent = doc.body || doc.documentElement;
    const styles = doc.querySelector('style');
    
    // Create preview HTML with styles safely embedded in the modal
    const previewHTML = `
        <style>
            ${styles ? styles.innerHTML : ''}
            #payslipContent {
                overflow: auto;
                max-height: 70vh;
                background: white;
            }
            #payslipContent .payslip-container {
                min-height: auto;
            }
            #payslipContent body {
                padding: 0 !important;
                margin: 0 !important;
            }
        </style>
        ${bodyContent.innerHTML}
    `;
    
    document.getElementById('payslipContent').innerHTML = previewHTML;
    window.currentPayslipHTML = payslipHTML; // Store full HTML for printing
    const modal = new bootstrap.Modal(document.getElementById('payslipModal'));
    modal.show();
}

function printPayslip() {
    const payslipHTML = window.currentPayslipHTML || document.getElementById('payslipContent').innerHTML;
    const win = window.open('', '_blank');
    win.document.write(payslipHTML);
    win.document.close();
    
    // Wait for images to load before printing
    win.onload = function() {
        setTimeout(function() {
            win.print();
        }, 250);
    };
}

function openBatchCalculationModal() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const lastDay = String(new Date(year, today.getMonth() + 1, 0).getDate()).padStart(2, '0');
    const fromEl = document.getElementById('batchSalaryDateFrom');
    const toEl = document.getElementById('batchSalaryDateTo');
    if (fromEl) {
        fromEl.value = `${year}-${month}-01`;
    }
    if (toEl) {
        toEl.value = `${year}-${month}-${lastDay}`;
    }
    const pageEs = document.getElementById('employment_status');
    const batchEs = document.getElementById('batchSalaryEmploymentStatus');
    if (pageEs && batchEs) {
        batchEs.value = pageEs.value || '';
    }
    const reqAtt = document.getElementById('batchSalaryRequireAttendance');
    if (reqAtt) {
        reqAtt.checked = true;
    }
    const modal = new bootstrap.Modal(document.getElementById('batchCalculationModal'));
    modal.show();
}

const BATCH_SALARY_PAGE_SIZE = 20;
let batchSalaryDataFull = [];
let batchSalaryCurrentPage = 1;

function calculateBatchSalaries() {
    const dateFrom = document.getElementById('batchSalaryDateFrom') ? document.getElementById('batchSalaryDateFrom').value : '';
    const dateTo = document.getElementById('batchSalaryDateTo') ? document.getElementById('batchSalaryDateTo').value : '';
    if (!dateFrom || !dateTo) {
        alert('Please select a start date and end date');
        return;
    }
    if (dateFrom > dateTo) {
        alert('Start date must be on or before the end date');
        return;
    }
    const tbody = document.getElementById('batchSalaryTableBody');
    tbody.innerHTML = '<tr><td colspan="13" class="text-center text-muted py-4">Calculating salaries...</td></tr>';
    document.getElementById('batchSalaryPagination').classList.add('d-none');
    
    const employmentStatus = document.getElementById('batchSalaryEmploymentStatus') ? document.getElementById('batchSalaryEmploymentStatus').value : '';
    const requireAttendance = document.getElementById('batchSalaryRequireAttendance') ? document.getElementById('batchSalaryRequireAttendance').checked : true;
    const esParam = employmentStatus ? `&employment_status=${encodeURIComponent(employmentStatus)}` : '';
    const reqParam = `&require_attendance=${requireAttendance ? '1' : '0'}`;
    const dateParam = `date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}`;
    // Use batch API - single request (same formula as single calc with custom range)
    const url = `batch_calculate_salary_api.php?${dateParam}${esParam}${reqParam}`;
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (!data.success || !data.salaries) {
                tbody.innerHTML = '<tr><td colspan="13" class="text-center text-danger py-4">' + (data.message || 'Failed to calculate salaries') + '</td></tr>';
                return;
            }
            
            const salaryData = data.salaries.map(s => ({
                empId: s.employee_id,
                fullName: s.fullName || '',
                position: s.position || '',
                totalHours: parseFloat(s.total_hours) || 0,
                totalLate: parseFloat(s.total_late) || 0,
                totalUndertime: parseFloat(s.total_undertime) || 0,
                totalAbsences: parseFloat(s.total_absences) || 0,
                grossSalary: parseFloat(s.gross_salary) || 0,
                tardinessDeduction: parseFloat(s.tardiness_deduction) || 0,
                totalDeductions: parseFloat(s.total_deductions) || 0,
                netIncome: parseFloat(s.net_income) || 0,
                hasError: !!s.error,
                errorMessage: s.error || ''
            }));
            
            batchSalaryDataFull = salaryData;
            batchSalaryCurrentPage = 1;
            displayBatchSalaries(salaryData);
        })
        .catch(error => {
            console.error('Batch salary calculation error:', error);
            tbody.innerHTML = '<tr><td colspan="13" class="text-center text-danger py-4">Error: ' + (error.message || 'Failed to calculate salaries') + '</td></tr>';
        });
}

function displayBatchSalaries(salaryData) {
    const tbody = document.getElementById('batchSalaryTableBody');
    tbody.innerHTML = '';
    
    if (salaryData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="13" class="text-center text-muted py-4">No salary data available</td></tr>';
        document.getElementById('batchSalaryPagination').classList.add('d-none');
        return;
    }
    
    // Compute totals from ALL data (not just current page)
    let totalGross = 0;
    let totalDeductions = 0;
    let totalNet = 0;
    let totalHoursSum = 0;
    let totalHoursInDaysSum = 0;
    let totalLateSum = 0;
    let totalUndertimeSum = 0;
    let totalTardinessLateInDaysSum = 0;
    let totalAbsenceInDaysSum = 0;
    let totalTardinessDeduction = 0;
    
    salaryData.forEach(salary => {
        const totalDed = salary.totalDeductions || 0;
        totalGross += salary.grossSalary || 0;
        totalDeductions += totalDed;
        totalNet += salary.netIncome || 0;
        totalHoursSum += salary.totalHours || 0;
        totalLateSum += salary.totalLate || 0;
        totalUndertimeSum += salary.totalUndertime || 0;
        totalTardinessDeduction += salary.tardinessDeduction || 0;
        const hoursInDays = salary.totalHours > 0 ? hoursToDayFraction(salary.totalHours) : 0;
        totalHoursInDaysSum += hoursInDays;
        const tardinessLateHours = (salary.totalLate || 0) + (salary.totalUndertime || 0);
        totalTardinessLateInDaysSum += tardinessLateHours > 0 ? hoursToDayFraction(tardinessLateHours) : 0;
        const absentHours = (salary.totalAbsences || 0) * 8;
        totalAbsenceInDaysSum += absentHours > 0 ? hoursToDayFraction(absentHours) : 0;
    });
    
    // Paginate: show only current page rows
    const totalPages = Math.ceil(salaryData.length / BATCH_SALARY_PAGE_SIZE) || 1;
    const page = Math.min(Math.max(1, batchSalaryCurrentPage), totalPages);
    batchSalaryCurrentPage = page;
    const startIdx = (page - 1) * BATCH_SALARY_PAGE_SIZE;
    const endIdx = Math.min(startIdx + BATCH_SALARY_PAGE_SIZE, salaryData.length);
    const pageData = salaryData.slice(startIdx, endIdx);
    
    pageData.forEach(salary => {
        const totalDed = salary.totalDeductions || 0;
        const hoursInDays = salary.totalHours > 0 ? hoursToDayFraction(salary.totalHours) : 0;
        const tardinessLateHours = (salary.totalLate || 0) + (salary.totalUndertime || 0);
        const tardinessLateInDays = tardinessLateHours > 0 ? hoursToDayFraction(tardinessLateHours) : 0;
        const absentHours = (salary.totalAbsences || 0) * 8;
        const absenceInDays = absentHours > 0 ? hoursToDayFraction(absentHours) : 0;
        
        const row = document.createElement('tr');
        row.className = salary.hasError ? 'table-warning' : '';
        row.innerHTML = `
            <td style="white-space: nowrap;">${salary.empId}</td>
            <td class="fw-medium" style="white-space: nowrap;">${salary.fullName}</td>
            <td style="white-space: nowrap; font-size: 0.875rem;">${salary.position}</td>
            <td class="text-end text-primary" style="white-space: nowrap;">${salary.totalHours.toFixed(2)}</td>
            <td class="text-end text-primary" style="white-space: nowrap;">${hoursInDays.toFixed(3)}</td>
            <td class="text-end text-danger" style="white-space: nowrap;">${salary.totalLate.toFixed(2)}</td>
            <td class="text-end text-warning" style="white-space: nowrap;">${salary.totalUndertime.toFixed(2)}</td>
            <td class="text-end text-danger" style="white-space: nowrap;">${tardinessLateInDays.toFixed(3)}</td>
            <td class="text-end text-secondary" style="white-space: nowrap;">${absenceInDays.toFixed(3)}</td>
            <td class="text-end text-primary fw-semibold" style="white-space: nowrap;">₱${salary.grossSalary.toFixed(2)}</td>
            <td class="text-end text-danger" style="white-space: nowrap;">₱${(salary.tardinessDeduction || 0).toFixed(2)}</td>
            <td class="text-end text-danger fw-semibold" style="white-space: nowrap;">₱${totalDed.toFixed(2)}</td>
            <td class="text-end text-success fw-semibold" style="white-space: nowrap; background-color: #d1e7dd;">₱${salary.netIncome.toFixed(2)}</td>
        `;
        tbody.appendChild(row);
    });
    
    // Total row (always shows aggregate of ALL data)
    const totalRow = document.createElement('tr');
    totalRow.className = 'table-secondary fw-bold';
    totalRow.innerHTML = `
        <td colspan="3" class="text-end fw-bold">TOTAL</td>
        <td class="text-end text-primary fw-bold" style="white-space: nowrap;">${totalHoursSum.toFixed(2)}</td>
        <td class="text-end text-primary fw-bold" style="white-space: nowrap;">${totalHoursInDaysSum.toFixed(3)}</td>
        <td class="text-end text-danger fw-bold" style="white-space: nowrap;">${totalLateSum.toFixed(2)}</td>
        <td class="text-end text-warning fw-bold" style="white-space: nowrap;">${totalUndertimeSum.toFixed(2)}</td>
        <td class="text-end text-danger fw-bold" style="white-space: nowrap;">${totalTardinessLateInDaysSum.toFixed(3)}</td>
        <td class="text-end text-secondary fw-bold" style="white-space: nowrap;">${totalAbsenceInDaysSum.toFixed(3)}</td>
        <td class="text-end text-primary fw-bold" style="white-space: nowrap;">₱${totalGross.toFixed(2)}</td>
        <td class="text-end text-danger fw-bold" style="white-space: nowrap;">₱${totalTardinessDeduction.toFixed(2)}</td>
        <td class="text-end text-danger fw-bold" style="white-space: nowrap;">₱${totalDeductions.toFixed(2)}</td>
        <td class="text-end text-success fw-bold" style="white-space: nowrap; background-color: #d1e7dd;">₱${totalNet.toFixed(2)}</td>
    `;
    tbody.appendChild(totalRow);
    
    // Pagination controls
    const paginationEl = document.getElementById('batchSalaryPagination');
    const pageInfoEl = document.getElementById('batchSalaryPageInfo');
    const controlsEl = document.getElementById('batchSalaryPaginationControls');
    
    if (salaryData.length > BATCH_SALARY_PAGE_SIZE) {
        paginationEl.classList.remove('d-none');
        paginationEl.classList.add('d-flex');
        pageInfoEl.textContent = `Showing ${startIdx + 1}–${endIdx} of ${salaryData.length}`;
        
        controlsEl.innerHTML = '';
        const addPageBtn = (label, pageNum, disabled) => {
            const li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (pageNum === page ? ' active' : '');
            const a = document.createElement('a');
            a.className = 'page-link';
            a.href = '#';
            a.textContent = label;
            if (!disabled) {
                a.onclick = (e) => { e.preventDefault(); batchSalaryCurrentPage = pageNum; displayBatchSalaries(batchSalaryDataFull); };
            }
            li.appendChild(a);
            controlsEl.appendChild(li);
        };
        
        addPageBtn('Previous', page - 1, page <= 1);
        const maxVisible = 5;
        let fromPage = Math.max(1, page - Math.floor(maxVisible / 2));
        let toPage = Math.min(totalPages, fromPage + maxVisible - 1);
        if (toPage - fromPage < maxVisible - 1) fromPage = Math.max(1, toPage - maxVisible + 1);
        for (let p = fromPage; p <= toPage; p++) {
            addPageBtn(String(p), p, false);
        }
        addPageBtn('Next', page + 1, page >= totalPages);
    } else {
        paginationEl.classList.add('d-none');
        paginationEl.classList.remove('d-flex');
    }
}


function exportBatchSalaries() {
    if (!batchSalaryDataFull || batchSalaryDataFull.length === 0) {
        alert('No salary data to export. Please calculate salaries first.');
        return;
    }
    
    let csv = 'Safe Employee ID,Full Name,Position,Total Hours,HOURS (DAY),Tardiness Hours,Undertime Hours,Tardiness/Late (Days),Absence (Days),Gross Salary,Tardiness Deduction,Total Deductions,Net Income\n';
    
    batchSalaryDataFull.forEach(salary => {
        const hoursInDays = salary.totalHours > 0 ? hoursToDayFraction(salary.totalHours) : 0;
        const tardinessLateHours = (salary.totalLate || 0) + (salary.totalUndertime || 0);
        const tardinessLateInDays = tardinessLateHours > 0 ? hoursToDayFraction(tardinessLateHours) : 0;
        const absentHours = (salary.totalAbsences || 0) * 8;
        const absenceInDays = absentHours > 0 ? hoursToDayFraction(absentHours) : 0;
        const values = [
            salary.empId || '',
            salary.fullName || '',
            salary.position || '',
            (salary.totalHours || 0).toFixed(2),
            hoursInDays.toFixed(3),
            (salary.totalLate || 0).toFixed(2),
            (salary.totalUndertime || 0).toFixed(2),
            tardinessLateInDays.toFixed(3),
            absenceInDays.toFixed(3),
            (salary.grossSalary || 0).toFixed(2),
            (salary.tardinessDeduction || 0).toFixed(2),
            (salary.totalDeductions || 0).toFixed(2),
            (salary.netIncome || 0).toFixed(2)
        ].map(v => (String(v).includes(',') || String(v).includes('"')) ? '"' + String(v).replace(/"/g, '""') + '"' : v);
        csv += values.join(',') + '\n';
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const df = document.getElementById('batchSalaryDateFrom') ? document.getElementById('batchSalaryDateFrom').value : '';
    const dt = document.getElementById('batchSalaryDateTo') ? document.getElementById('batchSalaryDateTo').value : '';
    a.download = `batch_salaries_${df || 'from'}_${dt || 'to'}.csv`;
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    document.body.removeChild(a);
}

// Official Times Management (continued - functions defined above in IIFE)
// currentOfficialTimesEmpId is now in the IIFE scope above
let currentOfficialTimesEmpId = window.currentOfficialTimesEmpId || '';

function loadOfficialTimes() {
    const startDate = document.getElementById('officialTimesStartDate').value;
    if (!startDate || !currentOfficialTimesEmpId) return;
    
    fetch(`manage_official_times_api.php?action=get&employee_id=${encodeURIComponent(currentOfficialTimesEmpId)}&start_date=${encodeURIComponent(startDate)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.official_time) {
                document.getElementById('officialTimesStartDate').value = data.official_time.start_date || startDate;
                document.getElementById('officialTimesEndDate').value = data.official_time.end_date || '';
                document.getElementById('officialTimeIn').value = data.official_time.time_in || '08:00';
                document.getElementById('officialLunchOut').value = data.official_time.lunch_out || '12:00';
                document.getElementById('officialLunchIn').value = data.official_time.lunch_in || '13:00';
                document.getElementById('officialTimeOut').value = data.official_time.time_out || '17:00';
            }
        })
        .catch(error => {
            console.error('Error loading official times:', error);
        });
}

window.loadOfficialTimesHistory = function loadOfficialTimesHistory() {
    const empId = window.currentOfficialTimesEmpId || currentOfficialTimesEmpId;
    if (!empId) {
        console.error('Employee ID not set for loading history');
        return;
    }
    
    fetch(`manage_official_times_api.php?action=get&employee_id=${encodeURIComponent(empId)}`)
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('officialTimesHistoryBody');
            tbody.innerHTML = '';
            
            if (data.success && data.official_times && data.official_times.length > 0) {
                data.official_times.forEach(ot => {
                    const row = document.createElement('tr');
                    
                    row.innerHTML = `
                        <td>${ot.start_date}</td>
                        <td>${ot.end_date || '<span class="text-success">Ongoing</span>'}</td>
                        <td>${ot.weekday || '-'}</td>
                        <td>${ot.time_in ? formatTimeTo12h(ot.time_in) : '-'}</td>
                        <td>${ot.lunch_out ? formatTimeTo12h(ot.lunch_out) : '-'}</td>
                        <td>${ot.lunch_in ? formatTimeTo12h(ot.lunch_in) : '-'}</td>
                        <td>${ot.time_out ? formatTimeTo12h(ot.time_out) : '-'}</td>
                        <td>
                            <button onclick="window.loadOfficialTime('${ot.start_date}', '${ot.weekday || ''}')" class="btn btn-sm btn-primary" title="Load">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="window.deleteOfficialTime(${ot.id})" class="btn btn-sm btn-danger" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No official times set yet</td></tr>';
            }
        })
        .catch(error => {
            console.error('Error loading official times history:', error);
            const tbody = document.getElementById('officialTimesHistoryBody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-3">Error loading history: ' + error.message + '</td></tr>';
            }
        });
};

window.loadOfficialTime = function loadOfficialTime(startDate, weekday) {
    console.log('loadOfficialTime called with:', startDate, weekday);
    const startDateElement = document.getElementById('officialTimesStartDate');
    const endDateElement = document.getElementById('officialTimesEndDate');
    
    if (!startDateElement) {
        console.error('Start date element not found');
        return;
    }
    
    if (startDateElement) {
        startDateElement.value = startDate;
    }
    
    // Load the official time data - get ALL weekdays with this start_date
    const employeeId = window.currentOfficialTimesEmpId;
    if (employeeId && startDate) {
        // First, get all official times for this start_date
        fetch(`manage_official_times_api.php?action=get&employee_id=${encodeURIComponent(employeeId)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.official_times) {
                    // Filter to get all times with this start_date
                    const timesForStartDate = data.official_times.filter(ot => ot.start_date === startDate);
                    
                    if (timesForStartDate.length > 0) {
                        // Clear previous edit IDs
                        if (!window.editingOfficialTimeIds) {
                            window.editingOfficialTimeIds = {};
                        }
                        window.editingOfficialTimeIds = {};
                        
                        // Use the first one's end_date (they should all have the same end_date)
                        const firstTime = timesForStartDate[0];
                        if (endDateElement) {
                            endDateElement.value = firstTime.end_date || '';
                        }
                        
                        // Check all weekday checkboxes and load their times
                        const weekdaysToLoad = [];
                        
                        timesForStartDate.forEach(ot => {
                            const dayName = ot.weekday || 'Monday';
                            // Store the ID for this weekday for edit mode
                            window.editingOfficialTimeIds[dayName] = parseInt(ot.id) || ot.id;
                            
                            weekdaysToLoad.push({
                                weekday: dayName,
                                id: ot.id,
                                time_in: ot.time_in || '08:00',
                                time_out: ot.time_out || '17:00',
                                lunch_out: ot.lunch_out && ot.lunch_out !== '-' ? ot.lunch_out : '',
                                lunch_in: ot.lunch_in && ot.lunch_in !== '-' ? ot.lunch_in : ''
                            });
                            
                            // Check the weekday checkbox
                            const weekdayCheckbox = document.getElementById('weekday-' + dayName.toLowerCase());
                            if (weekdayCheckbox) {
                                weekdayCheckbox.checked = true;
                            }
                        });
                        
                        // Update the weekday inputs to create tabs
                        if (typeof window.updateWeekdayTimeInputs === 'function') {
                            window.updateWeekdayTimeInputs();
                        }
                        
                        // Load times for each weekday after tabs are created
                        setTimeout(() => {
                            weekdaysToLoad.forEach(ot => {
                                const dayLower = ot.weekday.toLowerCase();
                                const timeInEl = document.getElementById('time_in_' + dayLower);
                                const timeOutEl = document.getElementById('time_out_' + dayLower);
                                const lunchOutEl = document.getElementById('lunch_out_' + dayLower);
                                const lunchInEl = document.getElementById('lunch_in_' + dayLower);
                                
                                if (timeInEl) timeInEl.value = ot.time_in;
                                if (timeOutEl) timeOutEl.value = ot.time_out;
                                if (lunchOutEl) lunchOutEl.value = ot.lunch_out || '';
                                if (lunchInEl) lunchInEl.value = ot.lunch_in || '';
                            });
                            
                            // Activate the first tab if weekday was specified
                            if (weekday) {
                                const dayLower = weekday.toLowerCase();
                                const tabButton = document.getElementById('tab-' + dayLower);
                                if (tabButton && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                                    const tab = new bootstrap.Tab(tabButton);
                                    tab.show();
                                }
                            }
                            
                            // Capture original values and attach change listeners if functions exist
                            if (typeof window.captureOriginalValues === 'function') {
                                window.captureOriginalValues();
                            }
                            if (typeof window.attachChangeListeners === 'function') {
                                window.attachChangeListeners();
                            }
                            if (typeof window.updateSaveButtonText === 'function') {
                                window.updateSaveButtonText();
                            }
                            
                            // Enter edit mode if function exists
                            if (typeof window.enterEditMode === 'function') {
                                window.enterEditMode();
                            }
                        }, 500);
                    } else {
                        // No times found for this start_date, just set the date
                        if (endDateElement) {
                            endDateElement.value = '';
                        }
                    }
                }
            })
            .catch(error => console.error('Error loading official times:', error));
    }
};

window.deleteOfficialTime = function deleteOfficialTime(id) {
    console.log('deleteOfficialTime called with id:', id);
    if (!confirm('Are you sure you want to delete this official time?')) {
        return;
    }
    
    const employeeId = window.currentOfficialTimesEmpId;
    if (!employeeId) {
        alert('Error: Safe Employee ID not set');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('employee_id', employeeId);
    formData.append('id', id);
    
    fetch('manage_official_times_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Clear the official times cache to force fresh data
            if (typeof employeeOfficialTimesCache !== 'undefined') {
                // Clear all cache entries for this employee
                if (employeeId) {
                    Object.keys(employeeOfficialTimesCache).forEach(key => {
                        if (key.startsWith(employeeId + '_')) {
                            delete employeeOfficialTimesCache[key];
                        }
                    });
                    console.log('Cleared official times cache for employee:', employeeId);
                }
            }
            
            showToast(data.message || 'Official times deleted successfully', 'success');
            if (typeof window.loadOfficialTimesHistory === 'function') {
                window.loadOfficialTimesHistory();
            }
            
            // Reload logs to refresh status column
            if (typeof loadLogsWithFilters === 'function' && window.currentLogsEmployeeId) {
                loadLogsWithFilters(window.currentLogsEmployeeId);
            }
            // Reset form to defaults (only if elements exist)
            const today = new Date();
            const startDateEl = document.getElementById('officialTimesStartDate');
            const endDateEl = document.getElementById('officialTimesEndDate');
            const timeInEl = document.getElementById('officialTimeIn');
            const lunchOutEl = document.getElementById('officialLunchOut');
            const lunchInEl = document.getElementById('officialLunchIn');
            const timeOutEl = document.getElementById('officialTimeOut');
            
            if (startDateEl) startDateEl.value = today.toISOString().split('T')[0];
            if (endDateEl) endDateEl.value = '';
            if (timeInEl) timeInEl.value = '08:00';
            if (lunchOutEl) lunchOutEl.value = '12:00';
            if (lunchInEl) lunchInEl.value = '13:00';
            if (timeOutEl) timeOutEl.value = '17:00';
        } else {
            showToast(data.message || 'Error deleting official times', 'error');
        }
    })
    .catch(error => {
        console.error('Error deleting official times:', error);
        showToast('Error deleting official times', 'error');
    });
}

window.saveOfficialTimes = function saveOfficialTimes() {
    try {
        const startDate = document.getElementById('officialTimesStartDate')?.value;
        const endDate = document.getElementById('officialTimesEndDate')?.value;
        const employeeId = window.currentOfficialTimesEmpId;
        
        if (!startDate) {
            alert('Please fill in Start Date');
            return;
        }
        
        if (!employeeId) {
            alert('Error: Safe Employee ID not set. Please close and reopen the modal.');
            return;
        }
        
        // Validate date range
        if (endDate && endDate < startDate) {
            alert('End date must be after start date');
            return;
        }
        
        // Get selected weekdays
        const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        const selectedWeekdays = [];
        
        weekdays.forEach(day => {
            const checkbox = document.getElementById('weekday-' + day.toLowerCase());
            if (checkbox && checkbox.checked) {
                selectedWeekdays.push(day);
            }
        });
        
        if (selectedWeekdays.length === 0) {
            alert('Please select at least one weekday');
            return;
        }
        
        // Validate and collect times for each selected weekday
        const weekdayTimes = [];
        let hasError = false;
        
        selectedWeekdays.forEach(day => {
            const dayLower = day.toLowerCase();
            const timeIn = document.getElementById('time_in_' + dayLower)?.value;
            const timeOut = document.getElementById('time_out_' + dayLower)?.value;
            const lunchOut = document.getElementById('lunch_out_' + dayLower)?.value || '';
            const lunchIn = document.getElementById('lunch_in_' + dayLower)?.value || '';
            
            if (!timeIn || !timeOut) {
                alert(`Please fill in Time In and Time Out for ${day}`);
                hasError = true;
                return;
            }
            
            weekdayTimes.push({
                weekday: day,
                time_in: timeIn,
                lunch_out: lunchOut,
                lunch_in: lunchIn,
                time_out: timeOut
            });
        });
        
        if (hasError) return;
        
        // Initialize editingOfficialTimeIds if not exists
        if (!window.editingOfficialTimeIds) {
            window.editingOfficialTimeIds = {};
        }
        
        // Save each weekday entry
        let savePromises = [];
        weekdayTimes.forEach(wt => {
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('employee_id', employeeId);
            formData.append('start_date', startDate);
            formData.append('end_date', endDate || '');
            formData.append('weekday', wt.weekday);
            formData.append('time_in', wt.time_in);
            formData.append('lunch_out', wt.lunch_out);
            formData.append('lunch_in', wt.lunch_in);
            formData.append('time_out', wt.time_out);
            
            // If in edit mode and we have an ID for this weekday, include it for update
            if (window.editingOfficialTimeIds && window.editingOfficialTimeIds[wt.weekday]) {
                formData.append('id', window.editingOfficialTimeIds[wt.weekday]);
            }
            
            savePromises.push(
                fetch('manage_official_times_api.php', {
                    method: 'POST',
                    body: formData
                }).then(response => response.json())
            );
        });
        
        // Execute all save operations
        Promise.all(savePromises)
        .then(results => {
            console.log('Save responses:', results);
            const allSuccess = results.every(r => r.success);
            const errorMessages = results.filter(r => !r.success).map(r => r.message).join(', ');
            
            if (allSuccess) {
                // Exit edit mode after successful save
                if (typeof window.exitEditMode === 'function') {
                    window.exitEditMode();
                }
                
                // Use showToast if available, otherwise use alert
                const message = window.editingOfficialTimeIds && Object.keys(window.editingOfficialTimeIds).length > 0
                    ? `Official times updated successfully for ${selectedWeekdays.length} weekday(s)`
                    : `Official times saved successfully for ${selectedWeekdays.length} weekday(s)`;
                
                if (typeof showToast === 'function') {
                    showToast(message, 'success');
                } else {
                    alert(message);
                }
                // Reload history
                const employeeId = window.currentOfficialTimesEmpId;
                console.log('Reloading history for employee:', employeeId);
                if (employeeId) {
                    fetch(`manage_official_times_api.php?action=get&employee_id=${encodeURIComponent(employeeId)}`)
                        .then(response => {
                            console.log('Response status:', response.status);
                            if (!response.ok) {
                                throw new Error('HTTP error! status: ' + response.status);
                            }
                            return response.text();
                        })
                        .then(text => {
                            console.log('Raw response:', text);
                            try {
                                const historyData = JSON.parse(text);
                                console.log('Parsed history data:', historyData);
                                const tbody = document.getElementById('officialTimesHistoryBody');
                                if (!tbody) {
                                    console.error('History table body not found');
                                    return;
                                }
                                
                                tbody.innerHTML = '';
                                
                                if (historyData.success && historyData.official_times && historyData.official_times.length > 0) {
                                    console.log('Found', historyData.official_times.length, 'official times');
                                    historyData.official_times.forEach(ot => {
                                        console.log('Processing official time:', ot);
                                        const row = document.createElement('tr');
                                        row.innerHTML = `
                                            <td>${ot.start_date || '-'}</td>
                                            <td>${ot.end_date || '<span class="text-success">Ongoing</span>'}</td>
                                            <td>${ot.weekday || '-'}</td>
                                            <td>${ot.time_in ? formatTimeTo12h(ot.time_in) : '-'}</td>
                                            <td>${ot.lunch_out ? formatTimeTo12h(ot.lunch_out) : '-'}</td>
                                            <td>${ot.lunch_in ? formatTimeTo12h(ot.lunch_in) : '-'}</td>
                                            <td>${ot.time_out ? formatTimeTo12h(ot.time_out) : '-'}</td>
                                            <td>
                                                <button onclick="window.loadOfficialTime('${ot.start_date}', '${ot.weekday || ''}')" class="btn btn-sm btn-primary" title="Edit">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button onclick="window.deleteOfficialTime(${ot.id})" class="btn btn-sm btn-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        `;
                                        tbody.appendChild(row);
                                    });
                                } else {
                                    console.log('No official times found or success=false');
                                    console.log('Success:', historyData.success);
                                    console.log('Official times:', historyData.official_times);
                                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No official times set yet</td></tr>';
                                }
                            } catch (e) {
                                console.error('Error parsing JSON:', e);
                                console.error('Response text:', text);
                                const tbody = document.getElementById('officialTimesHistoryBody');
                                if (tbody) {
                                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-3">Error parsing response: ' + e.message + '</td></tr>';
                                }
                            }
                        })
                        .catch(error => {
                            console.error('Error loading history:', error);
                            const tbody = document.getElementById('officialTimesHistoryBody');
                            if (tbody) {
                                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-3">Error loading history: ' + error.message + '</td></tr>';
                            }
                        });
                } else {
                    console.error('Employee ID not set');
                }
            } else {
                alert('Some entries failed to save: ' + errorMessages);
            }
        })
        .catch(error => {
            console.error('Error saving official times:', error);
            alert('Error saving official times: ' + error.message);
        });
    } catch (error) {
        console.error('Error in saveOfficialTimes:', error);
        alert('Error: ' + error.message);
    }
};

console.log('Official times functions loaded:', {
    manageOfficialTimes: typeof window.manageOfficialTimes,
    loadOfficialTimes: typeof window.loadOfficialTimes,
    loadOfficialTimesHistory: typeof window.loadOfficialTimesHistory,
    loadOfficialTime: typeof window.loadOfficialTime,
    deleteOfficialTime: typeof window.deleteOfficialTime,
    saveOfficialTimes: typeof window.saveOfficialTimes
});

// All Tardiness Modal Functions
// Define function directly on window object (no IIFE wrapper for immediate execution)
let allTardinessModalInstance = null;

window.openAllTardinessModal = function() {
    console.log('openAllTardinessModal called');
    
    const modalElement = document.getElementById('allTardinessModal');
    if (!modalElement) {
        console.error('allTardinessModal element not found');
        alert('Modal element not found. Please refresh the page.');
        return;
    }
    
    // Check if Bootstrap is available
    if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        console.error('Bootstrap Modal not available');
        alert('Bootstrap is not loaded. Please refresh the page.');
        return;
    }
    
    try {
        // Dispose existing modal instance if it exists
        if (allTardinessModalInstance) {
            try {
                allTardinessModalInstance.hide();
                allTardinessModalInstance.dispose();
            } catch (e) {
                console.warn('Error disposing modal:', e);
            }
            allTardinessModalInstance = null;
        }
        
        // Get or create Bootstrap modal instance using getOrCreateInstance
        // This handles modal instances better
        if (typeof bootstrap.Modal.getOrCreateInstance === 'function') {
            allTardinessModalInstance = bootstrap.Modal.getOrCreateInstance(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
        } else {
            // Fallback for older Bootstrap versions
            allTardinessModalInstance = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
        }
        
        // Set default date filters (current month) before showing
        const now = new Date();
        const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
        const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        
        const startDateInput = document.getElementById('tardinessStartDateFilter');
        const endDateInput = document.getElementById('tardinessEndDateFilter');
        
        if (startDateInput && !startDateInput.value) {
            startDateInput.value = firstDay.toISOString().split('T')[0];
        }
        if (endDateInput && !endDateInput.value) {
            endDateInput.value = lastDay.toISOString().split('T')[0];
        }
        
        // Show modal
        allTardinessModalInstance.show();
        
            // Load tardiness records after modal is shown (use once option to auto-remove)
            modalElement.addEventListener('shown.bs.modal', function loadData() {
                // Wait a bit to ensure function is loaded
                setTimeout(function() {
                    if (typeof window.loadAllTardiness === 'function') {
                        window.loadAllTardiness();
                    } else {
                        console.error('loadAllTardiness function not found - retrying...');
                        // Retry after a delay
                        setTimeout(function() {
                            if (typeof window.loadAllTardiness === 'function') {
                                window.loadAllTardiness();
                            } else {
                                console.error('loadAllTardiness function still not found after retry');
                            }
                        }, 200);
                    }
                }, 100);
            }, { once: true });
        
    } catch (error) {
        console.error('Error opening tardiness modal:', error);
        alert('Error opening modal: ' + error.message);
    }
};

// Mark that the function is loaded
console.log('openAllTardinessModal function defined');

// Mark script as fully loaded
window.employeeLogsLoaded = true;
if (typeof window.employeeLogsScriptLoading !== 'undefined') {
    window.employeeLogsScriptLoading = false;
}

// Make loadAllTardiness globally available for inline handlers
window.loadAllTardiness = function() {
    const tbody = document.getElementById('allTardinessTableBody');
    if (!tbody) return;
    
    // Show loading state
    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i>Loading tardiness records...</td></tr>';
    
    // Get filter values
    const startDate = document.getElementById('tardinessStartDateFilter')?.value || '';
    const endDate = document.getElementById('tardinessEndDateFilter')?.value || '';
    const employeeId = document.getElementById('tardinessEmployeeFilter')?.value || '';
    
    // Build query string
    const params = new URLSearchParams();
    if (startDate) params.append('start_date', startDate);
    if (endDate) params.append('end_date', endDate);
    if (employeeId) params.append('employee_id', employeeId);
    
    fetch('get_all_tardiness_api.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayAllTardiness(data.records);
                // Update count badge
                const badge = document.getElementById('tardinessCountBadge');
                if (badge) {
                    badge.textContent = `${data.count} record${data.count !== 1 ? 's' : ''}`;
                }
            } else {
                tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle me-2"></i>Error: ${data.message || 'Failed to load tardiness records'}</td></tr>`;
            }
        })
        .catch(error => {
            console.error('Error loading tardiness records:', error);
            tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle me-2"></i>Error loading tardiness records: ${error.message}</td></tr>`;
        });
};

function displayAllTardiness(records) {
    const tbody = document.getElementById('allTardinessTableBody');
    if (!tbody) return;
    
    if (records.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-info-circle me-2"></i>No tardiness records found</td></tr>';
        return;
    }
    
    tbody.innerHTML = '';
    let counter = 1;
    
    records.forEach(record => {
        const row = document.createElement('tr');
        
        // Format date
        const logDate = record.log_date ? new Date(record.log_date).toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        }) : '-';
        
        row.innerHTML = `
            <td>${counter++}</td>
            <td class="fw-medium">${record.full_name || '-'}</td>
            <td>${record.position || '-'}</td>
            <td>${logDate}</td>
            <td><span class="badge bg-info">${record.official_time_in ? formatTimeTo12h(record.official_time_in) : '-'}</span></td>
            <td><span class="badge bg-warning">${record.time_in ? formatTimeTo12h(record.time_in) : '-'}</span></td>
            <td class="text-end text-danger fw-bold">${record.time_info || '-'}</td>
        `;
        tbody.appendChild(row);
    });
}

// Make functions globally available
window.clearTardinessFilters = function() {
    const startDateInput = document.getElementById('tardinessStartDateFilter');
    const endDateInput = document.getElementById('tardinessEndDateFilter');
    const employeeInput = document.getElementById('tardinessEmployeeFilter');
    
    if (startDateInput) startDateInput.value = '';
    if (endDateInput) endDateInput.value = '';
    if (employeeInput) employeeInput.value = '';
};

window.generateTardinessReport = function() {
    try {
        // Get current filter values
        const startDate = document.getElementById('tardinessStartDateFilter')?.value || '';
        const endDate = document.getElementById('tardinessEndDateFilter')?.value || '';
        const employeeId = document.getElementById('tardinessEmployeeFilter')?.value || '';
        
        console.log('Generating report with filters:', { startDate, endDate, employeeId });
        
        // Show loading message
        if (typeof showToast === 'function') {
            showToast('Generating tardiness report...', 'info');
        }
        
        // Build query parameters
        const params = new URLSearchParams();
        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        if (employeeId) params.append('employee_id', employeeId);
        
        // Build the download URL - relative to current page
        const downloadUrl = 'generate_tardiness_report.php?' + params.toString();
        
        // Use fetch to download the file as a blob, then trigger download
        fetch(downloadUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                // Get the filename from Content-Disposition header if available
                const contentDisposition = response.headers.get('Content-Disposition');
                let filename = 'Tardiness_Report.xlsx';
                if (contentDisposition) {
                    const filenameMatch = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
                    if (filenameMatch && filenameMatch[1]) {
                        filename = filenameMatch[1].replace(/['"]/g, '');
                    }
                }
                
                return response.blob().then(blob => ({ blob, filename }));
            })
            .then(({ blob, filename }) => {
                // Create a temporary URL for the blob
                const url = window.URL.createObjectURL(blob);
                
                // Create a temporary anchor element
                const link = document.createElement('a');
                link.href = url;
                link.download = filename;
                link.style.display = 'none';
                
                // Append to body, click, then remove
                document.body.appendChild(link);
                link.click();
                
                // Clean up
                setTimeout(function() {
                    document.body.removeChild(link);
                    window.URL.revokeObjectURL(url);
                }, 100);
                
                // Show success message
                if (typeof showToast === 'function') {
                    showToast('Report downloaded successfully', 'success');
                }
            })
            .catch(error => {
                console.error('Error downloading report:', error);
                alert('Error downloading report: ' + error.message);
                
                // Fallback: try direct link approach
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.target = '_blank';
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                setTimeout(function() {
                    document.body.removeChild(link);
                }, 100);
            });
        
    } catch (error) {
        console.error('Error generating tardiness report:', error);
        alert('Error generating report: ' + error.message);
    }
}
