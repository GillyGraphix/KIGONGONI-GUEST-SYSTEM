<?php
session_start();
include 'db_connect.php'; 

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security: only receptionist
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

// --- INITIALIZE VARIABLES ---
$currentPage = basename($_SERVER['PHP_SELF']);
$success = $error = '';

// --- FUNCTION TO CALCULATE PAYMENT STATUS ---
function calculatePaymentStatus($guest_id, $conn) {
    $guest_query = $conn->prepare("SELECT room_rate, DATEDIFF(checkout_date, checkin_date) as nights FROM guest WHERE guest_id = ?");
    $guest_query->bind_param("i", $guest_id);
    $guest_query->execute();
    $guest_result = $guest_query->get_result();
    
    if ($guest_row = $guest_result->fetch_assoc()) {
        $nights = max(1, $guest_row['nights']); 
        $total_due = $guest_row['room_rate'] * $nights;
        
        $payment_query = $conn->prepare("SELECT SUM(amount + extra_charges - discount) as total_paid FROM payments WHERE guest_id = ?");
        $payment_query->bind_param("i", $guest_id);
        $payment_query->execute();
        $payment_result = $payment_query->get_result();
        $payment_row = $payment_result->fetch_assoc();
        
        $total_paid = $payment_row['total_paid'] ?? 0;
        
        if ($total_paid >= $total_due) return 'Paid';
        elseif ($total_paid > 0) return 'Partial';
        else return 'Pending';
    }
    return 'Paid'; 
}

// --- FUNCTION TO UPDATE ALL PAYMENT STATUSES FOR A GUEST ---
function updateGuestPaymentStatuses($guest_id, $conn) {
    $status = calculatePaymentStatus($guest_id, $conn);
    // REKEBISHO: Tumetumia 'payment_status' badala ya 'status' kuendana na Error yako
    $update_stmt = $conn->prepare("UPDATE payments SET payment_status = ? WHERE guest_id = ?");
    $update_stmt->bind_param("si", $status, $guest_id);
    $update_stmt->execute();
    $update_stmt->close();
    return $status;
}

