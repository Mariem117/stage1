<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';

// Get current user's employee profile
try {
    $stmt = $pdo->prepare("
        SELECT u.*, ep.* 
        FROM users u 
        LEFT JOIN employee_profiles ep ON u.id = ep.user_id 
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        $error = "Employee profile not found. Please contact administrator.";
        // Create default employee array
        $employee = [
            'id' => null,
            'username' => '',
            'email' => '',
            'first_name' => '',
            'last_name' => '',
            'employee_id' => '',
            'phone' => '',
            'date_of_birth' => '',
            'ncin' => '',
            'gender' => 'male',
            'factory' => 1,
            'department' => '',
            'position' => '',
            'address' => '',
            'hire_date' => '',
            'civil_status' => 'single',
            'profile_picture' => null,
            'cin_image' => null,
            'cin_image_front' => null,
            'cin_image_back' => null,
            'driving_license_image' => null,
            'has_driving_license' => 0,
            'driving_license_category' => '',
            'driving_license_number' => '',
            'cnss_first' => '',
            'cnss_last' => '',
            'education' => ''
        ];
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
    error_log("Profile database error: " . $e->getMessage());
    $employee = [];
}

// Get children data - using correct column name from your database
$children = [];
if (!empty($employee['id'])) {
    try {
        $children_stmt = $pdo->prepare("SELECT * FROM employee_children WHERE employee_profile_id = ? OR employee_id = ?");
        $children_stmt->execute([$employee['id'], $employee['id']]);
        $children = $children_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("Children query error: " . $e->getMessage());
        $children = [];
    }
}

// Handle profile update
if ($_POST && isset($_POST['update_profile']) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    try {
        $pdo->beginTransaction();

        // Update users table
        $user_stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
        $user_stmt->execute([
            sanitize($_POST['email']),
            $_SESSION['user_id']
        ]);

        // Update or insert employee profile
        if ($employee['id']) {
            // Update existing profile
            $profile_stmt = $pdo->prepare("
                UPDATE employee_profiles SET 
                    first_name = ?, last_name = ?, phone = ?, date_of_birth = ?, 
                    ncin = ?, gender = ?, factory = ?, department = ?, position = ?, 
                    address = ?, civil_status = ?, cnss_first = ?, cnss_last = ?, 
                    education = ?, has_driving_license = ?, driving_license_category = ?, 
                    driving_license_number = ?
                WHERE id = ?
            ");
            $profile_stmt->execute([
                sanitize($_POST['first_name']),
                sanitize($_POST['last_name']),
                sanitize($_POST['phone']),
                $_POST['date_of_birth'] ?: null,
                sanitize($_POST['ncin']),
                sanitize($_POST['gender']),
                (int) $_POST['factory'],
                sanitize($_POST['department']),
                sanitize($_POST['position']),
                sanitize($_POST['address']),
                sanitize($_POST['civil_status']),
                sanitize($_POST['cnss_first']),
                sanitize($_POST['cnss_last']),
                sanitize($_POST['education']),
                isset($_POST['has_driving_license']) ? 1 : 0,
                sanitize($_POST['driving_license_category']),
                sanitize($_POST['driving_license_number']),
                $employee['id']
            ]);
        } else {
            // Insert new profile
            $profile_stmt = $pdo->prepare("
                INSERT INTO employee_profiles (
                    user_id, first_name, last_name, phone, date_of_birth, ncin, 
                    gender, factory, department, position, address, civil_status, 
                    cnss_first, cnss_last, education, has_driving_license, 
                    driving_license_category, driving_license_number
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $profile_stmt->execute([
                $_SESSION['user_id'],
                sanitize($_POST['first_name']),
                sanitize($_POST['last_name']),
                sanitize($_POST['phone']),
                $_POST['date_of_birth'] ?: null,
                sanitize($_POST['ncin']),
                sanitize($_POST['gender']),
                (int) $_POST['factory'],
                sanitize($_POST['department']),
                sanitize($_POST['position']),
                sanitize($_POST['address']),
                sanitize($_POST['civil_status']),
                sanitize($_POST['cnss_first']),
                sanitize($_POST['cnss_last']),
                sanitize($_POST['education']),
                isset($_POST['has_driving_license']) ? 1 : 0,
                sanitize($_POST['driving_license_category']),
                sanitize($_POST['driving_license_number'])
            ]);
            $employee_profile_id = $pdo->lastInsertId();
        }

        // Handle children updates
        if (isset($_POST['children']) && is_array($_POST['children'])) {
            $employee_profile_id = $employee['id'] ?: $pdo->lastInsertId();

            foreach ($_POST['children'] as $child_data) {
                if (!empty($child_data['child_first_name']) && !empty($child_data['child_second_name'])) {
                    if (!empty($child_data['id'])) {
                        // Update existing child
                        $child_stmt = $pdo->prepare("
                            UPDATE employee_children SET 
                                child_first_name = ?, child_second_name = ?, child_date_of_birth = ?
                            WHERE id = ? AND employee_profile_id = ?
                        ");
                        $child_stmt->execute([
                            sanitize($child_data['child_first_name']),
                            sanitize($child_data['child_second_name']),
                            $child_data['child_date_of_birth'] ?: null,
                            (int) $child_data['id'],
                            $employee_profile_id
                        ]);
                    } else {
                        // Insert new child
                        $child_stmt = $pdo->prepare("
                            INSERT INTO employee_children (
                                employee_profile_id, employee_id, child_first_name, 
                                child_second_name, child_date_of_birth
                            ) VALUES (?, ?, ?, ?, ?)
                        ");
                        $child_stmt->execute([
                            $employee_profile_id,
                            $employee_profile_id, // Set both for compatibility
                            sanitize($child_data['child_first_name']),
                            sanitize($child_data['child_second_name']),
                            $child_data['child_date_of_birth'] ?: null
                        ]);
                    }
                }
            }
        }

        // Handle child removals
        if (isset($_POST['remove_child']) && is_array($_POST['remove_child'])) {
            foreach ($_POST['remove_child'] as $child_id) {
                $remove_stmt = $pdo->prepare("DELETE FROM employee_children WHERE id = ? AND employee_profile_id = ?");
                $remove_stmt->execute([(int) $child_id, $employee['id']]);
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
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Failed to update profile: ' . $e->getMessage();
        error_log('Profile update error: ' . $e->getMessage());
    }
}

// Only try to get admin-specific data if user is admin
$requests = [];
$notifications = [];
$stats = [];
$available_users = [];

if ($_SESSION['role'] === 'admin') {
    // Admin-specific queries with error handling
    try {
        // Check if tables exist before querying
        $table_check = $pdo->query("SHOW TABLES LIKE 'employee_requests'");
        if ($table_check->rowCount() > 0) {
            // Get requests statistics
            $stats_stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_requests,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_requests,
                    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_requests,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_requests,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_requests,
                    COUNT(CASE WHEN priority = 'urgent' THEN 1 END) as urgent_requests,
                    COUNT(CASE WHEN priority = 'high' THEN 1 END) as high_priority_requests,
                    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_requests
                FROM employee_requests
            ");
            $stats_stmt->execute();
            $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        // Check if notifications table exists
        $notification_check = $pdo->query("SHOW TABLES LIKE 'notifications'");
        if ($notification_check->rowCount() > 0) {
            // Get admin notifications
            $notifications_stmt = $pdo->prepare("
                SELECT n.*, COUNT(n.id) as notification_count
                FROM notifications n
                WHERE n.user_id = ? AND n.type IN ('new_request', 'request_updated', 'urgent_request')
                GROUP BY n.type, n.is_read
                ORDER BY n.created_at DESC
                LIMIT 10
            ");
            $notifications_stmt->execute([$_SESSION['user_id']]);
            $notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

    } catch (Exception $e) {
        error_log("Admin data query error: " . $e->getMessage());
    }
}
?>

<?php
// Determine header based on role
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
if ($isAdmin) {
    // Use unified admin header with page-specific CSS
    $page_title = "My Profile";
    $additional_css = ["profile.css"];
    include 'admin_header.php';
}
?>

<?php if (!$isAdmin): ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Employee Management System</title>
    <link rel="stylesheet" href="profile.css">
    <style>
        .profile-form {
            position: relative;
        }

        .profile-picture-corner {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 10;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid #fff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .profile-picture-corner:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .profile-picture-corner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-picture-corner .placeholder-icon {
            font-size: 48px;
            color: #ccc;
        }

        .profile-picture-input {
            display: none;
        }

        .form-content {
            padding-left: 140px;
            padding-top: 20px;
        }

        .image-upload-box {
            width: 100%;
            height: 200px;
            border: 2px dashed #ccc;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: border-color 0.3s ease;
            background-color: #fafafa;
            margin-top: 8px;
        }

        .image-upload-box:hover {
            border-color: #007bff;
            background-color: #f0f8ff;
        }

        .uploaded-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 6px;
        }

        .upload-placeholder {
            text-align: center;
            color: #666;
        }

        .upload-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }

        .upload-text {
            font-size: 16px;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .profile-picture-corner {
                position: static;
                margin: 0 auto 20px;
                display: block;
            }

            .form-content {
                padding-left: 0;
                padding-top: 0;
            }
        }
    </style>
    </head>

    <body>
        <nav class="navbar">
            <div class="navbar-container">
                <img src="logo.png" alt="Logo" class="logo">
                <div class="navbar-nav">
                    <a href="profile.php" class="nav-link">My Profile</a>
                    <a href="emp_request.php" class="nav-link">Requests</a>
                    <a href="logout.php" class="nav-link">Logout</a>
                </div>
            </div>
        </nav>
<?php endif; ?>

    <div class="container">
        <div class="profile-header">
            <h1>My Profile</h1>
            <p>Update your personal information</p>
        </div>

        <div class="profile-form">
            <?php if ($error): ?>
                <div class="alert alert-error" id="alert-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success" id="alert-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <!-- Profile Picture Corner -->
            <div class="profile-picture-corner" onclick="document.getElementById('profile_picture').click()">
                <?php if (!empty($employee['profile_picture']) && isImage($employee['profile_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($employee['profile_picture']); ?>" alt="Profile Picture">
                <?php else: ?>
                    <div class="placeholder-icon">👤</div>
                <?php endif; ?>
            </div>

            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png"
                    class="profile-picture-input">

                <div class="form-content">
                    <div class="form-row">
                        <div class="form-group readonly">
                            <label for="employee_id">Employee ID</label>
                            <input type="text" id="employee_id"
                                value="<?php echo htmlspecialchars($employee['employee_id'] ?? ''); ?>" readonly>
                        </div>
                        <div class="form-group readonly">
                            <label for="username">Username</label>
                            <input type="text" id="username"
                                value="<?php echo htmlspecialchars($employee['username'] ?? ''); ?>" readonly>
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
                            value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>" required>
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

                    <div class="form-group">
                        <label for="ncin">NCIN</label>
                        <input type="text" id="ncin" name="ncin"
                            value="<?php echo htmlspecialchars($employee['ncin'] ?? ''); ?>">
                    </div>

                    <div class="form-section">
                        <h3>CIN Images</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>CIN Image (Front)</label>
                                <div class="image-upload-box"
                                    onclick="document.getElementById('cin_image_front').click()">
                                    <?php if (!empty($employee['cin_image_front']) && isImage($employee['cin_image_front'])): ?>
                                        <img src="<?php echo htmlspecialchars($employee['cin_image_front']); ?>"
                                            alt="CIN Front" class="uploaded-image">
                                    <?php elseif (!empty($employee['cin_image']) && isImage($employee['cin_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($employee['cin_image']); ?>" alt="CIN"
                                            class="uploaded-image">
                                    <?php else: ?>
                                        <div class="upload-placeholder">
                                            <div class="upload-icon">📷</div>
                                            <div class="upload-text">Click to upload CIN Front</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="cin_image_front" name="cin_image_front"
                                    accept="image/jpeg,image/png" style="display: none;">
                            </div>

                            <div class="form-group">
                                <label>CIN Image (Back)</label>
                                <div class="image-upload-box"
                                    onclick="document.getElementById('cin_image_back').click()">
                                    <?php if (!empty($employee['cin_image_back']) && isImage($employee['cin_image_back'])): ?>
                                        <img src="<?php echo htmlspecialchars($employee['cin_image_back']); ?>"
                                            alt="CIN Back" class="uploaded-image">
                                    <?php else: ?>
                                        <div class="upload-placeholder">
                                            <div class="upload-icon">📷</div>
                                            <div class="upload-text">Click to upload CIN Back</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="cin_image_back" name="cin_image_back"
                                    accept="image/jpeg,image/png" style="display: none;">
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="cnss_first">CNSS (First 8 digits)</label>
                            <input type="text" id="cnss_first" name="cnss_first" maxlength="8" pattern="\d{8}"
                                value="<?php echo htmlspecialchars($employee['cnss_first'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="cnss_last">CNSS (Last 2 digits)</label>
                            <input type="text" id="cnss_last" name="cnss_last" maxlength="2" pattern="\d{2}"
                                value="<?php echo htmlspecialchars($employee['cnss_last'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="education">Education</label>
                        <input type="text" id="education" name="education"
                            value="<?php echo htmlspecialchars($employee['education'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Has Driving License</label>
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="has_driving_license" value="1" <?php echo (!empty($employee['has_driving_license']) && $employee['has_driving_license'] == '1') ? 'checked' : ''; ?>>
                                Yes
                            </label>
                            <label>
                                <input type="radio" name="has_driving_license" value="0" <?php echo (empty($employee['has_driving_license']) || $employee['has_driving_license'] == '0') ? 'checked' : ''; ?>>
                                No
                            </label>
                        </div>
                    </div>

                    <div class="form-group" id="driving-license-section"
                        style="display: <?php echo (!empty($employee['has_driving_license']) && $employee['has_driving_license'] == '1') ? 'block' : 'none'; ?>;">
                        <label for="driving_license_category">Driving License Category</label>
                        <input type="text" id="driving_license_category" name="driving_license_category"
                            value="<?php echo htmlspecialchars($employee['driving_license_category'] ?? ''); ?>">

                        <label for="driving_license_number">Driving License Number</label>
                        <input type="text" id="driving_license_number" name="driving_license_number"
                            value="<?php echo htmlspecialchars($employee['driving_license_number'] ?? ''); ?>">

                        <!-- Driving License Image Section -->
                        <div class="form-section" id="driving-license-image-section">
                            <h3>Driving License</h3>
                            <div class="form-group">
                                <label>Driving License Image</label>
                                <div class="image-upload-box"
                                    onclick="document.getElementById('driving_license_image').click()">
                                    <?php if (!empty($employee['driving_license_image']) && isImage($employee['driving_license_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($employee['driving_license_image']); ?>"
                                            alt="Driving License" class="uploaded-image">
                                    <?php else: ?>
                                        <div class="upload-placeholder">
                                            <div class="upload-icon">🚗</div>
                                            <div class="upload-text">Click to upload Driving License</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="driving_license_image" name="driving_license_image"
                                    accept="image/jpeg,image/png" style="display: none;">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="gender">Gender <span class="required">*</span></label>
                        <select id="gender" name="gender" required>
                            <option value="male" <?php echo ($employee['gender'] === 'male') ? 'selected' : ''; ?>>Male
                            </option>
                            <option value="female" <?php echo ($employee['gender'] === 'female') ? 'selected' : ''; ?>>
                                Female</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="factory">Factory <span class="required">*</span></label>
                        <select id="factory" name="factory" required>
                            <option value="1" <?php echo ($employee['factory'] == '1') ? 'selected' : ''; ?>>Factory 1
                            </option>
                            <option value="2" <?php echo ($employee['factory'] == '2') ? 'selected' : ''; ?>>Factory 2
                            </option>
                            <option value="3" <?php echo ($employee['factory'] == '3') ? 'selected' : ''; ?>>Factory 3
                            </option>
                            <option value="4" <?php echo ($employee['factory'] == '4') ? 'selected' : ''; ?>>Factory 4
                            </option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="department">Department</label>
                            <input type="text" id="department" name="department"
                                value="<?php echo htmlspecialchars($employee['department'] ?? 'Not specified'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="position">Position</label>
                            <input type="text" id="position" name="position"
                                value="<?php echo htmlspecialchars($employee['position'] ?? 'Not specified'); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" maxlength="32"
                            placeholder="Enter your full address"
                            value="<?php echo htmlspecialchars($employee['address'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group readonly">
                            <label for="hire_date">Hire Date</label>
                            <input type="text" id="hire_date"
                                value="<?php echo !empty($employee['hire_date']) ? date('F j, Y', strtotime($employee['hire_date'])) : 'Not specified'; ?>"
                                readonly>
                        </div>
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
                                    <button type="button" class="remove-child"
                                        onclick="removeChildRow(this, <?php echo $child['id']; ?>)">×</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div id="new-children"></div>
                        <button type="button" class="btn btn-secondary" id="add-child">Add Child</button>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="update_profile" class="btn">Update Profile</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Show/hide driving license section
        document.addEventListener('DOMContentLoaded', function () {
            const drivingLicenseRadios = document.querySelectorAll('input[name="has_driving_license"]');
            const drivingLicenseSection = document.getElementById('driving-license-section');

            drivingLicenseRadios.forEach(radio => {
                radio.addEventListener('change', function () {
                    if (this.value === '1') {
                        drivingLicenseSection.style.display = 'block';
                    } else {
                        drivingLicenseSection.style.display = 'none';
                    }
                });
            });
        });

        // Add new child row
        document.getElementById('add-child').addEventListener('click', function () {
            const container = document.getElementById('new-children');
            const index = document.querySelectorAll('.child-row').length;
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
                    <button type="button" class="remove-child" onclick="removeChildRow(this)">×</button>
                </div>
            `;
            container.appendChild(newRow);
        });

        // Remove child row and re-index remaining rows
        function removeChildRow(button, childId = null) {
            const row = button.closest('.child-row');
            if (childId) {
                // Mark existing child for deletion
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'remove_child[]';
                input.value = childId;
                row.appendChild(input);
                row.style.display = 'none'; // Hide the row
            } else {
                // Remove new (unsaved) child row
                row.remove();
            }
            // Re-index all visible child rows
            reindexChildRows();
        }

        // Re-index child rows to ensure consistent array indices
        function reindexChildRows() {
            const rows = document.querySelectorAll('.child-row:not([style*="display: none"])');
            rows.forEach((row, index) => {
                const inputs = row.querySelectorAll('input[name]');
                inputs.forEach(input => {
                    const name = input.name;
                    if (name.startsWith('children[')) {
                        const newName = name.replace(/children\[\d+\]/, `children[${index}]`);
                        input.name = newName;
                    }
                });
            });
        }

        // Auto-hide alert messages
        document.addEventListener('DOMContentLoaded', function () {
            const alert = document.getElementById('alert-message');
            if (alert) {
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 5000);
            }
        });
    </script>
</body>

</html>