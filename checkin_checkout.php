<?php
session_start();
include 'db_connect.php'; 

// Security: only receptionist
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

$currentPage = basename($_SERVER['PHP_SELF']);

// =======================
// HANDLE AJAX REQUESTS
// =======================
if (isset($_POST['action'])) {
    $response = [];
    
    function safe_json_number($n) {
        return number_format(floatval($n), 0, '', ''); 
    }

    $guest_id = intval($_POST['guest_id'] ?? 0);
    $guest_id_safe = mysqli_real_escape_string($conn, $guest_id);
    
    if ($guest_id === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Guest ID.']);
        exit();
    }

    // ADD DAY or REDUCE DAY
    if ($_POST['action'] == 'add_day' || $_POST['action'] == 'reduce_day') {
        $action = $_POST['action'];
        
        // Nimeongeza g.first_name na g.last_name ili tujue tunamfanyia nani
        $q = mysqli_query($conn, "
            SELECT g.room_rate, g.first_name, g.last_name, c.days_stayed, c.checkin_time 
            FROM checkin_checkout c 
            JOIN guest g ON g.guest_id = c.guest_id 
            WHERE c.guest_id='$guest_id_safe'
        ");

        if ($q && mysqli_num_rows($q) > 0) {
            $row = mysqli_fetch_assoc($q);
            $rate = floatval($row['room_rate']);
            $current_days = intval($row['days_stayed']);
            $checkin_time = $row['checkin_time'];
            $guestName = $row['first_name'] . ' ' . $row['last_name'];

            $new_days = $current_days;

            if ($action == 'add_day') {
                $new_days = $current_days + 1;
                $log_action = "Extend Stay";
                $log_desc = "Added 1 day for guest: $guestName. Total days: $new_days";
            } elseif ($action == 'reduce_day') {
                if ($current_days > 1) {
                    $new_days = $current_days - 1;
                    $log_action = "Reduce Stay";
                    $log_desc = "Reduced 1 day for guest: $guestName. Total days: $new_days";
                } else {
                    $response = ['status' => 'error', 'message' => 'Cannot reduce stay below 1 day.'];
                    echo json_encode($response);
                    exit();
                }
            }
            
            $new_total = $new_days * $rate;
            // Calculate new checkout date based on checkin time + new days
            $new_checkout_date = date('Y-m-d', strtotime("+$new_days days", strtotime($checkin_time)));

            mysqli_query($conn, "UPDATE checkin_checkout SET days_stayed='$new_days', total_amount='$new_total' WHERE guest_id='$guest_id_safe'");
            mysqli_query($conn, "UPDATE guest SET checkout_date='$new_checkout_date' WHERE guest_id='$guest_id_safe'");
            
            // --- LOGGING ---
            logActivity($conn, $log_action, $log_desc);
            
            // Calculate new balance for response
            $paid_q = mysqli_query($conn, "SELECT SUM(amount + extra_charges - discount) as paid FROM payments WHERE guest_id='$guest_id_safe'");
            $paid_row = mysqli_fetch_assoc($paid_q);
            $total_paid = floatval($paid_row['paid'] ?? 0);
            $new_balance = $new_total - $total_paid;

            $response = [
                'status' => 'success', 
                'days' => $new_days, 
                'rate' => safe_json_number($rate),
                'total' => safe_json_number($new_total),
                'balance' => safe_json_number($new_balance),
                'checkout_date' => date('d/M/Y', strtotime($new_checkout_date)) // New date sent back
            ];
            
        } else {
            $response = ['status' => 'error', 'message' => 'Guest record not found.'];
        }
    }

    // CHECKOUT - STRICT: BLOCKS IF NOT FULLY PAID
    if ($_POST['action'] == 'checkout') {
        
        // 1. Fetch Guest & Cost Details
        $q = mysqli_query($conn, "
            SELECT c.*, g.first_name, g.last_name, g.room_name AS room_name_g, g.room_type AS room_type_g 
            FROM checkin_checkout c 
            LEFT JOIN guest g ON g.guest_id = c.guest_id
            WHERE c.guest_id='$guest_id_safe'
        ");

        if ($q && mysqli_num_rows($q) > 0) {
            $row = mysqli_fetch_assoc($q);
            
            $room_name = mysqli_real_escape_string($conn, $row['room_name_g'] ?? $row['room_name']);
            $total_bill_amount = floatval($row['total_amount']);
            $guestName = $row['first_name'] . ' ' . $row['last_name'];
            
            // 2. Calculate What Has Already Been Paid
            $paid_query = mysqli_query($conn, "SELECT SUM(amount + extra_charges - discount) as total_paid FROM payments WHERE guest_id='$guest_id_safe'");
            $paid_row = mysqli_fetch_assoc($paid_query);
            $already_paid = floatval($paid_row['total_paid'] ?? 0);

            // 3. STRICT CHECK: Is there a balance?
            $balance_due = $total_bill_amount - $already_paid;

            // Allow a tiny margin of error for float calculation (e.g., 0.01)
            if ($balance_due > 1) {
                // STOP! Debt found.
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'DENIED: Guest has an outstanding balance of TZS ' . number_format($balance_due) . '. Payment must be cleared in Payments page first.'
                ]);
                exit();
            }

            // 4. Proceed - No new payment record needed because it must be paid already
            
            // Update room status to Available
            mysqli_query($conn, "UPDATE rooms SET status='Available' WHERE room_name='$room_name'");
            
            // DELETE from checkin_checkout
            mysqli_query($conn, "DELETE FROM checkin_checkout WHERE guest_id='$guest_id_safe'");

            // DELETE from guest table
            mysqli_query($conn, "DELETE FROM guest WHERE guest_id='$guest_id_safe'");

            // --- LOGGING ---
            // Hapa tunarekodi kwamba ametoka salama
            logActivity($conn, "Check-Out", "Checked out guest: $guestName from Room: $room_name. Bill Cleared: " . number_format($total_bill_amount));

            $response = [
                'status' => 'success', 
                'message' => 'Guest checked out successfully. Room is now Available.'
            ];
            
        } else {
            $response = ['status' => 'error', 'message' => 'Check-in record not found.'];
        }
    }
    
    // DELETE BUTTON (HAPA NDO MSINGI WA USHAHIDI)
    if ($_POST['action'] == 'delete') {
        // Tunachukua majina KABLA ya kufuta
        $q = mysqli_query($conn, "SELECT first_name, last_name, room_name, phone FROM guest WHERE guest_id='$guest_id_safe'");
        
        if ($q && mysqli_num_rows($q) > 0) {
            $row = mysqli_fetch_assoc($q);
            $room_name = mysqli_real_escape_string($conn, $row['room_name']);
            $guestName = $row['first_name'] . ' ' . $row['last_name'];
            $phone = $row['phone'];
            
            // Futa sasa
            mysqli_query($conn, "UPDATE rooms SET status='Available' WHERE room_name='$room_name'");
            mysqli_query($conn, "DELETE FROM checkin_checkout WHERE guest_id='$guest_id_safe'");
            mysqli_query($conn, "DELETE FROM guest WHERE guest_id='$guest_id_safe'");
            
            // --- LOGGING (USHAHIDI USIOFUTIKA) ---
            // Hii itatokea kwa Manager hata kama Receptionist amefuta kila kitu
            logActivity($conn, "DELETE GUEST", "⚠️ PERMANENT DELETE: Guest $guestName (Room: $room_name, Phone: $phone) was deleted manually by receptionist.");
            
            $response = [
                'status' => 'success', 
                'message' => 'Guest and check-in records permanently deleted. Room is now Available.'
            ];
        } else {
            $response = ['status' => 'error', 'message' => 'Guest record not found for deletion.'];
        }
    }

    echo json_encode($response);
    exit();
}

