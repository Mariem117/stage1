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

        // Delete from employee_profiles (cascade will handle employee_children)
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

// Fetch all employees
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.email, u.role, 
           ep.first_name, ep.last_name, ep.employee_id, 
           ep.ncin, ep.ncss_first, ep.ncss_last,
           ep.department, ep.position, ep.phone, ep.address,
           ep.date_of_birth, ep.age, ep.education, ep.has_driving_license,
           ep.gender, ep.factory, ep.civil_status, ep.hire_date, ep.status,
           (SELECT COUNT(*) FROM employee_children ec WHERE ec.employee_profile_id = ep.id) as children_count
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id
    WHERE u.role = 'employee'
    ORDER BY ep.last_name, ep.first_name
");
$stmt->execute();
$employees = $stmt->fetchAll();

// Function to calculate age from date of birth
function calculateAge($dob)
{
    if (!$dob)
        return 'N/A';
    $birthDate = new DateTime($dob);
    $today = new DateTime();
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
    <link rel="stylesheet" href="employees_listing.css">
    <script>
        function confirmDelete(employeeName) {
            return confirm(`Are you sure you want to delete ${employeeName}'s profile? This action cannot be undone.`);
        }
    </script>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-brand">Employee Management System</div>
            <div class="navbar-nav">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="employees_listing.php" class="nav-link">Employees</a>
                <a href="profile.php" class="nav-link">My Profile</a>
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

            <?php if (empty($employees)): ?>
                <p>No employees found.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Matricule</th>
                            <th>Name</th>
                            <th>First Date</th>
                            <th>NCIN</th>
                            <th>NCSS</th>
                            <th>Department</th>
                            <th>Birthday</th>
                            <th>Education</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>Age</th>
                            <th>Transport</th>
                            <th>F/M</th>
                            <th>Factory</th>
                            <th>Civil Status</th>
                            <th>Children</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $employee): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($employee['employee_id'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></td>
                                <td><?php echo $employee['hire_date'] ? date('M j, Y', strtotime($employee['hire_date'])) : 'N/A'; ?>
                                </td>
                                <td><?php echo htmlspecialchars($employee['ncin'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(($employee['ncss_first'] ?? '') . '-' . ($employee['ncss_last'] ?? '')); ?>
                                </td>
                                <td><?php echo htmlspecialchars($employee['department'] ?? 'Not specified'); ?></td>
                                <td><?php echo $employee['date_of_birth'] ? date('M j, Y', strtotime($employee['date_of_birth'])) : 'N/A'; ?>
                                </td>
                                <td><?php echo htmlspecialchars($employee['education'] ?? 'Not specified'); ?></td>
                                <td><?php echo htmlspecialchars($employee['phone'] ?? 'Not specified'); ?></td>
                                <td><?php echo htmlspecialchars($employee['address'] ?? 'Not specified'); ?></td>
                                <td><?php echo calculateAge($employee['date_of_birth']); ?></td>
                                <td><?php echo $employee['has_driving_license'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($employee['gender'] ?? 'Not specified')); ?></td>
                                <td><?php echo htmlspecialchars($employee['factory'] ?? 'Not specified'); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($employee['civil_status'] ?? 'Not specified')); ?></td>
                                <td><?php echo htmlspecialchars($employee['children_count']); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($employee['status'] ?? 'Active')); ?></td>
                                <td class="action-buttons">
                                    <a href="admin_edit_employee.php?id=<?php echo $employee['id']; ?>"
                                        class="btn btn-view">View/Edit</a>
                                    <form method="POST" action=""
                                        onsubmit="return confirmDelete('<?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="user_id" value="<?php echo $employee['id']; ?>">
                                        <button type="submit" name="delete_employee" class="btn btn-delete">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>