<?php
require_once 'config.php';
requireLogin();

$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = 'Request submitted successfully!';
}

// Get employee profile
$stmt = $pdo->prepare("
    SELECT ep.id as employee_profile_id, ep.first_name, ep.last_name, u.email
    FROM employee_profiles ep
    JOIN users u ON ep.user_id = u.id
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$employee = $stmt->fetch();

if (!$employee) {
    header('Location: login.php');
    exit();
}

// Handle request submission
if ($_POST && isset($_POST['submit_request']) && verifyCSRFToken($_POST['csrf_token'])) {
    $subject = sanitize($_POST['subject']);
    $message = sanitize($_POST['message']);
    $priority = sanitize($_POST['priority']);

    // Validation
    if (empty($subject) || empty($message)) {
        $error = 'Please fill in all required fields';
    } elseif (!in_array($priority, ['low', 'medium', 'high'])) {
        $error = 'Invalid priority selected';
    } else {
        try {
            // Insert request first (outside transaction)
            $stmt = $pdo->prepare("
                INSERT INTO employee_requests (employee_id, subject, message, priority)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$employee['employee_profile_id'], $subject, $message, $priority]);
            $request_id = $pdo->lastInsertId();

            // Begin transaction for notifications only
            $pdo->beginTransaction();

            // Get admin IDs
            $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin'");
            $stmt->execute();
            $admin_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Insert notifications for admins
            foreach ($admin_ids as $admin_id) {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, related_id)
                    VALUES (?, 'new_request', ?, ?, ?)
                ");
                $stmt->execute([
                    $admin_id,
                    "New Request from {$employee['first_name']} {$employee['last_name']}",
                    "Subject: $subject",
                    $request_id
                ]);
            }

            // Insert notification for employee
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, related_id)
                VALUES (?, 'request_submitted', ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                "Request Submitted",
                "Your request '$subject' has been submitted.",
                $request_id
            ]);

            $pdo->commit();
            header('Location: emp_request.php?success=1');
            exit();
        } catch (Exception $e) {
            // Only rollback if there's an active transaction
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to submit request: ' . $e->getMessage();
        }
    }
}

// Fetch user's requests
$stmt = $pdo->prepare("
    SELECT er.*, u.email as admin_email
    FROM employee_requests er
    LEFT JOIN users u ON er.admin_id = u.id
    WHERE er.employee_id = ?
    ORDER BY er.created_at DESC
");
$stmt->execute([$employee['employee_profile_id']]);
$requests = $stmt->fetchAll();

// Fetch notifications
$stmt = $pdo->prepare("
    SELECT id, title, message, is_read, created_at, related_id
    FROM notifications
    WHERE user_id = ? AND type IN ('request_submitted', 'request_responded')
    ORDER BY created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Requests - Employee Management System</title>
    <link rel="stylesheet" href="profile.css">
    <style>
        .logo {
            height: 50px;
            margin-right: 15px;
        }

        img {
            overflow-clip-margin: content-box;
            overflow: clip;
        }

        .mark-read {
            background: #007bff;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 10px;
        }

        .mark-read:hover {
            background: #0056b3;
        }

        .notification-item {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-unread {
            font-weight: bold;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
        }
    </style>
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <img src="logo.png" alt="Logo" class="logo">
            <div class="navbar-nav">
                <a href="profile.php" class="nav-link">My Profile</a>
                <a href="emp_request.php" class="nav-link">Requests</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="profile-header">
            <h1>My Requests</h1>
        </div>

        <div class="profile-form">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">📋</div>
                    <h3>Submit New Request</h3>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="form-group">
                        <label for="subject">Subject <span class="required">*</span></label>
                        <input type="text" id="subject" name="subject" required>
                    </div>
                    <div class="form-group">
                        <label for="priority">Priority <span class="required">*</span></label>
                        <select id="priority" name="priority" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="message">Message <span class="required">*</span></label>
                        <textarea id="message" name="message" required></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="submit_request" class="btn">Submit Request</button>
                    </div>
                </form>
            </div>

            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">🔔</div>
                    <h3>Notifications</h3>
                </div>
                <?php if (empty($notifications)): ?>
                    <p>No notifications available.</p>
                <?php else: ?>
                    <div class="notifications-list">
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item <?php echo !$notification['is_read'] ? 'notification-unread' : ''; ?>"
                                id="notification-<?php echo $notification['id']; ?>">
                                <div class="dept-name">
                                    <?php echo htmlspecialchars($notification['title']); ?>
                                    <?php if (!$notification['is_read']): ?>
                                        <span class="admin-badge">New</span>
                                        <button class="mark-read" data-notification-id="<?php echo $notification['id']; ?>">Mark as
                                            Read</button>
                                    <?php endif; ?>
                                </div>
                                <div><?php echo htmlspecialchars($notification['message']); ?></div>
                                <div><small><?php echo date('F j, Y H:i', strtotime($notification['created_at'])); ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">📜</div>
                    <h3>Request History</h3>
                </div>
                <?php if (empty($requests)): ?>
                    <p>No requests submitted yet.</p>
                <?php else: ?>
                    <table class="employee-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Created At</th>
                                <th>Admin Response</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td><a
                                            href="request_detail.php?id=<?php echo $request['id']; ?>"><?php echo htmlspecialchars($request['subject']); ?></a>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $request['status']; ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo ucfirst($request['priority']); ?></td>
                                    <td><?php echo date('F j, Y H:i', strtotime($request['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($request['admin_response'] ?? 'No response yet'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Handle mark as read functionality
        document.addEventListener('DOMContentLoaded', function () {
            const markReadButtons = document.querySelectorAll('.mark-read');

            markReadButtons.forEach(button => {
                button.addEventListener('click', function () {
                    const notificationId = this.dataset.notificationId;
                    const notificationElement = document.getElementById('notification-' + notificationId);

                    // Disable button to prevent multiple clicks
                    this.disabled = true;
                    this.textContent = 'Marking...';

                    fetch('mark_notification_read.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            notification_id: parseInt(notificationId),
                            csrf_token: '<?php echo generateCSRFToken(); ?>'
                        })
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Remove unread styling
                                notificationElement.classList.remove('notification-unread');

                                // Remove "New" badge
                                const adminBadge = notificationElement.querySelector('.admin-badge');
                                if (adminBadge) {
                                    adminBadge.remove();
                                }

                                // Remove the mark as read button
                                this.remove();
                            } else {
                                // Re-enable button on error
                                this.disabled = false;
                                this.textContent = 'Mark as Read';
                                alert('Failed to mark notification as read: ' + (data.message || 'Unknown error'));
                            }
                        })
                        .catch(error => {
                            // Re-enable button on error
                            this.disabled = false;
                            this.textContent = 'Mark as Read';
                            alert('Error: ' + error.message);
                        });
                });
            });
        });
    </script>
</body>

</html>