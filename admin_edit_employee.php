<?php
require_once 'config.php';
requireLogin();
requireAdmin();

$page_title = "Edit Employee";
$additional_css = [];
$additional_styles = '';

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
                        // Check for both possible field name formats
                        $child_first_name = isset($child['child_first_name']) ? $child['child_first_name'] :
                            (isset($child['first_name']) ? $child['first_name'] : '');
                        $child_second_name = isset($child['child_second_name']) ? $child['child_second_name'] :
                            (isset($child['second_name']) ? $child['second_name'] : '');
                        $child_dob = isset($child['child_date_of_birth']) ? $child['child_date_of_birth'] :
                            (isset($child['date_of_birth']) ? $child['date_of_birth'] : '');

                        // Only insert if we have the required data
                        if (!empty($child_first_name) && !empty($child_second_name) && !empty($child_dob)) {
                            $stmt = $pdo->prepare("
            INSERT INTO employee_children (employee_profile_id, child_first_name, child_second_name, child_date_of_birth, created_at, updated_at) 
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
                            $stmt->execute([
                                $employee['id'],
                                sanitize($child_first_name),
                                sanitize($child_second_name),
                                $child_dob
                            ]);
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

<?php include 'admin_header.php'; ?>

<head>
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
    <script>
        function addChildRow(first_name = '', second_name = '', dob = '') {
            const container = document.querySelector('.children-container');
            const childCount = container.querySelectorAll('.child-row').length;
            const row = document.createElement('div');
            row.className = 'child-row';
            row.innerHTML = `
        <input type="text" name="children[${childCount}][child_first_name]" value="${first_name}" placeholder="Child's First Name" required>
        <input type="text" name="children[${childCount}][child_second_name]" value="${second_name}" placeholder="Child's Second Name" required>
        <input type="date" name="children[${childCount}][child_date_of_birth]" value="${dob}" required>
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
    <div class="container">
        <div class="profile-header">
            <h1>Edit Employee</h1>
            <p>Update employee information</p>
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
                        <div class="form-group">
                            <label for="gender">Gender <span class="required">*</span></label>
                            <select id="gender" name="gender" required>
                                <option value="male" <?php echo ($employee['gender'] === 'male') ? 'selected' : ''; ?>>
                                    Male
                                </option>
                                <option value="female" <?php echo ($employee['gender'] === 'female') ? 'selected' : ''; ?>>
                                    Female</option>
                            </select>
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
                        <div class="form-group">
                            <label for="address">Address</label>
                            <input type="text" id="address" name="address" maxlength="32"
                                placeholder="Enter your full address"
                                value="<?php echo htmlspecialchars($employee['address'] ?? ''); ?>">
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
                        <div class="form-group">
                            <label for="salary">Salary</label>
                            <input type="number" id="salary" name="salary" step="0.01" min="0"
                                value="<?php echo htmlspecialchars($employee['salary'] ?? ''); ?>">
                        </div>
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
                    <div class="form-group">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status">
                                    <option value="active" <?php echo ($employee['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($employee['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="dismissed" <?php echo ($employee['status'] === 'dismissed') ? 'selected' : ''; ?>>Dismissed</option>
                                </select>
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
                </div>
                <div class="form-actions">
                    <button type="submit" name="update_employee" class="btn btn-submit">Update Employee</button>
                    <a href="employees_listing.php" class="btn btn-cancel">Back to Employees</a>
                </div>
            </form>
        </div>
    </div>
    <script>
        function updateChildrenCounter() {
            const childRows = document.querySelectorAll('.child-row');
            const counter = document.getElementById('children-counter');
            const countInput = document.getElementById('children_count');

            const count = childRows.length;

            if (counter) {
                counter.textContent = `(${count})`;
            }
            if (countInput) {
                countInput.value = count;
            }
        }

        // Update your existing add-child event listener (replace the existing one):
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
            updateChildrenCounter(); // Add this line
        });

        // Update your existing removeChildRow function:
        function removeChildRow(button, childId = null) {
            const row = button.closest('.child-row');
            row.remove();
            reindexChildRows();
            updateChildrenCounter(); // Add this line
        }

        // Re-index child rows to ensure consistent array indices
        function reindexChildRows() {
            const rows = document.querySelectorAll('.child-row');
            rows.forEach((row, index) => {
                const inputs = row.querySelectorAll('input[name]');
                inputs.forEach(input => {
                    const name = input.name;
                    if (name.startsWith('children[')) {
                        // Update the index in the name attribute
                        const newName = name.replace(/children\[\d+\]/, `children[${index}]`);
                        input.name = newName;

                        // Update the id attribute as well
                        const id = input.id;
                        if (id) {
                            const newId = id.replace(/_\d+(_|$)/, `_${index}$1`);
                            input.id = newId;
                        }
                    }
                });

                // Update labels as well
                const labels = row.querySelectorAll('label[for]');
                labels.forEach(label => {
                    const forAttr = label.getAttribute('for');
                    if (forAttr) {
                        const newFor = forAttr.replace(/_\d+(_|$)/, `_${index}$1`);
                        label.setAttribute('for', newFor);
                    }
                });
            });
        }
    </script>
</body>

</html>