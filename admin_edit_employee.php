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
$stmt->execute([$user_id]);
$employee = $stmt->fetch();

if ($employee && $employee['role'] === 'admin') {
    $error = 'Cannot edit admin profiles';
    $employee = null;
}

if (!$employee) {
    $error = 'Employee not found';
    $employee = null;
} else {
    // Fetch children data
    $stmt = $pdo->prepare("SELECT * FROM employee_children WHERE employee_profile_id = ?");
    $stmt->execute([$employee['id']]);
    $children = $stmt->fetchAll();
}

// Function to calculate age from date of birth
function calculateAge($dob)
{
    if (!$dob)
        return null;
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    return $age >= 0 ? $age : null;
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
    $ncin = sanitize($_POST['ncin']);
    $cnss_first = sanitize($_POST['cnss_first']);
    $cnss_last = sanitize($_POST['cnss_last']);
    $education = sanitize($_POST['education']);
    $has_driving_license = isset($_POST['has_driving_license']) && $_POST['has_driving_license'] == '1' ? 1 : 0;
    $driving_license_category = sanitize($_POST['driving_license_category'] ?? '');
    $gender = sanitize($_POST['gender']);
    $factory = sanitize($_POST['factory']);
    $salary = floatval($_POST['salary']);
    $dismissal_reason = ($status === 'dismissed') ? sanitize($_POST['dismissal_reason']) : null;
    $children_data = isset($_POST['children']) ? $_POST['children'] : [];

    // Handle file uploads
    $cin_image_front = $employee['cin_image_front'];
    $cin_image_back = $employee['cin_image_back'];
    $driving_license_image = $employee['driving_license_image'];
    $profile_picture = $employee['profile_picture'];

    if (!empty($_FILES['cin_image_front']['name'])) {
        $upload = handleFileUpload($_FILES['cin_image_front'], 'uploads/');
        if ($upload['success']) {
            $cin_image_front = $upload['path'];
        } else {
            $error = $upload['error'];
        }
    }

    if (!empty($_FILES['cin_image_back']['name'])) {
        $upload = handleFileUpload($_FILES['cin_image_back'], 'uploads/');
        if ($upload['success']) {
            $cin_image_back = $upload['path'];
        } else {
            $error = $upload['error'];
        }
    }

    if ($has_driving_license && !empty($_FILES['driving_license_image']['name'])) {
        $upload = handleFileUpload($_FILES['driving_license_image'], 'uploads/');
        if ($upload['success']) {
            $driving_license_image = $upload['path'];
        } else {
            $error = $upload['error'];
        }
    }

    if (!empty($_FILES['profile_picture']['name'])) {
        $upload = handleFileUpload($_FILES['profile_picture'], 'uploads/');
        if ($upload['success']) {
            $profile_picture = $upload['path'];
        } else {
            $error = $upload['error'];
        }
    }

    // Validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($civil_status) || empty($gender)) {
        $error = 'Please fill in all required fields';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (!in_array($civil_status, ['single', 'married', 'divorced', 'widowed'])) {
        $error = 'Invalid civil status';
    } elseif (!in_array($gender, ['male', 'female'])) {
        $error = 'Invalid gender';
    } elseif (!in_array($status, ['active', 'inactive', 'dismissed'])) {
        $error = 'Invalid status';
    } elseif (!in_array($factory, ['1', '2', '3', '4'])) {
        $error = 'Invalid factory selection';
    } elseif (!empty($cnss_first) && (strlen($cnss_first) !== 8 || !ctype_digit($cnss_first))) {
        $error = 'CNSS first part must be exactly 8 digits';
    } elseif (!empty($cnss_last) && (strlen($cnss_last) !== 2 || !ctype_digit($cnss_last))) {
        $error = 'CNSS last part must be exactly 2 digits';
    } elseif ($status === 'dismissed' && empty($dismissal_reason)) {
        $error = 'Dismissal reason is required when status is dismissed';
    }

    if (!$error) {
        try {
            // Check if email already exists for other users
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->fetch()) {
                $error = 'Email address already exists';
            } else {
                // Check if NCIN already exists for other employees
                if (!empty($ncin)) {
                    $stmt = $pdo->prepare("SELECT id FROM employee_profiles WHERE ncin = ? AND user_id != ?");
                    $stmt->execute([$ncin, $user_id]);
                    if ($stmt->fetch()) {
                        $error = 'NCIN already exists';
                    }
                }

                if (!$error) {
                    $pdo->beginTransaction();

                    // Update users table
                    $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $stmt->execute([$email, $user_id]);

                    // Update employee_profiles table
                    $stmt = $pdo->prepare("
                        UPDATE employee_profiles 
                        SET first_name = ?, last_name = ?, phone = ?, address = ?, date_of_birth = ?,
                            civil_status = ?, department = ?, position = ?, status = ?, ncin = ?, 
                            cnss_first = ?, cnss_last = ?, education = ?, has_driving_license = ?, 
                            driving_license_category = ?, gender = ?, factory = ?, salary = ?, 
                            cin_image_front = ?, cin_image_back = ?, driving_license_image = ?, 
                            profile_picture = ?, dismissal_reason = ?, updated_at = NOW()
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
                        $ncin,
                        $cnss_first,
                        $cnss_last,
                        $education,
                        $has_driving_license,
                        $driving_license_category,
                        $gender,
                        $factory,
                        $salary,
                        $cin_image_front,
                        $cin_image_back,
                        $driving_license_image,
                        $profile_picture,
                        $dismissal_reason,
                        $user_id
                    ]);

                    // Update children data
                    $stmt = $pdo->prepare("DELETE FROM employee_children WHERE employee_profile_id = ?");
                    $stmt->execute([$employee['id']]);
                    foreach ($children_data as $child) {
                        if (!empty($child['first_name']) && !empty($child['second_name']) && !empty($child['date_of_birth'])) {
                            $stmt = $pdo->prepare("
                                INSERT INTO employee_children (employee_profile_id, child_first_name, child_second_name, child_date_of_birth, created_at, updated_at) 
                                VALUES (?, ?, ?, ?, NOW(), NOW())
                            ");
                            $stmt->execute([$employee['id'], sanitize($child['first_name']), sanitize($child['second_name']), $child['date_of_birth']]);
                        }
                    }

                    $pdo->commit();
                    $success = 'Employee profile updated successfully!';

                    // Refresh employee and children data
                    $stmt = $pdo->prepare("
                        SELECT u.*, ep.* 
                        FROM users u 
                        LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
                        WHERE u.id = ?
                    ");
                    $stmt->execute([$user_id]);
                    $employee = $stmt->fetch();

                    $stmt = $pdo->prepare("SELECT * FROM employee_children WHERE employee_profile_id = ?");
                    $stmt->execute([$employee['id']]);
                    $children = $stmt->fetchAll();
                }
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
    <link rel="stylesheet" href="emp_register.css">
    <style>
        .logo {
            height: 50px;
            margin-right: 15px;
        }

        img {
            overflow-clip-margin: content-box;
            overflow: clip;
        }
    </style>
    <script>
        function addChildRow(first_name = '', second_name = '', dob = '') {
            const container = document.querySelector('.children-container');
            const childCount = container.querySelectorAll('.child-row').length;
            const row = document.createElement('div');
            row.className = 'child-row';
            row.innerHTML = `
                <input type="text" name="children[${childCount}][first_name]" value="${first_name}" placeholder="Child's First Name" required>
                <input type="text" name="children[${childCount}][second_name]" value="${second_name}" placeholder="Child's Second Name" required>
                <input type="date" name="children[${childCount}][date_of_birth]" value="${dob}" required>
                <button type="button" class="remove-child" onclick="this.parentElement.remove()">X</button>
            `;
            container.appendChild(row);
        }

        function toggleChildrenSection() {
            const section = document.querySelector('.children-section');
            section.classList.toggle('show');
        }

        window.onload = function () {
            <?php if (!empty($children)): ?>
                document.querySelector('.children-section').classList.add('show');
            <?php endif; ?>
        };

        document.addEventListener('DOMContentLoaded', function () {
            function toggleDrivingLicenseSection() {
                const yesRadio = document.querySelector('input[name="has_driving_license"][value="1"]');
                const section = document.getElementById('driving-license-section');
                if (yesRadio && yesRadio.checked) {
                    section && section.classList.add('show');
                } else {
                    section && section.classList.remove('show');
                }
            }
            const radios = document.querySelectorAll('input[name="has_driving_license"]');
            radios.forEach(radio => radio.addEventListener('change', toggleDrivingLicenseSection));
            toggleDrivingLicenseSection();
        });
    </script>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
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
                <a href="admin_request.php" class="nav-link">Notifications</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="register-header">
            <h1>Edit Employee</h1>
            <p>Update employee information</p>
        </div>

        <div class="employee-form">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($employee): ?>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="form-section">
                        <h2>Employee Information</h2>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="employee_id">Employee ID</label>
                                <input type="text" id="employee_id"
                                    value="<?php echo htmlspecialchars($employee['employee_id']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" id="username"
                                    value="<?php echo htmlspecialchars($employee['username']); ?>" readonly>
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

                        <div class="form-row">
                            <div class="form-group">
                                <label for="ncin">NCIN</label>
                                <input type="text" id="ncin" name="ncin"
                                    value="<?php echo htmlspecialchars($employee['ncin'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="cin_image_front">CIN Image Front</label>
                                <input type="file" id="cin_image_front" name="cin_image_front"
                                    accept="image/jpeg,image/png">
                                <?php if ($employee['cin_image_front']): ?>
                                    <p>Current: <a href="<?php echo htmlspecialchars($employee['cin_image_front']); ?>"
                                            target="_blank">View CIN Front Image</a></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="cin_image_back">CIN Image Back</label>
                                <input type="file" id="cin_image_back" name="cin_image_back" accept="image/jpeg,image/png">
                                <?php if ($employee['cin_image_back']): ?>
                                    <p>Current: <a href="<?php echo htmlspecialchars($employee['cin_image_back']); ?>"
                                            target="_blank">View CIN Back Image</a></p>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="cnss_first">CNSS (First 8 Digits)</label>
                                <input type="text" id="cnss_first" name="cnss_first" maxlength="8" pattern="\d{8}"
                                    value="<?php echo htmlspecialchars($employee['cnss_first'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="cnss_last">CNSS (Last 2 Digits)</label>
                                <input type="text" id="cnss_last" name="cnss_last" maxlength="2" pattern="\d{2}"
                                    value="<?php echo htmlspecialchars($employee['cnss_last'] ?? ''); ?>">
                            </div>
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
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="position">Position</label>
                                <input type="text" id="position" name="position"
                                    value="<?php echo htmlspecialchars($employee['position'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="factory">Factory <span class="required">*</span></label>
                                <select id="factory" name="factory" required>
                                    <option value="1" <?php echo ($employee['factory'] === '1') ? 'selected' : ''; ?>>Factory
                                        1</option>
                                    <option value="2" <?php echo ($employee['factory'] === '2') ? 'selected' : ''; ?>>Factory
                                        2</option>
                                    <option value="3" <?php echo ($employee['factory'] === '3') ? 'selected' : ''; ?>>Factory
                                        3</option>
                                    <option value="4" <?php echo ($employee['factory'] === '4') ? 'selected' : ''; ?>>Factory
                                        4</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="education">Education</label>
                                <input type="text" id="education" name="education"
                                    value="<?php echo htmlspecialchars($employee['education'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="salary">Salary</label>
                                <input type="number" id="salary" name="salary" step="0.01"
                                    value="<?php echo htmlspecialchars($employee['salary'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea id="address" name="address"
                                placeholder="Enter full address"><?php echo htmlspecialchars($employee['address'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Has Driving License</label>
                            <label>
                                <input type="radio" name="has_driving_license" value="1"
                                    <?php echo (isset($employee['has_driving_license']) && $employee['has_driving_license'] == '1') ? 'checked' : ''; ?>>
                                Yes
                            </label>
                            <label>
                                <input type="radio" name="has_driving_license" value="0"
                                    <?php echo (!isset($employee['has_driving_license']) || $employee['has_driving_license'] == '0') ? 'checked' : ''; ?>>
                                No
                            </label>
                        </div>

                        <div class="form-group">
                            <label for="driving_license_category">Driving License Category</label>
                            <input type="text" id="driving_license_category" name="driving_license_category"
                                value="<?php echo htmlspecialchars($employee['driving_license_category'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="driving_license_image">Driving License Image</label>
                            <input type="file" id="driving_license_image" name="driving_license_image"
                                accept="image/jpeg,image/png">
                            <?php if ($employee['driving_license_image']): ?>
                                <p>Current: <a href="<?php echo htmlspecialchars($employee['driving_license_image']); ?>"
                                        target="_blank">View Driving License Image</a></p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="profile_picture">Profile Picture</label>
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png">
                            <?php if ($employee['profile_picture']): ?>
                                <p>Current: <a href="<?php echo htmlspecialchars($employee['profile_picture']); ?>"
                                        target="_blank">View Profile Picture</a></p>
                            <?php endif; ?>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="gender">Gender <span class="required">*</span></label>
                                <select id="gender" name="gender" required>
                                    <option value="male" <?php echo ($employee['gender'] === 'male') ? 'selected' : ''; ?>>
                                        Male</option>
                                    <option value="female" <?php echo ($employee['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="civil_status">Civil Status <span class="required">*</span></label>
                                <select id="civil_status" name="civil_status" required>
                                    <option value="single" <?php echo ($employee['civil_status'] === 'single') ? 'selected' : ''; ?>>Single</option>
                                    <option value="married" <?php echo ($employee['civil_status'] === 'married') ? 'selected' : ''; ?>>Married</option>
                                    <option value="divorced" <?php echo ($employee['civil_status'] === 'divorced') ? 'selected' : ''; ?>>Divorced</option>
                                    <option value="widowed" <?php echo ($employee['civil_status'] === 'widowed') ? 'selected' : ''; ?>>Widowed</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status">
                                    <option value="active" <?php echo ($employee['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($employee['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="dismissed" <?php echo ($employee['status'] === 'dismissed') ? 'selected' : ''; ?>>Dismissed</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="hire_date">Hire Date</label>
                                <input type="text" id="hire_date"
                                    value="<?php echo $employee['hire_date'] ? date('F j, Y', strtotime($employee['hire_date'])) : 'Not specified'; ?>"
                                    readonly>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="dismissal_reason">Dismissal Reason</label>
                                <textarea id="dismissal_reason"
                                    name="dismissal_reason"><?php echo htmlspecialchars($employee['dismissal_reason'] ?? 'None'); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="age">Age</label>
                                <input type="text" id="age"
                                    value="<?php echo calculateAge($employee['date_of_birth']) ?? 'Not calculated'; ?>"
                                    readonly>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h2>Children Information</h2>
                        <button type="button" class="add-child-btn" onclick="toggleChildrenSection()">Toggle Children
                            Section</button>
                        <div class="children-section">
                            <h3>Children</h3>
                            <div class="children-container">
                                <?php foreach ($children as $index => $child): ?>
                                    <div class="child-row">
                                        <input type="text" name="children[<?php echo $index; ?>][first_name]"
                                            value="<?php echo htmlspecialchars($child['child_first_name']); ?>"
                                            placeholder="Child's First Name" required>
                                        <input type="text" name="children[<?php echo $index; ?>][second_name]"
                                            value="<?php echo htmlspecialchars($child['child_second_name']); ?>"
                                            placeholder="Child's Second Name" required>
                                        <input type="date" name="children[<?php echo $index; ?>][date_of_birth]"
                                            value="<?php echo $child['child_date_of_birth']; ?>" required>
                                        <button type="button" class="remove-child"
                                            onclick="this.parentElement.remove()">X</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="add-child-btn" onclick="addChildRow()">Add Child</button>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="update_employee" class="btn btn-submit">Update Employee</button>
                        <a href="employees_listing.php" class="btn btn-cancel">Back to Employees</a>
                    </div>
                </form>
            <?php else: ?>
                <p>Employee not found.</p>
                <a href="employees_listing.php" class="btn btn-cancel">Back to Employees</a>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>