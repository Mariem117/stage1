<?php
require_once 'config.php';
requireLogin();
requireAdmin();

$error = '';
$success = '';
$filter_status = $_GET['status'] ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build WHERE clause for filtering
$where_conditions = ['1=1'];
$params = [];

if ($filter_status !== 'all') {
    $where_conditions[] = 'er.status = ?';
    $params[] = $filter_status;
}

if ($filter_priority !== 'all') {
    $where_conditions[] = 'er.priority = ?';
    $params[] = $filter_priority;
}

if (!empty($search_query)) {
    $where_conditions[] = '(er.subject LIKE ? OR er.message LIKE ? OR ep.first_name LIKE ? OR ep.last_name LIKE ? OR u.email LIKE ?)';
    $search_param = '%' . $search_query . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
}

$where_clause = implode(' AND ', $where_conditions);

// Validate sort parameters
$valid_sort_columns = ['created_at', 'priority', 'status', 'subject', 'first_name', 'last_name'];
$sort_by = in_array($sort_by, $valid_sort_columns) ? $sort_by : 'created_at';
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// Get total count for pagination
$count_stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM employee_requests er
    JOIN employee_profiles ep ON er.employee_id = ep.id
    JOIN users u ON ep.user_id = u.id
    WHERE $where_clause
");
$count_stmt->execute($params);
$total_requests = $count_stmt->fetch()['total'];
$total_pages = ceil($total_requests / $per_page);

// Ensure integer values for pagination
$per_page = (int) 10;
$offset = (int) (($page - 1) * $per_page);

// Validate sort parameters
$valid_sort_columns = ['created_at', 'priority', 'status', 'subject', 'first_name', 'last_name'];
$sort_by = in_array($sort_by, $valid_sort_columns) ? $sort_by : 'created_at';
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// Determine the ORDER BY column/expression
$order_by_clause = '';
switch ($sort_by) {
    case 'priority':
        $order_by_clause = "priority_order $sort_order";
        break;
    case 'created_at':
        $order_by_clause = "er.created_at $sort_order";
        break;
    case 'subject':
        $order_by_clause = "er.subject $sort_order";
        break;
    case 'status':
        $order_by_clause = "er.status $sort_order";
        break;
    case 'first_name':
        $order_by_clause = "ep.first_name $sort_order";
        break;
    case 'last_name':
        $order_by_clause = "ep.last_name $sort_order";
        break;
    default:
        $order_by_clause = "er.created_at $sort_order";
}

// Fetch filtered and sorted requests
$stmt = $pdo->prepare("
    SELECT er.*, ep.first_name, ep.last_name, u.email,
           CASE 
               WHEN er.priority = 'urgent' THEN 1
               WHEN er.priority = 'high' THEN 2
               WHEN er.priority = 'normal' THEN 3
               WHEN er.priority = 'low' THEN 4
               ELSE 5
           END as priority_order
    FROM employee_requests er
    JOIN employee_profiles ep ON er.employee_id = ep.id
    JOIN users u ON ep.user_id = u.id
    WHERE $where_clause
    ORDER BY $order_by_clause
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params); // Only pass WHERE clause parameters
$requests = $stmt->fetchAll();

