<?php
// product_management.php (Updated with Role Check)
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


// --- CUSTOM STRING ESCAPE FUNCTION (REQUIRED FIX) ---
if (!function_exists('sqlsrv_escape_string')) {
    function sqlsrv_escape_string($string) {
        if (is_null($string)) return ''; 
        return str_replace("'", "''", strval($string));
    }
}
// ------------------------------------


// --- A. HANDLE PRODUCT CREATION/UPDATE/DELETE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        // Category comes from the 'category_select' field which is either the dropdown value or the new text input value
        $category_value = $_POST['category_select'] ?? '';
        
        // Sanitize and escape all user input fields for SQL injection prevention
        $safe_name = sqlsrv_escape_string($_POST['name']);
        $safe_category = sqlsrv_escape_string($category_value); 
        $safe_price = round(floatval($_POST['base_price']), 2);
        $safe_image_url = sqlsrv_escape_string($_POST['image_url']);
        
        // Convert checkbox inputs to SQL Server BIT/INT (1 or 0)
        $has_size = isset($_POST['has_size_options']) ? 1 : 0;
        $has_temp = isset($_POST['has_temp_options']) ? 1 : 0;

        if ($action === 'add') {
            // INSERT Query using string interpolation (NO placeholders)
            $sql = "INSERT INTO Products 
                    (name, category, base_price, image_url, has_size_options, has_temp_options)
                    VALUES ('$safe_name', '$safe_category', $safe_price, '$safe_image_url', $has_size, $has_temp)";
            
            $stmt = sqlsrv_query($conn, $sql);

            if ($stmt === false) {
                $_SESSION['message'] = "Error adding product: " . print_r(sqlsrv_errors(), true);
            } else {
                $_SESSION['message'] = "Product added successfully.";
            }

        } elseif ($action === 'edit') {
            $product_id = intval($_POST['product_id']); 

            // UPDATE Query using string interpolation (NO placeholders)
            $sql = "UPDATE Products SET 
                    name = '$safe_name', 
                    category = '$safe_category', 
                    base_price = $safe_price, 
                    image_url = '$safe_image_url', 
                    has_size_options = $has_size, 
                    has_temp_options = $has_temp 
                    WHERE product_id = $product_id";

            $stmt = sqlsrv_query($conn, $sql);

            if ($stmt === false) {
                $_SESSION['message'] = "Error updating product: " . print_r(sqlsrv_errors(), true);
            } else {
                $_SESSION['message'] = "Product updated successfully.";
            }
        }
        
    } elseif ($action === 'delete') {
        $product_id = intval($_POST['product_id']);

        // DELETE Query using string interpolation (NO placeholders)
        $sql = "DELETE FROM Products WHERE product_id = $product_id";
        
        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt === false) {
            $_SESSION['message'] = "Error deleting product: " . print_r(sqlsrv_errors(), true);
        } else {
            $_SESSION['message'] = "Product deleted successfully.";
        }
    }
    
    // Redirect to prevent form resubmission
    sqlsrv_close($conn);
    header("Location: product_management.php");
    exit();
}


// --- B. FETCH UNIQUE CATEGORIES ---
$categories = [];
$sql_categories = "SELECT DISTINCT category FROM Products ORDER BY category";
$stmt_categories = sqlsrv_query($conn, $sql_categories);

if ($stmt_categories) {
    while ($row = sqlsrv_fetch_array($stmt_categories, SQLSRV_FETCH_ASSOC)) {
        $categories[] = $row['category'];
    }
    sqlsrv_free_stmt($stmt_categories);
}


// --- C. FETCH ALL PRODUCTS FOR DISPLAY (GET REQUEST) ---
$products = [];
$sql_select = "SELECT product_id, name, category, base_price, image_url, has_size_options, has_temp_options FROM Products ORDER BY category, name";
$stmt_select = sqlsrv_query($conn, $sql_select);

if ($stmt_select) {
    while ($row = sqlsrv_fetch_array($stmt_select, SQLSRV_FETCH_ASSOC)) {
        $products[] = $row;
    }
    sqlsrv_free_stmt($stmt_select);
}
sqlsrv_close($conn); 

