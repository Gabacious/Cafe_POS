<?php
// dashboard.php (Updated with Role Check)
session_start();

// --- DATABASE CONNECTION BLOCK ---
$serverName = "DESKTOP-FSCINGM\\SQLEXPRESS";
$connectionOptions = [
    "Database" => "DLSU",
    "Uid" => "",
    "PWD" => ""
];
$conn = @sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    die("Database Connection Error. Check connection settings.");
}
// --- END CONNECTION BLOCK ---

// Check if logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
    // Redirect to login or orders page if not admin
    header("Location: " . ($_SESSION['user_role'] === 'Cashier' ? "orders.php" : "login.php"));
    sqlsrv_close($conn);
    exit();
}

// --- Dashboard data fetching logic would go here ---
$dashboard_data = ['example' => 'data']; // Placeholder

sqlsrv_close($conn); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .bg-cafe { background-color: #6C544B !important; } 
        .navbar-logo { height: 30px; margin-right: 8px; vertical-align: middle; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-cafe shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php">
                <img src="logo_bossg.png" alt="Kapihan ni Boss G Logo" class="navbar-logo">
                Kapihan ni Boss G (Admin)
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="orders.php">Orders</a></li> 
                        <li class="nav-item"><a class="nav-link" href="product_management.php">Products</a></li> 
                        <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
                        <li class="nav-item"><a class="nav-link" href="users.php">Users & Settings</a></li>
                    <?php endif; ?>
                </ul>
                <a href="logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mt-5">
        <h2>Admin Dashboard</h2>
        <p>Welcome to the Dashboard, <?php echo htmlspecialchars($_SESSION['username']); ?>!</p>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card text-center p-3">
                    <h4>Total Sales Today</h4>
                    <p class="fs-2">₱1,250.00</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center p-3">
                    <h4>Orders Completed</h4>
                    <p class="fs-2">25</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center p-3">
                    <h4>Products Available</h4>
                    <p class="fs-2">45</p>
                </div>
            </div>
        </div>
        </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>