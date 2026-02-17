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

// Filter by date range and status
$where_clauses = [];
$from = $to = $status_filter = "";

if (isset($_GET['from']) && isset($_GET['to']) && $_GET['from'] != "" && $_GET['to'] != "") {
    $from = $_GET['from'];
    $to = $_GET['to'];
    $where_clauses[] = "p.payment_date BETWEEN '$from' AND '$to'";
}

if (isset($_GET['status']) && $_GET['status'] != "") {
    $status_filter = $_GET['status'];
    $where_clauses[] = "p.status = '$status_filter'";
}

$where = "";
if (count($where_clauses) > 0) {
    $where = "WHERE " . implode(" AND ", $where_clauses);
}

// SQL Query
$sql = "SELECT p.payment_id, 
                p.guest_id,
                COALESCE(NULLIF(p.guest_name, ''), CONCAT(g.first_name, ' ', g.last_name)) AS guest_name,
                g.phone AS contact, 
                COALESCE(NULLIF(p.room_name, ''), r.room_name, g.room_name) AS room_name,
                COALESCE(NULLIF(p.room_type, ''), r.room_type, g.room_type) AS room_type,
                p.amount, 
                p.extra_charges,
                p.discount,
                (p.amount + p.extra_charges - p.discount) AS net_amount,
                p.payment_method, 
                p.payment_date,
                p.status,
                g.room_rate,
                DATEDIFF(g.checkout_date, g.checkin_date) as days_stayed,
                (SELECT SUM(amount + extra_charges - discount) FROM payments WHERE guest_id = p.guest_id) as total_paid_history
        FROM payments p
        LEFT JOIN guest g ON p.guest_id = g.guest_id
        LEFT JOIN rooms r ON p.room_id = r.room_id
        $where
        ORDER BY p.payment_date DESC";

$result = $conn->query($sql);

if (!$result) {
    die("Query Error: " . $conn->error);
}

// Summary Query
$summary_sql = "SELECT 
                COUNT(*) as total_transactions,
                SUM(amount + extra_charges - discount) as total_amount,
                SUM(CASE WHEN status = 'Paid' THEN amount + extra_charges - discount ELSE 0 END) as paid_amount,
                SUM(CASE WHEN status = 'Partial' THEN amount + extra_charges - discount ELSE 0 END) as partial_amount,
                SUM(CASE WHEN status = 'Pending' THEN amount + extra_charges - discount ELSE 0 END) as pending_amount,
                COUNT(CASE WHEN status = 'Paid' THEN 1 END) as paid_count,
                COUNT(CASE WHEN status = 'Partial' THEN 1 END) as partial_count,
                COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending_count
                FROM payments p
                $where";
