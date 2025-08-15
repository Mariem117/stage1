<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'employee_management');
define('DB_USER', 'root');
define('DB_PASS', '');

// Create database connection with proper error handling
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Add autocommit mode for better transaction handling
    $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true);

    // Test the connection immediately
    $pdo->query("SELECT 1");

} catch (Exception $e) {
    // Don't try to rollback - connection doesn't exist yet!
    error_log('Database connection error: ' . $e->getMessage());
    die('Database connection failed. Please check your configuration.');
}

// Start session
session_start();

// Helper functions
function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function isAdmin()
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

function requireAdmin()
{
    if (!isAdmin()) {
        header('Location: profile.php');
        exit();
    }
}

function sanitize($data)
{
    return htmlspecialchars(strip_tags(trim($data)));
}

// Generate CSRF token
function generateCSRFToken()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify CSRF token
function verifyCSRFToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Enhanced file upload handler function
function handleFileUpload($file, $uploadDir = 'uploads/')
{
    // Create upload directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create upload directory'];
        }
    }

    // Check if file was uploaded without errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File too large (ini_size)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (form_size)',
            UPLOAD_ERR_PARTIAL => 'File upload was not completed',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];

        return ['success' => false, 'error' => $errors[$file['error']] ?? 'Unknown upload error'];
    }

    // For resume uploads - different validation
    if (strpos($uploadDir, 'resumes') !== false) {
        // Resume file validation
        $maxSize = 5 * 1024 * 1024; // 5MB in bytes
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'error' => 'File size exceeds 5MB limit'];
        }

        $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $fileType = $file['type'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'doc', 'docx'];

        if (!in_array($fileType, $allowedTypes) && !in_array($fileExtension, $allowedExtensions)) {
            return ['success' => false, 'error' => 'Invalid file type. Only PDF, DOC, and DOCX files are allowed'];
        }
    } else {
        // Image file validation
        $maxSize = 5 * 1024 * 1024; // 5MB in bytes
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'error' => 'File size exceeds 5MB limit'];
        }

        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $fileType = $file['type'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

        if (!in_array($fileType, $allowedTypes) || !in_array($fileExtension, $allowedExtensions)) {
            return ['success' => false, 'error' => 'Invalid file type. Only JPEG, PNG, and GIF files are allowed'];
        }
    }

    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $fileExtension;
    $targetPath = $uploadDir . $filename;

    // Move uploaded file to target directory
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Optional: Resize image if needed (requires GD extension) - only for images
        if (strpos($uploadDir, 'resumes') === false) {
            resizeImage($targetPath, 800, 600); // Max width: 800px, Max height: 600px
        }

        return ['success' => true, 'path' => $targetPath, 'filename' => $filename];
    } else {
        return ['success' => false, 'error' => 'Failed to move uploaded file'];
    }
}

function isImage($filename)
{
    if (!$filename || !file_exists($filename)) {
        return false;
    }
    $imageInfo = @getimagesize($filename);
    return $imageInfo !== false;
}

// Function to resize images (optional - requires GD extension)
function resizeImage($filePath, $maxWidth = 800, $maxHeight = 600)
{
    if (!extension_loaded('gd')) {
        return false;
    }

    $imageInfo = getimagesize($filePath);
    if (!$imageInfo) {
        return false;
    }

    $width = $imageInfo[0];
    $height = $imageInfo[1];
    $type = $imageInfo[2];

    // Check if resize is needed
    if ($width <= $maxWidth && $height <= $maxHeight) {
        return true;
    }

    // Calculate new dimensions
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = intval($width * $ratio);
    $newHeight = intval($height * $ratio);

    // Create image resource based on type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = imagecreatefromjpeg($filePath);
            break;
        case IMAGETYPE_PNG:
            $source = imagecreatefrompng($filePath);
            break;
        case IMAGETYPE_GIF:
            $source = imagecreatefromgif($filePath);
            break;
        default:
            return false;
    }

    if (!$source) {
        return false;
    }

    // Create new image
    $newImage = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG and GIF
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
        imagefill($newImage, 0, 0, $transparent);
    }

    // Resize image
    imagecopyresampled($newImage, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Save resized image
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($newImage, $filePath, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($newImage, $filePath);
            break;
        case IMAGETYPE_GIF:
            imagegif($newImage, $filePath);
            break;
    }

    // Clean up memory
    imagedestroy($source);
    imagedestroy($newImage);

    return true;
}

// Function to delete uploaded file
function deleteUploadedFile($filePath)
{
    if (file_exists($filePath)) {
        return unlink($filePath);
    }
    return false;
}

