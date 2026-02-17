<?php
session_start();
include 'db_connect.php'; 

// Security: only manager
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'admin', 'ceo'])) {
    header("Location: login.php");
    exit();
}

// Session timeout (30 mins)
$timeout_duration = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

$fullname = $_SESSION['fullname'] ?? 'Manager';

// --- 1. DATA ZA VYUMBA (THE SOURCE OF TRUTH) ---

// A. Jumla ya Vyumba (Total Inventory)
$totalRooms = 0;
$resTotal = $conn->query("SELECT COUNT(*) as total FROM rooms");
if ($resTotal && $row = $resTotal->fetch_assoc()) { 
    $totalRooms = $row['total']; 
}

// B. Vyumba Vilivyo Wazi (Available Stock) - Hii ndio uliyotaka
$availableRooms = 0;
$resAvail = $conn->query("SELECT COUNT(*) as available FROM rooms WHERE status='Available'");
if ($resAvail && $row = $resAvail->fetch_assoc()) { 
    $availableRooms = $row['available']; 
}

// C. Vyumba vyenye Watu (Active Guests)
$occupiedRooms = 0;
$resOcc = $conn->query("SELECT COUNT(*) as occupied FROM rooms WHERE status='Occupied'");
if ($resOcc && $row = $resOcc->fetch_assoc()) { 
    $occupiedRooms = $row['occupied']; 
}

// D. Vyumba vilivyowekwa Booking (Reserved)
$reservedRooms = 0;
$resRes = $conn->query("SELECT COUNT(*) as reserved FROM rooms WHERE status='Reserved'");
if ($resRes && $row = $resRes->fetch_assoc()) { 
    $reservedRooms = $row['reserved']; 
}

