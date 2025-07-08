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

// Get recent employees
$stmt = $pdo->prepare("
    SELECT u.username, ep.first_name, ep.last_name, ep.employee_id, ep.department, ep.hire_date, ep.status
    FROM users u 
    JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.role = 'employee'
    ORDER BY ep.created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_employees = $stmt->fetchAll();

// Get departments count
$stmt = $pdo->prepare("
    SELECT department, COUNT(*) as count 
    FROM employee_profiles 
    WHERE department IS NOT NULL AND department != '' AND user_id != 1
    GROUP BY department
");
$stmt->execute();
$departments = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Employee Management System</title>
    <link rel="stylesheet" href="dashboard.css">
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
                <h2>Recent Employees</h2>
                <div class="quick-actions">
                    <a href="employees_listing.php" class="btn btn-small">View All Employees</a>
                    <a href="add_employee.php" class="btn btn-small">Add New Employee</a>
                </div>

                <?php if ($recent_employees): ?>
                    <table class="employee-table">
                        <thead>
                            <tr>
                                <th>Employee ID</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Hire Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_employees as $employee): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($employee['employee_id']); ?></td>
                                    <td><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($employee['department'] ?? 'Not specified'); ?></td>
                                    <td><?php echo $employee['hire_date'] ? date('M j, Y', strtotime($employee['hire_date'])) : 'N/A'; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $employee['status']; ?>">
                                            <?php echo ucfirst($employee['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No employees found.</p>
                <?php endif; ?>
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
</body>

</html>