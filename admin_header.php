<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : 'Admin Panel'; ?> - Employee Management System</title>
    <link rel="stylesheet" href="dashboard_responsive.css">
    <link rel="stylesheet" href="dashboard.css">
    <?php if (isset($additional_css)): ?>
        <?php foreach ($additional_css as $css): ?>
            <link rel="stylesheet" href="<?php echo $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if (isset($include_chartjs) && $include_chartjs): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>
    <style>
        .logo {
            height: 50px;
            margin-right: 15px;
        }

        img {
            overflow-clip-margin: content-box;
            overflow: clip;
        }

        .admin-badge {
            background: #a70202;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .navbar {
            background: white;
            padding-top: 15px;
            padding-bottom: 15px;
            padding-left: 0;
            padding-right: 0;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .navbar-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .nav-link {
            color: #333;
            text-decoration: none;
            font-size: 1rem;
            transition: color 0.3s, transform 0.2s;
        }

        .nav-link:hover,
        .nav-link:focus {
            color: #a70202;
            transform: translateY(-2px);
            outline: none;
        }

        .nav-link.active {
            color: #a70202;
            font-weight: bold;
        }
    </style>
    <?php if (isset($additional_styles)): ?>
        <style><?php echo $additional_styles; ?></style>
    <?php endif; ?>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
            <div class="navbar-nav">
                <span class="admin-badge">ADMIN</span>
                <a href="dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">Dashboard</a>
                <a href="employees_listing.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'employees_listing.php' ? 'active' : ''; ?>">Employees</a>
                <a href="admin_jobs.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_jobs.php' ? 'active' : ''; ?>">Jobs</a>
                <a href="admin_applications.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_applications.php' ? 'active' : ''; ?>">Applications</a>
                <a href="admin_request.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_request.php' ? 'active' : ''; ?>">Requests</a>
                <a href="profile.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">My Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>
