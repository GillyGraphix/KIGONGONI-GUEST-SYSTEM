<?php
session_start();
include 'db_connect.php';

// Security check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: login.php");
    exit();
}

$fullname = $_SESSION['fullname'];
$currentPage = basename($_SERVER['PHP_SELF']);

// Default to last 30 days
$period = $_GET['period'] ?? 'last_30_days';
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');

// Set date range based on period
switch($period) {
    case 'last_7_days':
        $from = date('Y-m-d', strtotime('-7 days'));
        $to = date('Y-m-d');
        break;
    case 'last_30_days':
        $from = date('Y-m-d', strtotime('-30 days'));
        $to = date('Y-m-d');
        break;
    case 'this_month':
        $from = date('Y-m-01');
        $to = date('Y-m-d');
        break;
    case 'this_year':
        $from = date('Y-01-01');
        $to = date('Y-m-d');
        break;
}

// Calculate previous period for comparison
$days_diff = (strtotime($to) - strtotime($from)) / 86400;
$prev_from = date('Y-m-d', strtotime($from . " -$days_diff days"));
$prev_to = $from;

// Fetch current period stats
$current_revenue = 0;
$current_guests = 0;
$result = $conn->query("SELECT SUM(amount) as revenue, COUNT(DISTINCT guest_id) as guests 
                        FROM payments WHERE payment_date BETWEEN '$from' AND '$to'");
if ($result && $row = $result->fetch_assoc()) {
    $current_revenue = $row['revenue'] ?? 0;
    $current_guests = $row['guests'] ?? 0;
}

// Fetch previous period stats
$prev_revenue = 0;
$prev_guests = 0;
$result = $conn->query("SELECT SUM(amount) as revenue, COUNT(DISTINCT guest_id) as guests 
                        FROM payments WHERE payment_date BETWEEN '$prev_from' AND '$prev_to'");
if ($result && $row = $result->fetch_assoc()) {
    $prev_revenue = $row['revenue'] ?? 0;
    $prev_guests = $row['guests'] ?? 0;
}

// Calculate percentages
$revenue_change = $prev_revenue > 0 ? (($current_revenue - $prev_revenue) / $prev_revenue) * 100 : 0;
$guests_change = $prev_guests > 0 ? (($current_guests - $prev_guests) / $prev_guests) * 100 : 0;

// Fetch occupancy rate
$total_rooms = 0;
$occupied_rooms = 0;
$result = $conn->query("SELECT COUNT(*) as total FROM rooms");
if ($result && $row = $result->fetch_assoc()) {
    $total_rooms = $row['total'];
}
$result = $conn->query("SELECT COUNT(*) as occupied FROM rooms WHERE status='Occupied'");
if ($result && $row = $result->fetch_assoc()) {
    $occupied_rooms = $row['occupied'];
}
$occupancy_rate = $total_rooms > 0 ? ($occupied_rooms / $total_rooms) * 100 : 0;

// 1. Recent Transactions (Last 10)
$recent_transactions = [];
$result = $conn->query("SELECT g.fullname, r.room_name, p.amount, p.payment_method, p.payment_date 
                        FROM payments p 
                        JOIN guest g ON p.guest_id = g.guest_id 
                        JOIN rooms r ON p.room_id = r.room_id 
                        WHERE p.payment_date BETWEEN '$from' AND '$to' 
                        ORDER BY p.payment_date DESC LIMIT 10");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_transactions[] = $row;
    }
}

// 2. Guest Frequency Report
$guest_frequency = [];
$result = $conn->query("SELECT g.fullname, g.contact, COUNT(p.payment_id) as visits, 
                        SUM(p.amount) as total_spent, MAX(p.payment_date) as last_visit 
                        FROM payments p 
                        JOIN guest g ON p.guest_id = g.guest_id 
                        GROUP BY g.guest_id 
                        HAVING visits > 1
                        ORDER BY visits DESC LIMIT 10");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $guest_frequency[] = $row;
    }
}

