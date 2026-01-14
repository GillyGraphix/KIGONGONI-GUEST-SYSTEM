<?php
session_start();
include 'db_connect.php';

// Security: Only Manager
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'manager') {
    header("Location: login.php");
    exit();
}

$currentPage = basename($_SERVER['PHP_SELF']);
$success = $error = '';

// --- HANDLE FORM SUBMISSIONS ---

// 1. ADD NEW STAFF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_staff') {
    $fullname = mysqli_real_escape_string($conn, $_POST['fullname']);
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];
    $role = $_POST['role'];

    // Check if username exists
    $check = $conn->query("SELECT id FROM users WHERE username='$username'");
    if ($check->num_rows > 0) {
        $error = "Username '$username' already exists. Please choose another.";
    } else {
        // Hash the password for security
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("INSERT INTO users (fullname, username, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $fullname, $username, $hashed_password, $role);
        
        if ($stmt->execute()) {
            $success = "New staff member added successfully!";
        } else {
            $error = "Error adding staff: " . $conn->error;
        }
    }
}

// 2. UPDATE STAFF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_staff') {
    $user_id = intval($_POST['user_id']);
    $fullname = mysqli_real_escape_string($conn, $_POST['fullname']);
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $role = $_POST['role'];
    $new_password = $_POST['password'];

    // Prepare query based on whether password is being changed
    if (!empty($new_password)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET fullname=?, username=?, role=?, password=? WHERE id=?");
        $stmt->bind_param("ssssi", $fullname, $username, $role, $hashed_password, $user_id);
    } else {
        // Keep old password
        $stmt = $conn->prepare("UPDATE users SET fullname=?, username=?, role=? WHERE id=?");
        $stmt->bind_param("sssi", $fullname, $username, $role, $user_id);
    }

    if ($stmt->execute()) {
        $success = "Staff details updated successfully!";
    } else {
        $error = "Error updating staff: " . $conn->error;
    }
}

// 3. DELETE STAFF
if (isset($_GET['delete'])) {
    $id_to_delete = intval($_GET['delete']);
    
    // Prevent self-deletion
    if ($id_to_delete == $_SESSION['user_id']) {
        $error = "You cannot delete your own account while logged in.";
    } else {
        if ($conn->query("DELETE FROM users WHERE id=$id_to_delete")) {
            $success = "Staff account deleted successfully.";
        } else {
            $error = "Failed to delete account.";
        }
    }
}

// --- FETCH ALL USERS ---
$users = [];
$result = $conn->query("SELECT * FROM users ORDER BY id DESC");
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Staff - HMS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
/* CSS Styling consistent with Manager Dashboard */
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; }

/* Sidebar */
.sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: #1e3a5f; color: #fff; padding: 30px 0; display: flex; flex-direction: column; box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1); z-index: 1000; overflow-y: auto; }
.sidebar-header { padding: 0 25px 30px 25px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); margin-bottom: 20px; }
.sidebar-header h2 { font-weight: 600; font-size: 1.4rem; color: #fff; margin-bottom: 5px; }
.sidebar-header p { font-size: 0.85rem; color: rgba(255, 255, 255, 0.7); }
.sidebar-nav { flex: 1; padding: 0 15px; }
.sidebar a { text-decoration: none; color: rgba(255, 255, 255, 0.85); display: flex; align-items: center; padding: 14px 18px; margin: 5px 0; border-radius: 10px; transition: all 0.3s ease; font-weight: 500; font-size: 0.95rem; }
.sidebar a i { margin-right: 14px; font-size: 1.1rem; width: 20px; text-align: center; }
.sidebar a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; transform: translateX(5px); }
.sidebar a.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
.logout-section { padding: 0 15px 20px 15px; border-top: 1px solid rgba(255, 255, 255, 0.1); margin-top: 20px; padding-top: 20px; }
.logout-btn { width: 100%; padding: 12px 18px; background: #dc3545; color: #fff; border: none; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; font-weight: 600; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; }
.logout-btn:hover { background: #c82333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3); }

/* Main Content */
.main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; }
.header { margin-bottom: 30px; background: #fff; padding: 25px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; justify-content: space-between; align-items: center; }
.header h2 { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; }

.btn-add { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; cursor: pointer; border: none; transition: 0.3s; }
.btn-add:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3); }

