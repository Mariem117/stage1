<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($input['notification_id']) && isset($input['csrf_token']) && verifyCSRFToken($input['csrf_token'])) {
    $notification_id = (int) $input['notification_id'];

    try {
        // Check if the notification belongs to the current user
        $stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $_SESSION['user_id']]);
        $notification = $stmt->fetch();

        if (!$notification) {
            echo json_encode(['success' => false, 'message' => 'Notification not found or access denied']);
            exit;
        }

        // Update notification as read
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $success = $stmt->execute([$notification_id, $_SESSION['user_id']]);

        if ($success && $stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to mark notification as read']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request or missing parameters']);
}
?>