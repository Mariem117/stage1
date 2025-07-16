<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'employee_management');
define('DB_USER', 'root');
define('DB_PASS', '');

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pdo->rollBack();
    logError('Database error in profile.php: ' . $e->getMessage());
    $error = 'An unexpected error occurred. Please try again later.';
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
?>
<?php
// Enhanced file upload handler function for config.php
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

    // Validate file size (5MB max)
    $maxSize = 5 * 1024 * 1024; // 5MB in bytes
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'File size exceeds 5MB limit'];
    }

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $fileType = $file['type'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

    if (!in_array($fileType, $allowedTypes) || !in_array($fileExtension, $allowedExtensions)) {
        return ['success' => false, 'error' => 'Invalid file type. Only JPEG, PNG, and GIF files are allowed'];
    }

    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $fileExtension;
    $targetPath = $uploadDir . $filename;

    // Move uploaded file to target directory
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Optional: Resize image if needed (requires GD extension)
        resizeImage($targetPath, 800, 600); // Max width: 800px, Max height: 600px

        return ['success' => true, 'path' => $targetPath];
    } else {
        return ['success' => false, 'error' => 'Failed to move uploaded file'];
    }
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
?>