$summary_result = $conn->query($summary_sql);
$summary = $summary_result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payment Reports - Hotel Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; overflow-x: hidden; }

    /* --- SIDEBAR STYLES (Responsive) --- */
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

    /* --- MAIN CONTENT & HEADER --- */
    .main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; transition: margin-left 0.3s ease-in-out; }
    
    .header { margin-bottom: 30px; background: #fff; padding: 20px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
    .header h2 { font-size: 1.5rem; font-weight: 700; color: #1e3a5f; margin: 0; }
    
    .menu-toggle { display: none; font-size: 1.5rem; color: #1e3a5f; cursor: pointer; margin-right: 15px; }

    .btn-actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .btn-print, .btn-excel, .btn-pdf { padding: 10px 15px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px; font-size: 0.9rem; color: #fff; }
    .btn-print { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    .btn-excel { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
    .btn-pdf { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); }

    /* --- SUMMARY CARDS --- */
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .summary-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; align-items: center; gap: 20px; }
    .summary-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
    .summary-content h4 { font-size: 0.85rem; color: #7f8c8d; font-weight: 500; margin-bottom: 5px; }
    .summary-content p { font-size: 1.4rem; font-weight: 700; color: #1e3a5f; word-break: break-all; }

    .icon-total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
    .icon-paid { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: #fff; }
    .icon-partial { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #fff; }
    .icon-pending { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: #fff; }

    /* --- FILTER FORM --- */
    .filter-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); margin-bottom: 25px; }
    .filter-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
    .form-group { display: flex; flex-direction: column; }
    .form-label { font-weight: 600; margin-bottom: 8px; color: #1e3a5f; font-size: 0.9rem; }
    .form-control { padding: 10px 15px; border: 2px solid #e9ecef; border-radius: 10px; background: #f8f9fa; font-size: 0.95rem; }
    
    .btn-group { display: flex; gap: 10px; }
    .btn-filter, .btn-reset { padding: 10px 15px; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; text-align: center; flex: 1; }
    .btn-filter { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: #fff; }
    .btn-reset { background: #6c757d; color: #fff; text-decoration: none; display: flex; align-items: center; justify-content: center; }

    /* --- REPORT CARD & TABLE --- */
    .report-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); }
    .report-title { font-size: 1.3rem; font-weight: 700; color: #1e3a5f; text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #667eea; }

    .table-responsive { max-height: 600px; overflow-y: auto; overflow-x: auto; border: 1px solid #e9ecef; border-radius: 10px; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 1100px; }
    table th { position: sticky; top: 0; z-index: 10; background: #1e3a5f; color: #fff; text-align: center; padding: 12px; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; white-space: nowrap; }
    table td { padding: 12px; text-align: center; border-bottom: 1px solid #e9ecef; vertical-align: middle; color: #2c3e50; font-size: 0.9rem; white-space: nowrap; }
    table tbody tr:hover { background: #f8f9fa; }

    .badge { padding: 5px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
    .badge-paid { background: linear-gradient(135deg, #28a745, #20c997); color: #fff; }
    .badge-partial { background: linear-gradient(135deg, #ffc107, #ff9800); color: #000; }
    .badge-pending { background: linear-gradient(135deg, #dc3545, #c82333); color: #fff; }
    .badge-cash { background: #28a745; color: #fff; }
    .badge-credit-card { background: #007bff; color: #fff; }
    .badge-mobile-payment { background: #17a2b8; color: #fff; }

    .text-right { text-align: right !important; }
    .text-danger { color: #dc3545; font-weight: bold; }
    .text-success { color: #28a745; font-weight: bold; }

    /* --- MOBILE OVERLAY --- */
    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 900; backdrop-filter: blur(2px); }

    /* --- RESPONSIVE MEDIA QUERIES --- */
    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); width: 260px; } 
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        
        .main-content { margin-left: 0; padding: 20px 15px; }
        
        .header { flex-direction: column; align-items: flex-start; gap: 15px; }
        .menu-toggle { display: block; }
        .btn-actions { width: 100%; justify-content: space-between; }
        .btn-actions button { flex: 1; justify-content: center; }
        
        .summary-grid { grid-template-columns: 1fr; }
        .filter-form { grid-template-columns: 1fr; }
        
        .report-card { padding: 15px; }
    }

    /* --- PDF / PRINT MODES --- */
    .pdf-mode { width: 100% !important; min-width: 0 !important; padding: 10px !important; box-shadow: none !important; }
    .pdf-mode .table-responsive { max-height: none !important; overflow: visible !important; border: none !important; }
    .pdf-mode table { width: 100% !important; min-width: 0 !important; font-size: 10px !important; }
    .pdf-mode th, .pdf-mode td { padding: 5px 2px !important; white-space: normal !important; word-wrap: break-word !important; }

    @media print {
        body * { visibility: hidden; }
        .print-area, .print-area * { visibility: visible; }
        .print-area { position: absolute; left: 0; top: 0; width: 100%; }
        .no-print { display: none !important; }
        .btn-actions { display: none !important; }
    }
</style>
</head>
<body>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar no-print" id="sidebar">
    <div class="sidebar-header">
        <h2>HGMA System</h2>
        <p>Hotel Management</p>
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
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span></button>
        </form>
    </div>
</div>

<div class="main-content">
    <div class="header no-print">
        <div style="display:flex; align-items:center;">
            <i class="fa-solid fa-bars menu-toggle" onclick="toggleSidebar()"></i>
            <h2><i class="fa-solid fa-chart-bar"></i> Payment Reports</h2>
        </div>
        <div class="btn-actions">
            <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            <button class="btn-excel" onclick="exportToExcel()"><i class="fa-solid fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" onclick="exportToPDF()"><i class="fa-solid fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <div class="summary-grid no-print">
        <div class="summary-card">
            <div class="summary-icon icon-total"><i class="fa-solid fa-money-bills"></i></div>
            <div class="summary-content">
                <h4>Total Revenue</h4>
                <p>TZS <?= number_format($summary['total_amount'] ?? 0, 2) ?></p>
                <small><?= $summary['total_transactions'] ?? 0 ?> transactions</small>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon icon-paid"><i class="fa-solid fa-circle-check"></i></div>
            <div class="summary-content">
                <h4>Fully Paid</h4>
                <p>TZS <?= number_format($summary['paid_amount'] ?? 0, 2) ?></p>
                <small><?= $summary['paid_count'] ?? 0 ?> payments</small>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon icon-partial"><i class="fa-solid fa-circle-half-stroke"></i></div>
            <div class="summary-content">
                <h4>Partial Payments</h4>
                <p>TZS <?= number_format($summary['partial_amount'] ?? 0, 2) ?></p>
                <small><?= $summary['partial_count'] ?? 0 ?> payments</small>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon icon-pending"><i class="fa-solid fa-clock"></i></div>
            <div class="summary-content">
                <h4>Pending</h4>
                <p>TZS <?= number_format($summary['pending_amount'] ?? 0, 2) ?></p>
                <small><?= $summary['pending_count'] ?? 0 ?> payments</small>
            </div>
        </div>
    </div>

    <div class="filter-card no-print">
        <form class="filter-form" method="GET">
            <div class="form-group">
                <label class="form-label">From Date</label>
                <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">To Date</label>
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Payment Status</label>
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="Paid" <?= $status_filter == 'Paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="Partial" <?= $status_filter == 'Partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="Pending" <?= $status_filter == 'Pending' ? 'selected' : '' ?>>Pending</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <div class="btn-group">
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Apply</button>
                    <a href="payment_reports.php" class="btn-reset" title="Clear Filters"><i class="fa-solid fa-rotate-right"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="report-card print-area" id="reportContent">
        <h4 class="report-title">Hotel Guest Payment Report</h4>
        <?php if ($from && $to): ?>
        <p style="text-align: center; margin-bottom: 20px; color: #7f8c8d;">Period: <?= date('d M Y', strtotime($from)) ?> to <?= date('d M Y', strtotime($to)) ?></p>
        <?php endif; ?>
        <div class="table-responsive">
            <table id="paymentTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Guest Name</th>
                        <th>Contact</th>
                        <th>Room</th>
                        <th>Days</th> <th class="text-right">Amount</th>
                        <th class="text-right">Extra</th>
                        <th class="text-right">Disc.</th>
                        <th class="text-right">Net Amount</th>
                        <th class="text-right">Balance</th> <th>Method</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if ($result && $result->num_rows > 0): 
                        $i=1; 
                        $grand_total = 0;
                        while($row = $result->fetch_assoc()): 
                            $grand_total += $row['net_amount'];
                            $badge_method_class = strtolower(str_replace(' ', '-', $row['payment_method']));
                            $status_class = strtolower($row['status']);

                            $days = max(1, $row['days_stayed']); 
                            $total_bill = ($days * $row['room_rate']);
                            $total_paid = $row['total_paid_history'];
                            $balance = $total_bill - $total_paid;
                            $show_balance = ($row['status'] == 'Paid') ? 0 : $balance;
                    ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($row['guest_name']) ?></td>
                            <td><?= htmlspecialchars($row['contact']) ?></td>
                            <td>
                                <?= htmlspecialchars($row['room_name']) ?>
                                <br><small style="color:#7f8c8d"><?= htmlspecialchars($row['room_type']) ?></small>
                            </td>
                            <td><strong><?= $days ?></strong></td> <td class="text-right"><?= number_format($row['amount'], 2) ?></td>
                            <td class="text-right"><?= number_format($row['extra_charges'], 2) ?></td>
                            <td class="text-right"><?= number_format($row['discount'], 2) ?></td>
                            <td class="text-right"><strong><?= number_format($row['net_amount'], 2) ?></strong></td>
                            
                            <td class="text-right">
                                <?php if($show_balance > 0): ?>
                                    <span class="text-danger">TZS <?= number_format($show_balance, 2) ?></span>
                                <?php else: ?>
                                    <span class="text-success">-</span>
                                <?php endif; ?>
                            </td>

                            <td><span class="badge badge-<?= $badge_method_class ?>"><?= htmlspecialchars($row['payment_method']) ?></span></td>
                            <td><?= date('d/M/y', strtotime($row['payment_date'])) ?></td>
                            <td><span class="badge badge-<?= $status_class ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        </tr>
                    <?php endwhile; ?>
                        <tr style="background: #f8f9fa; font-weight: bold; border-top: 3px solid #1e3a5f;">
                            <td colspan="8" class="text-right" style="padding: 20px;">GRAND TOTAL REVENUE:</td>
                            <td class="text-right" style="font-size: 1.1rem; color: #1e3a5f;">TZS <?= number_format($grand_total, 2) ?></td>
                            <td colspan="4"></td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="13" style="text-align:center; padding: 20px; color:#7f8c8d;">No payment records found.</td></tr>
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

    // FUNCTION 1: Export to Excel
    function exportToExcel() {
        var table = document.getElementById("paymentTable");
        var html = table.outerHTML;
        var url = 'data:application/vnd.ms-excel,' + escape(html); 
        var link = document.createElement("a");
        link.href = url;
        link.download = "Payment_Report_" + new Date().toISOString().slice(0,10) + ".xls";
        link.click();
    }

    // FUNCTION 2: Export to PDF
    function exportToPDF() {
        const element = document.getElementById('reportContent');
        element.classList.add('pdf-mode');

        var opt = {
            margin:       0.2,
            filename:     'Payment_Report.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true },
            jsPDF:        { unit: 'in', format: 'a3', orientation: 'landscape' }
        };

        html2pdf().set(opt).from(element).save().then(function(){
            element.classList.remove('pdf-mode');
        });
    }
</script>

</body>
</html>