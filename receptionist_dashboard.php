<?php
session_start();
include 'db_connect.php';

// Security: only receptionist
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

// Session timeout (30 mins)
$timeout_duration = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && 
    (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

$fullname = $_SESSION['fullname'];

// Fetch total guests
$totalGuests = 0;
$res1 = $conn->query("SELECT COUNT(*) as total FROM guest");
if ($res1 && $res1->num_rows > 0) {
    $totalGuests = $res1->fetch_assoc()['total'];
}

// Fetch available rooms
$availableRooms = 0;
$res2 = $conn->query("SELECT COUNT(*) as available FROM rooms WHERE status='Available'");
if ($res2 && $res2->num_rows > 0) {
    $availableRooms = $res2->fetch_assoc()['available'];
}

// Current page for sidebar highlight
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receptionist Dashboard - Hotel Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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

.sidebar-nav::-webkit-scrollbar {
    width: 6px;
}

.sidebar-nav::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 10px;
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
    margin-bottom: 35px;
    background: #fff;
    padding: 25px 30px;
    border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.welcome {
    font-size: 1.6rem;
    font-weight: 700;
    color: #1e3a5f;
}

.welcome span {
    color: #667eea;
}

/* Statistics Cards */
.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 35px;
}

.stat-card {
    background: #fff;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    border-left: 4px solid #667eea;
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
}

.stat-card h3 {
    font-size: 0.9rem;
    font-weight: 600;
    color: #7f8c8d;
    margin-bottom: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-card p {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a5f;
}

.stat-card:nth-child(2) {
    border-left-color: #f093fb;
}

/* Action Cards */
.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
}

.card {
    background: #fff;
    border-radius: 15px;
    padding: 35px 30px;
    text-align: center;
    cursor: pointer;
    transition: all 0.4s ease;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    border: 2px solid transparent;
}

.card:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
    border-color: #667eea;
}

.card-icon {
    width: 70px;
    height: 70px;
    margin: 0 auto 20px auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.card i {
    font-size: 2rem;
    color: #fff;
}

.card:nth-child(2) .card-icon {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.card:nth-child(3) .card-icon {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.card:nth-child(4) .card-icon {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.card:nth-child(5) .card-icon {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
}

.card:nth-child(6) .card-icon {
    background: linear-gradient(135deg, #30cfd0 0%, #330867 100%);
}

.card h3 {
    margin: 15px 0 10px 0;
    font-weight: 600;
    font-size: 1.2rem;
    color: #1e3a5f;
}

.card p {
    font-size: 0.9rem;
    color: #7f8c8d;
    line-height: 1.5;
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
    
    .cards {
        grid-template-columns: 1fr;
    }
}
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
        <div class="welcome">Welcome back, <span><?php echo htmlspecialchars($fullname); ?></span></div>
    </div>

    <div class="stats">
        <div class="stat-card">
            <h3>Total Guests</h3>
            <p><?php echo $totalGuests; ?></p>
        </div>
        <div class="stat-card">
            <h3>Available Rooms</h3>
            <p><?php echo $availableRooms; ?></p>
        </div>
    </div>

    <div class="cards">
        <div class="card" onclick="window.location.href='add_guest.php';">
            <div class="card-icon">
                <i class="fa-solid fa-user-plus"></i>
            </div>
            <h3>Add Guest</h3>
            <p>Register a new guest to the system</p>
        </div>
        <div class="card" onclick="window.location.href='view_guests.php';">
            <div class="card-icon">
                <i class="fa-solid fa-users"></i>
            </div>
            <h3>View Guests</h3>
            <p>See all registered guests information</p>
        </div>
        <div class="card" onclick="window.location.href='rooms.php';">
            <div class="card-icon">
                <i class="fa-solid fa-bed"></i>
            </div>
            <h3>Rooms</h3>
            <p>Check room availability and status</p>
        </div>
        <div class="card" onclick="window.location.href='checkin_checkout.php';">
            <div class="card-icon">
                <i class="fa-solid fa-door-open"></i>
            </div>
            <h3>Check-in / Check-out</h3>
            <p>Manage guest arrivals and departures</p>
        </div>
        <div class="card" onclick="window.location.href='payments.php';">
            <div class="card-icon">
                <i class="fa-solid fa-credit-card"></i>
            </div>
            <h3>Payments</h3>
            <p>Track and process guest payments</p>
        </div>
        <div class="card" onclick="window.location.href='messages.php';">
            <div class="card-icon">
                <i class="fa-solid fa-bell"></i>
            </div>
            <h3>Messages / Notifications</h3>
            <p>View guest alerts and notifications</p>
        </div>
    </div>
</div>

</body>
</html>