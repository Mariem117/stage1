<?php
require_once 'config.php';
requireLogin();
requireAdmin();

$error = '';
$success = '';

// Get employee ID from URL
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch employee data
$stmt = $pdo->prepare("
    SELECT u.*, ep.* 
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.id = ?
");
$employee = $stmt->fetch();
if ($employee && $employee['role'] === 'admin') {
    $error = 'Cannot edit admin profiles';
    $employee = null;
}
$stmt->execute([$user_id]);


if (!$employee) {
    $error = 'Employee not found';
    $employee = null;
} else {
    // Fetch children
    $stmt = $pdo->prepare("
        SELECT id, child_name, child_date_of_birth 
        FROM employee_children 
        WHERE employee_profile_id = ?
    ");
    $stmt->execute([$employee['id']]);
    $children = $stmt->fetchAll();
}

// Handle form submission
if ($_POST && isset($_POST['update_employee']) && verifyCSRFToken($_POST['csrf_token'])) {
    $first_name = sanitize($_POST['first_name']);
    $last_name = sanitize($_POST['last_name']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $address = sanitize($_POST['address']);
    $date_of_birth = $_POST['date_of_birth'] ? $_POST['date_of_birth'] : null;
    $civil_status = sanitize($_POST['civil_status']);
    $department = sanitize($_POST['department']);
    $position = sanitize($_POST['position']);
    $status = sanitize($_POST['status']);
    $children_data = isset($_POST['children']) ? $_POST['children'] : [];
    $children_to_delete = isset($_POST['delete_children']) ? array_map('intval', $_POST['delete_children']) : [];

    // Validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($civil_status)) {
        $error = 'Please fill in all required fields';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (!in_array($civil_status, ['single', 'married', 'divorced', 'widowed'])) {
        $error = 'Invalid civil status';
    } elseif (!in_array($status, ['active', 'inactive'])) {
        $error = 'Invalid status';
    } else {
        try {
            // Check if email already exists for other users
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->fetch()) {
                $error = 'Email address already exists';
            } else {
                $pdo->beginTransaction();

                // Update users table
                $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                $stmt->execute([$email, $user_id]);

                // Update employee_profiles table
                $stmt = $pdo->prepare("
                    UPDATE employee_profiles 
                    SET first_name = ?, last_name = ?, phone = ?, address = ?, date_of_birth = ?, 
                        civil_status = ?, department = ?, position = ?, status = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([
                    $first_name,
                    $last_name,
                    $phone,
                    $address,
                    $date_of_birth,
                    $civil_status,
                    $department,
                    $position,
                    $status,
                    $user_id
                ]);

                // Delete specified children
                if (!empty($children_to_delete)) {
                    $stmt = $pdo->prepare("DELETE FROM employee_children WHERE id IN (" . implode(',', array_fill(0, count($children_to_delete), '?')) . ") AND employee_profile_id = ?");
                    $stmt->execute(array_merge($children_to_delete, [$employee['id']]));
                }

                // Update or insert children
                $stmt = $pdo->prepare("INSERT INTO employee_children (employee_profile_id, child_name, child_date_of_birth) VALUES (?, ?, ?)");
                $stmt_update = $pdo->prepare("UPDATE employee_children SET child_name = ?, child_date_of_birth = ? WHERE id = ? AND employee_profile_id = ?");
                foreach ($children_data as $child) {
                    if (!empty($child['name']) && !empty($child['dob'])) {
                        if (!empty($child['id'])) {
                            $stmt_update->execute([sanitize($child['name']), $child['dob'], $child['id'], $employee['id']]);
                        } else {
                            $stmt->execute([$employee['id'], sanitize($child['name']), $child['dob']]);
                        }
                    }
                }

                $pdo->commit();
                $success = 'Employee profile updated successfully!';

                // Refresh employee data
                $stmt = $pdo->prepare("
                    SELECT u.*, ep.* 
                    FROM users u 
                    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
                    WHERE u.id = ?
                ");
                $stmt->execute([$user_id]);
                $employee = $stmt->fetch();

                // Refresh children data
                $stmt = $pdo->prepare("
                    SELECT id, child_name, child_date_of_birth 
                    FROM employee_children 
                    WHERE employee_profile_id = ?
                ");
                $stmt->execute([$employee['id']]);
                $children = $stmt->fetchAll();
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Update failed: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Employee - Employee Management System</title>
    <link rel="stylesheet" href="profile.css">
    <script>
        function addChildRow() {
            const container = document.getElementById('children-container');
            const row = document.createElement('div');
            row.className = 'child-row';
            row.innerHTML = `
                <input type="hidden" name="children[][id]" value="">
                <input type="text" name="children[][name]" placeholder="Child's Name">
                <input type="date" name="children[][dob]" placeholder="Child's Date of Birth">
                <span class="remove-child" onclick="this.parentElement.remove()">X</span>
            `;
            container.appendChild(row);
        }
    </script>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-brand">Employee Management System</div>
            <div class="navbar-nav">
                <span class="admin-badge">ADMIN</span>
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="employees_listing.php" class="nav-link">Employees</a>
                <a href="admin_register.php" class="nav-link">Register Admin</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="profile-header">
            <h1>Edit Employee</h1>
            <p>Update employee information</p>
        </div>

        <div class="profile-form">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($employee): ?>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="form-row">
                        <div class="form-group readonly">
                            <label for="employee_id">Employee ID</label>
                            <input type="text" id="employee_id"
                                value="<?php echo htmlspecialchars($employee['employee_id']); ?>" readonly>
                        </div>
                        <div class="form-group readonly">
                            <label for="username">Username</label>
                            <input type="text" id="username" value="<?php echo htmlspecialchars($employee['username']); ?>"
                                readonly>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name <span class="required">*</span></label>
                            <input type="text" id="first_name" name="first_name"
                                value="<?php echo htmlspecialchars($employee['first_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name <span class="required">*</span></label>
                            <input type="text" id="last_name" name="last_name"
                                value="<?php echo htmlspecialchars($employee['last_name']); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email">Email <span class="required">*</span></label>
                        <input type="email" id="email" name="email"
                            value="<?php echo htmlspecialchars($employee['email']); ?>" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone"
                                value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="date_of_birth">Date of Birth</label>
                            <input type="date" id="date_of_birth" name="date_of_birth"
                                value="<?php echo $employee['date_of_birth'] ?? ''; ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="civil_status">Civil Status <span class="required">*</span></label>
                        <select id="civil_status" name="civil_status" required>
                            <option value="single" <?php echo ($employee['civil_status'] === 'single') ? 'selected' : ''; ?>>
                                Single</option>
                            <option value="married" <?php echo ($employee['civil_status'] === 'married') ? 'selected' : ''; ?>>Married</option>
                            <option value="divorced" <?php echo ($employee['civil_status'] === 'divorced') ? 'selected' : ''; ?>>Divorced</option>
                            <option value="widowed" <?php echo ($employee['civil_status'] === 'widowed') ? 'selected' : ''; ?>>Widowed</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="department">Department</label>
                            <select id="department" name="department">
                                <option value="">Select Department</option>
                                <option value="IT" <?php echo ($employee['department'] === 'IT') ? 'selected' : ''; ?>>IT
                                </option>
                                <option value="HR" <?php echo ($employee['department'] === 'HR') ? 'selected' : ''; ?>>HR
                                </option>
                                <option value="Finance" <?php echo ($employee['department'] === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                                <option value="Marketing" <?php echo ($employee['department'] === 'Marketing') ? 'selected' : ''; ?>>Marketing</option>
                                <option value="Operations" <?php echo ($employee['department'] === 'Operations') ? 'selected' : ''; ?>>Operations</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="position">Position</label>
                            <input type="text" id="position" name="position"
                                value="<?php echo htmlspecialchars($employee['position'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address"
                            placeholder="Enter full address"><?php echo htmlspecialchars($employee['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group readonly">
                            <label for="hire_date">Hire Date</label>
                            <input type="text" id="hire_date"
                                value="<?php echo $employee['hire_date'] ? date('F j, Y', strtotime($employee['hire_date'])) : 'Not specified'; ?>"
                                readonly>
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="active" <?php echo ($employee['status'] === 'active') ? 'selected' : ''; ?>>
                                    Active</option>
                                <option value="inactive" <?php echo ($employee['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Children (Optional)</label>
                        <div id="children-container" class="children-container">
                            <?php foreach ($children as $child): ?>
                                <div class="child-row">
                                    <input type="hidden" name="children[][id]"
                                        value="<?php echo htmlspecialchars($child['id']); ?>">
                                    <input type="text" name="children[][name]"
                                        value="<?php echo htmlspecialchars($child['child_name']); ?>"
                                        placeholder="Child's Name">
                                    <input type="date" name="children[][dob]"
                                        value="<?php echo htmlspecialchars($child['child_date_of_birth']); ?>"
                                        placeholder="Child's Date of Birth">
                                    <input type="checkbox" name="delete_children[]"
                                        value="<?php echo htmlspecialchars($child['id']); ?>"
                                        id="delete_child_<?php echo $child['id']; ?>">
                                    <label for="delete_child_<?php echo $child['id']; ?>" class="remove-child">X</label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addChildRow()">Add Child</button>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="update_employee" class="btn">Update Employee</button>
                        <a href="employees_listing.php" class="btn btn-secondary">Back to Employees</a>
                    </div>
                </form>
            <?php else: ?>
                <p>Employee not found.</p>
                <a href="employees_listing.php" class="btn btn-secondary">Back to Employees</a>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>