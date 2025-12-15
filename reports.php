<?php
// reports.php (CORRECTED AGAIN: Changed 'ProductName' to 'name')
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
$filter_date = $_GET['filter_date'] ?? date('Y-m-d'); // Default to today
$error_message = '';

// --- 2. FETCH SUMMARY DATA ---
$sql_summary = "
    SELECT 
        COUNT(order_id) AS total_orders, 
        SUM(total_amount) AS total_sales,
        SUM(discount_amount) AS total_discount
    FROM Orders 
    WHERE CAST(order_date AS DATE) = ?";

$params_summary = [&$filter_date];
$stmt_summary = sqlsrv_query($conn, $sql_summary, $params_summary);

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
    WHERE CAST(o.order_date AS DATE) = ?
    ORDER BY o.order_date DESC";

$params_orders = [&$filter_date];
$stmt_orders = sqlsrv_query($conn, $sql_orders, $params_orders);

if ($stmt_orders) {
    while ($row = sqlsrv_fetch_array($stmt_orders, SQLSRV_FETCH_ASSOC)) {
        $row['order_date'] = $row['order_date']->format('Y-m-d H:i:s');
        $order_list[] = $row;
    }
    sqlsrv_free_stmt($stmt_orders);
} else {
    $error_message .= "<br>Error fetching order list: " . print_r(sqlsrv_errors(), true);
}


// --- 4. FETCH SALES BY CATEGORY REPORT (CHECKING name) ---
$sales_by_category = [];
$sql_category_sales = "
    SELECT 
        p.category,
        SUM(oi.quantity * oi.unit_price) AS category_subtotal
    FROM Orders o
    INNER JOIN Order_Items oi ON o.order_id = oi.order_id
    INNER JOIN Products p ON oi.product_id = p.product_id
    WHERE CAST(o.order_date AS DATE) = ?
    GROUP BY p.category
    ORDER BY category_subtotal DESC";

$params_category = [&$filter_date];
$stmt_category = sqlsrv_query($conn, $sql_category_sales, $params_category);

if ($stmt_category) {
    while ($row = sqlsrv_fetch_array($stmt_category, SQLSRV_FETCH_ASSOC)) {
        $sales_by_category[] = $row;
    }
    sqlsrv_free_stmt($stmt_category);
} else {
    $error_message .= "<br>Error fetching category sales: " . print_r(sqlsrv_errors(), true);
}

// --- 5. NEW: FETCH TOP SELLING PRODUCTS (CORRECTED TO p.name) ---
$top_products = [];
$sql_top_products = "
    SELECT TOP 10
        p.name, -- NEW CORRECTED COLUMN NAME
        SUM(oi.quantity) AS total_quantity_sold
    FROM Orders o
    INNER JOIN Order_Items oi ON o.order_id = oi.order_id
    INNER JOIN Products p ON oi.product_id = p.product_id
    WHERE CAST(o.order_date AS DATE) = ?
    GROUP BY p.name -- NEW CORRECTED COLUMN NAME
    ORDER BY total_quantity_sold DESC";

$params_top_products = [&$filter_date];
$stmt_top_products = sqlsrv_query($conn, $sql_top_products, $params_top_products);

if ($stmt_top_products) {
    while ($row = sqlsrv_fetch_array($stmt_top_products, SQLSRV_FETCH_ASSOC)) {
        // We use 'name' as the array key for display
        $top_products[] = $row;
    }
    sqlsrv_free_stmt($stmt_top_products);
} else {
    $error_message .= "<br>Error fetching top selling products (p.name): " . print_r(sqlsrv_errors(), true);
}


// --- 6. NEW: FETCH SALES BY CASHIER ---
$sales_by_cashier = [];
$sql_cashier_sales = "
    SELECT 
        u.username AS cashier_name,
        COUNT(o.order_id) AS total_orders,
        SUM(o.total_amount) AS total_sales
    FROM Orders o
    INNER JOIN Users u ON o.cashier_id = u.user_id
    WHERE CAST(o.order_date AS DATE) = ?
    GROUP BY u.username
    ORDER BY total_sales DESC";

$params_cashier_sales = [&$filter_date];
$stmt_cashier_sales = sqlsrv_query($conn, $sql_cashier_sales, $params_cashier_sales);

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
    <title>Kapihan ni Boss G POS - Sales Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .bg-cafe { background-color: #5d4037 !important; }
        .navbar-logo { height: 30px; margin-right: 8px; vertical-align: middle; }
        .card-icon { font-size: 2.5rem; color: #5d4037; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-cafe shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php">
                <img src="logo_bossg.png" alt="Kapihan ni Boss G Logo" class="navbar-logo">
                Kapihan ni Boss G POS (Admin)
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
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
        <h3 class="mb-4 text-secondary">Detailed Sales Reports</h3>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Database Error:</strong> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form action="reports.php" method="GET" class="row align-items-end">
                    <div class="col-md-3">
                        <label for="filter_date" class="form-label">Select Date:</label>
                        <input type="date" class="form-control" id="filter_date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">Load Report</button>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="mb-0 fw-bold fs-5">Report for: <span class="text-cafe"><?php echo date('F j, Y', strtotime($filter_date)); ?></span></p>
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
                    <div class="card-header bg-warning text-dark"><i class="bi bi-star-fill me-2"></i>Top 10 Selling Products</div>
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
                                        <tr><td colspan="4" class="text-center text-muted">No orders found for <?php echo date('F j, Y', strtotime($filter_date)); ?>.</td></tr>
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