<?php
require_once 'config.php';
requireLogin();

// Check if user is admin
if ($_SESSION['role'] !== 'admin') {
    header('Location: profile.php');
    exit();
}

$error = '';
$success = '';

// Handle delete request
if ($_POST && isset($_POST['delete_employee']) && verifyCSRFToken($_POST['csrf_token'])) {
    $user_id = intval($_POST['user_id']);

    try {
        $pdo->beginTransaction();

        // Delete from users table (cascade will handle employee_profiles and employee_children)
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
        $stmt->execute([$user_id]);

        if ($stmt->rowCount() > 0) {
            $pdo->commit();
            $success = 'Employee deleted successfully!';
        } else {
            $pdo->rollBack();
            $error = 'Cannot delete employee. User not found or is an admin.';
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Deletion failed: ' . $e->getMessage();
    }
}

// Handle status update request
if ($_POST && isset($_POST['update_status']) && verifyCSRFToken($_POST['csrf_token'])) {
    $user_id = intval($_POST['user_id']);
    $new_status = in_array($_POST['status'], ['active', 'inactive', 'dismissed']) ? $_POST['status'] : 'active';
    $dismissal_reason = $_POST['status'] === 'dismissed' ? ($_POST['dismissal_reason'] ?? '') : '';

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE employee_profiles SET status = ?, dismissal_reason = ? WHERE user_id = ?");
        $stmt->execute([$new_status, $dismissal_reason, $user_id]);

        if ($stmt->rowCount() > 0) {
            $pdo->commit();
            $success = 'Employee status updated successfully!';
        } else {
            $pdo->rollBack();
            $error = 'Failed to update status. Employee not found.';
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Status update failed: ' . $e->getMessage();
    }
}

// Fetch all employees with their profiles
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.email, u.role, 
           ep.id as profile_id, ep.user_id, ep.first_name, ep.last_name, ep.employee_id, 
           ep.ncin, ep.cin_image_front, ep.cin_image_back, ep.cnss_first, ep.cnss_last,
           ep.department, ep.position, ep.phone, ep.address,
           ep.date_of_birth, ep.education, ep.has_driving_license,
           ep.driving_license_category, ep.driving_license_number , ep.driving_license_image,
           ep.gender, ep.factory, ep.civil_status, ep.children, ep.hire_date, 
           ep.salary, ep.profile_picture, ep.status, ep.dismissal_reason,
           ep.created_at, ep.updated_at
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id
    WHERE u.role = 'employee'
    ORDER BY ep.last_name, ep.first_name
");
$stmt->execute();
$employees = $stmt->fetchAll();

// Function to format date
function formatDate($date)
{
    if (!$date)
        return "None";
    return date('M j, Y', strtotime($date));
}

// Function to format NCSS
function formatNCSS($first, $last)
{
    if (!$first && !$last)
        return null;
    return ($first ?: '') . '-' . ($last ?: '');
}

// Function to format salary
function formatSalary($salary)
{
    if (!$salary)
        return null;
    return '$' . number_format($salary, 2);
}

// Function to calculate age
function calculateAge($dateOfBirth)
{
    if (!$dateOfBirth)
        return null;
    $today = new DateTime();
    $birthDate = new DateTime($dateOfBirth);
    $age = $today->diff($birthDate)->y;
    return $age;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Listing - Employee Management System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #a70202 0%, rgb(0, 0, 0) 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }

        .logo {
            height: 50px;
            margin-right: 15px;
        }

        img {
            overflow-clip-margin: content-box;
            overflow: clip;
        }

        .navbar {
            background: white;
            padding-top: 15px;
            padding-bottom: 15px;
            padding-left: 0;
            padding-right: 0;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .navbar-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .nav-link {
            color: #333;
            text-decoration: none;
            font-size: 1rem;
            transition: color 0.3s, transform 0.2s;
        }

        .nav-link:hover,
        .nav-link:focus {
            color: #a70202;
            transform: translateY(-2px);
            outline: none;
        }

        .container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .header h1 {
            color: #fff;
            margin-bottom: 0.5rem;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
        }

        .header p {
            color: rgb(255, 255, 255);
            font-size: 0.875rem;
        }

        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .search-box {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.875rem;
            width: 250px;
        }

        .view-toggle {
            display: flex;
            gap: 0.5rem;
        }

        .toggle-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #a70202;
            background: white;
            color: #a70202;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .toggle-btn.active {
            background: #a70202;
            color: white;
        }

        .employee-table {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table-container {
            overflow-x: auto;
            max-height: 70vh;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
        }

        th,
        td {
            padding: 0.75rem 0.5rem;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
            white-space: nowrap;
        }

        th {
            background: #f8f9fa;
            color: #333;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            border-bottom: 2px solid #dee2e6;
        }

        td {
            color: #555;
        }

        .compact-view .extra-column {
            display: none;
        }

        .detailed-view .table-container {
            max-height: 60vh;
        }

        .employee-name {
            font-weight: 600;
            color: #333;
            min-width: 150px;
        }

        .employee-id {
            font-family: monospace;
            background: #f8f9fa;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.8rem;
            min-width: 80px;
        }

        .status {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status.active {
            background: #d4edda;
            color: #155724;
        }

        .status.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .status.dismissed {
            background: #fff3cd;
            color: #856404;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            min-width: 150px;
        }

        .btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-weight: 500;
        }

        .btn-view {
            background: #a70202;
            color: white;
        }

        .btn-view:hover {
            background: #8b0000;
            transform: translateY(-1px);
        }

        .btn-delete {
            background: #dc3545;
            color: white;
        }

        .btn-delete:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        .alert {
            padding: 0.75rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
            text-align: center;
            font-size: 0.875rem;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .empty-message {
            text-align: center;
            padding: 2rem;
            color: #666;
            font-style: italic;
        }

        .profile-pic {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .cin-image {
            width: 30px;
            height: 20px;
            object-fit: cover;
            border-radius: 2px;
        }

        .license-image {
            width: 30px;
            height: 20px;
            object-fit: cover;
            border-radius: 2px;
        }

        @media (max-width: 1200px) {
            .container {
                max-width: 95%;
            }

            .search-box {
                width: 200px;
            }
        }

        @media (max-width: 768px) {
            .controls {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                width: 100%;
            }

            .view-toggle {
                justify-content: center;
            }

            .employee-table {
                padding: 1rem;
            }

            th,
            td {
                padding: 0.5rem 0.25rem;
                font-size: 0.8rem;
            }

            .action-buttons {
                flex-direction: column;
                gap: 0.25rem;
                min-width: 100px;
            }

            .btn {
                padding: 0.3rem 0.6rem;
                font-size: 0.7rem;
            }

            .navbar-container {
                flex-direction: column;
                gap: 1rem;
            }

            .navbar-nav {
                flex-wrap: wrap;
                justify-content: center;
            }
        }

        /* Column width definitions */
        .col-profile-pic {
            width: 60px;
        }

        .col-id {
            width: 80px;
        }

        .col-name {
            width: 180px;
        }

        .col-employee-id {
            width: 100px;
        }

        .col-ncin {
            width: 120px;
        }

        .col-cin-front {
            width: 80px;
        }

        .col-cin-back {
            width: 80px;
        }

        .col-cnss {
            width: 120px;
        }

        .col-department {
            width: 120px;
        }

        .col-position {
            width: 120px;
        }

        .col-phone {
            width: 120px;
        }

        .col-address {
            width: 150px;
        }

        .col-dob {
            width: 100px;
        }

        .col-age {
            width: 50px;
        }

        .col-education {
            width: 100px;
        }

        .col-license {
            width: 80px;
        }

        .col-license-cat {
            width: 100px;
        }

        .col-license-img {
            width: 80px;
        }

        .col-gender {
            width: 80px;
        }

        .col-factory {
            width: 100px;
        }

        .col-civil {
            width: 100px;
        }

        .col-children {
            width: 80px;
        }

        .col-hire-date {
            width: 100px;
        }

        .col-salary {
            width: 100px;
        }

        .col-status {
            width: 100px;
        }

        .col-dismissal {
            width: 150px;
        }

        .col-created {
            width: 100px;
        }

        .col-updated {
            width: 100px;
        }

        .col-actions {
            width: 150px;
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
            <div class="navbar-nav">
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <span class="admin-badge">ADMIN</span>
                    <a href="dashboard.php" class="nav-link">Dashboard</a>
                    <a href="employees_listing.php" class="nav-link">Employees</a>
                <?php else: ?>
                    <a href="dashboard.php" class="nav-link">Dashboard</a>
                <?php endif; ?>
                <a href="profile.php" class="nav-link">My Profile</a>
                <a href="admin_request.php" class="nav-link">Requests</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="header">
            <h1>Employee Listing</h1>
            <p>Manage all employee profiles</p>
        </div>

        <div class="employee-table">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="controls">
                <input type="text" class="search-box" placeholder="Search employees..." id="searchInput">
                <div class="view-toggle">
                    <button class="toggle-btn active" onclick="toggleView('compact')">Compact View</button>
                    <button class="toggle-btn" onclick="toggleView('detailed')">Detailed View</button>
                </div>
            </div>

            <?php if (empty($employees)): ?>
                <div class="empty-message">
                    <p>No employees found.</p>
                </div>
            <?php else: ?>
                <div class="table-container compact-view" id="tableContainer">
                    <table id="employeeTable">
                        <thead>
                            <tr>
                                <th class="col-profile-pic extra-column">Photo</th>
                                <th class="col-employee-id">Matricule</th>
                                <th class="col-name">Name</th>
                                <th class="col-gender extra-column">Gender</th>
                                <th class="col-dob extra-column">Date of Birth</th>
                                <th class="col-age extra-column">Age</th>
                                <th class="col-department">Department</th>
                                <th class="col-position extra-column">Position</th>
                                <th class="col-phone">Phone</th>
                                <th class="col-address extra-column">Address</th>
                                <th class="col-education extra-column">Education</th>
                                <th class="col-civil extra-column">Civil Status</th>
                                <th class="col-children extra-column">Children</th>
                                <th class="col-ncin extra-column">NCIN</th>
                                <th class="col-cin-front extra-column">CIN Front</th>
                                <th class="col-cin-back extra-column">CIN Back</th>
                                <th class="col-cnss extra-column">CNSS</th>
                                <th class="col-license extra-column">Has License</th>
                                <th class="col-license-cat extra-column">License Category</th>
                                <th class="col-license-nb extra-column">License number</th>
                                <th class="col-license-img extra-column">License Image</th>
                                <th class="col-factory extra-column">Factory</th>
                                <th class="col-hire-date extra-column">Hire Date</th>
                                <th class="col-salary extra-column">Salary</th>
                                <th class="col-status">Status</th>
                                <th class="col-dismissal extra-column">Dismissal Reason</th>
                                <th class="col-created extra-column">Created</th>
                                <th class="col-updated extra-column">Updated</th>
                                <th class="col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="employeeTableBody">
                            <?php foreach ($employees as $employee): ?>
                                <tr>
                                    <td class="col-profile-pic extra-column">
                                        <?php if ($employee['profile_picture']): ?>
                                            <img src="<?php echo htmlspecialchars($employee['profile_picture']); ?>"
                                                class="profile-pic" alt="Profile Picture">
                                        <?php else: ?>
                                            None
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-employee-id employee-id">
                                        <?php echo htmlspecialchars($employee['employee_id'] ?? null); ?>
                                    </td>
                                    <td class="col-name employee-name">
                                        <?php echo htmlspecialchars(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')); ?>
                                    </td>
                                    <td class="col-gender extra-column">
                                        <?php echo htmlspecialchars($employee['gender'] ?? null); ?>
                                    </td>
                                    <td class="col-dob extra-column"><?php echo formatDate($employee['date_of_birth']); ?></td>
                                    <td class="col-age extra-column"><?php echo calculateAge($employee['date_of_birth']); ?>
                                    </td>
                                    <td class="col-department"><?php echo htmlspecialchars($employee['department'] ?? null); ?>
                                    </td>
                                    <td class="col-position extra-column">
                                        <?php echo htmlspecialchars($employee['position'] ?? null); ?>
                                    </td>
                                    <td class="col-phone"><?php echo htmlspecialchars($employee['phone'] ?? null); ?></td>
                                    <td class="col-address extra-column">
                                        <?php echo htmlspecialchars($employee['address'] ?? null); ?>
                                    </td>
                                    <td class="col-education extra-column">
                                        <?php echo htmlspecialchars($employee['education'] ?? null); ?>
                                    </td>
                                    <td class="col-civil extra-column">
                                        <?php echo htmlspecialchars($employee['civil_status'] ?? null); ?>
                                    </td>
                                    <td class="col-children extra-column">
                                        <?php echo htmlspecialchars($employee['children'] ?? '0'); ?>
                                    </td>
                                    <td class="col-ncin extra-column">
                                        <?php echo htmlspecialchars($employee['ncin'] ?? null); ?>
                                    </td>
                                    <td class="col-cin-front extra-column">
                                        <?php if ($employee['cin_image_front']): ?>
                                            <img src="<?php echo htmlspecialchars($employee['cin_image_front']); ?>"
                                                class="cin-image" alt="CIN Front">
                                        <?php else: ?>
                                            None
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-cin-back extra-column">
                                        <?php if ($employee['cin_image_back']): ?>
                                            <img src="<?php echo htmlspecialchars($employee['cin_image_back']); ?>"
                                                class="cin-image" alt="CIN Back">
                                        <?php else: ?>
                                            None
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-cnss extra-column">
                                        <?php echo formatNCSS($employee['cnss_first'], $employee['cnss_last']); ?>
                                    </td>
                                    <td class="col-license extra-column">
                                        <?php echo $employee['has_driving_license'] ? 'Yes' : 'No'; ?>
                                    </td>
                                    <td class="col-license-cat extra-column">
                                        <?php echo htmlspecialchars($employee['driving_license_category'] ?? null); ?>
                                    </td>
                                    <td class="col-license-nb extra-column">
                                        <?php echo htmlspecialchars($employee['driving_license_number'] ?? null); ?>
                                    </td>
                                    <td class="col-license-img extra-column">
                                        <?php if ($employee['driving_license_image']): ?>
                                            <img src="<?php echo htmlspecialchars($employee['driving_license_image']); ?>"
                                                class="license-image" alt="License">
                                        <?php else: ?>
                                            None
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-factory extra-column">
                                        <?php echo htmlspecialchars($employee['factory'] ?? null); ?>
                                    </td>
                                    <td class="col-hire-date extra-column"><?php echo formatDate($employee['hire_date']); ?>
                                    </td>
                                    <td class="col-salary extra-column"><?php echo formatSalary($employee['salary']); ?></td>
                                    <td class="col-status">
                                        <span class="status <?php echo htmlspecialchars($employee['status'] ?? 'active'); ?>">
                                            <?php echo htmlspecialchars($employee['status'] ?? 'Active'); ?>
                                        </span>
                                    </td>
                                    <td class="col-dismissal extra-column">
                                        <?php echo htmlspecialchars($employee['dismissal_reason'] ?? null); ?>
                                    </td>
                                    <td class="col-created extra-column"><?php echo formatDate($employee['created_at']); ?></td>
                                    <td class="col-updated extra-column"><?php echo formatDate($employee['updated_at']); ?></td>
                                    <td class="col-actions">
                                        <div class="action-buttons">
                                            <a href="admin_edit_employee.php?id=<?php echo $employee['id']; ?>"
                                                class="btn btn-view">View/Edit</a>
                                            <form method="POST" action="" style="display: inline;"
                                                onsubmit="return confirmDelete('<?php echo htmlspecialchars(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')); ?>');">
                                                <input type="hidden" name="csrf_token"
                                                    value="<?php echo generateCSRFToken(); ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $employee['id']; ?>">
                                                <button type="submit" name="delete_employee"
                                                    class="btn btn-delete">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        let currentView = 'compact';

        function toggleView(view) {
            const container = document.getElementById('tableContainer');
            const toggleBtns = document.querySelectorAll('.toggle-btn');

            toggleBtns.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');

            if (view === 'compact') {
                container.className = 'table-container compact-view';
                currentView = 'compact';
            } else {
                container.className = 'table-container detailed-view';
                currentView = 'detailed';
            }
        }

        function confirmDelete(employeeName) {
            return confirm(`Are you sure you want to delete ${employeeName}'s profile? This action cannot be undone.`);
        }

        document.getElementById('searchInput').addEventListener('input', function (e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#employeeTableBody tr');

            rows.forEach(row => {
                const name = row.querySelector('.employee-name').textContent.toLowerCase();
                const department = row.cells[6].textContent.toLowerCase(); // Department column
                const phone = row.cells[8].textContent.toLowerCase(); // Phone column
                const matricule = row.cells[1].textContent.toLowerCase(); // Employee ID column

                if (name.includes(searchTerm) || department.includes(searchTerm) ||
                    phone.includes(searchTerm) || matricule.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        document.addEventListener('DOMContentLoaded', function () {
            const container = document.getElementById('tableContainer');
            if (container) {
                container.className = 'table-container compact-view';
            }
        });
    </script>
</body>

</html>