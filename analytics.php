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

// --- 1. DATE FILTER LOGIC ---

// Default
$from = date('Y-m-d');
$to = date('Y-m-d');
$period = 'today'; 

if (isset($_GET['period'])) {
    $period = $_GET['period'];
    switch ($period) {
        case 'today':
            $from = date('Y-m-d');
            $to = date('Y-m-d');
            break;
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
            $to = date('Y-m-t'); 
            break;
        case 'this_year': 
            $from = date('Y-01-01'); 
            $to = date('Y-12-31'); 
            break;
    }
}

// Manual Date Override
if (isset($_GET['from']) && !empty($_GET['from'])) { $from = $_GET['from']; }
if (isset($_GET['to']) && !empty($_GET['to'])) { $to = $_GET['to']; if(isset($_GET['from'])) { $period = 'custom'; } }

// Logic ya Previous Period
$days_diff = (strtotime($to) - strtotime($from)) / 86400;
if ($days_diff < 1) { $days_diff = 1; } 
$prev_from = date('Y-m-d', strtotime($from . " -$days_diff days"));
$prev_to = date('Y-m-d', strtotime($from . " -1 day"));

// --- 2. DATA QUERIES ---

// A. Revenue
$curr_rev = $conn->query("SELECT SUM(amount + extra_charges - discount) FROM payments WHERE DATE(payment_date) BETWEEN '$from' AND '$to'")->fetch_row()[0] ?? 0;
$prev_rev = $conn->query("SELECT SUM(amount + extra_charges - discount) FROM payments WHERE DATE(payment_date) BETWEEN '$prev_from' AND '$prev_to'")->fetch_row()[0] ?? 0;
$growth = ($prev_rev > 0) ? (($curr_rev - $prev_rev) / $prev_rev) * 100 : 0;

// B. Guests
$curr_guests = $conn->query("SELECT COUNT(DISTINCT guest_id) FROM payments WHERE DATE(payment_date) BETWEEN '$from' AND '$to'")->fetch_row()[0] ?? 0;
$prev_guests = $conn->query("SELECT COUNT(DISTINCT guest_id) FROM payments WHERE DATE(payment_date) BETWEEN '$prev_from' AND '$prev_to'")->fetch_row()[0] ?? 0;
$guest_growth = ($prev_guests > 0) ? (($curr_guests - $prev_guests) / $prev_guests) * 100 : 0;

// C. Occupancy
$total_rooms = $conn->query("SELECT COUNT(*) FROM rooms")->fetch_row()[0] ?? 1;
$occupied_rooms = $conn->query("SELECT COUNT(*) FROM rooms WHERE status='Occupied'")->fetch_row()[0] ?? 0;
$occupancy_rate = ($occupied_rooms / $total_rooms) * 100;

