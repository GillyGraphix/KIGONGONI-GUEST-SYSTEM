<?php
session_start();
include 'db_connect.php';

// Security: Only receptionist
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

$currentPage = basename($_SERVER['PHP_SELF']);

// 1. DETERMINE MODE (Individual, Group, or History)
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'individual';
$search = $_GET['search'] ?? '';

// 2. CONSTRUCT QUERY
if ($mode === 'history') {
    // --- HISTORY VIEW: SHOW ALL CHECKED-OUT GUESTS ---
    $query = "SELECT * FROM guest WHERE status = 'Checked-out'";

    if (!empty($search)) {
        $query .= " AND (first_name LIKE '%$search%' OR last_name LIKE '%$search%' OR phone LIKE '%$search%' OR company_name LIKE '%$search%')";
    }
    $query .= " ORDER BY checkout_date DESC"; 

} elseif ($mode === 'group') {
    // --- GROUP VIEW (CURRENT) ---
    $query = "SELECT 
                min(guest_id) as guest_id_ref,
                company_name, first_name, last_name, phone, email,
                checkin_date, checkin_time, checkout_date, checkout_time, status,
                company_address, car_available, car_plate,
                COUNT(*) as total_rooms,
                GROUP_CONCAT(room_name ORDER BY room_name ASC SEPARATOR ', ') as room_list,
                GROUP_CONCAT(DISTINCT room_type SEPARATOR ', ') as type_list,
                SUM(room_rate) as total_group_rate,
                GROUP_CONCAT(DISTINCT car_plate SEPARATOR ', ') as all_cars
              FROM guest 
              WHERE booking_type = 'group' AND status != 'Checked-out' "; 
              
    if (!empty($search)) {
        $query .= " AND (company_name LIKE '%$search%' OR first_name LIKE '%$search%' OR last_name LIKE '%$search%')";
    }
    $query .= " GROUP BY company_name, checkin_date ORDER BY checkin_date DESC";

} else {
    // --- INDIVIDUAL VIEW (CURRENT) ---
    $query = "SELECT * FROM guest WHERE booking_type = 'individual' AND status != 'Checked-out'";
    
    if (!empty($search)) {
        $query .= " AND (first_name LIKE '%$search%' OR last_name LIKE '%$search%' OR phone LIKE '%$search%' OR room_name LIKE '%$search%')";
    }
    $query .= " ORDER BY guest_id DESC";
}

