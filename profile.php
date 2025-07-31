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
    ORDER BY ec.id
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

// Function to update children count in employee_profiles
function updateChildrenCount($pdo, $user_id)
{
    // Count actual children records
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM employee_children ec 
        JOIN employee_profiles ep ON ec.employee_profile_id = ep.id 
        WHERE ep.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $count = $stmt->fetchColumn();

    // Update the children count in employee_profiles
    $stmt = $pdo->prepare("
        UPDATE employee_profiles 
        SET children = ? 
        WHERE user_id = ?
    ");
    $stmt->execute([$count, $user_id]);

    return $count;
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
    $has_driving_license = isset($_POST['has_driving_license']) && $_POST['has_driving_license'] == '1' ? 1 : 0;
    $driving_license_number = sanitize($_POST['driving_license_number'] ?? '');
    $driving_license_category = sanitize($_POST['driving_license_category'] ?? '');
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
    if ($has_driving_license && empty($driving_license_number)) {
        $error = 'Driving license number is required';
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
            // Check if all required fields are provided for this child
            $hasFirstName = !empty(trim($child['child_first_name'] ?? ''));
            $hasSecondName = !empty(trim($child['child_second_name'] ?? ''));
            $hasBirthDate = !empty(trim($child['child_date_of_birth'] ?? ''));

            // If any field is provided, all must be provided
            if ($hasFirstName || $hasSecondName || $hasBirthDate) {
                if (!$hasFirstName || !$hasSecondName || !$hasBirthDate) {
                    $error = "Child " . ($index + 1) . ": All fields (First Name, Second Name, Birth Date) must be filled";
                    break;
                }

                // Validate birth date
                $birth_date = DateTime::createFromFormat('Y-m-d', $child['child_date_of_birth']);
                if (!$birth_date || $birth_date->format('Y-m-d') !== $child['child_date_of_birth']) {
                    $error = "Child " . ($index + 1) . ": Invalid birth date format";
                    break;
                }

                if ($birth_date > new DateTime()) {
                    $error = "Child " . ($index + 1) . ": Birth date cannot be in the future";
                    break;
                }

                // Check if birth date is reasonable (not too old)
                $minDate = new DateTime();
                $minDate->sub(new DateInterval('P100Y')); // 100 years ago
                if ($birth_date < $minDate) {
                    $error = "Child " . ($index + 1) . ": Birth date seems too old";
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

                        try {
                            // Update users table
                            $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                            $stmt->execute([$email, $_SESSION['user_id']]);

                            // Get employee profile ID
                            $stmt = $pdo->prepare("SELECT id FROM employee_profiles WHERE user_id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            $employee_profile_id = $stmt->fetchColumn();

                            if (!$employee_profile_id) {
                                throw new Exception("Employee profile not found");
                            }

                            // Remove selected children
                            if (!empty($children_to_remove)) {
                                $placeholders = implode(',', array_fill(0, count($children_to_remove), '?'));
                                $stmt = $pdo->prepare("
                                    DELETE FROM employee_children 
                                    WHERE id IN ($placeholders) 
                                    AND employee_profile_id = ?
                                ");
                                $params = array_merge($children_to_remove, [$employee_profile_id]);
                                $stmt->execute($params);
                            }

                            // Process children data
                            $existingChildIds = [];
                            foreach ($children as $child) {
                                $existingChildIds[] = $child['id'];
                            }

                            foreach ($children_data as $index => $child) {
                                // Skip empty children
                                $hasFirstName = !empty(trim($child['child_first_name'] ?? ''));
                                $hasSecondName = !empty(trim($child['child_second_name'] ?? ''));
                                $hasBirthDate = !empty(trim($child['child_date_of_birth'] ?? ''));

                                if (!$hasFirstName && !$hasSecondName && !$hasBirthDate) {
                                    continue; // Skip completely empty child entries
                                }

                                $childId = isset($child['id']) ? (int) $child['id'] : null;
                                $isExistingChild = $childId && in_array($childId, $existingChildIds);
                                $isMarkedForRemoval = $childId && in_array($childId, $children_to_remove);

                                if ($isExistingChild && !$isMarkedForRemoval) {
                                    // Update existing child
                                    $stmt = $pdo->prepare("
                                        UPDATE employee_children 
                                        SET child_first_name = ?, child_second_name = ?, child_date_of_birth = ?
                                        WHERE id = ? AND employee_profile_id = ?
                                    ");
                                    $stmt->execute([
                                        sanitize($child['child_first_name']),
                                        sanitize($child['child_second_name']),
                                        $child['child_date_of_birth'],
                                        $childId,
                                        $employee_profile_id
                                    ]);
                                } elseif (!$isExistingChild && !$isMarkedForRemoval) {
                                    // Add new child
                                    $stmt = $pdo->prepare("
                                        INSERT INTO employee_children (employee_profile_id, child_first_name, child_second_name, child_date_of_birth) 
                                        VALUES (?, ?, ?, ?)
                                    ");
                                    $stmt->execute([
                                        $employee_profile_id,
                                        sanitize($child['child_first_name']),
                                        sanitize($child['child_second_name']),
                                        $child['child_date_of_birth']
                                    ]);
                                }
                            }

                            // Update children count in employee_profiles
                            $children_count = updateChildrenCount($pdo, $_SESSION['user_id']);

                            // Update employee_profiles table (including the updated children count)
                            $stmt = $pdo->prepare("
                                UPDATE employee_profiles 
                                SET first_name = ?, last_name = ?, employee_id = ?, ncin = ?, cin_image_front = ?, cin_image_back = ?, 
                                    cnss_first = ?, cnss_last = ?, department = ?, position = ?, phone = ?, address = ?, 
                                    date_of_birth = ?, education = ?, has_driving_license = ?, driving_license_category = ?, 
                                    driving_license_number = ?,driving_license_image = ?, gender = ?, factory = ?, civil_status = ?, hire_date = ?, 
                                    salary = ?, profile_picture = ?, status = ?, dismissal_reason = ?, children = ?, updated_at = ?
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
                                $driving_license_category,
                                $driving_license_number,
                                $driving_license_image,
                                $gender,
                                $factory,
                                $civil_status,
                                $hire_date,
                                $salary,
                                $profile_picture,
                                $status,
                                $dismissal_reason,
                                $children_count, // Updated children count
                                $updated_at,
                                $_SESSION['user_id']
                            ]);

                            $pdo->commit();
                            $success = 'Profile updated successfully! Children count: ' . $children_count;

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
                                ORDER BY ec.id
                            ");
                            $stmt->execute([$_SESSION['user_id']]);
                            $children = $stmt->fetchAll();

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            throw $e;
                        }
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Update failed: ' . $e->getMessage();
            }
        }
    }
}

// Helper function to check if file is an image
function isImage($filename) {
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $imageExtensions);
}
?>
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
        .logo {
            height: 50px;
            margin-right: 15px;
        }
        img {
            overflow-clip-margin: content-box;
            overflow: clip;
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

        .image-upload-box:hover .upload-placeholder {
            color: #007bff;
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
                <?php if ($employee['profile_picture'] && isImage($employee['profile_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($employee['profile_picture']); ?>" alt="Profile Picture">
                <?php else: ?>
                    <div class="placeholder-icon">👤</div>
                <?php endif; ?>
            </div>

            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png" class="profile-picture-input">

                <div class="form-content">
                    <div class="form-row">
                        <div class="form-group readonly">
                            <label for="employee_id">Employee ID</label>
                            <input type="text" id="employee_id"
                                value="<?php echo htmlspecialchars($employee['employee_id'] ?? NULL); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" value="<?php echo htmlspecialchars($employee['username']); ?>"
                                >
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
                    </div>

                   
                    <div class="form-section">
                    <h3>CIN Images</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>CIN Image (Front)</label>
                            <div class="image-upload-box" onclick="document.getElementById('cin_image_front').click()">
                                <?php if ($employee['cin_image_front'] && isImage($employee['cin_image_front'])): ?>
                                    <img src="<?php echo htmlspecialchars($employee['cin_image_front']); ?>" alt="CIN Front" class="uploaded-image">
                                <?php else: ?>
                                    <div class="upload-placeholder">
                                        <div class="upload-icon">📷</div>
                                        <div class="upload-text">Click to upload CIN Front</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="file" id="cin_image_front" name="cin_image_front" accept="image/jpeg,image/png" style="display: none;">
                        </div>
                        
                        <div class="form-group">
                            <label>CIN Image (Back)</label>
                            <div class="image-upload-box" onclick="document.getElementById('cin_image_back').click()">
                                <?php if ($employee['cin_image_back'] && isImage($employee['cin_image_back'])): ?>
                                    <img src="<?php echo htmlspecialchars($employee['cin_image_back']); ?>" alt="CIN Back" class="uploaded-image">
                                <?php else: ?>
                                    <div class="upload-placeholder">
                                        <div class="upload-icon">📷</div>
                                        <div class="upload-text">Click to upload CIN Back</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="file" id="cin_image_back" name="cin_image_back" accept="image/jpeg,image/png" style="display: none;">
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
                </div>
                <?php if (isset($employee['has_driving_license']) && $employee['has_driving_license'] == '1'): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            document.getElementById('driving-license-section').style.display = 'block';
                        });
                    </script>
                    <div class="form-group" id="driving-license-section">
                        <label for="driving_license_category">Driving License Category</label>
                        <input type="text" id="driving_license_category" name="driving_license_category"
                            value="<?php echo htmlspecialchars($employee['driving_license_category'] ?? ''); ?>">
                        <label for="driving_license_number">Driving License Number</label>
                        <input type="text" id="driving_license_number" name="driving_license_number"
                            value="<?php echo htmlspecialchars($employee['driving_license_number'] ?? ''); ?>">
                    </div>

                    <!-- Enhanced Driving License Image Section -->
                    <div class="form-section" id="driving-license-image-section">
                        <h3>Driving License</h3>
                        <div class="form-group">
                            <label>Driving License Image</label>
                            <div class="image-upload-box" onclick="document.getElementById('driving_license_image').click()">
                                <?php if ($employee['driving_license_image'] && isImage($employee['driving_license_image'])): ?>
                                    <img src="<?php echo htmlspecialchars($employee['driving_license_image']); ?>" alt="Driving License" class="uploaded-image">
                                <?php else: ?>
                                    <div class="upload-placeholder">
                                        <div class="upload-icon">🚗</div>
                                        <div class="upload-text">Click to upload Driving License</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="file" id="driving_license_image" name="driving_license_image" accept="image/jpeg,image/png" style="display: none;">
                        </div>
                    </div>
                <?php endif; ?>                   
                <div class="form-group">
                    <label for="gender">Gender <span class="required">*</span></label>
                        <select id="gender" name="gender" required>
                            <option value="male" <?php echo ($employee['gender'] === 'male') ? 'selected' : ''; ?>>Male
                            </option>
                            <option value="female" <?php echo ($employee['gender'] === 'female') ? 'selected' : ''; ?>>
                                Female
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
                        <div class="form-group">
                            <label for="department">Department</label>
                            <input type="text" id="department"
                                value="<?php echo htmlspecialchars($employee['department'] ?? 'Not specified'); ?>"
                            >
                        </div>
                        <div class="form-group">
                            <label for="position">Position</label>
                            <input type="text" id="position"
                                value="<?php echo htmlspecialchars($employee['position'] ?? 'Not specified'); ?>"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" maxlength="32"
                            placeholder="Enter your full address" value="<?php echo htmlspecialchars($employee['address'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group readonly">
                            <label for="hire_date">Hire Date</label>
                            <input type="text" id="hire_date"
                                value="<?php echo $employee['hire_date'] ? date('F j, Y', strtotime($employee['hire_date'])) : 'Not specified'; ?>"
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
            </form>
        </div>
    </div>

    <script>
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
        document.addEventListener('DOMContentLoaded', function () {
        const alert = document.getElementById('alert-message');
        if (alert) {
            setTimeout(() => {
                alert.style.display = 'none';
            }, 3000);
        }
    });
    </script>
</body>

</html>