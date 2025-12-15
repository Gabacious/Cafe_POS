<?php
// api/clear_override.php
session_start();
header('Content-Type: application/json');

if (isset($_SESSION['temp_admin_access'])) {
    unset($_SESSION['temp_admin_access']);
}

echo json_encode(['message' => 'Admin override cleared.']);
?>