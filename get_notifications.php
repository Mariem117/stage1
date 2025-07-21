<?php
// File 1: get_notifications.php
?>
<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

try {
    // Get unread count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count
        FROM notifications
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $unread_count = $stmt->fetch()['unread_count'];

    // Get recent notifications
    $stmt = $pdo->prepare("
        SELECT id, title, message, is_read, created_at, related_id, type
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $notifications = $stmt->fetchAll();

    // Add time_ago to each notification
    foreach ($notifications as &$notification) {
        $notification['time_ago'] = timeAgo($notification['created_at']);
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unread_count
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load notifications: ' . $e->getMessage()
    ]);
}
?>

<?php
// File 2: mark_notification_read.php
?>
<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['notification_id']) || !isset($input['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

if (!verifyCSRFToken($input['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET is_read = 1, updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$input['notification_id'], $_SESSION['user_id']]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Notification not found or already read']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

<?php
// File 3: mark_all_notifications_read.php
?>
<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Missing CSRF token']);
    exit();
}

if (!verifyCSRFToken($input['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET is_read = 1, updated_at = NOW()
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$_SESSION['user_id']]);

    echo json_encode([
        'success' => true,
        'marked_count' => $stmt->rowCount()
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

<?php
// File 4: get_csrf_token.php (if you don't have it already)
?>
<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

echo json_encode([
    'csrf_token' => generateCSRFToken()
]);
?>

<?php
// File 5: notifications.php (Optional - dedicated notifications page)
?>
<?php
require_once 'config.php';
requireLogin();

$error = '';
$success = '';

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

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total notifications count
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM notifications
    WHERE user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$total_notifications = $stmt->fetch()['total'];
$total_pages = ceil($total_notifications / $per_page);

// Fetch all notifications with pagination
$stmt = $pdo->prepare("
    SELECT id, title, message, is_read, created_at, related_id, type
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$_SESSION['user_id'], $per_page, $offset]);
$all_notifications = $stmt->fetchAll();

// Get unread count
$stmt = $pdo->prepare("
    SELECT COUNT(*) as unread_count
    FROM notifications
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$_SESSION['user_id']]);
$unread_count = $stmt->fetch()['unread_count'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Notifications - Employee Management System</title>
    <link rel="stylesheet" href="profile.css">
</head>

<body>
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-nav">
                <a href="profile.php" class="nav-link">My Profile</a>
                <a href="emp_request.php" class="nav-link">Requests</a>
                <a href="notifications.php" class="nav-link active">Notifications</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="profile-header">
            <h1>All Notifications</h1>
            <p><?php echo $total_notifications; ?> total notifications, <?php echo $unread_count; ?> unread</p>
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
                    <div class="section-icon">🔔</div>
                    <h3>All Notifications</h3>
                    <?php if ($unread_count > 0): ?>
                        <button class="btn btn-small" onclick="markAllAsRead()">Mark All as Read</button>
                    <?php endif; ?>
                </div>

                <?php if (empty($all_notifications)): ?>
                    <p>No notifications available.</p>
                <?php else: ?>
                    <div class="notifications-list">
                        <?php foreach ($all_notifications as $notification): ?>
                            <div class="notification-item-full <?php echo $notification['is_read'] ? '' : 'unread'; ?>"
                                data-notification-id="<?php echo $notification['id']; ?>">
                                <div class="notification-content">
                                    <div class="notification-title-full">
                                        <?php echo htmlspecialchars($notification['title']); ?>
                                        <?php if (!$notification['is_read']): ?>
                                            <span class="unread-indicator">NEW</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notification-message-full">
                                        <?php echo htmlspecialchars($notification['message']); ?>
                                    </div>
                                    <div class="notification-meta">
                                        <span class="notification-time">
                                            <?php echo date('F j, Y H:i', strtotime($notification['created_at'])); ?>
                                        </span>
                                        <?php if (!$notification['is_read']): ?>
                                            <button class="btn btn-small mark-read-btn"
                                                onclick="markAsRead(<?php echo $notification['id']; ?>)">
                                                Mark as Read
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <style>
        .notifications-list {
            margin-top: 20px;
        }

        .notification-item-full {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 15px;
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .notification-item-full:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .notification-item-full.unread {
            border-left: 4px solid #007bff;
            background-color: #f8f9ff;
        }

        .notification-content {
            padding: 15px;
        }

        .notification-title-full {
            font-weight: bold;
            color: #333;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notification-message-full {
            color: #666;
            margin-bottom: 10px;
            line-height: 1.4;
        }

        .notification-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #999;
        }

        .unread-indicator {
            background: #007bff;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: normal;
        }

        .mark-read-btn {
            font-size: 11px;
            padding: 4px 8px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 30px;
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
            border-color: #007bff;
        }

        .page-link:hover:not(.active) {
            background: #f8f9fa;
        }
    </style>

    <script>
        // Fetch CSRF token dynamically and use it for markAsRead
        let csrfToken = null;
        fetch('get_csrf_token.php')
            .then(response => response.json())
            .then(tokenData => {
                csrfToken = tokenData.csrf_token;
            });

        function markAsRead(notificationId) {
            // Wait for CSRF token to be fetched
            if (!csrfToken) {
                setTimeout(() => markAsRead(notificationId), 100);
                return;
            }
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    notification_id: notificationId,
                    csrf_token: csrfToken
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const item = document.querySelector(`[data-notification-id="${notificationId}"]`);
                        if (item) {
                            item.classList.remove('unread');
                            const indicator = item.querySelector('.unread-indicator');
                            const button = item.querySelector('.mark-read-btn');
                            if (indicator) indicator.remove();
                            if (button) button.remove();
                        }
                    } else {
                        alert(data.message || 'Failed to mark notification as read.');
                    }
                })
                .catch(() => {
                    alert('Failed to mark notification as read.');
                });
        }

        // Mark all as read
        function markAllAsRead() {
            // Wait for CSRF token to be fetched
            if (!csrfToken) {
                setTimeout(markAllAsRead, 100);
                return;
            }
            fetch('mark_all_notifications_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    csrf_token: csrfToken
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.querySelectorAll('.notification-item-full.unread').forEach(item => {
                            item.classList.remove('unread');
                            const indicator = item.querySelector('.unread-indicator');
                            const button = item.querySelector('.mark-read-btn');
                            if (indicator) indicator.remove();
                            if (button) button.remove();
                        });
                    } else {
                        alert(data.message || 'Failed to mark all as read.');
                    }
                })
                .catch(() => {
                    alert('Failed to mark all as read.');
                });
        }
    </script>
</body>

</html>