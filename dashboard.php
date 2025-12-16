<?php
// dashboard.php
session_start();

// --- 1. CONFIGURATION CHANGES (NEW) ---
// Set the default timezone to ensure PHP's date() function uses the local time (Asia/Manila).
date_default_timezone_set('Asia/Manila');
// --- END CONFIGURATION CHANGES ---

// --- ACCESS CONTROL ---
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
// --- END ACCESS CONTROL ---

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

// --- 2. DATE AND FILTER SETUP (UPDATED) ---
$today_date = date('Y-m-d');
$start_of_day = $today_date . ' 00:00:00'; 
$next_day_date = date('Y-m-d', strtotime($today_date . ' +1 day')); 
$end_of_day = $next_day_date . ' 00:00:00'; 

// The robust, time-inclusive WHERE clause for today's sales (no parameters)
$today_where_clause = "WHERE o.order_date >= '$start_of_day' AND o.order_date < '$end_of_day'";
$cashier_filter = "";

$user_role = $_SESSION['user_role'] ?? 'Cashier'; // Default to Cashier if role is missing

if ($user_role == 'Cashier') {
    // If it's a Cashier, filter for their sales only
    $user_id = $_SESSION['user_id'];
    $cashier_filter = " AND o.cashier_id = '$user_id'";
    $dashboard_title = "My Shift Summary: " . date('F j, Y');
} else {
    // If it's an Admin, show all sales
    $dashboard_title = "Today's Total Sales: " . date('F j, Y');
}

// --- 3. FETCH TODAY'S SUMMARY DATA (UPDATED SQL) ---
$sql_summary = "
    SELECT 
        COUNT(o.order_id) AS total_orders, 
        SUM(o.total_amount) AS total_sales,
        SUM(o.discount_amount) AS total_discount
    FROM Orders o
    $today_where_clause
    $cashier_filter"; // Append the cashier filter here

// Use an empty array for parameters since we used string concatenation
$stmt_summary = sqlsrv_query($conn, $sql_summary, []);

$summary = [
    'total_orders' => 0,
    'total_sales' => 0.00,
    'total_discount' => 0.00
];

if ($stmt_summary) {
    if ($row = sqlsrv_fetch_array($stmt_summary, SQLSRV_FETCH_ASSOC)) {
        $summary['total_orders'] = $row['total_orders'] ?? 0;
        $summary['total_sales'] = $row['total_sales'] ?? 0.00;
        $summary['total_discount'] = $row['total_discount'] ?? 0.00;
    }
    sqlsrv_free_stmt($stmt_summary);
}

// --- 4. FETCH ALL-TIME SUMMARY (e.g., for comparison or overall stats) ---
$sql_all_time = "
    SELECT 
        COUNT(order_id) AS all_time_orders, 
        SUM(total_amount) AS all_time_sales
    FROM Orders"; 

// If the user is a Cashier, filter their all-time sales
if ($user_role == 'Cashier') {
    $sql_all_time .= " WHERE cashier_id = '$user_id'";
}

$stmt_all_time = sqlsrv_query($conn, $sql_all_time, []);

$all_time_summary = [
    'all_time_orders' => 0,
    'all_time_sales' => 0.00
];

if ($stmt_all_time) {
    if ($row = sqlsrv_fetch_array($stmt_all_time, SQLSRV_FETCH_ASSOC)) {
        $all_time_summary['all_time_orders'] = $row['all_time_orders'] ?? 0;
        $all_time_summary['all_time_sales'] = $row['all_time_sales'] ?? 0.00;
    }
    sqlsrv_free_stmt($stmt_all_time);
}

sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kapihan ni Boss G - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .bg-cafe { background-color: #5d4037 !important; }
        .navbar-logo { height: 30px; margin-right: 8px; vertical-align: middle; }
        .card-icon { font-size: 2.5rem; color: #5d4037; }
        .card-sales { border-left: 5px solid #198754; }
        .card-orders { border-left: 5px solid #0d6efd; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-cafe shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php">
                <img src="logo_bossg.png" alt="Kapihan ni Boss G Logo" class="navbar-logo">
                Kapihan ni Boss G (<?= htmlspecialchars($user_role); ?>)
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="orders.php">Orders</a></li>
                    <?php if ($user_role == 'Admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="product_management.php">Products</a></li>
                        <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
                        <li class="nav-item"><a class="nav-link" href="users.php">Users & Settings</a></li>
                    <?php endif; ?>
                </ul>
                <a href="logout.php" class="btn btn-outline-light">Logout (<?= htmlspecialchars($_SESSION['username'] ?? 'User'); ?>)</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h3 class="mb-4 text-secondary"><?= htmlspecialchars($dashboard_title); ?></h3>
        
        <div class="row mb-5">
            <div class="col-md-4">
                <div class="card text-center shadow card-sales">
                    <div class="card-body">
                        <i class="bi bi-cash-stack card-icon text-success"></i>
                        <h5 class="card-title mt-2 text-success">Net Sales Today</h5>
                        <p class="card-text fs-3 fw-bold">₱<?php echo number_format($summary['total_sales'], 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center shadow card-orders">
                    <div class="card-body">
                        <i class="bi bi-basket card-icon text-primary"></i>
                        <h5 class="card-title mt-2 text-primary">Total Orders Today</h5>
                        <p class="card-text fs-3 fw-bold"><?php echo number_format($summary['total_orders']); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center shadow border-danger">
                    <div class="card-body">
                        <i class="bi bi-tag card-icon text-danger"></i>
                        <h5 class="card-title mt-2 text-danger">Total Discount Given Today</h5>
                        <p class="card-text fs-3 fw-bold">₱<?php echo number_format($summary['total_discount'], 2); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <h4 class="mb-3 text-secondary">Overall Statistics</h4>
        <div class="row">
            <div class="col-md-6">
                <div class="card text-center shadow h-100">
                    <div class="card-body">
                        <i class="bi bi-bar-chart-fill card-icon text-dark"></i>
                        <h5 class="card-title mt-2">All-Time Total Sales (<?= $user_role == 'Cashier' ? 'My Sales' : 'System Total'; ?>)</h5>
                        <p class="card-text fs-3 fw-bold text-dark">₱<?php echo number_format($all_time_summary['all_time_sales'], 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card text-center shadow h-100">
                    <div class="card-body">
                        <i class="bi bi-journal-check card-icon text-secondary"></i>
                        <h5 class="card-title mt-2">All-Time Total Orders (<?= $user_role == 'Cashier' ? 'My Orders' : 'System Total'; ?>)</h5>
                        <p class="card-text fs-3 fw-bold text-secondary"><?php echo number_format($all_time_summary['all_time_orders']); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 p-3 bg-light rounded text-center">
            <p class="mb-0 text-muted">Data accurate as of: <?php echo date('Y-m-d h:i:s A'); ?></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>