<?php
// user_settings.php (Updated with Role Check and User CRUD Logic)
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

// Initialize message for user feedback
$message = null;

// --- A. HANDLE USER MANAGEMENT POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $action = $_POST['action'] ?? '';
    
    // --- 1. ADD NEW USER ---
    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = trim($_POST['role'] ?? 'Cashier');
        
        if (empty($username) || empty($password)) {
            $_SESSION['message'] = "Username and Password cannot be empty.";
        } else {
            $safe_username = sqlsrv_escape_string($username);
            // NOTE: In a real system, use password_hash() here. For this simulation, we use plain text.
            $password_hash = sqlsrv_escape_string($password); 
            $safe_role = sqlsrv_escape_string($role);
            
            // Check for existing user
            $sql_check = "SELECT user_id FROM Users WHERE username = '$safe_username'";
            $stmt_check = sqlsrv_query($conn, $sql_check);
            if ($stmt_check && sqlsrv_has_rows($stmt_check)) {
                 $_SESSION['message'] = "Error: Username '$username' already exists.";
            } else {
                // Insert Query
                $sql = "INSERT INTO Users (username, password_hash, role, is_active)
                         VALUES ('$safe_username', '$password_hash', '$safe_role', 1)";
                
                $stmt = sqlsrv_query($conn, $sql);

                if ($stmt === false) {
                    $_SESSION['message'] = "Error adding user: " . print_r(sqlsrv_errors(), true);
                } else {
                    $_SESSION['message'] = "User **$username** added successfully as **$role**.";
                }
            }
        }

    // --- 2. EDIT USER (Role, Password, or Username) ---
    } elseif ($action === 'edit') {
        $user_id = intval($_POST['user_id']);
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? ''); // Optional
        $role = trim($_POST['role'] ?? 'Cashier');
        
        if ($user_id <= 0 || empty($username)) {
            $_SESSION['message'] = "Invalid User ID or Username.";
        } else {
            $safe_username = sqlsrv_escape_string($username);
            $safe_role = sqlsrv_escape_string($role);
            $update_fields = ["username = '$safe_username'", "role = '$safe_role'"];
            
            // If password is provided, include it in the update
            if (!empty($password)) {
                $password_hash = sqlsrv_escape_string($password);
                $update_fields[] = "password_hash = '$password_hash'";
            }

            // Update Query
            $sql = "UPDATE Users SET " . implode(', ', $update_fields) . " WHERE user_id = $user_id";
            
            $stmt = sqlsrv_query($conn, $sql);

            if ($stmt === false) {
                $_SESSION['message'] = "Error updating user: " . print_r(sqlsrv_errors(), true);
            } else {
                $_SESSION['message'] = "User ID **$user_id** updated successfully.";
            }
        }

    // --- 3. DELETE USER (SOFT DELETE FIX) ---
    } elseif ($action === 'delete') {
        $user_id = intval($_POST['user_id']);
        
        if ($user_id === intval($_SESSION['user_id'])) {
             $_SESSION['message'] = "Error: You cannot delete your own account while logged in.";
        } elseif ($user_id > 0) {
            // Check if this is the last Admin account
            $sql_check_admin = "SELECT COUNT(*) FROM Users WHERE role = 'Admin' AND is_active = 1"; // Check active admins
            $stmt_admin = sqlsrv_query($conn, $sql_check_admin);
            $current_admin_count = ($stmt_admin && sqlsrv_fetch($stmt_admin)) ? sqlsrv_get_field($stmt_admin, 0) : 0;
            
            $sql_get_role = "SELECT role, is_active FROM Users WHERE user_id = $user_id";
            $stmt_role = sqlsrv_query($conn, $sql_get_role);
            $target_role = ($stmt_role && sqlsrv_fetch($stmt_role)) ? sqlsrv_get_field($stmt_role, 0) : null;
            $target_is_active = (isset($stmt_role)) ? sqlsrv_get_field($stmt_role, 1) : 0;

            // Only decrement the count if the target user is currently active
            $admin_count_after_deletion = $current_admin_count - (($target_role === 'Admin' && $target_is_active) ? 1 : 0);

            if ($target_role === 'Admin' && $admin_count_after_deletion <= 0) {
                $_SESSION['message'] = "Error: Cannot permanently remove the last active Admin account.";
            } else {
                // FIX: Replace the hard DELETE with a SOFT DELETE (UPDATE is_active = 0)
                // This prevents the "conflicted with the REFERENCE constraint" foreign key error.
                $sql = "UPDATE Users SET is_active = 0 WHERE user_id = $user_id"; 
                $stmt = sqlsrv_query($conn, $sql);

                if ($stmt === false) {
                    $_SESSION['message'] = "Error archiving user: " . print_r(sqlsrv_errors(), true);
                } else {
                    // Update success message to reflect soft delete/archival
                    $_SESSION['message'] = "User ID **$user_id** was safely archived/deactivated (Soft Delete) to maintain order history.";
                }
            }
        } else {
            $_SESSION['message'] = "Invalid User ID for deletion.";
        }

    // --- 4. ACTIVATE/DEACTIVATE USER ---
    } elseif ($action === 'toggle_active') {
        $user_id = intval($_POST['user_id']);
        $is_active = intval($_POST['is_active']); // 1 for activate, 0 for deactivate

        if ($user_id === intval($_SESSION['user_id'])) {
             $_SESSION['message'] = "Error: You cannot deactivate your own account while logged in.";
        } elseif ($user_id > 0) {
            
            // Additional check for last active admin before deactivation
            if ($is_active === 0) {
                $sql_get_role = "SELECT role FROM Users WHERE user_id = $user_id";
                $stmt_role = sqlsrv_query($conn, $sql_get_role);
                $target_role = ($stmt_role && sqlsrv_fetch($stmt_role)) ? sqlsrv_get_field($stmt_role, 0) : null;
                
                if ($target_role === 'Admin') {
                    $sql_check_admin = "SELECT COUNT(*) FROM Users WHERE role = 'Admin' AND is_active = 1";
                    $stmt_admin = sqlsrv_query($conn, $sql_check_admin);
                    $current_admin_count = ($stmt_admin && sqlsrv_fetch($stmt_admin)) ? sqlsrv_get_field($stmt_admin, 0) : 0;
                    
                    if ($current_admin_count <= 1) {
                         $_SESSION['message'] = "Error: Cannot deactivate the last remaining active Admin account.";
                         goto end_post;
                    }
                }
            }
            
            // Toggle Query
            $sql = "UPDATE Users SET is_active = $is_active WHERE user_id = $user_id";
            $stmt = sqlsrv_query($conn, $sql);

            if ($stmt === false) {
                $_SESSION['message'] = "Error updating user status: " . print_r(sqlsrv_errors(), true);
            } else {
                $_SESSION['message'] = "User ID **$user_id** status updated successfully.";
            }
        } else {
            $_SESSION['message'] = "Invalid User ID for status update.";
        }
    }
    
    end_post:
    // Redirect to prevent form resubmission
    sqlsrv_close($conn);
    header("Location: users.php");
    exit();
}


