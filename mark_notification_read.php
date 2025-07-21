<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($input['notification_id']) && verifyCSRFToken($input['csrf_token'])) {
    $notification_id = (int) $input['notification_id'];

    try {
        $success = markNotificationAsRead($pdo, $notification_id, $_SESSION['user_id']);

        if ($success) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to mark notification as read']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>