/* Table Styling */
.table-container { background: #fff; border-radius: 15px; padding: 30px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
table th { background: #1e3a5f; color: #fff; text-align: left; padding: 15px; font-weight: 600; font-size: 0.9rem; text-transform: uppercase; }
table td { padding: 15px; border-bottom: 1px solid #e9ecef; color: #2c3e50; vertical-align: middle; }
table tbody tr:hover { background: #f8f9fa; }

.role-badge { padding: 5px 10px; border-radius: 15px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }
.role-manager { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
.role-receptionist { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

.action-btn { padding: 6px 12px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.85rem; margin-right: 5px; color: white; transition: 0.3s; }
.btn-edit { background: #ffc107; color: #333; }
.btn-delete { background: #dc3545; }
.btn-edit:hover { background: #e0a800; }
.btn-delete:hover { background: #c82333; }

/* Modal Styling */
.modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; backdrop-filter: blur(4px); }
.modal-content { background: #fff; padding: 30px; border-radius: 15px; width: 100%; max-width: 500px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); animation: slideDown 0.3s ease; }
@keyframes slideDown { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
.modal-header h3 { color: #1e3a5f; margin: 0; }
.close-modal { font-size: 1.5rem; color: #aaa; cursor: pointer; }
.close-modal:hover { color: #000; }

.form-group { margin-bottom: 15px; }
.form-label { display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50; }
.form-control { width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 0.95rem; }
.form-control:focus { border-color: #667eea; outline: none; }
.btn-save { width: 100%; padding: 12px; background: #1e3a5f; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 10px; }
.btn-save:hover { background: #162c4a; }

@media (max-width: 768px) {
    .sidebar { width: 70px; }
    .sidebar-header h2, .sidebar-header p, .sidebar a span { display: none; }
    .sidebar a { justify-content: center; padding: 14px; }
    .main-content { margin-left: 70px; padding: 20px; }
}
</style>
</head>
<body>

<?php if ($success): ?>
<script>Swal.fire({ icon: 'success', title: 'Success', text: '<?= $success ?>', showConfirmButton: false, timer: 2000 });</script>
<?php endif; ?>
<?php if ($error): ?>
<script>Swal.fire({ icon: 'error', title: 'Error', text: '<?= $error ?>' });</script>
<?php endif; ?>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>HGMA System</h2>
        <p>Hotel Management</p>
    </div>
    <div class="sidebar-nav">
        <a href="manager_dashboard.php"><i class="fa-solid fa-house"></i> <span>Dashboard</span></a>
        <a href="manage_staff.php" class="active"><i class="fa-solid fa-user-shield"></i> <span>Manage Staff</span></a>
        <a href="room_details.php"><i class="fa-solid fa-bed"></i> <span>Room Details</span></a>
        <a href="payment_reports.php"><i class="fa-solid fa-credit-card"></i> <span>Payment Reports</span></a>
        <a href="analytics.php"><i class="fa-solid fa-chart-line"></i> <span>Analytics</span></a>
        <a href="activity_log.php"><i class="fa-solid fa-clipboard-list"></i> <span>Activity Log</span></a>
    </div>
    <div class="logout-section">
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span></button>
        </form>
    </div>
</div>

<div class="main-content">
    <div class="header">
        <h2><i class="fa-solid fa-users-gear"></i> Staff Management</h2>
        <button onclick="openModal('add')" class="btn-add"><i class="fa-solid fa-plus"></i> Add New Staff</button>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Created At</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                    <td>#<?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['fullname']) ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td>
                        <span class="role-badge <?= $u['role'] === 'manager' ? 'role-manager' : 'role-receptionist' ?>">
                            <?= ucfirst($u['role']) ?>
                        </span>
                    </td>
                    <td><?= date('d M Y', strtotime($u['created_at'] ?? 'now')) ?></td>
                    <td style="text-align:center;">
                        <button class="action-btn btn-edit" onclick='openModal("edit", <?= json_encode($u) ?>)'>
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        
                        <?php if($u['id'] != $_SESSION['user_id']): ?>
                        <a href="manage_staff.php?delete=<?= $u['id'] ?>" class="action-btn btn-delete" onclick="return confirm('Are you sure you want to delete this account? This cannot be undone.');">
                            <i class="fa-solid fa-trash"></i>
                        </a>
                        <?php else: ?>
                        <button class="action-btn btn-delete" disabled style="opacity: 0.5; cursor: not-allowed;" title="You cannot delete yourself">
                            <i class="fa-solid fa-ban"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="staffModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Add New Staff</h3>
            <span class="close-modal" onclick="closeModal()">&times;</span>
        </div>
        <form method="POST" id="staffForm">
            <input type="hidden" name="action" id="formAction" value="add_staff">
            <input type="hidden" name="user_id" id="userId">

            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="fullname" id="fullname" class="form-control" required placeholder="e.g. Juma Jux">
            </div>

            <div class="form-group">
                <label class="form-label">Username (Login ID)</label>
                <input type="text" name="username" id="username" class="form-control" required placeholder="e.g. juma">
            </div>

            <div class="form-group">
                <label class="form-label">Role</label>
                <select name="role" id="role" class="form-control">
                    <option value="receptionist">Receptionist</option>
                    <option value="manager">Manager</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" id="passLabel">Password</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="Enter password">
                <small id="passNote" style="display:none; color: #666; font-size: 0.8rem;">Leave blank to keep current password.</small>
            </div>

            <button type="submit" class="btn-save" id="btnSubmit">Create Staff</button>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('staffModal');
    const form = document.getElementById('staffForm');
    const modalTitle = document.getElementById('modalTitle');
    const formAction = document.getElementById('formAction');
    const userIdInput = document.getElementById('userId');
    const fullnameInput = document.getElementById('fullname');
    const usernameInput = document.getElementById('username');
    const roleInput = document.getElementById('role');
    const passInput = document.getElementById('password');
    const passNote = document.getElementById('passNote');
    const btnSubmit = document.getElementById('btnSubmit');

    function openModal(mode, data = null) {
        modal.style.display = 'flex';
        
        if (mode === 'edit' && data) {
            // Edit Mode
            modalTitle.textContent = 'Edit Staff Details';
            formAction.value = 'update_staff';
            btnSubmit.textContent = 'Update Staff';
            
            userIdInput.value = data.id;
            fullnameInput.value = data.fullname;
            usernameInput.value = data.username;
            roleInput.value = data.role;
            
            // Password handling for edit
            passInput.required = false;
            passInput.placeholder = "Enter new password (optional)";
            passNote.style.display = 'block';
        } else {
            // Add Mode
            modalTitle.textContent = 'Add New Staff';
            formAction.value = 'add_staff';
            btnSubmit.textContent = 'Create Staff';
            form.reset();
            
            passInput.required = true;
            passInput.placeholder = "Enter password";
            passNote.style.display = 'none';
        }
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    // Close modal if clicking outside
    window.onclick = function(event) {
        if (event.target == modal) {
            closeModal();
        }
    }
</script>

</body>
</html>