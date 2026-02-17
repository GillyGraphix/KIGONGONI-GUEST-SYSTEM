<?php
session_start();
include 'db_connect.php'; 

// Security: Only receptionist
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

$currentPage = basename($_SERVER['PHP_SELF']); 

// --- 1. PATA TAKWIMU ZA HARAKA (STATS) ---
$stats = ['Available' => 0, 'Occupied' => 0, 'Reserved' => 0, 'Maintenance' => 0];
$stat_query = mysqli_query($conn, "SELECT status, COUNT(*) as total FROM rooms GROUP BY status");
if ($stat_query) {
    while ($row = mysqli_fetch_assoc($stat_query)) {
        $stats[$row['status']] = $row['total'];
    }
}
$total_rooms = array_sum($stats);

// --- 2. SEARCH & FILTER ---
$search = $_GET['search'] ?? '';
$query = "SELECT * FROM rooms WHERE 1";
if (!empty($search)) {
    $search_safe = mysqli_real_escape_string($conn, $search);
    $query .= " AND (room_name LIKE '%$search_safe%' OR room_type LIKE '%$search_safe%')";
}
$query .= " ORDER BY room_id ASC";

$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Room Status - Hotel Management System</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    /* STANDARD DASHBOARD STYLES */
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; overflow-x: hidden; }

    /* Sidebar Styling */
    .sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: #1e3a5f; color: #fff; padding: 30px 0; display: flex; flex-direction: column; box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1); z-index: 1000; overflow-y: auto; transition: transform 0.3s ease-in-out; }
    .sidebar-header { padding: 0 25px 30px 25px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); margin-bottom: 20px; }
    .sidebar-header h2 { font-weight: 600; font-size: 1.2rem; color: #fff; margin-bottom: 5px; text-align: center; }
    .sidebar-header p { font-size: 0.85rem; color: rgba(255, 255, 255, 0.7); text-align: center; }
    .sidebar-nav { flex: 1; padding: 0 15px; }
    .sidebar a { text-decoration: none; color: rgba(255, 255, 255, 0.85); display: flex; align-items: center; padding: 14px 18px; margin: 5px 0; border-radius: 10px; transition: all 0.3s ease; font-weight: 500; font-size: 0.95rem; }
    .sidebar a i { margin-right: 14px; font-size: 1.1rem; width: 20px; text-align: center; }
    .sidebar a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; transform: translateX(5px); }
    .sidebar a.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
    .logout-section { padding: 0 15px 20px 15px; border-top: 1px solid rgba(255, 255, 255, 0.1); margin-top: 20px; padding-top: 20px; }
    .logout-btn { width: 100%; padding: 12px 18px; background: #dc3545; color: #fff; border: none; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; font-weight: 600; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; }
    .logout-btn i { margin-right: 10px; }
    .logout-btn:hover { background: #c82333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3); }

    /* Main Content */
    .main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; transition: margin-left 0.3s ease-in-out; }

    /* Header */
    .header { margin-bottom: 35px; background: #fff; padding: 25px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; align-items: center; justify-content: space-between; }
    .header-title { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; display: flex; align-items: center; gap: 10px; }
    .menu-toggle { display: none; font-size: 1.5rem; color: #1e3a5f; cursor: pointer; }

    /* Stats Grid */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background: #fff; padding: 25px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-left: 5px solid #ccc; display: flex; align-items: center; justify-content: space-between; transition: transform 0.3s ease; }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
    .stat-card h3 { font-size: 0.85rem; color: #7f8c8d; text-transform: uppercase; margin-bottom: 5px; font-weight: 700; letter-spacing: 0.5px; }
    .stat-card .number { font-size: 1.8rem; font-weight: 800; color: #2c3e50; }
    .stat-card i { font-size: 2.2rem; opacity: 0.8; }

    /* Stat Colors */
    .stat-available { border-left-color: #28a745; }
    .stat-available i { color: #28a745; }
    .stat-occupied { border-left-color: #dc3545; }
    .stat-occupied i { color: #dc3545; }
    .stat-reserved { border-left-color: #ffc107; }
    .stat-reserved i { color: #ffc107; }
    .stat-total { border-left-color: #1e3a5f; }
    .stat-total i { color: #1e3a5f; }

    /* Search Card */
    .search-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); margin-bottom: 25px; }
    .search-form { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
    .form-group { flex: 1; min-width: 200px; }
    .form-label { font-weight: 600; margin-bottom: 8px; color: #2c3e50; font-size: 0.9rem; display: block; }
    .form-control { width: 100%; padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 0.95rem; background: #f8f9fa; }
    .form-control:focus { outline: none; border-color: #667eea; background: #fff; }
    
    .btn-search, .btn-reset { padding: 12px 24px; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; }
    .btn-search { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
    .btn-search:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4); }
    .btn-reset { background: #6c757d; color: #fff; text-decoration: none; }
    .btn-reset:hover { background: #5a6268; }

    /* Table */
    .table-container { background: #fff; border-radius: 15px; padding: 0; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); overflow: hidden; border: 1px solid #e9ecef; }
    .table-responsive { overflow-x: auto; max-height: 70vh; overflow-y: auto; }
    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    table th { background: #1e3a5f; color: #fff; text-align: left; padding: 18px 20px; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
    table td { padding: 18px 20px; border-bottom: 1px solid #e9ecef; color: #2c3e50; vertical-align: middle; white-space: nowrap; font-size: 0.9rem; }
    table tbody tr:hover { background: #f8f9fa; }

    /* Badges */
    .badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; text-transform: uppercase; color: #fff; }
    .badge-Available { background: #28a745; }
    .badge-Occupied { background: #dc3545; }
    .badge-Reserved { background: #ffc107; color: #000; }
    .badge-Maintenance { background: #6c757d; }

    /* Mobile Overlay */
    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 900; }

    /* Responsive */
    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); width: 250px; }
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        .main-content { margin-left: 0; padding: 20px 15px; }
        .header { flex-direction: column; align-items: flex-start; gap: 15px; }
        .menu-toggle { display: block; }
        .search-form { flex-direction: column; align-items: stretch; }
        .btn-search, .btn-reset { width: 100%; justify-content: center; }
        .stats-grid { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2>Receptionist Dashboard</h2>
        <p>Front Desk Management</p>
    </div>
    
    <div class="sidebar-nav">
        <a href="receptionist_dashboard.php" class="<?= $currentPage=='receptionist_dashboard.php'?'active':'' ?>">
            <i class="fa-solid fa-house"></i> <span>Dashboard</span>
        </a>
        <a href="add_guest.php" class="<?= $currentPage=='add_guest.php'?'active':'' ?>">
            <i class="fa-solid fa-user-plus"></i> <span>Add Guest</span>
        </a>
        <a href="view_guests.php" class="<?= $currentPage=='view_guests.php'?'active':'' ?>">
            <i class="fa-solid fa-users"></i> <span>View Guests</span>
        </a>
        <a href="rooms.php" class="<?= $currentPage=='rooms.php'?'active':'' ?>"> 
            <i class="fa-solid fa-bed"></i> <span>Rooms </span>
        </a>
        <a href="checkin_checkout.php" class="<?= $currentPage=='checkin_checkout.php'?'active':'' ?>">
            <i class="fa-solid fa-door-open"></i> <span>Check-in / Check-out</span>
        </a>
        <a href="payments.php" class="<?= $currentPage=='payments.php'?'active':'' ?>">
            <i class="fa-solid fa-credit-card"></i> <span>Payments</span>
        </a>
        <a href="bookings.php" class="<?= $currentPage=='bookings.php'?'active':'' ?>">
            <i class="fa-solid fa-calendar-check"></i> <span>Reservations</span>
        </a>
    </div>
    
    <div class="logout-section">
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span>
            </button>
        </form>
    </div>
</div>

<div class="main-content">
    <div class="header">
        <div class="header-title">
            <i class="fa-solid fa-bars menu-toggle" onclick="toggleSidebar()"></i>
            <span><i class="fa-solid fa-bed"></i> Room Inventory</span>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card stat-occupied">
            <div>
                <h3>Occupied (Active)</h3>
                <div class="number"><?= $stats['Occupied'] ?></div>
            </div>
            <i class="fa-solid fa-user-check"></i>
        </div>
        <div class="stat-card stat-reserved">
            <div>
                <h3>Reserved (Bookings)</h3>
                <div class="number"><?= $stats['Reserved'] ?></div>
            </div>
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div class="stat-card stat-available">
            <div>
                <h3>Available</h3>
                <div class="number"><?= $stats['Available'] ?></div>
            </div>
            <i class="fa-solid fa-door-open"></i>
        </div>
        <div class="stat-card stat-total">
            <div>
                <h3>Total Rooms</h3>
                <div class="number"><?= $total_rooms ?></div>
            </div>
            <i class="fa-solid fa-building"></i>
        </div>
    </div>

    <div class="search-card">
        <form method="GET" class="search-form">
            <div class="form-group">
                <label class="form-label">Quick Search</label>
                <input type="text" name="search" class="form-control" placeholder="Enter room name or type..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn-search"><i class="fa-solid fa-search"></i> Search</button>
            <a href="rooms.php" class="btn-reset"><i class="fa-solid fa-rotate-right"></i> Reset</a>
        </form>
    </div>

    <div class="table-container">
        <div class="table-responsive">
            <table>
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
                                    $statusClass = str_replace(' ', '', $status); // Remove spaces for class
                                    echo '<span class="badge badge-'.$statusClass.'">'.$status.'</span>';
                                ?>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="5" style="text-align:center; padding: 30px; color: #7f8c8d;">No room records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('active');
        document.querySelector('.sidebar-overlay').classList.toggle('active');
    }
</script>

</body>
</html>