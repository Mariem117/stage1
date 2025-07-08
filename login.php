<?php
require_once 'config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';

// Handle login form submission
if ($_POST && isset($_POST['login'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request';
    } else {
        $identifier = sanitize($_POST['identifier']); // Can be username, email, or NCIN
        $password = $_POST['password'];

        // Validation
        if (empty($identifier) || empty($password)) {
            $error = 'Please fill in all required fields';
        } else {
            try {
                // Check if identifier matches username, email, or NCIN
                $stmt = $pdo->prepare("
                    SELECT u.id, u.username, u.email, u.password, u.role
                    FROM users u
                    LEFT JOIN employee_profiles ep ON u.id = ep.user_id
                    WHERE u.username = ? OR u.email = ? OR ep.ncin = ?
                ");
                $stmt->execute([$identifier, $identifier, $identifier]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    // Start session and store user data
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];

                    $success = 'Login successful! Redirecting...';
                    header('Refresh: 2; url=dashboard.php');
                } else {
                    $error = 'Invalid username, email, NCIN, or password';
                }
            } catch (Exception $e) {
                $error = 'Login failed: ' . $e->getMessage();
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
    <title>Login - Employee Management System</title>
    <link rel="stylesheet" href="login.css">
</head>

<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Employee Login</h1>
            <p>Sign in to your account</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="login" value="1">

            <div class="form-group">
                <label for="identifier">Email, or NCIN <span class="required">*</span></label>
                <input type="text" id="identifier" name="identifier"
                    value="<?php echo isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier']) : ''; ?>"
                    required>
            </div>

            <div class="form-group">
                <label for="password">Password <span class="required">*</span></label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" name="login" class="btn">Login</button>
        </form>

        <div class="register-link">
            <p>Don't have an account? <a href="emp_register.php">Register here</a></p>
        </div>
    </div>
</body>

</html>