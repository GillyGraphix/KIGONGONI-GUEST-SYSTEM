<?php
session_start();
include 'db_connect.php';

// Security: only receptionist
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

$currentPage = basename($_SERVER['PHP_SELF']);
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
$booking_id_delete = 0; 
$swal_json = ''; 

// --- 1. AUTOFILL KUTOKA KWA BOOKING ---
if (isset($_GET['booking_id'])) {
    $bid = intval($_GET['booking_id']);
    $b_stmt = $conn->query("SELECT * FROM bookings WHERE id = $bid");
    if ($b_row = $b_stmt->fetch_assoc()) {
        $first_name = $b_row['first_name'];
        $last_name = $b_row['last_name'];
        $phone = $b_row['phone_number'];
        $room_name = $b_row['room_name']; 
        $checkin_date = $b_row['check_in'];
        $checkout_date = $b_row['check_out'];
        if (!empty($b_row['arrival_time']) && $b_row['arrival_time'] != '00:00:00') {
             $checkin_time = date('H:i', strtotime($b_row['arrival_time']));
        }
        $booking_id_delete = $bid; 
        $auto_checkin = true; 
    }
}

// Fetch available rooms
$available_rooms = [];
$room_details_map = []; 
$sql_rooms = "SELECT room_name, room_type, room_rate FROM rooms WHERE status='Available'";
if (!empty($room_name)) {
    $safe_room = mysqli_real_escape_string($conn, $room_name);
    $sql_rooms .= " OR room_name = '$safe_room'";
}
$room_stmt = $conn->prepare($sql_rooms);
$room_stmt->execute();
$room_result = $room_stmt->get_result();
while ($row = $room_result->fetch_assoc()) {
    $available_rooms[] = $row;
    $room_details_map[$row['room_name']] = ['type' => $row['room_type'], 'rate' => $row['room_rate']];
}
$room_stmt->close();

// ==============================================================
// HELPER FUNCTION: Angalia chumba kama kiko busy TU
// ==============================================================
function checkDuplicate($conn, $room_name, $passport_id = '', $phone = '') {
    // Angalia chumba kama kiko na mgeni aliyecheckin tayari
    $r = $conn->prepare("SELECT guest_id FROM guest WHERE room_name = ? AND status = 'Checked-in' LIMIT 1");
    $r->bind_param("s", $room_name);
    $r->execute();
    $exists = $r->get_result()->num_rows > 0;
    $r->close();
    if ($exists) {
        return "Chumba <strong>$room_name</strong> kina mgeni tayari ambaye bado hajatoka. Tafadhali mfanye checkout kwanza au chagua chumba kingine.";
    }
    return null; // Sawa, endelea
}

// HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_mode = $_POST['form_mode'] ?? 'single';
    $booking_id_to_clear = isset($_POST['booking_id_delete']) ? intval($_POST['booking_id_delete']) : 0;

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
                // CHECK DUPLICATE KWA KILA CHUMBA
                $dup_error = checkDuplicate($conn, $r_name, '', $phone);
                if ($dup_error) {
                    $error = $dup_error;
                    break;
                }

                $r_type = $room_details_map[$r_name]['type'];
                $r_rate = $room_details_map[$r_name]['rate'];
                
                $stmt = $conn->prepare("INSERT INTO guest (first_name, last_name, phone, email, address, city, country, company_name, company_address, room_name, room_type, room_rate, checkin_date, checkin_time, checkout_date, checkout_time, status, car_available, car_plate, booking_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Checked-in', ?, ?, ?)");
                $address_dummy = $company_address; 
                $stmt->bind_param("sssssssssssssssssss", $first_name, $last_name, $phone, $email, $address_dummy, $city, $country, $company_name, $company_address, $r_name, $r_type, $r_rate, $checkin_date, $checkin_time, $checkout_date, $checkout_time, $car_available, $car_plate, $booking_type);
                
                try {
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
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() == 1062) {
                        $error = "Chumba <strong>$r_name</strong> kina mgeni tayari (Duplicate Entry). Tafadhali mfanye checkout kwanza.";
                    } else {
                        $error = "Database Error: " . $e->getMessage();
                    }
                }
            }
            if ($count > 0 && empty($error)) {
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
            }
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
        $passport_expiry = !empty($_POST['passport_expiry']) ? $_POST['passport_expiry'] : null;
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
        } elseif (empty($gender)) {
            $error = "Tafadhali chagua <strong>Jinsia (Gender)</strong> ya mgeni kabla ya kuendelea.";
        } elseif (!$confirm_details) {
            $error = "You must confirm the guest details.";
        } elseif (!$auto_checkin) {
            $error = "You must check 'Automatically check in this guest'.";
        } elseif (empty($checkout_date)) {
            $error = "IMPORTANT: To use Auto Check-In, you MUST provide a Check-out Date.";
        } else {
            // === CHECK DUPLICATE KABLA YA KUINSERT ===
            $dup_error = checkDuplicate($conn, $room_name, $passport_id, $phone);
            if ($dup_error) {
                $error = $dup_error;
            } else {
                $stmt = $conn->prepare("INSERT INTO guest (first_name,last_name,gender,resident_status,phone,email,address,city,country,passport_id,passport_country,passport_expiry,company_name,company_address,room_name,room_type,room_rate,checkin_date,checkin_time,checkout_date,checkout_time,status,car_available,car_plate,booking_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

                if (!$stmt) { die("Prepare failed: " . $conn->error); }

                $stmt->bind_param("sssssssssssssssssssssssss", $first_name,$last_name,$gender,$resident_status,$phone,$email,$address,$city,$country,$passport_id,$passport_country,$passport_expiry,$company_name,$company_address,$room_name,$room_type,$room_rate,$checkin_date,$checkin_time,$checkout_date,$checkout_time,$status,$car_available,$car_plate,$booking_type);

                try {
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
                                    $log_msg = "Guest Check-in: $first_name $last_name into Room: $room_name. Bill: TZS " . number_format($total_amount);
                                    logActivity($conn, "Check-in", $log_msg);

                                    if ($booking_id_to_clear > 0) {
                                        $conn->query("DELETE FROM bookings WHERE id = $booking_id_to_clear");
                                    }

                                    $swal_data = [
                                        'icon' => 'success',
                                        'title' => 'Guest Checked In!',
                                        'html' => "Room Assigned: <strong>$room_name</strong><br>Total Bill: <strong>TZS " . number_format($total_amount) . "</strong>",
                                        'redirect' => 'view_guests.php'
                                    ];
                                    $swal_json = json_encode($swal_data);
                                    $conn->query("UPDATE rooms SET status='Occupied' WHERE room_name='$room_name'");
                                    $first_name = $last_name = $phone = ''; 
                                }
                                $checkin_stmt->close();
                            }
                        }
                    }
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() == 1062) {
                        // Duplicate entry - most likely the unique_guest index
                        // Drop it: ALTER TABLE guest DROP INDEX unique_guest;
                        $error = "Chumba <strong>$room_name</strong> kina mgeni tayari. Tafadhali mfanye checkout mgeni wa sasa, kisha ujaribu tena.";
                    } else {
                        $error = "Tatizo la Database: " . $e->getMessage();
                    }
                }
                $stmt->close();
            }
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
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; overflow-x: hidden; }

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

    .main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; transition: margin-left 0.3s ease-in-out; }

    .header { margin-bottom: 35px; background: #fff; padding: 25px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; }
    .header-title { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; display: flex; align-items: center; gap: 10px; }
    .menu-toggle { display: none; font-size: 1.5rem; color: #1e3a5f; cursor: pointer; }

    .mode-switcher { display: flex; gap: 10px; }
    .btn-mode { text-decoration: none; padding: 10px 20px; border-radius: 12px; font-size: 0.9rem; font-weight: 600; transition: 0.3s; border: 2px solid #e9ecef; color: #6c757d; display: flex; align-items: center; gap: 8px; }
    .btn-mode:hover { background: #f8f9fa; transform: translateY(-2px); }
    .btn-mode.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-color: transparent; box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3); }

    .form-card { background: #fff; border-radius: 15px; padding: 30px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); margin-bottom: 25px; transition: all 0.3s ease; }
    .form-card:hover { box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08); }
    .section-title { font-size: 1.1rem; font-weight: 700; color: #1e3a5f; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #f0f2f5; display: flex; align-items: center; gap: 10px; }
    
    .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 15px; }
    .form-group { display: flex; flex-direction: column; }
    .form-label { font-weight: 600; margin-bottom: 8px; color: #2c3e50; font-size: 0.9rem; }
    .form-label .required { color: #dc3545; margin-left: 3px; }
    
    .form-control { width: 100%; padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 0.95rem; transition: all 0.3s ease; background: #f8f9fa; color: #000 !important; }
    .form-control:focus { outline: none; border-color: #667eea; background: #fff; }
    select.form-control { color: #1e3a5f !important; font-weight: 500; }
    .form-control:read-only { background: #e9ecef; color: #000 !important; font-weight: 600; }
    
    .form-check { display: flex; align-items: center; gap: 10px; padding-top: 15px; }
    .form-check-input { width: 18px; height: 18px; accent-color: #667eea; cursor: pointer; }
    .form-check-label { font-weight: 500; color: #2c3e50; cursor: pointer; font-size: 0.95rem; }

    .custom-dropdown { position: relative; width: 100%; }
    .dropdown-trigger { padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px; background: #f8f9fa; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: 0.3s; color: #1e3a5f; font-weight: 600; }
    .dropdown-content { display: none; position: absolute; top: 100%; left: 0; width: 100%; max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid #ddd; border-radius: 0 0 10px 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); z-index: 100; margin-top: 5px; }
    .dropdown-content.show { display: block; }
    .dropdown-item { padding: 10px 15px; border-bottom: 1px solid #f1f1f1; cursor: pointer; display: flex; align-items: center; gap: 10px; color: #000; }
    .dropdown-item:hover { background: #f8f9fa; }

    .btn-submit { width: 100%; padding: 15px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: #fff; border: none; border-radius: 10px; font-weight: 700; font-size: 1.1rem; cursor: pointer; transition: all 0.3s ease; margin-top: 10px; display: flex; align-items: center; justify-content: center; gap: 10px; }
    .btn-submit:hover:not(:disabled) { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4); }
    .btn-submit:disabled { background: #ccc; cursor: not-allowed; }

    .checkin-notice { background: #e3f2fd; border-left: 4px solid #0c63e4; padding: 15px; border-radius: 8px; color: #084298; margin: 15px 0; font-size: 0.9rem; display: flex; gap: 10px; align-items: center; }
    .confirm-notice { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; border-radius: 8px; color: #856404; margin: 15px 0; font-size: 0.9rem; display: flex; gap: 10px; align-items: center; font-weight: 600; }

    .calculation-bar { margin-top: 20px; padding: 15px; background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; color: #2e7d32; font-weight: 700; }

    .btn-add-car { background: #17a2b8; color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-size: 0.85rem; margin-top: 10px; }
    .btn-remove-car { background: #dc3545; color: white; border: none; padding: 8px 12px; border-radius: 8px; cursor: pointer; }
    .car-input-group { display: flex; gap: 10px; margin-top: 10px; align-items: center; }

    /* Error alert iliyoboreshwa */
    .alert { padding: 18px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 500; display: flex; align-items: flex-start; gap: 12px; }
    .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-left: 5px solid #dc3545; }
    .alert-danger .alert-icon { font-size: 1.4rem; flex-shrink: 0; margin-top: 2px; }
    .alert-actions { margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap; }
    .alert-btn { padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
    .alert-btn-checkout { background: #dc3545; color: #fff; }
    .alert-btn-rooms { background: #1e3a5f; color: #fff; }

    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 900; }

    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); width: 250px; }
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        .main-content { margin-left: 0; padding: 20px 15px; }
        .header { flex-direction: column; align-items: flex-start; gap: 20px; }
        .menu-toggle { display: block; }
        .mode-switcher { width: 100%; }
        .btn-mode { flex: 1; justify-content: center; }
        .form-row { grid-template-columns: 1fr; }
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
        <a href="receptionist_dashboard.php" class="<?= $currentPage=='receptionist_dashboard.php'?'active':'' ?>"><i class="fa-solid fa-house"></i> <span>Dashboard</span></a>
        <a href="add_guest.php" class="<?= $currentPage=='add_guest.php'?'active':'' ?>"><i class="fa-solid fa-user-plus"></i> <span>Add Guest</span></a>
        <a href="view_guests.php" class="<?= $currentPage=='view_guests.php'?'active':'' ?>"><i class="fa-solid fa-users"></i> <span>View Guests</span></a>
        <a href="rooms.php" class="<?= $currentPage=='rooms.php'?'active':'' ?>"><i class="fa-solid fa-bed"></i> <span>Rooms</span></a>
        <a href="checkin_checkout.php" class="<?= $currentPage=='checkin_checkout.php'?'active':'' ?>"><i class="fa-solid fa-door-open"></i> <span>Check-in / Check-out</span></a>
        <a href="payments.php" class="<?= $currentPage=='payments.php'?'active':'' ?>"><i class="fa-solid fa-credit-card"></i> <span>Payments</span></a>
        <a href="bookings.php" class="<?= $currentPage=='bookings.php'?'active':'' ?>"><i class="fa-solid fa-calendar-check"></i> <span>Reservations</span></a>
    </div>
    <div class="logout-section">
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span></button>
        </form>
    </div>
</div>

<div class="main-content">
    <div class="header">
        <div class="header-title">
            <i class="fa-solid fa-bars menu-toggle" onclick="toggleSidebar()"></i>
            <span><i class="fa-solid fa-user-plus"></i> Add New Guest</span>
        </div>
        <div class="mode-switcher">
            <a href="add_guest.php?mode=single" class="btn-mode <?= $mode == 'single' ? 'active' : '' ?>"><i class="fa-solid fa-user"></i> Individual</a>
            <a href="add_guest.php?mode=group" class="btn-mode <?= $mode == 'group' ? 'active' : '' ?>"><i class="fa-solid fa-users"></i> Group / Company</a>
        </div>
    </div>

    <?php if($error): ?>
    <div class="alert alert-danger">
        <div class="alert-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
        <div>
            <?= $error ?>
            <div class="alert-actions">
                <a href="checkin_checkout.php" class="alert-btn alert-btn-checkout"><i class="fa-solid fa-door-open"></i> Nenda Check-in/out</a>
                <a href="rooms.php" class="alert-btn alert-btn-rooms"><i class="fa-solid fa-bed"></i> Angalia Vyumba</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($mode === 'group'): ?>
    <form method="POST" id="groupForm">
        <input type="hidden" name="form_mode" value="group">
        <div class="form-card" style="border-top: 4px solid #667eea;">
            <h4 class="section-title"><i class="fa-solid fa-building"></i> Company / Group Details</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Company Name / Group Name <span class="required">*</span></label><input type="text" name="company_name" class="form-control" placeholder="e.g. Vodacom Team" required></div>
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
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Select Multiple Rooms<span class="required">*</span></label>
                    <div class="custom-dropdown" id="roomDropdown">
                        <div class="dropdown-trigger" id="dropdownTrigger">Select Rooms... <i class="fa-solid fa-chevron-down"></i></div>
                        <div class="dropdown-content">
                            <?php foreach($available_rooms as $room): ?>
                            <div class="dropdown-item">
                                <input type="checkbox" name="selected_rooms[]" id="room_<?= $room['room_name'] ?>" value="<?= htmlspecialchars($room['room_name']) ?>" data-rate="<?= $room['room_rate'] ?>" class="room-checkbox" style="width:18px; height:18px;">
                                <label for="room_<?= $room['room_name'] ?>" style="cursor:pointer; width:100%; color: #000;">Room <?= htmlspecialchars($room['room_name']) ?> - <?= htmlspecialchars($room['room_type']) ?> <small style="color:#666;">(TZS <?= number_format($room['room_rate']) ?>)</small></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="calculation-bar"><span><i class="fa-solid fa-calculator"></i> Total Value (Per Night):</span><span id="total_display" style="font-size: 1.2rem;">TZS 0</span></div>
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
            <h4 class="section-title"><i class="fa-solid fa-car"></i> Car Information</h4>
            <div class="form-check"><input type="checkbox" name="car_available" id="group_car_available" class="form-check-input" value="Yes"><label class="form-check-label" for="group_car_available">Guest has a car(s)</label></div>
            <div id="group_car_container" style="display: none; margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px;">
                <label class="form-label">Car Plate Numbers</label>
                <div id="group_car_inputs"><div class="car-input-group"><input type="text" name="car_plates[]" class="form-control" placeholder="e.g. T 123 ABC"></div></div>
                <button type="button" class="btn-add-car" onclick="addCarInput('group_car_inputs')"><i class="fa-solid fa-plus"></i> Add Another Car</button>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-check-circle"></i> Confirmation</h4>
            <div class="checkin-notice"><i class="fa-solid fa-info-circle"></i><span>Please tick both boxes below to proceed.</span></div>
            <div class="form-row">
                <div class="form-check"><input type="checkbox" name="auto_checkin" id="auto_checkin_group" class="form-check-input" value="1"><label class="form-check-label" for="auto_checkin_group"><strong>Auto Check-in</strong></label></div>
                <div class="form-check"><input type="checkbox" name="confirm_details" id="confirm_details_group" class="form-check-input" value="1"><label class="form-check-label" for="confirm_details_group">I confirm group details are correct</label></div>
            </div>
            <button type="submit" class="btn-submit" id="submitBtnGroup" disabled><i class="fa-solid fa-check"></i> Process Group Check-In</button>
        </div>
    </form>

    <?php else: ?>
    <form method="POST" id="guestForm">
        <input type="hidden" name="form_mode" value="single">
        <input type="hidden" name="booking_id_delete" value="<?= $booking_id_delete ?>">
        <div class="form-card" style="border-top: 4px solid #667eea;">
            <h4 class="section-title"><i class="fa-solid fa-user"></i> Guest Information</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">First Name<span class="required">*</span></label><input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($first_name) ?>" required></div>
                <div class="form-group"><label class="form-label">Last Name<span class="required">*</span></label><input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($last_name) ?>" required></div>
                <div class="form-group"><label class="form-label">Gender <span class="required">*</span></label><select name="gender" class="form-control" required><option value="">Select Gender</option><option value="Male" <?= $gender==='Male'?'selected':'' ?>>Male</option><option value="Female" <?= $gender==='Female'?'selected':'' ?>>Female</option></select></div>
                <div class="form-group"><label class="form-label">Resident Status</label><select name="resident_status" class="form-control"><option value="">Select Status</option><option value="Resident" <?= $resident_status==='Resident'?'selected':'' ?>>Resident</option><option value="Non-Resident" <?= $resident_status==='Non-Resident'?'selected':'' ?>>Non-Resident</option></select></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-address-book"></i> Contact Details</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Phone Number</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($phone) ?>"></div>
                <div class="form-group"><label class="form-label">Email Address</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>"></div>
                <div class="form-group"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="<?= htmlspecialchars($address) ?>"></div>
                <div class="form-group"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?= htmlspecialchars($city) ?>"></div>
                <div class="form-group"><label class="form-label">Country</label><input type="text" name="country" class="form-control" value="<?= htmlspecialchars($country) ?>"></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-passport"></i> Identification</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Passport / ID Number</label><input type="text" name="passport_id" class="form-control" value="<?= htmlspecialchars($passport_id) ?>"></div>
                <div class="form-group"><label class="form-label">Issuing Country</label><input type="text" name="passport_country" class="form-control" value="<?= htmlspecialchars($passport_country) ?>"></div>
                <div class="form-group"><label class="form-label">Expiration Date</label><input type="date" name="passport_expiry" class="form-control" value="<?= htmlspecialchars($passport_expiry) ?>"></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-briefcase"></i> Company (Optional)</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Company Name</label><input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($company_name) ?>"></div>
                <div class="form-group"><label class="form-label">Company Address</label><input type="text" name="company_address" class="form-control" value="<?= htmlspecialchars($company_address) ?>"></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-bed"></i> Room Assignment</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Room Name<span class="required">*</span></label><select name="room_name" id="room_name" class="form-control" required><option value="">Select Room</option><?php foreach($available_rooms as $room): ?><option value="<?= htmlspecialchars($room['room_name']) ?>" data-type="<?= htmlspecialchars($room['room_type']) ?>" data-rate="<?= htmlspecialchars($room['room_rate']) ?>" <?= $room_name===$room['room_name']?'selected':'' ?>><?= htmlspecialchars($room['room_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Room Type</label><input type="text" name="room_type" id="room_type" class="form-control" value="<?= htmlspecialchars($room_type) ?>" readonly></div>
                <div class="form-group"><label class="form-label">Room Rate (TZS)</label><input type="number" name="room_rate" id="room_rate" class="form-control" value="<?= htmlspecialchars($room_rate) ?>" readonly></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-clock"></i> Check-in / Check-out</h4>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Check-in Date<span class="required">*</span></label><input type="date" name="checkin_date" class="form-control" value="<?= htmlspecialchars($checkin_date) ?>" required></div>
                <div class="form-group"><label class="form-label">Check-in Time</label><input type="time" name="checkin_time" class="form-control" value="<?= htmlspecialchars($checkin_time) ?>"></div>
                <div class="form-group"><label class="form-label">Check-out Date</label><input type="date" name="checkout_date" class="form-control" value="<?= htmlspecialchars($checkout_date) ?>"></div>
                <div class="form-group"><label class="form-label">Check-out Time</label><input type="time" name="checkout_time" class="form-control" value="<?= htmlspecialchars($checkout_time) ?>"></div>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-car"></i> Car Details</h4>
            <div class="form-check"><input type="checkbox" name="car_available" id="single_car_available" class="form-check-input" value="Yes" <?= $car_available==='Yes'?'checked':'' ?>><label class="form-check-label" for="single_car_available">Guest has a car</label></div>
            <div id="single_car_container" style="display: none; margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px;">
                <label class="form-label">Car Plate Number</label>
                <div id="single_car_inputs"><div class="car-input-group"><input type="text" name="car_plates[]" class="form-control" value="<?= htmlspecialchars($car_plate) ?>" placeholder="e.g. T 123 ABC"></div></div>
                <button type="button" class="btn-add-car" onclick="addCarInput('single_car_inputs')"><i class="fa-solid fa-plus"></i> Add Another Car</button>
            </div>
        </div>
        <div class="form-card">
            <h4 class="section-title"><i class="fa-solid fa-clipboard-check"></i> Finalize Check-In</h4>
            <div class="checkin-notice"><i class="fa-solid fa-magic"></i><span>Check the box below to automatically check in this guest.</span></div>
            <div class="form-check"><input type="checkbox" name="auto_checkin" class="form-check-input" id="auto_checkin_single" value="1" <?= $auto_checkin?'checked':'' ?>><label class="form-check-label" for="auto_checkin_single">Automatically check in this guest</label></div>
            <div class="confirm-notice"><i class="fa-solid fa-exclamation-triangle"></i><span>Verification Required</span></div>
            <div class="form-check"><input type="checkbox" name="confirm_details" class="form-check-input" id="confirm_details_single" value="1" <?= $confirm_details?'checked':'' ?>><label class="form-check-label" for="confirm_details_single">I confirm all guest details are accurate</label></div>
            <button type="submit" class="btn-submit" id="submitBtnSingle" disabled><i class="fa-solid fa-user-check"></i> Complete Check-In</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('active');
    document.querySelector('.sidebar-overlay').classList.toggle('active');
}

<?php if(!empty($swal_json)): ?>
    const swalData = <?= $swal_json ?>;
    Swal.fire({
        icon: swalData.icon, title: swalData.title, html: swalData.html,
        confirmButtonText: 'OK', confirmButtonColor: '#28a745', allowOutsideClick: false
    }).then((result) => { if (result.isConfirmed) { window.location.href = swalData.redirect; } });
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
        trigger.innerHTML = count > 0 ? count + " Rooms Selected <i class='fa-solid fa-chevron-down'></i>" : "Select Rooms... <i class='fa-solid fa-chevron-down'></i>";
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
    if(document.getElementById('room_name').value) {
        document.getElementById('room_name').dispatchEvent(new Event('change'));
    }
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

function setupSubmitButton(autoId, confirmId, btnId) {
    const chkAuto = document.getElementById(autoId);
    const chkConfirm = document.getElementById(confirmId);
    const btn = document.getElementById(btnId);
    if (chkAuto && chkConfirm && btn) {
        const toggleBtn = () => { btn.disabled = !(chkAuto.checked && chkConfirm.checked); };
        chkAuto.addEventListener('change', toggleBtn);
        chkConfirm.addEventListener('change', toggleBtn);
        toggleBtn();
    }
}
setupSubmitButton('auto_checkin_group', 'confirm_details_group', 'submitBtnGroup');
setupSubmitButton('auto_checkin_single', 'confirm_details_single', 'submitBtnSingle');

document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        var checkout = form.querySelector('input[name="checkout_date"]');
        if (checkout && !checkout.value) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Missing Information', text: 'IMPORTANT: Check-out Date is required.' });
            checkout.focus();
        }
    });
});
</script>
</body>
</html>