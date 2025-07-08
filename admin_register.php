<?php
require_once 'config.php';


$error = '';
$success = '';

// Function to calculate age from date of birth
function calculateAge($dob) {
    if (!$dob) return null;
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birthDate)->y;
}

// Handle admin registration form submission
if ($_POST) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request';
    } else {
        $username = sanitize($_POST['username']);
        $email = sanitize($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $first_name = sanitize($_POST['first_name']);
        $last_name = sanitize($_POST['last_name']);
        $ncin = sanitize($_POST['ncin']);
        $ncss_first = sanitize($_POST['ncss_first']);
        $ncss_last = sanitize($_POST['ncss_last']);
        $department = sanitize($_POST['department']);
        $position = sanitize($_POST['position']);
        $phone = sanitize($_POST['phone']);
        $address = sanitize(substr($_POST['address'], 0, 32)); // Limit to 32 characters
        $date_of_birth = $_POST['date_of_birth'] ? $_POST['date_of_birth'] : null;
        $age = $date_of_birth ? calculateAge($date_of_birth) : null;
        $education = sanitize($_POST['education']);
        $has_driving_license = isset($_POST['has_driving_license']) ? 1 : 0;
        $gender = sanitize($_POST['gender']);
        $factory = sanitize($_POST['factory']);
        $civil_status = sanitize($_POST['civil_status']);

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
        } elseif (strlen($ncss_first) !== 8 || !ctype_digit($ncss_first)) {
            $error = 'NCSS first part must be exactly 8 digits';
        } elseif (strlen($ncss_last) !== 2 || !ctype_digit($ncss_last)) {
            $error = 'NCSS last part must be exactly 2 digits';
        } elseif (!in_array($civil_status, ['single', 'married', 'divorced', 'widowed'])) {
            $error = 'Invalid civil status';
        } elseif (!in_array($gender, ['male', 'female'])) {
            $error = 'Invalid gender';
        } elseif (strlen($address) > 32) {
            $error = 'Address must not exceed 32 characters';
        } else {
            try {
                // Check if username, email, or ncin already exists
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
                        // Hash password
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                        // Generate employee ID
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employee_profiles");
                        $stmt->execute();
                        $count = $stmt->fetchColumn();
                        $employee_id = 'EMP' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);

                        // Begin transaction
                        $pdo->beginTransaction();

                        // Insert user with role 'admin'
                        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'admin')");
                        $stmt->execute([$username, $email, $hashed_password]);
                        $user_id = $pdo->lastInsertId();

                        // Insert employee profile
                        $stmt = $pdo->prepare("
                            INSERT INTO employee_profiles (
                                user_id, first_name, last_name, employee_id, ncin, ncss_first, ncss_last,
                                department, position, phone, address, date_of_birth, age, education,
                                has_driving_license, gender, factory, civil_status, hire_date
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $user_id, $first_name, $last_name, $employee_id, $ncin, $ncss_first, $ncss_last,
                            $department, $position, $phone, $address, $date_of_birth, $age, $education,
                            $has_driving_license, $gender, $factory, $civil_status
                        ]);

                        // Commit transaction
                        $pdo->commit();

                        $success = 'Admin registration successful! The new admin can now log in.';
                        header('Location: dashboard.php');
                        exit;
                    }
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Registration failed: ' . $e->getMessage();
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
    <title>Admin Registration - Employee Management System</title>
    <link rel="stylesheet" href="admin_register.css">
</head>

<body>
    <div class="container">
        <div class="welcome-section">
            <h1>Admin Registration</h1>
        </div>

        <div class="card">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="info-note">
                <strong>Note:</strong> Fields marked with <span class="required">*</span> are required.
            </div>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

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
                        <label for="ncin">CIN<span class="required">*</span></label>
                        <input type="text" id="ncin" name="ncin"
                            value="<?php echo isset($_POST['ncin']) ? htmlspecialchars($_POST['ncin']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="ncss_first">NCSS (First 8 Digits)</label>
                        <input type="text" id="ncss_first" name="ncss_first" maxlength="8" pattern="\d{8}"
                            value="<?php echo isset($_POST['ncss_first']) ? htmlspecialchars($_POST['ncss_first']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="ncss_last">NCSS (Last 2 Digits)</label>
                        <input type="text" id="ncss_last" name="ncss_last" maxlength="2" pattern="\d{2}"
                            value="<?php echo isset($_POST['ncss_last']) ? htmlspecialchars($_POST['ncss_last']) : ''; ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="department">Department</label>
                        <select id="department" name="department">
                            <option value="">Select Department </option>
                            <option value="IT" <?php echo (isset($_POST['department']) && $_POST['department'] === 'IT') ? 'selected' : ''; ?>>IT</option>
                            <option value="HR" <?php echo (isset($_POST['department']) && $_POST['department'] === 'HR') ? 'selected' : ''; ?>>HR</option>
                            <option value="Finance" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                            <option value="Marketing" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Marketing') ? 'selected' : ''; ?>>Marketing</option>
                            <option value="Operations" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Operations') ? 'selected' : ''; ?>>Operations</option>
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
                            value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth <span class="required">*</span></label>
                        <input type="date" id="date_of_birth" name="date_of_birth"
                            value="<?php echo isset($_POST['date_of_birth']) ? $_POST['date_of_birth'] : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="address">Address <span class="required">*</span></label>
                    <textarea id="address" name="address" maxlength="32"
                        placeholder="Enter full address"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                </div>
                <div class="form-group">
                    <label for="education">Education <span class="required">*</span></label>
                    <input type="text" id="education" name="education"
                        value="<?php echo isset($_POST['education']) ? htmlspecialchars($_POST['education']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="has_driving_license">Has Driving License</label>
                    <input type="checkbox" id="has_driving_license" name="has_driving_license"
                        <?php echo (isset($_POST['has_driving_license']) && $_POST['has_driving_license']) ? 'checked' : ''; ?>>
                </div>

                <div class="form-group">
                    <label for="gender">Gender <span class="required">*</span></label>
                    <select id="gender" name="gender" required>
                        <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="factory">Factory</label>
                    <input type="text" id="factory" name="factory"
                        value="<?php echo isset($_POST['factory']) ? htmlspecialchars($_POST['factory']) : ''; ?>">
                    </div>

                <div class="form-group">
                    <label for="civil_status">Civil Status <span class="required">*</span></label>
                    <select id="civil_status" name="civil_status" required>
                        <option value="single" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] === 'single') ? 'selected' : ''; ?>>Single</option>
                        <option value="married" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] === 'married') ? 'selected' : ''; ?>>Married</option>
                        <option value="divorced" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] === 'divorced') ? 'selected' : ''; ?>>Divorced</option>
                        <option value="widowed" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] === 'widowed') ? 'selected' : ''; ?>>Widowed</option>
                    </select>
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
                    <button type="submit" class="btn" >Register Admin</button>
                </div>
            </form>
        </div>
    </div>
</body>

</html>