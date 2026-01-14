<?php
session_start();
include 'db_connect.php';

// Security: only receptionist
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'single'; 

// Initialize variables
$first_name = $last_name = '';
$gender = $resident_status = '';
$phone = $email = $address = $city = $country = '';
$passport_id = $passport_country = $passport_expiry = '';
$company_name = $company_address = '';
$room_name = $room_type = $room_rate = '';
$checkin_date = date('Y-m-d');
$checkin_time = date('H:i');
$checkout_date = '';
$checkout_time = '10:00';
$car_available = $car_plate = '';
$success = $error = '';
$auto_checkin = false;
$confirm_details = false;

// Variable mpya kwa ajili ya SweetAlert
$swal_json = ''; 

// Fetch available rooms
$available_rooms = [];
$room_details_map = []; 
$room_stmt = $conn->prepare("SELECT room_name, room_type, room_rate FROM rooms WHERE status='Available'");
$room_stmt->execute();
$room_result = $room_stmt->get_result();
while ($row = $room_result->fetch_assoc()) {
    $available_rooms[] = $row;
    $room_details_map[$row['room_name']] = ['type' => $row['room_type'], 'rate' => $row['room_rate']];
}
$room_stmt->close();

$currentPage = basename($_SERVER['PHP_SELF']);

// HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_mode = $_POST['form_mode'] ?? 'single';

    // --- A. GROUP CHECK-IN ---
    if ($form_mode === 'group') {
        $booking_type = 'group'; 
        
        $company_name = $_POST['company_name'] ?? '';
        $company_address = $_POST['company_address'] ?? '';
        $first_name = $_POST['first_name'] ?? ''; 
        $last_name = $_POST['last_name'] ?? '';   
        $phone = $_POST['phone'] ?? '';
        $email = $_POST['email'] ?? '';
        $city = $_POST['city'] ?? '';
        $country = $_POST['country'] ?? '';
        $checkin_date = $_POST['checkin_date'] ?? '';
        $checkin_time = $_POST['checkin_time'] ?? '';
        $checkout_date = $_POST['checkout_date'] ?? '';
        $checkout_time = $_POST['checkout_time'] ?? '';
        $car_available = isset($_POST['car_available']) ? 'Yes' : 'No';
        $car_plates_array = $_POST['car_plates'] ?? [];
        $car_plates_clean = array_filter($car_plates_array, function($value) { return !empty(trim($value)); });
        $car_plate = !empty($car_plates_clean) ? implode(', ', $car_plates_clean) : '';
        $selected_rooms = $_POST['selected_rooms'] ?? []; 
        $auto_checkin = isset($_POST['auto_checkin']);
        $confirm_details = isset($_POST['confirm_details']);

        if (empty($first_name) || empty($last_name) || empty($selected_rooms) || empty($checkout_date)) {
            $error = "Group Leader Name, Checkout Date, and at least ONE Room are required.";
        } elseif (!$confirm_details || !$auto_checkin) {
            $error = "You must tick both confirmation boxes.";
        } else {
            $count = 0;
            $grand_total = 0;
            $room_list_string = implode(", ", $selected_rooms);

            foreach ($selected_rooms as $r_name) {
                $r_type = $room_details_map[$r_name]['type'];
                $r_rate = $room_details_map[$r_name]['rate'];
                
                $stmt = $conn->prepare("INSERT INTO guest (first_name, last_name, phone, email, address, city, country, company_name, company_address, room_name, room_type, room_rate, checkin_date, checkin_time, checkout_date, checkout_time, status, car_available, car_plate, booking_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Checked-in', ?, ?, ?)");
                $address_dummy = $company_address; 
                $stmt->bind_param("sssssssssssssssssss", $first_name, $last_name, $phone, $email, $address_dummy, $city, $country, $company_name, $company_address, $r_name, $r_type, $r_rate, $checkin_date, $checkin_time, $checkout_date, $checkout_time, $car_available, $car_plate, $booking_type);
                
                if ($stmt->execute()) {
                    $guest_id = $conn->insert_id;
                    $checkin_datetime = "$checkin_date " . ($checkin_time ?: '00:00:00');
                    $checkout_datetime = "$checkout_date " . ($checkout_time ?: '10:00:00');
                    $diff = strtotime($checkout_datetime) - strtotime($checkin_datetime);
                    $days = max(1, ceil($diff / (60*60*24)));
                    $total = $r_rate * $days;
                    $grand_total += $total;

                    $c_stmt = $conn->prepare("INSERT INTO checkin_checkout (guest_id, room_name, room_type, checkin_time, days_stayed, total_amount, status) VALUES (?, ?, ?, ?, ?, ?, 'Checked In')");
                    $c_stmt->bind_param("isssid", $guest_id, $r_name, $r_type, $checkin_datetime, $days, $total);
                    $c_stmt->execute();
                    $conn->query("UPDATE rooms SET status='Occupied' WHERE room_name='$r_name'");
                    $count++;
                }
            }
            if ($count > 0) {
                // --- LOGGING FOR GROUP ---
                $log_msg = "Group Check-in: $company_name (Leader: $first_name $last_name). Rooms: $room_list_string. Total Bill: TZS " . number_format($grand_total);
                logActivity($conn, "Check-in", $log_msg);

                $swal_data = [
                    'icon' => 'success',
                    'title' => 'Group Added Successfully!',
                    'html' => "<strong>$count Rooms Booked</strong><br>Total Amount: <strong>TZS " . number_format($grand_total) . "</strong>",
                    'redirect' => 'view_guests.php?mode=group'
                ];
                $swal_json = json_encode($swal_data);
                
                $first_name = $last_name = $company_name = '';
            } else { $error = "Failed to book rooms. Please try again."; }
        }
    }

    // --- B. SINGLE CHECK-IN ---
    else {
        $booking_type = 'individual'; 

        $first_name = $_POST['first_name'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $resident_status = $_POST['resident_status'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $email = $_POST['email'] ?? '';
        $address = $_POST['address'] ?? '';
        $city = $_POST['city'] ?? '';
        $country = $_POST['country'] ?? '';
        $passport_id = $_POST['passport_id'] ?? '';
        $passport_country = $_POST['passport_country'] ?? '';
        $passport_expiry = $_POST['passport_expiry'] ?? '';
        $company_name = $_POST['company_name'] ?? '';
        $company_address = $_POST['company_address'] ?? '';
        $room_name = $_POST['room_name'] ?? '';
        $room_type = $_POST['room_type'] ?? '';
        $room_rate = $_POST['room_rate'] ?? '';
        $checkin_date = $_POST['checkin_date'] ?? '';
        $checkin_time = $_POST['checkin_time'] ?? '';
        $checkout_date = $_POST['checkout_date'] ?? '';
        $checkout_time = $_POST['checkout_time'] ?? '';
        $status = 'Checked-in';
        $car_available = isset($_POST['car_available']) ? 'Yes' : 'No';
        $car_plates_array = $_POST['car_plates'] ?? [];
        $car_plates_clean = array_filter($car_plates_array, function($value) { return !empty(trim($value)); });
        $car_plate = !empty($car_plates_clean) ? implode(', ', $car_plates_clean) : ($_POST['car_plate'] ?? '');
        $auto_checkin = isset($_POST['auto_checkin']);
        $confirm_details = isset($_POST['confirm_details']);

        if (empty($first_name) || empty($last_name) || empty($room_name) || empty($room_type) || empty($checkin_date)) {
            $error = "First Name, Last Name, Room Name, Room Type and Check-in Date are required.";
        } elseif (!$confirm_details) {
            $error = "You must confirm the guest details.";
        } elseif (!$auto_checkin) {
            $error = "You must check 'Automatically check in this guest'.";
        } elseif (empty($checkout_date)) {
            $error = "IMPORTANT: To use Auto Check-In, you MUST provide a Check-out Date.";
        } else {
            $stmt = $conn->prepare("INSERT INTO guest (first_name,last_name,gender,resident_status,phone,email,address,city,country,passport_id,passport_country,passport_expiry,company_name,company_address,room_name,room_type,room_rate,checkin_date,checkin_time,checkout_date,checkout_time,status,car_available,car_plate, booking_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            if (!$stmt) { die("Prepare failed: " . $conn->error); }

            $stmt->bind_param("sssssssssssssssssssssssss", $first_name,$last_name,$gender,$resident_status,$phone,$email,$address,$city,$country,$passport_id,$passport_country,$passport_expiry,$company_name,$company_address,$room_name,$room_type,$room_rate,$checkin_date,$checkin_time,$checkout_date,$checkout_time,$status,$car_available,$car_plate, $booking_type);

            if ($stmt->execute()) {
                $guest_id = $conn->insert_id;
                $checkin_datetime = $checkin_date . ' ' . ($checkin_time ?: '00:00:00');
                $checkout_datetime = $checkout_date . ' ' . ($checkout_time ?: '10:00:00');
                $checkin_ts = strtotime($checkin_datetime);
                $checkout_ts = strtotime($checkout_datetime);
                
                if ($checkout_ts <= $checkin_ts) {
                    $error = "Check-out date and time must be after Check-in.";
                } else {
                    $days_stayed = ceil(($checkout_ts - $checkin_ts) / (60 * 60 * 24));
                    if ($days_stayed < 1) $days_stayed = 1;
                    $total_amount = floatval($room_rate) * $days_stayed;
                    
                    $checkin_stmt = $conn->prepare("INSERT INTO checkin_checkout (guest_id, room_name, room_type, checkin_time, days_stayed, total_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($checkin_stmt) {
                        $status_cc = 'Checked In';
                        $checkin_stmt->bind_param("isssids", $guest_id, $room_name, $room_type, $checkin_datetime, $days_stayed, $total_amount, $status_cc);
                        if ($checkin_stmt->execute()) {
                            
                            // --- LOGGING FOR INDIVIDUAL ---
                            $log_msg = "Guest Check-in: $first_name $last_name into Room: $room_name. Bill: TZS " . number_format($total_amount);
                            logActivity($conn, "Check-in", $log_msg);

                            $swal_data = [
                                'icon' => 'success',
                                'title' => 'Guest Checked In!',
                                'html' => "Room Assigned: <strong>$room_name</strong><br>Total Bill: <strong>TZS " . number_format($total_amount) . "</strong>",
                                'redirect' => 'view_guests.php'
                            ];
                            $swal_json = json_encode($swal_data);
                            
                            $conn->query("UPDATE rooms SET status='Occupied' WHERE room_name='$room_name'");
                            $first_name = $last_name = $phone = ''; // Reset
                        } else { $error = "Checkin record failed: " . $checkin_stmt->error; }
                        $checkin_stmt->close();
                    }
                }
            } else { $error = "Error: " . $stmt->error; }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Guest - Hotel Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* CSS STYLES */
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; }
.sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: #1e3a5f; color: #fff; padding: 30px 0; display: flex; flex-direction: column; box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1); z-index: 1000; }
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
.logout-btn:hover { background: #c82333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3); }
.main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; }
.header { margin-bottom: 30px; background: #fff; padding: 25px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); }
.header h2 { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; }
.alert { padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 500; }
.alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
.alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
.form-card { background: #fff; border-radius: 15px; padding: 30px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); margin-bottom: 25px; }
.section-title { font-size: 1.2rem; font-weight: 700; color: #1e3a5f; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 3px solid #667eea; display: flex; align-items: center; gap: 10px; }
.form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 15px; }
.form-group { display: flex; flex-direction: column; }
.form-label { font-weight: 600; margin-bottom: 8px; color: #1e3a5f; font-size: 0.9rem; }
.form-label .required { color: #dc3545; margin-left: 3px; }
.form-control { padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 0.95rem; transition: all 0.3s ease; background: #f8f9fa; }
.form-control:focus { outline: none; border-color: #667eea; background: #fff; }
.form-control:read-only { background: #e9ecef; cursor: not-allowed; }
select.form-control { cursor: pointer; }
.form-check { display: flex; align-items: center; gap: 10px; padding-top: 30px; }
.form-check-input { width: 20px; height: 20px; cursor: pointer; accent-color: #667eea; }
.form-check-label { font-weight: 600; color: #1e3a5f; cursor: pointer; margin: 0; }
.btn-submit { padding: 14px 40px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: #fff; border: none; border-radius: 10px; font-weight: 700; font-size: 1.1rem; cursor: pointer; transition: all 0.3s ease; margin-top: 20px; display: flex; align-items: center; gap: 10px; }
.btn-submit:hover:not(:disabled) { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(40, 167, 69, 0.5); }
.btn-submit:disabled { background: linear-gradient(135deg, #ccc 0%, #aaa 100%); cursor: not-allowed; opacity: 0.6; }
.checkin-notice { background: #cfe2ff; border: 2px solid #0c63e4; border-radius: 10px; padding: 15px; margin: 20px 0; color: #084298; font-weight: 500; display: flex; align-items: center; gap: 10px; }
.confirm-notice { background: #fff3cd; border: 2px solid #ffc107; border-radius: 10px; padding: 15px; margin: 20px 0; color: #856404; font-weight: 600; display: flex; align-items: center; gap: 10px; }
.mode-switcher { display: inline-flex; gap: 10px; margin-left: 20px; vertical-align: middle; }
.btn-mode { text-decoration: none; padding: 8px 16px; border-radius: 20px; font-size: 0.9rem; font-weight: 600; transition: 0.3s; border: 2px solid #e9ecef; color: #6c757d; }
.btn-mode:hover { background: #e9ecef; }
.btn-mode.active { background: #667eea; color: white; border-color: #667eea; box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3); }
.custom-dropdown { position: relative; width: 100%; }
.dropdown-trigger { padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px; background: #f8f9fa; cursor: pointer; font-size: 0.95rem; display: flex; justify-content: space-between; align-items: center; transition: 0.3s; }
.dropdown-trigger:hover { background: #fff; border-color: #667eea; }
.dropdown-trigger::after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: #6c757d; }
.dropdown-content { display: none; position: absolute; top: 100%; left: 0; width: 100%; max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid #ddd; border-radius: 0 0 10px 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); z-index: 100; margin-top: 5px; }
.dropdown-content.show { display: block; }
.dropdown-item { display: flex; align-items: center; padding: 10px 15px; border-bottom: 1px solid #f1f1f1; cursor: pointer; transition: 0.2s; }
.dropdown-item:hover { background: #f8f9fa; }
.dropdown-item input[type="checkbox"] { margin-right: 12px; width: 18px; height: 18px; accent-color: #667eea; cursor: pointer; }
.dropdown-item label { cursor: pointer; width: 100%; font-size: 0.9rem; }
.dropdown-item small { color: #7f8c8d; margin-left: 5px; }
.calculation-bar { margin-top: 20px; padding: 15px; background: #e3f2fd; border: 1px solid #90caf9; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; color: #1565c0; font-weight: 600; }
.car-input-group { display: flex; gap: 10px; margin-top: 10px; margin-bottom: 10px; }
.btn-add-car { background: #17a2b8; color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-size: 0.85rem; margin-top: 5px; }
.btn-remove-car { background: #dc3545; color: white; border: none; padding: 0 15px; border-radius: 8px; cursor: pointer; }
@media (max-width: 768px) { .sidebar { width: 70px; } .sidebar-header h2, .sidebar-header p, .sidebar a span { display: none; } .sidebar a { justify-content: center; padding: 14px; } .sidebar a i { margin: 0; } .main-content { margin-left: 70px; padding: 20px; } .header { flex-direction: column; gap: 15px; align-items: flex-start; } .form-row { grid-template-columns: 1fr; } .mode-switcher { display: block; margin: 15px 0 0 0; } }
</style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-header"><h2>Receptionist Dashboard</h2><p>Front Desk Management</p></div>
    <div class="sidebar-nav">
        <a href="receptionist_dashboard.php" class="<?= $currentPage=='receptionist_dashboard.php'?'active':'' ?>"><i class="fa-solid fa-house"></i> <span>Dashboard</span></a>
        <a href="add_guest.php" class="<?= $currentPage=='add_guest.php'?'active':'' ?>"><i class="fa-solid fa-user-plus"></i> <span>Add Guest</span></a>
        <a href="view_guests.php" class="<?= $currentPage=='view_guests.php'?'active':'' ?>"><i class="fa-solid fa-users"></i> <span>View Guests</span></a>
        <a href="rooms.php" class="<?= $currentPage=='rooms.php'?'active':'' ?>"><i class="fa-solid fa-bed"></i> <span>Rooms </span></a>
        <a href="checkin_checkout.php" class="<?= $currentPage=='checkin_checkout.php'?'active':'' ?>"><i class="fa-solid fa-door-open"></i> <span>Check-in / Check-out</span></a>
        <a href="payments.php" class="<?= $currentPage=='payments.php'?'active':'' ?>"><i class="fa-solid fa-credit-card"></i> <span>Payments</span></a>
        <a href="messages.php" class="<?= $currentPage=='messages.php'?'active':'' ?>"><i class="fa-solid fa-bell"></i> <span>Messages</span></a>
    </div>
    <div class="logout-section"><form action="logout.php" method="POST"><button type="submit" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span></button></form></div>
</div>

<div class="main-content">
    <div class="header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
        <div style="display: flex; align-items: center;">
            <h2 style="margin: 0;"><i class="fa-solid fa-user-plus"></i> Add Guest</h2>
            <div class="mode-switcher">
                <a href="add_guest.php?mode=single" class="btn-mode <?= $mode == 'single' ? 'active' : '' ?>"><i class="fa-solid fa-user"></i> Individual</a>
                <a href="add_guest.php?mode=group" class="btn-mode <?= $mode == 'group' ? 'active' : '' ?>"><i class="fa-solid fa-users"></i> Group / Company</a>
            </div>
        </div>
    </div>
    
    <?php if($error): ?><div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($mode === 'group'): ?>
    <form method="POST" id="groupForm">
        <input type="hidden" name="form_mode" value="group">
        <div class="form-card" style="border-top: 5px solid #667eea;">
            <h4 class="section-title"><i class="fa-solid fa-building"></i> Company / Group Details</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Company Name / Group Name</label><input type="text" name="company_name" class="form-control" placeholder="e.g. Vodacom Team" required></div>
                <div class="form-group"><label class="form-label">Company Address</label><input type="text" name="company_address" class="form-control" placeholder="Location"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">City</label><input type="text" name="city" class="form-control" placeholder="City"></div>
                <div class="form-group"><label class="form-label">Country</label><input type="text" name="country" class="form-control" placeholder="Country"></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-user-tie"></i> Team Leader Info</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">First Name<span class="required">*</span></label><input type="text" name="first_name" class="form-control" placeholder="Leader's First Name" required></div>
                <div class="form-group"><label class="form-label">Last Name<span class="required">*</span></label><input type="text" name="last_name" class="form-control" placeholder="Leader's Last Name" required></div>
                <div class="form-group"><label class="form-label">Phone Number</label><input type="text" name="phone" class="form-control"></div>
                <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-bed"></i> Select Rooms</h4>
            <div class="form-row">
                <div class="form-group" style="grid-column: span 3;">
                    <label class="form-label">Select Multiple Rooms<span class="required">*</span></label>
                    <div class="custom-dropdown" id="roomDropdown">
                        <div class="dropdown-trigger" id="dropdownTrigger">Select Rooms...</div>
                        <div class="dropdown-content">
                            <?php foreach($available_rooms as $room): ?>
                            <div class="dropdown-item">
                                <input type="checkbox" name="selected_rooms[]" id="room_<?= $room['room_name'] ?>" value="<?= htmlspecialchars($room['room_name']) ?>" data-rate="<?= $room['room_rate'] ?>" class="room-checkbox">
                                <label for="room_<?= $room['room_name'] ?>">Room <?= htmlspecialchars($room['room_name']) ?> - <?= htmlspecialchars($room['room_type']) ?> <small>(TZS <?= number_format($room['room_rate']) ?>)</small></label>
                            </div>
                            <?php endforeach; ?>
                            <?php if(empty($available_rooms)): ?><div class="dropdown-item" style="color: red;">No rooms available.</div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="calculation-bar"><span><i class="fa-solid fa-calculator"></i> Selected Value (Per Night):</span><span id="total_display" style="font-size: 1.2rem;">TZS 0</span></div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-calendar-alt"></i> Stay Duration</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Check-in Date<span class="required">*</span></label><input type="date" name="checkin_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <div class="form-group"><label class="form-label">Check-in Time</label><input type="time" name="checkin_time" class="form-control" value="<?= date('H:i') ?>"></div>
                <div class="form-group"><label class="form-label">Check-out Date<span class="required">*</span></label><input type="date" name="checkout_date" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Check-out Time</label><input type="time" name="checkout_time" class="form-control" value="10:00"></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-car"></i> Car Information (Optional)</h4>
            <div class="form-row"><div class="form-check" style="padding-top:0;"><input type="checkbox" name="car_available" id="group_car_available" class="form-check-input" value="Yes"><label class="form-check-label" for="group_car_available">Guest has a car(s)</label></div></div>
            <div id="group_car_container" style="display: none; margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px;">
                <label class="form-label">Car Plate Numbers</label>
                <div id="group_car_inputs"><div class="car-input-group"><input type="text" name="car_plates[]" class="form-control" placeholder="e.g. T 123 ABC"></div></div>
                <button type="button" class="btn-add-car" onclick="addCarInput('group_car_inputs')"><i class="fa-solid fa-plus"></i> Add Another Car</button>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-check"></i> Confirmation</h4>
            <div class="checkin-notice"><i class="fa-solid fa-info-circle"></i><span>Please tick both boxes below to proceed.</span></div>
            <div class="form-row">
                <div class="form-check"><input type="checkbox" name="auto_checkin" id="auto_checkin" class="form-check-input" value="1"><label class="form-check-label" for="auto_checkin"><strong>Auto Check-in</strong> (Add to Check-in List)</label></div>
                <div class="form-check"><input type="checkbox" name="confirm_details" id="confirm_details" class="form-check-input" value="1"><label class="form-check-label" for="confirm_details">I confirm group details are correct</label></div>
            </div>
            <button type="submit" class="btn-submit" id="submitBtn" disabled><i class="fa-solid fa-users"></i> Process Group Check-In</button>
        </div>
    </form>
    <?php else: ?>
    <form method="POST" id="guestForm">
        <input type="hidden" name="form_mode" value="single">
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-user"></i> Common Information</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">First Name<span class="required">*</span></label><input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($first_name) ?>" required></div>
                <div class="form-group"><label class="form-label">Last Name<span class="required">*</span></label><input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($last_name) ?>" required></div>
                <div class="form-group"><label class="form-label">Gender</label><select name="gender" class="form-control"><option value="">Select Gender</option><option value="Male" <?= $gender==='Male'?'selected':'' ?>>Male</option><option value="Female" <?= $gender==='Female'?'selected':'' ?>>Female</option></select></div>
                <div class="form-group"><label class="form-label">Resident Status</label><select name="resident_status" class="form-control"><option value="">Select Status</option><option value="Resident" <?= $resident_status==='Resident'?'selected':'' ?>>Resident</option><option value="Non-Resident" <?= $resident_status==='Non-Resident'?'selected':'' ?>>Non-Resident</option></select></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-address-book"></i> Contact Information</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Phone Number</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($phone) ?>"></div>
                <div class="form-group"><label class="form-label">Email Address</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>"></div>
                <div class="form-group"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="<?= htmlspecialchars($address) ?>"></div>
                <div class="form-group"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?= htmlspecialchars($city) ?>"></div>
                <div class="form-group"><label class="form-label">Country</label><input type="text" name="country" class="form-control" value="<?= htmlspecialchars($country) ?>"></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-passport"></i> Passport / ID Information</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Passport / ID Number</label><input type="text" name="passport_id" class="form-control" value="<?= htmlspecialchars($passport_id) ?>"></div>
                <div class="form-group"><label class="form-label">Issuing Country</label><input type="text" name="passport_country" class="form-control" value="<?= htmlspecialchars($passport_country) ?>"></div>
                <div class="form-group"><label class="form-label">Expiration Date</label><input type="date" name="passport_expiry" class="form-control" value="<?= htmlspecialchars($passport_expiry) ?>"></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-building"></i> Company Information (Optional)</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Company Name</label><input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($company_name) ?>"></div>
                <div class="form-group"><label class="form-label">Company Address</label><input type="text" name="company_address" class="form-control" value="<?= htmlspecialchars($company_address) ?>"></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-bed"></i> Room Information</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Room Name<span class="required">*</span></label><select name="room_name" id="room_name" class="form-control" required><option value="">Select Room</option><?php foreach($available_rooms as $room): ?><option value="<?= htmlspecialchars($room['room_name']) ?>" data-type="<?= htmlspecialchars($room['room_type']) ?>" data-rate="<?= htmlspecialchars($room['room_rate']) ?>" <?= $room_name===$room['room_name']?'selected':'' ?>><?= htmlspecialchars($room['room_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Room Type<span class="required">*</span></label><input type="text" name="room_type" id="room_type" class="form-control" value="<?= htmlspecialchars($room_type) ?>" readonly></div>
                <div class="form-group"><label class="form-label">Room Rate (TZS)</label><input type="number" name="room_rate" id="room_rate" class="form-control" value="<?= htmlspecialchars($room_rate) ?>" readonly></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-calendar-check"></i> Check-in / Check-out Details</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Check-in Date<span class="required">*</span></label><input type="date" name="checkin_date" class="form-control" value="<?= htmlspecialchars($checkin_date) ?>" required></div>
                <div class="form-group"><label class="form-label">Check-in Time</label><input type="time" name="checkin_time" class="form-control" value="<?= htmlspecialchars($checkin_time) ?>"></div>
                <div class="form-group"><label class="form-label">Check-out Date</label><input type="date" name="checkout_date" class="form-control" value="<?= htmlspecialchars($checkout_date) ?>"></div>
                <div class="form-group"><label class="form-label">Check-out Time</label><input type="time" name="checkout_time" class="form-control" value="<?= htmlspecialchars($checkout_time) ?>"></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-car"></i> Car Information (Optional)</h4>
            <div class="form-row"><div class="form-check" style="padding-top:0;"><input type="checkbox" name="car_available" id="single_car_available" class="form-check-input" value="Yes" <?= $car_available==='Yes'?'checked':'' ?>><label class="form-check-label" for="single_car_available">Guest has a car</label></div></div>
             <div id="single_car_container" style="display: none; margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px;">
                <label class="form-label">Car Plate Number</label>
                <div id="single_car_inputs"><div class="car-input-group"><input type="text" name="car_plates[]" class="form-control" value="<?= htmlspecialchars($car_plate) ?>" placeholder="e.g. T 123 ABC"></div></div>
                 <button type="button" class="btn-add-car" onclick="addCarInput('single_car_inputs')"><i class="fa-solid fa-plus"></i> Add Another Car</button>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-sign-in-alt"></i> Auto Check-In</h4>
            <div class="checkin-notice"><i class="fa-solid fa-info-circle"></i><span>Check the box below to automatically check in this guest and add them to the Check-in/Check-out page</span></div>
            <div class="form-row"><div class="form-check"><input type="checkbox" name="auto_checkin" class="form-check-input" id="auto_checkin" value="1" <?= $auto_checkin?'checked':'' ?>><label class="form-check-label" for="auto_checkin">Automatically check in this guest</label></div></div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-check-double"></i> Confirmation</h4>
            <div class="confirm-notice"><i class="fa-solid fa-exclamation-triangle"></i><span>IMPORTANT: You must verify and confirm all guest details before recording them in the system.</span></div>
            <div class="form-row"><div class="form-check"><input type="checkbox" name="confirm_details" class="form-check-input" id="confirm_details" value="1" <?= $confirm_details?'checked':'' ?>><label class="form-check-label" for="confirm_details">I confirm all guest details are correct and accurate</label></div></div>
        </div>
        <button type="submit" class="btn-submit" id="submitBtn" disabled><i class="fa-solid fa-user-plus"></i> Add Guest</button>
    </form>
    <?php endif; ?>
</div>

<script>
<?php if(!empty($swal_json)): ?>
    const swalData = <?= $swal_json ?>;
    Swal.fire({
        icon: swalData.icon,
        title: swalData.title,
        html: swalData.html,
        confirmButtonText: 'OK',
        confirmButtonColor: '#28a745',
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = swalData.redirect;
        }
    });
<?php endif; ?>

const trigger = document.getElementById('dropdownTrigger');
const content = document.querySelector('.dropdown-content');
const roomCheckboxes = document.querySelectorAll('.room-checkbox');
const totalDisplay = document.getElementById('total_display');
if (trigger) {
    trigger.addEventListener('click', function(e) { content.classList.toggle('show'); e.stopPropagation(); });
    document.addEventListener('click', function(e) { if (!trigger.contains(e.target) && !content.contains(e.target)) { content.classList.remove('show'); } });
    function updateDropdown() {
        let count = 0; let total = 0;
        roomCheckboxes.forEach(box => { if (box.checked) { count++; total += parseFloat(box.getAttribute('data-rate')); } });
        trigger.textContent = count > 0 ? count + " Rooms Selected" : "Select Rooms...";
        if(count>0) {trigger.style.fontWeight="bold"; trigger.style.color="#1e3a5f";} else {trigger.style.fontWeight="normal"; trigger.style.color="#2c3e50";}
        if (totalDisplay) { totalDisplay.textContent = "TZS " + total.toLocaleString(); }
    }
    roomCheckboxes.forEach(box => { box.addEventListener('change', updateDropdown); });
}
if (document.getElementById('room_name')) {
    document.getElementById('room_name').addEventListener('change', function() {
        var selected = this.options[this.selectedIndex];
        document.getElementById('room_type').value = selected.getAttribute('data-type') || '';
        document.getElementById('room_rate').value = selected.getAttribute('data-rate') || '';
    });
}
function toggleCarSection(checkboxId, containerId) {
    const checkbox = document.getElementById(checkboxId);
    const container = document.getElementById(containerId);
    if(checkbox && container) {
        checkbox.addEventListener('change', function() { container.style.display = this.checked ? 'block' : 'none'; });
        container.style.display = checkbox.checked ? 'block' : 'none';
    }
}
toggleCarSection('group_car_available', 'group_car_container');
toggleCarSection('single_car_available', 'single_car_container');
function addCarInput(containerId) {
    const container = document.getElementById(containerId);
    const div = document.createElement('div');
    div.className = 'car-input-group';
    div.innerHTML = `<input type="text" name="car_plates[]" class="form-control" placeholder="Car Plate Number"><button type="button" class="btn-remove-car" onclick="removeCarInput(this)"><i class="fa-solid fa-times"></i></button>`;
    container.appendChild(div);
}
function removeCarInput(button) { button.parentElement.remove(); }
const chkAuto = document.getElementById('auto_checkin');
const chkConfirm = document.getElementById('confirm_details');
const btn = document.getElementById('submitBtn');
if (chkAuto && chkConfirm && btn) {
    function toggleBtn() { btn.disabled = !(chkAuto.checked && chkConfirm.checked); }
    chkAuto.addEventListener('change', toggleBtn);
    chkConfirm.addEventListener('change', toggleBtn);
}
const forms = document.querySelectorAll('form');
forms.forEach(form => {
    form.addEventListener('submit', function(e) {
        var checkout = form.querySelector('input[name="checkout_date"]');
        if (checkout && !checkout.value) { e.preventDefault(); alert('IMPORTANT: Check-out Date is required.'); checkout.focus(); }
    });
});
</script>
</body>
</html>