<?php
require_once 'config.php';
requireLogin();
requireAdmin();

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

$stmt = $pdo->query("SELECT education, COUNT(*) as count FROM employee_profiles GROUP BY education");
$education_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

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
    WHERE department IN ('IT', 'HR', 'Finance', 'Marketing', 'Production','Maintenance') 
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
$departments = ['IT' => 0, 'HR' => 0, 'Finance' => 0, 'Marketing' => 0, 'Production' => 0, 'Maintenance' => 0];
$stmt = $pdo->query("SELECT department, COUNT(*) as count 
    FROM employee_profiles 
    WHERE department IN ('IT', 'HR', 'Finance', 'Marketing', 'Production', 'Maintenance') 
    GROUP BY department");
$results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($results as $dept => $count) {
    if (array_key_exists($dept, $departments)) {
        $departments[$dept] = $count;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Employee Management System</title>
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-brand">Employee Management System</div>
            <div class="navbar-nav">
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <span class="admin-badge">ADMIN</span>
                    <a href="dashboard.php" class="nav-link">Dashboard</a>
                    <a href="employees_listing.php" class="nav-link">Employees</a>
                <?php else: ?>
                    <a href="dashboard.php" class="nav-link">Dashboard</a>
                <?php endif; ?>
                <a href="profile.php" class="nav-link">My Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="welcome-section">
            <h1>Welcome, Admin!</h1>
            <p>Manage your employee records and system settings efficiently.</p>
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
            <div class="stat-card inactive">
                <div class="stat-number"><?php echo $inactive_employees; ?></div>
                <div class="stat-label">Inactive Employees</div>
            </div>
            <div class="stat-card dismissed">
                <div class="stat-number"><?php echo $dismissed_employees; ?></div>
                <div class="stat-label">Dismissed Employees</div>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="card">
                <h2>Recent Employees</h2>
                <table class="employee-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Employee ID</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_employees as $emp): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($emp['employee_id']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $emp['status']; ?>">
                                        <?php echo ucfirst($emp['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h2>Departments</h2>
                <ul class="dept-list">
                    <?php foreach ($departments as $dept => $count): ?>
                        <li class="dept-item">
                            <span class="dept-name"><?php echo htmlspecialchars($dept); ?></span>
                            <span class="dept-count"><?php echo $count; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="chart-container">
                <h3>Gender Distribution</h3>
                <canvas id="genderChart"></canvas>
            </div>
            <div class="chart-container">
                <h3>Education Level</h3>
                <canvas id="educationChart"></canvas>
            </div>
            <div class="chart-container">
                <h3>Age Segmentation</h3>
                <canvas id="ageChart"></canvas>
            </div>
            <div class="chart-container">
                <h3>Department Distribution</h3>
                <canvas id="departmentChart"></canvas>
            </div>
        </div>
    </div>

    <script>
        const genderData = <?php echo json_encode($gender_data); ?>;
        const educationData = <?php echo json_encode($education_data); ?>;
        const ageData = <?php echo json_encode($age_data); ?>;
        const departmentData = <?php echo json_encode($departments); ?>;
        const salaryData = <?php echo json_encode($salary_data); ?>;
    </script>
    <script src="dashboard.js"></script>
</body>

</html>