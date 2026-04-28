/**
 * Dashboard JavaScript
 * Handles all dashboard functionality with proper XSS protection
 * Adapted for FP Timekeeper System
 */

// Configuration
const CONFIG = {
    basePath: '',
    apiPath: 'api'
};

/**
 * Escape HTML to prevent XSS attacks
 * @param {string} text - Text to escape
 * @return {string} Escaped text
 */
function escapeHtml(text) {
    if (text === null || text === undefined) {
        return '';
    }
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Format time string
 * @param {string} time - Time string
 * @return {string} Formatted time
 */
/**
 * @param {Object} record
 * @returns {boolean}
 */
function attendanceRecordIsTarfMirror(record) {
    if (!record) return false;
    const r = String(record.remarks || '').trim();
    if (r.indexOf('TARF:') !== 0) return false;
    if (r.indexOf('TARF_HOURS_CREDIT:') !== -1) return true;
    return !!record.tarf_id;
}

/**
 * @param {Object} record
 * @param {string} time
 * @returns {string}
 */
function formatTarfOrTime(record, time) {
    if (attendanceRecordIsTarfMirror(record)) {
        return '<span class="badge bg-info text-dark">TARF</span>';
    }
    return escapeHtml(formatTime(time));
}

function formatTime(time) {
    if (!time) return '-';
    try {
        // Handle TIME format (HH:MM:SS) from attendance_logs
        if (typeof time === 'string' && time.match(/^\d{2}:\d{2}:\d{2}$/)) {
            return time.substring(0, 5); // Return HH:MM
        }
        const date = new Date(time);
        if (isNaN(date.getTime())) return '-';
        return date.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    } catch (e) {
        console.error('Error formatting time:', e);
        return '-';
    }
}

/**
 * Format date string
 * @param {string} date - Date string
 * @return {string} Formatted date
 */
function formatDate(date) {
    if (!date) return '-';
    try {
        const d = new Date(date + (date.includes('T') ? '' : 'T00:00:00'));
        if (isNaN(d.getTime())) return escapeHtml(date);
        return d.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    } catch (e) {
        console.error('Error formatting date:', e);
        return escapeHtml(date);
    }
}

/**
 * Calculate total hours from attendance record
 * Calculation: Morning (Time In to Lunch Out) + Afternoon (Lunch In to Time Out) + OT
 * @param {Object} record - Attendance record
 * @return {string} Total hours formatted
 */
function calculateTotalHours(record) {
    let totalMinutes = 0;
    let hasAnyData = false;
    
    try {
        const cred = String(record.remarks || '').match(/TARF_HOURS_CREDIT:([\d.]+)/);
        if (cred) {
            const h = parseFloat(cred[1], 10);
            if (!isNaN(h) && h > 0) {
                const whole = Math.floor(h);
                const frac = Math.round((h - whole) * 60);
                return `${String(whole).padStart(2, '0')}:${String(frac).padStart(2, '0')}:00`;
            }
        }

        // Helper function to parse time to minutes
        const parseTimeToMinutes = (timeStr) => {
            if (!timeStr) return null;
            if (typeof timeStr === 'string' && timeStr.match(/^\d{2}:\d{2}:\d{2}$/)) {
                const parts = timeStr.split(':');
                return parseInt(parts[0]) * 60 + parseInt(parts[1]);
            }
            const date = new Date(timeStr);
            if (!isNaN(date.getTime())) {
                return date.getHours() * 60 + date.getMinutes();
            }
            return null;
        };

        // Calculate Time In to Lunch Out
        if (record.time_in && record.lunch_out) {
            const timeIn = parseTimeToMinutes(record.time_in);
            const lunchOut = parseTimeToMinutes(record.lunch_out);
            if (timeIn !== null && lunchOut !== null && lunchOut > timeIn) {
                totalMinutes += (lunchOut - timeIn);
                hasAnyData = true;
            }
        }
        
        // Calculate Lunch In to Time Out
        if (record.lunch_in && record.time_out) {
            const lunchIn = parseTimeToMinutes(record.lunch_in);
            const timeOut = parseTimeToMinutes(record.time_out);
            if (lunchIn !== null && timeOut !== null && timeOut > lunchIn) {
                totalMinutes += (timeOut - lunchIn);
                hasAnyData = true;
            }
        }

        // Calculate OT In to OT Out
        if (record.ot_in && record.ot_out) {
            const otIn = parseTimeToMinutes(record.ot_in);
            const otOut = parseTimeToMinutes(record.ot_out);
            if (otIn !== null && otOut !== null && otOut > otIn) {
                totalMinutes += (otOut - otIn);
                hasAnyData = true;
            }
        }
        
        if (!hasAnyData || totalMinutes <= 0) return '-';
        
        const hours = Math.floor(totalMinutes / 60);
        const minutes = Math.floor(totalMinutes % 60);
        return `${hours}h ${minutes}m`;
    } catch (e) {
        console.error('Error calculating total hours:', e);
        return '-';
    }
}

/**
 * Show notification message
 * @param {string} message - Message to display
 * @param {string} type - Type: 'success', 'error', 'warning', 'info'
 */
function showNotification(message, type = 'info') {
    // Remove existing notifications
    const existing = document.querySelector('.notification-toast');
    if (existing) {
        existing.remove();
    }
    
    const alertClass = {
        'success': 'alert-success',
        'error': 'alert-danger',
        'warning': 'alert-warning',
        'info': 'alert-info'
    }[type] || 'alert-info';
    
    const icon = {
        'success': 'bi-check-circle-fill',
        'error': 'bi-exclamation-triangle-fill',
        'warning': 'bi-exclamation-triangle-fill',
        'info': 'bi-info-circle-fill'
    }[type] || 'bi-info-circle-fill';
    
    const notification = document.createElement('div');
    notification.className = `alert ${alertClass} alert-dismissible fade show notification-toast position-fixed top-0 start-50 translate-middle-x mt-3`;
    notification.style.zIndex = '9999';
    notification.setAttribute('role', 'alert');
    notification.innerHTML = `
        <i class="bi ${icon} me-2"></i>
        <span>${escapeHtml(message)}</span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}

/**
 * Handle API errors
 * @param {Error} error - Error object
 * @param {string} context - Context of the error
 */
function handleApiError(error, context) {
    console.error(`Error in ${context}:`, error);
    showNotification(`Error: ${context}. Please try again.`, 'error');
}

/**
 * Load attendance data
 */
async function loadAttendanceData() {
    try {
        const today = new Date().toISOString().split('T')[0];
        // Station ID will be filtered server-side based on session
        const url = `${CONFIG.apiPath}/get-attendance.php?date=${encodeURIComponent(today)}`;
        
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        const tbody = document.getElementById('attendanceTableBody');
        
        if (!tbody) {
            console.error('Attendance table body not found');
            return;
        }
        
        if (data.success && data.attendance && data.attendance.length > 0) {
            tbody.innerHTML = data.attendance.map(record => {
                const totalHours = calculateTotalHours(record);
                return `
                    <tr>
                        <td data-label="Date">${formatDate(record.log_date || record.attendance_date)}</td>
                        <td data-label="Safe Employee ID"><strong>${escapeHtml(record.employee_id || 'N/A')}</strong></td>
                        <td data-label="Name">${escapeHtml(record.name || 'N/A')}</td>
                        <td data-label="Time In">${formatTarfOrTime(record, record.time_in)}</td>
                        <td data-label="Lunch Out">${formatTarfOrTime(record, record.lunch_out)}</td>
                        <td data-label="Lunch In">${formatTarfOrTime(record, record.lunch_in)}</td>
                        <td data-label="Time Out">${formatTarfOrTime(record, record.time_out)}</td>
                        <td data-label="Total Hours"><strong>${escapeHtml(totalHours)}</strong></td>
                        <td data-label="Actions">
                            <button class="btn btn-sm btn-info" onclick="viewAttendanceDetails(${parseInt(record.id)})">
                                <i class="bi bi-eye"></i> View
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox me-2"></i>No attendance records found for today
                    </td>
                </tr>
            `;
        }
    } catch (error) {
        handleApiError(error, 'loading attendance');
        const tbody = document.getElementById('attendanceTableBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-5 text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>Error loading attendance data
                    </td>
                </tr>
            `;
        }
    }
}