// =======================
// FETCH DATA FOR TABLE
// =======================
$query = mysqli_query($conn, "
    SELECT 
        g.guest_id, 
        CONCAT(g.first_name, ' ', g.last_name) AS full_name,
        g.room_name, 
        g.room_type, 
        g.room_rate,
        c.days_stayed,
        c.total_amount,
        c.status,
        c.checkin_time,
        DATE_ADD(c.checkin_time, INTERVAL c.days_stayed DAY) AS expected_checkout_date,
        (SELECT SUM(amount + extra_charges - discount) FROM payments WHERE guest_id = g.guest_id) as total_paid
    FROM 
        checkin_checkout c
    JOIN 
        guest g ON g.guest_id = c.guest_id
    WHERE 
        c.status='Checked In'
    ORDER BY 
        c.checkin_time ASC
");

if (!$query) {
    die("Query failed: " . mysqli_error($conn));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Check In / Check Out - Receptionist</title>
<meta name="viewport" content="width=device-width, initial-width=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; }

/* Sidebar Styling */
.sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: #1e3a5f; color: #fff; padding: 30px 0; display: flex; flex-direction: column; box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1); z-index: 1000; }
.sidebar-header { padding: 0 25px 30px 25px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); margin-bottom: 20px; text-align: center; }
.sidebar-header h2 { font-weight: 600; font-size: 1.2rem; color: #fff; margin-bottom: 5px; }
.sidebar-header p { font-size: 0.85rem; color: rgba(255, 255, 255, 0.7); }
.sidebar-nav { flex: 1; padding: 0 15px; overflow-y: auto; }
.sidebar a { text-decoration: none; color: rgba(255, 255, 255, 0.85); display: flex; align-items: center; padding: 14px 18px; margin: 5px 0; border-radius: 10px; transition: all 0.3s ease; font-weight: 500; font-size: 0.95rem; }
.sidebar a i { margin-right: 14px; font-size: 1.1rem; width: 20px; text-align: center; }
.sidebar a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; transform: translateX(5px); }
.sidebar a.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
.logout-section { padding: 0 15px 20px 15px; border-top: 1px solid rgba(255, 255, 255, 0.1); margin-top: 20px; padding-top: 20px; }
.logout-btn { width: 100%; padding: 12px 18px; background: #dc3545; color: #fff; border: none; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; font-weight: 600; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; }
.logout-btn i { margin-right: 10px; }
.logout-btn:hover { background: #c82333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3); }

/* Main Content */
.main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; }
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: #fff; padding: 25px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); }
.header h2 { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; }

/* Table Card Styling */
.table-container { background: #fff; border-radius: 15px; padding: 30px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); overflow-x: auto; }
table { width: 100%; border-collapse: collapse; border: none; }
table th { background: #1e3a5f; color: #fff; text-align: left; padding: 15px; font-weight: 600; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }
table td { padding: 15px; text-align: left; border-bottom: 1px solid #e9ecef; vertical-align: middle; color: #2c3e50; }
table tbody tr:hover { background: #f8f9fa; }

/* Action Buttons */
.action-btn { padding: 8px 10px; border-radius: 8px; color: #fff; border: none; cursor: pointer; font-weight: 600; font-size: 0.85rem; transition: all 0.3s ease; margin: 3px; display: inline-flex; align-items: center; gap: 5px; }
.add-day { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
.reduce-day { background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); }
.checkout { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); }
.delete-btn { background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); color: #2c3e50; }
.action-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.2); }

/* Status Badges */
.status { padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; display: inline-block; }
.checked-in { background: #28a745; color: #fff; }

/* Balance Indicators */
.balance-ok { color: #28a745; font-weight: bold; }
.balance-due { color: #dc3545; font-weight: bold; }

/* Responsive */
@media (max-width: 768px) {
    .sidebar { width: 70px; }
    .sidebar-header h2, .sidebar-header p, .sidebar a span { display: none; }
    .sidebar a { justify-content: center; padding: 14px; }
    .sidebar a i { margin: 0; }
    .main-content { margin-left: 70px; padding: 20px; }
    table { font-size: 0.75rem; }
    table th, table td { padding: 8px; }
    td:last-child { flex-direction: column; align-items: stretch; display: flex;}
    .action-btn { margin: 2px 0; justify-content: center; }
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
        <a href="receptionist_dashboard.php" class="<?= $currentPage=='receptionist_dashboard.php'?'active':'' ?>"><i class="fa-solid fa-house"></i> <span>Dashboard</span></a>
        <a href="add_guest.php" class="<?= $currentPage=='add_guest.php'?'active':'' ?>"><i class="fa-solid fa-user-plus"></i> <span>Add Guest</span></a>
        <a href="view_guests.php" class="<?= $currentPage=='view_guests.php'?'active':'' ?>"><i class="fa-solid fa-users"></i> <span>View Guests</span></a>
        <a href="rooms.php" class="<?= $currentPage=='rooms.php'?'active':'' ?>"><i class="fa-solid fa-bed"></i> <span>Rooms </span></a>
        <a href="checkin_checkout.php" class="<?= $currentPage=='checkin_checkout.php'?'active':'' ?>"><i class="fa-solid fa-door-open"></i> <span>Check In / Check Out</span></a>
        <a href="payments.php" class="<?= $currentPage=='payments.php'?'active':'' ?>"><i class="fa-solid fa-credit-card"></i> <span>Payments</span></a>
        <a href="messages.php" class="<?= $currentPage=='messages.php'?'active':'' ?>"><i class="fa-solid fa-bell"></i> <span>Messages</span></a>
    </div>
    <div class="logout-section">
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span></button>
        </form>
    </div>
</div>

<div class="main-content">
    <div class="header">
        <h2><i class="fa-solid fa-door-open"></i> Guests Check In / Check Out</h2>
    </div>
    
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Guest Name</th>
                    <th>Room Name</th>
                    <th>Room Type</th>
                    <th>Check In Date</th> 
                    <th style="text-align: right;">Days</th>
                    <th>Out Date</th>
                    <th style="text-align: right;">Total Bill</th>
                    <th style="text-align: right;">Balance</th>
                    <th>Status</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($query && mysqli_num_rows($query) > 0):
                    while($row = mysqli_fetch_assoc($query)): 
                        $rate_display = number_format($row['room_rate'], 0);
                        $total_bill = floatval($row['total_amount']);
                        $total_paid = floatval($row['total_paid'] ?? 0);
                        $balance = $total_bill - $total_paid;
                        
                        $total_display = number_format($total_bill, 0);
                        $balance_display = number_format($balance, 0);
                        $balance_class = ($balance > 0) ? "balance-due" : "balance-ok";

                        $checkin_date_display = date('d/M/Y H:i', strtotime($row['checkin_time'])); 
                        $checkout_date_display = date('d/M/Y', strtotime($row['expected_checkout_date']));
                        ?>
                <tr id="row_<?= $row['guest_id'] ?>">
                    <td><strong><?= htmlspecialchars($row['full_name']); ?></strong></td>
                    <td class="room_name_cell"><?= htmlspecialchars($row['room_name']); ?></td>
                    <td><?= htmlspecialchars($row['room_type']); ?></td>
                    <td><?= $checkin_date_display; ?></td> 
                    <td class="days" style="text-align: right; font-weight: 600;"><?= $row['days_stayed']; ?></td>
                    <td class="checkout_date_cell"><?= $checkout_date_display; ?></td> 
                    <td class="total" style="text-align: right; font-weight: 700; color: #1e3a5f;"><?= $total_display; ?></td> 
                    <td class="balance" style="text-align: right;">
                        <span class="<?= $balance_class ?>">TZS <?= $balance_display; ?></span>
                    </td> 
                    <td><span class="status checked-in"><?= $row['status']; ?></span></td>
                    <td style="text-align: center;">
                        <button class="action-btn add-day" data-id="<?= $row['guest_id']; ?>"><i class="fa-solid fa-plus"></i></button>
                        <button class="action-btn reduce-day" data-id="<?= $row['guest_id']; ?>"><i class="fa-solid fa-minus"></i></button>
                        <button class="action-btn checkout" 
                                data-id="<?= $row['guest_id']; ?>" 
                                data-balance="<?= $balance ?>">
                                <i class="fa-solid fa-sign-out-alt"></i> Out
                        </button>
                        <button class="action-btn delete-btn" data-id="<?= $row['guest_id']; ?>"><i class="fa-solid fa-trash-alt"></i></button>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="10" style="text-align: center; color: #7f8c8d; font-style: italic;">No guests currently checked in.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
$(document).ready(function(){
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function updateDay(id, action, buttonText) {
        let row = $("#row_" + id);
        Swal.fire({
            title: buttonText + '...',
            text: 'Please wait...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading() }
        });

        $.post("checkin_checkout.php", { action: action, guest_id: id }, function(res){
            Swal.close(); 
            try {
                let data = JSON.parse(res);
                if(data.status === "success"){
                    row.find(".days").text(data.days);
                    row.find(".rate").text(formatNumber(data.rate)); 
                    row.find(".total").text(formatNumber(data.total));
                    
                    row.find(".checkout_date_cell").text(data.checkout_date); 
                    
                    // Update balance text and color
                    let balanceAmount = data.balance;
                    let balanceSpan = row.find(".balance span");
                    balanceSpan.text("TZS " + formatNumber(balanceAmount));
                    
                    if(parseFloat(balanceAmount) > 0) {
                        balanceSpan.removeClass("balance-ok").addClass("balance-due");
                    } else {
                        balanceSpan.removeClass("balance-due").addClass("balance-ok");
                    }

                    // Update button data
                    row.find(".checkout").data("balance", data.balance);

                    Swal.fire("Success", "Stay updated! New days: " + data.days, "success");
                } else {
                    Swal.fire("Error", data.message, "error");
                }
            } catch (e) {
                Swal.fire("Error", "Unexpected response.", "error");
            }
        });
    }

    $(".add-day").click(function(){ updateDay($(this).data("id"), "add_day", "Extending Stay"); });
    $(".reduce-day").click(function(){ updateDay($(this).data("id"), "reduce_day", "Reducing Stay"); });

    $(".checkout").click(function(){
        let id = $(this).data("id");
        let balance = parseFloat($(this).data("balance"));
        
        // STRICT JS CHECK
        if(balance > 1) { // Tolerance of 1 TZS
            Swal.fire({
                icon: 'error',
                title: 'Cannot Check Out!',
                html: `<p>This guest has an outstanding balance of <br><strong>TZS ${formatNumber(balance)}</strong></p>
                        <p>Please go to the <strong>Payments</strong> page to clear this bill before checking out.</p>`,
                footer: '<a href="payments.php">Go to Payments Page</a>'
            });
            return; // STOP EXECUTION
        }

        // If balance is OK (<= 0), proceed with confirmation
        Swal.fire({
            title: "Confirm Check-Out?",
            text: "Guest is fully paid. Proceed with checkout?",
            icon: "success",
            showCancelButton: true,
            confirmButtonColor: "#28a745", 
            cancelButtonColor: "#6c757d",
            confirmButtonText: "Yes, Check Out",
        }).then((result) => {
            if(result.isConfirmed){
                Swal.fire({
                    title: 'Processing...',
                    text: 'Closing record.',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading() }
                });
                
                $.post("checkin_checkout.php", { 
                    action: "checkout", 
                    guest_id: id
                }, function(res){
                    Swal.close(); 
                    try {
                        let data = JSON.parse(res);
                        if(data.status === "success"){
                            $("#row_" + id).fadeOut(500, function(){ $(this).remove(); });
                            Swal.fire("Success!", data.message, "success");
                        } else {
                            Swal.fire("Error!", data.message, "error");
                        }
                    } catch (e) {
                        Swal.fire("Error", "Unexpected response.", "error");
                    }
                });
            }
        });
    });

    $(".delete-btn").click(function(){
        let id = $(this).data("id");
        let room_name = $("#row_" + id).find(".room_name_cell").text();
        Swal.fire({
            title: "CONFIRM DELETION?",
            html: `<p style="color:red;">Permanently delete guest (ID: ${id})?</p><p>Room <strong>${room_name}</strong> will become Available.</p>`,
            icon: "error",
            showCancelButton: true,
            confirmButtonColor: "#d33", 
            confirmButtonText: "Yes, Delete",
        }).then((result) => {
            if(result.isConfirmed){
                $.post("checkin_checkout.php", { action: "delete", guest_id: id }, function(res){
                    try {
                        let data = JSON.parse(res);
                        if(data.status === "success"){
                            $("#row_" + id).fadeOut(500, function(){ $(this).remove(); });
                            Swal.fire("Deleted!", data.message, "success");
                        } else { Swal.fire("Error!", data.message, "error"); }
                    } catch (e) { Swal.fire("Error", "Unexpected response.", "error"); }
                });
            }
        });
    });
});
</script>

</body>
</html>