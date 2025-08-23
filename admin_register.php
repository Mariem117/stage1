<?php
require_once 'config.php';

$error = '';
$success = '';
$child_error = '';

function calculateAge($dob)
{
    if (!$dob)
        return null;
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    return $age >= 0 ? $age : null;
}

function validateChildren($children)
{
    $errors = [];
    if (!empty($children)) {
        foreach ($children as $index => $child) {
            $childNum = $index + 1;
            if (empty(trim($child['child_first_name']))) {
                $errors[] = "Child #{$childNum}: First name is required";
            }
            if (empty(trim($child['child_second_name']))) {
                $errors[] = "Child #{$childNum}: Second name is required";
            }
            if (empty($child['child_date_of_birth'])) {
                $errors[] = "Child #{$childNum}: Date of birth is required";
            } else {
                $childAge = calculateAge($child['child_date_of_birth']);
                if ($childAge === null || $childAge < 0 || $childAge > 25) {
                    $errors[] = "Child #{$childNum}: Invalid date of birth";
                }
            }
            if (!empty(trim($child['child_first_name'])) && !preg_match('/^[a-zA-Z\s]+$/', trim($child['child_first_name']))) {
                $errors[] = "Child #{$childNum}: First name can only contain letters and spaces";
            }
            if (!empty(trim($child['child_second_name'])) && !preg_match('/^[a-zA-Z\s]+$/', trim($child['child_second_name']))) {
                $errors[] = "Child #{$childNum}: Second name can only contain letters and spaces";
            }
        }
    }
    return $errors;
}
function validateUploadedFile($file)
{
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error occurred'];
    }

    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'File size must be less than 5MB'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => 'Only JPG, JPEG, PNG, and GIF files are allowed'];
    }

    return ['success' => true];
}