$result = mysqli_query($conn, $query);
if (!$result) die("Query Error: " . mysqli_error($conn));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>View Guests - Hotel Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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

    /* Header & Mode Switcher */
    .header { margin-bottom: 35px; background: #fff; padding: 25px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; }
    .header-title { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; display: flex; align-items: center; gap: 10px; }
    .menu-toggle { display: none; font-size: 1.5rem; color: #1e3a5f; cursor: pointer; }

    .mode-switcher { display: flex; gap: 10px; flex-wrap: wrap; }
    .btn-mode { text-decoration: none; padding: 10px 20px; border-radius: 12px; font-size: 0.9rem; font-weight: 600; transition: 0.3s; border: 2px solid #e9ecef; color: #6c757d; display: flex; align-items: center; gap: 8px; }
    .btn-mode:hover { background: #f8f9fa; transform: translateY(-2px); }
    .btn-mode.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-color: transparent; box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3); }
    .btn-mode.active-history { background: #7f8c8d; color: white; border-color: #7f8c8d; }

    /* Search Card */
    .search-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); margin-bottom: 25px; }
    .search-form { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
    .form-group { flex: 1; min-width: 200px; }
    .form-label { font-weight: 600; margin-bottom: 8px; color: #2c3e50; font-size: 0.9rem; display: block; }
    .form-control { width: 100%; padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 0.95rem; transition: all 0.3s ease; background: #f8f9fa; }
    .form-control:focus { outline: none; border-color: #667eea; background: #fff; }
    
    .btn-search, .btn-reset { padding: 12px 24px; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; }
    .btn-search { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
    .btn-search:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4); }
    .btn-reset { background: #6c757d; color: #fff; text-decoration: none; }
    .btn-reset:hover { background: #5a6268; }

    /* Table Container */
    .table-container { background: #fff; border-radius: 15px; padding: 0; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); overflow: hidden; border: 1px solid #e9ecef; }
    .table-responsive { overflow-x: auto; max-height: 70vh; overflow-y: auto; }
    
    table { width: 100%; border-collapse: collapse; min-width: 900px; }
    table th { background: #1e3a5f; color: #fff; text-align: left; padding: 18px 20px; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
    table td { padding: 18px 20px; border-bottom: 1px solid #e9ecef; color: #2c3e50; vertical-align: middle; white-space: nowrap; font-size: 0.9rem; }
    table tbody tr:hover { background: #f8f9fa; }

    /* Badges & Buttons */
    .badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; text-transform: uppercase; }
    .badge-room { background: #e3f2fd; color: #1565c0; }
    .badge-group { background: #f3e5f5; color: #7b1fa2; }
    .badge-status { background: #e8f5e9; color: #2e7d32; }
    .badge-out { background: #eceff1; color: #546e7a; }

    .btn-view { padding: 8px 16px; background: #667eea; color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; font-size: 0.85rem; display: flex; align-items: center; gap: 5px; }
    .btn-view:hover { background: #5a67d8; transform: translateY(-2px); }

    /* Modal */
    .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(5px); justify-content: center; align-items: center; padding: 20px; }
    .modal.show { display: flex; }
    .modal-content { background: #fff; border-radius: 15px; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3); width: 100%; max-width: 550px; max-height: 90vh; overflow-y: auto; display: flex; flex-direction: column; animation: slideIn 0.3s ease; }
    @keyframes slideIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    
    .modal-header { padding: 20px 25px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; background: #fff; position: sticky; top: 0; z-index: 5; }
    .modal-title { font-size: 1.1rem; font-weight: 700; color: #1e3a5f; margin: 0; }
    .btn-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #7f8c8d; }
    .modal-body { padding: 25px; overflow-y: auto; }
    .modal-body h6 { font-size: 0.9rem; font-weight: 700; color: #1e3a5f; margin: 20px 0 10px 0; padding-bottom: 5px; border-bottom: 2px solid #f0f2f5; text-transform: uppercase; letter-spacing: 0.5px; }
    .modal-body p { margin: 8px 0; font-size: 0.95rem; color: #2c3e50; display: flex; justify-content: space-between; }
    .modal-footer { padding: 20px 25px; border-top: 1px solid #e9ecef; display: flex; justify-content: flex-end; background: #fff; position: sticky; bottom: 0; }
    .btn-secondary { padding: 10px 24px; background: #6c757d; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 600; }

    /* Mobile Overlay */
    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 900; }

    /* Responsive */
    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); width: 250px; }
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        .main-content { margin-left: 0; padding: 20px 15px; }
        .header { flex-direction: column; align-items: flex-start; gap: 20px; }
        .menu-toggle { display: block; }
        .search-form { flex-direction: column; align-items: stretch; }
        .btn-search, .btn-reset { width: 100%; justify-content: center; }
        .mode-switcher { width: 100%; overflow-x: auto; padding-bottom: 5px; }
        .btn-mode { white-space: nowrap; flex: 0 0 auto; }
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
            <span><i class="fa-solid fa-users"></i> Guest List</span>
        </div>
        
        <div class="mode-switcher">
            <a href="view_guests.php?mode=individual" class="btn-mode <?= $mode == 'individual' ? 'active' : '' ?>">
                <i class="fa-solid fa-user"></i> In House (Individual)
            </a>
            <a href="view_guests.php?mode=group" class="btn-mode <?= $mode == 'group' ? 'active' : '' ?>">
                <i class="fa-solid fa-building"></i> In House (Group)
            </a>
            <a href="view_guests.php?mode=history" class="btn-mode <?= $mode == 'history' ? 'active active-history' : '' ?>">
                <i class="fa-solid fa-clock-rotate-left"></i> History / Archives
            </a>
        </div>
    </div>

    <div class="search-card">
        <form method="GET" class="search-form">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
            <div class="form-group">
                <label class="form-label">
                    <?php 
                        if($mode == 'group') echo "Search Company Name";
                        elseif($mode == 'history') echo "Search Past Guests (Name/Company)";
                        else echo "Search Guest Name";
                    ?>
                </label>
                <input type="text" name="search" class="form-control" placeholder="Type here to search..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn-search"><i class="fa-solid fa-search"></i> Search</button>
            <a href="view_guests.php?mode=<?= $mode ?>" class="btn-reset"><i class="fa-solid fa-rotate-right"></i> Reset</a>
        </form>
    </div>

    <div class="table-container">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ($mode === 'group'): ?>
                            <th>Company Name</th>
                            <th>Leader Name</th>
                            <th>Total Rooms</th>
                            <th>Room List</th>
                            <th>Total Rate</th>
                            <th>Status</th>
                        <?php elseif ($mode === 'history'): ?>
                            <th>Full Name / Company</th>
                            <th>Phone</th>
                            <th>Room</th>
                            <th>Check-In</th>
                            <th>Check-Out</th>
                            <th>Status</th>
                        <?php else: ?>
                            <th>Full Name</th>
                            <th>Gender</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Country</th>
                            <th>Room Name</th>
                            <th>Room Type</th>
                            <th>Rate</th>
                        <?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(mysqli_num_rows($result) > 0): $i=1; while($row = mysqli_fetch_assoc($result)): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            
                            <?php if ($mode === 'group'): ?>
                                <td><strong><?= htmlspecialchars($row['company_name']) ?></strong></td>
                                <td><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></td>
                                <td><span class="badge badge-group"><?= htmlspecialchars($row['total_rooms']) ?> Rooms</span></td>
                                <td><small><?= htmlspecialchars($row['room_list']) ?></small></td>
                                <td><strong><?= number_format($row['total_group_rate']) ?></strong></td>
                                <td><span class="badge badge-status"><?= htmlspecialchars($row['status']) ?></span></td>
                                <td>
                                    <button type="button" class="btn-view" onclick="openModal(<?= $row['guest_id_ref'] ?>)">
                                        <i class="fa-solid fa-list"></i> Details
                                    </button>
                                </td>

                            <?php elseif ($mode === 'history'): ?>
                                <td>
                                    <strong><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></strong>
                                    <?php if($row['company_name']) echo "<br><small style='color:#7f8c8d'>".htmlspecialchars($row['company_name'])."</small>"; ?>
                                </td>
                                <td><?= htmlspecialchars($row['phone']) ?></td>
                                <td><span class="badge badge-room"><?= htmlspecialchars($row['room_name']) ?></span></td>
                                <td><?= date('d/m/Y', strtotime($row['checkin_date'])) ?></td>
                                <td><?= date('d/m/Y', strtotime($row['checkout_date'])) ?></td>
                                <td><span class="badge badge-out">Checked-out</span></td>
                                <td>
                                    <button type="button" class="btn-view" style="background:#7f8c8d;" onclick="openModal(<?= $row['guest_id'] ?>)">
                                        <i class="fa-solid fa-history"></i> History
                                    </button>
                                </td>

                            <?php else: ?>
                                <td><strong><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></strong></td>
                                <td><?= htmlspecialchars($row['gender']) ?></td>
                                <td><?= htmlspecialchars($row['phone']) ?></td>
                                <td><?= htmlspecialchars($row['email']) ?></td>
                                <td><?= htmlspecialchars($row['country']) ?></td>
                                <td><span class="badge badge-room"><?= htmlspecialchars($row['room_name']) ?></span></td>
                                <td><?= htmlspecialchars($row['room_type']) ?></td>
                                <td><strong><?= number_format($row['room_rate'], 2) ?></strong></td>
                                <td>
                                    <button type="button" class="btn-view" onclick="openModal(<?= $row['guest_id'] ?>)">
                                        <i class="fa-solid fa-eye"></i> View
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>

                        <div class="modal" id="guestModal<?= $mode==='group' ? $row['guest_id_ref'] : $row['guest_id'] ?>">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <?php if($mode === 'group'): ?>
                                            <i class="fa-solid fa-building"></i> Group: <?= htmlspecialchars($row['company_name']) ?>
                                        <?php else: ?>
                                            <i class="fa-solid fa-user-circle"></i> Guest: <?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?>
                                        <?php endif; ?>
                                    </h5>
                                    <button type="button" class="btn-close" onclick="closeModal(<?= $mode==='group' ? $row['guest_id_ref'] : $row['guest_id'] ?>)">&times;</button>
                                </div>
                                <div class="modal-body">
                                    <?php if($mode === 'group'): ?>
                                        <h6><i class="fa-solid fa-user-tie"></i> Leader Information</h6>
                                        <p><span>Name:</span> <strong><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></strong></p>
                                        <p><span>Phone:</span> <strong><?= htmlspecialchars($row['phone']) ?></strong></p>
                                        <p><span>Email:</span> <strong><?= htmlspecialchars($row['email']) ?></strong></p>

                                        <h6><i class="fa-solid fa-bed"></i> Booking Summary</h6>
                                        <p><span>Total Rooms:</span> <strong><?= $row['total_rooms'] ?></strong></p>
                                        <p><span>Room Numbers:</span> <strong><?= $row['room_list'] ?></strong></p>
                                        <p><span>Room Types:</span> <strong><?= $row['type_list'] ?></strong></p>

                                        <h6><i class="fa-solid fa-calendar"></i> Dates</h6>
                                        <p><span>Check-in:</span> <strong><?= $row['checkin_date'] ?></strong></p>
                                        <p><span>Check-out:</span> <strong><?= $row['checkout_date'] ?></strong></p>

                                    <?php else: ?>
                                        <h6><i class="fa-solid fa-passport"></i> Identification</h6>
                                        <p><span>ID/Passport:</span> <strong><?= htmlspecialchars($row['passport_id'] ?: 'N/A') ?></strong></p>
                                        <p><span>Country:</span> <strong><?= htmlspecialchars($row['country'] ?: 'N/A') ?></strong></p>

                                        <h6><i class="fa-solid fa-bed"></i> Stay Information</h6>
                                        <p><span>Room:</span> <strong><?= htmlspecialchars($row['room_name']) ?></strong> (<?= htmlspecialchars($row['room_type']) ?>)</p>
                                        <p><span>Rate:</span> <strong>TZS <?= number_format($row['room_rate']) ?></strong></p>
                                        <p><span>Check-in:</span> <strong><?= htmlspecialchars($row['checkin_date']) ?></strong></p>
                                        <p><span>Check-out:</span> <strong><?= htmlspecialchars($row['checkout_date']) ?></strong></p>

                                        <h6><i class="fa-solid fa-info-circle"></i> Other Info</h6>
                                        <p><span>Phone:</span> <strong><?= htmlspecialchars($row['phone']) ?></strong></p>
                                        <p><span>Email:</span> <strong><?= htmlspecialchars($row['email']) ?></strong></p>
                                        <?php if($row['company_name']): ?>
                                            <p><span>Company:</span> <strong><?= htmlspecialchars($row['company_name']) ?></strong></p>
                                        <?php endif; ?>
                                        <?php if($row['car_plate']): ?>
                                            <p><span>Car Plate:</span> <strong><?= htmlspecialchars($row['car_plate']) ?></strong></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn-secondary" onclick="closeModal(<?= $mode==='group' ? $row['guest_id_ref'] : $row['guest_id'] ?>)">Close</button>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; else: ?>
                        <tr><td colspan="11" style="text-align:center; padding: 30px; color: #7f8c8d;">No records found matching your criteria.</td></tr>
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

function openModal(id) {
    document.getElementById('guestModal' + id).classList.add('show');
}
function closeModal(id) {
    document.getElementById('guestModal' + id).classList.remove('show');
}
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

</body>
</html>