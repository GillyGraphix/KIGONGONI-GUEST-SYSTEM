<?php
session_start();
include 'config.php'; // Changed from db_connect.php to config.php as per your new code

// Security: Only receptionist
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'receptionist') {
    // Hii sehemu ni muhimu. Kama bado ina-logout, angalia 'config.php' au Session
    header("Location: login.php");
    exit();
}

// NOTE: Jina la faili hili ni 'rooms.php'
$currentPage = basename($_SERVER['PHP_SELF']); 

// Search filter
$search = $_GET['search'] ?? '';

// Query rooms
$query = "SELECT * FROM rooms WHERE 1";
if (!empty($search)) {
    $search_safe = mysqli_real_escape_string($conn, $search);
    $query .= " AND (room_name LIKE '%$search_safe%' OR room_type LIKE '%$search_safe%')";
}
$query .= " ORDER BY room_id ASC";

$result = mysqli_query($conn, $query);
if (!$result) {
    die("Query Error: " . mysqli_error($conn));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Room Details - Hotel Management System</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ------------------------------------------------------------------- */
/* --- APPLIED MODERN DASHBOARD STYLES (DEEP BLUE, GRADIENTS, CARDS) --- */
/* ------------------------------------------------------------------- */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Inter', sans-serif;
}

body {
    background: #f5f7fa; /* Light background */
    color: #2c3e50;
    min-height: 100vh;
}

/* Sidebar Styling */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 260px; /* Wider sidebar */
    height: 100vh;
    background: #1e3a5f; /* Deep Blue */
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
    font-size: 1.2rem;
    color: #fff;
    margin-bottom: 5px;
    text-align: center;
}

.sidebar-header p {
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.7);
    text-align: center;
}

.sidebar-nav {
    flex: 1;
    padding: 0 15px;
    overflow-y: auto;
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
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); /* Primary Gradient */
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
    background: #d93041ff; /* Red Button */
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
    margin-left: 260px; /* Adjusted margin for wider sidebar */
    padding: 35px 40px;
    min-height: 100vh;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    background: #fff; /* Card Style */
    padding: 25px 30px;
    border-radius: 15px; /* Card Style */
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); /* Card Style */
}

.header h2 {
    font-size: 1.6rem;
    font-weight: 700;
    color: #1e3a5f;
}

/* Search Card */
.search-card {
    background: #fff;
    border-radius: 15px;
    padding: 25px 30px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    margin-bottom: 25px;
}

.search-form {
    display: flex;
    gap: 15px;
    align-items: flex-end;
}

.form-group {
    flex: 1;
}

.form-label {
    font-weight: 600;
    margin-bottom: 8px;
    color: #1e3a5f;
    font-size: 0.9rem;
    display: block;
}

.form-control {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: #f8f9fa;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    background: #fff;
}

.btn-search {
    padding: 12px 24px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-search:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

.btn-reset {
    padding: 12px 24px;
    background: #6c757d;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
}

.btn-reset:hover {
    background: #5a6268;
    color: #fff;
}

/* Table Container (Card Style) */
.table-container {
    background: #fff;
    border-radius: 15px;
    padding: 30px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); /* Card Style */
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    border: none;
}

table th {
    background: #1e3a5f; /* Deep blue header */
    color: #fff;
    text-align: left; /* Aligned left for better flow */
    padding: 15px;
    font-weight: 600;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

table td {
    padding: 15px;
    text-align: left; /* Aligned left for better flow */
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
.badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-block;
}

.badge-Available { /* Renamed for PHP output consistency */
    background: linear-gradient(135deg, #28a745, #20c997);
    color: #fff;
}

.badge-Occupied {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: #fff;
}

.badge-Reserved {
    background: linear-gradient(135deg, #ffc107, #ff9800);
    color: #000;
}

.badge-Maintenance, .badge-Outofservice { /* Fallback for other statuses */
    background: linear-gradient(135deg, #6c757d, #5a6268);
    color: #fff;
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
    /* Adjusted main content margin for small screen */
    .main-content {
        margin-left: 70px;
        padding: 20px;
    }
    
    .search-form {
        flex-direction: column;
    }
    
    table {
        font-size: 0.85rem;
    }
    
    table th,
    table td {
        padding: 10px;
    }
}
/* ------------------------------------------------------------------- */
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>Receptionist Dashboard</h2>
        <p>Front Desk Management</p>
    </div>
    
    <div class="sidebar-nav">
        <a href="receptionist_dashboard.php" class="<?= $currentPage=='receptionist_dashboard.php'?'active':'' ?>">
            <i class="fa-solid fa-house"></i> 
            <span>Dashboard</span>
        </a>
        <a href="add_guest.php" class="<?= $currentPage=='add_guest.php'?'active':'' ?>">
            <i class="fa-solid fa-user-plus"></i> 
            <span>Add Guest</span>
        </a>
        <a href="view_guests.php" class="<?= $currentPage=='view_guests.php'?'active':'' ?>">
            <i class="fa-solid fa-users"></i> 
            <span>View Guests</span>
        </a>
        <a href="rooms.php" class="<?= $currentPage=='rooms.php'?'active':'' ?>"> 
            <i class="fa-solid fa-bed"></i> 
            <span>Rooms </span>
        </a>
        <a href="checkin_checkout.php" class="<?= $currentPage=='checkin_checkout.php'?'active':'' ?>">
            <i class="fa-solid fa-door-open"></i> 
            <span>Check-in / Check-out</span>
        </a>
        <a href="payments.php" class="<?= $currentPage=='payments.php'?'active':'' ?>">
            <i class="fa-solid fa-credit-card"></i> 
            <span>Payments</span>
        </a>
        <a href="messages.php" class="<?= $currentPage=='messages.php'?'active':'' ?>">
            <i class="fa-solid fa-bell"></i> 
            <span>Messages</span>
        </a>
    </div>
    
    <div class="logout-section">
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-right-from-bracket"></i> 
                <span>Logout</span>
            </button>
        </form>
    </div>
</div>

<div class="main-content">
    <div class="header">
        <h2><i class="fa-solid fa-bed"></i> Rooms </h2>
    </div>

    <div class="search-card">
        <form method="GET" class="search-form">
            <div class="form-group">
                <label class="form-label">Search Room</label>
                <input type="text" name="search" class="form-control" placeholder="Enter room name or type..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div>
                <button type="submit" class="btn-search">
                    <i class="fa-solid fa-search"></i> Search
                </button>
            </div>
            <div>
                <a href="rooms.php" class="btn-reset">
                    <i class="fa-solid fa-rotate-right"></i> Reset
                </a>
            </div>
        </form>
    </div>

    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Room Name</th>
                        <th>Room Type</th>
                        <th>Rate (TZS)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(mysqli_num_rows($result) > 0): $i=1; while($row = mysqli_fetch_assoc($result)): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><strong><?= htmlspecialchars($row['room_name']) ?></strong></td>
                            <td><?= htmlspecialchars($row['room_type']) ?></td>
                            <td><strong><?= number_format($row['room_rate'], 2) ?></strong></td>
                            <td>
                                <?php 
                                    $status = htmlspecialchars($row['status']);
                                    // Match status string with the badge class name (e.g., "Available" -> "badge-Available")
                                    // Remove spaces to match the CSS class names (e.g., "Out of Service" -> "OutofService")
                                    $statusClass = str_replace(' ', '', $status); 
                                    echo '<span class="badge badge-'.$statusClass.'">'.$status.'</span>';
                                ?>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="5" class="text-center">No room records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>