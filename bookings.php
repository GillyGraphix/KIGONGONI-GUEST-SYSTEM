<?php
ob_start();
session_start();
include 'db_connect.php';

// Security: Check login and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

// Session timeout
$timeout_duration = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

$fullname = $_SESSION['fullname'];
$currentPage = basename($_SERVER['PHP_SELF']);

// --- 1. DATABASE SYNC ---
$checkCol = $conn->query("SHOW COLUMNS FROM bookings LIKE 'booking_type'");
if ($checkCol->num_rows == 0) {
    $conn->query("ALTER TABLE bookings ADD COLUMN booking_type ENUM('Individual', 'Group') DEFAULT 'Individual' AFTER phone_number");
}

$conn->query("CREATE TABLE IF NOT EXISTS `bookings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `phone_number` VARCHAR(20) NOT NULL,
  `booking_type` ENUM('Individual', 'Group') DEFAULT 'Individual',
  `room_name` VARCHAR(100) NOT NULL,
  `check_in` DATE NOT NULL,
  `check_out` DATE NOT NULL,
  `arrival_time` TIME DEFAULT NULL,
  `status` ENUM('Pending', 'Confirmed', 'Checked-in', 'Cancelled') DEFAULT 'Pending',
  `booked_by` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// --- 2. INTELLIGENT ROOM UPDATER ---
$today = date('Y-m-d');
$auto_reserve = $conn->query("SELECT room_name FROM bookings WHERE status = 'Confirmed' AND check_in <= '$today' AND check_out > '$today'");
while($row = $auto_reserve->fetch_assoc()) {
    $r_name = $row['room_name'];
    $conn->query("UPDATE rooms SET status = 'Reserved' WHERE room_name = '$r_name' AND status = 'Available'");
}

// --- 3. LOGIC: CONFIRM BOOKING ---
if (isset($_GET['action']) && $_GET['action'] == 'confirm' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    $qry = $conn->query("SELECT room_name, check_in FROM bookings WHERE id = $id");
    if($row = $qry->fetch_assoc()){
        $roomToReserve = $row['room_name'];
        $checkInDate = $row['check_in'];
        $todayDate = date('Y-m-d');
        
        $conn->query("UPDATE bookings SET status = 'Confirmed' WHERE id = $id");

        if ($checkInDate <= $todayDate) {
            $conn->query("UPDATE rooms SET status = 'Reserved' WHERE room_name = '$roomToReserve' AND status != 'Occupied'");
            $msg = "Payment Received! Room is Reserved for TODAY.";
        } else {
            $msg = "Payment Received! Room remains Available until Check-in date.";
        }
    }
    header("Location: bookings.php?success=$msg");
    exit();
}

// --- 4. LOGIC: DELETE / CANCEL BOOKING ---
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    $res = $conn->query("SELECT room_name FROM bookings WHERE id = $id");
    if ($row = $res->fetch_assoc()) {
        $room = $row['room_name'];
        $conn->query("UPDATE rooms SET status = 'Available' WHERE room_name = '$room' AND status = 'Reserved'");
    }
    $conn->query("DELETE FROM bookings WHERE id = $id");
    header("Location: bookings.php?success=Reservation cancelled successfully.");
    exit();
}

