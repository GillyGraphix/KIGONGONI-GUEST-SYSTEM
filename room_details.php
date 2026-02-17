<?php
session_start();
include 'db_connect.php';

// Security: Only admin/manager/ceo
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','manager','ceo'])) {
    header("Location: login.php");
    exit();
}

$fullname = $_SESSION['fullname'];
$currentPage = basename($_SERVER['PHP_SELF']);

// Default View
$active_tab = isset($_GET['view']) ? $_GET['view'] : 'rooms'; // rooms, guests, reservations

// --- 1. ROOMS LOGIC (CRUD) ---
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $stmt = $conn->prepare("DELETE FROM rooms WHERE room_id=?");
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    echo "<script>sessionStorage.setItem('roomDeleted', '1'); window.location.href='room_details.php';</script>";
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_room') {
    $room_id = $_POST['room_id'] ?? '';
    $room_name = strtoupper($_POST['room_name']);
    $room_type = strtoupper($_POST['room_type']);
    $room_rate = $_POST['room_rate'];

    if ($room_id) {
        $stmt = $conn->prepare("UPDATE rooms SET room_name=?, room_type=?, room_rate=? WHERE room_id=?");
        $stmt->bind_param("ssdi", $room_name, $room_type, $room_rate, $room_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO rooms (room_name, room_type, room_rate, status) VALUES (?, ?, ?, 'AVAILABLE')");
        $stmt->bind_param("ssd", $room_name, $room_type, $room_rate);
    }
    $stmt->execute();
    echo "<script>sessionStorage.setItem('roomSaved', '1'); window.location.href='room_details.php';</script>";
    exit();
}

// Fetch Rooms
$rooms_result = $conn->query("SELECT * FROM rooms ORDER BY room_id ASC");


// --- 2. GUESTS LOGIC (View & Search) ---
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'individual';
$search = $_GET['search'] ?? '';

if ($mode === 'group') {
    $query = "SELECT 
                min(guest_id) as guest_id_ref,
                company_name, first_name, last_name, phone, email,
                checkin_date, checkin_time, checkout_date, checkout_time, status,
                company_address, car_available, car_plate,
                COUNT(*) as total_rooms,
                GROUP_CONCAT(room_name ORDER BY room_name ASC SEPARATOR ', ') as room_list,
                SUM(room_rate) as total_group_rate,
                GROUP_CONCAT(DISTINCT car_plate SEPARATOR ', ') as all_cars
              FROM guest 
              WHERE booking_type = 'group' AND status != 'Checked-out' ";
              
    if (!empty($search)) {
        $query .= " AND (company_name LIKE '%$search%' OR first_name LIKE '%$search%' OR last_name LIKE '%$search%')";
    }
    $query .= " GROUP BY company_name, checkin_date ORDER BY checkin_date DESC";

} else {
    $query = "SELECT * FROM guest WHERE booking_type = 'individual' AND status != 'Checked-out'";
    if (!empty($search)) {
        $query .= " AND (first_name LIKE '%$search%' OR last_name LIKE '%$search%' OR phone LIKE '%$search%' OR room_name LIKE '%$search%' OR email LIKE '%$search%' OR country LIKE '%$search%')";
    }
    $query .= " ORDER BY guest_id DESC";
}
$guests_result = mysqli_query($conn, $query);


