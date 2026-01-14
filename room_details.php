<?php
session_start();
include 'db_connect.php';

// Security: Only admin/manager/ceo
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','manager','ceo'])) {
    header("Location: login.php");
    exit();
}

$fullname = $_SESSION['fullname'];
$currentPage = basename($_SERVER['PHP_SELF']);

// Handle delete request
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $stmt = $conn->prepare("DELETE FROM rooms WHERE room_id=?");
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $stmt->close();

    echo "<script>
        sessionStorage.setItem('roomDeleted', '1');
        window.location.href='room_details.php';
    </script>";
    exit();
}

// Handle form submission (Add or Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_id = $_POST['room_id'] ?? '';
    $room_name = strtoupper($_POST['room_name']);
    $room_type = strtoupper($_POST['room_type']);
    $room_rate = $_POST['room_rate'];

    if ($room_id) {
        $stmt = $conn->prepare("UPDATE rooms SET room_name=?, room_type=?, room_rate=? WHERE room_id=?");
        $stmt->bind_param("ssdi", $room_name, $room_type, $room_rate, $room_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO rooms (room_name, room_type, room_rate, status) VALUES (?, ?, ?, 'AVAILABLE')");
        $stmt->bind_param("ssd", $room_name, $room_type, $room_rate);
        $stmt->execute();
        $stmt->close();
    }

    echo "<script>
        sessionStorage.setItem('roomSaved', '1');
        window.location.href='room_details.php';
    </script>";
    exit();
}

// Fetch all rooms
$result = $conn->query("SELECT * FROM rooms ORDER BY room_id ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Room Details - Hotel Management System</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Inter', sans-serif;
}

body {
    background: #f5f7fa;
    color: #2c3e50;
    min-height: 100vh;
}

/* Sidebar Styling */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 260px;
    height: 100vh;
    background: #1e3a5f;
    color: #fff;
    padding: 30px 0;
    display: flex;
    flex-direction: column;
    box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
    z-index: 1000;
}

.sidebar-header {
    padding: 0 25px 30px 25px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    margin-bottom: 20px;
}

.sidebar-header h2 {
    font-weight: 600;
    font-size: 1.4rem;
    color: #fff;
    margin-bottom: 5px;
}

.sidebar-header p {
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.7);
}

.sidebar-nav {
    flex: 1;
    padding: 0 15px;
}

.sidebar a {
    text-decoration: none;
    color: rgba(255, 255, 255, 0.85);
    display: flex;
    align-items: center;
    padding: 14px 18px;
    margin: 5px 0;
    border-radius: 10px;
    transition: all 0.3s ease;
    font-weight: 500;
    font-size: 0.95rem;
}

.sidebar a i {
    margin-right: 14px;
    font-size: 1.1rem;
    width: 20px;
    text-align: center;
}

.sidebar a:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
    transform: translateX(5px);
}

.sidebar a.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
}

.logout-section {
    padding: 0 15px 20px 15px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    margin-top: 20px;
    padding-top: 20px;
}

.logout-btn {
    width: 100%;
    padding: 12px 18px;
    background: #dc3545;
    color: #fff;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 600;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.logout-btn i {
    margin-right: 10px;
}

