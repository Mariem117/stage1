<?php
require_once 'config.php';
requireLogin();

$error = '';
$success = '';
$request = null;
$comments = [];
$attachments = [];
$status_history = [];
$assignment_history = [];

// Handle URL parameters for success/error messages
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: emp_request.php');
    exit();
}

$request_id = (int) $_GET['id'];

// Check if user has permission to view this request
$is_admin = ($_SESSION['role'] === 'admin');
$stmt = $pdo->prepare("
    SELECT er.*, ep.first_name, ep.last_name, u.email, u2.email as assigned_email
    FROM employee_requests er
    JOIN employee_profiles ep ON er.employee_id = ep.id
    JOIN users u ON ep.user_id = u.id
    LEFT JOIN users u2 ON er.assigned_to = u2.id
    WHERE er.id = ? AND (ep.user_id = ? OR ?)
");
$stmt->execute([$request_id, $_SESSION['user_id'], $is_admin]);
$request = $stmt->fetch();

if (!$request) {
    header('Location: emp_request.php');
    exit();
}

// Fetch conversation history
$stmt = $pdo->prepare("
    SELECT rc.*, u.username, u.role
    FROM request_comments rc
    JOIN users u ON rc.user_id = u.id
    WHERE rc.request_id = ? AND (rc.is_internal = 0 OR ?)
    ORDER BY rc.created_at ASC
");
$stmt->execute([$request_id, $is_admin]);
$comments = $stmt->fetchAll();

// Fetch attachments
$stmt = $pdo->prepare("
    SELECT ra.*, u.username
    FROM request_attachments ra
    JOIN users u ON ra.user_id = u.id
    WHERE ra.request_id = ?
    ORDER BY ra.uploaded_at ASC
");
$stmt->execute([$request_id]);
$attachments = $stmt->fetchAll();

// Fetch status history
$stmt = $pdo->prepare("
    SELECT rsh.*, u.username
    FROM request_status_history rsh
    JOIN users u ON rsh.changed_by = u.id
    WHERE rsh.request_id = ?
    ORDER BY rsh.created_at ASC
");
$stmt->execute([$request_id]);
$status_history = $stmt->fetchAll();

// Fetch assignment history
$stmt = $pdo->prepare("
    SELECT ra.*, u1.username as from_username, u2.username as to_username, u3.username as by_username
    FROM request_assignments ra
    LEFT JOIN users u1 ON ra.assigned_from = u1.id
    LEFT JOIN users u2 ON ra.assigned_to = u2.id
    JOIN users u3 ON ra.assigned_by = u3.id
    WHERE ra.request_id = ?
    ORDER BY ra.created_at ASC
");
$stmt->execute([$request_id]);
$assignment_history = $stmt->fetchAll();

// Handle new comment submission
if ($_POST && isset($_POST['submit_comment']) && verifyCSRFToken($_POST['csrf_token'])) {
    $comment = sanitize($_POST['comment']);
    $is_internal = ($is_admin && isset($_POST['is_internal'])) ? 1 : 0;

    if (empty($comment)) {
        $error = 'Comment cannot be empty';
    } else {
        try {
            $stmt->execute([$request_id, $_SESSION['user_id'], $comment, $is_internal]);
            
        // FIXED: Redirect to prevent resubmission
        header('Location: request_detail.php?id=' . $request_id . '&success=Comment added successfully');
        exit();
        } catch (Exception $e) {
            $error = 'Failed to add comment: ' . $e->getMessage();
        }
    }
}

// Handle file upload
if ($_POST && isset($_POST['upload_attachment']) && verifyCSRFToken($_POST['csrf_token'])) {
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB

        if (!in_array($file['type'], $allowed_types)) {
            $error = 'Invalid file type. Only JPEG, PNG, and PDF are allowed.';
        } elseif ($file['size'] > $max_size) {
            $error = 'File size exceeds 5MB limit.';
        } else {
            $upload_dir = 'uploads/requests/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $filename = uniqid() . '_' . sanitize_file_name($file['name']);
            $file_path = $upload_dir . $filename;

            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO request_attachments (request_id, user_id, filename, original_filename, file_size, mime_type, file_path)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $request_id,
                        $_SESSION['user_id'],
                        $filename,
                        $file['name'],
                        $file['size'],
                        $file['type'],
                        $file_path
                    ]);
                    
                // FIXED: Redirect to prevent resubmission
                header('Location: request_detail.php?id=' . $request_id . '&success=File uploaded successfully');
                exit();
                } catch (Exception $e) {
                    $error = 'Failed to save attachment: ' . $e->getMessage();
                    unlink($file_path);
                }
            } else {
                $error = 'Failed to upload file';
            }
        }
    } else {
        $error = 'No file uploaded or upload error occurred';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Details - Employee Management System</title>
    <link rel="stylesheet" href="profile.css">
    <style>
        .request-details {
            margin-top: 20px;
        }

        .conversation-section,
        .attachments-section,
        .status-timeline,
        .assignment-history {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
            padding: 20px;
        }

        .comment-item {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
            position: relative;
        }

        .comment-item.internal {
            background-color: #fff8e1;
        }

        .comment-meta {
            color: #666;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .attachment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .timeline-item {
            padding: 10px 0;
            border-left: 2px solid #007bff;
            padding-left: 20px;
            position: relative;
        }

        .timeline-item:before {
            content: '';
            width: 10px;
            height: 10px;
            background: #007bff;
            border-radius: 50%;
            position: absolute;
            left: -6px;
            top: 12px;
        }

        .assignment-item {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
            <div class="navbar-nav">
                <?php if ($is_admin): ?>
                    <<?php if ($_SESSION['role'] === 'admin'): ?>
                    <span class="admin-badge">ADMIN</span>
                    <a href="dashboard.php" class="nav-link">Dashboard</a>
                    <a href="employees_listing.php" class="nav-link">Employees</a>
                <?php else: ?>
                    <a href="dashboard.php" class="nav-link">Dashboard</a>
                <?php endif; ?>
                <a href="profile.php" class="nav-link">My Profile</a>
                <a href="admin_request.php" class="nav-link">Requests</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="profile-header">
            <h1>Request #<?php echo htmlspecialchars($request['request_number']); ?></h1>
            <p>Subject: <?php echo htmlspecialchars($request['subject']); ?></p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="request-details">
            <!-- Request Overview -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">📋</div>
                    <h3>Request Overview</h3>
                </div>
                <p><strong>Employee:</strong>
                    <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($request['email']); ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($request['category'])); ?></p>
                <p><strong>Priority:</strong> <?php echo htmlspecialchars(ucfirst($request['priority'])); ?></p>
                <p><strong>Status:</strong>
                    <span class="status-badge status-<?php echo $request['status']; ?>">
                        <?php echo ucfirst($request['status']); ?>
                    </span>
                </p>
                <p><strong>Assigned To:</strong>
                    <?php echo htmlspecialchars($request['assigned_email'] ?? 'Unassigned'); ?></p>
                <p><strong>Created:</strong> <?php echo date('F j, Y H:i', strtotime($request['created_at'])); ?></p>
                <p><strong>Message:</strong> <?php echo htmlspecialchars($request['message']); ?></p>
            </div>

            <!-- Conversation History -->
            <div class="conversation-section">
                <div class="section-header">
                    <div class="section-icon">💬</div>
                    <h3>Conversation History</h3>
                </div>
                <div class="comments-list">
                    <?php if (empty($comments)): ?>
                        <p>No comments yet.</p>
                    <?php else: ?>
                        <?php foreach ($comments as $comment): ?>
                            <div class="comment-item <?php echo $comment['is_internal'] ? 'internal' : ''; ?>">
                                <div class="comment-meta">
                                    <span><?php echo htmlspecialchars($comment['username']); ?>
                                        (<?php echo ucfirst($comment['role']); ?>)</span>
                                    <span><?php echo date('F j, Y H:i', strtotime($comment['created_at'])); ?></span>
                                    <?php if ($comment['is_internal']): ?>
                                        <span class="unread-indicator">Internal</span>
                                    <?php endif; ?>
                                </div>
                                <p><?php echo htmlspecialchars($comment['comment']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="form-group">
                        <label for="comment">Add Comment</label>
                        <textarea id="comment" name="comment" required></textarea>
                        <?php if ($is_admin): ?>
                            <label><input type="checkbox" name="is_internal"> Internal Note (visible only to admins)</label>
                        <?php endif; ?>
                    </div>
                    <button type="submit" name="submit_comment" class="btn">Add Comment</button>
                </form>
            </div>

            <!-- Attachments -->
            <div class="attachments-section">
                <div class="section-header">
                    <div class="section-icon">📎</div>
                    <h3>Attachments</h3>
                </div>
                <?php if (empty($attachments)): ?>
                    <p>No attachments uploaded.</p>
                <?php else: ?>
                    <div class="attachments-list">
                        <?php foreach ($attachments as $attachment): ?>
                            <div class="attachment-item">
                                <div>
                                    <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" target="_blank">
                                        <?php echo htmlspecialchars($attachment['original_filename']); ?>
                                    </a>
                                    <span>(<?php echo number_format($attachment['file_size'] / 1024, 2); ?> KB)</span>
                                    <span>Uploaded by <?php echo htmlspecialchars($attachment['username']); ?></span>
                                    <span><?php echo date('F j, Y H:i', strtotime($attachment['uploaded_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="form-group">
                        <label for="attachment">Upload Attachment (JPEG, PNG, PDF, max 5MB)</label>
                        <input type="file" id="attachment" name="attachment" accept=".jpg,.jpeg,.png,.pdf">
                    </div>
                    <button type="submit" name="upload_attachment" class="btn">Upload Attachment</button>
                </form>
            </div>

            <!-- Status Timeline -->
            <div class="status-timeline">
                <div class="section-header">
                    <div class="section-icon">⏳</div>
                    <h3>Status Timeline</h3>
                </div>
                <?php if (empty($status_history)): ?>
                    <p>No status changes recorded.</p>
                <?php else: ?>
                    <div class="timeline-list">
                        <?php foreach ($status_history as $status): ?>
                            <div class="timeline-item">
                                <p>
                                    <strong>Status changed</strong> from
                                    <?php echo htmlspecialchars($status['old_status'] ?? 'None'); ?>
                                    to <?php echo htmlspecialchars($status['new_status']); ?>
                                </p>
                                <p>By: <?php echo htmlspecialchars($status['username']); ?></p>
                                <p>Notes: <?php echo htmlspecialchars($status['notes'] ?? 'No notes'); ?></p>
                                <p><?php echo date('F j, Y H:i', strtotime($status['created_at'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Assignment History -->
            <div class="assignment-history">
                <div class="section-header">
                    <div class ="the-section-icon">👥</div>
                    <h3>Assignment History</h3>
                </div>
                <?php if (empty($assignment_history)): ?>
                    <p>No assignment changes recorded.</p>
                <?php else: ?>
                    <div class="assignment-list">
                        <?php foreach ($assignment_history as $assignment): ?>
                            <div class="assignment-item">
                                <p>
                                    <strong>Assignment changed</strong> from
                                    <?php echo htmlspecialchars($assignment['from_username'] ?? 'Unassigned'); ?>
                                    to <?php echo htmlspecialchars($assignment['to_username'] ?? 'Unassigned'); ?>
                                </p>
                                <p>By: <?php echo htmlspecialchars($assignment['by_username']); ?></p>
                                <p>Notes: <?php echo htmlspecialchars($assignment['notes'] ?? 'No notes'); ?></p>
                                <p><?php echo date('F j, Y H:i', strtotime($assignment['created_at'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>