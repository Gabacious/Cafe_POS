<?php
// api/process_order.php (UPDATED: Direct Variable Embedding for INSERT)
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized access. Please log in again.']));
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
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}
// --- END CONNECTION BLOCK ---

$cashier_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

// Extract Order Data
$items = $input['items'] ?? [];
$subtotal = $input['subtotal'] ?? 0.00;
$discountRate = $input['discountRate'] ?? 0.00;
$discountAmount = $input['discountAmount'] ?? 0.00;
$totalAmount = $input['totalAmount'] ?? 0.00;
$paymentMethod = 'Cash'; 

if (count($items) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Order item list is empty.']);
    sqlsrv_close($conn);
    exit();
}

// Start Transaction (Crucial for multi-step inserts)
sqlsrv_begin_transaction($conn);
$order_id = null;

try {
    // --- 1. Insert into Orders Table - DIRECT VALUE INSERT ---
    
    // Safely format string variables for SQL
    $paymentMethod_safe = $conn ? sqlsrv_escape_string($conn, $paymentMethod) : addslashes($paymentMethod);

    $sql_order = "INSERT INTO Orders 
        (cashier_id, subtotal, discount_rate, discount_amount, total_amount, payment_method, order_date)
        OUTPUT INSERTED.order_id
        VALUES ($cashier_id, $subtotal, $discountRate, $discountAmount, $totalAmount, '$paymentMethod_safe', GETDATE())";
    
    // Execute without parameters array
    $stmt_order = sqlsrv_query($conn, $sql_order); 
    
    if ($stmt_order === false) {
        throw new Exception("Order Insert Failed: " . print_r(sqlsrv_errors(), true));
    }
    
    if ($row = sqlsrv_fetch_array($stmt_order, SQLSRV_FETCH_ASSOC)) {
        $order_id = $row['order_id'];
    } else {
        throw new Exception("Could not retrieve Order ID after insert.");
    }
    sqlsrv_free_stmt($stmt_order);

    // --- 2. Insert into Order_Items Table - DIRECT VALUE INSERT ---
    $sql_item_base = "INSERT INTO Order_Items 
        (order_id, product_id, quantity, unit_price, item_name_at_sale, selected_size, selected_temp)";

    foreach ($items as $item) {
        $pid = $item['productId'] ?? 'NULL'; 
        $qty = $item['quantity'];
        $uprice = $item['unitPrice'];
        
        // Safely format string variables for SQL
        $name_safe = $conn ? sqlsrv_escape_string($conn, $item['itemNameAtSale']) : addslashes($item['itemNameAtSale']);
        $size_val = (empty($item['selectedSize']) || $item['selectedSize'] === 'N/A') ? 'NULL' : ("'" . ($conn ? sqlsrv_escape_string($conn, $item['selectedSize']) : addslashes($item['selectedSize'])) . "'");
        $temp_val = (empty($item['selectedTemp']) || $item['selectedTemp'] === 'N/A') ? 'NULL' : ("'" . ($conn ? sqlsrv_escape_string($conn, $item['selectedTemp']) : addslashes($item['selectedTemp'])) . "'");
        
        $sql_item = $sql_item_base . " VALUES ($order_id, $pid, $qty, $uprice, '$name_safe', $size_val, $temp_val)";
        
        // Execute without parameters array
        $stmt_item = sqlsrv_query($conn, $sql_item); 
        if ($stmt_item === false) {
            throw new Exception("Order Item Insert Failed for product ID {$pid}: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($stmt_item);
    }
    
    // 3. Commit the transaction
    sqlsrv_commit($conn);
    
    // 4. Clear the temporary session flag after successful transaction
    if (isset($_SESSION['temp_admin_access'])) {
        unset($_SESSION['temp_admin_access']);
    }
    
    sqlsrv_close($conn);

    echo json_encode(['success' => true, 'orderId' => $order_id]);

} catch (Exception $e) {
    sqlsrv_rollback($conn);
    sqlsrv_close($conn);

    http_response_code(500);
    echo json_encode(['error' => 'Transaction failed. Data was not saved. ' . $e->getMessage()]);
}
?>