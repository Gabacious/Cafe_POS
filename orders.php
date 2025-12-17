<?php
// orders.php
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
    die("
        <!DOCTYPE html>
        <html lang='en'><head><title>DB Error</title></head><body>
        <div class='container mt-5'>
            <div class='alert alert-danger'>
                <h4>Database Connection Error</h4>
                <p>Could not connect to SQL Server. Please check your connection settings.</p>
                <pre>" . print_r(sqlsrv_errors(), true) . "</pre>
            </div>
        </div>
        </body></html>
    ");
}
// --- END CONNECTION BLOCK ---

// Check if logged in (any role)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    sqlsrv_close($conn);
    exit();
}

$cashier_id = $_SESSION['user_id'];
$cashier_name = $_SESSION['username']; 
$is_admin = $_SESSION['user_role'] === 'Admin';


// --- CUSTOM STRING ESCAPE FUNCTION---
if (!function_exists('sqlsrv_escape_string')) {
    function sqlsrv_escape_string($string) {
        if (is_null($string)) return ''; 
        return str_replace("'", "''", strval($string));
    }
}
// ------------------------------------


// --- 1. HANDLE ORDER PROCESSING AJAX REQUEST (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'process_order') {
    
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['items']) || !isset($data['totalAmount'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid order data received.']);
        sqlsrv_close($conn);
        exit();
    }

    // Sanitize and prepare data 
    $subtotal = round(floatval($data['subtotal']), 2);
    $discountRate = round(floatval($data['discountRate']), 2);
    $discountAmount = round(floatval($data['discountAmount']), 2);
    $totalAmount = round(floatval($data['totalAmount']), 2);
    $paymentMethod = $data['paymentMethod'] ?? 'Cash';
    
    // String values must be escaped
    $safe_cashier_id = sqlsrv_escape_string($cashier_id);
    $safe_payment_method = sqlsrv_escape_string($paymentMethod);  // Now dynamic!
    $orderId = null;

    if (sqlsrv_begin_transaction($conn) === false) {
        sqlsrv_close($conn);
        http_response_code(500);
        echo json_encode(['error' => 'Could not start database transaction.']);
        exit();
    }

    try {
        // A. INSERT into Orders table - Using String Interpolation
        $sql_order = "INSERT INTO Orders 
            (cashier_id, subtotal, discount_rate, discount_amount, total_amount, payment_method)
            OUTPUT INSERTED.order_id
            VALUES ('$safe_cashier_id', $subtotal, $discountRate, $discountAmount, $totalAmount, '$safe_payment_method')";
        
        $stmt_order = sqlsrv_query($conn, $sql_order); 

        if ($stmt_order === false) {
            throw new Exception("Order header insertion failed: " . print_r(sqlsrv_errors(), true));
        }

        // Retrieve the new order_id
        if (sqlsrv_fetch($stmt_order)) {
            $orderId = sqlsrv_get_field($stmt_order, 0); 
        } else {
            throw new Exception("Could not retrieve inserted Order ID.");
        }
        sqlsrv_free_stmt($stmt_order);

        // B. INSERT into Order_Items table for each item - Using String Interpolation
        foreach ($data['items'] as $item) {
            // Sanitize item-specific string data before interpolation
            $productId = intval($item['productId']);
            $quantity = intval($item['quantity']);
            $unitPrice = round(floatval($item['unitPrice']), 2);
            
            // Escape and quote string fields
            $safe_itemName = sqlsrv_escape_string($item['itemNameAtSale']);
            $safe_selectedSize = sqlsrv_escape_string($item['selectedSize']);
            $safe_selectedTemp = sqlsrv_escape_string($item['selectedTemp']);


            $sql_item = "INSERT INTO Order_Items 
                (order_id, product_id, quantity, unit_price, item_name_at_sale, selected_size, selected_temp) 
                VALUES ($orderId, $productId, $quantity, $unitPrice, '$safe_itemName', '$safe_selectedSize', '$safe_selectedTemp')";
            
            $stmt_item = sqlsrv_query($conn, $sql_item);
            
            if ($stmt_item === false) {
                throw new Exception("Order item insertion failed for product " . $productId . ": " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($stmt_item);
        }

        // C. Commit the transaction
        sqlsrv_commit($conn);
        
        // D. Clear the Admin Override flag if it was used
        if (isset($_SESSION['admin_override_authorized'])) {
            unset($_SESSION['admin_override_authorized']);
        }

        echo json_encode(['success' => true, 'orderId' => $orderId, 'message' => 'Order processed.', 'paymentMethod' => $paymentMethod]);

    } catch (Exception $e) {
        // Rollback on any failure
        sqlsrv_rollback($conn);
        
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Transaction failed.', 'details' => $e->getMessage()]);
    }

    sqlsrv_close($conn);
    exit();
}


// --- 2. HANDLE ADMIN OVERRIDE AJAX REQUEST (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'override') {
    
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['username']) || !isset($data['password'])) {
        http_response_code(400); 
        echo json_encode(['error' => 'Missing username or password.']);
        sqlsrv_close($conn);
        exit();
    }

    $username = $data['username'];
    $password = $data['password'];
    $output = ['success' => false, 'message' => null, 'error' => null];

    
    $sql = "SELECT password_hash, role FROM Users WHERE username = ? AND is_active = 1";
    $params = [$username];
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt && sqlsrv_fetch($stmt)) {
        $stored_password = sqlsrv_get_field($stmt, 0); 
        $user_role = sqlsrv_get_field($stmt, 1); 

        // CRITICAL: Must be an Admin account to grant an override
        if ($user_role === 'Admin' && $password === $stored_password) {
            $_SESSION['admin_override_authorized'] = time();
            $output['success'] = true;
            $output['message'] = 'Admin override granted.';
            $output['timestamp'] = time(); 
            http_response_code(200);
        } else {
            $output['error'] = 'Invalid credentials or user is not an Admin.';
            http_response_code(401);
        }
    } else {
        $output['error'] = 'User not found.';
        http_response_code(404);
    }
    
    if ($stmt) sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    echo json_encode($output);
    exit();
}


