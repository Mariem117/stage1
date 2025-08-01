<?php
require_once 'config.php';
requireLogin();
requireAdmin();

// Add missing sanitize function if not in config.php
if (!function_exists('sanitize')) {
    function sanitize($input)
    {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

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

// Validate sort parameters - SECURE VERSION
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
$per_page = (int) $per_page;
$offset = (int) $offset;

// Secure ORDER BY mapping
$order_columns = [
    'created_at' => 'er.created_at',
    'priority' => 'priority_order',
    'status' => 'er.status',
    'subject' => 'er.subject',
    'first_name' => 'ep.first_name',
    'last_name' => 'ep.last_name'
];

$order_column = isset($order_columns[$sort_by]) ? $order_columns[$sort_by] : 'er.created_at';
$order_by_clause = "$order_column $sort_order";

// Fetch filtered and sorted requests - SECURE VERSION
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

// Execute with only the filter parameters
$stmt->execute($params);
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

// Enhanced request response handling with better error handling
if ($_POST && isset($_POST['respond_request']) && verifyCSRFToken($_POST['csrf_token'])) {
    $request_id = (int) $_POST['request_id'];
    $response = trim(sanitize($_POST['admin_response']));
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
                        $notification_message .= " and assigned to " . htmlspecialchars($assigned_user['username']);
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
                    "You have been assigned to handle a " . htmlspecialchars($priority) . " priority request",
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

            // Refresh requests data with the same secure query
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
            $error = 'Failed to submit response: ' . htmlspecialchars($e->getMessage());
            error_log('Admin request response error: ' . $e->getMessage());
        }
    } else {
        $error = implode('<br>', array_map('htmlspecialchars', $validation_errors));
    }
}

// Handle bulk actions with improved validation
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
            $bulk_errors = [];

            foreach ($selected_requests as $req_id) {
                $req_id = (int) $req_id;

                // Validate that request exists
                $check_stmt = $pdo->prepare("SELECT id FROM employee_requests WHERE id = ?");
                $check_stmt->execute([$req_id]);
                if (!$check_stmt->fetch()) {
                    $bulk_errors[] = "Request ID $req_id not found";
                    continue;
                }

                try {
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
                } catch (Exception $e) {
                    $bulk_errors[] = "Failed to update request ID $req_id: " . $e->getMessage();
                }
            }

            if (empty($bulk_errors)) {
                $pdo->commit();
                $success = "Bulk action completed successfully! Updated $bulk_success_count request(s).";
            } else {
                $pdo->rollBack();
                $error = "Bulk action partially failed. Errors: " . implode(', ', $bulk_errors);
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Bulk action failed: ' . htmlspecialchars($e->getMessage());
            error_log('Bulk action error: ' . $e->getMessage());
        }
    }
}

