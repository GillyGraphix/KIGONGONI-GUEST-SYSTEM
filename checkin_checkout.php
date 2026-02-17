<?php
ob_start(); 
session_start();
include 'db_connect.php'; 

// Security: only receptionist
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

$currentPage = basename($_SERVER['PHP_SELF']);

// =======================
// HANDLE AJAX REQUESTS
// =======================
if (isset($_POST['action'])) {
    ob_clean(); 
    header('Content-Type: application/json'); 

    $response = [];
    function safe_json_number($n) { return number_format(floatval($n), 0, '', ''); }

    $guest_id = intval($_POST['guest_id'] ?? 0);
    $guest_id_safe = mysqli_real_escape_string($conn, $guest_id);
    
    if ($guest_id === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Guest ID.']);
        exit();
    }

    // ADD / REDUCE DAY
    if ($_POST['action'] == 'add_day' || $_POST['action'] == 'reduce_day') {
        $action = $_POST['action'];
        $q = mysqli_query($conn, "SELECT g.room_rate, c.days_stayed, c.checkin_time FROM checkin_checkout c JOIN guest g ON g.guest_id = c.guest_id WHERE c.guest_id='$guest_id_safe'");

        if ($q && mysqli_num_rows($q) > 0) {
            $row = mysqli_fetch_assoc($q);
            $rate = floatval($row['room_rate']);
            $current_days = intval($row['days_stayed']);
            $checkin_time = $row['checkin_time'];
            
            // SMART LOGIC: Kuzuia chini ya siku 1
            if ($action == 'reduce_day' && $current_days <= 1) {
                echo json_encode(['status' => 'warning', 'message' => 'Cannot reduce stay duration to less than 1 day.']);
                exit();
            }

            $new_days = ($action == 'add_day') ? $current_days + 1 : $current_days - 1;
            $new_total = $new_days * $rate;
            $new_checkout_date = date('Y-m-d', strtotime("+$new_days days", strtotime($checkin_time)));

            mysqli_query($conn, "UPDATE checkin_checkout SET days_stayed='$new_days', total_amount='$new_total' WHERE guest_id='$guest_id_safe'");
            mysqli_query($conn, "UPDATE guest SET checkout_date='$new_checkout_date' WHERE guest_id='$guest_id_safe'");
            
            $paid_q = mysqli_query($conn, "SELECT SUM(amount + extra_charges - discount) as paid FROM payments WHERE guest_id='$guest_id_safe'");
            $paid_row = mysqli_fetch_assoc($paid_q);
            $new_balance = $new_total - floatval($paid_row['paid'] ?? 0);

            $response = [
                'status' => 'success', 'days' => $new_days, 'total' => safe_json_number($new_total),
                'balance' => safe_json_number($new_balance), 'checkout_date' => date('d/M/Y', strtotime($new_checkout_date)) 
            ];
        }
    }

    if ($_POST['action'] == 'checkout') {
        $q = mysqli_query($conn, "SELECT room_name FROM guest WHERE guest_id='$guest_id_safe'");
        if ($row = mysqli_fetch_assoc($q)) {
            $room_name = $row['room_name'];
            mysqli_query($conn, "UPDATE rooms SET status='Available' WHERE room_name='$room_name'");
            mysqli_query($conn, "UPDATE guest SET status='Checked-out' WHERE guest_id='$guest_id_safe'");
            mysqli_query($conn, "UPDATE checkin_checkout SET status='Checked Out' WHERE guest_id='$guest_id_safe'");
            $response = ['status' => 'success', 'message' => 'Guest successfully checked out.'];
        }
    }
    
    if ($_POST['action'] == 'delete') {
        $q = mysqli_query($conn, "SELECT room_name FROM guest WHERE guest_id='$guest_id_safe'");
        if ($row = mysqli_fetch_assoc($q)) {
            mysqli_query($conn, "UPDATE rooms SET status='Available' WHERE room_name='{$row['room_name']}'");
            mysqli_query($conn, "DELETE FROM checkin_checkout WHERE guest_id='$guest_id_safe'");
            mysqli_query($conn, "DELETE FROM guest WHERE guest_id='$guest_id_safe'");
            $response = ['status' => 'success', 'message' => 'Record deleted.'];
        }
    }
    echo json_encode($response);
    exit();
}
ob_end_flush(); 