// D. Recent Transactions
$recent_transactions = [];
$res_trans = $conn->query("SELECT COALESCE(NULLIF(p.guest_name, ''), CONCAT(g.first_name, ' ', g.last_name)) AS fullname, 
                           COALESCE(NULLIF(p.room_name, ''), r.room_name) AS room_name, 
                           (p.amount + p.extra_charges - p.discount) as net_amount, 
                           p.payment_method, p.payment_date 
                           FROM payments p 
                           LEFT JOIN guest g ON p.guest_id = g.guest_id 
                           LEFT JOIN rooms r ON p.room_id = r.room_id 
                           WHERE DATE(p.payment_date) BETWEEN '$from' AND '$to' 
                           ORDER BY p.payment_date DESC LIMIT 10"); 
if ($res_trans) { while ($row = $res_trans->fetch_assoc()) { $recent_transactions[] = $row; } }

// E. Repeat Guests
$guest_frequency = [];
$res_freq = $conn->query("SELECT COALESCE(NULLIF(guest_name, ''), 'Unknown') AS fullname, COUNT(*) as visits, SUM(amount) as total_spent 
                          FROM payments 
                          GROUP BY guest_name 
                          HAVING visits > 1 
                          ORDER BY visits DESC LIMIT 5");
if ($res_freq) { while ($row = $res_freq->fetch_assoc()) { $guest_frequency[] = $row; } }

// F. Daily Revenue Chart Data
$daily_revenue = [];
$res_daily = $conn->query("SELECT DATE(payment_date) as date, COUNT(*) as transactions, SUM(amount + extra_charges - discount) as revenue 
                           FROM payments 
                           WHERE DATE(payment_date) BETWEEN '$from' AND '$to' 
                           GROUP BY DATE(payment_date) 
                           ORDER BY date DESC"); 
if ($res_daily) { while ($row = $res_daily->fetch_assoc()) { $daily_revenue[] = $row; } }

// G. Peak Days
$peak_bookings = [];
$res_peak = $conn->query("SELECT DAYNAME(payment_date) as day_name, COUNT(*) as bookings, SUM(amount + extra_charges - discount) as revenue 
                          FROM payments 
                          WHERE DATE(payment_date) BETWEEN '$from' AND '$to' 
                          GROUP BY DAYNAME(payment_date) 
                          ORDER BY bookings DESC LIMIT 7");
if ($res_peak) { while ($row = $res_peak->fetch_assoc()) { $peak_bookings[] = $row; } }

// H. MONTHLY BREAKDOWN
$monthly_breakdown = [];
$sql_breakdown = "SELECT 
                    DATE_FORMAT(payment_date, '%M %Y') as month_name,
                    COUNT(*) as total_guests,
                    SUM(CASE WHEN payment_method = 'Cash' THEN amount + extra_charges - discount ELSE 0 END) as cash_total,
                    COUNT(CASE WHEN payment_method = 'Cash' THEN 1 END) as cash_count,
                    SUM(CASE WHEN payment_method = 'Credit Card' THEN amount + extra_charges - discount ELSE 0 END) as card_total,
                    COUNT(CASE WHEN payment_method = 'Credit Card' THEN 1 END) as card_count,
                    SUM(CASE WHEN payment_method LIKE '%Mobile%' OR payment_method = 'Mobile Payment' THEN amount + extra_charges - discount ELSE 0 END) as mobile_total,
                    COUNT(CASE WHEN payment_method LIKE '%Mobile%' OR payment_method = 'Mobile Payment' THEN 1 END) as mobile_count,
                    SUM(amount + extra_charges - discount) as grand_total
                  FROM payments 
                  GROUP BY DATE_FORMAT(payment_date, '%Y-%m') 
                  ORDER BY DATE_FORMAT(payment_date, '%Y-%m') DESC LIMIT 12";

$res_breakdown = $conn->query($sql_breakdown);
if ($res_breakdown) { while ($row = $res_breakdown->fetch_assoc()) { $monthly_breakdown[] = $row; } }

// --- 3. DATA PREPARATION FOR CHARTS ---
$chart_revenue_data = array_reverse($daily_revenue);
$chart_labels = [];
$chart_values = [];
foreach ($chart_revenue_data as $d) {
    $chart_labels[] = date('d M', strtotime($d['date']));
    $chart_values[] = $d['revenue'];
}

// Payment Methods
$pay_methods = $conn->query("SELECT payment_method, SUM(amount + extra_charges - discount) as total FROM payments WHERE DATE(payment_date) BETWEEN '$from' AND '$to' GROUP BY payment_method");
$pie_labels = [];
$pie_values = [];
$print_cash = 0; $print_mobile = 0; $print_card = 0;

while($pm = $pay_methods->fetch_assoc()){
    $pie_labels[] = $pm['payment_method'];
    $pie_values[] = $pm['total'];
    if($pm['payment_method'] == 'Cash') { $print_cash += $pm['total']; }
    elseif($pm['payment_method'] == 'Credit Card') { $print_card += $pm['total']; }
    else { $print_mobile += $pm['total']; }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Analytics - HGMA System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; overflow-x: hidden; }

    /* ANIMATIONS */
    @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    /* SIDEBAR (Responsive) */
    .sidebar { 
        position: fixed; left: 0; top: 0; width: 260px; height: 100vh; 
        background: #1e3a5f; color: #fff; padding: 30px 0; 
        display: flex; flex-direction: column; z-index: 1000; 
        box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1); 
        transition: transform 0.3s ease-in-out; 
    }
    .sidebar-header { padding: 0 25px 30px 25px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); margin-bottom: 20px; }
    .sidebar-header h2 { font-weight: 600; font-size: 1.4rem; color: #fff; margin-bottom: 5px; }
    .sidebar-header p { font-size: 0.85rem; color: rgba(255, 255, 255, 0.7); }
    .sidebar-nav { flex: 1; padding: 0 15px; }
    .sidebar a { text-decoration: none; color: rgba(255, 255, 255, 0.85); display: flex; align-items: center; padding: 14px 18px; margin: 5px 0; border-radius: 10px; transition: all 0.3s ease; font-weight: 500; font-size: 0.95rem; }
    .sidebar a i { margin-right: 14px; font-size: 1.1rem; width: 20px; text-align: center; }
    .sidebar a:hover, .sidebar a.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
    .logout-section { padding: 0 15px 20px 15px; border-top: 1px solid rgba(255, 255, 255, 0.1); margin-top: 20px; padding-top: 20px; }
    .logout-btn { width: 100%; padding: 12px 18px; background: #dc3545; color: #fff; border: none; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; font-weight: 600; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; }

    /* MOBILE OVERLAY */
    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 900; }

    /* MAIN CONTENT */
    .main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; animation: fadeIn 0.8s ease-in-out; transition: margin-left 0.3s; }
    
    /* HEADER */
    .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: #fff; padding: 20px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); }
    .header h2 { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; }
    .menu-toggle { display: none; font-size: 1.5rem; color: #1e3a5f; cursor: pointer; margin-right: 15px; }

    .btn-actions { display: flex; gap: 10px; }
    .btn-print { padding: 10px 20px; background: #1e3a5f; color: #fff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; font-size: 0.95rem; }
    .btn-print:hover { background: #162447; transform: translateY(-2px); }
    .btn-excel { padding: 10px 20px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: #fff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
    .btn-pdf { padding: 10px 20px; background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: #fff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; }


    /* FILTERS */
    .filter-card { background: #fff; border-radius: 15px; padding: 25px 30px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); margin-bottom: 30px; animation: slideUp 0.6s ease-out; }
    .filter-buttons { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
    .filter-btn { padding: 10px 20px; border: 2px solid #e9ecef; background: #fff; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 0.9rem; color: #2c3e50; text-decoration: none; display: inline-block;}
    .filter-btn.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; border-color: transparent; }
    
    .custom-range { display: flex; gap: 15px; align-items: end; flex-wrap: wrap; }
    .form-group { flex: 1; min-width: 150px; }
    .form-label { font-weight: 600; margin-bottom: 8px; color: #1e3a5f; font-size: 0.9rem; display: block; }
    .form-control { width: 100%; padding: 10px 15px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 0.95rem; background: #f8f9fa; }
    .btn-apply { padding: 10px 24px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: #fff; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; }
    
    /* RESET BUTTON STYLE */
    .btn-reset { padding: 10px 24px; background: #dc3545; color: #fff; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 5px; }
    .btn-reset:hover { background: #c82333; }

    /* STATS & CHARTS */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); border-left: 4px solid #667eea; animation: slideUp 0.6s ease-out forwards; }
    .stat-card:nth-child(2) { border-left-color: #f093fb; animation-delay: 0.1s; }
    .stat-card:nth-child(3) { border-left-color: #4facfe; animation-delay: 0.2s; }
    .stat-value { font-size: 1.8rem; font-weight: 800; color: #1e3a5f; margin: 10px 0; }
    .stat-comparison { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; }
    .stat-comparison.positive { color: #28a745; background: rgba(40, 167, 69, 0.1); padding: 4px 8px; border-radius: 20px; }
    .stat-comparison.negative { color: #dc3545; background: rgba(220, 53, 69, 0.1); padding: 4px 8px; border-radius: 20px; }

    .charts-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; margin-bottom: 30px; opacity: 0; animation: slideUp 0.8s ease-out forwards; animation-delay: 0.4s; }
    .chart-container { background: #fff; padding: 25px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); position: relative; height: 380px; }
    .chart-container h3 { font-size: 1.1rem; font-weight: 700; color: #1e3a5f; margin-bottom: 15px; }

    /* TABLES CONTENT */
    .content-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 25px; margin-bottom: 30px; opacity: 0; animation: slideUp 0.8s ease-out forwards; animation-delay: 0.5s; }
    .full-width-card { grid-column: 1 / -1; }
    
    .data-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); }
    .data-card h3 { font-size: 1.1rem; font-weight: 700; color: #1e3a5f; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #f0f2f5; padding-bottom: 15px; }

    /* Tables (Responsive Scroll) */
    .table-scroll { max-height: 400px; overflow-y: auto; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .data-table { width: 100%; border-collapse: collapse; min-width: 600px; }
    .data-table thead tr { background: #f8f9fa; border-bottom: 2px solid #e9ecef; }
    .data-table th, .data-table td { padding: 15px; text-align: left; border-bottom: 1px solid #e9ecef; white-space: nowrap; }
    .data-table th { font-weight: 600; color: #1e3a5f; font-size: 0.85rem; text-transform: uppercase; }
    .text-right { text-align: right !important; }
    .text-center { text-align: center; }
    
    .badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; }
    .badge-success { background: rgba(40, 167, 69, 0.15); color: #28a745; }
    .badge-info { background: rgba(79, 172, 254, 0.15); color: #007bff; }
    .badge-primary { background: rgba(102, 126, 234, 0.15); color: #667eea; }

    /* === RESPONSIVE MEDIA QUERIES === */
    @media (max-width: 992px) {
        .charts-grid { grid-template-columns: 1fr; }
        .chart-container { height: 320px; }
    }

    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); width: 250px; }
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        .main-content { margin-left: 0; padding: 20px 15px; }
        .menu-toggle { display: block; }
        .header { flex-wrap: wrap; gap: 10px; padding: 15px; }
        .header h2 { font-size: 1.3rem; }
        .filter-buttons { overflow-x: auto; padding-bottom: 5px; flex-wrap: nowrap; }
        .filter-btn { white-space: nowrap; }
        .custom-range { flex-direction: column; }
        .form-group, .btn-apply, .btn-reset { width: 100%; }
        .stats-grid { grid-template-columns: 1fr; }
        .content-grid { grid-template-columns: 1fr; }
    }

    /* --- PRINT LAYOUT STYLES --- */
    #print-layout { display: none; }

    @media print {
        @page { size: A4; margin: 1cm; }
        body { background: #fff; color: #000; font-family: 'Times New Roman', serif; }
        
        .sidebar, .main-content, .sidebar-overlay { display: none !important; }
        #print-layout { display: block !important; width: 100%; }
        
        .print-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 30px; }
        .print-header h1 { font-size: 24px; text-transform: uppercase; margin: 0; }
        .meta-info { display: flex; justify-content: space-between; margin-bottom: 25px; border-bottom: 1px dotted #999; padding-bottom: 10px; font-size: 14px; }
        
        .section-box { margin-bottom: 30px; }
        .section-title { font-size: 16px; font-weight: bold; text-transform: uppercase; border-bottom: 1px solid #000; margin-bottom: 10px; }
        
        .print-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .print-table td { padding: 8px 0; border-bottom: 1px dotted #ccc; }
        .print-table .total-row td { border-top: 2px solid #000; font-weight: bold; font-size: 16px; padding-top: 10px; }
        
        .metrics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 10px; }
        .metric-item { border: 1px solid #ccc; padding: 15px; border-radius: 5px; }
        .metric-item strong { display: block; font-size: 18px; margin-bottom: 5px; }
        .metric-item span { font-size: 12px; color: #555; text-transform: uppercase; }
        
        .signature-area { display: flex; justify-content: space-between; margin-top: 80px; }
        .sign-line { width: 40%; border-top: 1px solid #000; text-align: center; padding-top: 10px; font-size: 14px; }
    }

    /* PDF Specific */
    .pdf-mode {
        width: 100% !important;
        margin: 0 !important;
        padding: 20px !important;
    }
    .pdf-mode .print-table { width: 100% !important; }
</style>
</head>
<body>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2>HGMA System</h2><p>Hotel Management</p>
    </div>
    <div class="sidebar-nav">
        <a href="manager_dashboard.php" class="<?= $currentPage=='manager_dashboard.php'?'active':'' ?>"><i class="fa-solid fa-house"></i> <span>Dashboard</span></a>
        <a href="manage_staff.php" class="<?= $currentPage=='manage_staff.php'?'active':'' ?>"><i class="fa-solid fa-user-shield"></i> <span>Manage Staff</span></a>
        <a href="room_details.php" class="<?= $currentPage=='room_details.php'?'active':'' ?>"><i class="fa-solid fa-bed"></i> <span>Room Details</span></a>
        <a href="payment_reports.php" class="<?= $currentPage=='payment_reports.php'?'active':'' ?>"><i class="fa-solid fa-credit-card"></i> <span>Payment Reports</span></a>
        <a href="analytics.php" class="<?= $currentPage=='analytics.php'?'active':'' ?>"><i class="fa-solid fa-chart-line"></i> <span>Analytics</span></a>
        <a href="activity_log.php" class="<?= $currentPage=='activity_log.php'?'active':'' ?>"><i class="fa-solid fa-clipboard-list"></i> <span>Activity Log</span></a>
        <a href="db_maintenance.php" class="<?= $currentPage=='db_maintenance.php'?'active':'' ?>"><i class="fa-solid fa-screwdriver-wrench"></i> <span>Maintenance</span></a>
    </div>
    <div class="logout-section">
        <form action="logout.php" method="POST"><button type="submit" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span></button></form>
    </div>
</div>

<div class="main-content">
    <div class="header">
        <div style="display:flex; align-items:center;">
            <i class="fa-solid fa-bars menu-toggle" onclick="toggleSidebar()"></i>
            <h2>Analytics Dashboard</h2>
        </div>
        <div class="btn-actions">
            <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            <button class="btn-excel" onclick="exportToExcel()"><i class="fa-solid fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" onclick="exportToPDF()"><i class="fa-solid fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <div class="filter-card">
        <div class="filter-buttons">
            <a href="?period=today" class="filter-btn <?= $period=='today'?'active':'' ?>">Today</a>
            <a href="?period=last_7_days" class="filter-btn <?= $period=='last_7_days'?'active':'' ?>">Last 7 Days</a>
            <a href="?period=last_30_days" class="filter-btn <?= $period=='last_30_days'?'active':'' ?>">Last 30 Days</a>
            <a href="?period=this_month" class="filter-btn <?= $period=='this_month'?'active':'' ?>">This Month</a>
            <a href="?period=this_year" class="filter-btn <?= $period=='this_year'?'active':'' ?>">This Year</a>
        </div>
        
        <form method="GET" class="custom-range">
            <div class="form-group">
                <label class="form-label">From</label>
                <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">To</label>
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>" required>
            </div>
            
            <button type="submit" class="btn-apply"><i class="fa-solid fa-filter"></i> Apply</button>
            
            <a href="analytics.php?period=today" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
        </form>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Total Revenue</h3>
            <div class="stat-value">TZS <span class="counter" data-target="<?= $curr_rev ?>">0</span></div>
            <div class="stat-comparison <?= $growth >= 0 ? 'positive' : 'negative' ?>">
                <i class="fa-solid fa-<?= $growth >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i> <?= number_format(abs($growth), 1) ?>%
            </div>
        </div>
        <div class="stat-card">
            <h3>Total Guests</h3>
            <div class="stat-value"><span class="counter" data-target="<?= $curr_guests ?>">0</span></div>
            <div class="stat-comparison <?= $guest_growth >= 0 ? 'positive' : 'negative' ?>">
                <i class="fa-solid fa-<?= $guest_growth >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i> <?= number_format(abs($guest_growth), 1) ?>%
            </div>
        </div>
        <div class="stat-card">
            <h3>Occupancy Rate</h3>
            <div class="stat-value"><span class="counter" data-target="<?= $occupancy_rate ?>">0</span>%</div>
            <div class="stat-comparison"><?= $occupied_rooms ?> of <?= $total_rooms ?> rooms</div>
        </div>
    </div>

    <div class="charts-grid">
        <div class="chart-container">
            <h3><i class="fa-solid fa-chart-area"></i> Revenue Trend (Daily)</h3>
            <canvas id="revenueChart"></canvas>
        </div>
        <div class="chart-container">
            <h3><i class="fa-solid fa-chart-pie"></i> Payment Distribution</h3>
            <canvas id="paymentPieChart"></canvas>
        </div>
    </div>

    <div class="content-grid">
        <div class="data-card full-width-card">
            <h3><i class="fa-solid fa-wallet"></i> Monthly Revenue Breakdown</h3>
            <?php if(count($monthly_breakdown) > 0): ?>
            <div class="table-scroll">
                <table class="data-table" id="tableMonthly">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th class="text-center">Total Guests</th>
                            <th class="text-right">Cash</th>
                            <th class="text-right">Credit Card</th>
                            <th class="text-right">Mobile</th>
                            <th class="text-right" style="background: #eef2f7;">Grand Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($monthly_breakdown as $month): ?>
                        <tr>
                            <td><strong><?= $month['month_name'] ?></strong></td>
                            <td class="text-center"><span class="badge badge-primary"><?= $month['total_guests'] ?></span></td>
                            <td class="text-right">
                                TZS <?= number_format($month['cash_total']) ?>
                                <br><small style="color: #7f8c8d; font-size: 0.8em;">(<?= $month['cash_count'] ?> paid)</small>
                            </td>
                            <td class="text-right">
                                TZS <?= number_format($month['card_total']) ?>
                                <br><small style="color: #7f8c8d; font-size: 0.8em;">(<?= $month['card_count'] ?> paid)</small>
                            </td>
                            <td class="text-right">
                                TZS <?= number_format($month['mobile_total']) ?>
                                <br><small style="color: #7f8c8d; font-size: 0.8em;">(<?= $month['mobile_count'] ?> paid)</small>
                            </td>
                            <td class="text-right" style="background: #f8f9fa; font-weight: bold;">TZS <?= number_format($month['grand_total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?><div class="text-center">No data available.</div><?php endif; ?>
        </div>

        <div class="data-card">
            <h3><i class="fa-solid fa-receipt"></i> Recent Transactions</h3>
            <?php if(count($recent_transactions) > 0): ?>
            <div class="table-scroll">
                <table class="data-table" id="tableRecent">
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
                            <td class="text-right">TZS <?= number_format($trans['net_amount'], 2) ?></td>
                            <td><?= date('M d', strtotime($trans['payment_date'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?><div class="text-center">No transactions found.</div><?php endif; ?>
        </div>

        <div class="data-card">
            <h3><i class="fa-solid fa-calendar-day"></i> Daily Revenue Summary</h3>
            <?php if(count($daily_revenue) > 0): ?>
            <div class="table-scroll">
                <table class="data-table" id="tableDaily">
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
            <?php else: ?><div class="text-center">No data available.</div><?php endif; ?>
        </div>

        <div class="data-card">
            <h3><i class="fa-solid fa-users"></i> Frequent Guests</h3>
            <?php if(count($guest_frequency) > 0): ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Guest Name</th>
                            <th class="text-right">Visits</th>
                            <th class="text-right">Total Spent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($guest_frequency as $guest): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($guest['fullname']) ?></strong></td>
                            <td class="text-right"><span class="badge badge-success"><?= $guest['visits'] ?> times</span></td>
                            <td class="text-right">TZS <?= number_format($guest['total_spent'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?><div class="text-center">No repeat guests found.</div><?php endif; ?>
        </div>

        <div class="data-card">
            <h3><i class="fa-solid fa-fire"></i> Peak Booking Days</h3>
            <?php if(count($peak_bookings) > 0): ?>
            <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Day of Week</th>
                        <th class="text-right">Bookings</th>
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
            </div>
            <?php else: ?><div class="text-center">No booking data available.</div><?php endif; ?>
        </div>
    </div>
</div>

<div id="print-layout">
    <div class="print-header">
        <h1>Kigongoni Gazella Hotel</h1>
        <p>Executive Performance Report</p>
    </div>

    <div class="meta-info">
        <div><strong>Period:</strong> <?= date('d M Y', strtotime($from)) ?> to <?= date('d M Y', strtotime($to)) ?></div>
        <div><strong>Printed By:</strong> <?= htmlspecialchars($fullname) ?></div>
        <div><strong>Date:</strong> <?= date('d M Y H:i A') ?></div>
    </div>

    <div class="section-box">
        <div class="section-title">0. Monthly Breakdown Overview</div>
        <table class="print-table">
            <thead>
                <tr style="background:#eee;">
                    <th>Month</th>
                    <th>Guests</th>
                    <th class="text-right">Cash</th>
                    <th class="text-right">Credit Card</th>
                    <th class="text-right">Mobile</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($monthly_breakdown as $month): ?>
                <tr>
                    <td><?= $month['month_name'] ?></td>
                    <td><?= $month['total_guests'] ?></td>
                    <td class="text-right"><?= number_format($month['cash_total']) ?></td>
                    <td class="text-right"><?= number_format($month['card_total']) ?></td>
                    <td class="text-right"><?= number_format($month['mobile_total']) ?></td>
                    <td class="text-right"><strong><?= number_format($month['grand_total']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="section-box">
        <div class="section-title">1. Financial Summary</div>
        <table class="print-table">
            <tr>
                <td>Cash Collections</td>
                <td class="text-right">TZS <?= number_format($print_cash, 2) ?></td>
            </tr>
            <tr>
                <td>Mobile Money Transfers</td>
                <td class="text-right">TZS <?= number_format($print_mobile, 2) ?></td>
            </tr>
            <tr>
                <td>Bank / Credit Card</td>
                <td class="text-right">TZS <?= number_format($print_card, 2) ?></td>
            </tr>
            <tr class="total-row">
                <td>TOTAL REVENUE</td>
                <td class="text-right">TZS <?= number_format($curr_rev, 2) ?></td>
            </tr>
        </table>
    </div>

    <div class="section-box">
        <div class="section-title">2. Operational Key Metrics</div>
        <div class="metrics-grid">
            <div class="metric-item">
                <strong><?= $curr_guests ?></strong>
                <span>Unique Guests Served</span>
            </div>
            <div class="metric-item">
                <strong><?= number_format($occupancy_rate, 1) ?>%</strong>
                <span>Current Occupancy</span>
            </div>
            <div class="metric-item">
                <strong><?= number_format(count($daily_revenue)) ?></strong>
                <span>Active Business Days</span>
            </div>
            <div class="metric-item">
                <strong>TZS <?= ($curr_guests > 0) ? number_format($curr_rev / $curr_guests, 0) : 0 ?></strong>
                <span>Avg. Revenue Per Guest</span>
            </div>
        </div>
    </div>

    <div class="section-box">
        <div class="section-title">3. Transactions Snapshot (Balance Check)</div>
        <table class="print-table">
            <thead>
                <tr style="background:#eee;">
                    <th>Date</th>
                    <th>Guest / Details</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $shown_total = 0;
                $count = 0;
                foreach($recent_transactions as $t): 
                    if($count >= 5) break; 
                    $shown_total += $t['net_amount'];
                    $count++;
                ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($t['payment_date'])) ?></td>
                    <td><?= htmlspecialchars($t['fullname']) ?></td>
                    <td class="text-right"><?= number_format($t['net_amount']) ?></td>
                </tr>
                <?php endforeach; ?>
                
                <?php 
                $balance_difference = $curr_rev - $shown_total;
                if($balance_difference > 0): 
                ?>
                <tr>
                    <td>-</td>
                    <td><em>Other Smaller Transactions</em></td>
                    <td class="text-right"><strong><?= number_format($balance_difference) ?></strong></td>
                </tr>
                <?php endif; ?>
                
                <tr class="total-row">
                    <td colspan="2">TOTAL MATCHING REVENUE</td>
                    <td class="text-right">TZS <?= number_format($curr_rev) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="signature-area">
        <div class="sign-line">Manager Signature</div>
        <div class="sign-line">Director/Boss Signature</div>
    </div>
</div>

<script>
    // MOBILE MENU TOGGLE
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('active');
        document.querySelector('.sidebar-overlay').classList.toggle('active');
    }

    // NUMBER COUNTER ANIMATION
    const counters = document.querySelectorAll('.counter');
    counters.forEach(counter => {
        const updateCount = () => {
            const target = +counter.getAttribute('data-target');
            const count = +counter.innerText.replace(/,/g, ''); 
            const speed = 200; 
            const inc = target / speed;
            if (count < target) {
                counter.innerText = Math.ceil(count + inc).toLocaleString();
                setTimeout(updateCount, 15);
            } else {
                counter.innerText = target.toLocaleString();
            }
        };
        updateCount();
    });

    // EXPORT FUNCTIONS
    function exportToExcel() {
        // Create a temporary container
        let div = document.createElement('div');
        
        // Clone Monthly Table
        let h3_1 = document.createElement('h3'); h3_1.innerText = "Monthly Breakdown";
        div.appendChild(h3_1);
        let tbl1 = document.getElementById('tableMonthly');
        if(tbl1) div.appendChild(tbl1.cloneNode(true));

        // Clone Daily Table
        let h3_2 = document.createElement('h3'); h3_2.innerText = "Daily Revenue";
        div.appendChild(h3_2);
        let tbl2 = document.getElementById('tableDaily');
        if(tbl2) div.appendChild(tbl2.cloneNode(true));
        
        // Clone Recent Transactions
        let h3_3 = document.createElement('h3'); h3_3.innerText = "Recent Transactions";
        div.appendChild(h3_3);
        let tbl3 = document.getElementById('tableRecent');
        if(tbl3) div.appendChild(tbl3.cloneNode(true));

        var html = div.innerHTML;
        var url = 'data:application/vnd.ms-excel,' + escape(html); 
        var link = document.createElement("a");
        link.href = url;
        link.download = "Analytics_Report_" + new Date().toISOString().slice(0,10) + ".xls";
        link.click();
    }

    function exportToPDF() {
        const element = document.getElementById('print-layout');
        // Temporarily display specifically for PDF generation
        element.style.display = 'block';
        element.classList.add('pdf-mode');

        var opt = {
            margin:       0.3,
            filename:     'Analytics_Report.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2 },
            jsPDF:        { unit: 'in', format: 'a4', orientation: 'portrait' }
        };

        html2pdf().set(opt).from(element).save().then(function(){
            // Re-hide after export
            element.style.display = 'none';
            element.classList.remove('pdf-mode');
        });
    }

    // CHART CONFIGURATION
    const ctxRevenue = document.getElementById('revenueChart').getContext('2d');
    const revenueData = <?= json_encode($chart_values) ?>;
    const revenueLabels = <?= json_encode($chart_labels) ?>;

    let gradient = ctxRevenue.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, 'rgba(102, 126, 234, 0.5)'); 
    gradient.addColorStop(1, 'rgba(102, 126, 234, 0.0)'); 

    new Chart(ctxRevenue, {
        type: 'line',
        data: {
            labels: revenueLabels,
            datasets: [{
                label: 'Revenue (TZS)',
                data: revenueData,
                backgroundColor: gradient,
                borderColor: '#667eea',
                borderWidth: 3,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#667eea',
                pointBorderWidth: 2,
                pointRadius: 4,
                fill: true,
                tension: 0.4 
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f0f2f5', borderDash: [5, 5] } },
                x: { grid: { display: false } }
            }
        }
    });

    const ctxPie = document.getElementById('paymentPieChart').getContext('2d');
    const pieLabels = <?= json_encode($pie_labels) ?>;
    const pieValues = <?= json_encode($pie_values) ?>;

    new Chart(ctxPie, {
        type: 'doughnut',
        data: {
            labels: pieLabels,
            datasets: [{
                data: pieValues,
                backgroundColor: ['#20c997', '#4facfe', '#ffc107', '#dc3545'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%', 
            plugins: { legend: { position: 'bottom' } }
        }
    });
</script>

</body>
</html>