// 4. Daily Revenue Summary
$daily_revenue = [];
$result = $conn->query("SELECT DATE(payment_date) as date, COUNT(payment_id) as transactions, 
                        SUM(amount) as revenue 
                        FROM payments 
                        WHERE payment_date BETWEEN '$from' AND '$to' 
                        GROUP BY DATE(payment_date) 
                        ORDER BY date DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $daily_revenue[] = $row;
    }
}

// 5. Checkout Schedule (Next 7 days)
$checkout_schedule = [];
$next_week = date('Y-m-d', strtotime('+7 days'));
$result = $conn->query("SELECT g.fullname, g.contact, r.room_name, g.check_out 
                        FROM guest g 
                        JOIN rooms r ON g.room_id = r.room_id 
                        WHERE g.check_out BETWEEN CURDATE() AND '$next_week' 
                        ORDER BY g.check_out ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $checkout_schedule[] = $row;
    }
}

// 6. Monthly Comparison (Last 6 months)
$monthly_comparison = [];
$result = $conn->query("SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, 
                        COUNT(DISTINCT guest_id) as guests, 
                        COUNT(payment_id) as bookings, 
                        SUM(amount) as revenue 
                        FROM payments 
                        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
                        GROUP BY DATE_FORMAT(payment_date, '%Y-%m') 
                        ORDER BY month DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $monthly_comparison[] = $row;
    }
}

// 7. Peak Booking Times
$peak_bookings = [];
$result = $conn->query("SELECT DAYNAME(payment_date) as day_name, 
                        COUNT(payment_id) as bookings, 
                        SUM(amount) as revenue 
                        FROM payments 
                        WHERE payment_date BETWEEN '$from' AND '$to' 
                        GROUP BY DAYNAME(payment_date), DAYOFWEEK(payment_date) 
                        ORDER BY DAYOFWEEK(payment_date)");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $peak_bookings[] = $row;
    }
}