// --- 3. HANDLE CLEAR OVERRIDE AJAX REQUEST (GET) ---
if (isset($_GET['action']) && $_GET['action'] === 'clear_override') {
    header('Content-Type: application/json');
    if (isset($_SESSION['admin_override_authorized'])) {
        unset($_SESSION['admin_override_authorized']);
    }
    sqlsrv_close($conn);
    echo json_encode(['success' => true, 'message' => 'Override cleared.']);
    exit();
}


// --- 4. STANDARD PAGE LOAD (GET) ---

$username = $_SESSION['username'];

// --- FETCH MENU DATA for rendering the page ---
$menu_data = [];
$sql_menu = "SELECT product_id AS id, name, category, base_price AS price, 
                   image_url AS image, has_size_options AS isDrink, has_temp_options AS needsTemp 
            FROM Products ORDER BY category, name";
$stmt_menu = sqlsrv_query($conn, $sql_menu);

if ($stmt_menu) {
    while ($row = sqlsrv_fetch_array($stmt_menu, SQLSRV_FETCH_ASSOC)) {
        // Convert SQL Server boolean/bit (which can be 1/0) to PHP boolean
        $row['isDrink'] = (bool)$row['isDrink'];
        $row['needsTemp'] = (bool)$row['needsTemp'];
        $menu_data[] = $row;
    }
    sqlsrv_free_stmt($stmt_menu);
}

// NOTE: Connection is closed *before* we proceed to HTML rendering
sqlsrv_close($conn); 

