<?php
// api/request_override.php
session_start();
header('Content-Type: application/json');

// --- DATABASE CONNECTION BLOCK ---
$serverName = "DESKTOP-FSCINGM\\SQLEXPRESS";
$connectionOptions = [
    "Database" => "DLSU",
    "Uid" => "",
    "PWD" => ""
];
$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    // Return a JSON error response if connection fails
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.', 'details' => print_r(sqlsrv_errors(), true)]);
    exit();
}
// --- END CONNECTION BLOCK ---

// 1. Check for POST request and get JSON data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['username']) || !isset($data['password'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Missing username or password.']);
    exit();
}

$username = $data['username'];
$password = $data['password']; // Note: Plaintext check for this simple project
$output = ['success' => false, 'message' => null, 'error' => null];

// 2. Query the database for the Admin user
$sql = "SELECT user_id, password_hash, role FROM Users WHERE username = ? AND role = 'Admin' AND is_active = 1";
$params = [$username];
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    // Database query error
    $output['error'] = 'Database query error.';
    http_response_code(500);
} else {
    if (sqlsrv_fetch($stmt)) {
        // Get the stored (plaintext) password hash
        $stored_password = sqlsrv_get_field($stmt, 1); // password_hash is the second field (index 1)
        $role = sqlsrv_get_field($stmt, 2); // role is the third field (index 2)

        // 3. Verify credentials (Plaintext comparison for simplicity)
        if ($password === $stored_password && $role === 'Admin') {
            
            // 4. Authorization successful: Set session flag
            $_SESSION['admin_override_authorized'] = time(); // Set timestamp for override validity (optional)
            
            $output['success'] = true;
            $output['message'] = 'Admin override granted.';
            http_response_code(200); // OK
            
        } else {
            // Password or role mismatch
            $output['error'] = 'Invalid Admin credentials or user is not an Admin.';
            http_response_code(401); // Unauthorized
        }
    } else {
        // Username not found
        $output['error'] = 'Admin username not found.';
        http_response_code(404); // Not Found
    }
    sqlsrv_free_stmt($stmt);
}

sqlsrv_close($conn);
echo json_encode($output);
?>