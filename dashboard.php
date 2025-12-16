<?php
// dashboard.php
session_start();

// --- DATABASE CONNECTION BLOCK (Using settings from users.php) ---
$serverName = "DESKTOP-FSCINGM\\SQLEXPRESS";
$connectionOptions = [
    "Database" => "DLSU",
    "Uid" => "",
    "PWD" => ""
];
$conn = @sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    // Graceful error handling for DB connection
    die("Database Connection Error. Check connection settings.");
}
// --- END CONNECTION BLOCK ---

// Check if logged in and is Admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
    header("Location: " . ($_SESSION['user_role'] === 'Cashier' ? "orders.php" : "login.php"));
    sqlsrv_close($conn);
    exit();
}

// --- KPI FETCHING LOGIC ---

// Initialize KPI variables
$total_sales_today = '0.00';
$total_orders_today = 0;
$best_seller_name = 'N/A';
$total_items = 0;

// Helper function to safely execute a query and fetch the first field result
function fetch_kpi($conn, $sql, $default_value) {
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt && sqlsrv_fetch($stmt)) {
        // sqlsrv_get_field(stmt, 0) fetches the value of the first column
        $value = sqlsrv_get_field($stmt, 0);
        sqlsrv_free_stmt($stmt);
        // Return the fetched value or the default if null/empty
        return $value ?? $default_value;
    }
    return $default_value;
}

// --- SQL DATE CALCULATIONS (FOR SAFER COMPARISON) ---
// Get today's date (start of day) and tomorrow's date (start of day)
// This ensures the comparison works reliably without time zone issues.
$today_start = date('Y-m-d 00:00:00');
$tomorrow_start = date('Y-m-d 00:00:00', strtotime('+1 day'));


// 1. Fetch Total Sales Today
$sql_sales = "
    SELECT SUM(total_amount) 
    FROM Orders 
    WHERE order_date >= '$today_start' AND order_date < '$tomorrow_start'
";
$raw_sales = fetch_kpi($conn, $sql_sales, 0);
$total_sales_today = number_format($raw_sales, 2);

// 2. Fetch Total Orders Today
$sql_orders = "
    SELECT COUNT(order_id) 
    FROM Orders 
    WHERE order_date >= '$today_start' AND order_date < '$tomorrow_start'
";
$total_orders_today = fetch_kpi($conn, $sql_orders, 0);


// 3. Fetch Best Seller Today (By Quantity Sold, handling ties)
$best_seller_name = 'N/A';
$best_sellers = [];

$sql_best_seller = "
    SELECT TOP 1 WITH TIES p.name
    FROM Order_Items od -- CORRECTED: changed Order_Details to Order_Items
    JOIN Orders o ON od.order_id = o.order_id
    JOIN Products p ON od.product_id = p.product_id
    WHERE o.order_date >= '$today_start' AND o.order_date < '$tomorrow_start' -- CORRECTED DATE RANGE
    GROUP BY p.name
    ORDER BY SUM(od.quantity) DESC
";

$stmt_best_seller = sqlsrv_query($conn, $sql_best_seller);

if ($stmt_best_seller) {
    while ($row = sqlsrv_fetch_array($stmt_best_seller, SQLSRV_FETCH_ASSOC)) {
        $best_sellers[] = $row['name'];
    }
    sqlsrv_free_stmt($stmt_best_seller);
}

if (!empty($best_sellers)) {
    $count = count($best_sellers);
    if ($count === 1) {
        $best_seller_name = $best_sellers[0];
    } elseif ($count === 2) {
        $best_seller_name = $best_sellers[0] . ' & ' . $best_sellers[1];
    } else {
        // More than 2 items: List all except the last one with commas, then use " & "
        $last_item = array_pop($best_sellers);
        $best_seller_name = implode(', ', $best_sellers) . ' & ' . $last_item;
    }
}


// 4. Fetch Total Items in Catalog (Total products regardless of status/stock)
$sql_items = "
    SELECT COUNT(product_id) 
    FROM Products
";
$total_items = fetch_kpi($conn, $sql_items, 0);


// 5. Fetch Recent Orders (for the table)
$recent_orders = [];
$sql_recent = "
    SELECT TOP 5 o.order_id, u.username AS cashier_name, o.order_date, o.total_amount
    FROM Orders o
    JOIN Users u ON o.cashier_id = u.user_id
    ORDER BY o.order_date DESC
";
$stmt_recent = sqlsrv_query($conn, $sql_recent);

if ($stmt_recent) {
    while ($row = sqlsrv_fetch_array($stmt_recent, SQLSRV_FETCH_ASSOC)) {
        $row['total_amount'] = number_format($row['total_amount'], 2);
        // Safely format the DateTime object for time
        if ($row['order_date'] instanceof DateTime) {
            $row['order_time'] = $row['order_date']->format('h:i A');
        } else {
            $row['order_time'] = 'N/A';
        }
        $recent_orders[] = $row;
    }
}

// Close connection after fetching all data
sqlsrv_close($conn);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .bg-cafe { background-color: #5d4037 !important; } 
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
                    <li class="nav-item"><a class="nav-link" href="orders.php">Orders</a></li> 
                    <li class="nav-item"><a class="nav-link" href="product_management.php">Products</a></li> 
                    <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
                    <li class="nav-item"><a class="nav-link" href="users.php">Users & Settings</a></li>
                </ul>
                <a href="logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>
    
    <div class="container mt-4">
        <h2>Admin Dashboard Overview</h2>
        
        <div class="row mb-4">
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card text-white bg-primary shadow">
                    <div class="card-body">
                        <h5 class="card-title">Total Sales (Today)</h5>
                        <p class="card-text fs-3">₱ <?php echo htmlspecialchars($total_sales_today); ?></p>
                        <i class="bi bi-currency-peso float-end fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card text-white bg-success shadow">
                    <div class="card-body">
                        <h5 class="card-title">Total Orders (Today)</h5>
                        <p class="card-text fs-3"><?php echo htmlspecialchars($total_orders_today); ?></p>
                        <i class="bi bi-basket float-end fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card text-white bg-warning shadow">
                    <div class="card-body">
                        <h5 class="card-title">Best Seller(s) Today</h5>
                        <p class="card-text fs-4" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;" 
                           title="<?php echo htmlspecialchars($best_seller_name); ?>">
                            <?php echo htmlspecialchars($best_seller_name); ?>
                        </p>
                        <i class="bi bi-award float-end fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card text-white bg-info shadow">
                    <div class="card-body">
                        <h5 class="card-title">Total Items (In Catalog)</h5>
                        <p class="card-text fs-3"><?php echo htmlspecialchars($total_items); ?></p> 
                        <i class="bi bi-tag float-end fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12 mb-4">
                <div class="card shadow">
                    <div class="card-header bg-cafe text-white">Recent Orders</div>
                    <div class="card-body">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>User</th>
                                    <th>Time</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_orders)): ?>
                                    <tr><td colspan="4" class="text-center text-muted">No recent orders found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recent_orders as $order): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($order['order_id']); ?></td>
                                        <td><?php echo htmlspecialchars($order['cashier_name']); ?></td>
                                        <td><?php echo htmlspecialchars($order['order_time']); ?></td>
                                        <td>₱ <?php echo htmlspecialchars($order['total_amount']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>