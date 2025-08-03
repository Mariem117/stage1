<?php
require_once 'config.php';

// Get filter parameters
$department_filter = $_GET['department'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build WHERE clause for filtering
$where_conditions = ["jp.status = 'active'"];
$params = [];

if ($department_filter !== 'all') {
    $where_conditions[] = 'jp.department = ?';
    $params[] = $department_filter;
}

if ($type_filter !== 'all') {
    $where_conditions[] = 'jp.employment_type = ?';
    $params[] = $type_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = '(jp.title LIKE ? OR jp.description LIKE ? OR jp.location LIKE ?)';
    $search_param = '%' . $search_query . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch active job postings
$stmt = $pdo->prepare("
    SELECT jp.*, u.username as posted_by_name
    FROM job_postings jp
    JOIN users u ON jp.posted_by = u.id
    WHERE $where_clause
    ORDER BY jp.created_at DESC
");
$stmt->execute($params);
$job_postings = $stmt->fetchAll();

// Get unique departments for filter
$dept_stmt = $pdo->prepare("SELECT DISTINCT department FROM job_postings WHERE status = 'active' ORDER BY department");
$dept_stmt->execute();
$departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Careers - Join Our Team</title>
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .hero-section {
            background: white;
            padding: 40px;
            border-radius: 15px;
            text-align: center;
            margin-bottom: 30px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .hero-title {
            font-size: 2.5em;
            color: #333;
            margin-bottom: 15px;
        }

        .hero-subtitle {
            font-size: 1.2em;
            color: #666;
            margin-bottom: 30px;
        }

        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .job-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }

        .job-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .job-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }

        .job-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .job-title {
            font-size: 1.5em;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }

        .job-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
            font-size: 14px;
            color: #666;
        }

        .job-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .job-description {
            color: #555;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .job-type-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 15px;
        }

        .type-full_time {
            background: #e3f2fd;
            color: #1976d2;
        }

        .type-part_time {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .type-contract {
            background: #fff3e0;
            color: #f57c00;
        }

        .type-internship {
            background: #e8f5e8;
            color: #388e3c;
        }

        .apply-btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 25px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s ease;
            display: inline-block;
        }

        .apply-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }

        .form-group select,
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        .btn {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .no-jobs {
            background: white;
            padding: 40px;
            border-radius: 15px;
            text-align: center;
            color: #666;
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
            <div class="navbar-nav">
                <a href="index.php" class="nav-link">Home</a>
                <a href="jobs.php" class="nav-link">Careers</a>
                <a href="login.php" class="nav-link">Employee Login</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Hero Section -->
        <div class="hero-section">
            <h1 class="hero-title">Join Our Amazing Team</h1>
            <p class="hero-subtitle">Discover exciting career opportunities and grow with us</p>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <form method="GET" action="">
                <div class="filters-grid">
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department" onchange="this.form.submit()">
                            <option value="all" <?php echo $department_filter === 'all' ? 'selected' : ''; ?>>All
                                Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department_filter === $dept ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($dept)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Employment Type</label>
                        <select name="type" onchange="this.form.submit()">
                            <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="full_time" <?php echo $type_filter === 'full_time' ? 'selected' : ''; ?>>Full
                                Time</option>
                            <option value="part_time" <?php echo $type_filter === 'part_time' ? 'selected' : ''; ?>>Part
                                Time</option>
                            <option value="contract" <?php echo $type_filter === 'contract' ? 'selected' : ''; ?>>Contract
                            </option>
                            <option value="internship" <?php echo $type_filter === 'internship' ? 'selected' : ''; ?>>
                                Internship</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Search Jobs</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>"
                            placeholder="Job title, keywords...">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn">Search Jobs</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Job Listings -->
        <?php if (empty($job_postings)): ?>
            <div class="no-jobs">
                <h3>No job openings found</h3>
                <p>We don't have any positions matching your criteria at the moment. Please check back later!</p>
            </div>
        <?php else: ?>
            <div class="job-grid">
                <?php foreach ($job_postings as $job): ?>
                    <div class="job-card">
                        <div class="job-type-badge type-<?php echo $job['employment_type']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $job['employment_type'])); ?>
                        </div>

                        <h3 class="job-title"><?php echo htmlspecialchars($job['title']); ?></h3>

                        <div class="job-meta">
                            <span>🏢 <?php echo htmlspecialchars($job['department']); ?></span>
                            <span>📍 <?php echo htmlspecialchars($job['location']); ?></span>
                            <?php if ($job['salary_min'] && $job['salary_max']): ?>
                                <span>💰 <?php echo number_format($job['salary_min']); ?> -
                                    <?php echo number_format($job['salary_max']); ?>             <?php echo $job['currency']; ?></span>
                            <?php endif; ?>
                            <span>📅 Posted <?php echo date('M j, Y', strtotime($job['created_at'])); ?></span>
                        </div>

                        <div class="job-description">
                            <?php
                            $description = htmlspecialchars($job['description']);
                            echo strlen($description) > 150 ? substr($description, 0, 150) . '...' : $description;
                            ?>
                        </div>

                        <?php if ($job['application_deadline']): ?>
                            <p style="color: #dc3545; font-weight: bold; font-size: 14px;">
                                Application Deadline: <?php echo date('F j, Y', strtotime($job['application_deadline'])); ?>
                            </p>
                        <?php endif; ?>

                        <a href="job_detail.php?id=<?php echo $job['id']; ?>" class="apply-btn">
                            View Details & Apply
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>