<?php
/**
 * Shared salary calculation logic for employee payroll.
 * Used by calculate_salary_api.php (single) and batch_calculate_salary_api.php (batch).
 *
 * Formula:
 * - Full month: Gross = weekly-based (40 hrs/week). Per week: full weekly salary if 40+ hrs, else prorated.
 * - Quincena (first/second, 15 days): Gross = (total_hours / 96) × (monthly_rate/2), capped at monthly_rate/2.
 *   So e.g. monthly ₱22,938 → Quincena base ₱11,469; pay is proportional to hours worked in that period (96 hrs = full Quincena).
 * - Daily rate: monthly_rate / 24 (24 working days/month)
 * - Absence deduction: 2x daily rate per absence
 * - Net: (Gross + Additions) - Deductions
 */
if (!class_exists('SalaryCalculator')) {
class SalaryCalculator {

    /** @var PDO */
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Calculate salary for one employee.
     * @param string $employee_id
     * @param string $position
     * @param int $year
     * @param string $month (2-digit)
     * @param string $period 'full', 'first', 'second'
     * @param string|null $date_from Optional Y-m-d start date (overrides year/month/period when with $date_to)
     * @param string|null $date_to Optional Y-m-d end date
     * @param float|null $override_annual_salary Optional pre-resolved annual salary (e.g. from batch join with position_salary)
     * @param string|null $dtrFilterMode Optional 'verified' or 'submitted' to filter logs by DTR (align with Employee Management)
     * @return array ['success' => bool, 'data' => array, 'message' => string]
     */
    public function calculate($employee_id, $position, $year, $month, $period = 'full', $date_from = null, $date_to = null, $override_annual_salary = null, $dtrFilterMode = null) {
        try {
            $month = str_pad((int)$month, 2, '0', STR_PAD_LEFT);

            $position_warning = '';
            $annual_salary = 0;

            // Use override when provided (batch API passes pre-resolved salary from same join as employee_logs)
            if ($override_annual_salary !== null && $override_annual_salary > 0) {
                $annual_salary = floatval($override_annual_salary);
                if ($annual_salary < 200000) {
                    $annual_salary = $annual_salary * 12;
                    $position_warning = 'Override: detected monthly, converted to annual.';
                }
            } else {
                // Get annual salary from position_salary (same logic as employee_logs: MIN id per position_title)
                $stmt = $this->db->prepare("
                    SELECT ps1.annual_salary FROM position_salary ps1
                    INNER JOIN (
                        SELECT position_title, MIN(id) as min_id FROM position_salary GROUP BY position_title
                    ) ps2 ON ps1.position_title = ps2.position_title AND ps1.id = ps2.min_id
                    WHERE ps1.position_title = ?
                    LIMIT 1
                ");
                $stmt->execute([$position]);
                $pos_salary_row = $stmt->fetch();

                if ($pos_salary_row && !empty($pos_salary_row['annual_salary'])) {
                    $annual_salary = floatval($pos_salary_row['annual_salary']);
                    if ($annual_salary > 0 && $annual_salary < 200000) {
                        $annual_salary = $annual_salary * 12;
                        $position_warning = 'Detected monthly salary in annual_salary field; converted to annual.';
                    }
                } else {
                    $stmt = $this->db->prepare("SELECT monthly_rate FROM positions WHERE position_name = ? LIMIT 1");
                    $stmt->execute([$position]);
                    $pos_row = $stmt->fetch();
                    if ($pos_row && !empty($pos_row['monthly_rate'])) {
                        $annual_salary = floatval($pos_row['monthly_rate']) * 12;
                        $position_warning = 'Using monthly_rate from positions table.';
                    } else {
                        $baseAnnualRate = 231600;
                        if (stripos($position, 'Instructor 2') !== false) $baseAnnualRate = 252000;
                        elseif (stripos($position, 'Instructor 3') !== false) $baseAnnualRate = 276000;
                        $annual_salary = floatval($baseAnnualRate);
                        $position_warning = 'Used fallback annual rate.';
                    }
                }
            }

            $monthly_rate = $annual_salary / 12;
            $daily_rate = $monthly_rate / 24;
            $hours_per_month = 192;
            $hourly_rate = $monthly_rate / $hours_per_month;
            $weekly_rate = $monthly_rate / 4.8;

            $first_day_of_month = "$year-$month-01";
            $last_day_of_month = date("Y-m-t", strtotime($first_day_of_month));

            if (!empty($date_from) && !empty($date_to)) {
                $first_day = $date_from;
                $last_day = $date_to;
            } elseif ($period === 'first') {
                $first_day = $first_day_of_month;
                $last_day = "$year-$month-15";
            } elseif ($period === 'second') {
                $first_day = "$year-$month-16";
                $last_day = $last_day_of_month;
            } else {
                $first_day = $first_day_of_month;
                $last_day = $last_day_of_month;
            }

            $stmt = $this->db->prepare("
                SELECT log_date, time_in, lunch_out, lunch_in, time_out, ot_in, ot_out, remarks, holiday_id
                FROM attendance_logs
                WHERE employee_id = ? AND log_date BETWEEN ? AND ?
                ORDER BY log_date ASC
            ");
            $stmt->execute([$employee_id, $first_day, $last_day]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Filter logs by DTR submitted/verified dates (align with Employee Management display)
            if (($dtrFilterMode === 'verified' || $dtrFilterMode === 'submitted') && !empty($logs)) {
                try {
                    $tblCheck = $this->db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
                    if ($tblCheck && $tblCheck->rowCount() > 0) {
                        $stmtUser = $this->db->prepare("SELECT user_id FROM faculty_profiles WHERE employee_id = ? LIMIT 1");
                        $stmtUser->execute([$employee_id]);
                        $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
                        if ($userRow) {
                            $colCheck = $this->db->query("SHOW COLUMNS FROM dtr_daily_submissions LIKE 'dean_verified_at'");
                            $hasVerified = $colCheck && $colCheck->rowCount() > 0;
                            if ($dtrFilterMode === 'verified' && $hasVerified) {
                                $stmtDtr = $this->db->prepare("SELECT log_date FROM dtr_daily_submissions WHERE user_id = ? AND dean_verified_at IS NOT NULL AND log_date BETWEEN ? AND ?");
                                $stmtDtr->execute([$userRow['user_id'], $first_day, $last_day]);
                            } else {
                                $stmtDtr = $this->db->prepare("SELECT log_date FROM dtr_daily_submissions WHERE user_id = ? AND log_date BETWEEN ? AND ?");
                                $stmtDtr->execute([$userRow['user_id'], $first_day, $last_day]);
                            }
                            $allowedDates = $stmtDtr->fetchAll(PDO::FETCH_COLUMN);
                            $allowedSet = array_flip($allowedDates);
                            $logs = array_filter($logs, function($log) use ($allowedSet) {
                                return isset($allowedSet[$log['log_date']]);
                            });
                            $logs = array_values($logs);
                        }
                    }
                } catch (Exception $e) { /* ignore filter on error */ }
            }

            $parseTimeToMinutes = function($timeStr) {
                if (empty($timeStr) || $timeStr === null) return null;
                $timeStr = trim((string)$timeStr);
                $parts = explode(':', $timeStr);
                if (count($parts) >= 2) {
                    return (intval($parts[0]) * 60) + intval($parts[1]);
                }
                return null;
            };

            $stmt = $this->db->prepare("
                SELECT start_date, end_date, weekday, time_in, lunch_out, lunch_in, time_out
                FROM employee_official_times WHERE employee_id = ?
                ORDER BY start_date DESC
            ");
            $stmt->execute([$employee_id]);
            $official_times_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $getWeekdayName = function($dateStr) {
                $date = new DateTime($dateStr);
                $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                return $weekdays[(int)$date->format('w')];
            };

            $getWeekKey = function($dateStr) {
                $date = new DateTime($dateStr);
                return $date->format('Y') . '-W' . $date->format('W');
            };

            $default_official_times = [
                'time_in' => '08:00:00', 'lunch_out' => '12:00:00',
                'lunch_in' => '13:00:00', 'time_out' => '17:00:00'
            ];

            $total_hours = 0;
            $total_late = 0;
            $total_undertime = 0;
            $total_overtime = 0;
            $days_worked = 0;
            $total_absences = 0;
            $weekly_hours = [];

            // For COC: hours worked on days with no official time go to COC (permanent/temporary only)
            $employment_status = '';
            $stmtES = $this->db->prepare("SELECT employment_status FROM faculty_profiles WHERE employee_id = ? LIMIT 1");
            $stmtES->execute([$employee_id]);
            $esRow = $stmtES->fetch(PDO::FETCH_ASSOC);
            if ($esRow && !empty($esRow['employment_status'])) {
                $employment_status = trim($esRow['employment_status']);
            }
            $isPermanentOrTemporary = (strcasecmp($employment_status, 'Permanent') === 0 || strcasecmp($employment_status, 'Temporary') === 0);

            foreach ($logs as $log) {
                $logDate = $log['log_date'];
                $logWeekday = $getWeekdayName($logDate);
                $official = $default_official_times;
                $mostRecentStartDate = null;

                foreach ($official_times_list as $ot) {
                    $startDate = $ot['start_date'];
                    $endDate = $ot['end_date'];
                    $weekday = $ot['weekday'] ?? null;
                    $weekdayMatches = ($weekday === null || $weekday === $logWeekday);
                    if ($weekdayMatches && $startDate <= $logDate && ($endDate === null || $endDate >= $logDate)) {
                        if ($mostRecentStartDate === null || $startDate > $mostRecentStartDate) {
                            $mostRecentStartDate = $startDate;
                            $official = $ot;
                        }
                    }
                }

                $official_in_minutes = $parseTimeToMinutes($official['time_in']);
                $official_out_minutes = $parseTimeToMinutes($official['time_out']);
                $official_lunch_out_minutes = $parseTimeToMinutes($official['lunch_out']);
                $official_lunch_in_minutes = $parseTimeToMinutes($official['lunch_in']);

                // Approved leave pardon: DB times are cleared; DTR shows LEAVE — credit rendered hours from official schedule only
                $is_leave = (strtoupper(trim($log['remarks'] ?? '')) === 'LEAVE');
                if ($is_leave) {
                    if ($official_in_minutes === null || $official_out_minutes === null ||
                        $official_lunch_out_minutes === null || $official_lunch_in_minutes === null) {
                        continue;
                    }
                    $official_base_minutes = ($official_lunch_out_minutes - $official_in_minutes)
                        + ($official_out_minutes - $official_lunch_in_minutes);
                    $actual_hours = max(0, $official_base_minutes / 60);
                    if ($actual_hours <= 0) {
                        continue;
                    }
                    $days_worked++;
                    $total_hours += $actual_hours;
                    $weekKey = $getWeekKey($logDate);
                    if (!isset($weekly_hours[$weekKey])) {
                        $weekly_hours[$weekKey] = 0;
                    }
                    $weekly_hours[$weekKey] += $actual_hours;
                    if (!empty($log['ot_in']) && !empty($log['ot_out'])) {
                        $ot_in_minutes = $parseTimeToMinutes($log['ot_in']);
                        $ot_out_minutes = $parseTimeToMinutes($log['ot_out']);
                        if ($ot_in_minutes !== null && $ot_out_minutes !== null && $ot_out_minutes > $ot_in_minutes) {
                            $total_overtime += ($ot_out_minutes - $ot_in_minutes) / 60;
                        }
                    }
                    continue;
                }

                // Holidays (full or half-day): credit payroll hours from official schedule; never count as absence or late/undertime
                if ($this->attendanceLogIsHoliday($log)) {
                    $officialHasLunch = $this->officialScheduleHasLunch($official);
                    $remarksTrim = trim($log['remarks'] ?? '');
                    $isHalfDayHoliday = (strpos($remarksTrim, 'Holiday (Half-day') === 0);

                    if ($isHalfDayHoliday) {
                        if ($official_in_minutes === null || $official_out_minutes === null) {
                            continue;
                        }
                        if ($officialHasLunch && ($official_lunch_out_minutes === null || $official_lunch_in_minutes === null)) {
                            continue;
                        }
                        $localIsTimeLogged = function ($time) {
                            if (empty($time)) {
                                return false;
                            }
                            $time = trim((string) $time);
                            if (strtoupper($time) === 'HOLIDAY' || strtoupper($time) === 'LEAVE') {
                                return false;
                            }
                            return $time !== '00:00' && $time !== '00:00:00' && $time !== '0:00' && $time !== '0:00:00';
                        };
                        $isHol = function ($v) {
                            return $v !== null && strtoupper(trim((string) $v)) === 'HOLIDAY';
                        };
                        $actual_hours = $this->halfDayHolidayPayrollHours(
                            $log,
                            $official_in_minutes,
                            $official_out_minutes,
                            $official_lunch_out_minutes,
                            $official_lunch_in_minutes,
                            $officialHasLunch,
                            $parseTimeToMinutes,
                            $localIsTimeLogged,
                            $isHol
                        );
                        if ($actual_hours === null || $actual_hours <= 0) {
                            continue;
                        }
                    } elseif (!$officialHasLunch && $official_in_minutes !== null && $official_out_minutes !== null) {
                        $actual_hours = max(0, ($official_out_minutes - $official_in_minutes) / 60);
                    } elseif ($official_in_minutes !== null && $official_out_minutes !== null
                        && $official_lunch_out_minutes !== null && $official_lunch_in_minutes !== null) {
                        $official_base_minutes = ($official_lunch_out_minutes - $official_in_minutes)
                            + ($official_out_minutes - $official_lunch_in_minutes);
                        $actual_hours = max(0, $official_base_minutes / 60);
                    } else {
                        continue;
                    }

                    $days_worked++;
                    $total_hours += $actual_hours;
                    $weekKey = $getWeekKey($logDate);
                    if (!isset($weekly_hours[$weekKey])) {
                        $weekly_hours[$weekKey] = 0;
                    }
                    $weekly_hours[$weekKey] += $actual_hours;

                    if (!empty($log['ot_in']) && !empty($log['ot_out'])) {
                        $ot_in_minutes = $parseTimeToMinutes($log['ot_in']);
                        $ot_out_minutes = $parseTimeToMinutes($log['ot_out']);
                        if ($ot_in_minutes !== null && $ot_out_minutes !== null && $ot_out_minutes > $ot_in_minutes) {
                            $total_overtime += ($ot_out_minutes - $ot_in_minutes) / 60;
                        }
                    }
                    continue;
                }

                $isTimeLogged = function($time) {
                    if (empty($time)) return false;
                    $time = trim($time);
                    return $time !== '00:00' && $time !== '00:00:00' && $time !== '0:00' && $time !== '0:00:00';
                };

                $has_time_in = $isTimeLogged($log['time_in']);
                $has_lunch_out = $isTimeLogged($log['lunch_out']);
                $has_lunch_in = $isTimeLogged($log['lunch_in']);
                $has_time_out = $isTimeLogged($log['time_out']);
                $morning_shift_complete = $has_time_in && $has_lunch_out;
                $afternoon_shift_complete = $has_lunch_in && $has_time_out;

                if (!$morning_shift_complete && !$afternoon_shift_complete) {
                    $total_absences++;
                    continue;
                }

                $actual_in_minutes = $has_time_in ? $parseTimeToMinutes($log['time_in']) : null;
                $actual_out_minutes = $has_time_out ? $parseTimeToMinutes($log['time_out']) : null;
                $actual_lunch_out_minutes = $has_lunch_out ? $parseTimeToMinutes($log['lunch_out']) : null;
                $actual_lunch_in_minutes = $has_lunch_in ? $parseTimeToMinutes($log['lunch_in']) : null;

                if ($official_in_minutes === null || $official_out_minutes === null ||
                    $official_lunch_out_minutes === null || $official_lunch_in_minutes === null) {
                    continue;
                }

                $effective_out_minutes = $actual_out_minutes;
                if ($has_time_out && $actual_out_minutes !== null && empty($log['ot_out']) && $actual_out_minutes > $official_out_minutes) {
                    $effective_out_minutes = $official_out_minutes;
                }

                $morning_minutes = 0;
                if ($morning_shift_complete && $actual_in_minutes !== null && $actual_lunch_out_minutes !== null) {
                    $morning_minutes = max(0, $actual_lunch_out_minutes - $actual_in_minutes);
                }
                $afternoon_minutes = 0;
                if ($afternoon_shift_complete && $actual_lunch_in_minutes !== null && $actual_out_minutes !== null) {
                    $afternoon_minutes = max(0, $effective_out_minutes - $actual_lunch_in_minutes);
                }

                $actual_hours = ($morning_minutes + $afternoon_minutes) / 60;

                // Cap hours at official base when no OT logged (align with Employee Management DTR display)
                $has_ot_logged = !empty(trim($log['ot_in'] ?? '')) && !empty(trim($log['ot_out'] ?? ''));
                if (!$has_ot_logged && $actual_hours > 0 && $official_in_minutes !== null && $official_out_minutes !== null
                    && $official_lunch_out_minutes !== null && $official_lunch_in_minutes !== null) {
                    $official_base_minutes = ($official_lunch_out_minutes - $official_in_minutes)
                        + ($official_out_minutes - $official_lunch_in_minutes);
                    $official_base_hours = $official_base_minutes / 60;
                    if ($actual_hours > $official_base_hours) {
                        $actual_hours = $official_base_hours;
                    }
                }

                // Hours on a day with no official time (perm/temp only): COC only — do NOT count in payroll/salary
                if ($isPermanentOrTemporary && $mostRecentStartDate === null && $actual_hours > 0) {
                    $total_overtime += $actual_hours;
                } else {
                    $days_worked++;
                    $total_hours += $actual_hours;

                    $weekKey = $getWeekKey($logDate);
                    if (!isset($weekly_hours[$weekKey])) $weekly_hours[$weekKey] = 0;
                    $weekly_hours[$weekKey] += $actual_hours;

                    $late_minutes = 0;
                    if ($morning_shift_complete && $actual_in_minutes !== null && $actual_in_minutes > $official_in_minutes) {
                        $late_minutes += ($actual_in_minutes - $official_in_minutes);
                    }
                    if ($afternoon_shift_complete && $actual_lunch_in_minutes !== null && $actual_lunch_in_minutes > $official_lunch_in_minutes) {
                        $late_minutes += ($actual_lunch_in_minutes - $official_lunch_in_minutes);
                    }
                    if ($late_minutes > 0) $total_late += $late_minutes / 60;

                    $undertime_minutes = 0;
                    if ($morning_shift_complete && $actual_lunch_out_minutes !== null && $actual_lunch_out_minutes < $official_lunch_out_minutes) {
                        $undertime_minutes += ($official_lunch_out_minutes - $actual_lunch_out_minutes);
                    }
                    if ($afternoon_shift_complete && $effective_out_minutes !== null && $effective_out_minutes < $official_out_minutes) {
                        $undertime_minutes += ($official_out_minutes - $effective_out_minutes);
                    }
                    if ($undertime_minutes > 0) $total_undertime += $undertime_minutes / 60;

                    if (!empty($log['ot_in']) && !empty($log['ot_out'])) {
                        $ot_in_minutes = $parseTimeToMinutes($log['ot_in']);
                        $ot_out_minutes = $parseTimeToMinutes($log['ot_out']);
                        if ($ot_in_minutes !== null && $ot_out_minutes !== null && $ot_out_minutes > $ot_in_minutes) {
                            $total_overtime += ($ot_out_minutes - $ot_in_minutes) / 60;
                        }
                    }
                }
            }

            $absence_deduction = $total_absences * $daily_rate * 2;

            $stmt = $this->db->prepare("
                SELECT d.item_name, d.type, d.dr_cr, ed.amount
                FROM employee_deductions ed
                JOIN deductions d ON ed.deduction_id = d.id
                WHERE ed.employee_id = ? AND ed.is_active = 1
                AND ed.start_date <= ? AND (ed.end_date IS NULL OR ed.end_date >= ?)
                ORDER BY d.order_num ASC
            ");
            $stmt->execute([$employee_id, $last_day, $first_day]);
            $additional_deductions = [];
            $total_deductions_only = 0;
            $total_additions = 0;

            while ($deduction = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $amount = floatval($deduction['amount']);
                $itemName = strtolower($deduction['item_name'] ?? '');
                $isPhilHealth = stripos($itemName, 'philhealth') !== false || stripos($itemName, 'phil health') !== false;

                if ($isPhilHealth && ($period === 'first' || $period === 'second')) {
                    $amount = $amount / 2;
                    $deduction['amount'] = $amount;
                }

                $isDeduction = false;
                if ($isPhilHealth) {
                    $isDeduction = true;
                    $total_deductions_only += $amount;
                } elseif ($deduction['dr_cr'] === 'Dr') {
                    $isDeduction = true;
                    $total_deductions_only += $amount;
                } elseif ($deduction['dr_cr'] === 'Cr') {
                    $total_additions += $amount;
                } else {
                    $isDeduction = ($deduction['type'] === 'Deduct' || ($deduction['type'] !== 'Add'));
                    if ($isDeduction) $total_deductions_only += $amount;
                    else $total_additions += $amount;
                }
                $additional_deductions[] = $deduction;
            }

            $total_deductions_only += $absence_deduction;
            if ($absence_deduction > 0) {
                $additional_deductions[] = [
                    'item_name' => 'Absence Deduction (Auto)',
                    'type' => 'Deduct', 'dr_cr' => 'Dr',
                    'amount' => $absence_deduction,
                    'is_automatic' => true,
                    'remarks' => "{$total_absences} absence(s) × 2 × Daily Rate"
                ];
            }

            // Quincena (first/second): base = half of monthly; gross = (hours in period / 96) * base, capped at base
            $hours_per_quincena = 96; // 192/2, 12 working days × 8 hrs
            $is_quincena_period = ($period === 'first' || $period === 'second');
            $quincena_base = $monthly_rate / 2;

            if ($is_quincena_period) {
                $gross_salary = $total_hours > 0
                    ? min($quincena_base, ($total_hours / $hours_per_quincena) * $quincena_base)
                    : 0;
            } else {
                $weekly_salary = $monthly_rate / 4.8;
                $required_weekly_hours = 40.0;
                $gross_salary = 0;
                foreach ($weekly_hours as $hours_worked) {
                    if ($hours_worked >= $required_weekly_hours) {
                        $gross_salary += $weekly_salary;
                    } else {
                        $gross_salary += ($hours_worked / $required_weekly_hours) * $weekly_salary;
                    }
                }
            }

            $adjusted_gross_salary = $gross_salary + $total_additions;
            $net_income = $adjusted_gross_salary - $total_deductions_only;
            $additional_deductions_total = $total_additions - $total_deductions_only;

            return [
                'success' => true,
                'data' => [
                    'employee_id' => $employee_id,
                    'position' => $position,
                    'month' => $month,
                    'year' => $year,
                    'total_hours' => number_format($total_hours, 2, '.', ''),
                    'total_late' => number_format($total_late, 2, '.', ''),
                    'total_undertime' => number_format($total_undertime, 2, '.', ''),
                    'total_overtime' => number_format($total_overtime, 2, '.', ''),
                    'total_coc_points' => number_format($total_overtime, 2, '.', ''),
                    'days_worked' => $days_worked,
                    'total_absences' => $total_absences,
                    'annual_salary' => number_format($annual_salary, 2, '.', ''),
                    'monthly_rate' => number_format($monthly_rate, 2, '.', ''),
                    'weekly_rate' => number_format($weekly_rate, 2, '.', ''),
                    'daily_rate' => number_format($daily_rate, 2, '.', ''),
                    'hourly_rate' => number_format($hourly_rate, 2, '.', ''),
                    'absence_deduction' => number_format($absence_deduction, 2, '.', ''),
                    'additional_deductions_total' => number_format($additional_deductions_total, 2, '.', ''),
                    'total_deductions_only' => number_format($total_deductions_only, 2, '.', ''),
                    'total_additions' => number_format($total_additions, 2, '.', ''),
                    'additional_deductions' => $additional_deductions,
                    'gross_salary' => number_format(max(0, $gross_salary), 2, '.', ''),
                    'adjusted_gross_salary' => number_format(max(0, $adjusted_gross_salary), 2, '.', ''),
                    'net_income' => number_format(max(0, $net_income), 2, '.', ''),
                    'warning' => $position_warning,
                    'period_base' => $is_quincena_period ? number_format($quincena_base, 2, '.', '') : null
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    private function attendanceLogIsHoliday(array $log) {
        if (!empty($log['holiday_id'])) {
            return true;
        }
        $r = trim($log['remarks'] ?? '');
        if ($r === '') {
            return false;
        }
        return strpos($r, 'Holiday:') === 0
            || strpos($r, 'Holiday (Half-day') === 0;
    }

    private function officialScheduleHasLunch(array $official) {
        $lo = isset($official['lunch_out']) ? trim((string) $official['lunch_out']) : '';
        $li = isset($official['lunch_in']) ? trim((string) $official['lunch_in']) : '';
        return $lo !== '' && $lo !== '00:00:00' && $li !== '' && $li !== '00:00:00';
    }

    /**
     * Half-day holiday hours for payroll (matches print DTR / faculty view_logs: work half actual + holiday half official).
     * @return float|null
     */
    private function halfDayHolidayPayrollHours(
        array $log,
        $official_in_minutes,
        $official_out_minutes,
        $official_lunch_out_minutes,
        $official_lunch_in_minutes,
        $officialHasLunch,
        callable $parseTimeToMinutes,
        callable $isTimeLogged,
        callable $isHol
    ) {
        $r = trim($log['remarks'] ?? '');
        $p = null;
        if (strpos($r, 'Holiday (Half-day PM):') === 0) {
            $p = 'afternoon';
        } elseif (strpos($r, 'Holiday (Half-day AM):') === 0 || strpos($r, 'Holiday (Half-day):') === 0) {
            $p = 'morning';
        }
        if ($p !== 'morning' && $p !== 'afternoon') {
            return null;
        }

        $om = 0.0;
        $oa = 0.0;
        if ($officialHasLunch && $official_in_minutes !== null && $official_out_minutes !== null
            && $official_lunch_out_minutes !== null && $official_lunch_in_minutes !== null) {
            $om = max(0, $official_lunch_out_minutes - $official_in_minutes);
            $oa = max(0, $official_out_minutes - $official_lunch_in_minutes);
        } elseif ($official_in_minutes !== null && $official_out_minutes !== null) {
            $total = max(0, $official_out_minutes - $official_in_minutes);
            $om = $total / 2;
            $oa = $total / 2;
        } else {
            return null;
        }

        $ti = $log['time_in'] ?? null;
        $lOt = $log['lunch_out'] ?? null;
        $lIn = $log['lunch_in'] ?? null;
        $tOut = $log['time_out'] ?? null;

        $hasTi = $isTimeLogged($ti) && !$isHol($ti);
        $hasLo = $isTimeLogged($lOt) && !$isHol($lOt);
        $hasLi = $isTimeLogged($lIn) && !$isHol($lIn);
        $hasTo = $isTimeLogged($tOut) && !$isHol($tOut);
        $holTi = $isHol($ti);
        $holLo = $isHol($lOt);
        $holLi = $isHol($lIn);
        $holTo = $isHol($tOut);

        $hours = 0.0;
        if ($p === 'afternoon') {
            if ($hasTi && $hasLo) {
                $aIn = $parseTimeToMinutes($ti);
                $aLo = $parseTimeToMinutes($lOt);
                if ($aIn !== null && $aLo !== null) {
                    $hours += max(0, ($aLo - $aIn) / 60);
                }
            } else {
                $hours += $om / 60;
            }
            if ($holLi && $holTo) {
                $hours += $oa / 60;
            } elseif ($hasLi && $hasTo) {
                $aLi = $parseTimeToMinutes($lIn);
                $aTo = $parseTimeToMinutes($tOut);
                if ($aLi !== null && $aTo !== null) {
                    $hours += max(0, ($aTo - $aLi) / 60);
                }
            } else {
                $hours += $oa / 60;
            }
        } else {
            if ($holTi && $holLo) {
                $hours += $om / 60;
            } elseif ($hasTi && $hasLo) {
                $aIn = $parseTimeToMinutes($ti);
                $aLo = $parseTimeToMinutes($lOt);
                if ($aIn !== null && $aLo !== null) {
                    $hours += max(0, ($aLo - $aIn) / 60);
                }
            } else {
                $hours += $om / 60;
            }
            if ($hasLi && $hasTo) {
                $aLi = $parseTimeToMinutes($lIn);
                $aTo = $parseTimeToMinutes($tOut);
                if ($aLi !== null && $aTo !== null) {
                    $hours += max(0, ($aTo - $aLi) / 60);
                }
            }
        }
        return round($hours, 2);
    }
}
}