// Fetch admin notifications with better filtering
$stmt = $pdo->prepare("
    SELECT n.*, COUNT(n.id) as notification_count
    FROM notifications n
    WHERE n.user_id = ? AND n.type IN ('new_request', 'request_updated', 'urgent_request')
    GROUP BY n.type, n.is_read
    ORDER BY n.created_at DESC
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

// Get request statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_requests,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_requests,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_requests,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_requests,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_requests,
        COUNT(CASE WHEN priority = 'urgent' THEN 1 END) as urgent_requests,
        COUNT(CASE WHEN priority = 'high' THEN 1 END) as high_priority_requests,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_requests
    FROM employee_requests
");
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

// Enhanced request response handling
if ($_POST && isset($_POST['respond_request']) && verifyCSRFToken($_POST['csrf_token'])) {
    $request_id = (int) $_POST['request_id'];
    $response = sanitize($_POST['admin_response']);
    $status = sanitize($_POST['status']);
    $assign_to = !empty($_POST['assign_to']) ? (int) $_POST['assign_to'] : null;
    $priority = sanitize($_POST['priority']);
    $is_internal_note = isset($_POST['is_internal_note']) ? 1 : 0;
    $follow_up_date = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;

    // Enhanced validation
    $validation_errors = [];

    if (empty($response)) {
        $validation_errors[] = 'Response message is required';
    } elseif (strlen($response) < 10) {
        $validation_errors[] = 'Response must be at least 10 characters long';
    } elseif (strlen($response) > 2000) {
        $validation_errors[] = 'Response cannot exceed 2000 characters';
    }

    if (!in_array($status, ['pending', 'in_progress', 'completed', 'rejected', 'on_hold'])) {
        $validation_errors[] = 'Invalid status selected';
    }

    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'])) {
        $validation_errors[] = 'Invalid priority selected';
    }

    // Validate assignment
    if ($assign_to) {
        $assign_stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('admin', 'manager')");
        $assign_stmt->execute([$assign_to]);
        if (!$assign_stmt->fetch()) {
            $validation_errors[] = 'Invalid user selected for assignment';
        }
    }

    // Validate follow-up date
    if ($follow_up_date) {
        $follow_up_timestamp = strtotime($follow_up_date);
        if (!$follow_up_timestamp || $follow_up_timestamp <= time()) {
            $validation_errors[] = 'Follow-up date must be in the future';
        }
    }

    // Check if request exists and get current data
    $current_request_stmt = $pdo->prepare("
        SELECT er.*, ep.user_id as employee_user_id 
        FROM employee_requests er 
        JOIN employee_profiles ep ON er.employee_id = ep.id 
        WHERE er.id = ?
    ");
    $current_request_stmt->execute([$request_id]);
    $current_request = $current_request_stmt->fetch();

    if (!$current_request) {
        $validation_errors[] = 'Request not found';
    }

    if (empty($validation_errors)) {
        try {
            $pdo->beginTransaction();

            // Update request
            $update_stmt = $pdo->prepare("
                UPDATE employee_requests
                SET status = ?, admin_response = ?, admin_id = ?, priority = ?, 
                    assigned_to = ?, follow_up_date = ?, responded_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $update_result = $update_stmt->execute([
                $status,
                $response,
                $_SESSION['user_id'],
                $priority,
                $assign_to,
                $follow_up_date,
                $request_id
            ]);

            if (!$update_result) {
                throw new Exception('Failed to update request');
            }

            // Record status change in history if status changed
            if ($current_request['status'] !== $status) {
                $status_history_stmt = $pdo->prepare("
                    INSERT INTO request_status_history (request_id, old_status, new_status, changed_by, notes)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $status_history_stmt->execute([
                    $request_id,
                    $current_request['status'],
                    $status,
                    $_SESSION['user_id'],
                    "Status changed via admin response. Priority: $priority"
                ]);
            }

            // Record assignment change if assigned
            if ($assign_to && $current_request['assigned_to'] != $assign_to) {
                $assignment_stmt = $pdo->prepare("
                    INSERT INTO request_assignments (request_id, assigned_from, assigned_to, assigned_by, notes)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $assignment_stmt->execute([
                    $request_id,
                    $current_request['assigned_to'],
                    $assign_to,
                    $_SESSION['user_id'],
                    "Assigned via admin response"
                ]);
            }

            // Add response as comment
            $comment_stmt = $pdo->prepare("
                INSERT INTO request_comments (request_id, user_id, comment, is_internal)
                VALUES (?, ?, ?, ?)
            ");
            $comment_stmt->execute([$request_id, $_SESSION['user_id'], $response, $is_internal_note]);

            // Create notification for employee (unless it's an internal note)
            if (!$is_internal_note) {
                $notification_title = "Request Update - " . ucfirst($status);
                $notification_message = "Your request has been updated with status: " . ucfirst($status);

                if ($assign_to) {
                    $assigned_user_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                    $assigned_user_stmt->execute([$assign_to]);
                    $assigned_user = $assigned_user_stmt->fetch();
                    if ($assigned_user) {
                        $notification_message .= " and assigned to " . $assigned_user['username'];
                    }
                }

                $employee_notification_stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, related_id)
                    VALUES (?, 'request_responded', ?, ?, ?)
                ");
                $employee_notification_stmt->execute([
                    $current_request['employee_user_id'],
                    $notification_title,
                    $notification_message,
                    $request_id
                ]);
            }

            // Create notification for assigned user if different from current admin
            if ($assign_to && $assign_to != $_SESSION['user_id']) {
                $assign_notification_stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, related_id)
                    VALUES (?, 'request_assigned', ?, ?, ?)
                ");
                $assign_notification_stmt->execute([
                    $assign_to,
                    "New Request Assignment",
                    "You have been assigned to handle a " . $priority . " priority request",
                    $request_id
                ]);
            }

            // Create follow-up reminder if date is set
            if ($follow_up_date) {
                $reminder_stmt = $pdo->prepare("
                    INSERT INTO request_reminders (request_id, admin_id, reminder_date, message)
                    VALUES (?, ?, ?, ?)
                ");
                $reminder_stmt->execute([
                    $request_id,
                    $_SESSION['user_id'],
                    $follow_up_date,
                    "Follow up on request response"
                ]);
            }

            $pdo->commit();
            $success = 'Response submitted successfully! Request updated with ' . ucfirst($status) . ' status.';

            // Refresh requests data
            $stmt = $pdo->prepare("
    SELECT er.*, ep.first_name, ep.last_name, u.email,
           CASE 
               WHEN er.priority = 'urgent' THEN 1
               WHEN er.priority = 'high' THEN 2
               WHEN er.priority = 'normal' THEN 3
               WHEN er.priority = 'low' THEN 4
               ELSE 5
           END as priority_order
    FROM employee_requests er
    JOIN employee_profiles ep ON er.employee_id = ep.id
    JOIN users u ON ep.user_id = u.id
    WHERE $where_clause
    ORDER BY $order_by_clause
    LIMIT $per_page OFFSET $offset
");
            $stmt->execute($params);
            $requests = $stmt->fetchAll();

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to submit response: ' . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $validation_errors);
    }
}

// Handle bulk actions
if ($_POST && isset($_POST['bulk_action']) && verifyCSRFToken($_POST['csrf_token'])) {
    $bulk_action = $_POST['bulk_action'];
    $selected_requests = $_POST['selected_requests'] ?? [];

    if (empty($selected_requests)) {
        $error = 'No requests selected for bulk action';
    } elseif (!in_array($bulk_action, ['mark_pending', 'mark_in_progress', 'mark_completed', 'assign_to_me'])) {
        $error = 'Invalid bulk action';
    } else {
        try {
            $pdo->beginTransaction();
            $bulk_success_count = 0;

            foreach ($selected_requests as $req_id) {
                $req_id = (int) $req_id;

                switch ($bulk_action) {
                    case 'mark_pending':
                        $bulk_stmt = $pdo->prepare("UPDATE employee_requests SET status = 'pending', updated_at = NOW() WHERE id = ?");
                        $bulk_stmt->execute([$req_id]);
                        break;
                    case 'mark_in_progress':
                        $bulk_stmt = $pdo->prepare("UPDATE employee_requests SET status = 'in_progress', updated_at = NOW() WHERE id = ?");
                        $bulk_stmt->execute([$req_id]);
                        break;
                    case 'mark_completed':
                        $bulk_stmt = $pdo->prepare("UPDATE employee_requests SET status = 'completed', updated_at = NOW() WHERE id = ?");
                        $bulk_stmt->execute([$req_id]);
                        break;
                    case 'assign_to_me':
                        $bulk_stmt = $pdo->prepare("UPDATE employee_requests SET assigned_to = ?, updated_at = NOW() WHERE id = ?");
                        $bulk_stmt->execute([$_SESSION['user_id'], $req_id]);
                        break;
                }
                $bulk_success_count++;
            }

            $pdo->commit();
            $success = "Bulk action completed successfully! Updated $bulk_success_count request(s).";

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Bulk action failed: ' . $e->getMessage();
        }
    }
}

// Get available users for assignment
$users_stmt = $pdo->prepare("
    SELECT u.id, u.username, u.email 
    FROM users u ,employee_profiles ep
    WHERE ep.user_id = u.id
    AND u.role IN ('admin', 'employee') AND ep.status = 'active'
    ORDER BY u.username
");
$users_stmt->execute();
$available_users = $users_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Admin Request Management - Employee Management System</title>
    <link rel="stylesheet" href="dashboard.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
            text-align: center;
        }

        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .filters-section {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }

        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            align-items: end;
        }

        .priority-urgent {
            color: #dc3545;
            font-weight: bold;
        }

        .priority-high {
            color: #fd7e14;
            font-weight: bold;
        }

        .priority-normal {
            color: #28a745;
        }

        .priority-low {
            color: #6c757d;
        }

        .response-form {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .bulk-actions {
            background: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
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

        .sort-header {
            cursor: pointer;
            user-select: none;
        }

        .sort-header:hover {
            background: #f8f9fa;
        }

        .advanced-response-form {
            display: none;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #ddd;
        }

        .toggle-advanced {
            color: #007bff;
            cursor: pointer;
            text-decoration: underline;
            font-size: 12px;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-in_progress {
            background: #cce7ff;
            color: #0c5460;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .status-on_hold {
            background: #e2e3e5;
            color: #495057;
        }

        .request-link {
            color: #007bff;
            text-decoration: none;
            font-weight: 500;
        }

        .request-link:hover {
            text-decoration: underline;
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

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }

        .btn:hover {
            background: #0056b3;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .alert {
            padding: 12px;
            border-radius: 4px;
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

        .employee-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .employee-table th,
        .employee-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .employee-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }

        .employee-table tbody tr:hover {
            background: #f8f9fa;
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
                <a href="profile.php" class="nav-link">My Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Statistics Dashboard -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_requests']; ?></div>
                <div class="stat-label">Total Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['pending_requests']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['in_progress_requests']; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['urgent_requests']; ?></div>
                <div class="stat-label">Urgent Priority</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['recent_requests']; ?></div>
                <div class="stat-label">Last 7 Days</div>
            </div>
        </div>

        <!-- Advanced Filters -->
        <div class="filters-section">
            <form method="GET" action="">
                <div class="filters-row">
                    <div class="form-group">
                        <label>Status Filter</label>
                        <select name="status" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses
                            </option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending
                            </option>
                            <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>
                                In Progress</option>
                            <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>
                                Completed</option>
                            <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>
                                Rejected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority Filter</label>
                        <select name="priority" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter_priority === 'all' ? 'selected' : ''; ?>>All Priorities
                            </option>
                            <option value="urgent" <?php echo $filter_priority === 'urgent' ? 'selected' : ''; ?>>Urgent
                            </option>
                            <option value="high" <?php echo $filter_priority === 'high' ? 'selected' : ''; ?>>High
                            </option>
                            <option value="normal" <?php echo $filter_priority === 'normal' ? 'selected' : ''; ?>>Normal
                            </option>
                            <option value="low" <?php echo $filter_priority === 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>"
                            placeholder="Search requests...">
                    </div>
                    <div class="form-group">
                        <label>Sort By</label>
                        <select name="sort" onchange="this.form.submit()">
                            <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Date
                                Created</option>
                            <option value="priority" <?php echo $sort_by === 'priority' ? 'selected' : ''; ?>>Priority
                            </option>
                            <option value="status" <?php echo $sort_by === 'status' ? 'selected' : ''; ?>>Status</option>
                            <option value="subject" <?php echo $sort_by === 'subject' ? 'selected' : ''; ?>>Subject
                            </option>
                        </select>
                    </div>
                    <button type="submit" class="btn">Apply Filters</button>
                </div>
            </form>
        </div>

        <!-- Bulk Actions -->
        <?php if (!empty($requests)): ?>
            <div class="bulk-actions">
                <form method="POST" action="" id="bulkForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <label>
                        <input type="checkbox" id="selectAll"> Select All
                    </label>
                    <select name="bulk_action" required>
                        <option value="">Choose Action...</option>
                        <option value="mark_pending">Mark as Pending</option>
                        <option value="mark_in_progress">Mark as In Progress</option>
                        <option value="mark_completed">Mark as Completed</option>
                        <option value="assign_to_me">Assign to Me</option>
                    </select>
                    <button type="submit" class="btn btn-small" onclick="return confirmBulkAction()">Apply</button>
                    <span id="selectedCount" style="font-size: 12px; color: #666;">0 selected</span>
                </form>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>All Requests (<?php echo $total_requests; ?> total)</h2>
            <?php if (empty($requests)): ?>
                <p>No requests found matching your criteria.</p>
            <?php else: ?>
                <?php foreach ($requests as $request): ?>
                    <div class="request-card <?php echo htmlspecialchars($request['priority']); ?>">
                        <div class="request-header">
                            <div>
                                <input type="checkbox" name="selected_requests[]" value="<?php echo $request['id']; ?>"
                                    form="bulkForm" class="request-checkbox">
                                <span class="request-title"><?php echo htmlspecialchars($request['subject']); ?></span>
                            </div>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <span
                                    class="priority-badge priority-<?php echo $request['priority']; ?>"><?php echo ucfirst($request['priority']); ?></span>
                                <span
                                    class="status-badge status-<?php echo $request['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?></span>
                            </div>
                        </div>

                        <div class="request-meta">
                            <span><strong>From:</strong>
                                <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></span>
                            <span><strong>Email:</strong> <?php echo htmlspecialchars($request['email']); ?></span>
                            <span><strong>Created:</strong>
                                <?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?></span>
                            <?php if ($request['responded_at']): ?>
                                <span><strong>Last Response:</strong>
                                    <?php echo date('M j, Y g:i A', strtotime($request['responded_at'])); ?></span>
                            <?php endif; ?>
                            <?php if ($request['assigned_to']): ?>
                                <?php
                                $assigned_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                                $assigned_stmt->execute([$request['assigned_to']]);
                                $assigned_user = $assigned_stmt->fetch();
                                ?>
                                <span><strong>Assigned to:</strong>
                                    <?php echo htmlspecialchars($assigned_user['username']); ?></span>
                            <?php endif; ?>
                            <?php if ($request['follow_up_date']): ?>
                                <span><strong>Follow-up:</strong>
                                    <?php echo date('M j, Y', strtotime($request['follow_up_date'])); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="request-content">
                            <strong>Request Details:</strong><br>
                            <?php echo nl2br(htmlspecialchars($request['message'])); ?>
                        </div>

                        <?php if ($request['admin_response']): ?>
                            <div class="request-content" style="border-left-color: #28a745; background: #e8f5e8;">
                                <strong>Admin Response:</strong><br>
                                <?php echo nl2br(htmlspecialchars($request['admin_response'])); ?>
                            </div>
                        <?php endif; ?>

                        <div class="request-actions">
                            <button type="button" class="btn toggle-response"
                                onclick="toggleResponseForm(<?php echo $request['id']; ?>)">
                                <?php echo $request['admin_response'] ? 'Update Response' : 'Respond'; ?>
                            </button>
                            <a href="view_request.php?id=<?php echo $request['id']; ?>" class="btn btn-secondary">View
                                Details</a>
                        </div>

                        <div id="response-form-<?php echo $request['id']; ?>" class="response-form" style="display: none;">
                            <h4>Respond to Request</h4>
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                <input type="hidden" name="respond_request" value="1">

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select name="status" required>
                                            <option value="pending" <?php echo $request['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="in_progress" <?php echo $request['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="completed" <?php echo $request['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="rejected" <?php echo $request['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                            <option value="on_hold" <?php echo $request['status'] === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Priority</label>
                                        <select name="priority" required>
                                            <option value="low" <?php echo $request['priority'] === 'low' ? 'selected' : ''; ?>>
                                                Low</option>
                                            <option value="normal" <?php echo $request['priority'] === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                            <option value="high" <?php echo $request['priority'] === 'high' ? 'selected' : ''; ?>>
                                                High</option>
                                            <option value="urgent" <?php echo $request['priority'] === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Assign To</label>
                                        <select name="assign_to">
                                            <option value="">None</option>
                                            <?php foreach ($available_users as $user): ?>
                                                <option value="<?php echo $user['id']; ?>" <?php echo $request['assigned_to'] == $user['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($user['username'] . ' (' . $user['email'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Follow-up Date</label>
                                        <input type="date" name="follow_up_date"
                                            value="<?php echo $request['follow_up_date']; ?>"
                                            min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Response Message <span style="color: red;">*</span></label>
                                    <textarea name="admin_response" required
                                        placeholder="Enter your response to the employee..."><?php echo htmlspecialchars($request['admin_response']); ?></textarea>
                                </div>

                                <div class="checkbox-group">
                                    <input type="checkbox" name="is_internal_note" id="internal-<?php echo $request['id']; ?>">
                                    <label for="internal-<?php echo $request['id']; ?>">Internal note (employee won't be
                                        notified)</label>
                                </div>

                                <div class="btn-group">
                                    <button type="submit" class="btn btn-primary">Submit Response</button>
                                    <button type="button" class="btn btn-secondary"
                                        onclick="toggleResponseForm(<?php echo $request['id']; ?>)">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">&laquo;
                                Previous</a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Toggle response form visibility
        function toggleResponseForm(requestId) {
            const form = document.getElementById('response-form-' + requestId);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }

        // Bulk actions functionality
        document.getElementById('selectAll').addEventListener('change', function () {
            const checkboxes = document.querySelectorAll('.request-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateSelectedCount();
        });

        // Update selected count when individual checkboxes change
        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('request-checkbox')) {
                updateSelectedCount();

                // Update select all checkbox
                const allCheckboxes = document.querySelectorAll('.request-checkbox');
                const checkedCheckboxes = document.querySelectorAll('.request-checkbox:checked');
                const selectAllCheckbox = document.getElementById('selectAll');

                selectAllCheckbox.indeterminate = checkedCheckboxes.length > 0 && checkedCheckboxes.length < allCheckboxes.length;
                selectAllCheckbox.checked = checkedCheckboxes.length === allCheckboxes.length;
            }
        });

        function updateSelectedCount() {
            const selected = document.querySelectorAll('.request-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = selected + ' selected';
        }

        function confirmBulkAction() {
            const selected = document.querySelectorAll('.request-checkbox:checked').length;
            if (selected === 0) {
                alert('Please select at least one request.');
                return false;
            }

            const action = document.querySelector('select[name="bulk_action"]').value;
            if (!action) {
                alert('Please select an action.');
                return false;
            }

            return confirm(`Are you sure you want to apply this action to ${selected} request(s)?`);
        }

        // Auto-submit search form on Enter
        document.querySelector('input[name="search"]').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.form.submit();
            }
        });

        // Character counter for response textarea
        document.addEventListener('input', function (e) {
            if (e.target.name === 'admin_response') {
                const maxLength = 2000;
                const currentLength = e.target.value.length;
                const remaining = maxLength - currentLength;

                let counter = e.target.parentNode.querySelector('.char-counter');
                if (!counter) {
                    counter = document.createElement('div');
                    counter.className = 'char-counter';
                    counter.style.fontSize = '12px';
                    counter.style.color = '#666';
                    counter.style.textAlign = 'right';
                    counter.style.marginTop = '5px';
                    e.target.parentNode.appendChild(counter);
                }

                counter.textContent = `${currentLength}/${maxLength} characters`;
                counter.style.color = remaining < 100 ? '#dc3545' : '#666';
            }
        });

        // Initialize selected count on page load
        updateSelectedCount();
    </script>
</body>

</html>