// --- 2. DATA ZA PESA (REVENUE LEO) ---
$todayRevenue = 0;
$dateToday = date('Y-m-d');
$resRevenue = $conn->query("SELECT SUM(amount + extra_charges - discount) as total FROM payments WHERE DATE(payment_date) = '$dateToday'");
if ($resRevenue && $row = $resRevenue->fetch_assoc()) {
    $todayRevenue = $row['total'] ?? 0;
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manager Dashboard - Hotel Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; overflow-x: hidden; }

    /* SIDEBAR Styles */
    .sidebar { 
        position: fixed; left: 0; top: 0; width: 260px; height: 100vh; 
        background: #1e3a5f; color: #fff; padding: 30px 0; 
        display: flex; flex-direction: column; 
        box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1); 
        z-index: 1000; overflow-y: auto;
        transition: transform 0.3s ease-in-out; 
    }
    
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

    /* MAIN CONTENT Styles */
    .main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; transition: margin-left 0.3s ease-in-out; }
    
    /* HEADER Styles */
    .header { margin-bottom: 35px; background: #fff; padding: 25px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; align-items: center; gap: 15px; }
    .welcome { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; }
    .welcome span { color: #667eea; }
    
    /* Menu Toggle (Mobile) */
    .menu-toggle { display: none; font-size: 1.5rem; color: #1e3a5f; cursor: pointer; }

    /* STATS Styles */
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 35px; }
    .stat-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); border-left: 4px solid #667eea; transition: all 0.3s ease; }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1); }
    .stat-card h3 { font-size: 0.9rem; font-weight: 600; color: #7f8c8d; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
    .stat-card p { font-size: 2rem; font-weight: 700; color: #1e3a5f; }

    /* CARDS Styles */
    .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; }
    .card { background: #fff; border-radius: 15px; padding: 35px 30px; text-align: center; cursor: pointer; transition: all 0.4s ease; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); border: 2px solid transparent; }
    .card:hover { transform: translateY(-8px); box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15); border-color: #667eea; }
    .card-icon { width: 70px; height: 70px; margin: 0 auto 20px auto; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .card i { font-size: 2rem; color: #fff; }
    .card:nth-child(2) .card-icon { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
    .card:nth-child(3) .card-icon { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .card:nth-child(4) .card-icon { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
    .card h3 { margin: 15px 0 10px 0; font-weight: 600; font-size: 1.2rem; color: #1e3a5f; }
    .card p { font-size: 0.9rem; color: #7f8c8d; line-height: 1.5; }

    /* MOBILE OVERLAY */
    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 900; }

    /* --- RESPONSIVE CSS --- */
    @media (max-width: 768px) { 
        .sidebar { transform: translateX(-100%); width: 250px; } 
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        
        .main-content { margin-left: 0; padding: 20px 15px; }
        
        .header { padding: 15px; }
        .welcome { font-size: 1.3rem; }
        .menu-toggle { display: block; }
        
        .cards { grid-template-columns: 1fr; }
        .stats { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2>HGMA System</h2>
        <p>Hotel Management</p>
    </div>
    
    <div class="sidebar-nav">
        <a href="manager_dashboard.php" class="<?= $currentPage=='manager_dashboard.php'?'active':'' ?>">
            <i class="fa-solid fa-house"></i> <span>Dashboard</span>
        </a>
        
        <a href="manage_staff.php" class="<?= $currentPage=='manage_staff.php'?'active':'' ?>">
            <i class="fa-solid fa-user-shield"></i> <span>Manage Staff</span>
        </a>

        <a href="room_details.php" class="<?= $currentPage=='room_details.php'?'active':'' ?>">
            <i class="fa-solid fa-bed"></i> <span>Room Details</span>
        </a>
        <a href="payment_reports.php" class="<?= $currentPage=='payment_reports.php'?'active':'' ?>">
            <i class="fa-solid fa-credit-card"></i> <span>Payment Reports</span>
        </a>
        <a href="analytics.php" class="<?= $currentPage=='analytics.php'?'active':'' ?>">
            <i class="fa-solid fa-chart-line"></i> <span>Analytics</span>
        </a>
        <a href="activity_log.php" class="<?= $currentPage=='activity_log.php'?'active':'' ?>">
            <i class="fa-solid fa-clipboard-list"></i> <span>Activity Log</span>
        </a>
        <a href="db_maintenance.php" class="<?= $currentPage=='db_maintenance.php'?'active':'' ?>">
            <i class="fa-solid fa-screwdriver-wrench"></i> <span>Maintenance</span>
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
        <i class="fa-solid fa-bars menu-toggle" onclick="toggleSidebar()"></i>
        <div class="welcome">Welcome back, <span><?= htmlspecialchars($fullname) ?></span></div>
    </div>

    <div class="stats">
        <div class="stat-card" style="border-left-color: #28a745;">
            <h3>Revenue Today</h3>
            <p style="color: #28a745;">TZS <?= number_format($todayRevenue) ?></p>
        </div>

        <div class="stat-card" style="border-left-color: #667eea;">
            <h3>Active Guests</h3>
            <p><?= $occupiedRooms ?></p>
        </div>
        
        <div class="stat-card" style="border-left-color: #00d2d3;">
            <h3>Available Rooms</h3>
            <p><?= $availableRooms ?> / <?= $totalRooms ?></p>
        </div>

        <div class="stat-card" style="border-left-color: #ffc107;">
            <h3>Reserved Rooms</h3>
            <p><?= $reservedRooms ?></p>
        </div>
    </div>

    <div class="cards">
        <div class="card" onclick="window.location.href='manage_staff.php';">
            <div class="card-icon" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                <i class="fa-solid fa-user-gear"></i>
            </div>
            <h3>Manage Staff</h3>
            <p>Add new Receptionists, Reset Passwords, or Remove Staff.</p>
        </div>

        <div class="card" onclick="window.location.href='room_details.php';">
            <div class="card-icon">
                <i class="fa-solid fa-bed"></i>
            </div>
            <h3>Room Details</h3>
            <p>Manage room information and availability status</p>
        </div>
        
        <div class="card" onclick="window.location.href='payment_reports.php';">
            <div class="card-icon">
                <i class="fa-solid fa-credit-card"></i>
            </div>
            <h3>Payment Reports</h3>
            <p>View daily, weekly, and monthly financial reports</p>
        </div>
        
        <div class="card" onclick="window.location.href='analytics.php';">
            <div class="card-icon">
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <h3>Analytics</h3>
            <p>Track guest frequency and booking trends</p>
        </div>
        
        <div class="card" onclick="window.location.href='activity_log.php';">
            <div class="card-icon">
                <i class="fa-solid fa-clipboard-list"></i>
            </div>
            <h3>Activity Log</h3>
            <p>Monitor all reception staff activities and actions</p>
        </div>

        <div class="card" onclick="window.location.href='db_maintenance.php';">
            <div class="card-icon" style="background: linear-gradient(135deg, #2c3e50 0%, #bdc3c7 100%);">
                <i class="fa-solid fa-screwdriver-wrench"></i>
            </div>
            <h3>Maintenance</h3>
            <p>Database backup and system maintenance tools</p>
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