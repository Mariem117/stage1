<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : 'Admin Panel'; ?> - Employee Management System</title>
    <link rel="stylesheet" href="responsive.css">
    <?php if (isset($additional_css)): ?>
        <?php foreach ($additional_css as $css): ?>
            <link rel="stylesheet" href="<?php echo $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if (isset($include_chartjs) && $include_chartjs): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>
    <style>
        /* Admin-specific styles only */
        .admin-badge {
            background: #a70202;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #a70202 0%, rgb(0, 0, 0) 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        /* Simple dropdown for Dashboard */
        .nav-dropdown {
            position: relative;
            display: inline-block;
        }
        .nav-dropdown .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: #ffffff;
            min-width: 180px;
            border-radius: 8px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            padding: 8px 0;
            z-index: 1000;
        }
        .nav-dropdown:hover .dropdown-menu {
            display: block;
        }
        .dropdown-item {
            display: block;
            padding: 10px 14px;
            color: #333;
            text-decoration: none;
            white-space: nowrap;
        }
        .dropdown-item:hover {
            background: #f3f4f6;
        }
        .nav-dropdown > .nav-link:after {
            content: ' ▾';
            font-size: 0.8em;
        }
        /* Keep active styling visible for the parent when a child is active */
        .nav-dropdown.active > .nav-link {
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
                <div class="nav-dropdown <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' || basename($_SERVER['PHP_SELF']) == 'employees_listing.php') ? 'active' : ''; ?>">
                    <a href="dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">Dashboard</a>
                    <div class="dropdown-menu">
                        <a href="dashboard.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">Statistics</a>
                        <a href="employees_listing.php" class="dropdown-item <?php echo basename($_SERVER['PHP_SELF']) == 'employees_listing.php' ? 'active' : ''; ?>">Employees</a>
                    </div>
                </div>
                <a href="admin_jobs.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_jobs.php' ? 'active' : ''; ?>">Jobs</a>
                <a href="admin_applications.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_applications.php' ? 'active' : ''; ?>">Applications</a>
                <a href="admin_request.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_request.php' ? 'active' : ''; ?>">Requests</a>
                <a href="profile.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">My Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>