$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Product Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .product-image { max-width: 50px; height: auto; }
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
                    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="orders.php">Orders</a></li> 
                        <li class="nav-item"><a class="nav-link active" href="product_management.php">Products</a></li> 
                        <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
                        <li class="nav-item"><a class="nav-link" href="users.php">Users & Settings</a></li>
                    <?php endif; ?>
                </ul>
                <a href="logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mt-5">
        <h2>Product Management</h2>

        <?php if ($message): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#productModal" onclick="prepareProductModal('add')">Add New Product</button>

        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Image</th>
                    <th>Options</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                <tr>
                    <td><?php echo htmlspecialchars($product['product_id']); ?></td>
                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                    <td><?php echo htmlspecialchars($product['category']); ?></td>
                    <td>₱<?php echo number_format($product['base_price'], 2); ?></td>
                    <td><img src="<?php echo htmlspecialchars($product['image_url']); ?>" class="product-image" onerror="this.onerror=null;this.src='https://via.placeholder.com/50x50?text=No+Img';"></td>
                    <td>
                        <?php echo $product['has_size_options'] ? 'Size ' : ''; ?>
                        <?php echo $product['has_temp_options'] ? 'Temp' : ''; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-warning" 
                                data-bs-toggle="modal" 
                                data-bs-target="#productModal" 
                                onclick='prepareProductModal("edit", <?php echo json_encode($product); ?>)'>Edit</button>
                        
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this product?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product['product_id']); ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    </div>

    <div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="productModalLabel"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" id="modal-action" value="add">
                        <input type="hidden" name="product_id" id="modal-product-id">

                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="category_select" class="form-label">Category</label>
                            <select class="form-select" id="category_select" name="category_select" required>
                                <option value="">-- Select or Type New --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                                <option value="___new_category___">-- Add New Category --</option>
                            </select>
                            <input type="text" class="form-control mt-2" id="new_category_input" placeholder="Enter New Category Name" style="display:none;">
                        </div>
                        <div class="mb-3">
                            <label for="base_price" class="form-label">Base Price</label>
                            <input type="number" step="0.01" class="form-control" id="base_price" name="base_price" required>
                        </div>
                        <div class="mb-3">
                            <label for="image_url" class="form-label">Image URL</label>
                            <input type="url" class="form-control" id="image_url" name="image_url">
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="has_size_options" name="has_size_options" value="1">
                            <label class="form-check-label" for="has_size_options">Has Size Options (S/M/L)</label>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="has_temp_options" name="has_temp_options" value="1">
                            <label class="form-check-label" for="has_temp_options">Has Temp Options (Hot/Iced)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary" id="modal-submit-btn">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const categorySelect = document.getElementById('category_select');
            const newCategoryInput = document.getElementById('new_category_input');

            // Toggle logic for the new category input field
            function toggleNewCategoryInput() {
                if (categorySelect.value === '___new_category___') {
                    newCategoryInput.style.display = 'block';
                    newCategoryInput.setAttribute('name', 'category_select'); 
                    newCategoryInput.required = true;
                    categorySelect.removeAttribute('name'); 
                    categorySelect.required = false;
                } else {
                    newCategoryInput.style.display = 'none';
                    newCategoryInput.removeAttribute('name');
                    newCategoryInput.required = false;
                    categorySelect.setAttribute('name', 'category_select'); 
                    categorySelect.required = true;
                }
            }

            categorySelect.addEventListener('change', toggleNewCategoryInput);
            
            // Submission logic to ensure only one field is sent
            document.querySelector('#productModal form').addEventListener('submit', function() {
                 toggleNewCategoryInput(); // Re-run to ensure the correct name is set right before submission
            });
        });

        // Function to populate modal for Add/Edit
        function prepareProductModal(action, product = null) {
            document.getElementById('modal-action').value = action;
            document.getElementById('productModalLabel').textContent = action === 'add' ? 'Add New Product' : 'Edit Product';
            document.getElementById('modal-submit-btn').textContent = action === 'add' ? 'Add Product' : 'Save Changes';
            
            const categorySelect = document.getElementById('category_select');
            const newCategoryInput = document.getElementById('new_category_input');
            
            // Reset state
            newCategoryInput.style.display = 'none';
            newCategoryInput.value = '';
            categorySelect.setAttribute('name', 'category_select'); 
            newCategoryInput.removeAttribute('name');

            if (action === 'edit' && product) {
                document.getElementById('modal-product-id').value = product.product_id;
                document.getElementById('name').value = product.name;
                document.getElementById('base_price').value = parseFloat(product.base_price).toFixed(2);
                document.getElementById('image_url').value = product.image_url;
                document.getElementById('has_size_options').checked = product.has_size_options == 1;
                document.getElementById('has_temp_options').checked = product.has_temp_options == 1;
                
                // Select the correct category
                categorySelect.value = product.category;

            } else {
                // Reset form for 'add'
                document.getElementById('modal-product-id').value = '';
                document.getElementById('name').value = '';
                categorySelect.value = ''; 
                document.getElementById('base_price').value = '';
                document.getElementById('image_url').value = '';
                document.getElementById('has_size_options').checked = false;
                document.getElementById('has_temp_options').checked = false;
            }
        }
    </script>
</body>
</html>