// Get available users for assignment - CORRECTED SQL
$users_stmt = $pdo->prepare("
    SELECT u.id, u.username, u.email 
    FROM users u 
    INNER JOIN employee_profiles ep ON ep.user_id = u.id
    WHERE u.role IN ('admin', 'employee') AND ep.status = 'active'
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
    <title>My Profile - Employee Management System</title>
    <link rel="stylesheet" href="profile.css">
    <style>
        .profile-form {
            position: relative;
        }
        .logo {
            height: 50px;
            margin-right: 15px;
        }
        img {
            overflow-clip-margin: content-box;
            overflow: clip;
        }
        .profile-picture-corner {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 10;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid #fff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .profile-picture-corner:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .profile-picture-corner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-picture-corner .placeholder-icon {
            font-size: 48px;
            color: #ccc;
        }

        .profile-picture-input {
            display: none;
        }

        .form-content {
            padding-left: 140px;
            padding-top: 20px;
        }

        @media (max-width: 768px) {
            .profile-picture-corner {
                position: static;
                margin: 0 auto 20px;
                display: block;
            }

            .form-content {
                padding-left: 0;
                padding-top: 0;
            }
        }
        .image-upload-box {
            width: 100%;
            height: 200px;
            border: 2px dashed #ccc;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: border-color 0.3s ease;
            background-color: #fafafa;
            margin-top: 8px;
        }

        .image-upload-box:hover {
            border-color: #007bff;
            background-color: #f0f8ff;
        }

        .uploaded-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 6px;
        }

        .upload-placeholder {
            text-align: center;
            color: #666;
        }

        .upload-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }

        .upload-text {
            font-size: 16px;
            font-weight: 500;
        }

        .image-upload-box:hover .upload-placeholder {
            color: #007bff;
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
            <div class="navbar-nav">
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <span class="admin-badge">ADMIN</span>
                    <a href="dashboard.php" class="nav-link">Dashboard</a>
                    <a href="employees_listing.php" class="nav-link">Employees</a>
                    <a href="admin_request.php" class="nav-link">Requests</a>
                <?php else: ?>
                    <a href="emp_request.php" class="nav-link">Requests</a>
                <?php endif; ?>
                <a href="profile.php" class="nav-link">My Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="profile-header">
            <h1>My Profile</h1>
            <p>Update your personal information</p>
        </div>

        <div class="profile-form">
            <?php if ($error): ?>
                <div class="alert alert-error" id="alert-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success" id="alert-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>


            <!-- Profile Picture Corner -->
            <div class="profile-picture-corner" onclick="document.getElementById('profile_picture').click()">
                <?php if ($employee['profile_picture'] && isImage($employee['profile_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($employee['profile_picture']); ?>" alt="Profile Picture">
                <?php else: ?>
                    <div class="placeholder-icon">👤</div>
                <?php endif; ?>
            </div>

            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png" class="profile-picture-input">

                <div class="form-content">
                    <div class="form-row">
                        <div class="form-group readonly">
                            <label for="employee_id">Employee ID</label>
                            <input type="text" id="employee_id"
                                value="<?php echo htmlspecialchars($employee['employee_id'] ?? NULL); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" value="<?php echo htmlspecialchars($employee['username']); ?>"
                                >
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name <span class="required">*</span></label>
                            <input type="text" id="first_name" name="first_name"
                                value="<?php echo htmlspecialchars($employee['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name <span class="required">*</span></label>
                            <input type="text" id="last_name" name="last_name"
                                value="<?php echo htmlspecialchars($employee['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email">Email <span class="required">*</span></label>
                        <input type="email" id="email" name="email"
                            value="<?php echo htmlspecialchars($employee['email']); ?>" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone"
                                value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="date_of_birth">Date of Birth</label>
                            <input type="date" id="date_of_birth" name="date_of_birth"
                                value="<?php echo htmlspecialchars($employee['date_of_birth'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="ncin">NCIN</label>
                            <input type="text" id="ncin" name="ncin"
                                value="<?php echo htmlspecialchars($employee['ncin'] ?? ''); ?>">
                        </div>
                    </div>

                   
                    <div class="form-section">
                    <h3>CIN Images</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>CIN Image (Front)</label>
                            <div class="image-upload-box" onclick="document.getElementById('cin_image_front').click()">
                                <?php if ($employee['cin_image_front'] && isImage($employee['cin_image_front'])): ?>
                                    <img src="<?php echo htmlspecialchars($employee['cin_image_front']); ?>" alt="CIN Front" class="uploaded-image">
                                <?php else: ?>
                                    <div class="upload-placeholder">
                                        <div class="upload-icon">📷</div>
                                        <div class="upload-text">Click to upload CIN Front</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="file" id="cin_image_front" name="cin_image_front" accept="image/jpeg,image/png" style="display: none;">
                        </div>
                        
                        <div class="form-group">
                            <label>CIN Image (Back)</label>
                            <div class="image-upload-box" onclick="document.getElementById('cin_image_back').click()">
                                <?php if ($employee['cin_image_back'] && isImage($employee['cin_image_back'])): ?>
                                    <img src="<?php echo htmlspecialchars($employee['cin_image_back']); ?>" alt="CIN Back" class="uploaded-image">
                                <?php else: ?>
                                    <div class="upload-placeholder">
                                        <div class="upload-icon">📷</div>
                                        <div class="upload-text">Click to upload CIN Back</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="file" id="cin_image_back" name="cin_image_back" accept="image/jpeg,image/png" style="display: none;">
                        </div>
                    </div>
                </div>


                <div class="form-row">
                    <div class="form-group">
                        <label for="cnss_first">CNSS (First 8 digits)</label>
                        <input type="text" id="cnss_first" name="cnss_first" maxlength="8" pattern="\d{8}"
                            value="<?php echo htmlspecialchars($employee['cnss_first'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="cnss_last">CNSS (Last 2 digits)</label>
                        <input type="text" id="cnss_last" name="cnss_last" maxlength="2" pattern="\d{2}"
                            value="<?php echo htmlspecialchars($employee['cnss_last'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="education">Education</label>
                    <input type="text" id="education" name="education"
                        value="<?php echo htmlspecialchars($employee['education'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Has Driving License</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="has_driving_license" value="1"
                                <?php echo (isset($employee['has_driving_license']) && $employee['has_driving_license'] == '1') ? 'checked' : ''; ?>>
                            Yes
                        </label>
                        <label>
                            <input type="radio" name="has_driving_license" value="0"
                                <?php echo (!isset($employee['has_driving_license']) || $employee['has_driving_license'] == '0') ? 'checked' : ''; ?>>
                            No
                        </label>
                    </div>
                </div>
                <?php if (isset($employee['has_driving_license']) && $employee['has_driving_license'] == '1'): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            document.getElementById('driving-license-section').style.display = 'block';
                        });
                    </script>
                    <div class="form-group" id="driving-license-section">
                        <label for="driving_license_category">Driving License Category</label>
                        <input type="text" id="driving_license_category" name="driving_license_category"
                            value="<?php echo htmlspecialchars($employee['driving_license_category'] ?? ''); ?>">
                        <label for="driving_license_number">Driving License Number</label>
                        <input type="text" id="driving_license_number" name="driving_license_number"
                            value="<?php echo htmlspecialchars($employee['driving_license_number'] ?? ''); ?>">
                    </div>

                    <!-- Enhanced Driving License Image Section -->
                    <div class="form-section" id="driving-license-image-section">
                        <h3>Driving License</h3>
                        <div class="form-group">
                            <label>Driving License Image</label>
                            <div class="image-upload-box" onclick="document.getElementById('driving_license_image').click()">
                                <?php if ($employee['driving_license_image'] && isImage($employee['driving_license_image'])): ?>
                                    <img src="<?php echo htmlspecialchars($employee['driving_license_image']); ?>" alt="Driving License" class="uploaded-image">
                                <?php else: ?>
                                    <div class="upload-placeholder">
                                        <div class="upload-icon">🚗</div>
                                        <div class="upload-text">Click to upload Driving License</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="file" id="driving_license_image" name="driving_license_image" accept="image/jpeg,image/png" style="display: none;">
                        </div>
                    </div>
                <?php endif; ?>                   
                <div class="form-group">
                    <label for="gender">Gender <span class="required">*</span></label>
                        <select id="gender" name="gender" required>
                            <option value="male" <?php echo ($employee['gender'] === 'male') ? 'selected' : ''; ?>>Male
                            </option>
                            <option value="female" <?php echo ($employee['gender'] === 'female') ? 'selected' : ''; ?>>
                                Female
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="factory">Factory <span class="required">*</span></label>
                        <select id="factory" name="factory" required>
                            <option value="1" <?php echo ($employee['factory'] === '1') ? 'selected' : ''; ?>>Factory 1
                            </option>
                            <option value="2" <?php echo ($employee['factory'] === '2') ? 'selected' : ''; ?>>Factory 2
                            </option>
                            <option value="3" <?php echo ($employee['factory'] === '3') ? 'selected' : ''; ?>>Factory 3
                            </option>
                            <option value="4" <?php echo ($employee['factory'] === '4') ? 'selected' : ''; ?>>Factory 4
                            </option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="department">Department</label>
                            <input type="text" id="department"
                                value="<?php echo htmlspecialchars($employee['department'] ?? 'Not specified'); ?>"
                            >
                        </div>
                        <div class="form-group">
                            <label for="position">Position</label>
                            <input type="text" id="position"
                                value="<?php echo htmlspecialchars($employee['position'] ?? 'Not specified'); ?>"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" maxlength="32"
                            placeholder="Enter your full address" value="<?php echo htmlspecialchars($employee['address'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group readonly">
                            <label for="hire_date">Hire Date</label>
                            <input type="text" id="hire_date"
                                value="<?php echo $employee['hire_date'] ? date('F j, Y', strtotime($employee['hire_date'])) : 'Not specified'; ?>"
                                readonly>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="civil_status">Civil Status <span class="required">*</span></label>
                        <select id="civil_status" name="civil_status" required>
                            <option value="single" <?php echo ($employee['civil_status'] === 'single') ? 'selected' : ''; ?>>
                                Single</option>
                            <option value="married" <?php echo ($employee['civil_status'] === 'married') ? 'selected' : ''; ?>>Married</option>
                            <option value="divorced" <?php echo ($employee['civil_status'] === 'divorced') ? 'selected' : ''; ?>>Divorced</option>
                            <option value="widowed" <?php echo ($employee['civil_status'] === 'widowed') ? 'selected' : ''; ?>>Widowed</option>
                        </select>
                    </div>

                    <div class="children-container">
                        <h3>Children</h3>
                        <?php foreach ($children as $index => $child): ?>
                            <div class="child-row" data-child-id="<?php echo $child['id']; ?>">
                                <div class="form-group">
                                    <label for="child_first_name_<?php echo $child['id']; ?>">Child First Name</label>
                                    <input type="text" id="child_first_name_<?php echo $child['id']; ?>"
                                        name="children[<?php echo $index; ?>][child_first_name]"
                                        value="<?php echo htmlspecialchars($child['child_first_name']); ?>">
                                    <input type="hidden" name="children[<?php echo $index; ?>][id]"
                                        value="<?php echo $child['id']; ?>">
                                </div>
                                <div class="form-group">
                                    <label for="child_second_name_<?php echo $child['id']; ?>">Child Second Name</label>
                                    <input type="text" id="child_second_name_<?php echo $child['id']; ?>"
                                        name="children[<?php echo $index; ?>][child_second_name]"
                                        value="<?php echo htmlspecialchars($child['child_second_name']); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="child_date_of_birth_<?php echo $child['id']; ?>">Birth Date</label>
                                    <input type="date" id="child_date_of_birth_<?php echo $child['id']; ?>"
                                        name="children[<?php echo $index; ?>][child_date_of_birth]"
                                        value="<?php echo htmlspecialchars($child['child_date_of_birth']); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Remove</label>
                                    <button type="button" class="remove-child"
                                        onclick="removeChildRow(this, <?php echo $child['id']; ?>)">×</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div id="new-children"></div>
                        <button type="button" class="btn btn-secondary" id="add-child">Add Child</button>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="update_profile" class="btn">Update Profile</button>
                    </div>
            </form>
        </div>
    </div>

    <script>
        // Add new child row
        document.getElementById('add-child').addEventListener('click', function () {
            const container = document.getElementById('new-children');
            const index = document.querySelectorAll('.child-row').length;
            const newRow = document.createElement('div');
            newRow.className = 'child-row';
            newRow.innerHTML = `
                <div class="form-group">
                    <label for="child_first_name_new_${index}">Child First Name</label>
                    <input type="text" id="child_first_name_new_${index}" name="children[${index}][child_first_name]">
                </div>
                <div class="form-group">
                    <label for="child_second_name_new_${index}">Child Second Name</label>
                    <input type="text" id="child_second_name_new_${index}" name="children[${index}][child_second_name]">
                </div>
                <div class="form-group">
                    <label for="child_date_of_birth_new_${index}">Birth Date</label>
                    <input type="date" id="child_date_of_birth_new_${index}" name="children[${index}][child_date_of_birth]">
                </div>
                <div class="form-group">
                    <label>Remove</label>
                    <button type="button" class="remove-child" onclick="removeChildRow(this)">×</button>
                </div>
            `;
            container.appendChild(newRow);
        });

        // Remove child row and re-index remaining rows
        function removeChildRow(button, childId = null) {
            const row = button.closest('.child-row');
            if (childId) {
                // Mark existing child for deletion
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'remove_child[]';
                input.value = childId;
                row.appendChild(input);
                row.style.display = 'none'; // Hide the row
            } else {
                // Remove new (unsaved) child row
                row.remove();
            }

            // Re-index all visible child rows
            reindexChildRows();
        }

        // Re-index child rows to ensure consistent array indices
        function reindexChildRows() {
            const rows = document.querySelectorAll('.child-row:not([style*="display: none"])');
            rows.forEach((row, index) => {
                const inputs = row.querySelectorAll('input[name]');
                inputs.forEach(input => {
                    const name = input.name;
                    if (name.startsWith('children[')) {
                        const newName = name.replace(/children\[\d+\]/, `children[${index}]`);
                        input.name = newName;
                    }
                });
            });
        }
        document.addEventListener('DOMContentLoaded', function () {
        const alert = document.getElementById('alert-message');
        if (alert) {
            setTimeout(() => {
                alert.style.display = 'none';
            }, 3000);
        }
    });
    </script>
</body>

</html>