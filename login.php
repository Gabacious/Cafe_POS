<?php
// login.php
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'Admin') {
        header("Location: dashboard.php");
    } else {
        header("Location: orders.php");
    }
    exit();
}

// --- DATABASE CONNECTION BLOCK ---
$serverName = "DESKTOP-FSCINGM\\SQLEXPRESS";
$connectionOptions = [
    "Database" => "DLSU",
    "Uid" => "",
    "PWD" => ""
];
$conn = @sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    die("Connection failed: " . print_r(sqlsrv_errors(), true));
}
// --- END CONNECTION BLOCK ---

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        // Query to find active user (Using prepared statement as it's a SELECT/READ operation)
        $sql = "SELECT user_id, password_hash, role, is_active FROM Users WHERE username = ?";
        $params = [&$username];
        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            $login_error = "A database error occurred.";
        } else {
            if ($user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                
                if ($user['is_active'] == 0) {
                    $login_error = "Account is inactive. Please contact an administrator.";
                } 
                // NOTE: Using plain string compare for demo. Use password_verify() in production!
                elseif ($password === $user['password_hash']) {
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $username;
                    $_SESSION['user_role'] = $user['role'];

                    if ($user['role'] === 'Admin') {
                        header("Location: dashboard.php");
                    } else {
                        header("Location: orders.php");
                    }
                    exit();
                } else {
                    $login_error = "Invalid username or password.";
                }
            } else {
                $login_error = "Invalid username or password.";
            }
            sqlsrv_free_stmt($stmt);
        }
    } else {
        $login_error = "Please enter both username and password.";
    }
}

sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kapihan ni Boss G POS - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #5d4037; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-container { width: 100%; max-width: 400px; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); }
        .logo { max-width: 150px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="login-container text-center">
        <img src="logo_bossg.png" alt="Kapihan ni Boss G Logo" class="logo">
        <h4 class="mb-4">POS System Login</h4>
        <?php if ($login_error): ?>
            <div class="alert alert-danger" role="alert"><?php echo $login_error; ?></div>
        <?php endif; ?>
        <form method="POST" action="login.php">
            <div class="mb-3">
                <input type="text" class="form-control" name="username" placeholder="Username" required>
            </div>
            <div class="mb-3">
                <input type="password" class="form-control" name="password" placeholder="Password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100 bg-cafe border-0">Log In</button>
        </form>
    </div>
</body>
</html>