// Function to get image info for display
function getImageInfo($filePath)
{
    if (!file_exists($filePath)) {
        return false;
    }

    $imageInfo = getimagesize($filePath);
    if (!$imageInfo) {
        return false;
    }

    return [
        'width' => $imageInfo[0],
        'height' => $imageInfo[1],
        'type' => $imageInfo['mime'],
        'size' => filesize($filePath)
    ];
}

/**
 * Create a new notification
 */
function createNotification($pdo, $user_id, $type, $title, $message, $related_id = null)
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, related_id) 
            VALUES (?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$user_id, $type, $title, $message, $related_id]);
    } catch (Exception $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Get unread notifications count for a user
 */
function getUnreadNotificationsCount($pdo, $user_id)
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error getting unread notifications count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get notifications for a user
 */
function getNotifications($pdo, $user_id, $limit = 10, $offset = 0)
{
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user_id, $limit, $offset]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting notifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark notification as read
 */
function markNotificationAsRead($pdo, $notification_id, $user_id)
{
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notification_id, $user_id]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Error marking notification as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all notifications as read for a user
 */
function markAllNotificationsAsRead($pdo, $user_id)
{
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = TRUE 
            WHERE user_id = ?
        ");
        return $stmt->execute([$user_id]);
    } catch (Exception $e) {
        error_log("Error marking all notifications as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all admin users
 */
function getAdminUsers($pdo)
{
    try {
        $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE role = 'admin'");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting admin users: " . $e->getMessage());
        return [];
    }
}

/**
 * Send notification to all admins
 */
function notifyAllAdmins($pdo, $type, $title, $message, $related_id = null)
{
    try {
        $admins = getAdminUsers($pdo);
        $success = true;
        foreach ($admins as $admin) {
            if (!createNotification($pdo, $admin['id'], $type, $title, $message, $related_id)) {
                $success = false;
            }
        }
        return $success;
    } catch (Exception $e) {
        error_log("Error notifying all admins: " . $e->getMessage());
        return false;
    }
}

/**
 * Get employee profile ID from user ID
 */
function getEmployeeProfileId($pdo, $user_id)
{
    try {
        $stmt = $pdo->prepare("SELECT id FROM employee_profiles WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error getting employee profile ID: " . $e->getMessage());
        return false;
    }
}

/**
 * Get employee name from profile ID
 */
function getEmployeeName($pdo, $employee_profile_id)
{
    try {
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM employee_profiles WHERE id = ?");
        $stmt->execute([$employee_profile_id]);
        $result = $stmt->fetch();
        return $result ? $result['first_name'] . ' ' . $result['last_name'] : 'Unknown Employee';
    } catch (Exception $e) {
        error_log("Error getting employee name: " . $e->getMessage());
        return 'Unknown Employee';
    }
}

/**
 * Get pending requests count for admin dashboard
 */
function getPendingRequestsCount($pdo)
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employee_requests WHERE status = 'pending'");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error getting pending requests count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get recent requests for admin dashboard
 */
function getRecentRequests($pdo, $limit = 5)
{
    try {
        $stmt = $pdo->prepare("
            SELECT er.*, ep.first_name, ep.last_name, ep.employee_id
            FROM employee_requests er
            JOIN employee_profiles ep ON er.employee_id = ep.id
            ORDER BY er.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting recent requests: " . $e->getMessage());
        return [];
    }
}

function timeAgo($datetime)
{
    $time = time() - strtotime($datetime);

    if ($time < 60)
        return 'just now';
    if ($time < 3600)
        return floor($time / 60) . 'm ago';
    if ($time < 86400)
        return floor($time / 3600) . 'h ago';
    if ($time < 2628000)
        return floor($time / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}

function sanitize_file_name($filename)
{
    $filename = preg_replace("/[^a-zA-Z0-9\.\-_]/", "", $filename);
    return $filename;
}

/**
 * Log an error message to a file
 */
function logError($message)
{
    $logFile = __DIR__ . '/error.log';
    $date = date('Y-m-d H:i:s');
    $entry = "[$date] ERROR: $message" . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Log debug information to a file
 */
function debugLog($label, $data = null)
{
    $logFile = __DIR__ . '/debug.log';
    $date = date('Y-m-d H:i:s');
    $entry = "[$date] DEBUG: $label";
    if ($data !== null) {
        $entry .= ' | ' . print_r($data, true);
    }
    $entry .= PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Test database connection and return status
 */

?>