// 8. Average Stay Duration
$avg_stay = 0;
$result = $conn->query("SELECT AVG(DATEDIFF(check_out, check_in)) as avg_days 
                        FROM guest 
                        WHERE check_in BETWEEN '$from' AND '$to' 
                        AND check_out IS NOT NULL");
if ($result && $row = $result->fetch_assoc()) {
    $avg_stay = round($row['avg_days'] ?? 0, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Analytics - Hotel Management System</title>
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

/* Filter Section */
.filter-card {
    background: #fff;
    border-radius: 15px;
    padding: 25px 30px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    margin-bottom: 30px;
}

.filter-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.filter-btn {
    padding: 10px 20px;
    border: 2px solid #e9ecef;
    background: #fff;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.9rem;
    color: #2c3e50;
    transition: all 0.3s ease;
}

.filter-btn:hover {
    border-color: #667eea;
    color: #667eea;
}

.filter-btn.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-color: transparent;
}

.custom-range {
    display: flex;
    gap: 15px;
    align-items: end;
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
    padding: 10px 15px;
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

.btn-apply {
    padding: 10px 24px;
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-apply:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
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

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a5f;
    margin-bottom: 10px;
}

.stat-comparison {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    font-weight: 600;
}

.stat-comparison.positive {
    color: #28a745;
}

.stat-comparison.negative {
    color: #dc3545;
}

.stat-card:nth-child(2) {
    border-left-color: #f093fb;
}

.stat-card:nth-child(3) {
    border-left-color: #4facfe;
}

.stat-card:nth-child(4) {
    border-left-color: #ffd700;
}

/* Content Grid */
.content-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}

.data-card {
    background: #fff;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.data-card h3 {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e3a5f;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Table Styling */
.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead tr {
    background: #f8f9fa;
    border-bottom: 2px solid #e9ecef;
}

.data-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #1e3a5f;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #e9ecef;
    color: #2c3e50;
    font-size: 0.9rem;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

.data-table tbody tr:last-child td {
    border-bottom: none;
}

.text-right {
    text-align: right;
}

.text-center {
    text-align: center;
    color: #7f8c8d;
    font-style: italic;
    padding: 40px 20px;
}

.badge {
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
}

.badge-success {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: #fff;
}

.badge-info {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: #fff;
}

.badge-warning {
    background: linear-gradient(135deg, #ffc107, #ff9800);
    color: #000;
}

.badge-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
}

/* Scrollable table container */
.table-scroll {
    max-height: 400px;
    overflow-y: auto;
}

.table-scroll::-webkit-scrollbar {
    width: 8px;
}

.table-scroll::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.table-scroll::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 10px;
}

.table-scroll::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Responsive */
@media (max-width: 1200px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
}

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
    
    .custom-range {
        flex-direction: column;
    }
    
    .content-grid {
        grid-template-columns: 1fr;
    }

    .data-table {
        font-size: 0.8rem;
    }

    .data-table th,
    .data-table td {
        padding: 8px 6px;
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
        <h2>Analytics Dashboard</h2>
    </div>

    <div class="filter-card">
        <div class="filter-buttons">
            <button class="filter-btn <?= $period=='last_7_days'?'active':'' ?>" onclick="location.href='?period=last_7_days'">Last 7 Days</button>
            <button class="filter-btn <?= $period=='last_30_days'?'active':'' ?>" onclick="location.href='?period=last_30_days'">Last 30 Days</button>
            <button class="filter-btn <?= $period=='this_month'?'active':'' ?>" onclick="location.href='?period=this_month'">This Month</button>
            <button class="filter-btn <?= $period=='this_year'?'active':'' ?>" onclick="location.href='?period=this_year'">This Year</button>
        </div>
        
        <form method="GET" class="custom-range">
            <div class="form-group">
                <label class="form-label">From Date</label>
                <input type="date" name="from" class="form-control" value="<?= $from ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">To Date</label>
                <input type="date" name="to" class="form-control" value="<?= $to ?>" required>
            </div>
            <button type="submit" class="btn-apply">
                <i class="fa-solid fa-filter"></i> Apply
            </button>
        </form>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Total Revenue</h3>
            <div class="stat-value">TZS <?= number_format($current_revenue, 2) ?></div>
            <div class="stat-comparison <?= $revenue_change >= 0 ? 'positive' : 'negative' ?>">
                <i class="fa-solid fa-<?= $revenue_change >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                <?= abs(number_format($revenue_change, 1)) ?>% vs previous period
            </div>
        </div>
        
        <div class="stat-card">
            <h3>Total Guests</h3>
            <div class="stat-value"><?= $current_guests ?></div>
            <div class="stat-comparison <?= $guests_change >= 0 ? 'positive' : 'negative' ?>">
                <i class="fa-solid fa-<?= $guests_change >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                <?= abs(number_format($guests_change, 1)) ?>% vs previous period
            </div>
        </div>
        
        <div class="stat-card">
            <h3>Occupancy Rate</h3>
            <div class="stat-value"><?= number_format($occupancy_rate, 1) ?>%</div>
            <div class="stat-comparison">
                <?= $occupied_rooms ?> of <?= $total_rooms ?> rooms occupied
            </div>
        </div>
        
        <div class="stat-card">
            <h3>Avg Stay Duration</h3>
            <div class="stat-value"><?= $avg_stay ?> Days</div>
            <div class="stat-comparison">
                Average guest stay period
            </div>
        </div>
    </div>

    <div class="content-grid">
        <div class="data-card">
            <h3><i class="fa-solid fa-receipt"></i> Recent Transactions</h3>
            <?php if(count($recent_transactions) > 0): ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Guest Name</th>
                            <th>Room</th>
                            <th>Method</th>
                            <th class="text-right">Amount</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_transactions as $trans): ?>
                        <tr>
                            <td><?= htmlspecialchars($trans['fullname']) ?></td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($trans['room_name']) ?></span></td>
                            <td><?= htmlspecialchars($trans['payment_method']) ?></td>
                            <td class="text-right">TZS <?= number_format($trans['amount'], 2) ?></td>
                            <td><?= date('M d, Y', strtotime($trans['payment_date'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center">No transactions found</div>
            <?php endif; ?>
        </div>

        <div class="data-card">
            <h3><i class="fa-solid fa-users"></i> Repeat Guests</h3>
            <?php if(count($guest_frequency) > 0): ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Guest Name</th>
                            <th>Contact</th>
                            <th class="text-right">Visits</th>
                            <th class="text-right">Total Spent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($guest_frequency as $guest): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($guest['fullname']) ?></strong></td>
                            <td><?= htmlspecialchars($guest['contact']) ?></td>
                            <td class="text-right"><span class="badge badge-success"><?= $guest['visits'] ?> times</span></td>
                            <td class="text-right">TZS <?= number_format($guest['total_spent'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center">No repeat guests found</div>
            <?php endif; ?>
        </div>

        <div class="data-card">
            <h3><i class="fa-solid fa-calendar-day"></i> Daily Revenue Summary</h3>
            <?php if(count($daily_revenue) > 0): ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-right">Transactions</th>
                            <th class="text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($daily_revenue as $day): ?>
                        <tr>
                            <td><?= date('D, M d, Y', strtotime($day['date'])) ?></td>
                            <td class="text-right"><?= $day['transactions'] ?></td>
                            <td class="text-right"><strong>TZS <?= number_format($day['revenue'], 2) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center">No revenue data available</div>
            <?php endif; ?>
        </div>

        <div class="data-card">
            <h3><i class="fa-solid fa-door-open"></i> Upcoming Checkouts (Next 7 Days)</h3>
            <?php if(count($checkout_schedule) > 0): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Guest Name</th>
                        <th>Contact</th>
                        <th>Room</th>
                        <th>Checkout Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($checkout_schedule as $checkout): ?>
                    <tr>
                        <td><?= htmlspecialchars($checkout['fullname']) ?></td>
                        <td><?= htmlspecialchars($checkout['contact']) ?></td>
                        <td><span class="badge badge-info"><?= htmlspecialchars($checkout['room_name']) ?></span></td>
                        <td><span class="badge badge-warning"><?= date('M d, Y', strtotime($checkout['check_out'])) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="text-center">No upcoming checkouts</div>
            <?php endif; ?>
        </div>

        <div class="data-card">
            <h3><i class="fa-solid fa-chart-column"></i> Monthly Comparison (Last 6 Months)</h3>
            <?php if(count($monthly_comparison) > 0): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th class="text-right">Guests</th>
                        <th class="text-right">Bookings</th>
                        <th class="text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($monthly_comparison as $month): ?>
                    <tr>
                        <td><strong><?= date('F Y', strtotime($month['month'] . '-01')) ?></strong></td>
                        <td class="text-right"><?= $month['guests'] ?></td>
                        <td class="text-right"><?= $month['bookings'] ?></td>
                        <td class="text-right">TZS <?= number_format($month['revenue'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="text-center">No monthly data available</div>
            <?php endif; ?>
        </div>

        <div class="data-card">
            <h3><i class="fa-solid fa-fire"></i> Peak Booking Days</h3>
            <?php if(count($peak_bookings) > 0): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Day of Week</th>
                        <th class="text-right">Total Bookings</th>
                        <th class="text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($peak_bookings as $day): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($day['day_name']) ?></strong></td>
                        <td class="text-right"><span class="badge badge-primary"><?= $day['bookings'] ?></span></td>
                        <td class="text-right">TZS <?= number_format($day['revenue'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="text-center">No booking data available</div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>