<?php
require_once 'config.php';
requireLogin();
requireAdmin();

$error = '';
$success = '';

// Handle URL parameters for success/error messages
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}

// Get filter parameters
$job_filter = $_GET['job_id'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Build WHERE clause for filtering
$where_conditions = ['1=1'];
$params = [];

if ($job_filter !== 'all') {
    $where_conditions[] = 'ja.job_posting_id = ?';
    $params[] = $job_filter;
}

if ($status_filter !== 'all') {
    $where_conditions[] = 'ja.status = ?';
    $params[] = $status_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = '(ja.applicant_name LIKE ? OR ja.applicant_email LIKE ? OR jp.title LIKE ?)';
    $search_param = '%' . $search_query . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count for pagination
$count_stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM job_applications ja
    JOIN job_postings jp ON ja.job_posting_id = jp.id
    WHERE $where_clause
");
$count_stmt->execute($params);
$total_applications = $count_stmt->fetch()['total'];
$total_pages = ceil($total_applications / $per_page);

// Fetch applications with job details
$stmt = $pdo->prepare("
    SELECT ja.*, jp.title as job_title, jp.department, jp.location,
           u.username as reviewed_by_name
    FROM job_applications ja
    JOIN job_postings jp ON ja.job_posting_id = jp.id
    LEFT JOIN users u ON ja.reviewed_by = u.id
    WHERE $where_clause
    ORDER BY ja.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$applications = $stmt->fetchAll();

// Get job postings for filter dropdown
$jobs_stmt = $pdo->prepare("
    SELECT jp.id, jp.title, COUNT(ja.id) as app_count
    FROM job_postings jp
    LEFT JOIN job_applications ja ON jp.id = ja.job_posting_id
    GROUP BY jp.id, jp.title
    ORDER BY jp.created_at DESC
");
$jobs_stmt->execute();
$job_options = $jobs_stmt->fetchAll();

// Get application statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_applications,
        COUNT(CASE WHEN ja.status = 'submitted' THEN 1 END) as new_applications,
        COUNT(CASE WHEN ja.status = 'under_review' THEN 1 END) as under_review,
        COUNT(CASE WHEN ja.status = 'shortlisted' THEN 1 END) as shortlisted,
        COUNT(CASE WHEN ja.status = 'interviewed' THEN 1 END) as interviewed,
        COUNT(CASE WHEN ja.status = 'hired' THEN 1 END) as hired,
        COUNT(CASE WHEN ja.status = 'rejected' THEN 1 END) as rejected,
        COUNT(CASE WHEN ja.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_applications
    FROM job_applications ja
    JOIN job_postings jp ON ja.job_posting_id = jp.id
");
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

// Handle status update
if ($_POST && isset($_POST['update_application_status']) && verifyCSRFToken($_POST['csrf_token'])) {
    $application_id = (int)$_POST['application_id'];
    $new_status = sanitize($_POST['new_status']);
    $admin_notes = sanitize($_POST['admin_notes']);
    
    $valid_statuses = ['submitted', 'under_review', 'shortlisted', 'interviewed', 'rejected', 'hired'];
    
    if (!in_array($new_status, $valid_statuses)) {
        header('Location: admin_applications.php?error=Invalid status selected');
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get current application details
        $current_stmt = $pdo->prepare("
            SELECT ja.*, jp.title as job_title
            FROM job_applications ja
            JOIN job_postings jp ON ja.job_posting_id = jp.id
            WHERE ja.id = ?
        ");
        $current_stmt->execute([$application_id]);
        $current_app = $current_stmt->fetch();
        
        if (!$current_app) {
            throw new Exception('Application not found');
        }
        
        // Update application
        $update_stmt = $pdo->prepare("
            UPDATE job_applications 
            SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$new_status, $admin_notes, $_SESSION['user_id'], $application_id]);
        
        // Record status change in history
        if ($current_app['status'] !== $new_status) {
            $history_stmt = $pdo->prepare("
                INSERT INTO job_application_status_history (application_id, old_status, new_status, changed_by, notes, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $history_stmt->execute([
                $application_id,
                $current_app['status'],
                $new_status,
                $_SESSION['user_id'],
                $admin_notes
            ]);
        }
        
        $pdo->commit();
        
        $redirect_params = array_merge($_GET, ['success' => 'Application status updated successfully']);
        header('Location: admin_applications.php?' . http_build_query($redirect_params));
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $redirect_params = array_merge($_GET, ['error' => 'Failed to update application: ' . $e->getMessage()]);
        header('Location: admin_applications.php?' . http_build_query($redirect_params));
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Applications Management - Admin Panel</title>
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
        }

        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .application-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }

        .application-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .application-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .applicant-name {
            font-size: 1.3em;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }

        .job-title {
            color: #007bff;
            font-weight: 500;
            margin-bottom: 10px;
        }

        .application-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
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

        .status-submitted { background: #fff3cd; color: #856404; }
        .status-under_review { background: #cce7ff; color: #0c5460; }
        .status-shortlisted { background: #e3f2fd; color: #1976d2; }
        .status-interviewed { background: #f3e5f5; color: #7b1fa2; }
        .status-hired { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }

        .application-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
            transition: all 0.2s ease;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-secondary { background: #6c757d; color: white; }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .update-form {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
            display: none;
        }

        .update-form.active {
            display: block;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }

        .page-link {
            padding: 8px 12px;
            border: 1px solid #ddd;
            color: #007bff;
            text-decoration: none;
            border-radius: 4px;
        }

        .page-link.active {
            background: #007bff;
            color: white;
        }

        .cover-letter-preview {
            background: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #007bff;
            margin-top: 10px;
            border-radius: 0 5px 5px 0;
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
                <a href="admin_jobs.php" class="nav-link">Jobs</a>
                <a href="admin_applications.php" class="nav-link active">Applications</a>
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
                <div class="stat-number"><?php echo $stats['total_applications']; ?></div>
                <div class="stat-label">Total Applications</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['new_applications']; ?></div>
                <div class="stat-label">New/Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['under_review']; ?></div>
                <div class="stat-label">Under Review</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['shortlisted']; ?></div>
                <div class="stat-label">Shortlisted</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['hired']; ?></div>
                <div class="stat-label">Hired</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['recent_applications']; ?></div>
                <div class="stat-label">This Week</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <form method="GET" action="">
                <div class="filters-grid">
                    <div class="form-group">
                        <label>Job Position</label>
                        <select name="job_id" onchange="this.form.submit()">
                            <option value="all" <?php echo $job_filter === 'all' ? 'selected' : ''; ?>>All Positions</option>
                            <?php foreach ($job_options as $job_option): ?>
                                <option value="<?php echo $job_option['id']; ?>" 
                                        <?php echo $job_filter == $job_option['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($job_option['title']); ?> (<?php echo $job_option['app_count']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" onchange="this.form.submit()">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                            <option value="under_review" <?php echo $status_filter === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                            <option value="shortlisted" <?php echo $status_filter === 'shortlisted' ? 'selected' : ''; ?>>Shortlisted</option>
                            <option value="interviewed" <?php echo $status_filter === 'interviewed' ? 'selected' : ''; ?>>Interviewed</option>
                            <option value="hired" <?php echo $status_filter === 'hired' ? 'selected' : ''; ?>>Hired</option>
                            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" 
                               placeholder="Name, email, job title...">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">Search</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Applications List -->
        <div class="applications-section">
            <h2>Job Applications (<?php echo $total_applications; ?> total)</h2>
            
            <?php if (empty($applications)): ?>
                <div class="application-card">
                    <p>No applications found matching your criteria.</p>
                </div>
            <?php else: ?>
                <?php foreach ($applications as $application): ?>
                    <div class="application-card">
                        <div class="application-header">
                            <div>
                                <div class="applicant-name"><?php echo htmlspecialchars($application['applicant_name']); ?></div>
                                <div class="job-title">Applied for: <?php echo htmlspecialchars($application['job_title']); ?></div>
                                <div style="font-size: 14px; color: #666;">
                                    Application #<?php echo htmlspecialchars($application['application_number']); ?>
                                </div>
                            </div>
                            <span class="status-badge status-<?php echo $application['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $application['status'])); ?>
                            </span>
                        </div>

                        <div class="application-meta">
                            <span>📧 <?php echo htmlspecialchars($application['applicant_email']); ?></span>
                            <?php if ($application['applicant_phone']): ?>
                                <span>📞 <?php echo htmlspecialchars($application['applicant_phone']); ?></span>
                            <?php endif; ?>
                            <?php if ($application['years_experience']): ?>
                                <span>💼 <?php echo $application['years_experience']; ?> years experience</span>
                            <?php endif; ?>
                            <?php if ($application['expected_salary']): ?>
                                <span>💰 Expected: <?php echo number_format($application['expected_salary']); ?></span>
                            <?php endif; ?>
                            <span>📅 Applied <?php echo date('M j, Y H:i', strtotime($application['created_at'])); ?></span>
                            <?php if ($application['availability_date']): ?>
                                <span>🗓️ Available from <?php echo date('M j, Y', strtotime($application['availability_date'])); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if ($application['reviewed_by_name']): ?>
                            <div style="font-size: 14px; color: #666; margin-bottom: 10px;">
                                Reviewed by: <?php echo htmlspecialchars($application['reviewed_by_name']); ?>
                                <?php if ($application['reviewed_at']): ?>
                                    on <?php echo date('M j, Y H:i', strtotime($application['reviewed_at'])); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="cover-letter-preview">
                            <strong>Cover Letter:</strong><br>
                            <?php 
                            $cover_letter = htmlspecialchars($application['cover_letter']);
                            echo strlen($cover_letter) > 200 ? substr($cover_letter, 0, 200) . '...' : $cover_letter; 
                            ?>
                        </div>

                        <?php if ($application['admin_notes']): ?>
                            <div style="background: #e8f5e8; padding: 10px; border-radius: 5px; margin-top: 10px;">
                                <strong>Admin Notes:</strong><br>
                                <?php echo nl2br(htmlspecialchars($application['admin_notes'])); ?>
                            </div>
                        <?php endif; ?>

                        <div class="application-actions">
                            <?php if ($application['resume_path']): ?>
                                <a href="<?php echo htmlspecialchars($application['resume_path']); ?>" 
                                   target="_blank" class="btn btn-secondary btn-small">📄 View Resume</a>
                            <?php endif; ?>
                            
                            <button type="button" class="btn btn-primary btn-small" 
                                    onclick="toggleUpdateForm(<?php echo $application['id']; ?>)">
                                ✏️ Update Status
                            </button>
                            
                        </div>

                        <!-- Status Update Form -->
                        <div id="update-form-<?php echo $application['id']; ?>" class="update-form">
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="application_id" value="<?php echo $application['id']; ?>">
                                
                                <div class="form-group">
                                    <label>New Status</label>
                                    <select name="new_status" required>
                                        <option value="">Select Status...</option>
                                        <option value="submitted" <?php echo $application['status'] === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                        <option value="under_review" <?php echo $application['status'] === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                        <option value="shortlisted" <?php echo $application['status'] === 'shortlisted' ? 'selected' : ''; ?>>Shortlisted</option>
                                        <option value="interviewed" <?php echo $application['status'] === 'interviewed' ? 'selected' : ''; ?>>Interviewed</option>
                                        <option value="hired" <?php echo $application['status'] === 'hired' ? 'selected' : ''; ?>>Hired</option>
                                        <option value="rejected" <?php echo $application['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label>Admin Notes</label>
                                    <textarea name="admin_notes" rows="3" 
                                              placeholder="Add notes about this application..."><?php echo htmlspecialchars($application['admin_notes']); ?></textarea>
                                </div>
                                
                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" name="update_application_status" class="btn btn-success btn-small">
                                        💾 Update Application
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-small" 
                                            onclick="toggleUpdateForm(<?php echo $application['id']; ?>)">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">&laquo; Previous</a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleUpdateForm(applicationId) {
            const form = document.getElementById('update-form-' + applicationId);
            form.classList.toggle('active');
        }

        // Auto-refresh every 2 minutes to show new applications
        setTimeout(function() {
            location.reload();
        }, 120000);

        // Confirmation for critical status changes
        document.addEventListener('submit', function(e) {
            if (e.target.querySelector('select[name="new_status"]')) {
                const status = e.target.querySelector('select[name="new_status"]').value;
                if (status === 'rejected' || status === 'hired') {
                    if (!confirm(`Are you sure you want to mark this application as "${status}"? This is a final decision.`)) {
                        e.preventDefault();
                    }
                }
            }
        });
    </script>
</body>
</html>