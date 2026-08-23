<?php
// upload_image.php - Handle image uploads for items
session_start();
require_once('../../includes/auth_check.php');

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Check if image file was uploaded
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No image uploaded or upload error']);
    exit();
}

$uploadDir = '../../uploads/';
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
$maxFileSize = 5 * 1024 * 1024; // 5MB

try {
    // Create uploads directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    $file = $_FILES['image'];
    $fileName = $file['name'];
    $fileTmpName = $file['tmp_name'];
    $fileSize = $file['size'];
    $fileType = $file['type'];

    // Validate file type
    if (!in_array($fileType, $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPEG, PNG, and GIF are allowed.');
    }

    // Validate file size
    if ($fileSize > $maxFileSize) {
        throw new Exception('File size too large. Maximum size is 5MB.');
    }

    // Generate unique filename to prevent conflicts
    $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
    $uniqueFileName = uniqid('item_') . '_' . time() . '.' . $fileExtension;
    $targetPath = $uploadDir . $uniqueFileName;

    // Additional security: verify it's actually an image
    $imageInfo = getimagesize($fileTmpName);
    if ($imageInfo === false) {
        throw new Exception('Invalid image file.');
    }

    // Move uploaded file to target directory
    if (move_uploaded_file($fileTmpName, $targetPath)) {
        echo json_encode([
            'success' => true,
            'filename' => $uniqueFileName,
            'message' => 'Image uploaded successfully'
        ]);
    } else {
        throw new Exception('Failed to move uploaded file');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>