.logout-btn:hover {
    background: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

/* Main Content */
.main-content {
    margin-left: 260px;
    padding: 35px 40px;
    min-height: 100vh;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    background: #fff;
    padding: 25px 30px;
    border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.header h2 {
    font-size: 1.6rem;
    font-weight: 700;
    color: #1e3a5f;
}

.add-btn {
    padding: 12px 24px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.add-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

/* Table Container */
.table-container {
    background: #fff;
    border-radius: 15px;
    padding: 30px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    border: none;
}

table th {
    background: #1e3a5f;
    color: #fff;
    text-align: left;
    padding: 15px;
    font-weight: 600;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

table td {
    padding: 15px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
    vertical-align: middle;
    color: #2c3e50;
}

table tbody tr {
    transition: all 0.2s ease;
}

table tbody tr:hover {
    background: #f8f9fa;
}

/* Status Badges */
.badge-AVAILABLE {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-block;
}

.badge-OCCUPIED {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-block;
}

.badge-RESERVED {
    background: linear-gradient(135deg, #ffc107, #ff9800);
    color: #000;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-block;
}

/* Action Buttons */
table td button {
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-right: 8px;
}

table td button:first-of-type {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: #fff;
}

table td button:first-of-type:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(79, 172, 254, 0.4);
}

.delete-btn {
    background: linear-gradient(135deg, #dc3545, #c82333) !important;
    color: #fff !important;
}

.delete-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(5px);
}

.modal-content {
    background: #fff;
    padding: 35px;
    border-radius: 15px;
    width: 100%;
    max-width: 500px;
    position: relative;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.close-btn {
    position: absolute;
    top: 15px;
    right: 20px;
    font-size: 1.8rem;
    color: #7f8c8d;
    cursor: pointer;
    transition: all 0.3s ease;
    width: 35px;
    height: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.close-btn:hover {
    background: #f8f9fa;
    color: #dc3545;
}

.modal-content h3 {
    margin-bottom: 25px;
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e3a5f;
}

form input,
form select {
    padding: 12px 15px;
    margin: 10px 0;
    width: 100%;
    border-radius: 10px;
    border: 2px solid #e9ecef;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: #f8f9fa;
}

form input:focus,
form select:focus {
    outline: none;
    border-color: #667eea;
    background: #fff;
}

form button.save-btn {
    padding: 12px 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border: none;
    border-radius: 10px;
    width: 100%;
    font-weight: 600;
    font-size: 1rem;
    margin-top: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
}

form button.save-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

.text-center {
    text-align: center;
    color: #7f8c8d;
    font-style: italic;
}

/* Responsive Design */
@media (max-width: 768px) {
    .sidebar {
        width: 70px;
    }
    
    .sidebar-header h2,
    .sidebar-header p,
    .sidebar a span {
        display: none;
    }
    
    .sidebar a {
        justify-content: center;
        padding: 14px;
    }
    
    .sidebar a i {
        margin: 0;
    }
    
    .logout-btn span {
        display: none;
    }
    
    .main-content {
        margin-left: 70px;
        padding: 20px;
    }
    
    .header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    table {
        font-size: 0.85rem;
    }
    
    table th,
    table td {
        padding: 10px;
    }
}
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>HGMA System</h2>
        <p>Hotel Management</p>
    </div>
    
    <div class="sidebar-nav">
        <a href="manager_dashboard.php" class="<?= $currentPage=='manager_dashboard.php'?'active':'' ?>">
            <i class="fa-solid fa-house"></i> 
            <span>Dashboard</span>
        </a>
        
        <a href="manage_staff.php" class="<?= $currentPage=='manage_staff.php'?'active':'' ?>">
            <i class="fa-solid fa-user-shield"></i> 
            <span>Manage Staff</span>
        </a>
        <a href="room_details.php" class="<?= $currentPage=='room_details.php'?'active':'' ?>">
            <i class="fa-solid fa-bed"></i> 
            <span>Room Details</span>
        </a>
        <a href="payment_reports.php" class="<?= $currentPage=='payment_reports.php'?'active':'' ?>">
            <i class="fa-solid fa-credit-card"></i> 
            <span>Payment Reports</span>
        </a>
        <a href="analytics.php" class="<?= $currentPage=='analytics.php'?'active':'' ?>">
            <i class="fa-solid fa-chart-line"></i> 
            <span>Analytics</span>
        </a>
        <a href="activity_log.php" class="<?= $currentPage=='activity_log.php'?'active':'' ?>">
            <i class="fa-solid fa-clipboard-list"></i> 
            <span>Activity Log</span>
        </a>
    </div>
    
    <div class="logout-section">
        <form action="logout.php" method="post">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-right-from-bracket"></i> 
                <span>Logout</span>
            </button>
        </form>
    </div>
</div>

<div class="main-content">
    <div class="header">
        <h2>Room Details</h2>
        <button class="add-btn" onclick="openModal()">
            <i class="fa-solid fa-plus"></i> Add Room
        </button>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Room Name</th>
                    <th>Room Type</th>
                    <th>Rate (TZS)</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if($result && $result->num_rows>0): $i=1; while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($row['room_name']) ?></td>
                        <td><?= htmlspecialchars($row['room_type']) ?></td>
                        <td><?= number_format($row['room_rate'],2) ?></td>
                        <td><span class="badge-<?= strtoupper($row['status']) ?>"><?= strtoupper($row['status']) ?></span></td>
                        <td>
                            <button onclick="openModal('<?= $row['room_id'] ?>','<?= $row['room_name'] ?>','<?= $row['room_type'] ?>','<?= $row['room_rate'] ?>')">
                                <i class="fa-solid fa-edit"></i> Edit
                            </button>
                            <button class="delete-btn" onclick="confirmDelete(<?= $row['room_id'] ?>)">
                                <i class="fa-solid fa-trash"></i> Delete
                            </button>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="6" class="text-center">No rooms found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="roomModal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal()">&times;</span>
        <h3 id="modalTitle">Add Room</h3>
        <form method="POST">
            <input type="hidden" name="room_id" id="room_id">
            <input type="text" name="room_name" id="room_name" placeholder="Room Name (e.g., A101)" required>
            <select name="room_type" id="room_type" required>
                <option value="">Select Room Type</option>
                <option value="DOUBLE">Double</option>
                <option value="TRIPLE">Triple</option>
                <option value="TWIN">Twin</option>
            </select>
            <input type="number" step="0.01" name="room_rate" id="room_rate" placeholder="Room Rate (TZS)" required>
            <button type="submit" class="save-btn">Save Room</button>
        </form>
    </div>
</div>

<script>
function openModal(id='', name='', type='', rate=''){
    document.getElementById('room_id').value=id;
    document.getElementById('room_name').value=name;
    document.getElementById('room_type').value=type;
    document.getElementById('room_rate').value=rate;
    document.getElementById('modalTitle').innerText = id ? 'Edit Room' : 'Add Room';
    document.getElementById('roomModal').style.display='flex';
}
function closeModal(){ document.getElementById('roomModal').style.display='none'; }
window.onclick = function(e){ if(e.target == document.getElementById('roomModal')) closeModal(); }

function confirmDelete(id){
    Swal.fire({
        title: 'Are you sure?',
        text: 'This room will be permanently deleted!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!'
    }).then((result)=>{
        if(result.isConfirmed){
            window.location.href='room_details.php?delete_id='+id;
        }
    });
}

// SweetAlert success alerts
document.addEventListener("DOMContentLoaded", ()=>{
    if(sessionStorage.getItem('roomSaved')){
        Swal.fire({icon:'success', title:'Success', text:'Room saved successfully!', timer:2000, showConfirmButton:false});
        sessionStorage.removeItem('roomSaved');
    }
    if(sessionStorage.getItem('roomDeleted')){
        Swal.fire({icon:'success', title:'Deleted', text:'Room deleted successfully!', timer:2000, showConfirmButton:false});
        sessionStorage.removeItem('roomDeleted');
    }
});
</script>

</body>
</html>