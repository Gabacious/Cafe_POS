<?php
// reports.php (Updated with Daily, Weekly, and Monthly Filtering)
session_start();

// --- ACCESS CONTROL (Admin only) ---
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
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

// --- 1. HANDLE INPUT AND FILTERS ---
$report_period = $_GET['period'] ?? 'daily'; // Default to 'daily'
$filter_date = $_GET['filter_date'] ?? date('Y-m-d'); // Default date for custom/daily view
$error_message = '';

$where_clause = "";
$report_title_suffix = "";
$params = []; // For parameterized queries

// Determine the SQL WHERE clause and title based on the selected period
switch ($report_period) {
    case 'weekly':
        // MS-SQL: Filter for the current week (DATEDIFF(wk, 0, GETDATE()) gets the week number starting from 1900-01-01)
        $where_clause = "WHERE o.order_date >= DATEADD(wk, DATEDIFF(wk, 0, GETDATE()), 0) 
                         AND o.order_date < DATEADD(wk, DATEDIFF(wk, 0, GETDATE()), 7)";
        $report_title_suffix = " (This Week)";
        break;

    case 'monthly':
        // MS-SQL: Filter for the current month and year
        $where_clause = "WHERE MONTH(o.order_date) = MONTH(GETDATE()) 
                         AND YEAR(o.order_date) = YEAR(GETDATE())";
        $report_title_suffix = " (This Month)";
        break;

    case 'daily':
    default:
        // Filter for a specific day (uses the date picker value or today's date)
        $where_clause = "WHERE CAST(o.order_date AS DATE) = ?";
        $params[] = $filter_date;
        $report_title_suffix = " (".date('F j, Y', strtotime($filter_date)).")";
        $report_period = 'daily'; // Ensure 'daily' is set as the active button
        break;
}


// --- 2. FETCH SUMMARY DATA ---
$sql_summary = "
    SELECT 
        COUNT(order_id) AS total_orders, 
        SUM(total_amount) AS total_sales,
        SUM(discount_amount) AS total_discount
    FROM Orders o
    $where_clause";

$stmt_summary = sqlsrv_query($conn, $sql_summary, $params);

$summary = [
    'total_orders' => 0,
    'total_sales' => 0.00,
    'total_discount' => 0.00
];

if ($stmt_summary) {
    if ($row = sqlsrv_fetch_array($stmt_summary, SQLSRV_FETCH_ASSOC)) {
        $summary['total_orders'] = $row['total_orders'] ?? 0;
        // Use a ternary operator to handle NULL values from SUM
        $summary['total_sales'] = $row['total_sales'] ?? 0.00;
        $summary['total_discount'] = $row['total_discount'] ?? 0.00;
    }
    sqlsrv_free_stmt($stmt_summary);
} else {
    $error_message = "Error fetching summary data: " . print_r(sqlsrv_errors(), true);
}


// --- 3. FETCH DETAILED ORDER LIST ---
$order_list = [];
$sql_orders = "
    SELECT TOP 50
        o.order_id, 
        o.order_date, 
        o.total_amount, 
        u.username AS cashier_name
    FROM Orders o
    INNER JOIN Users u ON o.cashier_id = u.user_id
    $where_clause
    ORDER BY o.order_date DESC";

$stmt_orders = sqlsrv_query($conn, $sql_orders, $params);

if ($stmt_orders) {
    while ($row = sqlsrv_fetch_array($stmt_orders, SQLSRV_FETCH_ASSOC)) {
        // Only format date if it's a DateTime object (MS-SQL driver handles this)
        if ($row['order_date'] instanceof DateTime) {
            $row['order_date'] = $row['order_date']->format('Y-m-d H:i:s');
        }
        $order_list[] = $row;
    }
    sqlsrv_free_stmt($stmt_orders);
} else {
    $error_message .= "<br>Error fetching order list: " . print_r(sqlsrv_errors(), true);
}


// --- 4. FETCH SALES BY CATEGORY REPORT ---
$sales_by_category = [];
$sql_category_sales = "
    SELECT 
        p.category,
        SUM(oi.quantity * oi.unit_price) AS category_subtotal
    FROM Orders o
    INNER JOIN Order_Items oi ON o.order_id = oi.order_id
    INNER JOIN Products p ON oi.product_id = p.product_id
    $where_clause
    GROUP BY p.category
    ORDER BY category_subtotal DESC";

$stmt_category = sqlsrv_query($conn, $sql_category_sales, $params);

if ($stmt_category) {
    while ($row = sqlsrv_fetch_array($stmt_category, SQLSRV_FETCH_ASSOC)) {
        $sales_by_category[] = $row;
    }
    sqlsrv_free_stmt($stmt_category);
} else {
    $error_message .= "<br>Error fetching category sales: " . print_r(sqlsrv_errors(), true);
}

// --- 5. FETCH TOP SELLING PRODUCTS ---
$top_products = [];
$sql_top_products = "
    SELECT TOP 10
        p.name, 
        SUM(oi.quantity) AS total_quantity_sold
    FROM Orders o
    INNER JOIN Order_Items oi ON o.order_id = oi.order_id
    INNER JOIN Products p ON oi.product_id = p.product_id
    $where_clause
    GROUP BY p.name 
    ORDER BY total_quantity_sold DESC";

$stmt_top_products = sqlsrv_query($conn, $sql_top_products, $params);

if ($stmt_top_products) {
    while ($row = sqlsrv_fetch_array($stmt_top_products, SQLSRV_FETCH_ASSOC)) {
        $top_products[] = $row;
    }
    sqlsrv_free_stmt($stmt_top_products);
} else {
    $error_message .= "<br>Error fetching top selling products (p.name): " . print_r(sqlsrv_errors(), true);
}


