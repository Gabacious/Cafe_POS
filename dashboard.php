<?php
// dashboard.php
session_start();
// Ensure user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
    header("Location: login.php");
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

$today = date('Y-m-d');
$dashboardData = [
    'total_sales' => 0.00,
    'total_orders' => 0,
    'top_seller' => 'N/A',
    'daily_sales_trend' => []
];

// --- 1. Total Sales and Orders (Today) ---
$sql_today = "
    SELECT 
        SUM(total_amount) AS total_sales, 
        COUNT(order_id) AS total_orders 
    FROM Orders 
    WHERE CAST(order_date AS DATE) = ?";
$params_today = [&$today];
$stmt_today = sqlsrv_query($conn, $sql_today, $params_today);

if ($stmt_today && ($row = sqlsrv_fetch_array($stmt_today, SQLSRV_FETCH_ASSOC))) {
    $dashboardData['total_sales'] = $row['total_sales'] ?? 0.00;
    $dashboardData['total_orders'] = $row['total_orders'] ?? 0;
}

// --- 2. Top Seller (Today) ---
$sql_top_seller = "
    SELECT TOP 1 
        i.item_name_at_sale, 
        SUM(i.quantity) AS total_quantity 
    FROM Order_Items i
    INNER JOIN Orders o ON i.order_id = o.order_id
    WHERE CAST(o.order_date AS DATE) = ?
    GROUP BY i.item_name_at_sale
    ORDER BY total_quantity DESC";
$params_seller = [&$today];
$stmt_seller = sqlsrv_query($conn, $sql_top_seller, $params_seller);

if ($stmt_seller && ($row = sqlsrv_fetch_array($stmt_seller, SQLSRV_FETCH_ASSOC))) {
    $dashboardData['top_seller'] = $row['item_name_at_sale'];
}

// --- 3. Daily Sales Trend (Last 7 Days) ---
$sql_trend = "
    SELECT 
        CAST(order_date AS DATE) AS sale_date,
        SUM(total_amount) AS daily_sales,
        COUNT(order_id) AS daily_orders
    FROM Orders
    WHERE order_date >= DATEADD(day, -6, CAST(GETDATE() AS DATE))
    GROUP BY CAST(order_date AS DATE)
    ORDER BY sale_date";

$stmt_trend = sqlsrv_query($conn, $sql_trend);
$trend_map = [];

if ($stmt_trend) {
    while ($row = sqlsrv_fetch_array($stmt_trend, SQLSRV_FETCH_ASSOC)) {
        $date_key = $row['sale_date']->format('Y-m-d');
        $trend_map[$date_key] = [
            'sales' => $row['daily_sales'],
            'orders' => $row['daily_orders']
        ];
    }
}

// Generate the 7-day trend array for JS
$last_sales = 0.00;
for ($i = 6; $i >= 0; $i--) {
    $date_obj = new DateTime("-$i days");
    $date_str = $date_obj->format('Y-m-d');
    $display_date = $date_obj->format('M d');
    
    $current_sales = $trend_map[$date_str]['sales'] ?? 0.00;
    $current_orders = $trend_map[$date_str]['orders'] ?? 0;
    
    $change = 'flat';
    if ($last_sales > 0) {
        if ($current_sales > $last_sales) $change = 'up';
        if ($current_sales < $last_sales) $change = 'down';
    }
    
    $dashboardData['daily_sales_trend'][] = [
        'date' => $display_date,
        'sales' => $current_sales,
        'orders' => $current_orders,
        'change' => $change
    ];
    
    $last_sales = $current_sales;
}

sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kapihan ni Boss G POS - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .bg-cafe { background-color: #5d4037 !important; }
        .card-icon { font-size: 3rem; color: #5d4037; }
        .info-card { transition: transform 0.2s; }
        .info-card:hover { transform: translateY(-5px); box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15) !important; }
        .navbar-logo { height: 30px; margin-right: 8px; vertical-align: middle; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-cafe shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="#">
                <img src="logo_bossg.png" alt="Kapihan ni Boss G Logo" class="navbar-logo">
                Kapihan ni Boss G POS (Admin)
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link active" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="orders.php">Orders</a></li>
                    <li class="nav-item"><a class="nav-link" href="product_management.php">Products</a></li>
                    <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
                    <li class="nav-item"><a class="nav-link" href="user_settings.php">Users & Settings</a></li>
                </ul>
                <a href="logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h3 class="mb-4 text-secondary">Admin Dashboard Overview (Today)</h3>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card info-card text-center shadow">
                    <div class="card-body">
                        <i class="bi bi-currency-dollar card-icon">₱</i>
                        <h5 class="card-title mt-2 text-primary">Total Sales (Today)</h5>
                        <p class="card-text fs-3 fw-bold" id="total-sales">₱<?php echo number_format($dashboardData['total_sales'], 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card info-card text-center shadow">
                    <div class="card-body">
                        <i class="bi bi-box card-icon">#</i>
                        <h5 class="card-title mt-2 text-success">Total Orders (Today)</h5>
                        <p class="card-text fs-3 fw-bold" id="total-orders"><?php echo number_format($dashboardData['total_orders']); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card info-card text-center shadow">
                    <div class="card-body">
                        <i class="bi bi-star card-icon">★</i>
                        <h5 class="card-title mt-2 text-warning">Top Seller</h5>
                        <p class="card-text fs-4 fw-bold" id="top-seller"><?php echo htmlspecialchars($dashboardData['top_seller']); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card shadow mb-4">
                    <div class="card-header bg-info text-white">Daily Sales Trend (Last 7 Days)</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Sales (₱)</th>
                                        <th>Orders</th>
                                        <th>Trend</th>
                                    </tr>
                                </thead>
                                <tbody id="daily-sales-table">
                                    <?php foreach ($dashboardData['daily_sales_trend'] as $data): 
                                        $trendIcon = '';
                                        $trendColor = '';
                                        if ($data['change'] === 'up') {
                                            $trendIcon = '▲'; 
                                            $trendColor = 'text-success';
                                        } elseif ($data['change'] === 'down') {
                                            $trendIcon = '▼'; 
                                            $trendColor = 'text-danger';
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($data['date']); ?></td>
                                            <td class="fw-bold">₱<?php echo number_format($data['sales'], 2); ?></td>
                                            <td><?php echo number_format($data['orders']); ?></td>
                                            <td class="<?php echo $trendColor; ?> fw-bold"><?php echo $trendIcon; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>