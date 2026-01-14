<?php
session_start();
include 'db_connect.php'; 

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security: only receptionist
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

// --- INITIALIZE VARIABLES ---
$currentPage = basename($_SERVER['PHP_SELF']);
$success = $error = '';

// --- FUNCTION TO CALCULATE PAYMENT STATUS ---
function calculatePaymentStatus($guest_id, $conn) {
    // 1. Check if guest exists first
    $guest_query = $conn->prepare("SELECT room_rate, DATEDIFF(checkout_date, checkin_date) as nights FROM guest WHERE guest_id = ?");
    $guest_query->bind_param("i", $guest_id);
    $guest_query->execute();
    $guest_result = $guest_query->get_result();
    
    if ($guest_row = $guest_result->fetch_assoc()) {
        // Guest exists (Active)
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
    
    // Update all payments for this guest
    $update_stmt = $conn->prepare("UPDATE payments SET status = ? WHERE guest_id = ?");
    $update_stmt->bind_param("si", $status, $guest_id);
    $update_stmt->execute();
    $update_stmt->close();
    
    return $status;
}

// --- GENERATE RECEIPT ---
if (isset($_GET['action']) && $_GET['action'] == 'get_receipt' && isset($_GET['payment_id'])) {
    $payment_id = intval($_GET['payment_id']);
    
    // UPDATED QUERY: Uses LEFT JOIN and fetches saved name/room from payments table
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
        // Determine Name: Use saved name if available (Checked out), otherwise use active guest name
        $display_name = !empty($row['saved_name']) ? $row['saved_name'] : ($row['first_name'] . ' ' . $row['last_name']);
        $display_room = !empty($row['saved_room']) ? $row['saved_room'] : $row['active_room'];
        
        // Calculate totals
        $this_payment = $row['amount'] + $row['extra_charges'] - $row['discount'];
        
        // Logic for total due/paid
        if (!empty($row['first_name'])) {
            // Guest is active, calculate normally
            $nights = max(1, $row['active_nights']);
            $total_due = $row['room_rate'] * $nights;
            
            $paid_query = $conn->prepare("SELECT SUM(amount + extra_charges - discount) as total_paid FROM payments WHERE guest_id = ?");
            $paid_query->bind_param("i", $row['guest_id']);
            $paid_query->execute();
            $paid_res = $paid_query->get_result()->fetch_assoc();
            $total_paid = $paid_res['total_paid'] ?? 0;
            $balance = $total_due - $total_paid;
        } else {
            // Guest is deleted (Checked out)
            $total_due = $this_payment; 
            $total_paid = $this_payment;
            $balance = 0;
        }
        
        $status_class = strtolower($row['status']);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt #<?= str_pad($row['payment_id'], 6, '0', STR_PAD_LEFT) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', sans-serif; background: #f5f5f5; }
        .receipt-container { max-width: 400px; margin: 20px auto; background: #fff; padding: 30px; border: 2px solid #1e3a5f; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .receipt-header { text-align: center; border-bottom: 2px solid #1e3a5f; padding-bottom: 15px; margin-bottom: 20px; }
        .receipt-header h1 { font-size: 24px; color: #1e3a5f; margin-bottom: 5px; }
        .receipt-header p { font-size: 12px; color: #666; }
        .receipt-id { text-align: center; background: #f8f9fa; padding: 10px; margin: 15px 0; border-radius: 5px; font-weight: bold; color: #1e3a5f; }
        .receipt-section { margin-bottom: 20px; }
        .receipt-section h3 { font-size: 12px; font-weight: bold; color: #1e3a5f; text-transform: uppercase; margin-bottom: 8px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        .receipt-row { display: flex; justify-content: space-between; margin: 8px 0; font-size: 13px; }
        .receipt-row strong { color: #1e3a5f; }
        .receipt-row.total { font-weight: bold; border-top: 2px solid #1e3a5f; border-bottom: 2px solid #1e3a5f; padding: 10px 0; margin: 15px 0; font-size: 16px; }
        .receipt-row.highlight { background: #fff3cd; padding: 8px; border-radius: 5px; font-weight: bold; }
        .receipt-footer { text-align: center; font-size: 11px; color: #999; margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-paid { background: #28a745; color: white; }
        .badge-partial { background: #ffc107; color: #000; }
        .badge-pending { background: #dc3545; color: white; }
        
        @media print { 
            body { background: white; margin: 0; padding: 0; } 
            .receipt-container { max-width: 100%; margin: 0; padding: 10px; border: none; box-shadow: none; } 
            .receipt-header { border-bottom: 1px dashed #666; padding-bottom: 10px; margin-bottom: 10px; }
            .receipt-row.total { border-top: 1px dashed #666; border-bottom: 1px dashed #666; }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <h1> KIGONGONI GAZELLA HOTEL PAYMENT RECEIPT</h1>
            <p>Hotel Management System</p>
        </div>
        
        <div class="receipt-id">Receipt #<?= str_pad($row['payment_id'], 6, '0', STR_PAD_LEFT) ?></div>
        
        <div class="receipt-section">
            <h3>Guest Information</h3>
            <div class="receipt-row">
                <span>Name:</span>
                <strong><?= htmlspecialchars($display_name) ?></strong>
            </div>
            <div class="receipt-row">
                <span>Room:</span>
                <strong><?= htmlspecialchars($display_room) ?></strong>
            </div>
            <div class="receipt-row">
                <span>Phone:</span>
                <strong><?= htmlspecialchars($row['phone'] ?: 'N/A') ?></strong>
            </div>
        </div>
        
        <div class="receipt-section">
            <h3>This Payment</h3>
            <div class="receipt-row">
                <span>Room Charge:</span>
                <strong>TZS <?= number_format($row['amount'], 2) ?></strong>
            </div>
            <?php if($row['extra_charges'] > 0): ?>
            <div class="receipt-row">
                <span>Extra Charges:</span>
                <strong>TZS <?= number_format($row['extra_charges'], 2) ?></strong>
            </div>
            <?php endif; ?>
            <?php if($row['discount'] > 0): ?>
            <div class="receipt-row">
                <span>Discount:</span>
                <strong>- TZS <?= number_format($row['discount'], 2) ?></strong>
            </div>
            <?php endif; ?>
            <div class="receipt-row total">
                <span>Amount Paid:</span>
                <strong>TZS <?= number_format($this_payment, 2) ?></strong>
            </div>
        </div>
        
        <div class="receipt-section">
            <h3>Payment Summary</h3>
            <div class="receipt-row">
                <span>Total Due:</span>
                <strong>TZS <?= number_format($total_due, 2) ?></strong>
            </div>
            <div class="receipt-row">
                <span>Total Paid:</span>
                <strong>TZS <?= number_format($total_paid, 2) ?></strong>
            </div>
            <div class="receipt-row highlight">
                <span>Balance:</span>
                <strong style="color: <?= $balance > 0 ? '#dc3545' : '#28a745' ?>">
                    TZS <?= number_format($balance, 2) ?>
                </strong>
            </div>
        </div>
        
        <div class="receipt-section">
            <h3>Payment Information</h3>
            <div class="receipt-row">
                <span>Method:</span>
                <strong><?= htmlspecialchars($row['payment_method']) ?></strong>
            </div>
            <div class="receipt-row">
                <span>Date:</span>
                <strong><?= date('d/M/Y H:i', strtotime($row['payment_date'])) ?></strong>
            </div>
            <div class="receipt-row">
                <span>Status:</span>
                <span class="badge badge-<?= $status_class ?>"><?= htmlspecialchars($row['status']) ?></span>
            </div>
            <?php if($row['reference_number']): ?>
            <div class="receipt-row">
                <span>Reference:</span>
                <strong><?= htmlspecialchars($row['reference_number']) ?></strong>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if($row['notes']): ?>
        <div class="receipt-section">
            <h3>Details / Notes</h3>
            <p style="font-size: 12px; line-height: 1.5; font-weight: 500; background: #eee; padding: 5px; border-radius: 4px;">
                <?= nl2br(htmlspecialchars($row['notes'])) ?>
            </p>
        </div>
        <?php endif; ?>
        
        <div class="receipt-footer">
            <p>Thank you for your payment!</p>
            <p>Printed by: <?= htmlspecialchars($_SESSION['username'] ?? 'System') ?> on: <?= date('d/M/Y H:i:s') ?></p>
        </div>
    </div>
</body>
</html>
        <?php
    } else {
        echo "<html><body><p style='color: red;'>Receipt not found or invalid payment ID.</p></body></html>";
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
    
    // Find room info from checked in array
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
    
    // CAPTURE EXTRA CHARGES AND TYPE
    $extra_charges = floatval($_POST['extra_charges'] ?? 0);
    $extra_charge_type = $_POST['extra_charge_type'] ?? '';
    
    // Combine Note with Extra Charge Type
    $notes = $_POST['notes'] ?? '';
    
    // KAMA KUNA EXTRA CHARGES, TUNABORESHA NOTES
    if ($extra_charges > 0 && !empty($extra_charge_type)) {
        $charge_detail = "[Extra Charge: " . $extra_charge_type . "]";
        if (!empty($notes)) {
            $notes = $charge_detail . " - " . $notes;
        } else {
            $notes = $charge_detail;
        }
    }

    if ($guest_id > 0 && $amount >= 0 && $payment_method && $payment_date) {
        // Insert payment
        $stmt = $conn->prepare("INSERT INTO payments (guest_id, guest_name, room_id, room_name, amount, payment_method, payment_date, reference_number, status, discount, extra_charges, notes) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?)");
        
        if (!$stmt) {
            $error = "Prepare failed: " . $conn->error;
        } else {
            // Need to match bind params
            $bind_result = $stmt->bind_param("isisssssdds", 
                $guest_id, $current_guest_name, $room_id, $current_room_name, $amount, $payment_method, $payment_date, $reference_number, $discount, $extra_charges, $notes
            );
            
            if (!$bind_result) {
                $error = "Bind failed: " . $stmt->error;
            } else {
                if ($stmt->execute()) {
                    // NOW AUTO-UPDATE STATUS BASED ON TOTAL PAID
                    $new_status = updateGuestPaymentStatuses($guest_id, $conn);
                    
                    // --- LOGGING (USHAHIDI WA PESA) ---
                    $log_desc = "Received TZS " . number_format($amount, 2) . " from $current_guest_name (Room: $current_room_name). Method: $payment_method";
                    
                    // Ongeza maelezo ya extra charges na discount kwenye log
                    if ($extra_charges > 0) $log_desc .= " [Extra: " . number_format($extra_charges) . "]";
                    if ($discount > 0) $log_desc .= " [Discount: " . number_format($discount) . "]";
                    
                    logActivity($conn, "Payment Received", $log_desc);
                    
                    $success = "Payment recorded successfully! Status: $new_status";
                } else {
                    $error = "Execute failed: " . $stmt->error;
                }
            }
            $stmt->close();
        }
    } else {
        $error = "Please fill all required fields correctly (Guest ID, Amount, Method, Date).";
    }
}

// --- FETCH PAYMENT HISTORY WITH FILTERS (UPDATED) ---
$filter_date = $_GET['filter_date'] ?? '';
$filter_method = $_GET['filter_method'] ?? '';
$search_guest = $_GET['search_guest'] ?? '';

$where_clauses = [];
$params = [];
$types = "";

if ($filter_date) {
    $where_clauses[] = "DATE(p.payment_date) = ?"; 
    $params[] = $filter_date;
    $types .= "s";
}
if ($filter_method) {
    $where_clauses[] = "p.payment_method = ?";
    $params[] = $filter_method;
    $types .= "s";
}
// SEARCH: Check both saved name (p) and active name (g)
if ($search_guest) {
    $where_clauses[] = "(p.guest_name LIKE ? OR CONCAT(g.first_name, ' ', g.last_name) LIKE ? OR p.room_name LIKE ?)";
    $search_term = "%$search_guest%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

$payment_history = [];

$history_query = "
    SELECT p.payment_id, p.guest_id, 
           COALESCE(NULLIF(p.guest_name, ''), CONCAT(g.first_name, ' ', g.last_name)) as display_name,
           COALESCE(NULLIF(p.room_name, ''), g.room_name) as display_room,
           (p.amount + p.extra_charges - p.discount) AS net_amount,
           p.amount, p.payment_method, p.payment_date, p.status, p.reference_number, p.discount, p.extra_charges,
           g.room_rate, DATEDIFF(g.checkout_date, g.checkin_date) as nights
    FROM payments p 
    LEFT JOIN guest g ON p.guest_id = g.guest_id 
    $where_sql
    ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 50";

$result = false;
if ($types) {
    $stmt = $conn->prepare($history_query);
    if ($stmt) {
        $bind_params = array_merge([$types], $params);
        $ref_params = [];
        foreach ($bind_params as $key => $value) {
            $ref_params[$key] = &$bind_params[$key];
        }
        
        call_user_func_array([$stmt, 'bind_param'], $ref_params);
        
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    } else {
        $error = "Payment history query preparation failed: " . $conn->error;
    }
} else {
    $result = $conn->query($history_query);
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        if ($row['room_rate']) {
            $nights = max(1, $row['nights']);
            $row['total_due'] = $row['room_rate'] * $nights;
            
            $paid_query = $conn->prepare("SELECT SUM(amount + extra_charges - discount) as total_paid FROM payments WHERE guest_id = ?");
            $paid_query->bind_param("i", $row['guest_id']);
            $paid_query->execute();
            $paid_res = $paid_query->get_result()->fetch_assoc();
            $row['total_paid'] = $paid_res['total_paid'] ?? 0;
            $row['balance'] = $row['total_due'] - $row['total_paid'];
        } else {
            $row['total_due'] = $row['net_amount']; 
            $row['total_paid'] = $row['net_amount'];
            $row['balance'] = 0;
        }
        
        $payment_history[] = $row;
    }
    if (is_object($result)) {
        $result->close();
    }
}

// --- FETCH SUMMARY STATS ---
$today_total = 0;
$today_query = $conn->query("SELECT SUM(amount + extra_charges - discount) as total FROM payments WHERE DATE(payment_date) = CURDATE()");
if ($today_query && $row = $today_query->fetch_assoc()) {
    $today_total = $row['total'] ?? 0;
    $today_query->close();
}

$month_total = 0;
$month_query = $conn->query("SELECT SUM(amount + extra_charges - discount) as total FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())");
if ($month_query && $row = $month_query->fetch_assoc()) {
    $month_total = $row['total'] ?? 0;
    $month_query->close();
}

$pending_count = 0;
$pending_query = $conn->query("SELECT COUNT(DISTINCT guest_id) as count FROM payments WHERE status='Pending' OR status='Partial'");
if ($pending_query && $row = $pending_query->fetch_assoc()) {
    $pending_count = $row['count'] ?? 0;
    $pending_query->close();
}
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

/* Grid Layout */
.payment-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
    margin-bottom: 30px;
}

/* Form Card */
.form-card {
    background: #fff;
    border-radius: 15px;
    padding: 30px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.section-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1e3a5f;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 3px solid #667eea;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    font-weight: 600;
    margin-bottom: 8px;
    color: #1e3a5f;
    font-size: 0.9rem;
    display: block;
}

.form-label .required {
    color: #dc3545;
    margin-left: 3px;
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

select.form-control {
    cursor: pointer;
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

/* Guest Info Display */
.guest-info {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
}

.guest-info h5 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 10px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin: 8px 0;
    font-size: 0.9rem;
}

.info-row strong {
    opacity: 0.9;
}

/* Calculation Display */
.calculation-box {
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.calc-row {
    display: flex;
    justify-content: space-between;
    margin: 10px 0;
    font-size: 0.95rem;
}

.calc-row.total {
    border-top: 2px solid #667eea;
    padding-top: 10px;
    margin-top: 15px;
    font-weight: 700;
    font-size: 1.1rem;
    color: #1e3a5f;
}

.calc-row.balance {
    background: #fff3cd;
    padding: 10px;
    border-radius: 8px;
    margin-top: 10px;
    font-weight: 700;
}

/* Submit Button */
.btn-submit {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
}

/* Filter Section */
.filter-section {
    background: #fff;
    border-radius: 15px;
    padding: 25px 30px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    margin-bottom: 25px;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    align-items: end;
}

.btn-filter {
    padding: 12px 20px;
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-filter:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4);
}

.btn-clear {
    padding: 12px 20px;
    background: #6c757d;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-clear:hover {
    background: #5a6268;
}

/* Payment History Table */
.table-card {
    background: #fff;
    border-radius: 15px;
    padding: 30px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.table-responsive {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
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
    border-bottom: 1px solid #e9ecef;
    color: #2c3e50;
}

table tbody tr:hover {
    background: #f8f9fa;
}

.badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-block;
}

/* Status Badges */
.badge-paid {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: #fff;
}

.badge-partial {
    background: linear-gradient(135deg, #ffc107, #ff9800);
    color: #000;
}

.badge-pending {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: #fff;
}

/* Method Badges */
.badge-cash {
    background: #28a745;
    color: #fff;
}

.badge-credit-card {
    background: #007bff;
    color: #fff;
}

.badge-mobile-payment {
    background: #17a2b8;
    color: #fff;
}

.text-right {
    text-align: right;
}

.text-center {
    text-align: center;
}

.no-data {
    text-align: center;
    padding: 40px;
    color: #7f8c8d;
    font-style: italic;
}

/* Action Buttons */
.btn-action {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.3s ease;
    margin-right: 5px;
}

.btn-receipt {
    background: #667eea;
    color: #fff;
}

.btn-receipt:hover {
    background: #5568d3;
}

/* Responsive */
@media (max-width: 1200px) {
    .payment-grid {
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
    
    .filter-grid {
        grid-template-columns: 1fr;
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

<?php if ($success): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: '<?= htmlspecialchars($success) ?>',
        showConfirmButton: false,
        timer: 3000
    });
    setTimeout(function() {
        window.location.href = 'payments.php';
    }, 1500);
</script>
<?php endif; ?>

<?php if ($error): ?>
<script>
    Swal.fire({
        icon: 'error',
        title: 'Error!',
        text: '<?= htmlspecialchars($error) ?>',
        footer: 'Check your database connection and payment table structure.',
        showConfirmButton: true
    });
</script>
<?php endif; ?>

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
            <span>Rooms</span>
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
        <h2><i class="fa-solid fa-credit-card"></i> Payment Management</h2>
    </div>

    <div class="payment-grid">
        <div class="form-card">
            <h4 class="section-title">
                <i class="fa-solid fa-money-bill-wave"></i> Record Payment
            </h4>

            <form method="POST" id="paymentForm">
                <div class="form-group">
                    <label class="form-label">Select Guest<span class="required">*</span></label>
                    <select name="guest_id" id="guest_id" class="form-control" required>
                        <option value="">Choose a guest...</option>
                        <?php foreach($checked_in_guests as $guest): ?>
                        <option value="<?= $guest['guest_id'] ?>"
                                data-room="<?= htmlspecialchars($guest['room_name']) ?>"
                                data-type="<?= htmlspecialchars($guest['room_type']) ?>"
                                data-rate="<?= $guest['room_rate'] ?>"
                                data-nights="<?= $guest['nights'] ?>"
                                data-total-due="<?= $guest['total_due'] ?>"
                                data-total-paid="<?= $guest['total_paid'] ?>"
                                data-balance="<?= $guest['balance'] ?>"
                                data-checkin="<?= $guest['checkin_date'] ?>"
                                data-checkout="<?= $guest['checkout_date'] ?>">
                            <?= htmlspecialchars($guest['fullname']) ?> - Room <?= htmlspecialchars($guest['room_name']) ?>
                            <?php if($guest['balance'] > 0): ?>
                                (Balance: TZS <?= number_format($guest['balance'], 2) ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="guest-info" id="guestInfo" style="display:none;">
                    <h5><i class="fa-solid fa-user-circle"></i> Guest Details</h5>
                    <div class="info-row">
                        <span>Room:</span>
                        <strong id="displayRoom">-</strong>
                    </div>
                    <div class="info-row">
                        <span>Room Type:</span>
                        <strong id="displayType">-</strong>
                    </div>
                    <div class="info-row">
                        <span>Nights:</span>
                        <strong id="displayNights">-</strong>
                    </div>
                    <div class="info-row">
                        <span>Total Due:</span>
                        <strong id="displayTotalDue">-</strong>
                    </div>
                    <div class="info-row">
                        <span>Already Paid:</span>
                        <strong id="displayTotalPaid">-</strong>
                    </div>
                    <div class="info-row" style="background: rgba(255,255,255,0.2); padding: 8px; border-radius: 5px; margin-top: 8px;">
                        <span>Balance:</span>
                        <strong id="displayBalance" style="font-size: 1.1rem;">-</strong>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Amount (TZS)<span class="required">*</span></label>
                    <input type="number" name="amount" id="amount" class="form-control" step="0.01" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Discount (TZS)</label>
                    <input type="number" name="discount" id="discount" class="form-control" step="0.01" value="0">
                </div>

                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <label class="form-label">Extra Charge Type</label>
                        <select name="extra_charge_type" id="extra_charge_type" class="form-control">
                            <option value="">None</option>
                            <option value="Food">Food (Chakula)</option>
                            <option value="Drinks">Drinks (Vinywaji)</option>
                            <option value="Laundry">Laundry (Nguo)</option>
                            <option value="Other">Other (Mengineyo)</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Amount (TZS)</label>
                        <input type="number" name="extra_charges" id="extra_charges" class="form-control" step="0.01" value="0">
                    </div>
                </div>
                <div class="calculation-box">
                    <div class="calc-row">
                        <span>Payment Amount:</span>
                        <strong id="calcRoom">TZS 0.00</strong>
                    </div>
                    <div class="calc-row">
                        <span>Extra Charges:</span>
                        <strong id="calcExtra">TZS 0.00</strong>
                    </div>
                    <div class="calc-row">
                        <span>Discount:</span>
                        <strong id="calcDiscount">- TZS 0.00</strong>
                    </div>
                    <div class="calc-row total">
                        <span>Net Payment:</span>
                        <strong id="calcTotal">TZS 0.00</strong>
                    </div>
                    <div class="calc-row balance">
                        <span>New Balance After Payment:</span>
                        <strong id="calcNewBalance">TZS 0.00</strong>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Method<span class="required">*</span></label>
                    <select name="payment_method" class="form-control" required>
                        <option value="">Select Method</option>
                        <option value="Cash">Cash</option>
                        <option value="Credit Card">Credit Card</option>
                        <option value="Mobile Payment">Mobile Payment</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Reference Number</label>
                    <input type="text" name="reference_number" class="form-control" placeholder="Transaction/Receipt number">
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Date<span class="required">*</span></label>
                    <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" placeholder="Additional notes..."></textarea>
                </div>

                <button type="submit" name="add_payment" class="btn-submit">
                    <i class="fa-solid fa-check-circle"></i> Record Payment
                </button>
            </form>
        </div>

        <div>
            <div class="form-card" style="margin-bottom: 25px;">
                <h4 class="section-title">
                    <i class="fa-solid fa-chart-pie"></i> Payment Summary
                </h4>
                <div class="calculation-box">
                    <div class="calc-row">
                        <span><i class="fa-solid fa-calendar-day"></i> Today's Payments:</span>
                        <strong>TZS <?= number_format($today_total, 2) ?></strong>
                    </div>
                    <div class="calc-row">
                        <span><i class="fa-solid fa-calendar-alt"></i> This Month:</span>
                        <strong>TZS <?= number_format($month_total, 2) ?></strong>
                    </div>
                    <div class="calc-row">
                        <span><i class="fa-solid fa-clock"></i> Guests with Balance:</span>
                        <strong><?= $pending_count ?></strong>
                    </div>
                </div>
            </div>

            <div class="form-card">
                <h4 class="section-title">
                    <i class="fa-solid fa-info-circle"></i> How Status Works
                </h4>
                <div style="padding: 15px; line-height: 1.8;">
                    <p style="margin-bottom: 10px;"><strong style="color: #28a745;">✓ Paid:</strong> Full amount paid</p>
                    <p style="margin-bottom: 10px;"><strong style="color: #ffc107;">◐ Partial:</strong> Some payment made</p>
                    <p style="margin-bottom: 10px;"><strong style="color: #dc3545;">✗ Pending:</strong> No payment yet</p>
                    <hr style="margin: 15px 0; border: none; border-top: 1px solid #e9ecef;">
                    <p style="font-size: 0.9rem; color: #6c757d;"><i class="fa-solid fa-lightbulb"></i> Status updates automatically based on total paid vs total due</p>
                </div>
            </div>
        </div>
    </div>

    <div class="filter-section">
        <h4 class="section-title">
            <i class="fa-solid fa-filter"></i> Filter Payment History
        </h4>
        <form method="GET" class="filter-grid">
            <div class="form-group">
                <label class="form-label">Search Guest/Room</label>
                <input type="text" name="search_guest" class="form-control" placeholder="Guest name or room..." value="<?= htmlspecialchars($search_guest) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Payment Date</label>
                <input type="date" name="filter_date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Payment Method</label>
                <select name="filter_method" class="form-control">
                    <option value="">All Methods</option>
                    <option value="Cash" <?= $filter_method == 'Cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="Credit Card" <?= $filter_method == 'Credit Card' ? 'selected' : '' ?>>Credit Card</option>
                    <option value="Mobile Payment" <?= $filter_method == 'Mobile Payment' ? 'selected' : '' ?>>Mobile Payment</option>
                </select>
            </div>
            <div class="form-group" style="display: flex; gap: 10px;">
                <button type="submit" class="btn-filter"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                <a href="payments.php" class="btn-clear"><i class="fa-solid fa-times"></i> Clear</a>
            </div>
        </form>
    </div>

    <div class="table-card">
        <h4 class="section-title">
            <i class="fa-solid fa-history"></i> Recent Payment History
        </h4>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Guest</th>
                        <th>Room</th>
                        <th class="text-right">This Payment</th>
                        <th class="text-right">Total Due</th>
                        <th class="text-right">Balance</th>
                        <th>Method</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($payment_history) > 0): ?>
                        <?php foreach ($payment_history as $payment): 
                            $badge_method_class = strtolower(str_replace(' ', '-', $payment['payment_method'])); 
                            $status_text = htmlspecialchars($payment['status']);
                            $status_class = strtolower($payment['status']);
                        ?>
                            <tr>
                                <td><?= $payment['payment_id'] ?></td>
                                <td><?= htmlspecialchars($payment['display_name']) ?></td>
                                <td><?= htmlspecialchars($payment['display_room']) ?></td>
                                <td class="text-right">
                                    <strong>TZS <?= number_format($payment['net_amount'], 2) ?></strong>
                                </td>
                                <td class="text-right">
                                    TZS <?= number_format($payment['total_due'], 2) ?>
                                </td>
                                <td class="text-right">
                                    <strong style="color: <?= $payment['balance'] > 0 ? '#dc3545' : '#28a745' ?>">
                                        TZS <?= number_format($payment['balance'], 2) ?>
                                    </strong>
                                </td>
                                <td><span class="badge badge-<?= $badge_method_class ?>"><?= htmlspecialchars($payment['payment_method']) ?></span></td>
                                <td><?= date('d M Y', strtotime($payment['payment_date'])) ?></td>
                                <td><span class="badge badge-<?= $status_class ?>"><?= $status_text ?></span></td>
                                <td class="text-center">
                                    <button class="btn-action btn-receipt" onclick="printReceipt(<?= $payment['payment_id'] ?>)">
                                        <i class="fa-solid fa-print"></i> Receipt
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="no-data">No payment history found based on the current filters.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const guestSelect = document.getElementById('guest_id');
    const amountInput = document.getElementById('amount');
    const discountInput = document.getElementById('discount');
    const extraChargesInput = document.getElementById('extra_charges');
    const guestInfoDiv = document.getElementById('guestInfo');
    
    let currentBalance = 0;
    
    function formatCurrency(number) {
        return `TZS ${parseFloat(number).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",")}`;
    }

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
        
        // Color code the new balance
        const balanceElement = document.getElementById('calcNewBalance');
        if (newBalance <= 0) {
            balanceElement.style.color = '#28a745'; // Green - fully paid
        } else if (newBalance < currentBalance) {
            balanceElement.style.color = '#ffc107'; // Yellow - partial
        } else {
            balanceElement.style.color = '#dc3545'; // Red - pending
        }
    }
    
    function updateGuestInfo() {
        const selectedOption = guestSelect.options[guestSelect.selectedIndex];
        
        if (selectedOption.value) {
            const room = selectedOption.getAttribute('data-room');
            const type = selectedOption.getAttribute('data-type');
            const nights = selectedOption.getAttribute('data-nights');
            const totalDue = parseFloat(selectedOption.getAttribute('data-total-due'));
            const totalPaid = parseFloat(selectedOption.getAttribute('data-total-paid'));
            const balance = parseFloat(selectedOption.getAttribute('data-balance'));
            
            currentBalance = balance;
            
            document.getElementById('displayRoom').textContent = room;
            document.getElementById('displayType').textContent = type;
            document.getElementById('displayNights').textContent = nights;
            document.getElementById('displayTotalDue').textContent = formatCurrency(totalDue);
            document.getElementById('displayTotalPaid').textContent = formatCurrency(totalPaid);
            document.getElementById('displayBalance').textContent = formatCurrency(balance);
            
            // Set suggested payment amount to remaining balance
            amountInput.value = balance > 0 ? balance.toFixed(2) : '0.00';
            
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
        printWindow.onload = function() {
            printWindow.print();
        };
    }

    guestSelect.addEventListener('change', updateGuestInfo);
    amountInput.addEventListener('input', updateCalculation);
    discountInput.addEventListener('input', updateCalculation);
    extraChargesInput.addEventListener('input', updateCalculation);

    updateCalculation();
    if (guestSelect.value) {
        updateGuestInfo();
    }
});
</script>

</body>
</html>