// --- B. FETCH ALL USERS FOR DISPLAY (GET REQUEST) ---
$users = [];
$sql_select = "SELECT user_id, username, role, is_active FROM Users ORDER BY role DESC, username";
$stmt_select = sqlsrv_query($conn, $sql_select);

if ($stmt_select) {
    while ($row = sqlsrv_fetch_array($stmt_select, SQLSRV_FETCH_ASSOC)) {
        // Convert is_active bit/int to boolean for easier display
        $row['is_active'] = (bool)$row['is_active']; 
        $users[] = $row;
    }
    sqlsrv_free_stmt($stmt_select);
}
sqlsrv_close($conn); 

// Retrieve and clear session message
$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Users & Settings</title>
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
                    <li class="nav-item"><a class="nav-link active" href="users.php">Users & Settings</a></li>
                </ul>
                <a href="logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mt-5">
        <h2>User Management and System Settings</h2>
        
        <?php if ($message): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card p-4 mt-4 shadow-sm">
            <h4 class="card-title">User Accounts</h4>
            <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#userModal" onclick="prepareUserModal('add')">
                <i class="bi bi-plus-circle me-2"></i>Add New User
            </button>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td>
                                <span class="badge <?php echo $user['role'] === 'Admin' ? 'bg-danger' : 'bg-success'; ?>">
                                    <?php echo htmlspecialchars($user['role']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo $user['is_active'] ? 'bg-primary' : 'bg-secondary'; ?>">
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-warning me-1" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#userModal" 
                                        onclick='prepareUserModal("edit", <?php echo json_encode($user); ?>)'>
                                    <i class="bi bi-pencil-square"></i> Edit
                                </button>
                                
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['user_id']); ?>">
                                    <input type="hidden" name="is_active" value="<?php echo $user['is_active'] ? '0' : '1'; ?>">
                                    <button type="submit" 
                                            class="btn btn-sm <?php echo $user['is_active'] ? 'btn-secondary' : 'btn-info'; ?>"
                                            onclick="return confirm('Are you sure you want to <?php echo $user['is_active'] ? 'DEACTIVATE' : 'ACTIVATE'; ?> user <?php echo htmlspecialchars($user['username']); ?>? This is a temporary suspension.');">
                                        <i class="bi bi-power"></i> <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>

                                <form method="POST" style="display:inline;" onsubmit="return confirm('WARNING: Are you sure you want to PERMANENTLY ARCHIVE user <?php echo htmlspecialchars($user['username']); ?>? This action deactivates the user and is used for historical records.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['user_id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i> Archive
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card p-4 mt-4 shadow-sm">
            <h4 class="card-title">System Settings (Placeholder)</h4>
            <form>
                <div class="mb-3">
                    <label for="taxRate" class="form-label">Default Tax Rate (%)</label>
                    <input type="number" step="0.01" class="form-control" id="taxRate" value="0.00">
                </div>
                <div class="mb-3">
                    <label for="storeName" class="form-label">Store Name (for Receipt)</label>
                    <input type="text" class="form-control" id="storeName" value="KAPIHAN NI BOSS G">
                </div>
                <button type="submit" class="btn btn-success">Save Settings</button>
            </form>
        </div>
    </div>

    <div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-cafe text-white">
                        <h5 class="modal-title" id="userModalLabel">Add New User</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" id="modal-action" value="add">
                        <input type="hidden" name="user_id" id="modal-user-id">

                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <div id="password-help" class="form-text">
                                *Required for **Add**. Leave blank for **Edit** if you do not want to change it.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select id="role" name="role" class="form-select">
                                <option value="Admin">Admin</option>
                                <option value="Cashier">Cashier</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary" id="modal-submit-btn">Save User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Function to populate modal for Add/Edit
        function prepareUserModal(action, user = null) {
            const modalTitle = document.getElementById('userModalLabel');
            const modalAction = document.getElementById('modal-action');
            const submitBtn = document.getElementById('modal-submit-btn');
            const userIdInput = document.getElementById('modal-user-id');
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            const roleSelect = document.getElementById('role');
            const passwordHelp = document.getElementById('password-help');

            modalAction.value = action;

            if (action === 'add') {
                modalTitle.textContent = 'Add New User';
                submitBtn.textContent = 'Add User';
                userIdInput.value = '';
                usernameInput.value = '';
                passwordInput.value = '';
                passwordInput.required = true;
                roleSelect.value = 'Cashier';
                passwordHelp.style.display = 'block';
            } else if (action === 'edit' && user) {
                modalTitle.textContent = `Edit User: ${user.username}`;
                submitBtn.textContent = 'Save Changes';
                userIdInput.value = user.user_id;
                usernameInput.value = user.username;
                passwordInput.value = ''; // Never pre-fill password
                passwordInput.required = false; // Password is optional for edit
                roleSelect.value = user.role;
                passwordHelp.style.display = 'block';
            }
        }
    </script>
</body>
</html>