<?php
require_once 'config.php';

$error = '';
$success = '';

// Handle login form submission
if ($_POST && isset($_POST['login']) && verifyCSRFToken($_POST['csrf_token'])) {
    $username = sanitize($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Invalid username or password';
            }
        } catch (Exception $e) {
            $error = 'Login failed: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management System - Home</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #ffffffff 0%, #a70202 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .navbar {
            background: none;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .navbar-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        .navbar-brand {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-left: auto;
        }

        .nav-link {
            color: #ffff;
            text-decoration: none;
            font-size: 20px;
            transition: color 0.3s;
        }

        .logo {
            height: 50px;
            margin-right: 15px;
        }

        .description {
            display: flex;
            align-items: flex-start;
            gap: 30px;
            margin: 50px 30px 80px 80px;
            padding: 70px;
            border-radius: 5px;
            color: black;
            font-size: 1.3em;
        }

        .description-text {
            flex: 1;
        }

        .description-image {
            flex-shrink: 0;
        }

        .description-image img {
            height: 300px;
            width: auto;
            border-radius: 10px;
        }

        .alert {
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
            <div class="navbar-nav">
                <a href="jobs.php" class="nav-link">Jobs</a>
                <a href="login.php" class="nav-link">Login</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="description">
            <div class="description-text">
                <h1>Welcome to the Employees Management System</h1>
                <p>An employees system securely stores and organizes employees personal details.
                    It streamlines HR processes by enabling efficient data access,
                    updates, and compliance with data protection regulations.</p>
            </div>
            <div class="description-image">
                <img src="ems.png" alt="Employee Management System">
            </div>
        </div>
    </div>
</body>

</html>