// --- 6. FETCH SALES BY CASHIER ---
$sales_by_cashier = [];
$sql_cashier_sales = "
    SELECT 
        u.username AS cashier_name,
        COUNT(o.order_id) AS total_orders,
        SUM(o.total_amount) AS total_sales
    FROM Orders o
    INNER JOIN Users u ON o.cashier_id = u.user_id
    $where_clause
    GROUP BY u.username
    ORDER BY total_sales DESC";

$stmt_cashier_sales = sqlsrv_query($conn, $sql_cashier_sales, $params);

if ($stmt_cashier_sales) {
    while ($row = sqlsrv_fetch_array($stmt_cashier_sales, SQLSRV_FETCH_ASSOC)) {
        $sales_by_cashier[] = $row;
    }
    sqlsrv_free_stmt($stmt_cashier_sales);
} else {
    $error_message .= "<br>Error fetching sales by cashier: " . print_r(sqlsrv_errors(), true);
}


sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kapihan ni Boss G - Sales Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .bg-cafe { background-color: #5d4037 !important; }
        .navbar-logo { height: 30px; margin-right: 8px; vertical-align: middle; }
        .card-icon { font-size: 2.5rem; color: #5d4037; }
        .btn-period.active { background-color: #6f4e37; color: white; border-color: #6f4e37; }
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
                    <li class="nav-item"><a class="nav-link active" href="reports.php">Reports</a></li>
                    <li class="nav-item"><a class="nav-link" href="users.php">Users & Settings</a></li>
                </ul>
                <a href="logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h3 class="mb-4 text-secondary">Detailed Sales Reports <?= $report_title_suffix; ?></h3>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Database Error:</strong> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center mb-3">
                    <label class="form-label fw-bold me-3 mb-0">Quick View:</label>
                    <a href="?period=daily" class="btn btn-outline-secondary btn-period me-2 <?= $report_period === 'daily' ? 'active' : ''; ?>">Daily</a>
                    <a href="?period=weekly" class="btn btn-outline-secondary btn-period me-2 <?= $report_period === 'weekly' ? 'active' : ''; ?>">Weekly</a>
                    <a href="?period=monthly" class="btn btn-outline-secondary btn-period me-4 <?= $report_period === 'monthly' ? 'active' : ''; ?>">Monthly</a>
                </div>

                <form action="reports.php" method="GET" class="row align-items-center">
                    <input type="hidden" name="period" value="daily">
                    <div class="col-md-3">
                        <label for="filter_date" class="form-label">Select Specific Day:</label>
                        <input type="date" class="form-control" id="filter_date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>" max="<?php echo date('Y-m-d'); ?>" <?= $report_period !== 'daily' ? 'disabled' : ''; ?>>
                    </div>
                    <div class="col-md-3 mt-3 mt-md-0">
                        <button type="submit" class="btn btn-primary" <?= $report_period !== 'daily' ? 'disabled' : ''; ?>>Load Date</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center shadow border-success">
                    <div class="card-body">
                        <i class="bi bi-cash-stack card-icon"></i>
                        <h5 class="card-title mt-2 text-success">Net Sales</h5>
                        <p class="card-text fs-3 fw-bold">₱<?php echo number_format($summary['total_sales'], 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center shadow border-primary">
                    <div class="card-body">
                        <i class="bi bi-basket card-icon"></i>
                        <h5 class="card-title mt-2 text-primary">Total Orders</h5>
                        <p class="card-text fs-3 fw-bold"><?php echo number_format($summary['total_orders']); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center shadow border-danger">
                    <div class="card-body">
                        <i class="bi bi-tag card-icon"></i>
                        <h5 class="card-title mt-2 text-danger">Total Discount Given</h5>
                        <p class="card-text fs-3 fw-bold">₱<?php echo number_format($summary['total_discount'], 2); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header bg-info text-white"><i class="bi bi-pie-chart-fill me-2"></i>Sales by Category</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th class="text-end">Subtotal (₱)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($sales_by_category) > 0): ?>
                                        <?php foreach ($sales_by_category as $sale): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($sale['category']); ?></td>
                                                <td class="text-end fw-bold">₱<?php echo number_format($sale['category_subtotal'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="2" class="text-center text-muted">No sales data for this date.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header bg-warning text-dark"><i class="bi bi-star-fill me-2"></i>Top Selling Products</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product Name</th>
                                        <th class="text-end">Quantity Sold</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($top_products) > 0): ?>
                                        <?php foreach ($top_products as $index => $product): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                                <td class="text-end fw-bold"><?php echo number_format($product['total_quantity_sold']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" class="text-center text-muted">No products sold on this date.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header bg-primary text-white"><i class="bi bi-person-badge-fill me-2"></i>Sales by Cashier</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Cashier</th>
                                        <th class="text-end">Total Orders</th>
                                        <th class="text-end">Total Sales (₱)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($sales_by_cashier) > 0): ?>
                                        <?php foreach ($sales_by_cashier as $cashier): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($cashier['cashier_name']); ?></td>
                                                <td class="text-end"><?php echo number_format($cashier['total_orders']); ?></td>
                                                <td class="text-end fw-bold">₱<?php echo number_format($cashier['total_sales'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" class="text-center text-muted">No cashier activity found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header bg-secondary text-white"><i class="bi bi-list-columns me-2"></i>Recent Orders (Top 50)</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Time</th>
                                        <th>Cashier</th>
                                        <th class="text-end">Total Amount (₱)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($order_list) > 0): ?>
                                        <?php foreach ($order_list as $order): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($order['order_id']); ?></td>
                                                <td><?php echo date('h:i:s A', strtotime($order['order_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($order['cashier_name']); ?></td>
                                                <td class="text-end fw-bold">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted">No orders found for this period.</td></tr>
                                    <?php endif; ?>
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