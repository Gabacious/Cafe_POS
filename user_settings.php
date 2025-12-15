<?php
// user_settings.php (UPDATED: Direct Variable Embedding for CREATE/UPDATE)
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
$users = [];

// --- CRUD OPERATIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['user_id'] ?? null;
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'Cashier';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($username) || (!$id && empty($password))) {
        $message = "Error: Username and password (for new users) are required.";
    } else {
        // Prepare safe strings for SQL
        $username_safe = $conn ? sqlsrv_escape_string($conn, $username) : addslashes($username);
        $role_safe = $conn ? sqlsrv_escape_string($conn, $role) : addslashes($role);
        
        // Use password variable directly in SQL if provided
        $password_update = '';
        if (!empty($password)) {
            $password_hash = $conn ? sqlsrv_escape_string($conn, $password) : addslashes($password); // Using plain text for demo
            $password_update = ", password_hash = '$password_hash'";
        }

        if ($id) {
            // UPDATE - DIRECT VALUE INSERT
            $sql = "UPDATE Users SET 
                    username = '$username_safe', 
                    role = '$role_safe', 
                    is_active = $isActive
                    $password_update 
                    WHERE user_id = $id";
            
            $stmt = sqlsrv_query($conn, $sql); 
            
            if ($stmt === false) {
                 $message = "Error updating user: " . print_r(sqlsrv_errors(), true);
            } else {
                 $message = "User updated successfully.";
            }
        } else {
            // CREATE - DIRECT VALUE INSERT
            $password_hash = $conn ? sqlsrv_escape_string($conn, $password) : addslashes($password); // Using plain text for demo
            $sql = "INSERT INTO Users (username, password_hash, role, is_active)
                    VALUES ('$username_safe', '$password_hash', '$role_safe', $isActive)";
            
            $stmt = sqlsrv_query($conn, $sql); 
            
            if ($stmt === false) {
                $error = print_r(sqlsrv_errors(), true);
                if (strpos($error, 'UNIQUE constraint') !== false) {
                    $message = "Error: Username already exists.";
                } else {
                    $message = "Error adding user: " . $error;
                }
            } else {
                 $message = "User account created successfully.";
            }
        }
    }
    // Redirect to clean up URL
    header("Location: user_settings.php?message=" . urlencode($message));
    exit();
} elseif (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    // DELETE (Direct variable embedding)
    $id = $_GET['id'];
    
    if ($id == $_SESSION['user_id']) {
        $message = "Error: Cannot delete your own active account.";
    } else {
        $sql = "DELETE FROM Users WHERE user_id = $id";
        $stmt = sqlsrv_query($conn, $sql);
        
        if ($stmt === false) {
            $error = print_r(sqlsrv_errors(), true);
            if (strpos($error, 'REFERENCE constraint') !== false) {
                $message = "Error: Cannot delete user ID {$id}. They have processed existing orders. Please set 'Active' to No instead.";
            } else {
                $message = "Error deleting user: " . $error;
            }
        } else {
            $rowsAffected = sqlsrv_rows_affected($stmt);
            $message = $rowsAffected > 0 ? "User deleted successfully." : "User ID {$id} not found.";
        }
    }
    // Redirect to clean up URL
    header("Location: user_settings.php?message=" . urlencode($message));
    exit();
}

// Check for redirected message
if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']);
}

// --- READ (FETCH ALL USERS) ---
$sql_read = "SELECT user_id, username, role, is_active FROM Users ORDER BY user_id";
$stmt_read = sqlsrv_query($conn, $sql_read);

if ($stmt_read) {
    while ($row = sqlsrv_fetch_array($stmt_read, SQLSRV_FETCH_ASSOC)) {
        $row['is_active'] = (bool)$row['is_active'];
        $users[] = $row;
    }
    sqlsrv_free_stmt($stmt_read);
} else {
    $message = "Error fetching users: " . print_r(sqlsrv_errors(), true);
}

sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kapihan ni Boss G POS - User Settings</title>
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
                    <li class="nav-item"><a class="nav-link" href="product_management.php">Products</a></li>
                    <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
                    <li class="nav-item"><a class="nav-link active" href="user_settings.php">Users & Settings</a></li>
                </ul>
                <a href="logout.php" class="btn btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h3 class="mb-4 text-secondary">Staff Account Management</h3>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo strpos($message, 'Error') !== false ? 'danger' : 'success'; ?>" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <button class="btn btn-success mb-4" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openUserModal()">+ Add New Staff</button>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle" id="user-table">
                        <thead class="bg-light">
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="user-list">
                            <?php if (count($users) > 0): ?>
                                <?php foreach ($users as $user): ?>
                                    <tr data-user='<?php echo json_encode($user); ?>'>
                                        <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['role']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'danger'; ?>">
                                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-info me-2" onclick="editUser(<?php echo $user['user_id']; ?>)">Edit</button>
                                            <a href="user_settings.php?action=delete&id=<?php echo $user['user_id']; ?>" 
                                               class="btn btn-sm btn-danger" 
                                               onclick="return confirm('WARNING: Are you sure you want to delete <?php echo addslashes($user['username']); ?>?')"
                                               <?php echo $user['user_id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>
                                            >Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted">No staff accounts found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="userForm" method="POST" action="user_settings.php">
                    <div class="modal-header bg-cafe text-white">
                        <h5 class="modal-title" id="userModalLabel">Add New Staff</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="user-id" name="user_id">
                        
                        <div class="mb-3">
                            <label for="user-username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="user-username" name="username" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="user-password" class="form-label" id="password-label">Password</label>
                            <input type="password" class="form-control" id="user-password" name="password" required>
                            <div class="form-text text-warning" id="password-help"></div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="user-role" class="form-label">Role</label>
                            <select class="form-select" id="user-role" name="role" required>
                                <option value="Cashier">Cashier</option>
                                <option value="Admin">Admin</option>
                            </select>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="user-active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="user-active">Account is Active</label>
                        </div>
                        
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary" id="save-user-btn">Save Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Get all user data that was rendered by PHP
        const ALL_USERS = [];
        document.querySelectorAll('#user-list tr').forEach(row => {
            const userData = row.getAttribute('data-user');
            if (userData) {
                ALL_USERS.push(JSON.parse(userData));
            }
        });

        function getUserById(id) {
            return ALL_USERS.find(u => u.user_id === id);
        }
        
        function openUserModal(user = null) {
            const modalTitle = document.getElementById('userModalLabel');
            const form = document.getElementById('userForm');
            const passwordField = document.getElementById('user-password');
            const passwordHelp = document.getElementById('password-help');
            
            form.reset();
            
            if (user) {
                modalTitle.textContent = 'Edit Staff: ' + user.username;
                document.getElementById('user-id').value = user.user_id;
                document.getElementById('user-username').value = user.username;
                document.getElementById('user-role').value = user.role;
                document.getElementById('user-active').checked = user.is_active;
                
                passwordField.removeAttribute('required');
                passwordField.value = ''; // Clear password field
                passwordHelp.textContent = 'Leave blank to keep current password.';
            } else {
                modalTitle.textContent = 'Add New Staff';
                document.getElementById('user-id').value = '';
                
                passwordField.setAttribute('required', 'required');
                passwordHelp.textContent = '';
                document.getElementById('user-active').checked = true;
            }
            
            const modal = new bootstrap.Modal(document.getElementById('userModal'));
            modal.show();
        }

        function editUser(id) {
            const user = getUserById(id);
            if (user) {
                openUserModal(user);
            } else {
                alert("User not found in local data!");
            }
        }
    </script>
</body>
</html>