// PHP for Discount State (for initial load)
$override_time = isset($_SESSION['admin_override_authorized']) ? $_SESSION['admin_override_authorized'] : 0;
$override_active_on_load = ($override_time > 0) && (time() - $override_time < 300); // 5 mins validity
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kapihan ni Boss G - Order Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: sans-serif; background-color: #f8f9fa; }
        .bg-cafe { background-color: #5d4037 !important; } 
        .navbar-logo { height: 30px; margin-right: 8px; vertical-align: middle; }
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; }
        .menu-item { border: 1px solid #ccc; border-radius: 8px; overflow: hidden; text-align: center; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; background: #fff; }
        .menu-item:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); }
        .menu-item img { width: 100%; height: 120px; object-fit: cover; background: #eee; }
        .menu-item-info { padding: 10px; }
        .order-list-item { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px dashed #eee; }
        .order-list-item .name { flex-grow: 1; margin-left: 10px;}
        .order-list-item .price { font-weight: bold; width: 70px; text-align: right; }
        .order-list-item .qty-control { width: 100px; }
        .qr-code-animate { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Receipt Styles for Printing */
        @media print {
            body * { visibility: hidden; }
            #receipt-window, #receipt-window * { visibility: visible; }
            #receipt-window { 
                position: absolute;
                left: 0;
                top: 0;
                width: 300px; 
                font-size: 10px;
                font-family: monospace;
                padding: 10px;
            }
        }
        .receipt-content {
            width: 100%;
            max-width: 300px;
            margin: auto;
            border: 1px dashed #333; 
            padding: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .receipt-header, .receipt-footer { text-align: center; margin-bottom: 5px; }
        .receipt-item-line { display: flex; justify-content: space-between; }
        .receipt-separator { border-top: 1px dashed #000; margin: 5px 0; }
        .receipt-total { font-weight: bold; font-size: 1.1em; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-cafe shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php">
                <img src="logo_bossg.png" alt="Kapihan ni Boss G Logo" class="navbar-logo">
                Kapihan ni Boss G (<?php echo $is_admin ? 'Admin' : 'Cashier'; ?>)
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <?php if ($is_admin): ?>
                    <li class="nav-item"><a class="nav-link active" href="orders.php">Orders</a></li>
                    <li class="nav-item"><a class="nav-link" href="product_management.php">Products</a></li> 
                    <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
                    <li class="nav-item"><a class="nav-link" href="users.php">Users & Settings</a></li>
                    <?php else: ?>
                    <li class="nav-item"><a class="nav-link active" href="orders.php">Orders</a></li>
                    <?php endif; ?>
                </ul>
                <a href="logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>
    <div class="container-fluid mt-3">
        <div class="row">
            <div class="col-md-8">
                <h4 class="mb-3">Menu Items</h4>
                
                <div class="d-flex mb-3 overflow-auto p-2 border rounded bg-white" id="category-tabs">
                    <button class="btn btn-sm btn-outline-secondary me-2 active" data-category="All">All</button>
                </div>

                <div class="menu-grid" id="menu-container">
                    </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Current Order</h5>
                    </div>
                    <div class="card-body">
                        <div id="order-list" style="min-height: 200px;">
                            <p class="text-muted text-center" id="empty-cart-message">Cart is empty.</p>
                        </div>
                        
                        <hr>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal:</span>
                            <span id="subtotal" class="fw-bold">₱0.00</span>
                        </div>
                        
                        <div class="mb-3 p-2 border rounded bg-light">
                            <h6 class="mb-2">Discount Options:</h6>
                            <div class="d-flex flex-wrap">
                                <div class="form-check form-check-inline me-3">
                                    <input class="form-check-input discount-type" type="radio" name="discount-type" id="discount-none" value="0" checked>
                                    <label class="form-check-label" for="discount-none">No Discount</label>
                                </div>
                                <div class="form-check form-check-inline me-3">
                                    <input class="form-check-input discount-type" type="radio" name="discount-type" id="discount-student" value="10">
                                    <label class="form-check-label" for="discount-student">Student (10%)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input discount-type" type="radio" name="discount-type" id="discount-senior-pwd" value="20">
                                    <label class="form-check-label" for="discount-senior-pwd">Senior/PWD (20%)</label>
                                </div>
                            </div>

                            <hr class="my-2">
                            
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span>Discount (<span id="discount-rate-display">0%</span>):</span>
                                <span id="discount-amount" class="fw-bold text-danger">-₱0.00</span>
                            </div>
                            
                            <?php if (!$is_admin): ?>
                                <div id="admin-override-area">
                                    <div id="override-status" class="mt-1 form-text text-danger text-center" style="display:<?php echo $override_active_on_load ? 'block' : 'none'; ?>">**Admin Override Active** (Discounts Authorized)</div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex justify-content-between fs-4 mb-3">
                            <span>**TOTAL:**</span>
                            <span id="total-amount" class="fw-bolder text-success">₱0.00</span>
                        </div>
                        
                        <!-- Payment Method Section -->
                        <div class="mb-3 p-2 border rounded bg-white">
                            <h6 class="mb-2">Payment Method:</h6>
                            <div class="d-flex flex-wrap mb-2">
                                <div class="form-check form-check-inline me-3">
                                    <input class="form-check-input payment-method" type="radio" 
                                           name="payment-method" id="cash" value="Cash" checked>
                                    <label class="form-check-label" for="cash">
                                        💵 <strong>Cash</strong>
                                    </label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input payment-method" type="radio" 
                                           name="payment-method" id="gcash" value="GCash">
                                    <label class="form-check-label" for="gcash">
                                        📱 <strong>GCash</strong>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- QR Code Container (Hidden by default) -->
                            <div id="gcash-qr-container" style="display: none;">
                                <hr class="my-2">
                                <div class="alert alert-info mb-2">
                                    <i class="bi bi-qr-code"></i> Please scan to pay via GCash
                                </div>
                                <div class="text-center">
                                    <!-- YOUR ACTUAL GCASH QR CODE -->
                                    <img src="gcash_qr_code.png" 
                                         id="gcash-qr-code" 
                                         class="img-fluid border p-2 qr-code-animate" 
                                         style="max-width: 200px;"
                                         alt="GCash QR Code">
                                    <p class="mt-2 small text-muted">
                                        Scan with GCash app to pay<br>
                                        <strong>Kapihan ni Boss G</strong><br>
                                        Amount to pay: <strong id="gcash-amount">₱0.00</strong>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Cash Tendered Section (Shows only for Cash) -->
                        <div class="mb-3 p-2 border rounded bg-white" id="cash-tendered-section">
                            <h6 class="mb-2">Cash Tendered:</h6>
                            <input type="number" class="form-control" id="cash-tendered" min="0" placeholder="Enter Cash Amount" value="0.00">
                            <div class="d-flex justify-content-between mt-2">
                                <span>Change:</span>
                                <span id="change-due" class="fw-bolder text-info">₱0.00</span>
                            </div>
                        </div>

                        <button class="btn btn-success w-100 mb-2" id="process-order-btn" disabled>Process Order</button>
                        <button class="btn btn-secondary w-100" id="clear-order-btn">Clear Order</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="modifiersModal" tabindex="-1" aria-labelledby="modifiersModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-cafe text-white">
                    <h5 class="modal-title" id="modifiersModalLabel">Item Customization</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="fw-bold" id="modifier-item-name"></p>
                    <div id="size-options" class="mb-3">
                        <h6>Size:</h6>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="size" id="size-small" value="Small" checked>
                            <label class="form-check-label" for="size-small">Small (Base Price)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="size" id="size-medium" value="Medium">
                            <label class="form-check-label" for="size-medium">Medium (+₱15.00)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="size" id="size-large" value="Large">
                            <label class="form-check-label" for="size-large">Large (+₱30.00)</label>
                        </div>
                    </div>
                    <div id="temp-options" class="mb-3">
                        <h6>Temperature:</h6>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="temp" id="temp-hot" value="Hot" checked>
                            <label class="form-check-label" for="temp-hot">Hot</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="temp" id="temp-iced" value="Iced">
                            <label class="form-check-label" for="temp-iced">Iced</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="add-to-cart-btn">Add to Cart</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="adminOverrideModal" tabindex="-1" aria-labelledby="adminOverrideModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="adminOverrideModalLabel">Admin Override Required</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Enter Administrator credentials to authorize a discount.</p>
                    <div class="mb-3">
                        <input type="text" class="form-control" id="admin-username" placeholder="Admin Username" required>
                    </div>
                    <div class="mb-3">
                        <input type="password" class="form-control" id="admin-password" placeholder="Admin Password" required>
                    </div>
                    <div id="override-modal-status" class="text-danger"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" id="authorize-override-btn">Authorize</button>
                </div>
            </div>
        </div>
    </div>
    
    <div id="receipt-window" style="display:none;"></div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const MENU_DATA = <?php echo json_encode($menu_data); ?>;
        const IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
        const CASHIER_NAME = "<?php echo htmlspecialchars($cashier_name); ?>";
        let adminOverrideTimestamp = <?php echo $override_active_on_load ? $override_time : 0; ?>;
        const OVERRIDE_DURATION_SECONDS = 300; 
        const GCASH_NUMBER = "+639178882123"; // Your GCash number
        
        
        let cart = [];
        let selectedProduct = null;
        let discountRate = 0;
        let paymentMethod = 'Cash'; // Default payment method

        const MODAL_SIZE_PRICES = {
            'Small': 0.00,
            'Medium': 15.00,
            'Large': 30.00
        };

        // --- CALCULATION FUNCTIONS ---
        
        function getActiveDiscountRate() {
            // UNIFIED LOGIC: Admin and Cashier both use radio buttons now.
            
            const seniorPwdRadio = document.getElementById('discount-senior-pwd');
            if (seniorPwdRadio && seniorPwdRadio.checked) return 20; 
            
            const studentRadio = document.getElementById('discount-student');
            if (studentRadio && studentRadio.checked) return 10;
            
            // If none is checked, return 0 (or fall through to the override check for Cashier)
            return 0;
        }
        
        function calculateSubtotal() {
            return cart.reduce((total, item) => total + (item.unitPrice * item.quantity), 0);
        }
        
        function calculateTotals() {
            discountRate = getActiveDiscountRate();
            
            const subtotal = calculateSubtotal();
            const discountAmount = subtotal * (discountRate / 100);
            const total = subtotal - discountAmount;
            
            const cashTenderedInput = document.getElementById('cash-tendered');
            const cashTendered = parseFloat(cashTenderedInput.value) || 0;
            const changeDue = cashTendered - total;

            document.getElementById('subtotal').textContent = `₱${subtotal.toFixed(2)}`;
            document.getElementById('discount-rate-display').textContent = `${discountRate}%`;
            document.getElementById('discount-amount').textContent = `-₱${discountAmount.toFixed(2)}`;
            document.getElementById('total-amount').textContent = `₱${total.toFixed(2)}`;
            
            // Update GCash amount display
            updateGCashQRCode(total);
            
            // FIX: Ensure change due display is always 0 or positive
            document.getElementById('change-due').textContent = `₱${Math.max(0, changeDue).toFixed(2)}`;

            const processBtn = document.getElementById('process-order-btn');
            
            // If payment method is GCash, don't require cash tendered
            if (paymentMethod === 'GCash') {
                processBtn.disabled = cart.length === 0;
                document.getElementById('change-due').textContent = '₱0.00';
            } else {
                // Cash payment: require sufficient cash
                processBtn.disabled = cart.length === 0 || changeDue < -0.01;
            }
            
            const emptyMsg = document.getElementById('empty-cart-message');
            if (emptyMsg) emptyMsg.style.display = cart.length === 0 ? 'block' : 'none';

            renderCart();
            updateOverrideUI(); 
        }
        
        function updateGCashQRCode(totalAmount) {
            // Just update the amount text below the QR code
            document.getElementById('gcash-amount').textContent = `₱${totalAmount.toFixed(2)}`;
        }
        
        function updateOverrideUI() {
            if (IS_ADMIN) return;

            const currentTime = Math.floor(Date.now() / 1000);
            const isOverrideActive = adminOverrideTimestamp > 0 && (currentTime - adminOverrideTimestamp < OVERRIDE_DURATION_SECONDS);
            
            const overrideStatus = document.getElementById('override-status');
            
            if (isOverrideActive) {
                if (overrideStatus) overrideStatus.style.display = 'block';
            } else {
                if (overrideStatus) overrideStatus.style.display = 'none';
                if(adminOverrideTimestamp > 0) adminOverrideTimestamp = 0;
            }
        }

        // --- RENDERING FUNCTIONS ---
        function renderMenu(filterCategory = 'All') {
            const container = document.getElementById('menu-container');
            container.innerHTML = '';
            
            const categories = new Set(['All']);
            
            MENU_DATA.forEach(item => {
                categories.add(item.category);
                
                if (filterCategory === 'All' || item.category === filterCategory) {
                    const itemElement = document.createElement('div');
                    itemElement.className = 'menu-item';
                    itemElement.onclick = () => handleMenuItemClick(item);

                    const imageUrl = item.image || `https://via.placeholder.com/180x120?text=${item.name.replace(/\s/g, '+')}`;
                    
                    itemElement.innerHTML = `
                        <img src="${imageUrl}" alt="${item.name}" loading="lazy" onerror="this.onerror=null;this.src='https://via.placeholder.com/180x120?text=Item+Image';">
                        <div class="menu-item-info">
                            <p class="mb-0 fw-bold text-truncate">${item.name}</p>
                            <span class="badge bg-secondary">${item.category}</span>
                            <p class="mb-0 mt-1">₱${parseFloat(item.price).toFixed(2)}</p>
                        </div>
                    `;
                    container.appendChild(itemElement);
                }
            });
            renderCategoryTabs(categories, filterCategory);
        }
        
        function renderCategoryTabs(categories, activeCategory) {
            const tabsContainer = document.getElementById('category-tabs');
            tabsContainer.innerHTML = ''; 
            
            categories.forEach(category => {
                const btn = document.createElement('button');
                btn.className = `btn btn-sm me-2 ${category === activeCategory ? 'btn-primary' : 'btn-outline-secondary'}`;
                btn.textContent = category;
                btn.setAttribute('data-category', category);
                btn.onclick = () => renderMenu(category);
                tabsContainer.appendChild(btn);
            });
        }

        function renderCart() {
            const list = document.getElementById('order-list');
            list.innerHTML = '';

            if (cart.length === 0) {
                const emptyMsg = document.getElementById('empty-cart-message');
                if (emptyMsg) list.appendChild(emptyMsg); 
                return;
            }

            cart.forEach((item, index) => {
                let spec = '';
                if (item.selectedSize !== 'N/A' || item.selectedTemp !== 'N/A') {
                    const specs = [];
                    if (item.selectedSize !== 'N/A') specs.push(item.selectedSize);
                    if (item.selectedTemp !== 'N/A') specs.push(item.selectedTemp);
                    spec = ` (${specs.join(', ')})`;
                }

                const price = item.unitPrice * item.quantity;
                
                const itemElement = document.createElement('div');
                itemElement.className = 'order-list-item';
                itemElement.innerHTML = `
                    <div class="qty-control d-flex align-items-center me-2">
                        <button class="btn btn-sm btn-outline-danger me-1" onclick="updateCartItemQuantity(${index}, -1)">-</button>
                        <span class="fw-bold">${item.quantity}</span>
                        <button class="btn btn-sm btn-outline-success ms-1" onclick="updateCartItemQuantity(${index}, 1)">+</button>
                    </div>
                    <div class="name">
                        <p class="mb-0 text-dark fw-bold">${item.itemNameAtSale}</p>
                        <small class="text-muted">${spec}</small>
                    </div>
                    <div class="price">
                        ₱${price.toFixed(2)}
                    </div>
                `;
                list.appendChild(itemElement);
            });
        }
        
        // --- PAYMENT METHOD HANDLERS ---
        function handlePaymentMethodChange() {
            const cashRadio = document.getElementById('cash');
            const gcashRadio = document.getElementById('gcash');
            const cashTenderedSection = document.getElementById('cash-tendered-section');
            const qrContainer = document.getElementById('gcash-qr-container');
            
            if (cashRadio.checked) {
                paymentMethod = 'Cash';
                cashTenderedSection.style.display = 'block';
                qrContainer.style.display = 'none';
                // Enable cash tendered input
                document.getElementById('cash-tendered').disabled = false;
            } else if (gcashRadio.checked) {
                paymentMethod = 'GCash';
                cashTenderedSection.style.display = 'none';
                qrContainer.style.display = 'block';
                // Disable cash tendered input for GCash
                document.getElementById('cash-tendered').disabled = true;
                document.getElementById('cash-tendered').value = '0.00';
            }
            
            calculateTotals(); // Recalculate to update button state
        }
        
        // --- CART/MODIFIER HANDLERS ---
        function handleMenuItemClick(item) {
            selectedProduct = item;
            
            if (item.isDrink || item.needsTemp) {
                document.getElementById('modifier-item-name').textContent = item.name;
                document.getElementById('size-options').style.display = item.isDrink ? 'block' : 'none';
                document.getElementById('temp-options').style.display = item.needsTemp ? 'block' : 'none';

                if (item.isDrink) { document.getElementById('size-small').checked = true; }
                if (item.needsTemp) { document.getElementById('temp-hot').checked = true; }
                
                new bootstrap.Modal(document.getElementById('modifiersModal')).show();
            } else {
                const newItem = {
                    productId: item.id,
                    itemNameAtSale: item.name,
                    quantity: 1,
                    unitPrice: parseFloat(item.price),
                    selectedSize: 'N/A',
                    selectedTemp: 'N/A'
                };
                
                const existingIndex = cart.findIndex(i => 
                    i.productId === newItem.productId && i.selectedSize === newItem.selectedSize && i.selectedTemp === newItem.selectedTemp
                );

                if (existingIndex > -1) {
                    cart[existingIndex].quantity += 1;
                } else {
                    cart.push(newItem);
                }
                
                calculateTotals();
            }
        }
        
        function handleAddToCart() {
            if (!selectedProduct) return;

            let size = 'N/A';
            let temp = 'N/A';
            let priceAdjustment = 0;
            
            if (selectedProduct.isDrink) {
                const sizeRadio = document.querySelector('input[name="size"]:checked');
                if (sizeRadio) {
                    size = sizeRadio.value;
                    priceAdjustment = MODAL_SIZE_PRICES[size] || 0;
                }
            }
            if (selectedProduct.needsTemp) {
                const tempRadio = document.querySelector('input[name="temp"]:checked');
                if (tempRadio) {
                    temp = tempRadio.value;
                }
            }

            const basePrice = parseFloat(selectedProduct.price);
            const finalPrice = basePrice + priceAdjustment;
            const nameAtSale = selectedProduct.name; 
            
            const newItem = {
                productId: selectedProduct.id,
                itemNameAtSale: nameAtSale, 
                quantity: 1,
                unitPrice: finalPrice,
                selectedSize: size,
                selectedTemp: temp
            };
            
            const existingIndex = cart.findIndex(item => 
                item.productId === newItem.productId && item.selectedSize === newItem.selectedSize && item.selectedTemp === newItem.selectedTemp
            );

            if (existingIndex > -1) {
                cart[existingIndex].quantity += 1;
            } else {
                cart.push(newItem);
            }

            calculateTotals();
            const modalElement = document.getElementById('modifiersModal');
            const modalInstance = bootstrap.Modal.getInstance(modalElement) || new bootstrap.Modal(modalElement);
            modalInstance.hide();
        }

        function updateCartItemQuantity(index, change) {
            cart[index].quantity += change;
            
            if (cart[index].quantity <= 0) {
                cart.splice(index, 1);
            }
            
            calculateTotals();
        }


        // --- DISCOUNT/OVERRIDE HANDLERS ---
        function handleDiscountChange() {
            // UNIFIED LOGIC: This function simply recalculates totals based on the selected radio button.
            calculateTotals();
        }

        async function handleAdminOverride() {
            const username = document.getElementById('admin-username').value;
            const password = document.getElementById('admin-password').value;
            const statusDiv = document.getElementById('override-modal-status');
            
            statusDiv.textContent = 'Authorizing...';

            try {
                const response = await fetch('orders.php?action=override', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });

                const result = await response.json();
                const modalElement = document.getElementById('adminOverrideModal');
                const modalInstance = bootstrap.Modal.getInstance(modalElement) || new bootstrap.Modal(modalElement);

                if (response.ok && result.success) {
                    adminOverrideTimestamp = result.timestamp; 
                    calculateTotals(); 
                    modalInstance.hide();
                    processOrder(); 

                } else {
                    statusDiv.textContent = result.error || 'Authorization failed. Check Admin credentials.';
                    if (!response.ok && response.status !== 401 && response.status !== 404) {
                         statusDiv.textContent = 'Network or server error during authorization.';
                    }
                    document.getElementById('process-order-btn').disabled = false;
                }
            } catch (e) {
                statusDiv.textContent = 'Network or server error during authorization.';
                document.getElementById('process-order-btn').disabled = false;
            }
        }

        // --- PROCESS ORDER ---
        
        async function processOrder() {
            if (cart.length === 0) return;
            
            const currentDiscountRate = getActiveDiscountRate(); 
            
            // DISCOUNT AUTHORIZATION CHECK: ONLY for non-Admin users.
            if (currentDiscountRate > 0 && !IS_ADMIN) {
                const currentTime = Math.floor(Date.now() / 1000);
                const isOverrideActive = adminOverrideTimestamp > 0 && (currentTime - adminOverrideTimestamp < OVERRIDE_DURATION_SECONDS);

                if (!isOverrideActive) {
                    
                    document.getElementById('process-order-btn').disabled = true;

                    document.getElementById('admin-username').value = '';
                    document.getElementById('admin-password').value = '';
                    document.getElementById('override-modal-status').textContent = 'Admin credentials required to authorize the discount.';
                    
                    new bootstrap.Modal(document.getElementById('adminOverrideModal')).show();
                    
                    return; 
                }
            }

            const processBtn = document.getElementById('process-order-btn');
            processBtn.disabled = true;
            processBtn.textContent = 'Processing...';
            
            const subtotal = calculateSubtotal();
            const discountAmount = subtotal * (currentDiscountRate / 100);
            const totalAmount = subtotal - discountAmount;
            
            const cashTendered = parseFloat(document.getElementById('cash-tendered').value) || 0;
            let changeDue = cashTendered - totalAmount;
            
            // Payment method validation
            if (paymentMethod === 'Cash' && changeDue < -0.01) {
                 alert(`Error: Cash tendered is insufficient. Required: ₱${totalAmount.toFixed(2)}`);
                 processBtn.disabled = false;
                 processBtn.textContent = 'Process Order';
                 return;
            }
            
            // For GCash, change is always 0
            if (paymentMethod === 'GCash') {
                changeDue = 0;
            }
            
            const orderData = {
                items: cart,
                subtotal: subtotal,
                discountRate: currentDiscountRate,
                discountAmount: discountAmount,
                totalAmount: totalAmount,
                paymentMethod: paymentMethod, // Include payment method
                cashTendered: cashTendered, 
                changeDue: changeDue,       
            };

            try {
                const response = await fetch('orders.php?action=process_order', { 
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(orderData)
                });
                
                const result = await response.json();

                if (response.ok && result.success) {
                    
                    printReceipt(orderData, result.orderId);
                    clearOrder(true);
                } else {
                    alert(`Order Failed: ${result.error || 'Server error.'}\nDetails: ${result.details || 'Check PHP error logs for transaction failure.'}`);
                    console.error("Process Order Error:", result);
                }
            } catch (e) {
                alert('An error occurred while processing the order. (Check console for network error)');
                console.error("Process Order Error:", e);
            } finally {
                processBtn.disabled = false;
                processBtn.textContent = 'Process Order';
                
                calculateTotals(); 
            }
        }

        async function clearOrder(clearServerOverride = true) {
            cart = [];
            
            document.getElementById('discount-none').checked = true; 
            document.getElementById('cash-tendered').value = '0.00'; 
            document.getElementById('cash').checked = true;
            handlePaymentMethodChange(); // Reset to cash
            
            // Only clear the timestamp if it's not an Admin, or if we are forced to clear server state
            if (!IS_ADMIN && clearServerOverride) {
                adminOverrideTimestamp = 0;
                try {
                    await fetch('orders.php?action=clear_override'); 
                } catch(e) {
                    console.warn("Could not clear server override.");
                }
            }
            
            calculateTotals(); 
            updateOverrideUI(); 
        }
        
        // --- RECEIPT FUNCTION ---
        function printReceipt(orderData, orderId) {
            const now = new Date();
            const receiptHtml = `
                <div class="receipt-content">
                    <div class="receipt-header">
                        <h4>KAPIHAN NI BOSS G</h4>
                        <p>123 DLSU Street, Manila</p>
                        <p>Tel: (02) 8-BOSS-G</p>
                    </div>
                    <div class="receipt-separator"></div>
                    <p>Order ID: **#${orderId}**</p>
                    <p>Cashier: **${CASHIER_NAME}**</p>
                    <p>Date: ${now.toLocaleDateString()} ${now.toLocaleTimeString()}</p>
                    <div class="receipt-separator"></div>
                    
                    <div class="receipt-item-line">
                        <span>**QTY** | **ITEM**</span>
                        <span>**PRICE**</span>
                    </div>
                    <div class="receipt-separator"></div>

                    ${orderData.items.map(item => `
                        <div class="receipt-item-line">
                            <span>${item.quantity} x ${item.itemNameAtSale} ${item.selectedSize !== 'N/A' ? `(${item.selectedSize})` : ''}</span>
                            <span>₱${(item.unitPrice * item.quantity).toFixed(2)}</span>
                        </div>
                    `).join('')}
                    
                    <div class="receipt-separator"></div>

                    <div class="receipt-item-line">
                        <span>Subtotal:</span>
                        <span>₱${orderData.subtotal.toFixed(2)}</span>
                    </div>
                    <div class="receipt-item-line">
                        <span>Discount (${orderData.discountRate}%):</span>
                        <span class="text-danger">-₱${orderData.discountAmount.toFixed(2)}</span>
                    </div>
                    
                    <div class="receipt-separator"></div>
                    
                    <div class="receipt-item-line receipt-total">
                        <span>TOTAL AMOUNT DUE:</span>
                        <span>₱${orderData.totalAmount.toFixed(2)}</span>
                    </div>
                    
                    <div class="receipt-separator"></div>

                    <div class="receipt-item-line">
                        <span>Payment Method:</span>
                        <span><strong>${orderData.paymentMethod}</strong></span>
                    </div>
                    
                    ${orderData.paymentMethod === 'Cash' ? `
                    <div class="receipt-item-line">
                        <span>Cash Tendered:</span>
                        <span>₱${orderData.cashTendered.toFixed(2)}</span>
                    </div>
                    <div class="receipt-item-line receipt-total">
                        <span>CHANGE:</span>
                        <span>₱${orderData.changeDue.toFixed(2)}</span>
                    </div>
                    ` : `
                    <div class="receipt-item-line">
                        <span>Payment Status:</span>
                        <span><strong>PAID VIA GCASH</strong></span>
                    </div>
                    `}

                    <div class="receipt-separator"></div>
                    <div class="receipt-footer">
                        <p>THANK YOU! COME AGAIN!</p>
                    </div>
                </div>
            `;
            
            const receiptWindow = document.getElementById('receipt-window');
            receiptWindow.innerHTML = receiptHtml;
            receiptWindow.style.display = 'block'; 
            
            window.print();
            
            receiptWindow.style.display = 'none'; 
        }

        
        // --- INITIALIZATION ---
        document.addEventListener('DOMContentLoaded', () => {
            renderMenu('All'); 
            
            calculateTotals(); 
            
            document.getElementById('cash-tendered').addEventListener('input', calculateTotals);

            // Payment method change listeners
            document.querySelectorAll('input[name="payment-method"]').forEach(radio => {
                radio.addEventListener('change', handlePaymentMethodChange);
            });

            document.querySelectorAll('.discount-type').forEach(radio => {
                radio.addEventListener('change', handleDiscountChange);
            });

            document.getElementById('authorize-override-btn')?.addEventListener('click', handleAdminOverride);
            document.getElementById('process-order-btn').addEventListener('click', processOrder);
            document.getElementById('clear-order-btn').addEventListener('click', () => clearOrder(true));
            
            const addToCartBtn = document.getElementById('add-to-cart-btn');
            if (addToCartBtn) {
                addToCartBtn.addEventListener('click', handleAddToCart);
            }
            
            if (!IS_ADMIN) {
                setInterval(updateOverrideUI, 5000); 
            }
            
            // Initialize payment method UI
            handlePaymentMethodChange();
        });
    </script>
</body>
</html>