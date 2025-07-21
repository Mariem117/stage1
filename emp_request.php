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

            // Begin transaction for notifications
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
            $pdo->rollBack();
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
                    <ul class="dept-list">
                        <?php foreach ($notifications as $notification): ?>
                            <li class="dept-item">
                                <span class="dept-name <?php echo $notification['is_read'] ? '' : 'font-bold'; ?>">
                                    <?php echo htmlspecialchars($notification['title']); ?>
                                    <?php if (!$notification['is_read']): ?>
                                        <span class="admin-badge">New</span>
                                    <?php endif; ?>
                                </span>
                                <span><?php echo htmlspecialchars($notification['message']); ?></span>
                                <span><?php echo date('F j, Y H:i', strtotime($notification['created_at'])); ?></span>
                                <?php if (!$notification['is_read']): ?>
                                    <button class="btn btn-small mark-read"
                                        data-notification-id="<?php echo $notification['id']; ?>">Mark as Read</button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
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
                                    <td><?php echo htmlspecialchars($request['subject']); ?></td>
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
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Handle mark as read
        document.querySelectorAll('.mark-read').forEach(button => {
            button.addEventListener('click', function () {
                const notificationId = this.dataset.notificationId;
                fetch('mark_notification_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        notification_id: notificationId,
                        csrf_token: '<?php echo generateCSRFToken(); ?>'
                    })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            this.closest('.dept-item').querySelector('.dept-name').classList.remove('font-bold');
                            this.closest('.dept-item').querySelector('.admin-badge').remove();
                            this.remove();
                        } else {
                            alert('Failed to mark notification as read: ' + (data.message || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        alert('Error: ' + error.message);
                    });
            });
        });
        // Fetch CSRF token dynamically
        fetch('get_csrf_token.php')
            .then(response => response.json())
            .then(tokenData => {
                document.querySelectorAll('.mark-read').forEach(button => {
                    button.addEventListener('click', function () {
                        const notificationId = this.dataset.notificationId;

                        fetch('mark_notification_read.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                notification_id: notificationId,
                                csrf_token: tokenData.csrf_token
                            })
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    const deptItem = this.closest('.dept-item');
                                    const deptName = deptItem.querySelector('.dept-name');
                                    const adminBadge = deptItem.querySelector('.admin-badge');

                                    if (deptName) {
                                        deptName.classList.remove('font-bold');
                                    }
                                    if (adminBadge) {
                                        adminBadge.remove();
                                    }
                                    this.remove();
                                } else {
                                    alert('Failed to mark notification as read: ' + (data.message || 'Unknown error'));
                                }
                            })
                            .catch(error => {
                                alert('Error: ' + error.message);
                            });
                    });
                });
            });
    </script>
</body>

</html>