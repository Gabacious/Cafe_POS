<?php
// product_management.php (UPDATED: Direct Variable Embedding for CREATE/UPDATE)
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

$message = '';
$products = [];

// --- CRUD OPERATIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $name = $_POST['name'] ?? '';
    $category = $_POST['category'] ?? '';
    $price = $_POST['price'] ?? 0.00;
    $isDrink = isset($_POST['isDrink']) ? 1 : 0;
    $needsTemp = isset($_POST['needsTemp']) ? 1 : 0;
    $image = $_POST['image'] ?? NULL;
    
    if (empty($name) || empty($category) || $price <= 0) {
        $message = "Error: Missing required product data (Name, Category, or Price).";
    } else {
        // Prepare safe strings for SQL
        $name_safe = $conn ? sqlsrv_escape_string($conn, $name) : addslashes($name);
        $category_safe = $conn ? sqlsrv_escape_string($conn, $category) : addslashes($category);
        $image_val = $image ? ("'" . ($conn ? sqlsrv_escape_string($conn, $image) : addslashes($image)) . "'") : 'NULL';


        if ($id) {
            // UPDATE - DIRECT VALUE INSERT
            $sql = "UPDATE Products SET 
                    name = '$name_safe', 
                    category = '$category_safe', 
                    base_price = $price, 
                    has_size_options = $isDrink, 
                    has_temp_options = $needsTemp, 
                    image_url = $image_val
                    WHERE product_id = $id";
            
            $stmt = sqlsrv_query($conn, $sql); 
            
            if ($stmt === false) {
                 $message = "Error updating product: " . print_r(sqlsrv_errors(), true);
            } else {
                 $message = "Product updated successfully.";
            }
        } else {
            // CREATE - DIRECT VALUE INSERT
            $sql = "INSERT INTO Products 
                    (name, category, base_price, has_size_options, has_temp_options, image_url)
                    VALUES ('$name_safe', '$category_safe', $price, $isDrink, $needsTemp, $image_val)";
            
            $stmt = sqlsrv_query($conn, $sql); 
            
            if ($stmt === false) {
                 $message = "Error adding product: " . print_r(sqlsrv_errors(), true);
            } else {
                 $message = "Product added successfully.";
            }
        }
    }
} elseif (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    // DELETE (Uses prepared statement for simple URL parameter, but direct variable embedding works too)
    $id = $_GET['id'];
    
    $sql = "DELETE FROM Products WHERE product_id = $id";
    $stmt = sqlsrv_query($conn, $sql);
    
    if ($stmt === false) {
        $error = print_r(sqlsrv_errors(), true);
        if (strpos($error, 'REFERENCE constraint') !== false) {
           $message = "Error: Cannot delete product ID {$id}. It has existing sales records. You should hide it instead of deleting.";
        } else {
           $message = "Error deleting product: " . $error;
        }
    } else {
        $rowsAffected = sqlsrv_rows_affected($stmt);
        if ($rowsAffected > 0) {
            $message = "Product deleted successfully.";
        } else {
            $message = "Product ID {$id} not found.";
        }
    }
    header("Location: product_management.php?message=" . urlencode($message));
    exit();
}

// Check for redirected message
if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']);
}

// --- READ (FETCH ALL PRODUCTS) ---
$sql_read = "SELECT product_id AS id, name, category, base_price AS price, 
               image_url AS image, has_size_options AS isDrink, has_temp_options AS needsTemp 
        FROM Products ORDER BY category, name";
$stmt_read = sqlsrv_query($conn, $sql_read);

if ($stmt_read) {
    while ($row = sqlsrv_fetch_array($stmt_read, SQLSRV_FETCH_ASSOC)) {
        $row['isDrink'] = (bool)$row['isDrink'];
        $row['needsTemp'] = (bool)$row['needsTemp'];
        $products[] = $row;
    }
    sqlsrv_free_stmt($stmt_read);
} else {
    $message = "Error fetching products: " . print_r(sqlsrv_errors(), true);
}

sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kapihan ni Boss G POS - Product Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .bg-cafe { background-color: #5d4037 !important; }
        .navbar-logo { height: 30px; margin-right: 8px; vertical-align: middle; }
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
                    <li class="nav-item"><a class="nav-link active" href="product_management.php">Products</a></li>
                    <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
                    <li class="nav-item"><a class="nav-link" href="user_settings.php">Users & Settings</a></li>
                </ul>
                <a href="logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h3 class="mb-4 text-secondary">Product Inventory Management</h3>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo strpos($message, 'Error') !== false ? 'danger' : 'success'; ?>" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <button class="btn btn-success mb-4" data-bs-toggle="modal" data-bs-target="#productModal" onclick="openProductModal()">+ Add New Product</button>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle" id="product-table">
                        <thead class="bg-light">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Base Price (₱)</th>
                                <th>Size Option</th>
                                <th>Temp Option</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="product-list">
                            <?php if (count($products) > 0): ?>
                                <?php foreach ($products as $product): ?>
                                    <tr data-product='<?php echo json_encode($product); ?>'>
                                        <td><?php echo htmlspecialchars($product['id']); ?></td>
                                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                                        <td><?php echo htmlspecialchars($product['category']); ?></td>
                                        <td class="fw-bold">₱<?php echo number_format($product['price'], 2); ?></td>
                                        <td><?php echo $product['isDrink'] ? 'Yes' : 'No'; ?></td>
                                        <td><?php echo $product['needsTemp'] ? 'Yes' : 'No'; ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-info me-2" onclick="editProduct(<?php echo $product['id']; ?>)">Edit</button>
                                            <a href="product_management.php?action=delete&id=<?php echo $product['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete <?php echo addslashes($product['name']); ?>?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted">No products found. Add a new one!</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="productForm" method="POST" action="product_management.php">
                    <div class="modal-header bg-cafe text-white">
                        <h5 class="modal-title" id="productModalLabel">Add New Product</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="product-id" name="id">
                        
                        <div class="mb-3">
                            <label for="product-name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="product-name" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="product-category" class="form-label">Category</label>
                            <select class="form-select" id="product-category" name="category" required>
                                <option value="" disabled selected>Select Category</option>
                                <option value="Coffee">Coffee</option>
                                <option value="Tea">Tea</option>
                                <option value="Juice">Juice</option>
                                <option value="Pastry">Pastry</option>
                                <option value="Meal">Meal</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="product-price" class="form-label">Base Price (₱)</label>
                            <input type="number" step="0.01" class="form-control" id="product-price" name="price" required min="1">
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="has-size" name="isDrink">
                            <label class="form-check-label" for="has-size">Requires Size Options</label>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="needs-temp" name="needsTemp">
                            <label class="form-check-label" for="needs-temp">Requires Temperature Option</label>
                        </div>
                        
                        <div class="mb-3">
                            <label for="product-image" class="form-label">Image URL (Optional)</label>
                            <input type="text" class="form-control" id="product-image" name="image">
                        </div>
                        
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary" id="save-product-btn">Save Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Get all product data that was rendered by PHP
        const ALL_PRODUCTS = [];
        document.querySelectorAll('#product-list tr').forEach(row => {
            const productData = row.getAttribute('data-product');
            if (productData) {
                ALL_PRODUCTS.push(JSON.parse(productData));
            }
        });
        
        function getProductById(id) {
            return ALL_PRODUCTS.find(p => p.id === id);
        }

        function openProductModal(product = null) {
            const modalTitle = document.getElementById('productModalLabel');
            const form = document.getElementById('productForm');
            
            form.reset();
            
            if (product) {
                modalTitle.textContent = 'Edit Product: ' + product.name;
                document.getElementById('product-id').value = product.id;
                document.getElementById('product-name').value = product.name;
                document.getElementById('product-category').value = product.category;
                document.getElementById('product-price').value = parseFloat(product.price);
                document.getElementById('has-size').checked = product.isDrink;
                document.getElementById('needs-temp').checked = product.needsTemp;
                document.getElementById('product-image').value = product.image || '';
            } else {
                modalTitle.textContent = 'Add New Product';
                document.getElementById('product-id').value = '';
            }
            
            const modal = new bootstrap.Modal(document.getElementById('productModal'));
            modal.show();
        }

        function editProduct(id) {
            const product = getProductById(id);
            if (product) {
                openProductModal(product);
            } else {
                alert("Product not found in local data!");
            }
        }
    </script>
</body>
</html>