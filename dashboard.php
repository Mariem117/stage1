<?php
require_once 'config.php';
requireLogin();
requireAdmin();

$page_title = "Dashboard";

// Fetch statistics for the dashboard
$stmt = $pdo->query("SELECT COUNT(*) as total_employees FROM employee_profiles");
$total_employees = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) as active_employees FROM employee_profiles WHERE status = 'active'");
$active_employees = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) as inactive_employees FROM employee_profiles WHERE status = 'inactive'");
$inactive_employees = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) as dismissed_employees FROM employee_profiles WHERE status = 'dismissed'");
$dismissed_employees = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT gender, COUNT(*) as count FROM employee_profiles GROUP BY gender");
$gender_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);


$stmt = $pdo->query("SELECT 
    CASE 
        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 20 THEN '<20'
        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 20 AND 29 THEN '20-29'
        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 30 AND 39 THEN '30-39'
        WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 40 AND 49 THEN '40-49'
        ELSE '50+' 
    END as age_range, 
    COUNT(*) as count 
    FROM employee_profiles 
    GROUP BY age_range");
$age_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch average salary by department
$stmt = $pdo->query("SELECT department, AVG(salary) as avg_salary 
    FROM employee_profiles 
    WHERE department IN ('General Management', 'Production Department', 'Quality Department', 'Logistics Department', 'Human Resources Department', 'Maintenance Department', 'Information Technology Department') 
    GROUP BY department");
$salary_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch recent employees
$stmt = $pdo->prepare("SELECT ep.first_name, ep.last_name, ep.employee_id, ep.status 
    FROM employee_profiles ep 
    ORDER BY ep.created_at DESC 
    LIMIT 5");
$stmt->execute();
$recent_employees = $stmt->fetchAll();

// Fetch department counts for specific departments
$departments = ['General Management' => 0, 'Production Department' => 0, 'Quality Department' => 0, 'Logistics Department' => 0, 'Human Resources Department' => 0, 'Maintenance Department' => 0, 'Information Technology Department' => 0];
$stmt = $pdo->query("SELECT department, COUNT(*) as count 
    FROM employee_profiles 
    WHERE department IN ('General Management', 'Production Department', 'Quality Department', 'Logistics Department', 'Human Resources Department', 'Maintenance Department', 'Information Technology Department') 
    GROUP BY department");
$results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($results as $dept => $count) {
    if (array_key_exists($dept, $departments)) {
        $departments[$dept] = $count;
    }
}
$stmt = $pdo->query("SELECT COUNT(*) as present_today FROM time_records WHERE DATE(check_in) = CURDATE()");
$present_today = $stmt->fetchColumn() ?: 0;

// Employés en retard aujourd'hui (après 8h30)
$stmt = $pdo->query("SELECT COUNT(*) as late_arrivals FROM time_records WHERE DATE(check_in) = CURDATE() AND TIME(check_in) > '08:30:00'");
$late_arrivals = $stmt->fetchColumn() ?: 0;

// Total heures travaillées aujourd'hui
$stmt = $pdo->query("SELECT SUM(TIMESTAMPDIFF(MINUTE, check_in, check_out)) as total_minutes FROM time_records WHERE DATE(check_in) = CURDATE() AND check_out IS NOT NULL");
$total_minutes = $stmt->fetchColumn() ?: 0;
$total_hours_today = round($total_minutes / 60, 1);

// Employés actuellement présents (pointés mais pas encore sortis)
$stmt = $pdo->query("SELECT COUNT(*) as currently_present FROM time_records WHERE DATE(check_in) = CURDATE() AND check_out IS NULL");
$currently_present = $stmt->fetchColumn() ?: 0;

// Top 5 employés ponctuels cette semaine
$stmt = $pdo->prepare("SELECT ep.first_name, ep.last_name, ep.employee_id, 
    COUNT(*) as days_present,
    ROUND(AVG(CASE WHEN TIME(tr.check_in) <= '08:30:00' THEN 1 ELSE 0 END) * 100, 1) as punctuality_rate
    FROM employee_profiles ep
    JOIN time_records tr ON ep.employee_id = tr.employee_id
    WHERE YEARWEEK(tr.check_in) = YEARWEEK(CURDATE())
    GROUP BY ep.employee_id
    HAVING days_present > 0
    ORDER BY punctuality_rate DESC, days_present DESC
    LIMIT 5");
$stmt->execute();
$punctual_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Présence par département aujourd'hui
$stmt = $pdo->query("SELECT ep.department, 
    COUNT(DISTINCT CASE WHEN DATE(tr.check_in) = CURDATE() THEN tr.employee_id END) as present_count,
    COUNT(DISTINCT ep.employee_id) as total_count,
    ROUND((COUNT(DISTINCT CASE WHEN DATE(tr.check_in) = CURDATE() THEN tr.employee_id END) / COUNT(DISTINCT ep.employee_id)) * 100, 1) as presence_rate
    FROM employee_profiles ep
    LEFT JOIN time_records tr ON ep.employee_id = tr.employee_id
    WHERE ep.department IN ('General Management', 'Production Department', 'Quality Department', 'Logistics Department', 'Human Resources Department', 'Maintenance Department', 'Information Technology Department') AND ep.status = 'active'
    GROUP BY ep.department
    ORDER BY presence_rate DESC");
$presence_by_dept = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcul du taux de présence global
$presence_rate = $active_employees > 0 ? round(($present_today / $active_employees) * 100, 1) : 0;
$late_rate = $present_today > 0 ? round(($late_arrivals / $present_today) * 100, 1) : 0;

$page_title = "Admin Dashboard";
$additional_css = [];
$include_chartjs = true;
$additional_css = ["dashboard.css"];
include 'admin_header.php';
?>

    <div class="container">
        <div class="welcome-section">
            <h1>Welcome, Admin!</h1>
            <p>Manage your employeeS records and system settings efficiently.</p>
        </div>
        <div class="time-stamp-section">
            <h2>🕐 YURA Time Tracking - Real Time <span class="live-indicator"></span></h2>
            <div class="time-stats-grid">
                <div class="time-stat-card">
                    <div class="time-stat-number"><?php echo $present_today; ?></div>
                    <div class="time-stat-label">Attendance Today</div>
                </div>
                <div class="time-stat-card">
                    <div class="time-stat-number"><?php echo $currently_present; ?></div>
                    <div class="time-stat-label">Currently Present</div>
                </div>
                <div class="time-stat-card">
                    <div class="time-stat-number"><?php echo $late_arrivals; ?></div>
                    <div class="time-stat-label">Late Arrivals Today</div>
                </div>
                <div class="time-stat-card">
                    <div class="time-stat-number"><?php echo $total_hours_today; ?>h</div>
                    <div class="time-stat-label">Hours Worked</div>
                </div>
            </div>
        </div>


        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_employees; ?></div>
                <div class="stat-label">Total Employees</div>
            </div>
            <div class="stat-card active">
                <div class="stat-number"><?php echo $active_employees; ?></div>
                <div class="stat-label">Active Employees</div>
            </div>
            <div class="stat-card presence">
                <div class="stat-number"><?php echo $presence_rate; ?>%</div>
                <div class="stat-label">Attendance Rate</div>
            </div>
            <div class="stat-card late-rate">
                <div class="stat-number"><?php echo $late_rate; ?>%</div>
                <div class="stat-label">Late Rate</div>
            </div>
        </div>
        <div class="dashboard-grid">
            <div class="card">
                <h2> Top Punctuality (This week)</h2>
                <?php if (!empty($punctual_employees)): ?>
                    <table class="punctuality-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>ID</th>
                                <th>Days</th>
                                <th>Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($punctual_employees as $emp): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($emp['employee_id']); ?></td>
                                    <td><?php echo $emp['days_present']; ?></td>
                                    <td class="punctuality-rate"><?php echo $emp['punctuality_rate']; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No attendance data this week</p>
                <?php endif; ?>
            </div>

            <!-- Attendance by Department -->
            <div class="card">
                <h2> Attendance by Department</h2>
                <ul class="dept-list">
                    <?php foreach ($presence_by_dept as $dept): ?>
                        <li class="dept-item">
                            <span class="presence-indicator <?php
                            echo $dept['presence_rate'] >= 90 ? 'present' :
                                ($dept['presence_rate'] >= 70 ? 'partial' : 'absent');
                            ?>"></span>
                            <span class="dept-name"><?php echo htmlspecialchars($dept['department']); ?></span>
                            <span
                                class="dept-count"><?php echo $dept['present_count'] . '/' . $dept['total_count']; ?></span>
                            <span
                                style="color: #666; font-size: 0.9em; margin-left: 8px;">(<?php echo $dept['presence_rate']; ?>%)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>

            </div>
                <div class="chart-container large-chart">
                    <h3>Department Distribution</h3>
                    <canvas id="departmentChart"></canvas>
                </div>
                <div class="chart-container">
                    <h3>Gender Distribution</h3>
                    <canvas id="genderChart"></canvas>
                </div>
                <div class="chart-container">
                    <h3>Age Segmentation</h3>
                    <canvas id="ageChart"></canvas>
                </div>

            </div>
        </div>
        </div>

        <script>
            const genderData = <?php echo json_encode($gender_data); ?>;
            const ageData = <?php echo json_encode($age_data); ?>;
            const departmentData = <?php echo json_encode($departments); ?>;
            const salaryData = <?php echo json_encode($salary_data); ?>;

            const presenceData = <?php echo json_encode($presence_by_dept); ?>;
            const timeStampStats = {
                presentToday: <?php echo $present_today; ?>,
                currentlyPresent: <?php echo $currently_present; ?>,
                lateArrivals: <?php echo $late_arrivals; ?>,
                totalHours: <?php echo $total_hours_today; ?>,
                presenceRate: <?php echo $presence_rate; ?>,
                lateRate: <?php echo $late_rate; ?>
            };

            // Automatic refresh every 5 minutes
            setInterval(function () {
                location.reload();
            }, 300000); // 5 minutes

            function updateTimeStampStats() {
                fetch('ajax/get_timestamp_stats.php')
                    .then(response => response.json())
                    .then(data => {
                        // Update DOM elements with new data
                        console.log('Stats updated:', data);
                    })
                    .catch(error => console.error('Error:', error));
            }

            console.log('Dashboard Time Stamp initialized successfully');
        </script>
        <script src="dashboard.js"></script>
</body>

</html>