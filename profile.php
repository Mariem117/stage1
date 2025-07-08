<?php
require_once 'config.php';
requireLogin();

$error = '';
$success = '';

// Get employee profile and children
$stmt = $pdo->prepare("
    SELECT u.*, ep.* 
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$employee = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT id, child_name, child_date_of_birth 
    FROM employee_children 
    WHERE employee_profile_id = ?
");
$stmt->execute([$employee['id']]);
$children = $stmt->fetchAll();

if (!$employee) {
    header('Location: login.php');
    exit();
}

// Handle profile update
if ($_POST && isset($_POST['update_profile'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request';
    } else {
        $first_name = sanitize($_POST['first_name']);
        $last_name = sanitize($_POST['last_name']);
        $email = sanitize($_POST['email']);
        $phone = sanitize($_POST['phone']);
        $address = sanitize($_POST['address']);
        $date_of_birth = $_POST['date_of_birth'] ? $_POST['date_of_birth'] : null;
        $civil_status = sanitize($_POST['civil_status']);
        $children_data = isset($_POST['children']) ? $_POST['children'] : [];
        $children_to_delete = isset($_POST['delete_children']) ? array_map('intval', $_POST['delete_children']) : [];

        // Validation
        if (empty($first_name) || empty($last_name) || empty($email) || empty($civil_status)) {
            $error = 'Please fill in all required fields';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address';
        } elseif (!in_array($civil_status, ['single', 'married', 'divorced', 'widowed'])) {
            $error = 'Invalid civil status';
        } else {
            try {
                // Check if email already exists for other users
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $_SESSION['user_id']]);

                if ($stmt->fetch()) {
                    $error = 'Email address already exists';
                } else {
                    // Begin transaction
                    $pdo->beginTransaction();

                    // Update users table
                    $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $stmt->execute([$email, $_SESSION['user_id']]);

                    // Update employee_profiles table
                    $stmt = $pdo->prepare("
                        UPDATE employee_profiles 
                        SET first_name = ?, last_name = ?, phone = ?, address = ?, date_of_birth = ?, civil_status = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$first_name, $last_name, $phone, $address, $date_of_birth, $civil_status, $_SESSION['user_id']]);

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
                                // Update existing child
                                $stmt_update->execute([sanitize($child['name']), $child['dob'], $child['id'], $employee['id']]);
                            } else {
                                // Insert new child
                                $stmt->execute([$employee['id'], sanitize($child['name']), $child['dob']]);
                            }
                        }
                    }

                    // Commit transaction
                    $pdo->commit();

                    $success = 'Profile updated successfully!';

                    // Refresh employee data
                    $stmt = $pdo->prepare("
                        SELECT u.*, ep.* 
                        FROM users u 
                        LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
                        WHERE u.id = ?
                    ");
                    $stmt->execute([$_SESSION['user_id']]);
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
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Employee Management System</title>
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
                <a href="profile.php" class="nav-link">My Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="profile-header">
            <h1>My Profile</h1>
            <p>Update your personal information</p>
        </div>

        <div class="profile-form">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="info-note">
                <strong>Note:</strong> Fields marked with <span class="required">*</span> are required. Some information
                like Employee ID and Department can only be updated by administrators.
            </div>

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
                    <div class="form-group readonly">
                        <label for="department">Department</label>
                        <input type="text" id="department"
                            value="<?php echo htmlspecialchars($employee['department'] ?? 'Not specified'); ?>">
                    </div>

                    <div class="form-group readonly">
                        <label for="position">Position</label>
                        <input type="text" id="position"
                            value="<?php echo htmlspecialchars($employee['position'] ?? 'Not specified'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address"
                        placeholder="Enter your full address"><?php echo htmlspecialchars($employee['address'] ?? ''); ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group readonly">
                        <label for="hire_date">Hire Date</label>
                        <input type="text" id="hire_date"
                            value="<?php echo $employee['hire_date'] ? date('F j, Y', strtotime($employee['hire_date'])) : 'Not specified'; ?>">
                    </div>

                    <div class="form-group readonly">
                        <label for="status">Status</label>
                        <input type="text" id="status" value="<?php echo ucfirst($employee['status'] ?? 'active'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Children </label>
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
                    <button type="submit" name="update_profile" class="btn">Update Profile</button>
                </div>
            </form>
        </div>
    </div>
</body>

</html>