$query = mysqli_query($conn, "SELECT g.guest_id, CONCAT(g.first_name, ' ', g.last_name) AS full_name, g.room_name, g.room_type, c.days_stayed, c.total_amount, c.status, c.checkin_time, DATE_ADD(c.checkin_time, INTERVAL c.days_stayed DAY) AS expected_checkout_date, (SELECT SUM(amount + extra_charges - discount) FROM payments WHERE guest_id = g.guest_id) as total_paid FROM checkin_checkout c JOIN guest g ON g.guest_id = c.guest_id WHERE c.status='Checked In' ORDER BY c.checkin_time ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Check-in / Check-out - Hotel Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

    /* Table Container */
    .table-container { background: #fff; border-radius: 15px; padding: 0; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); overflow: hidden; border: 1px solid #e9ecef; }
    .table-responsive { overflow-x: auto; max-height: 75vh; overflow-y: auto; }
    
    table { width: 100%; border-collapse: collapse; min-width: 900px; }
    table th { background: #1e3a5f; color: #fff; text-align: left; padding: 18px 20px; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
    table td { padding: 15px 20px; border-bottom: 1px solid #e9ecef; color: #2c3e50; vertical-align: middle; white-space: nowrap; font-size: 0.9rem; }
    table tbody tr:hover { background: #f8f9fa; }

    /* Custom Logic Styles */
    .action-group { display: flex; gap: 8px; justify-content: center; }
    .action-btn { width: 32px; height: 32px; border-radius: 8px; color: #fff; border: none; cursor: pointer; transition: 0.3s; display: inline-flex; align-items: center; justify-content: center; font-size: 0.9rem; }
    .action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
    
    .add-day { background: #28a745; }
    .reduce-day { background: #17a2b8; }
    .checkout { background: #667eea; width: auto; padding: 0 12px; font-weight: 600; font-size: 0.8rem; gap: 5px; }
    .delete-btn { background: #dc3545; }

    .balance-due { color: #dc3545; font-weight: 700; background: #f8d7da; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; }
    .balance-ok { color: #28a745; font-weight: 700; background: #d4edda; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; }
    
    .room-badge { background: #e3f2fd; color: #1e3a5f; padding: 5px 10px; border-radius: 15px; font-weight: 600; font-size: 0.85rem; }

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
            <span><i class="fa-solid fa-door-open"></i> Check-in / Check-out</span>
        </div>
    </div>
    
    <div class="table-container">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Guest Name</th>
                        <th>Room</th>
                        <th>Type</th>
                        <th>In Date</th>
                        <th style="text-align: center;">Days</th>
                        <th>Expected Out</th>
                        <th style="text-align: right;">Total Bill</th>
                        <th style="text-align: right;">Balance</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($query)): 
                        $total_bill = floatval($row['total_amount']);
                        $balance = $total_bill - floatval($row['total_paid'] ?? 0); ?>
                    <tr id="row_<?= $row['guest_id'] ?>">
                        <td><strong><?= htmlspecialchars($row['full_name']); ?></strong></td>
                        <td class="room_name_cell"><span class="room-badge"><?= htmlspecialchars($row['room_name']); ?></span></td>
                        <td><small><?= htmlspecialchars($row['room_type']); ?></small></td>
                        <td><?= date('d/M H:i', strtotime($row['checkin_time'])); ?></td>
                        <td class="days_cell" style="text-align: center; font-weight: 700;"><?= $row['days_stayed']; ?></td>
                        <td class="checkout_date_cell"><?= date('d/M/Y', strtotime($row['expected_checkout_date'])); ?></td>
                        <td class="total_cell" style="text-align: right; font-weight:700; color:#1e3a5f;"><?= number_format($total_bill); ?></td>
                        <td class="balance_cell" style="text-align: right;"><span class="<?= $balance > 0 ? 'balance-due' : 'balance-ok' ?>">TZS <?= number_format($balance); ?></span></td>
                        <td style="text-align: center;">
                            <div class="action-group">
                                <button class="action-btn add-day" data-id="<?= $row['guest_id']; ?>" title="Add Day"><i class="fa-solid fa-plus"></i></button>
                                <button class="action-btn reduce-day" data-id="<?= $row['guest_id']; ?>" title="Reduce Day"><i class="fa-solid fa-minus"></i></button>
                                <button class="action-btn checkout" data-id="<?= $row['guest_id']; ?>" data-balance="<?= $balance ?>" title="Check Out"><i class="fa-solid fa-sign-out-alt"></i> Out</button>
                                <button class="action-btn delete-btn" data-id="<?= $row['guest_id']; ?>" title="Delete Record"><i class="fa-solid fa-trash-alt"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
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

    $(document).ready(function(){
        const formatNumber = (num) => num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");

        function updateDay(id, action, buttonText) {
            Swal.fire({ title: buttonText + '...', didOpen: () => { Swal.showLoading() } });
            $.post("checkin_checkout.php", { action: action, guest_id: id }, function(res){
                Swal.close(); 
                try {
                    let data = typeof res === "object" ? res : JSON.parse(res);
                    
                    if(data.status === "warning"){
                        Swal.fire({ icon: 'warning', title: 'Limit Reached!', text: data.message, confirmButtonColor: '#1e3a5f' });
                        return;
                    }

                    if(data.status === "success"){
                        let row = $("#row_" + id);
                        row.find(".days_cell").text(data.days);
                        row.find(".total_cell").text(formatNumber(data.total));
                        row.find(".checkout_date_cell").text(data.checkout_date); 
                        row.find(".balance_cell span").text("TZS " + formatNumber(data.balance)).attr('class', data.balance > 0 ? 'balance-due' : 'balance-ok');
                        row.find(".checkout").data("balance", data.balance);
                        Swal.fire({ icon: 'success', title: 'Updated!', timer: 1000, showConfirmButton: false });
                    }
                } catch (e) { Swal.fire("Error", "System error.", "error"); }
            });
        }

        $(".add-day").click(function(){ updateDay($(this).data("id"), "add_day", "Extending"); });
        $(".reduce-day").click(function(){ updateDay($(this).data("id"), "reduce_day", "Reducing"); });

        $(".checkout").click(function(){
            let id = $(this).data("id");
            let balance = parseFloat($(this).data("balance"));
            if(balance > 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Cannot Check Out!',
                    html: `<p>This guest has an outstanding balance of <br><strong style="font-size:1.4rem; color:#dc3545;">TZS ${formatNumber(balance)}</strong></p>
                           <p style="margin-top:10px;">Please go to the <strong>Payments</strong> page to clear this bill before checking out.</p>`,
                    showCancelButton: true,
                    confirmButtonColor: '#1e3a5f',
                    confirmButtonText: 'OK',
                    cancelButtonColor: '#28a745',
                    cancelButtonText: 'Go to Payments Page'
                }).then((result) => {
                    if (result.dismiss === Swal.DismissReason.cancel) { window.location.href = 'payments.php'; }
                });
                return; 
            }
            Swal.fire({ title: "Confirm Check-Out?", text: "Guest record will be archived.", icon: "success", showCancelButton: true, confirmButtonColor: "#28a745", confirmButtonText: "Yes, Check Out" }).then((result) => {
                if(result.isConfirmed){
                    $.post("checkin_checkout.php", { action: "checkout", guest_id: id }, function(res){
                        $("#row_" + id).fadeOut(500, function(){ $(this).remove(); });
                    });
                }
            });
        });

        $(".delete-btn").click(function(){
            let id = $(this).data("id");
            let room_name = $("#row_" + id).find(".room_name_cell").text();
            Swal.fire({
                title: "CONFIRM DELETION?",
                html: `<p>Permanently delete guest records?</p>
                       <p style="margin-top:10px; font-weight:600;">Room <span style="color:#dc3545;">${room_name}</span> will become Available.</p>`,
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#dc3545", 
                confirmButtonText: "Yes, Delete",
                cancelButtonColor: "#6c757d"
            }).then((result) => {
                if(result.isConfirmed){
                    $.post("checkin_checkout.php", { action: "delete", guest_id: id }, function(res){
                        $("#row_" + id).fadeOut(500, function(){ $(this).remove(); });
                    });
                }
            });
        });
    });
</script>
</body>
</html>