function generateUniqueFileName($originalName)
{
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    return $filename;
}
if ($_POST && isset($_POST['register']) && verifyCSRFToken($_POST['csrf_token'])) {
    // Sanitize and assign ALL variables FIRST
    $username = sanitize($_POST['username']);
    $email = sanitize($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $first_name = sanitize($_POST['first_name']);
    $last_name = sanitize($_POST['last_name']);
    $ncin = sanitize($_POST['ncin']);
    $cnss_first = sanitize($_POST['cnss_first']);
    $cnss_last = sanitize($_POST['cnss_last']);
    $department = sanitize($_POST['department']);
    $position = sanitize($_POST['position']);
    $phone = sanitize($_POST['phone']);
    $address = sanitize(substr($_POST['address'], 0, 32));
    $date_of_birth = $_POST['date_of_birth'] ? $_POST['date_of_birth'] : null;
    $age = $date_of_birth ? calculateAge($date_of_birth) : null;
    $education = sanitize($_POST['education']);
    $has_driving_license = isset($_POST['has_driving_license']) && $_POST['has_driving_license'] == '1' ? 1 : 0;
    $driving_license_category = sanitize($_POST['driving_license_category'] ?? '');
    $driving_license_number = sanitize($_POST['driving_license_number'] ?? '');
    $gender = sanitize($_POST['gender']);
    $factory = sanitize($_POST['factory']);
    $civil_status = sanitize($_POST['civil_status']);

    // Store temporary file paths instead of moving files immediately
    $temp_files = [];
    $final_paths = [];

    // Validate and prepare CIN Front Image
    if (!empty($_FILES['cin_image_front']['name'])) {
        $validation = validateUploadedFile($_FILES['cin_image_front']);
        if ($validation['success']) {
            $temp_files['cin_image_front'] = $_FILES['cin_image_front']['tmp_name'];
            $final_paths['cin_image_front'] = 'uploads/cin/' . generateUniqueFileName($_FILES['cin_image_front']['name']);
        } else {
            $error = 'CIN Front Image: ' . $validation['error'];
        }
    }

    // Validate and prepare CIN Back Image
    if (!$error && !empty($_FILES['cin_image_back']['name'])) {
        $validation = validateUploadedFile($_FILES['cin_image_back']);
        if ($validation['success']) {
            $temp_files['cin_image_back'] = $_FILES['cin_image_back']['tmp_name'];
            $final_paths['cin_image_back'] = 'uploads/cin/' . generateUniqueFileName($_FILES['cin_image_back']['name']);
        } else {
            $error = 'CIN Back Image: ' . $validation['error'];
        }
    }

    // Validate and prepare Driving License Image
    if (!$error && $has_driving_license && !empty($_FILES['driving_license_image']['name'])) {
        $validation = validateUploadedFile($_FILES['driving_license_image']);
        if ($validation['success']) {
            $temp_files['driving_license_image'] = $_FILES['driving_license_image']['tmp_name'];
            $final_paths['driving_license_image'] = 'uploads/driving_license/' . generateUniqueFileName($_FILES['driving_license_image']['name']);
        } else {
            $error = 'Driving License Image: ' . $validation['error'];
        }
    }

    // Validate and prepare Profile Picture
    if (!$error && !empty($_FILES['profile_picture']['name'])) {
        $validation = validateUploadedFile($_FILES['profile_picture']);
        if ($validation['success']) {
            $temp_files['profile_picture'] = $_FILES['profile_picture']['tmp_name'];
            $final_paths['profile_picture'] = 'uploads/profiles/' . generateUniqueFileName($_FILES['profile_picture']['name']);
        } else {
            $error = 'Profile Picture: ' . $validation['error'];
        }
    }

    // Fixed children data processing to match database schema
    $children = [];
    if ($civil_status === 'married' && isset($_POST['children']) && is_array($_POST['children'])) {
        foreach ($_POST['children'] as $child) {
            // Check if any child field has data
            if (!empty(trim($child['child_first_name'])) || !empty(trim($child['child_second_name'])) || !empty($child['child_date_of_birth'])) {
                $children[] = [
                    'child_first_name' => sanitize(trim($child['child_first_name'])),
                    'child_second_name' => sanitize(trim($child['child_second_name'])),
                    'child_date_of_birth' => $child['child_date_of_birth']
                ];
            }
        }
    }

    // Validation
    if (empty($username) || empty($email) || empty($password) || empty($first_name) || empty($last_name) || empty($civil_status) || empty($gender)) {
        $error = 'Please fill in all required fields';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = 'Password must be at least 6 characters long and contain at least one letter and one number';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error = 'Username can only contain letters, numbers, and underscores';
    } elseif (!in_array($civil_status, ['single', 'married', 'divorced', 'widowed'])) {
        $error = 'Invalid civil status';
    } elseif (!in_array($gender, ['male', 'female'])) {
        $error = 'Invalid gender';
    } elseif (!in_array($factory, ['1', '2', '3', '4'])) {
        $error = 'Invalid factory selection';
    } elseif (!empty($cnss_first) && (strlen($cnss_first) !== 8 || !ctype_digit($cnss_first))) {
        $error = 'CNSS first part must be exactly 8 digits';
    } elseif (!empty($cnss_last) && (strlen($cnss_last) !== 2 || !ctype_digit($cnss_last))) {
        $error = 'CNSS last part must be exactly 2 digits';
    } elseif (strlen($address) > 32) {
        $error = 'Address must not exceed 32 characters';
    } elseif ($has_driving_license && empty($driving_license_number)) {
        $error = 'Driving license number is required when you have a driving license';
    }

    if (!$error && $civil_status === 'married') {
        $childErrors = validateChildren($children);
        if (!empty($childErrors)) {
            $child_error = implode('<br>', $childErrors);
        }
    }

    if (!$error && !$child_error) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = 'Username or email already exists';
            } else {
                $stmt = $pdo->prepare("SELECT id FROM employee_profiles WHERE ncin = ?");
                $stmt->execute([$ncin]);
                if ($stmt->fetch()) {
                    $error = 'NCIN already exists';
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employee_profiles");
                    $stmt->execute();
                    $count = $stmt->fetchColumn();
                    $employee_id = 'EMP' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);


                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'admin')");
                    $stmt->execute([$username, $email, $hashed_password]);
                    $user_id = $pdo->lastInsertId();

                    // Insert employee profile with file paths
                    $stmt = $pdo->prepare("
                       INSERT INTO employee_profiles (
                            user_id, first_name, last_name, employee_id, ncin, cin_image_front, cin_image_back,
                            cnss_first, cnss_last, department, position, phone, address, date_of_birth, education,
                            has_driving_license, driving_license_category, driving_license_number, driving_license_image, gender, factory,
                            civil_status, hire_date, salary, profile_picture, status, dismissal_reason, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,NOW(), 0, ?, 'active', '', NOW(), NOW())
                    ");

                    $stmt->execute([
                        $user_id,
                        $first_name,
                        $last_name,
                        $employee_id,
                        $ncin,
                        $final_paths['cin_image_front'] ?? null,
                        $final_paths['cin_image_back'] ?? null,
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
                        $final_paths['driving_license_image'] ?? null,
                        $gender,
                        $factory,
                        $civil_status,
                        $final_paths['profile_picture'] ?? null
                    ]);

                    $profile_id = $pdo->lastInsertId();

                    // Insert children
                    if (!empty($children)) {
                        $stmt = $pdo->prepare("INSERT INTO employee_children (employee_profile_id, child_first_name, child_second_name, child_date_of_birth, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                        foreach ($children as $child) {
                            $stmt->execute([
                                $profile_id,
                                $child['child_first_name'],
                                $child['child_second_name'],
                                $child['child_date_of_birth']
                            ]);
                        }
                    }

                    // NOW move the files to their final destinations
                    $file_move_success = true;
                    foreach ($temp_files as $field => $temp_path) {
                        $final_path = $final_paths[$field];

                        // Create directory if it doesn't exist
                        $dir = dirname($final_path);
                        if (!file_exists($dir)) {
                            mkdir($dir, 0755, true);
                        }

                        // Move file
                        if (!move_uploaded_file($temp_path, $final_path)) {
                            $file_move_success = false;
                            throw new Exception("Failed to move uploaded file: " . $field);
                        }
                    }

                    if ($file_move_success) {
                        $pdo->commit();
                        $success = 'Admin registration successful! You can now log in.';
                        $_POST = array(); // Clear form data
                    } else {
                        throw new Exception("File upload failed");
                    }
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();

            // Clean up any files that might have been moved
            foreach ($final_paths as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            
            $error = 'Registration failed: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Registration - Employee Management System</title>
    <link rel="stylesheet" href="emp_register.css">
</head>

<body>
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

</head>

<body>
    <div class="register-container">
        <div class="register-header">
            <h1>Admin Registration</h1>
            <p>Create your admin account</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($child_error): ?>
            <div class="alert alert-error"><?php echo $child_error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="info-note">
            <strong>Note:</strong> Fields marked with <span class="required">*</span> are required.
        </div>

        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="register" value="1">

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name <span class="required">*</span></label>
                    <input type="text" id="first_name" name="first_name"
                        value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>"
                        required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name <span class="required">*</span></label>
                    <input type="text" id="last_name" name="last_name"
                        value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>"
                        required>
                </div>
            </div>

            <div class="form-group">
                <label for="username">Username <span class="required">*</span></label>
                <input type="text" id="username" name="username"
                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                    required>
            </div>

            <div class="form-group">
                <label for="email">Email <span class="required">*</span></label>
                <input type="email" id="email" name="email"
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="ncin">NCIN <span class="required">*</span></label>
                    <input type="text" id="ncin" name="ncin"
                        value="<?php echo isset($_POST['ncin']) ? htmlspecialchars($_POST['ncin']) : ''; ?>" required>
                </div>

                <div class="form-group">
                    <label for="cnss_first">CNSS (First 8 Digits) </label>
                    <input type="text" id="cnss_first" name="cnss_first" maxlength="8" pattern="\d{8}"
                        value="<?php echo isset($_POST['cnss_first']) ? htmlspecialchars($_POST['cnss_first']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="cnss_last">CNSS (Last 2 Digits) </label>
                    <input type="text" id="cnss_last" name="cnss_last" maxlength="2" pattern="\d{2}"
                        value="<?php echo isset($_POST['cnss_last']) ? htmlspecialchars($_POST['cnss_last']) : ''; ?>">
                </div>
            </div>

            <!-- Image Upload Section -->
            <div class="image-upload-section">
                <h3>Document Images</h3>

                <div class="upload-row">
                    <div class="form-group">
                        <label class="file-upload-label" for="cin_image_front">CIN Front Image <span
                                class="required">*</span></label>
                        <input type="file" id="cin_image_front" name="cin_image_front" class="file-upload-input"
                            accept="image/*" onchange="previewImage(this, 'cin_front_preview')" required>
                        <div class="file-preview" id="cin_front_preview">
                            <img src="" alt="CIN Front Preview">
                            <div class="file-info" id="cin_front_info"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="file-upload-label" for="cin_image_back">CIN Back Image <span
                                class="required">*</span></label>
                        <input type="file" id="cin_image_back" name="cin_image_back" class="file-upload-input"
                            accept="image/*" onchange="previewImage(this, 'cin_back_preview')" required>
                        <div class="file-preview" id="cin_back_preview">
                            <img src="" alt="CIN Back Preview">
                            <div class="file-info" id="cin_back_info"></div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="file-upload-label" for="profile_picture">Profile Picture</label>
                    <input type="file" id="profile_picture" name="profile_picture" class="file-upload-input"
                        accept="image/*" onchange="previewImage(this, 'profile_preview')">
                    <div class="file-preview" id="profile_preview">
                        <img src="" alt="Profile Preview">
                        <div class="file-info" id="profile_info"></div>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="department">Department</label>
                    <select id="department" name="department" required>
                        <option value="">Select Department</option>
                        <option value="General Management">General Management</option>
                        <option value="Production Department">Production Department</option>
                        <option value="Quality Department">Quality Department</option>
                        <option value="Logistics Department">Logistics Department</option>
                        <option value="Human Resources Department">Human Resources Department</option>
                        <option value="Maintenance Department">Maintenance Department</option>
                        <option value="Information Technology Department">Information Technology Department</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="position">Position</label>
                    <input type="text" id="position" name="position"
                        value="<?php echo isset($_POST['position']) ? htmlspecialchars($_POST['position']) : ''; ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="phone">Phone Number <span class="required">*</span></label>
                    <input type="tel" id="phone" name="phone"
                        value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" required>
                </div>

                <div class="form-group">
                    <label for="date_of_birth">Date of Birth <span class="required">*</span></label>
                    <input type="date" id="date_of_birth" name="date_of_birth"
                        value="<?php echo isset($_POST['date_of_birth']) ? $_POST['date_of_birth'] : ''; ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label for="education">Education <span class="required">*</span></label>
                <input type="text" id="education" name="education"
                    value="<?php echo isset($_POST['education']) ? htmlspecialchars($_POST['education']) : ''; ?>"
                    required>
            </div>

            <div class="form-group">
                <label>Has Driving License</label>
                <div class="radio-group">
                    <label>
                        <input type="radio" name="has_driving_license" value="1"
                            onchange="toggleDrivingLicenseSection()" <?php echo (isset($_POST['has_driving_license']) && $_POST['has_driving_license'] == '1') ? 'checked' : ''; ?>>
                        Yes
                    </label>
                    <label>
                        <input type="radio" name="has_driving_license" value="0"
                            onchange="toggleDrivingLicenseSection()" <?php echo (!isset($_POST['has_driving_license']) || $_POST['has_driving_license'] == '0') ? 'checked' : ''; ?>>
                        No
                    </label>
                </div>
            </div>

            <!-- Driving License Section -->
            <div id="driving-license-section"
                class="driving-license-section <?php echo (isset($_POST['has_driving_license']) && $_POST['has_driving_license'] == '1') ? 'show' : ''; ?>">
                <div class="form-group">
                    <label for="driving_license_category">Driving License Category</label>
                    <select id="driving_license_category" name="driving_license_category">
                        <option value="">Select Category</option>
                        <option value="A" <?php echo (isset($_POST['driving_license_category']) && $_POST['driving_license_category'] === 'A') ? 'selected' : ''; ?>>A (Motorcycle)</option>
                        <option value="B" <?php echo (isset($_POST['driving_license_category']) && $_POST['driving_license_category'] === 'B') ? 'selected' : ''; ?>>B (Car)</option>
                        <option value="C" <?php echo (isset($_POST['driving_license_category']) && $_POST['driving_license_category'] === 'C') ? 'selected' : ''; ?>>C (Truck)</option>
                        <option value="D" <?php echo (isset($_POST['driving_license_category']) && $_POST['driving_license_category'] === 'D') ? 'selected' : ''; ?>>D (Bus)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="driving_license_number">Driving License Number</label>
                    <input type="text" id="driving_license_number" name="driving_license_number"
                        value="<?php echo isset($_POST['driving_license_number']) ? htmlspecialchars($_POST['driving_license_number']) : ''; ?>">
                </div>
                <div class="upload-row">
                    <div class="form-group">
                        <label class="file-upload-label" for="driving_license_image">Driving License Image</label>
                        <input type="file" id="driving_license_image" name="driving_license_image"
                            class="file-upload-input" accept="image/*" onchange="previewImage(this, 'license_preview')">
                        <div class="file-preview" id="license_preview">
                            <img id="license_img" src="" alt="License Preview">
                            <div class="file-info" id="license_info"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="gender">Gender <span class="required">*</span></label>
                <select id="gender" name="gender" required>
                    <option value="">Select Gender</option>
                    <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                    <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                </select>
            </div>

            <div class="form-group">
                <label for="factory">Factory <span class="required">*</span></label>
                <select id="factory" name="factory" required>
                    <option value="">Select Factory</option>
                    <option value="1" <?php echo (isset($_POST['factory']) && $_POST['factory'] === '1') ? 'selected' : ''; ?>>Factory 1</option>
                    <option value="2" <?php echo (isset($_POST['factory']) && $_POST['factory'] === '2') ? 'selected' : ''; ?>>Factory 2</option>
                    <option value="3" <?php echo (isset($_POST['factory']) && $_POST['factory'] === '3') ? 'selected' : ''; ?>>Factory 3</option>
                    <option value="4" <?php echo (isset($_POST['factory']) && $_POST['factory'] === '4') ? 'selected' : ''; ?>>Factory 4</option>
                </select>
            </div>

            <div class="form-group">
                <label for="address">Address <span class="required">*</span></label>
                <textarea id="address" name="address" maxlength="32" placeholder="Enter full address"
                    required><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
            </div>

            <div class="form-group">
                <label for="civil_status">Civil Status <span class="required">*</span></label>
                <select id="civil_status" name="civil_status" required onchange="toggleChildrenSection()">
                    <option value="">Select Status</option>
                    <option value="single" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] === 'single') ? 'selected' : ''; ?>>Single</option>
                    <option value="married" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] === 'married') ? 'selected' : ''; ?>>Married</option>
                    <option value="divorced" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] === 'divorced') ? 'selected' : ''; ?>>Divorced</option>
                    <option value="widowed" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] === 'widowed') ? 'selected' : ''; ?>>Widowed</option>
                </select>
            </div>

            <!-- Children Section -->
            <div id="children-section"
                class="children-section <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] === 'married') ? 'show' : ''; ?>">
                <h3>Children Information</h3>
                <div id="children-container" class="children-container">
                    <?php if (isset($_POST['children']) && is_array($_POST['children'])): ?>
                        <?php foreach ($_POST['children'] as $index => $child): ?>
                            <?php if (!empty(trim($child['child_first_name'])) || !empty(trim($child['child_second_name'])) || !empty($child['child_date_of_birth'])): ?>
                                <div class="child-row">
                                    <input type="text" name="children[<?php echo $index; ?>][child_first_name]"
                                        placeholder="Child's First Name"
                                        value="<?php echo htmlspecialchars($child['child_first_name']); ?>">
                                    <input type="text" name="children[<?php echo $index; ?>][child_second_name]"
                                        placeholder="Child's Second Name"
                                        value="<?php echo htmlspecialchars($child['child_second_name']); ?>">
                                    <input type="date" name="children[<?php echo $index; ?>][child_date_of_birth]"
                                        value="<?php echo htmlspecialchars($child['child_date_of_birth']); ?>">
                                    <button type="button" class="remove-child" onclick="removeChildRow(this)">×</button>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button type="button" class="add-child-btn" onclick="addChildRow()">+ Add Child</button>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="register" class="btn">Register</button>
            </div>

            <div class="login-link">
                <p>Already have an account? <a href="login.php">Sign in here</a></p>
            </div>
        </form>
    </div>

    <script>
        function previewImage(input, previewId) {
            const file = input.files[0];
            const preview = document.getElementById(previewId);
            const img = preview.querySelector('img');
            const info = preview.querySelector('.file-info');

            if (file) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    img.src = e.target.result;
                    info.textContent = `File: ${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                preview.style.display = 'none';
                img.src = '';
                info.textContent = '';
            }
        }

        function toggleDrivingLicenseSection() {
            const radios = document.getElementsByName('has_driving_license');
            let hasLicense = false;
            radios.forEach(radio => {
                if (radio.checked && radio.value === '1') {
                    hasLicense = true;
                }
            });
            const section = document.getElementById('driving-license-section');
            if (hasLicense) {
                section.classList.add('show');
            } else {
                section.classList.remove('show');
                document.getElementById('driving_license_category').value = '';
                document.getElementById('driving_license_image').value = '';
                document.getElementById('license_preview').style.display = 'none';
            }
        }

        function toggleChildrenSection() {
            const civilStatus = document.getElementById('civil_status').value;
            const childrenSection = document.getElementById('children-section');

            if (civilStatus === 'married') {
                childrenSection.classList.add('show');
            } else {
                childrenSection.classList.remove('show');
                // Clear children data when not married
                const childrenContainer = document.getElementById('children-container');
                childrenContainer.innerHTML = '';
            }
        }

        function addChildRow() {
            const container = document.getElementById('children-container');
            const childrenCount = container.children.length;

            const row = document.createElement('div');
            row.className = 'child-row';
            row.innerHTML = `
                <input type="text" name="children[${childrenCount}][child_first_name]" placeholder="Child's First Name" required>
                <input type="text" name="children[${childrenCount}][child_second_name]" placeholder="Child's Second Name" required>
                <input type="date" name="children[${childrenCount}][child_date_of_birth]" required>
                <button type="button" class="remove-child" onclick="removeChildRow(this)">×</button>
            `;
            container.appendChild(row);
        }

        function removeChildRow(button) {
            const row = button.parentElement;
            row.remove();

            // Re-index remaining children
            const container = document.getElementById('children-container');
            const childRows = container.querySelectorAll('.child-row');

            childRows.forEach((row, index) => {
                const firstNameInput = row.querySelector('input[name*="child_first_name"]');
                const secondNameInput = row.querySelector('input[name*="child_second_name"]');
                const dobInput = row.querySelector('input[name*="child_date_of_birth"]');

                firstNameInput.name = `children[${index}][child_first_name]`;
                secondNameInput.name = `children[${index}][child_second_name]`;
                dobInput.name = `children[${index}][child_date_of_birth]`;
            });
        }

        // Form validation
        function validateForm() {
            const hasDrivingLicense = document.getElementById('has_driving_license').checked;
            const drivingLicenseImage = document.getElementById('driving_license_image');
            const drivingLicenseCategory = document.getElementById('driving_license_category');

            if (hasDrivingLicense) {
                if (!drivingLicenseCategory.value) {
                    alert('Please select a driving license category.');
                    return false;
                }
            }

            return true;
        }

        // File size validation
        function validateFileSize(input, maxSizeMB = 5) {
            const file = input.files[0];
            if (file && file.size > maxSizeMB * 1024 * 1024) {
                alert(`File size must be less than ${maxSizeMB}MB`);
                input.value = '';
                return false;
            }
            return true;
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function () {
            toggleChildrenSection();
            toggleDrivingLicenseSection();

            // Add file size validation to all file inputs
            const fileInputs = document.querySelectorAll('input[type="file"]');
            fileInputs.forEach(input => {
                input.addEventListener('change', function () {
                    validateFileSize(this);
                });
            });

            // Add form validation on submit
            const form = document.querySelector('form');
            form.addEventListener('submit', function (e) {
                if (!validateForm()) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>

</html>