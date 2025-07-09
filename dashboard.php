<?php
require_once 'config.php';
requireAdmin();

// Get statistics
$stmt = $pdo->prepare("SELECT COUNT(*) as total_employees FROM employee_profiles WHERE user_id != 1");
$stmt->execute();
$total_employees = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) as active_employees FROM employee_profiles WHERE status = 'active' AND user_id != 1");
$stmt->execute();
$active_employees = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) as inactive_employees FROM employee_profiles WHERE status = 'inactive' AND user_id != 1");
$stmt->execute();
$inactive_employees = $stmt->fetchColumn();

// Get departments count
$stmt = $pdo->prepare("
    SELECT department, COUNT(*) as count 
    FROM employee_profiles 
    WHERE department IS NOT NULL AND department != '' AND user_id != 1
    GROUP BY department
");
$stmt->execute();
$departments = $stmt->fetchAll();

// Get gender distribution
$stmt = $pdo->prepare("
    SELECT gender, COUNT(*) as count 
    FROM employee_profiles 
    WHERE user_id != 1 
    GROUP BY gender
");
$stmt->execute();
$gender_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get education level distribution
$stmt = $pdo->prepare("
    SELECT education, COUNT(*) as count 
    FROM employee_profiles 
    WHERE user_id != 1 AND education IS NOT NULL AND education != '' 
    GROUP BY education
");
$stmt->execute();
$education_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get age segmentation (approximate based on date_of_birth)
$stmt = $pdo->prepare("
    SELECT 
        CASE 
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 29 THEN '18-29'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 30 AND 40 THEN '30-40'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 41 AND 55 THEN '41-55'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 56 THEN '56-70'
            ELSE 'Unknown'
        END as age_group,
        COUNT(*) as count 
    FROM employee_profiles 
    WHERE user_id != 1 AND date_of_birth IS NOT NULL 
    GROUP BY age_group
");
$stmt->execute();
$age_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Employee Management System</title>
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .chart-container {
            margin-bottom: 20px;
        }

        canvas {
            max-width: 100%;
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-brand">Employee Management System</div>
            <div class="navbar-nav">
                <span class="admin-badge">ADMIN</span>
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="employees_listing.php" class="nav-link">All Employees</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="welcome-section">
            <h1>Admin Dashboard</h1>
            <p>Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>! Here's an overview of your employee
                management system.</p>
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
        </div>

        <div class="dashboard-grid">
            <div class="card">
                <h2>Diversity Analytics</h2>
                <div class="chart-container">
                    <h3>Comparative Gender Distribution</h3>
                    <canvas id="genderChart"></canvas>
                </div>
                <div class="chart-container">
                    <h3>Employees by Education Level</h3>
                    <canvas id="educationChart"></canvas>
                </div>
                <div class="chart-container">
                    <h3>Employees Age Segmentation</h3>
                    <canvas id="ageChart"></canvas>
                </div>
            </div>

            <div class="card">
                <h2>Departments</h2>
                <?php if ($departments): ?>
                    <ul class="dept-list">
                        <?php foreach ($departments as $dept): ?>
                            <li class="dept-item">
                                <span class="dept-name"><?php echo htmlspecialchars($dept['department']); ?></span>
                                <span class="dept-count"><?php echo $dept['count']; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No departments found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Pass PHP data to JavaScript
        const genderData = <?php echo json_encode($gender_data); ?>;
        const educationData = <?php echo json_encode($education_data); ?>;
        const ageData = <?php echo json_encode($age_data); ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="dashboard.js"></script>
</body>

</html>