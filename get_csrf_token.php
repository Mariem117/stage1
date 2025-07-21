<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');
echo json_encode(['csrf_token' => generateCSRFToken()]);
?>