// --- GENERATE RECEIPT ---
if (isset($_GET['action']) && $_GET['action'] == 'get_receipt' && isset($_GET['payment_id'])) {
    $payment_id = intval($_GET['payment_id']);
    
    $receipt_query = $conn->prepare("
        SELECT p.*, 
               p.guest_name AS saved_name,
               p.room_name AS saved_room,
               g.first_name, g.last_name, g.room_name AS active_room, g.phone, g.email, g.room_rate,
               DATEDIFF(g.checkout_date, g.checkin_date) as active_nights
        FROM payments p 
        LEFT JOIN guest g ON p.guest_id = g.guest_id 
        WHERE p.payment_id = ?
    ");
    $receipt_query->bind_param("i", $payment_id);
    $receipt_query->execute();
    $receipt_result = $receipt_query->get_result();
    
    if ($receipt_result && $row = $receipt_result->fetch_assoc()) {
        $display_name = !empty($row['saved_name']) ? $row['saved_name'] : ($row['first_name'] . ' ' . $row['last_name']);
        $display_room = !empty($row['saved_room']) ? $row['saved_room'] : $row['active_room'];
        
        $this_payment = $row['amount'] + $row['extra_charges'] - $row['discount'];
        
        if (!empty($row['first_name'])) {
            $nights = max(1, $row['active_nights']);
            $total_due = $row['room_rate'] * $nights;
            
            $paid_query = $conn->prepare("SELECT SUM(amount + extra_charges - discount) as total_paid FROM payments WHERE guest_id = ?");
            $paid_query->bind_param("i", $row['guest_id']);
            $paid_query->execute();
            $paid_res = $paid_query->get_result()->fetch_assoc();
            $total_paid = $paid_res['total_paid'] ?? 0;
            $balance = $total_due - $total_paid;
        } else {
            $total_due = $this_payment; 
            $total_paid = $this_payment;
            $balance = 0;
        }
        
        // REKEBISHO: Tumetumia payment_status badala ya status
        $status_class = strtolower($row['payment_status']);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt #<?= str_pad($row['payment_id'], 6, '0', STR_PAD_LEFT) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background: #f5f5f5; color: #333; }
        .receipt-container { max-width: 400px; margin: 40px auto; background: #fff; padding: 30px; border: 1px solid #ddd; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 15px; }
        .header h1 { font-size: 20px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .header p { font-size: 12px; color: #666; }
        .meta { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 15px; }
        .section { margin-bottom: 15px; border-bottom: 1px dashed #eee; padding-bottom: 10px; }
        .row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; }
        .row strong { font-weight: 600; }
        .total-row { display: flex; justify-content: space-between; margin-top: 15px; font-size: 16px; font-weight: bold; border-top: 2px solid #333; padding-top: 10px; }
        .footer { text-align: center; font-size: 10px; margin-top: 30px; color: #999; }
        .badge { padding: 3px 6px; border-radius: 4px; color: #fff; font-size: 10px; text-transform: uppercase; }
        .bg-paid { background: #28a745; } .bg-partial { background: #ffc107; color: black; } .bg-pending { background: #dc3545; }
        @media print { body { background: #fff; } .receipt-container { box-shadow: none; border: none; margin: 0; width: 100%; max-width: 100%; } }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="header">
            <h1>Official Receipt</h1>
            <p>Hotel Management System</p>
            <p>Receipt #: <strong><?= str_pad($row['payment_id'], 6, '0', STR_PAD_LEFT) ?></strong></p>
        </div>
        
        <div class="meta">
            <span>Date: <?= date('d M Y', strtotime($row['payment_date'])) ?></span>
            <span>Ref: <?= htmlspecialchars($row['reference_number'] ?: '-') ?></span>
        </div>

        <div class="section">
            <div class="row"><span>Guest:</span> <strong><?= htmlspecialchars($display_name) ?></strong></div>
            <div class="row"><span>Room:</span> <strong><?= htmlspecialchars($display_room) ?></strong></div>
            <div class="row"><span>Method:</span> <strong><?= htmlspecialchars($row['payment_method']) ?></strong></div>
        </div>

        <div class="section">
            <div class="row"><span>Amount:</span> <span><?= number_format($row['amount'], 2) ?></span></div>
            <?php if($row['extra_charges'] > 0): ?>
            <div class="row"><span>Extra Charges:</span> <span><?= number_format($row['extra_charges'], 2) ?></span></div>
            <?php endif; ?>
            <?php if($row['discount'] > 0): ?>
            <div class="row"><span>Discount:</span> <span>- <?= number_format($row['discount'], 2) ?></span></div>
            <?php endif; ?>
            <div class="total-row"><span>PAID NOW:</span> <span>TZS <?= number_format($this_payment, 2) ?></span></div>
        </div>

        <div class="section" style="background: #f9f9f9; padding: 10px; border-radius: 5px;">
            <div class="row"><span>Total Due:</span> <strong><?= number_format($total_due, 2) ?></strong></div>
            <div class="row"><span>Total Paid:</span> <strong><?= number_format($total_paid, 2) ?></strong></div>
            <div class="row"><span>Balance:</span> <strong style="color: <?= $balance > 0 ? 'red' : 'green' ?>"><?= number_format($balance, 2) ?></strong></div>
            <div class="row" style="margin-top:5px;"><span>Status:</span> <span class="badge bg-<?= $status_class ?>"><?= htmlspecialchars($row['payment_status']) ?></span></div>
        </div>

        <?php if($row['notes']): ?>
        <div class="section">
            <p style="font-size: 11px; color: #555;">Note: <?= nl2br(htmlspecialchars($row['notes'])) ?></p>
        </div>
        <?php endif; ?>

        <div class="footer">
            <p>Thank you for your business!</p>
            <p>Printed by: <?= htmlspecialchars($_SESSION['username']) ?></p>
        </div>
    </div>
    <script>window.print();</script>
</body>
</html>
        <?php
    } else {
        echo "<html><body><h3 style='color:red; text-align:center;'>Receipt not found.</h3></body></html>";
    }
    exit();
}

// --- FETCH CHECKED-IN GUESTS WITH PAYMENT INFO ---
$checked_in_guests = [];
$guest_stmt = $conn->prepare("SELECT g.guest_id, CONCAT(g.first_name, ' ', g.last_name) as fullname, 
                             g.room_name, g.room_type, g.room_rate, g.checkin_date, g.checkout_date, 
                             r.room_id,
                             DATEDIFF(g.checkout_date, g.checkin_date) as nights,
                             (SELECT SUM(amount + extra_charges - discount) FROM payments WHERE guest_id = g.guest_id) as total_paid
                             FROM guest g 
                             LEFT JOIN rooms r ON g.room_name = r.room_name
                             WHERE g.status='Checked-in' 
                             ORDER BY fullname");
$guest_stmt->execute();
$guest_result = $guest_stmt->get_result();
while ($row = $guest_result->fetch_assoc()) {
    $row['nights'] = max(1, $row['nights']);
    $row['total_due'] = $row['room_rate'] * $row['nights'];
    $row['total_paid'] = $row['total_paid'] ?? 0;
    $row['balance'] = $row['total_due'] - $row['total_paid'];
    $checked_in_guests[] = $row;
}
$guest_stmt->close();

// --- HANDLE PAYMENT SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    $guest_id = intval($_POST['guest_id']);
    
    $room_id = 0;
    $current_guest_name = '';
    $current_room_name = '';
    
    foreach ($checked_in_guests as $g) {
        if ($g['guest_id'] == $guest_id) {
            $room_id = $g['room_id'] ?? 0;
            $current_guest_name = $g['fullname'];
            $current_room_name = $g['room_name'];
            break;
        }
    }
    
    $amount = floatval($_POST['amount']);
    $payment_method = $_POST['payment_method'];
    $payment_date = $_POST['payment_date'];
    $reference_number = $_POST['reference_number'] ?? '';
    $discount = floatval($_POST['discount'] ?? 0);
    $extra_charges = floatval($_POST['extra_charges'] ?? 0);
    $extra_charge_type = $_POST['extra_charge_type'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    if ($extra_charges > 0 && !empty($extra_charge_type)) {
        $charge_detail = "[Extra: " . $extra_charge_type . "]";
        $notes = !empty($notes) ? $charge_detail . " - " . $notes : $charge_detail;
    }

    if ($guest_id > 0 && $amount >= 0 && $payment_method && $payment_date) {
        // MAREKEBISHO: Tumetumia 'payment_status' badala ya 'status' kuendana na Database yako
        $stmt = $conn->prepare("INSERT INTO payments (guest_id, guest_name, room_id, room_name, amount, payment_method, payment_date, reference_number, payment_status, discount, extra_charges, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?)");
        
        if (!$stmt) {
            $error = "Prepare failed: " . $conn->error;
        } else {
            $bind_result = $stmt->bind_param("isisssssdds", $guest_id, $current_guest_name, $room_id, $current_room_name, $amount, $payment_method, $payment_date, $reference_number, $discount, $extra_charges, $notes);
            
            if ($stmt->execute()) {
                $new_status = updateGuestPaymentStatuses($guest_id, $conn);
                
                $receptionist_name = $_SESSION['fullname'] ?? $_SESSION['username'];
                $log_desc = "Received TZS " . number_format($amount, 2) . " from $current_guest_name (Room: $current_room_name). Method: $payment_method";
                if ($extra_charges > 0) $log_desc .= " [Extra: " . number_format($extra_charges) . "]";
                $conn->query("INSERT INTO activity_logs (username, role, action, description, timestamp) VALUES ('$receptionist_name', 'Receptionist', 'Payment Received', '$log_desc', NOW())");
                
                $success = "Payment recorded successfully! Status: $new_status";
            } else {
                $error = "Execute failed: " . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        $error = "Please fill all required fields correctly.";
    }
}

// --- FETCH PAYMENT HISTORY WITH FILTERS ---
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_method = $_GET['filter_method'] ?? '';
$search_guest = $_GET['search_guest'] ?? '';

$where_clauses = [];
$params = [];
$types = "";

if ($filter_date_from && $filter_date_to) {
    $where_clauses[] = "DATE(p.payment_date) BETWEEN ? AND ?";
    $params[] = $filter_date_from; $params[] = $filter_date_to; $types .= "ss";
} elseif ($filter_date_from) {
    $where_clauses[] = "DATE(p.payment_date) >= ?";
    $params[] = $filter_date_from; $types .= "s";
} elseif ($filter_date_to) {
    $where_clauses[] = "DATE(p.payment_date) <= ?";
    $params[] = $filter_date_to; $types .= "s";
}

if ($filter_method) {
    $where_clauses[] = "p.payment_method = ?";
    $params[] = $filter_method; $types .= "s";
}

if ($search_guest) {
    $where_clauses[] = "(p.guest_name LIKE ? OR CONCAT(g.first_name, ' ', g.last_name) LIKE ? OR p.room_name LIKE ?)";
    $search_term = "%$search_guest%";
    $params[] = $search_term; $params[] = $search_term; $params[] = $search_term; $types .= "sss";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

$payment_history = [];
// MAREKEBISHO: 'payment_status' badala ya 'status'
$history_query = "SELECT p.payment_id, p.guest_id, COALESCE(NULLIF(p.guest_name, ''), CONCAT(g.first_name, ' ', g.last_name)) as display_name, COALESCE(NULLIF(p.room_name, ''), g.room_name) as display_room, (p.amount + p.extra_charges - p.discount) AS net_amount, p.amount, p.payment_method, p.payment_date, p.payment_status, p.reference_number, p.discount, p.extra_charges, g.room_rate, DATEDIFF(g.checkout_date, g.checkin_date) as nights FROM payments p LEFT JOIN guest g ON p.guest_id = g.guest_id $where_sql ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 50";

if ($types) {
    $stmt = $conn->prepare($history_query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    }
} else {
    $result = $conn->query($history_query);
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        if ($row['room_rate']) {
            $nights = max(1, $row['nights']);
            $row['total_due'] = $row['room_rate'] * $nights;
            $paid_res = $conn->query("SELECT SUM(amount + extra_charges - discount) as total_paid FROM payments WHERE guest_id = " . $row['guest_id'])->fetch_assoc();
            $row['total_paid'] = $paid_res['total_paid'] ?? 0;
            $row['balance'] = $row['total_due'] - $row['total_paid'];
        } else {
            $row['total_due'] = $row['net_amount']; 
            $row['total_paid'] = $row['net_amount'];
            $row['balance'] = 0;
        }
        $payment_history[] = $row;
    }
}

// --- FETCH SUMMARY STATS ---
$today_total = $conn->query("SELECT SUM(amount + extra_charges - discount) as total FROM payments WHERE DATE(payment_date) = CURDATE()")->fetch_assoc()['total'] ?? 0;
$month_total = $conn->query("SELECT SUM(amount + extra_charges - discount) as total FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())")->fetch_assoc()['total'] ?? 0;
$pending_count = $conn->query("SELECT COUNT(DISTINCT guest_id) as count FROM payments WHERE payment_status='Pending' OR payment_status='Partial'")->fetch_assoc()['count'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payments - Hotel Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
/* ... (CSS STYLES BAKI VILEVILE) ... */
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; }
.sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: #1e3a5f; color: #fff; padding: 30px 0; display: flex; flex-direction: column; box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1); z-index: 1000; transition: transform 0.3s ease-in-out; }
.sidebar-header { padding: 0 25px 30px 25px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); margin-bottom: 20px; }
.sidebar-header h2 { font-weight: 600; font-size: 1.2rem; color: #fff; margin-bottom: 5px; text-align: center; }
.sidebar-header p { font-size: 0.85rem; color: rgba(255, 255, 255, 0.7); text-align: center; }
.sidebar-nav { flex: 1; padding: 0 15px; overflow-y: auto; }
.sidebar a { text-decoration: none; color: rgba(255, 255, 255, 0.85); display: flex; align-items: center; padding: 14px 18px; margin: 5px 0; border-radius: 10px; transition: all 0.3s ease; font-weight: 500; font-size: 0.95rem; }
.sidebar a i { margin-right: 14px; font-size: 1.1rem; width: 20px; text-align: center; }
.sidebar a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; transform: translateX(5px); }
.sidebar a.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
.logout-section { padding: 0 15px 20px 15px; border-top: 1px solid rgba(255, 255, 255, 0.1); margin-top: 20px; padding-top: 20px; }
.logout-btn { width: 100%; padding: 12px 18px; background: #dc3545; color: #fff; border: none; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; font-weight: 600; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; }
.logout-btn i { margin-right: 10px; }
.logout-btn:hover { background: #c82333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3); }

.main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; transition: margin-left 0.3s ease-in-out; }
.header { margin-bottom: 30px; background: #fff; padding: 25px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; justify-content: space-between; align-items: center; }
.header h2 { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; display: flex; align-items: center; gap: 10px; }
.menu-toggle { display: none; font-size: 1.5rem; color: #1e3a5f; cursor: pointer; }

.payment-grid { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 25px; margin-bottom: 30px; }
.form-card { background: #fff; border-radius: 15px; padding: 30px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); }
.section-title { font-size: 1.1rem; font-weight: 700; color: #1e3a5f; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #e9ecef; display: flex; align-items: center; gap: 10px; }

.form-group { margin-bottom: 18px; }
.form-label { font-weight: 600; margin-bottom: 8px; color: #2c3e50; font-size: 0.9rem; display: block; }
.form-control { width: 100%; padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 0.95rem; transition: all 0.3s ease; background: #f8f9fa; }
.form-control:focus { outline: none; border-color: #667eea; background: #fff; }

.guest-info { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; padding: 20px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3); }
.guest-info h5 { font-size: 1rem; font-weight: 600; margin-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 5px; }
.info-row { display: flex; justify-content: space-between; margin: 8px 0; font-size: 0.9rem; }

.calculation-box { background: #fff; border: 1px solid #e9ecef; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
.calc-row { display: flex; justify-content: space-between; margin: 8px 0; font-size: 0.9rem; color: #555; }
.calc-row.total { border-top: 1px dashed #ccc; padding-top: 10px; margin-top: 10px; font-weight: 700; color: #1e3a5f; font-size: 1.1rem; }
.calc-row.balance { background: #f8f9fa; padding: 10px; border-radius: 8px; margin-top: 15px; font-weight: 700; color: #2c3e50; border: 1px solid #e9ecef; }

.btn-submit { width: 100%; padding: 14px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: #fff; border: none; border-radius: 10px; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 10px; }
.btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4); }

.filter-section { background: #fff; border-radius: 15px; padding: 25px 30px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); margin-bottom: 25px; }
.filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
.btn-filter, .btn-clear { padding: 12px 20px; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
.btn-filter { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
.btn-clear { background: #6c757d; color: #fff; }

.table-card { background: #fff; border-radius: 15px; padding: 0; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); overflow: hidden; border: 1px solid #e9ecef; }
.table-responsive { overflow-x: auto; max-height: 500px; }
table { width: 100%; border-collapse: collapse; min-width: 900px; }
table th { background: #1e3a5f; color: #fff; text-align: left; padding: 15px 20px; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
table td { padding: 15px 20px; border-bottom: 1px solid #e9ecef; color: #2c3e50; font-size: 0.9rem; }
table tbody tr:hover { background: #f8f9fa; }

.badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; text-transform: uppercase; }
.badge-paid { background: #d4edda; color: #155724; }
.badge-partial { background: #fff3cd; color: #856404; }
.badge-pending { background: #f8d7da; color: #721c24; }
.badge-method { background: #e2e3e5; color: #383d41; font-size: 0.7rem; }

.btn-action { padding: 6px 12px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.8rem; font-weight: 600; transition: all 0.3s ease; margin-right: 5px; }
.btn-receipt { background: #17a2b8; color: #fff; }
.btn-receipt:hover { background: #138496; }

.sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 900; }

@media (max-width: 1024px) { .payment-grid { grid-template-columns: 1fr; } }
@media (max-width: 768px) { 
    .sidebar { transform: translateX(-100%); width: 250px; } 
    .sidebar.active { transform: translateX(0); } 
    .sidebar-overlay.active { display: block; }
    .main-content { margin-left: 0; padding: 20px 15px; } 
    .menu-toggle { display: block; }
    .header { flex-direction: column; align-items: flex-start; gap: 15px; }
}
</style>
</head>
<body>

<?php if ($success): ?>
<script>
    Swal.fire({ icon: 'success', title: 'Success!', text: '<?= htmlspecialchars($success) ?>', showConfirmButton: false, timer: 2000 });
    setTimeout(function() { window.location.href = 'payments.php'; }, 2000);
</script>
<?php endif; ?>
<?php if ($error): ?>
<script>
    Swal.fire({ icon: 'error', title: 'Error!', text: '<?= htmlspecialchars($error) ?>' });
</script>
<?php endif; ?>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2>Receptionist Dashboard</h2>
        <p>Front Desk Management</p>
    </div>
    <div class="sidebar-nav">
        <a href="receptionist_dashboard.php" class="<?= $currentPage=='receptionist_dashboard.php'?'active':'' ?>"><i class="fa-solid fa-house"></i> <span>Dashboard</span></a>
        <a href="add_guest.php" class="<?= $currentPage=='add_guest.php'?'active':'' ?>"><i class="fa-solid fa-user-plus"></i> <span>Add Guest</span></a>
        <a href="view_guests.php" class="<?= $currentPage=='view_guests.php'?'active':'' ?>"><i class="fa-solid fa-users"></i> <span>View Guests</span></a>
        <a href="rooms.php" class="<?= $currentPage=='rooms.php'?'active':'' ?>"><i class="fa-solid fa-bed"></i> <span>Rooms</span></a>
        <a href="checkin_checkout.php" class="<?= $currentPage=='checkin_checkout.php'?'active':'' ?>"><i class="fa-solid fa-door-open"></i> <span>Check-in / Check-out</span></a>
        <a href="payments.php" class="<?= $currentPage=='payments.php'?'active':'' ?>"><i class="fa-solid fa-credit-card"></i> <span>Payments</span></a>
        <a href="bookings.php" class="<?= $currentPage=='bookings.php'?'active':'' ?>"><i class="fa-solid fa-calendar-check"></i> <span>Reservations</span></a>
    </div>
    <div class="logout-section"><form action="logout.php" method="POST"><button type="submit" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span></button></form></div>
</div>

<div class="main-content">
    <div class="header">
        <h2>
            <i class="fa-solid fa-bars menu-toggle" onclick="toggleSidebar()"></i>
            <span><i class="fa-solid fa-credit-card"></i> Payment Management</span>
        </h2>
    </div>

    <div class="payment-grid">
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-money-bill-wave"></i> Record Payment</h4>
            <form method="POST" id="paymentForm">
                <div class="form-group">
                    <label class="form-label">Select Guest<span class="required">*</span></label>
                    <select name="guest_id" id="guest_id" class="form-control" required>
                        <option value="">Choose a guest...</option>
                        <?php foreach($checked_in_guests as $guest): ?>
                        <option value="<?= $guest['guest_id'] ?>"
                                data-room="<?= htmlspecialchars($guest['room_name']) ?>"
                                data-type="<?= htmlspecialchars($guest['room_type']) ?>"
                                data-nights="<?= $guest['nights'] ?>"
                                data-total-due="<?= $guest['total_due'] ?>"
                                data-total-paid="<?= $guest['total_paid'] ?>"
                                data-balance="<?= $guest['balance'] ?>">
                            <?= htmlspecialchars($guest['fullname']) ?> - Room <?= htmlspecialchars($guest['room_name']) ?>
                            <?php if($guest['balance'] > 0): ?> (Due: <?= number_format($guest['balance']) ?>) <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="guest-info" id="guestInfo" style="display:none;">
                    <h5><i class="fa-solid fa-user-circle"></i> Guest Status</h5>
                    <div class="info-row"><span>Room:</span><strong id="displayRoom">-</strong></div>
                    <div class="info-row"><span>Total Due:</span><strong id="displayTotalDue">-</strong></div>
                    <div class="info-row"><span>Paid So Far:</span><strong id="displayTotalPaid">-</strong></div>
                    <div class="info-row" style="margin-top:10px; font-size:1rem; border-top:1px dashed rgba(255,255,255,0.3); padding-top:5px;">
                        <span>Current Balance:</span><strong id="displayBalance">-</strong>
                    </div>
                </div>

                <div class="form-group"><label class="form-label">Payment Amount (TZS)<span class="required">*</span></label><input type="number" name="amount" id="amount" class="form-control" step="0.01" required></div>
                
                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div><label class="form-label">Discount</label><input type="number" name="discount" id="discount" class="form-control" step="0.01" value="0"></div>
                    <div><label class="form-label">Extra Charges</label><input type="number" name="extra_charges" id="extra_charges" class="form-control" step="0.01" value="0"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Extra Charge Type</label>
                    <select name="extra_charge_type" id="extra_charge_type" class="form-control">
                        <option value="">None</option>
                        <option value="Food">Food / Restaurant</option>
                        <option value="Drinks">Drinks / Bar</option>
                        <option value="Laundry">Laundry</option>
                        <option value="Damages">Damages</option>
                        <option value="Other">Other Services</option>
                    </select>
                </div>

                <div class="calculation-box">
                    <div class="calc-row"><span>Subtotal:</span><strong id="calcRoom">0.00</strong></div>
                    <div class="calc-row"><span>+ Extra:</span><strong id="calcExtra">0.00</strong></div>
                    <div class="calc-row"><span>- Discount:</span><strong id="calcDiscount">0.00</strong></div>
                    <div class="calc-row total"><span>Net Paying Now:</span><strong id="calcTotal" style="color:#28a745;">0.00</strong></div>
                    <div class="calc-row balance"><span>Remaining Balance:</span><strong id="calcNewBalance">0.00</strong></div>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Method<span class="required">*</span></label>
                    <select name="payment_method" class="form-control" required>
                        <option value="">Select Method</option>
                        <option value="Cash">Cash</option>
                        <option value="Credit Card">Credit Card</option>
                        <option value="Mobile Payment">Mobile Payment (M-Pesa/Tigo)</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                    </select>
                </div>
                
                <div class="form-group"><label class="form-label">Reference No. (Optional)</label><input type="text" name="reference_number" class="form-control" placeholder="Receipt / Transaction ID"></div>
                <div class="form-group"><label class="form-label">Date<span class="required">*</span></label><input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" placeholder="Any comments..."></textarea></div>
                
                <button type="submit" name="add_payment" class="btn-submit"><i class="fa-solid fa-check-circle"></i> Confirm Payment</button>
            </form>
        </div>

        <div>
            <div class="form-card" style="margin-bottom: 25px; background: linear-gradient(135deg, #1e3a5f 0%, #2c3e50 100%); color: white;">
                <h4 class="section-title" style="color: white; border-color: rgba(255,255,255,0.2);"><i class="fa-solid fa-chart-pie"></i> Quick Stats</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
                    <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 10px; text-align: center;">
                        <span style="display: block; font-size: 0.8rem; opacity: 0.8;">Today's Income</span>
                        <strong style="font-size: 1.2rem;">TZS <?= number_format($today_total) ?></strong>
                    </div>
                    <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 10px; text-align: center;">
                        <span style="display: block; font-size: 0.8rem; opacity: 0.8;">This Month</span>
                        <strong style="font-size: 1.2rem;">TZS <?= number_format($month_total) ?></strong>
                    </div>
                </div>
                <div style="margin-top: 20px; background: rgba(220, 53, 69, 0.2); padding: 15px; border-radius: 10px; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-exclamation-circle" style="font-size: 1.5rem; color: #ff6b6b;"></i>
                    <div>
                        <strong style="font-size: 1.1rem;"><?= $pending_count ?> Guests</strong>
                        <span style="display: block; font-size: 0.85rem; opacity: 0.9;">have pending balances.</span>
                    </div>
                </div>
            </div>

            <div class="form-card">
                <h4 class="section-title"><i class="fa-solid fa-info-circle"></i> Legend</h4>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="badge badge-paid">Paid</span> <span style="font-size: 0.9rem; color: #555;">Fully settled bill.</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="badge badge-partial">Partial</span> <span style="font-size: 0.9rem; color: #555;">Paid some amount.</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="badge badge-pending">Pending</span> <span style="font-size: 0.9rem; color: #555;">No payment yet.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="history-section" class="filter-section">
        <h4 class="section-title"><i class="fa-solid fa-filter"></i> Payment History & Receipts</h4>
        <form method="GET" class="filter-grid">
            <div class="form-group">
                <label class="form-label">Search</label>
                <input type="text" name="search_guest" class="form-control" placeholder="Guest Name / Room..." value="<?= htmlspecialchars($search_guest) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Date From</label>
                <input type="date" name="filter_date_from" class="form-control" value="<?= htmlspecialchars($filter_date_from) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Date To</label>
                <input type="date" name="filter_date_to" class="form-control" value="<?= htmlspecialchars($filter_date_to) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Method</label>
                <select name="filter_method" class="form-control">
                    <option value="">All</option>
                    <option value="Cash" <?= $filter_method=='Cash'?'selected':'' ?>>Cash</option>
                    <option value="Mobile Payment" <?= $filter_method=='Mobile Payment'?'selected':'' ?>>Mobile Payment</option>
                    <option value="Credit Card" <?= $filter_method=='Credit Card'?'selected':'' ?>>Credit Card</option>
                </select>
            </div>
            <div class="form-group" style="display: flex; gap: 10px;">
                <button type="submit" class="btn-filter"><i class="fa-solid fa-search"></i> Filter</button>
                <a href="payments.php?cleared=1" class="btn-clear"><i class="fa-solid fa-rotate-right"></i> Reset</a>
            </div>
        </form>
    </div>

    <div class="table-card">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Guest Name</th>
                        <th>Room</th>
                        <th class="text-right">Paid Now</th>
                        <th class="text-right">Total Due</th>
                        <th class="text-right">Balance</th>
                        <th>Method</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($payment_history) > 0): ?>
                        <?php foreach ($payment_history as $payment): 
                            $status_class = strtolower($payment['payment_status']);
                        ?>
                            <tr>
                                <td>#<?= $payment['payment_id'] ?></td>
                                <td><strong><?= htmlspecialchars($payment['display_name']) ?></strong></td>
                                <td><?= htmlspecialchars($payment['display_room']) ?></td>
                                <td class="text-right" style="color: #1e3a5f; font-weight: 700;">TZS <?= number_format($payment['net_amount']) ?></td>
                                <td class="text-right">TZS <?= number_format($payment['total_due']) ?></td>
                                <td class="text-right"><strong style="color: <?= $payment['balance'] > 0 ? '#dc3545' : '#28a745' ?>">TZS <?= number_format($payment['balance']) ?></strong></td>
                                <td><span class="badge badge-method"><?= htmlspecialchars($payment['payment_method']) ?></span></td>
                                <td><?= date('d M', strtotime($payment['payment_date'])) ?></td>
                                <td><span class="badge badge-<?= $status_class ?>"><?= htmlspecialchars($payment['payment_status']) ?></span></td>
                                <td class="text-center">
                                    <button class="btn-action btn-receipt" onclick="printReceipt(<?= $payment['payment_id'] ?>)"><i class="fa-solid fa-print"></i> Receipt</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" class="no-data">No records found.</td></tr>
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

document.addEventListener('DOMContentLoaded', function() {
    const guestSelect = document.getElementById('guest_id');
    const amountInput = document.getElementById('amount');
    const discountInput = document.getElementById('discount');
    const extraChargesInput = document.getElementById('extra_charges');
    const guestInfoDiv = document.getElementById('guestInfo');
    
    let currentBalance = 0;
    
    function formatCurrency(number) { return `TZS ${parseFloat(number).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",")}`; }

    function updateCalculation() {
        const amount = parseFloat(amountInput.value) || 0;
        const discount = parseFloat(discountInput.value) || 0;
        const extra_charges = parseFloat(extraChargesInput.value) || 0;
        const netPayment = (amount + extra_charges) - discount;
        const newBalance = currentBalance - netPayment;
        
        document.getElementById('calcRoom').textContent = formatCurrency(amount);
        document.getElementById('calcExtra').textContent = formatCurrency(extra_charges);
        document.getElementById('calcDiscount').textContent = `- ${formatCurrency(discount)}`;
        document.getElementById('calcTotal').textContent = formatCurrency(netPayment);
        document.getElementById('calcNewBalance').textContent = formatCurrency(Math.max(0, newBalance));
        
        const balanceElement = document.getElementById('calcNewBalance');
        if (newBalance <= 0) { balanceElement.style.color = '#28a745'; } else { balanceElement.style.color = '#dc3545'; }
    }
    
    function updateGuestInfo() {
        const selectedOption = guestSelect.options[guestSelect.selectedIndex];
        if (selectedOption.value) {
            const room = selectedOption.getAttribute('data-room');
            const totalDue = parseFloat(selectedOption.getAttribute('data-total-due'));
            const totalPaid = parseFloat(selectedOption.getAttribute('data-total-paid'));
            const balance = parseFloat(selectedOption.getAttribute('data-balance'));
            
            currentBalance = balance;
            
            document.getElementById('displayRoom').textContent = room;
            document.getElementById('displayTotalDue').textContent = formatCurrency(totalDue);
            document.getElementById('displayTotalPaid').textContent = formatCurrency(totalPaid);
            document.getElementById('displayBalance').textContent = formatCurrency(balance);
            
            amountInput.value = balance > 0 ? balance : '';
            guestInfoDiv.style.display = 'block';
        } else {
            guestInfoDiv.style.display = 'none'; 
            amountInput.value = ''; 
            currentBalance = 0;
        }
        updateCalculation();
    }
    
    window.printReceipt = function(paymentId) {
        const url = 'payments.php?action=get_receipt&payment_id=' + paymentId;
        const printWindow = window.open(url, '_blank', 'width=500,height=600');
        printWindow.onload = function() { printWindow.print(); };
    }

    guestSelect.addEventListener('change', updateGuestInfo);
    amountInput.addEventListener('input', updateCalculation);
    discountInput.addEventListener('input', updateCalculation);
    extraChargesInput.addEventListener('input', updateCalculation);
    
    if (guestSelect.value) { updateGuestInfo(); }
    
    if(window.location.search.includes('filter_') || window.location.search.includes('search_')) {
        document.getElementById('history-section').scrollIntoView({ behavior: 'smooth' });
    }
});
</script>

</body>
</html>