// --- 5. LOGIC: ADD NEW BOOKING ---
if (isset($_POST['add_booking'])) {
    $f_name = mysqli_real_escape_string($conn, $_POST['first_name']);
    $l_name = mysqli_real_escape_string($conn, $_POST['last_name']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone_number']);
    $b_type = mysqli_real_escape_string($conn, $_POST['booking_type']);
    $check_in = mysqli_real_escape_string($conn, $_POST['check_in']);
    $check_out = mysqli_real_escape_string($conn, $_POST['check_out']);
    $arrival_time = !empty($_POST['arrival_time']) ? mysqli_real_escape_string($conn, $_POST['arrival_time']) : NULL;
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $user = $_SESSION['username'];

    // LOGIC YA KUCHAGUA VYUMBA (SINGLE vs MULTI)
    $rooms_selected = array();

    if ($b_type == 'Individual') {
        // Tumia Single Select
        if (!empty($_POST['room_single'])) {
            $rooms_selected[] = $_POST['room_single'];
        }
    } else {
        // Tumia Checkboxes (Group)
        if (!empty($_POST['room_group'])) {
            $rooms_selected = $_POST['room_group']; // Hii ni Array tayari
        }
    }

    $booked_count = 0;
    
    if (empty($rooms_selected)) {
        $error = "Please select at least one room.";
    } else {
        foreach ($rooms_selected as $room_name) {
            $room_name = mysqli_real_escape_string($conn, $room_name);
            
            $sql = "INSERT INTO bookings (first_name, last_name, phone_number, booking_type, room_name, check_in, check_out, arrival_time, status, booked_by) 
                    VALUES ('$f_name', '$l_name', '$phone', '$b_type', '$room_name', '$check_in', '$check_out', '$arrival_time', '$status', '$user')";
            
            if ($conn->query($sql)) {
                $booked_count++;
                $todayDate = date('Y-m-d');
                
                if ($status == 'Confirmed' && $check_in <= $todayDate) {
                    $conn->query("UPDATE rooms SET status = 'Reserved' WHERE room_name = '$room_name'");
                } 
            }
        }
        if ($booked_count > 0) {
            $success = "Successfully secured booking for $booked_count room(s).";
        }
    }
}

// Fetch Rooms Data
$roomsResult = $conn->query("SELECT room_name FROM rooms WHERE status='Available'");
$availableRoomsArray = [];
while($r = $roomsResult->fetch_assoc()) {
    $availableRoomsArray[] = $r;
}

// Fetch Active Bookings
$active_bookings = $conn->query("SELECT * FROM bookings WHERE status NOT IN ('Checked-in', 'Cancelled') ORDER BY check_in ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reservations - Hotel Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    /* STANDARD DASHBOARD STYLES */
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; overflow-x: hidden; }

    /* Custom Scrollbar */
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: #f1f1f1; }
    ::-webkit-scrollbar-thumb { background: #1e3a5f; border-radius: 3px; }

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

    /* Main Content */
    .main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; transition: margin-left 0.3s ease-in-out; }
    .header { margin-bottom: 35px; background: #fff; padding: 25px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; align-items: center; gap: 15px; }
    .welcome { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; }
    .menu-toggle { display: none; font-size: 1.5rem; color: #1e3a5f; cursor: pointer; }

    /* Booking Grid Layout */
    .booking-grid { display: grid; grid-template-columns: 1fr 380px; gap: 30px; align-items: start; }
    
    .card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); }
    .card h3 { color: #1e3a5f; margin-bottom: 20px; font-size: 1.1rem; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; }

    /* Table Styles */
    .table-responsive { overflow-x: auto; max-height: 700px; border: 1px solid #eee; border-radius: 10px; }
    table { width: 100%; border-collapse: collapse; min-width: 700px; }
    th { text-align: left; padding: 15px; background: #1e3a5f; color: #fff; font-size: 0.8rem; text-transform: uppercase; position: sticky; top: 0; z-index: 10; white-space: nowrap; }
    td { padding: 15px; border-bottom: 1px solid #eee; font-size: 0.9rem; vertical-align: middle; color: #34495e; }
    tr:hover { background: #f8f9fa; }

    /* Actions & Badges */
    .action-buttons { display: flex; align-items: center; gap: 5px; }
    .btn-action { width: 32px; height: 32px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; color: white; text-decoration: none; transition: 0.3s; font-size: 0.9rem; }
    .btn-action:hover { transform: translateY(-2px); }
    .btn-checkin { background: #28a745; }
    .btn-cancel { background: #dc3545; }
    .btn-confirm { background: #17a2b8; } 

    .badge { padding: 5px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-confirmed { background: #d4edda; color: #155724; }

    /* Form Styles */
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.85rem; color: #34495e; }
    .form-control { width: 100%; padding: 10px 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 0.9rem; transition: 0.3s; background: #f9f9f9; }
    .form-control:focus { outline: none; border-color: #667eea; background: white; }
    
    .btn-primary { width: 100%; padding: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.3s; margin-top: 10px; }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }

    /* Checkbox Group Container */
    .checkbox-container { display: none; border: 2px solid #e0e0e0; border-radius: 8px; padding: 10px; max-height: 150px; overflow-y: auto; background: #fff; }
    .checkbox-item { display: flex; align-items: center; padding: 6px 0; border-bottom: 1px solid #f1f1f1; }
    .checkbox-item:last-child { border-bottom: none; }
    .checkbox-item input { width: 16px; height: 16px; margin-right: 10px; accent-color: #667eea; }
    .checkbox-item label { margin: 0; font-weight: 500; cursor: pointer; font-size: 0.85rem; flex: 1; }

    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 900; }
    
    @media (max-width: 1024px) { .booking-grid { grid-template-columns: 1fr; } .card { margin-bottom: 20px; } }
    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); width: 250px; }
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        .main-content { margin-left: 0; padding: 20px 15px; }
        .menu-toggle { display: block; }
        .header { flex-direction: column; align-items: flex-start; gap: 10px; }
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
        <a href="rooms.php" class="<?= $currentPage=='rooms.php'?'active':'' ?>"><i class="fa-solid fa-bed"></i> <span>Rooms </span></a>
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
        <div style="display: flex; align-items: center; gap: 15px;">
            <i class="fa-solid fa-bars menu-toggle" onclick="toggleSidebar()"></i>
            <div class="welcome"><i class="fa-solid fa-calendar-check"></i> Room <span>Reservations</span></div>
        </div>
    </div>

    <div class="booking-grid">
        <div class="card">
            <h3><i class="fa-solid fa-list"></i> Upcoming Arrivals</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Guest</th>
                            <th>Type</th>
                            <th>Room</th>
                            <th>Check-in</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($active_bookings && $active_bookings->num_rows > 0): ?>
                            <?php while($row = $active_bookings->fetch_assoc()): ?>
                            <tr style="<?= ($row['check_in'] == date('Y-m-d')) ? 'background-color: #f0f7ff;' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></strong><br>
                                    <small style="color: #7f8c8d;"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($row['phone_number']) ?></small>
                                </td>
                                <td>
                                    <span style="font-size: 0.75rem; background: #e2e6ea; padding: 2px 8px; border-radius: 4px; color: #495057;">
                                        <?= htmlspecialchars($row['booking_type'] ?? 'Individual') ?>
                                    </span>
                                </td>
                                <td><span style="font-weight: 700; color: #1e3a5f;"><?= htmlspecialchars($row['room_name']) ?></span></td>
                                <td>
                                    <?= date('d M', strtotime($row['check_in'])) ?>
                                    <?php if($row['check_in'] == date('Y-m-d')) echo ' <span style="color:#dc3545; font-weight:bold; font-size:0.7rem;">(TODAY)</span>'; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $row['status'] == 'Confirmed' ? 'status-confirmed' : 'status-pending' ?>">
                                        <?= $row['status'] ?>
                                    </span>
                                    <?php if(!empty($row['arrival_time']) && $row['arrival_time'] != '00:00:00'): ?>
                                        <div style="font-size: 0.7rem; color: #666; margin-top: 2px;"><i class="fa-regular fa-clock"></i> <?= date('h:i A', strtotime($row['arrival_time'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if($row['status'] == 'Pending'): ?>
                                            <a href="bookings.php?action=confirm&id=<?= $row['id'] ?>" class="btn-action btn-confirm" title="Confirm Payment" onclick="return confirm('Confirm payment received?')">
                                                <i class="fa-solid fa-money-bill-wave"></i>
                                            </a>
                                        <?php endif; ?>

                                        <a href="add_guest.php?booking_id=<?= $row['id'] ?>&type=<?= $row['booking_type'] ?>" class="btn-action btn-checkin" title="Check-in Now">
                                            <i class="fa-solid fa-check"></i>
                                        </a>

                                        <a href="bookings.php?delete_id=<?= $row['id'] ?>" class="btn-action btn-cancel" onclick="return confirm('Cancel this reservation?')" title="Cancel">
                                            <i class="fa-solid fa-xmark"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" align="center" style="color: #95a5a6; padding: 40px;">No upcoming reservations found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3><i class="fa-solid fa-plus-circle"></i> Quick Booking</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Booking Type</label>
                    <select name="booking_type" id="bookingType" class="form-control">
                        <option value="Individual">Individual</option>
                        <option value="Group">Group / Company</option>
                    </select>
                </div>

                <div class="form-group"><label>First Name</label><input type="text" name="first_name" class="form-control" required placeholder="Guest Name"></div>
                <div class="form-group"><label>Last Name</label><input type="text" name="last_name" class="form-control" required placeholder="Surname"></div>
                <div class="form-group"><label>Phone Number</label><input type="text" name="phone_number" class="form-control" required placeholder="07..."></div>
                
                <div class="form-group">
                    <label>Assign Room(s)</label>
                    
                    <select name="room_single" id="roomSingle" class="form-control" required>
                        <option value="">Select Room</option>
                        <?php foreach($availableRoomsArray as $room): ?>
                            <option value="<?= htmlspecialchars($room['room_name']) ?>"><?= htmlspecialchars($room['room_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div id="roomGroupContainer" class="checkbox-container">
                        <?php if(empty($availableRoomsArray)): ?>
                            <div style="color:red; font-size:0.8rem; padding: 5px;">No available rooms.</div>
                        <?php else: ?>
                            <?php foreach($availableRoomsArray as $room): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="room_group[]" id="room_<?= $room['room_name'] ?>" value="<?= htmlspecialchars($room['room_name']) ?>">
                                    <label for="room_<?= $room['room_name'] ?>">Room <?= htmlspecialchars($room['room_name']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex: 1;"><label>Arrival</label><input type="date" name="check_in" class="form-control" required min="<?= date('Y-m-d') ?>"></div>
                    <div class="form-group" style="flex: 1;"><label>Departure</label><input type="date" name="check_out" class="form-control" required></div>
                </div>
                
                <div class="form-group"><label>Arrival Time</label><input type="time" name="arrival_time" class="form-control"></div>
                
                <div class="form-group">
                    <label>Initial Status</label>
                    <select name="status" class="form-control">
                        <option value="Pending">Pending (Inquiry)</option>
                        <option value="Confirmed">Confirmed (Paid)</option>
                    </select>
                </div>
                
                <button type="submit" name="add_booking" class="btn-primary"><i class="fa-solid fa-lock"></i> Secure Reservation</button>
            </form>
        </div>
    </div>
</div>

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('active');
        document.querySelector('.sidebar-overlay').classList.toggle('active');
    }

    // TOGGLE ROOM SELECTION MODE
    const typeSelect = document.getElementById('bookingType');
    const singleInput = document.getElementById('roomSingle');
    const groupContainer = document.getElementById('roomGroupContainer');

    typeSelect.addEventListener('change', function() {
        if (this.value === 'Group') {
            singleInput.style.display = 'none';
            singleInput.required = false; 
            singleInput.value = ""; 
            groupContainer.style.display = 'block';
        } else {
            groupContainer.style.display = 'none';
            singleInput.style.display = 'block';
            singleInput.required = true; 
            const checkboxes = groupContainer.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = false);
        }
    });

    <?php if(isset($success) || isset($_GET['success'])): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: '<?= $success ?? $_GET['success'] ?>',
        confirmButtonColor: '#1e3a5f'
    });
    <?php endif; ?>
    
    <?php if(isset($error)): ?>
    Swal.fire({
        icon: 'error',
        title: 'Oops...',
        text: '<?= $error ?>',
        confirmButtonColor: '#dc3545'
    });
    <?php endif; ?>
</script>

</body>
</html>