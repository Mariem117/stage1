<?php
require_once 'config.php';
requireLogin();
requireAdmin();

$error = '';
$success = '';

// Handle success/error messages from URL
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}

// Handle job status updates
if ($_POST && isset($_POST['update_status']) && verifyCSRFToken($_POST['csrf_token'])) {
    $job_id = (int) $_POST['job_id'];
    $new_status = sanitize($_POST['status']);

    if (in_array($new_status, ['draft', 'active', 'closed', 'filled'])) {
        try {
            $stmt = $pdo->prepare("UPDATE job_postings SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $job_id]);

            header('Location: admin_jobs.php?success=Job status updated successfully');
            exit();
        } catch (Exception $e) {
            $error = 'Failed to update job status: ' . $e->getMessage();
        }
    } else {
        $error = 'Invalid status selected';
    }
}

// Handle job deletion
if ($_POST && isset($_POST['delete_job']) && verifyCSRFToken($_POST['csrf_token'])) {
    $job_id = (int) $_POST['job_id'];

    try {
        // Check if job has applications
        $check_stmt = $pdo->prepare("SELECT COUNT(*) as app_count FROM job_applications WHERE job_posting_id = ?");
        $check_stmt->execute([$job_id]);
        $app_count = $check_stmt->fetch()['app_count'];

        if ($app_count > 0) {
            header('Location: admin_jobs.php?error=Cannot delete job posting with existing applications');
            exit();
        }

        $stmt = $pdo->prepare("DELETE FROM job_postings WHERE id = ?");
        $stmt->execute([$job_id]);

        header('Location: admin_jobs.php?success=Job posting deleted successfully');
        exit();
    } catch (Exception $e) {
        $error = 'Failed to delete job posting: ' . $e->getMessage();
    }
}

// Fetch all job postings with application counts
$stmt = $pdo->prepare("
    SELECT jp.*, 
           COUNT(ja.id) as application_count,
           COUNT(CASE WHEN ja.status = 'submitted' THEN 1 END) as new_applications
    FROM job_postings jp
    LEFT JOIN job_applications ja ON jp.id = ja.job_posting_id
    GROUP BY jp.id
    ORDER BY jp.created_at DESC
");
$stmt->execute();
$job_postings = $stmt->fetchAll();

// Get job posting statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_jobs,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_jobs,
        COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_jobs,
        COUNT(CASE WHEN status = 'closed' THEN 1 END) as closed_jobs,
        COUNT(CASE WHEN status = 'filled' THEN 1 END) as filled_jobs
    FROM job_postings
");
$stats_stmt->execute();
$stats = $stats_stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Management - Admin Panel</title>
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: #666;
        }

        .job-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .job-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .job-title {
            font-size: 1.4em;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }

        .job-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 14px;
            color: #666;
            margin-bottom: 15px;
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-draft {
            background: #fff3cd;
            color: #856404;
        }

        .status-closed {
            background: #f8d7da;
            color: #721c24;
        }

        .status-filled {
            background: #cce7ff;
            color: #0c5460;
        }

        .job-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .applications-count {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }

        .quick-actions {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .alert {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
            <div class="navbar-nav">
                <span class="admin-badge">ADMIN</span>
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="employees_listing.php" class="nav-link">Employees</a>
                <a href="admin_request.php" class="nav-link">Requests</a>
                <a href="admin_jobs.php" class="nav-link active">Jobs</a>
                <a href="admin_applications.php" class="nav-link">Applications</a>
                <a href="profile.php" class="nav-link">Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_jobs']; ?></div>
                <div class="stat-label">Total Jobs</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_jobs']; ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['draft_jobs']; ?></div>
                <div class="stat-label">Drafts</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['filled_jobs']; ?></div>
                <div class="stat-label">Filled</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <h2>Job Postings Management</h2>
            <div>
                <a href="create_job.php" class="btn btn-primary">➕ Create New Job</a>
                <a href="admin_applications.php" class="btn btn-success">📋 View All Applications</a>
            </div>
        </div>

        <!-- Job Postings List -->
        <div class="jobs-list">
            <?php if (empty($job_postings)): ?>
                <div class="job-card">
                    <p>No job postings found. <a href="create_job.php">Create your first job posting</a>.</p>
                </div>
            <?php else: ?>
                <?php foreach ($job_postings as $job): ?>
                    <div class="job-card">
                        <div class="job-header">
                            <div>
                                <h3 class="job-title"><?php echo htmlspecialchars($job['title']); ?></h3>
                                <div class="job-meta">
                                    <span>🏢 <?php echo htmlspecialchars($job['department']); ?></span>
                                    <span>📍 <?php echo htmlspecialchars($job['location']); ?></span>
                                    <span>💼 <?php echo ucfirst(str_replace('_', ' ', $job['employment_type'])); ?></span>
                                    <span>📅 Created <?php echo date('M j, Y', strtotime($job['created_at'])); ?></span>
                                    <?php if ($job['application_deadline']): ?>
                                        <span>⏰ Deadline
                                            <?php echo date('M j, Y', strtotime($job['application_deadline'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <span class="status-badge status-<?php echo $job['status']; ?>">
                                    <?php echo ucfirst($job['status']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="job-description">
                            <?php
                            $description = htmlspecialchars($job['description']);
                            echo strlen($description) > 200 ? substr($description, 0, 200) . '...' : $description;
                            ?>
                        </div>

                        <?php if ($job['application_count'] > 0): ?>
                            <div class="applications-count">
                                <strong>📋 Applications: <?php echo $job['application_count']; ?></strong>
                                <?php if ($job['new_applications'] > 0): ?>
                                    <span style="color: #dc3545; font-weight: bold;">
                                        (<?php echo $job['new_applications']; ?> new)
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="job-actions">
                            <a href="admin_applications.php?job_id=<?php echo $job['id']; ?>" class="btn btn-success">
                                📋 View Applications (<?php echo $job['application_count']; ?>)
                            </a>
                            <a href="job_detail.php?id=<?php echo $job['id']; ?>" class="btn btn-secondary" target="_blank">👁️
                                Preview</a>

                            <!-- Status Update Form -->
                            <form method="POST" style="display: inline-block;">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                <select name="status"
                                    onchange="if(confirm('Change status to ' + this.value + '?')) this.form.submit();">
                                    <option value="">Change Status...</option>
                                    <option value="draft" <?php echo $job['status'] === 'draft' ? 'disabled' : ''; ?>>Draft
                                    </option>
                                    <option value="active" <?php echo $job['status'] === 'active' ? 'disabled' : ''; ?>>Active
                                    </option>
                                    <option value="closed" <?php echo $job['status'] === 'closed' ? 'disabled' : ''; ?>>Closed
                                    </option>
                                    <option value="filled" <?php echo $job['status'] === 'filled' ? 'disabled' : ''; ?>>Filled
                                    </option>
                                </select>
                                <input type="hidden" name="update_status" value="1">
                            </form>

                            <!-- Delete Button -->
                            <?php if ($job['application_count'] == 0): ?>
                                <form method="POST" style="display: inline-block;"
                                    onsubmit="return confirm('Are you sure you want to delete this job posting? This action cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                    <button type="submit" name="delete_job" class="btn btn-danger">🗑️ Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-refresh page every 5 minutes to show new applications
        setTimeout(function () {
            location.reload();
        }, 300000);

        // Confirmation for status changes
        document.addEventListener('change', function (e) {
            if (e.target.name === 'status' && e.target.value) {
                const jobTitle = e.target.closest('.job-card').querySelector('.job-title').textContent;
                if (!confirm(`Change status of "${jobTitle}" to "${e.target.value}"?`)) {
                    e.target.selectedIndex = 0;
                }
            }
        });
    </script>
</body>

</html>