/**
 * Load employees data
 */
async function loadEmployeesData() {
    try {
        const response = await fetch(`${CONFIG.apiPath}/get-employees.php`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        const tbody = document.getElementById('employeesTableBody');
        
        if (!tbody) {
            console.error('Employees table body not found');
            return;
        }
        
        if (data.success && data.employees && data.employees.length > 0) {
            tbody.innerHTML = data.employees.map(employee => {
                const statusClass = employee.status === 'Active' ? 'bg-success' : 'bg-secondary';
                return `
                    <tr>
                        <td><strong>${escapeHtml(employee.employee_id || 'N/A')}</strong></td>
                        <td>${escapeHtml(employee.name || 'N/A')}</td>
                        <td>${escapeHtml(employee.email || 'N/A')}</td>
                        <td>${escapeHtml(employee.department || 'N/A')}</td>
                        <td>${escapeHtml(employee.position || 'N/A')}</td>
                        <td>
                            <span class="badge ${statusClass}">${escapeHtml(employee.status)}</span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick="editEmployee(${parseInt(employee.user_id)})">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <button class="btn btn-sm btn-info" onclick="viewEmployee(${parseInt(employee.user_id)})">
                                <i class="bi bi-eye"></i> View
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox me-2"></i>No employees found
                    </td>
                </tr>
            `;
        }
    } catch (error) {
        handleApiError(error, 'loading employees');
        const tbody = document.getElementById('employeesTableBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5 text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>Error loading employees data
                    </td>
                </tr>
            `;
        }
    }
}

/**
 * Load leave data
 */
async function loadLeaveData() {
    try {
        const response = await fetch(`${CONFIG.apiPath}/get-leave.php`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        const tbody = document.getElementById('leaveTableBody');
        
        if (!tbody) {
            console.error('Leave table body not found');
            return;
        }
        
        if (data.success && data.leave_requests && data.leave_requests.length > 0) {
            tbody.innerHTML = data.leave_requests.map(leave => {
                const statusClass = leave.status === 'Approved' ? 'bg-success' : 
                                   leave.status === 'Rejected' ? 'bg-danger' : 'bg-warning';
                const actions = leave.status === 'Pending' ? `
                    <button class="btn btn-sm btn-success" onclick="approveLeave(${parseInt(leave.id)})">
                        <i class="bi bi-check"></i> Approve
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="rejectLeave(${parseInt(leave.id)})">
                        <i class="bi bi-x"></i> Reject
                    </button>
                ` : `
                    <button class="btn btn-sm btn-info" onclick="viewLeave(${parseInt(leave.id)})">
                        <i class="bi bi-eye"></i> View
                    </button>
                `;
                
                return `
                    <tr>
                        <td><strong>${escapeHtml(leave.employee_id || 'N/A')}</strong></td>
                        <td>${escapeHtml(leave.name || 'N/A')}</td>
                        <td>${escapeHtml(leave.leave_type || 'N/A')}</td>
                        <td>${formatDate(leave.start_date)}</td>
                        <td>${formatDate(leave.end_date)}</td>
                        <td>${escapeHtml(leave.days || 0)} days</td>
                        <td>
                            <span class="badge ${statusClass}">${escapeHtml(leave.status)}</span>
                        </td>
                        <td>${actions}</td>
                    </tr>
                `;
            }).join('');
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox me-2"></i>No leave records found
                    </td>
                </tr>
            `;
        }
    } catch (error) {
        handleApiError(error, 'loading leave data');
        const tbody = document.getElementById('leaveTableBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-5 text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>Error loading leave data
                    </td>
                </tr>
            `;
        }
    }
}

/**
 * Load tardiness data
 */
async function loadTardinessData() {
    try {
        const response = await fetch(`${CONFIG.apiPath}/get-tardiness.php`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        const tbody = document.getElementById('tardinessTableBody');
        
        if (!tbody) {
            console.error('Tardiness table body not found');
            return;
        }
        
        if (data.success && data.tardiness && data.tardiness.length > 0) {
            tbody.innerHTML = data.tardiness.map(record => {
                const hours = Math.floor(record.minutes_late / 60);
                const minutes = record.minutes_late % 60;
                const lateTime = hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
                
                return `
                    <tr>
                        <td>${formatDate(record.date)}</td>
                        <td><strong>${escapeHtml(record.employee_id || 'N/A')}</strong></td>
                        <td>${escapeHtml(record.name || 'N/A')}</td>
                        <td>${escapeHtml(record.scheduled_time || 'N/A')}</td>
                        <td>${escapeHtml(record.actual_time_in || 'N/A')}</td>
                        <td><span class="badge bg-warning">${escapeHtml(lateTime)}</span></td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="viewTardinessDetails(${parseInt(record.id)})">
                                <i class="bi bi-eye"></i> View
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox me-2"></i>No tardiness records found
                    </td>
                </tr>
            `;
        }
    } catch (error) {
        handleApiError(error, 'loading tardiness data');
        const tbody = document.getElementById('tardinessTableBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5 text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>Error loading tardiness data
                    </td>
                </tr>
            `;
        }
    }
}

/**
 * Load absent data
 */
async function loadAbsentData() {
    try {
        const response = await fetch(`${CONFIG.apiPath}/get-absent.php`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        const tbody = document.getElementById('absentTableBody');
        
        if (!tbody) {
            console.error('Absent table body not found');
            return;
        }
        
        if (data.success && data.absent_records && data.absent_records.length > 0) {
            const periodInfo = data.period_info || {};
            const periodLabel = periodInfo.period_label || 'Current Period';
            const periodMonth = periodInfo.period_month || '';
            
            // Update table header with current period information
            const tableHead = document.querySelector('#absent thead tr');
            if (tableHead) {
                const headers = tableHead.querySelectorAll('th');
                if (headers.length >= 4) {
                    headers[3].innerHTML = `${periodLabel}<br><small class="text-muted">${periodMonth}</small>`;
                }
            }
            
            tbody.innerHTML = data.absent_records.map(record => {
                // Determine badge color based on period number
                const badgeColor = record.period_number === 1 ? 'primary' : 'success';
                return `
                    <tr>
                        <td><strong>${escapeHtml(record.employee_id || 'N/A')}</strong></td>
                        <td>${escapeHtml(record.name || 'N/A')}</td>
                        <td>
                            <span class="badge bg-danger fs-6">${record.total_absent_days || 0}</span>
                        </td>
                        <td>
                            <span class="badge bg-${badgeColor}">${record.period_count || 0}</span>
                            <br><small class="text-muted">${record.period_month || periodMonth}</small>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="viewAbsentDetailsByEmployeeId('${escapeHtml(record.employee_id)}')">
                                <i class="bi bi-eye"></i> View Details
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox me-2"></i>No absent records found
                    </td>
                </tr>
            `;
        }
    } catch (error) {
        handleApiError(error, 'loading absent data');
        const tbody = document.getElementById('absentTableBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center py-5 text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>Error loading absent data
                    </td>
                </tr>
            `;
        }
    }
}

// Employee Functions
function showAddEmployeeModal() {
    document.getElementById('employeeModalLabel').textContent = 'Add Employee';
    document.getElementById('employeeForm').reset();
    document.getElementById('employee_user_id').value = '';
    document.getElementById('passwordRow').style.display = 'block';
    document.getElementById('employee_password').required = true;
    document.getElementById('employee_is_active').value = '1';
    const modal = new bootstrap.Modal(document.getElementById('employeeModal'));
    modal.show();
}

async function editEmployee(id) {
    try {
        const response = await fetch(`${CONFIG.apiPath}/get-employee-details.php?user_id=${encodeURIComponent(id)}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            const emp = data.employee;
            document.getElementById('employeeModalLabel').textContent = 'Edit Employee';
            document.getElementById('employee_user_id').value = emp.user_id;
            document.getElementById('employee_first_name').value = emp.first_name || '';
            document.getElementById('employee_last_name').value = emp.last_name || '';
            document.getElementById('employee_middle_name').value = emp.middle_name || '';
            document.getElementById('employee_email').value = emp.email || '';
            document.getElementById('employee_employee_id').value = emp.employee_id || '';
            document.getElementById('employee_department').value = emp.department || '';
            document.getElementById('employee_position').value = emp.position || '';
            document.getElementById('employee_employment_status').value = emp.employment_status || '';
            document.getElementById('employee_phone').value = emp.phone || '';
            document.getElementById('employee_address').value = emp.address || '';
            document.getElementById('employee_is_active').value = emp.is_active ? '1' : '0';
            document.getElementById('employee_user_type').value = emp.user_type || 'staff';
            document.getElementById('passwordRow').style.display = 'none';
            document.getElementById('employee_password').required = false;
            
            const modal = new bootstrap.Modal(document.getElementById('employeeModal'));
            modal.show();
        } else {
            showNotification('Error loading employee: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        handleApiError(error, 'loading employee details');
    }
}

async function viewEmployee(id) {
    try {
        const response = await fetch(`${CONFIG.apiPath}/get-employee-details.php?user_id=${encodeURIComponent(id)}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            const emp = data.employee;
            const statusBadge = emp.is_active ? 'bg-success' : 'bg-secondary';
            const statusText = emp.is_active ? 'Active' : 'Inactive';
            const userType = emp.user_type ? emp.user_type.charAt(0).toUpperCase() + emp.user_type.slice(1) : 'N/A';
            
            const content = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Personal Information</h6>
                        <p><strong>Name:</strong> ${escapeHtml(emp.full_name || 'N/A')}</p>
                        <p><strong>Email:</strong> ${escapeHtml(emp.email || 'N/A')}</p>
                        <p><strong>Phone:</strong> ${escapeHtml(emp.phone || 'N/A')}</p>
                        <p><strong>Address:</strong> ${escapeHtml(emp.address || 'N/A')}</p>
                    </div>
                    <div class="col-md-6">
                        <h6>Employment Information</h6>
                        <p><strong>Safe Employee ID:</strong> ${escapeHtml(emp.employee_id || 'N/A')}</p>
                        <p><strong>Department:</strong> ${escapeHtml(emp.department || 'N/A')}</p>
                        <p><strong>Position:</strong> ${escapeHtml(emp.position || 'N/A')}</p>
                        <p><strong>Employment Status:</strong> ${escapeHtml(emp.employment_status || 'N/A')}</p>
                        <p><strong>User Type:</strong> ${escapeHtml(userType)}</p>
                        <p><strong>Status:</strong> <span class="badge ${statusBadge}">${escapeHtml(statusText)}</span></p>
                        <p><strong>Hire Date:</strong> ${emp.hire_date ? formatDate(emp.hire_date) : 'N/A'}</p>
                    </div>
                </div>
            `;
            document.getElementById('viewEmployeeContent').innerHTML = content;
            const modal = new bootstrap.Modal(document.getElementById('viewEmployeeModal'));
            modal.show();
        } else {
            showNotification('Error loading employee: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        handleApiError(error, 'loading employee details');
    }
}

async function saveEmployee() {
    const form = document.getElementById('employeeForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const user_id = document.getElementById('employee_user_id').value;
    const isEdit = user_id !== '';
    
    const employeeData = {
        first_name: document.getElementById('employee_first_name').value.trim(),
        last_name: document.getElementById('employee_last_name').value.trim(),
        middle_name: document.getElementById('employee_middle_name').value.trim(),
        email: document.getElementById('employee_email').value.trim(),
        employee_id: document.getElementById('employee_employee_id').value.trim(),
        department: document.getElementById('employee_department').value.trim(),
        position: document.getElementById('employee_position').value.trim(),
        employment_status: document.getElementById('employee_employment_status').value,
        phone: document.getElementById('employee_phone').value.trim(),
        address: document.getElementById('employee_address').value.trim(),
        is_active: document.getElementById('employee_is_active').value,
        user_type: document.getElementById('employee_user_type').value
    };
    
    if (!isEdit) {
        const password = document.getElementById('employee_password').value;
        if (password.length < 8) {
            showNotification('Password must be at least 8 characters long', 'error');
            return;
        }
        employeeData.password = password;
    }
    
    if (isEdit) {
        employeeData.user_id = user_id;
    }
    
    try {
        const url = isEdit ? `${CONFIG.apiPath}/update-employee.php` : `${CONFIG.apiPath}/create-employee.php`;
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(employeeData)
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            showNotification(result.message || 'Employee saved successfully', 'success');
            bootstrap.Modal.getInstance(document.getElementById('employeeModal')).hide();
            loadEmployeesData();
        } else {
            showNotification('Error: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        handleApiError(error, 'saving employee');
    }
}

// Attendance Functions
async function viewAttendanceDetails(id) {
    try {
        // First get the attendance record to get user_id
        const detailResponse = await fetch(`${CONFIG.apiPath}/get-attendance-details.php?id=${encodeURIComponent(id)}`);
        
        if (!detailResponse.ok) {
            throw new Error(`HTTP error! status: ${detailResponse.status}`);
        }
        
        const detailData = await detailResponse.json();
        
        if (!detailData.success || !detailData.attendance) {
            showNotification('Error loading attendance: ' + (detailData.message || 'Unknown error'), 'error');
            return;
        }
        
        const user_id = detailData.attendance.user_id;
        
        // Show modal with date filter
        showAttendanceDetailsModal(user_id);
    } catch (error) {
        console.error('Error:', error);
        handleApiError(error, 'loading attendance details');
    }
}

async function showAttendanceDetailsModal(user_id, filterDate = null) {
    try {
        // Build API URL with optional date filter
        let apiUrl = `${CONFIG.apiPath}/get-user-attendance.php?user_id=${encodeURIComponent(user_id)}`;
        if (filterDate) {
            apiUrl += `&date=${encodeURIComponent(filterDate)}`;
        }
        
        const response = await fetch(apiUrl);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            const records = data.attendance_records || [];
            const analytics = data.analytics || {};
            const userInfo = data.user_info || {};
            
            // Build analytics section
            const analyticsHtml = `
                <div class="row mb-4">
                    <div class="col-md-12">
                        <h5 class="mb-3"><i class="bi bi-graph-up me-2"></i>Attendance Analytics</h5>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6 class="card-subtitle mb-2 text-muted">Total Days</h6>
                                        <h3 class="card-title text-primary">${analytics.total_days || 0}</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6 class="card-subtitle mb-2 text-muted">Complete Days</h6>
                                        <h3 class="card-title text-success">${analytics.complete_days || 0}</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6 class="card-subtitle mb-2 text-muted">Total Hours</h6>
                                        <h3 class="card-title text-info">${analytics.total_hours || 0}</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6 class="card-subtitle mb-2 text-muted">Absent Days</h6>
                                        <h3 class="card-title text-danger">${analytics.absent_count || 0}</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Build user info section
            const userInfoHtml = `
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title"><i class="bi bi-person me-2"></i>Employee Information</h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <p class="mb-1"><strong>Name:</strong> ${escapeHtml(userInfo.name || 'N/A')}</p>
                                    </div>
                                    <div class="col-md-4">
                                        <p class="mb-1"><strong>Safe Employee ID:</strong> ${escapeHtml(userInfo.employee_id || 'N/A')}</p>
                                    </div>
                                    <div class="col-md-4">
                                        <p class="mb-1"><strong>Total Records:</strong> ${records.length}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Build attendance records table
            let recordsHtml = '';
            if (records.length > 0) {
                recordsHtml = `
                    <div class="row">
                        <div class="col-md-12">
                            <h5 class="mb-3"><i class="bi bi-calendar-check me-2"></i>Attendance Records</h5>
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-hover table-sm">
                                    <thead class="table-dark sticky-top">
                                        <tr>
                                            <th>Date</th>
                                            <th>Time In</th>
                                            <th>Lunch Out</th>
                                            <th>Lunch In</th>
                                            <th>Time Out</th>
                                            <th>OT In</th>
                                            <th>OT Out</th>
                                            <th>Total Hours</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${records.map(record => {
                                            const isTarfRow = attendanceRecordIsTarfMirror(record);
                                            const timeIn = formatTime(record.time_in);
                                            const lunchOut = formatTime(record.lunch_out);
                                            const lunchIn = formatTime(record.lunch_in);
                                            const timeOut = formatTime(record.time_out);
                                            const otIn = formatTime(record.ot_in);
                                            const otOut = formatTime(record.ot_out);
                                            
                                            const isComplete = isTarfRow || (record.time_in && record.lunch_out && record.lunch_in && record.time_out);
                                            const hasOT = record.ot_in && record.ot_out;
                                            const totalHours = calculateTotalHours(record);
                                            
                                            // Status badge
                                            let statusBadge = '';
                                            if (isTarfRow) {
                                                statusBadge = '<span class="badge bg-info text-dark">TARF</span>';
                                            } else if (isComplete) {
                                                statusBadge = '<span class="badge bg-success">Complete</span>';
                                            } else if (record.time_in) {
                                                statusBadge = '<span class="badge bg-warning">Incomplete</span>';
                                            } else {
                                                statusBadge = '<span class="badge bg-danger">No Record</span>';
                                            }
                                            
                                            // Helper function to show field with indicator
                                            const showField = (value, fieldName) => {
                                                if (!value || value === '-') {
                                                    return `<span class="text-danger" title="Missing ${fieldName}"><i class="bi bi-x-circle me-1"></i>-</span>`;
                                                }
                                                return escapeHtml(value);
                                            };
                                            const punchCell = (rawTime, formatted, fieldName) => {
                                                if (isTarfRow) {
                                                    return formatTarfOrTime(record, rawTime);
                                                }
                                                return showField(formatted, fieldName);
                                            };
                                            
                                            return `
                                                <tr class="${isComplete ? '' : 'table-warning'}">
                                                    <td><strong>${formatDate(record.log_date || record.attendance_date)}</strong></td>
                                                    <td>${punchCell(record.time_in, timeIn, 'Time In')}</td>
                                                    <td>${punchCell(record.lunch_out, lunchOut, 'Lunch Out')}</td>
                                                    <td>${punchCell(record.lunch_in, lunchIn, 'Lunch In')}</td>
                                                    <td>${punchCell(record.time_out, timeOut, 'Time Out')}</td>
                                                    <td>${showField(otIn, 'OT In')}</td>
                                                    <td>${showField(otOut, 'OT Out')}</td>
                                                    <td><strong>${escapeHtml(totalHours)}</strong></td>
                                                    <td>${statusBadge}</td>
                                                </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                recordsHtml = `
                    <div class="row">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>No attendance records found.
                            </div>
                        </div>
                    </div>
                `;
            }
            
            const content = userInfoHtml + analyticsHtml + recordsHtml;
            document.getElementById('viewAttendanceContent').innerHTML = content;
            const modal = new bootstrap.Modal(document.getElementById('viewAttendanceModal'));
            modal.show();
        } else {
            showNotification('Error loading attendance: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        handleApiError(error, 'loading attendance details');
    }
}

// Leave Functions
function showAddLeaveModal() {
    document.getElementById('leaveForm').reset();
    const modal = new bootstrap.Modal(document.getElementById('leaveModal'));
    modal.show();
}

async function saveLeave() {
    const form = document.getElementById('leaveForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const startDate = new Date(document.getElementById('leave_start_date').value);
    const endDate = new Date(document.getElementById('leave_end_date').value);
    
    if (endDate < startDate) {
        showNotification('End date must be after start date', 'error');
        return;
    }
    
    const leaveData = {
        leave_type: document.getElementById('leave_type').value,
        start_date: document.getElementById('leave_start_date').value,
        end_date: document.getElementById('leave_end_date').value,
        reason: document.getElementById('leave_reason').value.trim()
    };
    
    try {
        const response = await fetch(`${CONFIG.apiPath}/create-leave.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(leaveData)
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            showNotification(result.message || 'Leave request submitted successfully', 'success');
            bootstrap.Modal.getInstance(document.getElementById('leaveModal')).hide();
            loadLeaveData();
        } else {
            showNotification('Error: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        handleApiError(error, 'submitting leave request');
    }
}

async function approveLeave(id) {
    if (!confirm('Are you sure you want to approve this leave request?')) {
        return;
    }
    
    try {
        const response = await fetch(`${CONFIG.apiPath}/update-leave-status.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                leave_id: id,
                status: 'approved'
            })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            showNotification(result.message || 'Leave request approved', 'success');
            loadLeaveData();
        } else {
            showNotification('Error: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        handleApiError(error, 'approving leave request');
    }
}

async function rejectLeave(id) {
    if (!confirm('Are you sure you want to reject this leave request?')) {
        return;
    }
    
    try {
        const response = await fetch(`${CONFIG.apiPath}/update-leave-status.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                leave_id: id,
                status: 'rejected'
            })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            showNotification(result.message || 'Leave request rejected', 'success');
            loadLeaveData();
        } else {
            showNotification('Error: ' + (result.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        handleApiError(error, 'rejecting leave request');
    }
}

async function viewLeave(id) {
    try {
        const response = await fetch(`${CONFIG.apiPath}/get-leave-details.php?id=${encodeURIComponent(id)}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            const leave = data.leave;
            const statusClass = leave.status === 'approved' ? 'bg-success' : 
                               leave.status === 'rejected' ? 'bg-danger' : 'bg-warning';
            const statusText = leave.status ? leave.status.charAt(0).toUpperCase() + leave.status.slice(1) : 'Pending';
            
            const content = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Employee Information</h6>
                        <p><strong>Safe Employee ID:</strong> ${escapeHtml(leave.employee_id || 'N/A')}</p>
                        <p><strong>Name:</strong> ${escapeHtml(leave.name || 'N/A')}</p>
                        <p><strong>Email:</strong> ${escapeHtml(leave.email || 'N/A')}</p>
                    </div>
                    <div class="col-md-6">
                        <h6>Leave Details</h6>
                        <p><strong>Leave Type:</strong> ${escapeHtml(leave.leave_type || 'N/A')}</p>
                        <p><strong>Start Date:</strong> ${formatDate(leave.start_date)}</p>
                        <p><strong>End Date:</strong> ${formatDate(leave.end_date)}</p>
                        <p><strong>Days:</strong> ${escapeHtml(leave.days || 0)} days</p>
                        <p><strong>Status:</strong> <span class="badge ${statusClass}">${escapeHtml(statusText)}</span></p>
                        ${leave.approver_name ? `<p><strong>Approved By:</strong> ${escapeHtml(leave.approver_name)}</p>` : ''}
                        ${leave.approved_at ? `<p><strong>Approved At:</strong> ${formatDate(leave.approved_at)}</p>` : ''}
                    </div>
                </div>
                ${leave.reason ? `<div class="mt-3"><h6>Reason</h6><p>${escapeHtml(leave.reason)}</p></div>` : ''}
            `;
            document.getElementById('viewLeaveContent').innerHTML = content;
            const modal = new bootstrap.Modal(document.getElementById('viewLeaveModal'));
            modal.show();
        } else {
            showNotification('Error loading leave: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        handleApiError(error, 'loading leave details');
    }
}

// Tardiness Functions
async function viewTardinessDetails(id) {
    try {
        const response = await fetch(`${CONFIG.apiPath}/get-attendance-details.php?id=${encodeURIComponent(id)}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            const att = data.attendance;
            const scheduledTime = '08:00 AM';
            const actualTime = formatTime(att.time_in);
            const timeIn = new Date(att.log_date || att.attendance_date + ' ' + (att.time_in || '08:00:00'));
            const scheduled = new Date((att.log_date || att.attendance_date) + ' 08:00:00');
            const minutesLate = Math.floor((timeIn - scheduled) / (1000 * 60));
            const hours = Math.floor(minutesLate / 60);
            const minutes = minutesLate % 60;
            const lateTime = hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
            
            const content = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Employee Information</h6>
                        <p><strong>Safe Employee ID:</strong> ${escapeHtml(att.employee_id || 'N/A')}</p>
                        <p><strong>Name:</strong> ${escapeHtml(att.name || 'N/A')}</p>
                        <p><strong>Date:</strong> ${formatDate(att.log_date || att.attendance_date)}</p>
                    </div>
                    <div class="col-md-6">
                        <h6>Tardiness Details</h6>
                        <p><strong>Scheduled Time:</strong> ${escapeHtml(scheduledTime)}</p>
                        <p><strong>Actual Time In:</strong> ${escapeHtml(actualTime)}</p>
                        <p><strong>Minutes Late:</strong> <span class="badge bg-warning">${escapeHtml(lateTime)}</span></p>
                    </div>
                </div>
            `;
            document.getElementById('viewTardinessContent').innerHTML = content;
            const modal = new bootstrap.Modal(document.getElementById('viewTardinessModal'));
            modal.show();
        } else {
            showNotification('Error loading tardiness: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        handleApiError(error, 'loading tardiness details');
    }
}

// Absent Functions
async function viewAbsentDetailsByEmployeeId(employeeId) {
    try {
        // First get user_id from employee_id
        const userResponse = await fetch(`${CONFIG.apiPath}/get-user-by-employee-id.php?employee_id=${encodeURIComponent(employeeId)}`);
        if (!userResponse.ok) {
            throw new Error(`HTTP error! status: ${userResponse.status}`);
        }
        const userData = await userResponse.json();
        
        if (!userData.success || !userData.user_id) {
            showNotification('Error: Could not find user for employee ID: ' + employeeId, 'error');
            return;
        }
        
        const userId = userData.user_id;
        
        // Now get all absent records for this user
        const response = await fetch(`${CONFIG.apiPath}/get-user-absent.php?user_id=${encodeURIComponent(userId)}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            const records = data.absent_records || [];
            const analytics = data.analytics || {};
            const userInfo = data.user_info || {};
            
            // Build user info section
            const userInfoHtml = `
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title"><i class="bi bi-person me-2"></i>Employee Information</h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <p class="mb-1"><strong>Name:</strong> ${escapeHtml(userInfo.name || 'N/A')}</p>
                                    </div>
                                    <div class="col-md-4">
                                        <p class="mb-1"><strong>Safe Employee ID:</strong> ${escapeHtml(userInfo.employee_id || 'N/A')}</p>
                                    </div>
                                    <div class="col-md-4">
                                        <p class="mb-1"><strong>Total Absent Days:</strong> ${records.length}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Build analytics section
            const analyticsHtml = `
                <div class="row mb-4">
                    <div class="col-md-12">
                        <h5 class="mb-3"><i class="bi bi-graph-up me-2"></i>Absent Analytics</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card text-center border-danger">
                                    <div class="card-body">
                                        <h6 class="card-subtitle mb-2 text-muted">Total Absent Days</h6>
                                        <h3 class="card-title text-danger">${analytics.total_absent_days || 0}</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Build absent records table
            let recordsHtml = '';
            if (records.length > 0) {
                recordsHtml = `
                    <div class="row">
                        <div class="col-md-12">
                            <h5 class="mb-3"><i class="bi bi-calendar-x me-2"></i>Absent History</h5>
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-hover table-sm">
                                    <thead class="table-dark sticky-top">
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Absence Type</th>
                                            <th>Reason</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${records.map(record => {
                                            const date = new Date(record.date + 'T00:00:00');
                                            const dayName = date.toLocaleDateString('en-US', { weekday: 'long' });
                                            return `
                                                <tr>
                                                    <td><strong>${formatDate(record.date)}</strong></td>
                                                    <td>${dayName}</td>
                                                    <td>${escapeHtml(record.absence_type || 'Unexcused')}</td>
                                                    <td>${escapeHtml(record.reason || 'No attendance record')}</td>
                                                    <td><span class="badge bg-danger">${escapeHtml(record.status || 'Absent')}</span></td>
                                                </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                recordsHtml = `
                    <div class="row">
                        <div class="col-md-12">
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>No absent records found for this employee.
                            </div>
                        </div>
                    </div>
                `;
            }
            
            const content = userInfoHtml + analyticsHtml + recordsHtml;
            document.getElementById('viewAbsentContent').innerHTML = content;
            const modal = new bootstrap.Modal(document.getElementById('viewAbsentModal'));
            modal.show();
        } else {
            showNotification('Error loading absent records: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        handleApiError(error, 'loading absent details');
    }
}

/**
 * Session Heartbeat - Keeps timekeeper session alive
 * Refreshes session every 5 minutes to prevent timeout
 */
function startSessionHeartbeat() {
    const refreshInterval = 5 * 60 * 1000; // 5 minutes in milliseconds
    
    setInterval(async function() {
        try {
            const response = await fetch('api/refresh-session.php', {
                method: 'GET',
                credentials: 'same-origin' // Include cookies
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    console.log('Session refreshed at', new Date().toLocaleTimeString());
                }
            } else if (response.status === 401) {
                // Session expired - redirect to login
                console.warn('Session expired, redirecting to login...');
                window.location.href = '../station_login.php';
            }
        } catch (error) {
            console.error('Error refreshing session:', error);
            // Don't redirect on network errors, just log
        }
    }, refreshInterval);
    
    console.log('Session heartbeat started - session will be refreshed every 5 minutes');
}

// Initialize dashboard when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Start session heartbeat to prevent timeout
    startSessionHeartbeat();
    
    // Load attendance data on page load (first tab is active by default)
    loadAttendanceData();
    
    // Add event listeners for tab changes
    const tabButtons = document.querySelectorAll('#dashboardTabs button[data-bs-toggle="tab"]');
    tabButtons.forEach(button => {
        button.addEventListener('shown.bs.tab', function(event) {
            const targetId = event.target.getAttribute('data-bs-target');
            
            switch(targetId) {
                case '#attendance':
                    loadAttendanceData();
                    break;
                case '#employees':
                    loadEmployeesData();
                    break;
                case '#leave':
                    loadLeaveData();
                    break;
                case '#tardiness':
                    loadTardinessData();
                    break;
                case '#absent':
                    loadAbsentData();
                    break;
            }
        });
    });
});

