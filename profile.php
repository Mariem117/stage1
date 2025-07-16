<?php
require_once 'config.php';
requireLogin();

$error = '';
$success = '';

// Get employee profile
$stmt = $pdo->prepare("
    SELECT u.*, ep.* 
    FROM users u 
    LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$employee = $stmt->fetch();

// Get children
$stmt = $pdo->prepare("
    SELECT ec.* 
    FROM employee_children ec 
    JOIN employee_profiles ep ON ec.employee_profile_id = ep.id 
    WHERE ep.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$children = $stmt->fetchAll();

if (!$employee) {
    header('Location: login.php');
    exit();
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

// Handle profile update
if ($_POST && isset($_POST['update_profile']) && verifyCSRFToken($_POST['csrf_token'])) {
    $first_name = sanitize($_POST['first_name']);
    $last_name = sanitize($_POST['last_name']);
    $employee_id = sanitize($employee['employee_id']); // Read-only
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $address = sanitize(substr($_POST['address'], 0, 32));
    $date_of_birth = $_POST['date_of_birth'] ? $_POST['date_of_birth'] : null;
    $civil_status = sanitize($_POST['civil_status']);
    $ncin = sanitize($_POST['ncin']);
    $cnss_first = sanitize($_POST['cnss_first']);
    $cnss_last = sanitize($_POST['cnss_last']);
    $education = sanitize($_POST['education']);
    $has_driving_license = isset($_POST['has_driving_license']) ? 1 : 0;
    $driving_licence_category = sanitize($_POST['driving_licence_category'] ?? '');
    $gender = sanitize($_POST['gender']);
    $factory = sanitize($_POST['factory']);
    $children_data = isset($_POST['children']) ? $_POST['children'] : [];
    $children_to_remove = isset($_POST['remove_child']) ? array_map('intval', $_POST['remove_child']) : [];
    $department = $employee['department']; // Read-only
    $position = $employee['position']; // Read-only
    $hire_date = $employee['hire_date']; // Read-only
    $salary = $employee['salary']; // Read-only
    $status = $employee['status']; // Read-only
    $dismissal_reason = $employee['dismissal_reason']; // Read-only
    $created_at = $employee['created_at']; // Read-only
    $updated_at = date('Y-m-d H:i:s'); // Update timestamp

    // Handle file uploads
    $cin_image_front = $employee['cin_image_front'];
    $cin_image_back = $employee['cin_image_back'];
    $driving_licence_image = $employee['driving_licence_image'];
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

    if ($has_driving_license && !empty($_FILES['driving_licence_image']['name'])) {
        $upload = handleFileUpload($_FILES['driving_licence_image'], 'uploads/');
        if ($upload['success']) {
            $driving_licence_image = $upload['path'];
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
    } elseif (!in_array($factory, ['1', '2', '3', '4'])) {
        $error = 'Invalid factory selection';
    } else {
        // Validate children data
        foreach ($children_data as $index => $child) {
            if (!empty($child['child_first_name']) && !empty($child['child_second_name']) && !empty($child['child_date_of_birth'])) {
                $birth_date = DateTime::createFromFormat('Y-m-d', $child['child_date_of_birth']);
                if (!$birth_date || $birth_date > new DateTime()) {
                    $error = "Child $index birth date must be in the past";
                    break;
                }
            }
        }

        if (!$error) {
            try {
                // Check if email already exists for other users
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $_SESSION['user_id']]);
                if ($stmt->fetch()) {
                    $error = 'Email address already exists';
                } else {
                    // Check if NCIN already exists for other employees
                    if (!empty($ncin)) {
                        $stmt = $pdo->prepare("SELECT id FROM employee_profiles WHERE ncin = ? AND user_id != ?");
                        $stmt->execute([$ncin, $_SESSION['user_id']]);
                        if ($stmt->fetch()) {
                            $error = 'NCIN already exists';
                        }
                    }

                    if (!$error) {
                        $pdo->beginTransaction();

                        // Update users table
                        $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                        $stmt->execute([$email, $_SESSION['user_id']]);

                        // Update employee_profiles table
                        $stmt = $pdo->prepare("
                            UPDATE employee_profiles 
                            SET first_name = ?, last_name = ?, employee_id = ?, ncin = ?, cin_image_front = ?, cin_image_back = ?, 
                                cnss_first = ?, cnss_last = ?, department = ?, position = ?, phone = ?, address = ?, 
                                date_of_birth = ?, education = ?, has_driving_license = ?, driving_licence_category = ?, 
                                driving_licence_image = ?, gender = ?, factory = ?, civil_status = ?, hire_date = ?, 
                                salary = ?, profile_picture = ?, status = ?, dismissal_reason = ?, updated_at = ?
                            WHERE user_id = ?
                        ");
                        $stmt->execute([
                            $first_name,
                            $last_name,
                            $employee_id,
                            $ncin,
                            $cin_image_front,
                            $cin_image_back,
                            $cnss_first,
                            $cnss_last,
                            $department,
                            $position,
                            $phone,
                            $address,
                            $date_of_birth,
                            $education,
                            $has_driving_license,
                            $driving_licence_category,
                            $driving_licence_image,
                            $gender,
                            $factory,
                            $civil_status,
                            $hire_date,
                            $salary,
                            $profile_picture,
                            $status,
                            $dismissal_reason,
                            $updated_at,
                            $_SESSION['user_id']
                        ]);

                        // Remove selected children
                        if (!empty($children_to_remove)) {
                            $stmt = $pdo->prepare("
                                DELETE FROM employee_children 
                                WHERE id IN (" . implode(',', array_fill(0, count($children_to_remove), '?')) . ") 
                                AND employee_profile_id = (SELECT id FROM employee_profiles WHERE user_id = ?)
                            ");
                            $stmt->execute([...$children_to_remove, $_SESSION['user_id']]);
                        }

                        // Update or add children
                        foreach ($children_data as $child) {
                            if (!empty($child['child_first_name']) && !empty($child['child_second_name']) && !empty($child['child_date_of_birth'])) {
                                if (isset($child['id']) && !in_array($child['id'], $children_to_remove)) {
                                    // Update existing child
                                    $stmt = $pdo->prepare("
                                        UPDATE employee_children 
                                        SET child_first_name = ?, child_second_name = ?, child_date_of_birth = ?
                                        WHERE id = ? AND employee_profile_id = (SELECT id FROM employee_profiles WHERE user_id = ?)
                                    ");
                                    $stmt->execute([
                                        sanitize($child['child_first_name']),
                                        sanitize($child['child_second_name']),
                                        $child['child_date_of_birth'],
                                        $child['id'],
                                        $_SESSION['user_id']
                                    ]);
                                } else {
                                    // Add new child
                                    $stmt = $pdo->prepare("
                                        INSERT INTO employee_children (employee_profile_id, child_first_name, child_second_name, child_date_of_birth) 
                                        VALUES ((SELECT id FROM employee_profiles WHERE user_id = ?), ?, ?, ?)
                                    ");
                                    $stmt->execute([
                                        $_SESSION['user_id'],
                                        sanitize($child['child_first_name']),
                                        sanitize($child['child_second_name']),
                                        $child['child_date_of_birth']
                                    ]);
                                }
                            }
                        }

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
                            SELECT ec.* 
                            FROM employee_children ec 
                            JOIN employee_profiles ep ON ec.employee_profile_id = ep.id 
                            WHERE ep.user_id = ?
                        ");
                        $stmt->execute([$_SESSION['user_id']]);
                        $children = $stmt->fetchAll();
                    }
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
                like Employee ID, Username, Department, Position, Hire Date, Salary, Status, Dismissal Reason, Created
                At,
                and Updated At can only be updated by administrators.
            </div>

            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="form-row">
                    <div class="form-group readonly">
                        <label for="employee_id">Employee ID</label>
                        <input type="text" id="employee_id"
                            value="<?php echo htmlspecialchars($employee['employee_id'] ?? 'N/A'); ?>" readonly>
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
                            value="<?php echo htmlspecialchars($employee['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name <span class="required">*</span></label>
                        <input type="text" id="last_name" name="last_name"
                            value="<?php echo htmlspecialchars($employee['last_name'] ?? ''); ?>" required>
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
                            value="<?php echo htmlspecialchars($employee['date_of_birth'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="ncin">NCIN</label>
                        <input type="text" id="ncin" name="ncin"
                            value="<?php echo htmlspecialchars($employee['ncin'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="cin_image_front">CIN Image (Front)</label>
                        <input type="file" id="cin_image_front" name="cin_image_front" accept="image/jpeg,image/png">
                        <?php if ($employee['cin_image_front']): ?>
                            <p>Current: <a href="<?php echo htmlspecialchars($employee['cin_image_front']); ?>"
                                    target="_blank">View CIN Image (Front)</a></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="cin_image_back">CIN Image (Back)</label>
                        <input type="file" id="cin_image_back" name="cin_image_back" accept="image/jpeg,image/png">
                        <?php if ($employee['cin_image_back']): ?>
                            <p>Current: <a href="<?php echo htmlspecialchars($employee['cin_image_back']); ?>"
                                    target="_blank">View CIN Image (Back)</a></p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="cnss_first">CNSS (First 8 digits)</label>
                        <input type="text" id="cnss_first" name="cnss_first" maxlength="8" pattern="\d{8}"
                            value="<?php echo htmlspecialchars($employee['cnss_first'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="cnss_last">CNSS (Last 2 digits)</label>
                    <input type="text" id="cnss_last" name="cnss_last" maxlength="2" pattern="\d{2}"
                        value="<?php echo htmlspecialchars($employee['cnss_last'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="education">Education</label>
                    <input type="text" id="education" name="education"
                        value="<?php echo htmlspecialchars($employee['education'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="has_driving_license">Has Driving License</label>
                    <input type="checkbox" id="has_driving_license" name="has_driving_license" <?php echo ($employee['has_driving_license'] ?? 0) ? 'checked' : ''; ?>>
                </div>

                <div class="form-group">
                    <label for="driving_licence_category">Driving License Category</label>
                    <input type="text" id="driving_licence_category" name="driving_licence_category"
                        value="<?php echo htmlspecialchars($employee['driving_licence_category'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="driving_licence_image">Driving License Image</label>
                    <input type="file" id="driving_licence_image" name="driving_licence_image"
                        accept="image/jpeg,image/png">
                    <?php if ($employee['driving_licence_image']): ?>
                        <p>Current: <a href="<?php echo htmlspecialchars($employee['driving_licence_image']); ?>"
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

                <div class="form-group">
                    <label for="gender">Gender <span class="required">*</span></label>
                    <select id="gender" name="gender" required>
                        <option value="male" <?php echo ($employee['gender'] === 'male') ? 'selected' : ''; ?>>Male
                        </option>
                        <option value="female" <?php echo ($employee['gender'] === 'female') ? 'selected' : ''; ?>>Female
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="factory">Factory <span class="required">*</span></label>
                    <select id="factory" name="factory" required>
                        <option value="1" <?php echo ($employee['factory'] === '1') ? 'selected' : ''; ?>>Factory 1
                        </option>
                        <option value="2" <?php echo ($employee['factory'] === '2') ? 'selected' : ''; ?>>Factory 2
                        </option>
                        <option value="3" <?php echo ($employee['factory'] === '3') ? 'selected' : ''; ?>>Factory 3
                        </option>
                        <option value="4" <?php echo ($employee['factory'] === '4') ? 'selected' : ''; ?>>Factory 4
                        </option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group readonly">
                        <label for="department">Department</label>
                        <input type="text" id="department"
                            value="<?php echo htmlspecialchars($employee['department'] ?? 'Not specified'); ?>"
                            readonly>
                    </div>
                    <div class="form-group readonly">
                        <label for="position">Position</label>
                        <input type="text" id="position"
                            value="<?php echo htmlspecialchars($employee['position'] ?? 'Not specified'); ?>" readonly>
                    </div>
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" maxlength="32"
                        placeholder="Enter your full address"><?php echo htmlspecialchars($employee['address'] ?? ''); ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group readonly">
                        <label for="hire_date">Hire Date</label>
                        <input type="text" id="hire_date"
                            value="<?php echo $employee['hire_date'] ? date('F j, Y', strtotime($employee['hire_date'])) : 'Not specified'; ?>"
                            readonly>
                    </div>
                    <div class="form-group readonly">
                        <label for="status">Status</label>
                        <input type="text" id="status" value="<?php echo ucfirst($employee['status'] ?? 'active'); ?>"
                            readonly>
                    </div>
                </div>

                <div class="form-group readonly">
                    <label for="salary">Salary</label>
                    <input type="text" id="salary"
                        value="<?php echo htmlspecialchars($employee['salary'] ?? 'Not specified'); ?>" readonly>
                </div>

                <div class="form-group readonly">
                    <label for="dismissal_reason">Dismissal Reason</label>
                    <textarea id="dismissal_reason"
                        readonly><?php echo htmlspecialchars($employee['dismissal_reason'] ?? 'N/A'); ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group readonly">
                        <label for="created_at">Created At</label>
                        <input type="text" id="created_at"
                            value="<?php echo $employee['created_at'] ? date('F j, Y H:i:s', strtotime($employee['created_at'])) : 'Not specified'; ?>"
                            readonly>
                    </div>
                    <div class="form-group readonly">
                        <label for="updated_at">Updated At</label>
                        <input type="text" id="updated_at"
                            value="<?php echo $employee['updated_at'] ? date('F j, Y H:i:s', strtotime($employee['updated_at'])) : 'Not specified'; ?>"
                            readonly>
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

                <div class="children-container">
                    <h3>Children</h3>
                    <?php foreach ($children as $index => $child): ?>
                        <div class="child-row" data-child-id="<?php echo $child['id']; ?>">
                            <div class="form-group">
                                <label for="child_first_name_<?php echo $child['id']; ?>">Child First Name</label>
                                <input type="text" id="child_first_name_<?php echo $child['id']; ?>"
                                    name="children[<?php echo $index; ?>][child_first_name]"
                                    value="<?php echo htmlspecialchars($child['child_first_name']); ?>">
                                <input type="hidden" name="children[<?php echo $index; ?>][id]"
                                    value="<?php echo $child['id']; ?>">
                            </div>
                            <div class="form-group">
                                <label for="child_second_name_<?php echo $child['id']; ?>">Child Second Name</label>
                                <input type="text" id="child_second_name_<?php echo $child['id']; ?>"
                                    name="children[<?php echo $index; ?>][child_second_name]"
                                    value="<?php echo htmlspecialchars($child['child_second_name']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="child_date_of_birth_<?php echo $child['id']; ?>">Birth Date</label>
                                <input type="date" id="child_date_of_birth_<?php echo $child['id']; ?>"
                                    name="children[<?php echo $index; ?>][child_date_of_birth]"
                                    value="<?php echo htmlspecialchars($child['child_date_of_birth']); ?>">
                            </div>
                            <div class="form-group">
                                <label>Remove</label>
                                <input type="checkbox" class="remove-child" name="remove_child[]"
                                    value="<?php echo $child['id']; ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div id="new-children"></div>
                    <button type="button" class="btn btn-secondary" id="add-child">Add Child</button>
                </div>

                <div class="form-actions">
                    <button type="submit" name="update_profile" class="btn">Update Profile</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('add-child').addEventListener('click', function () {
            const container = document.getElementById('new-children');
            const index = container.children.length + <?php echo count($children); ?>;
            const newRow = document.createElement('div');
            newRow.className = 'child-row';
            newRow.innerHTML = `
                <div class="form-group">
                    <label for="child_first_name_new_${index}">Child First Name</label>
                    <input type="text" id="child_first_name_new_${index}" name="children[${index}][child_first_name]">
                </div>
                <div class="form-group">
                    <label for="child_second_name_new_${index}">Child Second Name</label>
                    <input type="text" id="child_second_name_new_${index}" name="children[${index}][child_second_name]">
                </div>
                <div class="form-group">
                    <label for="child_date_of_birth_new_${index}">Birth Date</label>
                    <input type="date" id="child_date_of_birth_new_${index}" name="children[${index}][child_date_of_birth]">
                </div>
                <div class="form-group">
                    <label>Remove</label>
                    <button type="button" class="remove-child btn btn-secondary" onclick="this.parentElement.parentElement.remove()">Remove</button>
                </div>
            `;
            container.appendChild(newRow);
        });
    </script>
</body>

</html>