// --- 3. RESERVATIONS LOGIC (Future & Today Bookings) ---
$res_query = "SELECT * FROM bookings WHERE status IN ('Pending', 'Confirmed') ORDER BY check_in ASC";
$reservations_result = mysqli_query($conn, $res_query);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Room & Guest Details - Hotel Management System</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; overflow-x: hidden; }

    /* --- SIDEBAR (Responsive) --- */
    .sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: #1e3a5f; color: #fff; padding: 30px 0; display: flex; flex-direction: column; box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1); z-index: 1000; overflow-y: auto; transition: transform 0.3s ease-in-out; }
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

    /* --- MAIN CONTENT --- */
    .main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; transition: margin-left 0.3s ease-in-out; }

    /* --- HEADER (Responsive) --- */
    .header { position: relative; display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: #fff; padding: 20px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); min-height: 90px; flex-wrap: wrap; gap: 15px; }
    .header h2 { font-size: 1.5rem; font-weight: 700; color: #1e3a5f; margin: 0; display: flex; align-items: center; gap: 10px; }
    
    .menu-toggle { display: none; font-size: 1.5rem; color: #1e3a5f; cursor: pointer; }

    /* SWITCHER */
    .view-switcher-wrapper { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); z-index: 10; }
    .view-switcher { display: flex; background: #f1f3f5; padding: 5px; border-radius: 12px; gap: 5px; }
    .switch-btn { padding: 8px 16px; border: none; border-radius: 8px; font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; background: transparent; color: #7f8c8d; display: flex; align-items: center; gap: 6px; white-space: nowrap; }
    .switch-btn.active { background: #fff; color: #1e3a5f; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .switch-btn:hover:not(.active) { background: rgba(0,0,0,0.05); }

    /* Buttons & Inputs */
    .header-right { display: flex; justify-content: flex-end; }
    .add-btn { padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; border: none; border-radius: 10px; font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
    .add-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4); }

    .search-bar { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
    .form-control { padding: 10px 15px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 0.9rem; }
    .btn-search { padding: 10px 20px; background: #667eea; color: #fff; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; }
    .btn-reset { padding: 10px 20px; background: #6c757d; color: #fff; border: none; border-radius: 10px; cursor: pointer; text-decoration: none; font-weight: 600; display: inline-block; }
    
    .btn-mode { text-decoration: none; padding: 8px 15px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; border: 1px solid #e9ecef; color: #6c757d; display: inline-flex; align-items: center; gap: 5px; margin-right: 5px; transition: 0.3s; }
    .btn-mode:hover { background: #f8f9fa; }
    .btn-mode.active { background: #667eea; color: white; border-color: #667eea; }

    /* Table & Scroll Styles */
    .table-container { background: #fff; border-radius: 15px; padding: 0; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); border: 1px solid #e9ecef; overflow: hidden; margin-top: 10px; }
    .table-responsive { max-height: 65vh; overflow-y: auto; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 900px; }
    table th { background: #1e3a5f; color: #fff; text-align: left; padding: 15px 20px; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; position: sticky; top: 0; z-index: 10; white-space: nowrap; }
    table td { padding: 15px 20px; border-bottom: 1px solid #e9ecef; color: #2c3e50; font-size: 0.9rem; vertical-align: middle; white-space: nowrap; }
    table tbody tr:hover { background: #f8f9fa; }

    /* Badges */
    .badge { padding: 5px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
    .badge-AVAILABLE { background: linear-gradient(135deg, #28a745, #20c997); color: #fff; }
    .badge-OCCUPIED { background: linear-gradient(135deg, #dc3545, #c82333); color: #fff; }
    .badge-RESERVED { background: linear-gradient(135deg, #ffc107, #ff9800); color: #fff; } 
    .badge-group { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: #fff; }
    .badge-room { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: #fff; }
    .badge-status { background: linear-gradient(135deg, #28a745, #20c997); color: #fff; }
    .badge-res-pending { background: #fff3cd; color: #856404; }
    .badge-res-confirmed { background: #d4edda; color: #155724; }

    .btn-view { padding: 6px 12px; background: #667eea; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 0.8rem; }
    .delete-btn { background: #dc3545; color: #fff; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.8rem; }

    /* Modal Styles */
    .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); justify-content: center; align-items: center; backdrop-filter: blur(5px); }
    .modal.show { display: flex; }
    .modal-content { background: #fff; padding: 30px; border-radius: 15px; width: 90%; max-width: 500px; position: relative; max-height: 85vh; overflow-y: auto; box-shadow: 0 15px 50px rgba(0,0,0,0.3); }
    .close-btn { position: absolute; top: 15px; right: 20px; font-size: 1.8rem; color: #7f8c8d; cursor: pointer; }
    
    .modal-form { display: flex; flex-direction: column; gap: 15px; margin-top: 15px; }
    .form-group { display: flex; flex-direction: column; gap: 8px; text-align: left; }
    .form-label { font-weight: 600; color: #1e3a5f; font-size: 0.9rem; }
    .modal-form input, .modal-form select { width: 100%; padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 0.95rem; background: #f8f9fa; }
    .modal-form .save-btn { margin-top: 10px; padding: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-weight: 600; border: none; border-radius: 10px; cursor: pointer; font-size: 1rem; }

    /* OVERLAY */
    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 900; backdrop-filter: blur(2px); }

    /* --- RESPONSIVE CSS --- */
    @media (max-width: 992px) {
        .view-switcher-wrapper { position: static; transform: none; width: 100%; order: 3; margin-top: 10px; overflow-x: auto; }
        .view-switcher { justify-content: center; width: 100%; }
        .header { flex-direction: column; align-items: stretch; height: auto; }
        .header-right { align-self: flex-end; order: 2; }
        .header h2 { order: 1; justify-content: space-between; width: 100%; }
        .menu-toggle { display: block; }
    }

    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); width: 260px; } 
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        .main-content { margin-left: 0; padding: 20px 15px; }
        
        /* Mobile Search Stack */
        .search-bar { flex-direction: column; align-items: stretch; }
        .search-bar form { flex-direction: column; }
        .search-bar input { width: 100%; }
        
        .modal-content { width: 95%; padding: 20px; }
    }
</style>
</head>
<body>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
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
    
    <div class="header">
        <h2>
            <i class="fa-solid fa-bars menu-toggle" onclick="toggleSidebar()"></i>
            <span><i class="fa-solid fa-hotel"></i> Accommodation</span>
        </h2>
        
        <div class="header-right">
            <button class="add-btn" onclick="openRoomModal()" id="addRoomBtn" style="<?= $active_tab == 'rooms' ? 'display:flex' : 'display:none' ?>">
                <i class="fa-solid fa-plus"></i> Add Room
            </button>
        </div>

        <div class="view-switcher-wrapper">
            <div class="view-switcher">
                <button class="switch-btn <?= $active_tab == 'rooms' ? 'active' : '' ?>" onclick="switchView('rooms')" id="btn-rooms">
                    <i class="fa-solid fa-bed"></i> Rooms
                </button>
                <button class="switch-btn <?= $active_tab == 'guests' ? 'active' : '' ?>" onclick="switchView('guests')" id="btn-guests">
                    <i class="fa-solid fa-users"></i> Guests (In-House)
                </button>
                <button class="switch-btn <?= $active_tab == 'reservations' ? 'active' : '' ?>" onclick="switchView('reservations')" id="btn-reservations">
                    <i class="fa-solid fa-calendar-check"></i> Reservations
                </button>
            </div>
        </div>
    </div>

    <div class="table-container" id="rooms-section" style="<?= $active_tab == 'rooms' ? 'display:block' : 'display:none' ?>">
        <h3 style="margin-bottom: 15px; color: #1e3a5f; padding: 15px 20px 0 20px;">Room Management</h3>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th>#</th><th>Room Name</th><th>Type</th><th>Rate (TZS)</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if($rooms_result && $rooms_result->num_rows>0): $i=1; while($row = $rooms_result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><strong><?= htmlspecialchars($row['room_name']) ?></strong></td>
                            <td><?= htmlspecialchars($row['room_type']) ?></td>
                            <td><?= number_format($row['room_rate'],2) ?></td>
                            <td><span class="badge badge-<?= strtoupper($row['status']) ?>"><?= strtoupper($row['status']) ?></span></td>
                            <td>
                                <button class="btn-view" onclick="openRoomModal('<?= $row['room_id'] ?>','<?= $row['room_name'] ?>','<?= $row['room_type'] ?>','<?= $row['room_rate'] ?>')"><i class="fa-solid fa-edit"></i> Edit</button>
                                <button class="delete-btn" onclick="confirmDelete(<?= $row['room_id'] ?>)"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-container" id="guests-section" style="<?= $active_tab == 'guests' ? 'display:block' : 'display:none' ?>">
        <div class="search-bar" style="padding: 15px 20px 0 20px;">
            <div style="display:flex; gap:5px; flex-wrap:wrap;">
                <a href="room_details.php?view=guests&mode=individual" class="btn-mode <?= $mode == 'individual' ? 'active' : '' ?>">
                    <i class="fa-solid fa-user"></i> Individual
                </a>
                <a href="room_details.php?view=guests&mode=group" class="btn-mode <?= $mode == 'group' ? 'active' : '' ?>">
                    <i class="fa-solid fa-building"></i> Group
                </a>
            </div>
            
            <form method="GET" style="display:flex; gap:10px; flex:1; width:100%;">
                <input type="hidden" name="view" value="guests">
                <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
                <input type="text" name="search" class="form-control" placeholder="Search In-House Guests..." value="<?= htmlspecialchars($search) ?>" style="flex:1;">
                <button type="submit" class="btn-search">Search</button>
                <a href="room_details.php?view=guests&mode=<?= $mode ?>" class="btn-reset">Reset</a>
            </form>
        </div>

        <div class="table-responsive" style="margin-top: 15px;">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($mode === 'group'): ?>
                            <th>Company Name</th><th>Leader Name</th><th>Phone</th><th>Total Rooms</th><th>Stay Period</th><th>Total Bill (Night)</th><th>Status</th>
                        <?php else: ?>
                            <th>Name</th><th>Gender</th><th>Phone</th><th>Email</th><th>Country</th><th>Room</th><th>Type</th><th>Rate</th>
                        <?php endif; ?>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(mysqli_num_rows($guests_result) > 0): $i=1; while($row = mysqli_fetch_assoc($guests_result)): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <?php if ($mode === 'group'): ?>
                            <td><strong><?= htmlspecialchars($row['company_name']) ?></strong></td>
                            <td><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></td>
                            <td><?= htmlspecialchars($row['phone']) ?></td>
                            <td><span class="badge badge-group"><?= htmlspecialchars($row['total_rooms']) ?> Rooms</span></td>
                            <td><small><?= date('d/m', strtotime($row['checkin_date'])) ?> - <?= date('d/m', strtotime($row['checkout_date'])) ?></small></td>
                            <td><strong><?= number_format($row['total_group_rate']) ?></strong></td>
                            <td><span class="badge badge-status"><?= htmlspecialchars($row['status']) ?></span></td>
                            <td><button type="button" class="btn-view" onclick="openGuestModal(<?= $row['guest_id_ref'] ?>)">Details</button></td>
                        <?php else: ?>
                            <td><strong><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></strong></td>
                            <td><?= htmlspecialchars($row['gender']) ?></td>
                            <td><?= htmlspecialchars($row['phone']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td><?= htmlspecialchars($row['country']) ?></td>
                            <td><span class="badge badge-room"><?= htmlspecialchars($row['room_name']) ?></span></td>
                            <td><?= htmlspecialchars($row['room_type']) ?></td>
                            <td><strong><?= number_format($row['room_rate'], 2) ?></strong></td>
                            <td><button type="button" class="btn-view" onclick="openGuestModal(<?= $row['guest_id'] ?>)"><i class="fa-solid fa-eye"></i></button></td>
                        <?php endif; ?>
                    </tr>

                    <div class="modal" id="guestModal<?= $mode==='group' ? $row['guest_id_ref'] : $row['guest_id'] ?>">
                        <div class="modal-content">
                            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:15px;">
                                <h3 style="margin:0; font-size:1.3rem;"><?= $mode==='group' ? $row['company_name'] : $row['first_name'].' '.$row['last_name'] ?></h3>
                                <button type="button" style="border:none; background:none; font-size:1.5rem; cursor:pointer;" onclick="closeGuestModal(<?= $mode==='group' ? $row['guest_id_ref'] : $row['guest_id'] ?>)">&times;</button>
                            </div>
                            <div class="modal-body">
                                <?php if($mode === 'group'): ?>
                                    <h6>Leader Info</h6>
                                    <p>Name: <?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></p>
                                    <p>Phone: <?= htmlspecialchars($row['phone']) ?></p>
                                    <p>Email: <?= htmlspecialchars($row['email']) ?></p>
                                    <h6>Booking Info</h6>
                                    <p>Rooms: <?= $row['room_list'] ?></p>
                                    <p>Cars: <?= !empty($row['all_cars']) ? $row['all_cars'] : 'None' ?></p>
                                    <p>Dates: <?= $row['checkin_date'] ?> - <?= $row['checkout_date'] ?></p>
                                <?php else: ?>
                                    <h6>Stay Info</h6>
                                    <p>Check-in: <?= htmlspecialchars($row['checkin_date']) ?> @ <?= htmlspecialchars($row['checkin_time']) ?></p>
                                    <p>Check-out: <?= htmlspecialchars($row['checkout_date']) ?></p>
                                    <p>Room Type: <?= htmlspecialchars($row['room_type']) ?></p>
                                    <p>Rate: <?= number_format($row['room_rate'],2) ?> TZS</p>
                                    <h6>Additional Info</h6>
                                    <p>Resident: <?= htmlspecialchars($row['resident_status']) ?></p>
                                    <p>ID/Passport: <?= htmlspecialchars($row['passport_id'] ?: 'N/A') ?></p>
                                    <p>Expiry: <?= htmlspecialchars($row['passport_expiry'] ?: 'N/A') ?></p>
                                    <p>Car Plate: <?= htmlspecialchars($row['car_plate'] ?: 'N/A') ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; else: ?>
                        <tr><td colspan="9" class="text-center" style="padding: 20px;">No active guests found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-container" id="reservations-section" style="<?= $active_tab == 'reservations' ? 'display:block' : 'display:none' ?>">
        <h3 style="margin-bottom: 15px; color: #1e3a5f; padding: 15px 20px 0 20px;">Future & Pending Reservations</h3>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Guest Details</th>
                        <th>Type</th>
                        <th>Room</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Status</th>
                        <th>Booked By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($reservations_result && $reservations_result->num_rows > 0): ?>
                        <?php while($res = $reservations_result->fetch_assoc()): 
                            $isToday = ($res['check_in'] == date('Y-m-d'));
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($res['first_name'].' '.$res['last_name']) ?></strong><br>
                                <small><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($res['phone_number']) ?></small>
                            </td>
                            <td>
                                <span style="font-size: 0.8rem; background: #e2e6ea; padding: 2px 8px; border-radius: 4px;">
                                    <?= htmlspecialchars($res['booking_type'] ?? 'Individual') ?>
                                </span>
                            </td>
                            <td><strong><?= htmlspecialchars($res['room_name']) ?></strong></td>
                            <td>
                                <?= date('d M Y', strtotime($res['check_in'])) ?>
                                <?php if($isToday) echo ' <span style="color:red; font-weight:bold; font-size:0.75rem;">(TODAY)</span>'; ?>
                            </td>
                            <td><?= date('d M Y', strtotime($res['check_out'])) ?></td>
                            <td>
                                <span class="badge <?= $res['status'] == 'Confirmed' ? 'badge-res-confirmed' : 'badge-res-pending' ?>">
                                    <?= $res['status'] ?>
                                </span>
                            </td>
                            <td><small><?= htmlspecialchars($res['booked_by']) ?></small></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center" style="padding:40px;">No pending or future reservations found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<div class="modal" id="roomModal">
    <div class="modal-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 id="roomModalTitle" style="margin:0;">Add Room</h3>
            <span class="close-btn" onclick="closeRoomModal()">&times;</span>
        </div>
        
        <form method="POST" class="modal-form">
            <input type="hidden" name="action" value="save_room">
            <input type="hidden" name="room_id" id="room_id">

            <div class="form-group">
                <label class="form-label">Room Name / Number</label>
                <input type="text" name="room_name" id="room_name" placeholder="e.g. 101, A1" required>
            </div>

            <div class="form-group">
                <label class="form-label">Room Type</label>
                <select name="room_type" id="room_type" required>
                    <option value="">Select Room Type</option>
                    <option value="DOUBLE">Double</option>
                    <option value="TRIPLE">Triple</option>
                    <option value="TWIN">Twin</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Rate per Night (TZS)</label>
                <input type="number" step="0.01" name="room_rate" id="room_rate" placeholder="0.00" required>
            </div>

            <button type="submit" class="save-btn">Save Room</button>
        </form>
    </div>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('active');
    document.querySelector('.sidebar-overlay').classList.toggle('active');
}

function switchView(view) {
    const roomsSec = document.getElementById('rooms-section');
    const guestsSec = document.getElementById('guests-section');
    const resSec = document.getElementById('reservations-section');
    
    const btnRooms = document.getElementById('btn-rooms');
    const btnGuests = document.getElementById('btn-guests');
    const btnRes = document.getElementById('btn-reservations');
    
    const addBtn = document.getElementById('addRoomBtn');

    // Hide All
    roomsSec.style.display = 'none';
    guestsSec.style.display = 'none';
    resSec.style.display = 'none';
    
    btnRooms.classList.remove('active');
    btnGuests.classList.remove('active');
    btnRes.classList.remove('active');

    // Show Selected
    if (view === 'rooms') {
        roomsSec.style.display = 'block';
        btnRooms.classList.add('active');
        addBtn.style.display = 'flex';
    } else if (view === 'guests') {
        guestsSec.style.display = 'block';
        btnGuests.classList.add('active');
        addBtn.style.display = 'none';
    } else if (view === 'reservations') {
        resSec.style.display = 'block';
        btnRes.classList.add('active');
        addBtn.style.display = 'none';
    }
}

function openRoomModal(id='', name='', type='', rate=''){
    document.getElementById('room_id').value=id;
    document.getElementById('room_name').value=name;
    document.getElementById('room_type').value=type;
    document.getElementById('room_rate').value=rate;
    document.getElementById('roomModalTitle').innerText = id ? 'Edit Room' : 'Add Room';
    document.getElementById('roomModal').classList.add('show');
}
function closeRoomModal(){ 
    document.getElementById('roomModal').classList.remove('show'); 
}

function openGuestModal(id) {
    document.getElementById('guestModal' + id).classList.add('show');
}
function closeGuestModal(id) {
    document.getElementById('guestModal' + id).classList.remove('show');
}

function confirmDelete(id){
    Swal.fire({
        title: 'Delete Room?',
        text: 'Action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete'
    }).then((result)=>{
        if(result.isConfirmed) window.location.href='room_details.php?delete_id='+id;
    });
}

window.onclick = function(e){
    if(e.target.classList.contains('modal')) {
        e.target.classList.remove('show');
    }
}

document.addEventListener("DOMContentLoaded", ()=>{
    if(sessionStorage.getItem('roomSaved')){
        Swal.fire({icon:'success', title:'Success', text:'Room saved!', timer:1500, showConfirmButton:false});
        sessionStorage.removeItem('roomSaved');
    }
    if(sessionStorage.getItem('roomDeleted')){
        Swal.fire({icon:'success', title:'Deleted', text:'Room deleted!', timer:1500, showConfirmButton:false});
        sessionStorage.removeItem('roomDeleted');
    }
});
</script>
</body>
</html>