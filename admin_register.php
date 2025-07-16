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
            if (empty(trim($child['first_name']))) {
                $errors[] = "Child #{$childNum}: First name is required";
            }
            if (empty(trim($child['second_name']))) {
                $errors[] = "Child #{$childNum}: Second name is required";
            }
            if (empty($child['dob'])) {
                $errors[] = "Child #{$childNum}: Date of birth is required";
            } else {
                $childAge = calculateAge($child['dob']);
                if ($childAge === null || $childAge < 0 || $childAge > 25) {
                    $errors[] = "Child #{$childNum}: Invalid date of birth";
                }
            }
            if (!empty(trim($child['first_name'])) && !preg_match('/^[a-zA-Z\s]+$/', trim($child['first_name']))) {
                $errors[] = "Child #{$childNum}: First name can only contain letters and spaces";
            }
            if (!empty(trim($child['second_name'])) && !preg_match('/^[a-zA-Z\s]+$/', trim($child['second_name']))) {
                $errors[] = "Child #{$childNum}: Second name can only contain letters and spaces";
            }
        }
    }
    return $errors;
}

if ($_POST && isset($_POST['register']) && verifyCSRFToken($_POST['csrf_token'])) {
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
    $has_driving_license = isset($_POST['has_driving_license']) ? 1 : 0;
    $driving_licence_category = sanitize($_POST['driving_licence_category'] ?? '');
    $gender = sanitize($_POST['gender']);
    $factory = sanitize($_POST['factory']);
    $civil_status = sanitize($_POST['civil_status']);
    $salary = floatval($_POST['salary']);

    // Handle file uploads
    $cin_image_front = null;
    $cin_image_back = null;
    $driving_licence_image = null;
    $profile_picture = null;

    // CIN Front Image Upload
    if (!empty($_FILES['cin_image_front']['name'])) {
        $upload = handleFileUpload($_FILES['cin_image_front'], 'uploads/cin/');
        if ($upload['success']) {
            $cin_image_front = $upload['path'];
        } else {
            $error = 'CIN Front Image: ' . $upload['error'];
        }
    }

    // CIN Back Image Upload
    if (!empty($_FILES['cin_image_back']['name'])) {
        $upload = handleFileUpload($_FILES['cin_image_back'], 'uploads/cin/');
        if ($upload['success']) {
            $cin_image_back = $upload['path'];
        } else {
            $error = 'CIN Back Image: ' . $upload['error'];
        }
    }

    // Driving License Image Upload
    if ($has_driving_license && !empty($_FILES['driving_licence_image']['name'])) {
        $upload = handleFileUpload($_FILES['driving_licence_image'], 'uploads/driving_license/');
        if ($upload['success']) {
            $driving_licence_image = $upload['path'];
        } else {
            $error = 'Driving License Image: ' . $upload['error'];
        }
    }

    // Profile Picture Upload
    if (!empty($_FILES['profile_picture']['name'])) {
        $upload = handleFileUpload($_FILES['profile_picture'], 'uploads/profiles/');
        if ($upload['success']) {
            $profile_picture = $upload['path'];
        } else {
            $error = 'Profile Picture: ' . $upload['error'];
        }
    }

    $children = [];
    if ($civil_status === 'married' && isset($_POST['children']) && is_array($_POST['children'])) {
        foreach ($_POST['children'] as $child) {
            if (!empty(trim($child['first_name'])) || !empty(trim($child['second_name'])) || !empty($child['dob'])) {
                $children[] = [
                    'first_name' => sanitize(trim($child['first_name'])),
                    'second_name' => sanitize(trim($child['second_name'])),
                    'dob' => $child['dob']
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

                    $stmt = $pdo->prepare("
                        INSERT INTO employee_profiles (
                            user_id, first_name, last_name, employee_id, ncin, cin_image_front, cin_image_back,
                            cnss_first, cnss_last, department, position, phone, address, date_of_birth, education,
                            has_driving_license, driving_licence_category, driving_licence_image, gender, factory,
                            civil_status, hire_date, salary, profile_picture, status, dismissal_reason, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 'active', '', NOW(), NOW())
                    ");

                    $stmt->execute([
                        $user_id,
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
                        $salary,
                        $profile_picture
                    ]);
                    $profile_id = $pdo->lastInsertId();

                    // Insert children if any
                    if (!empty($children)) {
                        $stmt = $pdo->prepare("INSERT INTO employee_children (employee_profile_id, child_first_name, child_second_name, child_date_of_birth) VALUES (?, ?, ?, ?)");
                        foreach ($children as $child) {
                            $stmt->execute([$profile_id, $child['first_name'], $child['second_name'], $child['dob']]);
                        }
                    }

                    $pdo->commit();
                    $success = 'Admin registration successful! You can now log in.';
                    $_POST = array();
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
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
                            <div class="file-info" id="cin_front_info"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="file-upload-label" for="cin_image_back">CIN Back Image <span
                                class="required">*</span></label>
                        <input type="file" id="cin_image_back" name="cin_image_back" class="file-upload-input"
                            accept="image/*" onchange="previewImage(this, 'cin_back_preview')" required>
                        <div class="file-preview" id="cin_back_preview">
                            <div class="file-info" id="cin_back_info"></div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="file-upload-label" for="profile_picture">Profile Picture</label>
                    <input type="file" id="profile_picture" name="profile_picture" class="file-upload-input"
                        accept="image/*" onchange="previewImage(this, 'profile_preview')">
                    <div class="file-preview" id="profile_preview">
                        <div class="file-info" id="profile_info"></div>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="department">Department</label>
                    <select id="department" name="department">
                        <option value="">Select Department</option>
                        <option value="Production" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Production') ? 'selected' : ''; ?>>Production</option>
                        <option value="HR" <?php echo (isset($_POST['department']) && $_POST['department'] === 'HR') ? 'selected' : ''; ?>>HR</option>
                        <option value="Maintenance" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="Finance" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                        <option value="IT" <?php echo (isset($_POST['department']) && $_POST['department'] === 'IT') ? 'selected' : ''; ?>>IT</option>
                        <option value="Marketing" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Marketing') ? 'selected' : ''; ?>>Marketing</option>
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
            <div class="upload-row">
                <div class="form-group">
                    <label for="has_driving_license">Has Driving License</label>
                    <input type="checkbox" id="has_driving_license" name="has_driving_license"
                        onchange="toggleDrivingLicenseSection()" <?php echo (isset($_POST['has_driving_license']) && $_POST['has_driving_license']) ? 'checked' : ''; ?>>
                </div>
            </div>
            <!-- Driving License Section -->
            <div id="driving-license-section"
                class="driving-license-section <?php echo (isset($_POST['has_driving_license']) && $_POST['has_driving_license']) ? 'show' : ''; ?>">
                <div class="form-group">
                    <label for="driving_licence_category">Driving License Category</label>
                    <select id="driving_licence_category" name="driving_licence_category">
                        <option value="">Select Category</option>
                        <option value="A" <?php echo (isset($_POST['driving_licence_category']) && $_POST['driving_licence_category'] === 'A') ? 'selected' : ''; ?>>A (Motorcycle)</option>
                        <option value="B" <?php echo (isset($_POST['driving_licence_category']) && $_POST['driving_licence_category'] === 'B') ? 'selected' : ''; ?>>B (Car)</option>
                        <option value="C" <?php echo (isset($_POST['driving_licence_category']) && $_POST['driving_licence_category'] === 'C') ? 'selected' : ''; ?>>C (Truck)</option>
                        <option value="D" <?php echo (isset($_POST['driving_licence_category']) && $_POST['driving_licence_category'] === 'D') ? 'selected' : ''; ?>>D (Bus)</option>
                    </select>
                </div>
                <div class="upload-row">
                    <div class="form-group">
                        <label class="file-upload-label" for="driving_licence_image">Driving License Image</label>
                        <input type="file" id="driving_licence_image" name="driving_licence_image"
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
                    <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                    <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                </select>
            </div>

            <div class="form-group">
                <label for="factory">Factory <span class="required">*</span></label>
                <select id="factory" name="factory" required>
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
                <label for="salary">Salary</label>
                <input type="number" id="salary" name="salary" step="0.01" min="0"
                    value="<?php echo isset($_POST['salary']) ? htmlspecialchars($_POST['salary']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="civil_status">Civil Status <span class="required">*</span></label>
                <select id="civil_status" name="civil_status" required onchange="toggleChildrenSection()">
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
                            <?php if (!empty(trim($child['first_name'])) || !empty(trim($child['second_name'])) || !empty($child['dob'])): ?>
                                <div class="child-row">
                                    <input type="text" name="children[<?php echo $index; ?>][first_name]"
                                        placeholder="Child's First Name"
                                        value="<?php echo htmlspecialchars($child['first_name']); ?>">
                                    <input type="text" name="children[<?php echo $index; ?>][second_name]"
                                        placeholder="Child's Second Name"
                                        value="<?php echo htmlspecialchars($child['second_name']); ?>">
                                    <input type="date" name="children[<?php echo $index; ?>][dob]"
                                        value="<?php echo htmlspecialchars($child['dob']); ?>">
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
            const checkbox = document.getElementById('has_driving_license');
            const section = document.getElementById('driving-license-section');

            if (checkbox.checked) {
                section.classList.add('show');
            } else {
                section.classList.remove('show');
                // Clear driving license data when unchecked
                document.getElementById('driving_licence_category').value = '';
                document.getElementById('driving_licence_image').value = '';
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
                <input type="text" name="children[${childrenCount}][first_name]" placeholder="Child's First Name" required>
                <input type="text" name="children[${childrenCount}][second_name]" placeholder="Child's Second Name" required>
                <input type="date" name="children[${childrenCount}][dob]" required>
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
                const firstNameInput = row.querySelector('input[name*="first_name"]');
                const secondNameInput = row.querySelector('input[name*="second_name"]');
                const dobInput = row.querySelector('input[type="date"]');

                firstNameInput.name = `children[${index}][first_name]`;
                secondNameInput.name = `children[${index}][second_name]`;
                dobInput.name = `children[${index}][dob]`;
            });
        }

        // Form validation
        function validateForm() {
            const hasDrivingLicense = document.getElementById('has_driving_license').checked;
            const drivingLicenseImage = document.getElementById('driving_licence_image');
            const drivingLicenseCategory = document.getElementById('driving_licence_category');

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