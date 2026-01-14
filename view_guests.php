<?php
session_start();
include 'db_connect.php';

// Security: Only receptionist
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

$currentPage = basename($_SERVER['PHP_SELF']);

// 1. DETERMINE MODE
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'individual';
$search = $_GET['search'] ?? '';

// 2. CONSTRUCT QUERY
if ($mode === 'group') {
    // --- GROUP VIEW: ONLY SHOW 'group' TYPE ---
    // Hii query haijalishi jina, inaangalia tu kama uli-book 'group'
    $query = "SELECT 
                min(guest_id) as guest_id_ref,
                company_name,
                first_name,
                last_name,
                phone,
                email,
                checkin_date,
                checkin_time,
                checkout_date,
                checkout_time,
                status,
                COUNT(*) as total_rooms,
                GROUP_CONCAT(room_name ORDER BY room_name ASC SEPARATOR ', ') as room_list,
                GROUP_CONCAT(DISTINCT room_type SEPARATOR ', ') as type_list,
                SUM(room_rate) as total_group_rate,
                GROUP_CONCAT(DISTINCT car_plate SEPARATOR ', ') as all_cars
              FROM guest 
              WHERE booking_type = 'group' "; 
              
    if (!empty($search)) {
        $query .= " AND (company_name LIKE '%$search%' OR first_name LIKE '%$search%' OR last_name LIKE '%$search%')";
    }
    
    $query .= " GROUP BY company_name, checkin_date ORDER BY checkin_date DESC";

} else {
    // --- INDIVIDUAL VIEW: ONLY SHOW 'individual' TYPE ---
    // Hii itawaleta hata wale individuals wenye majina ya kampuni
    $query = "SELECT * FROM guest WHERE booking_type = 'individual'";
    
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
/* STYLES ZILE ZILE */
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
.header h2 { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; margin: 0; }
.mode-switcher { display: inline-flex; gap: 10px; margin-left: 20px; vertical-align: middle; }
.btn-mode { text-decoration: none; padding: 8px 20px; border-radius: 20px; font-size: 0.9rem; font-weight: 600; transition: 0.3s; border: 2px solid #e9ecef; color: #6c757d; display: flex; align-items: center; gap: 8px; }
.btn-mode:hover { background: #e9ecef; }
.btn-mode.active { background: #667eea; color: white; border-color: #667eea; box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3); }
.search-card { background: #fff; border-radius: 15px; padding: 25px 30px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); margin-bottom: 25px; }
.search-form { display: flex; gap: 15px; align-items: end; }
.form-group { flex: 1; }
.form-label { font-weight: 600; margin-bottom: 8px; color: #1e3a5f; font-size: 0.9rem; display: block; }
.form-control { width: 100%; padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 0.95rem; transition: all 0.3s ease; background: #f8f9fa; }
.form-control:focus { outline: none; border-color: #667eea; background: #fff; }
.btn-search { padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; }
.btn-search:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4); }
.btn-reset { padding: 12px 24px; background: #6c757d; color: #fff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-block; }
.btn-reset:hover { background: #5a6268; color: #fff; }
.table-container { background: #fff; border-radius: 15px; padding: 30px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); overflow-x: auto; }
table { width: 100%; border-collapse: collapse; border: none; }
table th { background: #1e3a5f; color: #fff; text-align: left; padding: 15px; font-weight: 600; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }
table td { padding: 15px; text-align: left; border-bottom: 1px solid #e9ecef; vertical-align: middle; color: #2c3e50; }
table tbody tr:hover { background: #f8f9fa; }
.badge { padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-block; }
.badge-room { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: #fff; }
.badge-group { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: #fff; }
.badge-status { background: linear-gradient(135deg, #28a745, #20c997); color: #fff; }
.btn-view { padding: 8px 16px; background: #667eea; color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; }
.btn-view:hover { background: #5a67d8; transform: translateY(-2px); }
.modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(5px); overflow-y: auto; }
.modal.show { display: flex; justify-content: center; align-items: center; }
.modal-dialog { width: 90%; max-width: 600px; margin: 50px auto; }
.modal-content { background: #fff; border-radius: 15px; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3); animation: slideIn 0.3s ease; }
@keyframes slideIn { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.modal-header { padding: 25px 30px; border-bottom: 2px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; }
.modal-title { font-size: 1.3rem; font-weight: 700; color: #1e3a5f; }
.btn-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #7f8c8d; }
.modal-body { padding: 30px; }
.modal-body h6 { font-size: 1rem; font-weight: 700; color: #1e3a5f; margin: 20px 0 10px 0; padding-bottom: 8px; border-bottom: 2px solid #667eea; }
.modal-body p { margin: 8px 0; color: #2c3e50; }
.modal-footer { padding: 20px 30px; border-top: 2px solid #e9ecef; display: flex; justify-content: flex-end; }
.btn-secondary { padding: 10px 24px; background: #6c757d; color: #fff; border: none; border-radius: 10px; cursor: pointer; }
@media (max-width: 768px) { .sidebar { width: 70px; } .sidebar-header h2, .sidebar-header p, .sidebar a span { display: none; } .sidebar a { justify-content: center; padding: 14px; } .main-content { margin-left: 70px; padding: 20px; } .search-form { flex-direction: column; } .header { flex-direction: column; align-items: flex-start; gap: 15px; } .mode-switcher { margin-left: 0; } }
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
        <a href="checkin_checkout.php" class="<?= $currentPage=='checkin_checkout.php'?'active':'' ?>"><i class="fa-solid fa-door-open"></i> <span>Check-in / Check-out</span></a>
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
    <div class="header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
        <div style="display: flex; align-items: center;">
            <h2 style="margin: 0;"><i class="fa-solid fa-users"></i> View Guests</h2>
            
            <div class="mode-switcher">
                <a href="view_guests.php?mode=individual" class="btn-mode <?= $mode == 'individual' ? 'active' : '' ?>">
                    <i class="fa-solid fa-user"></i> Individual View
                </a>
                <a href="view_guests.php?mode=group" class="btn-mode <?= $mode == 'group' ? 'active' : '' ?>">
                    <i class="fa-solid fa-building"></i> Group / Company View
                </a>
            </div>
        </div>
    </div>

    <div class="search-card">
        <form method="GET" class="search-form">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
            <div class="form-group">
                <label class="form-label">Search by <?= $mode === 'group' ? 'Company Name or Leader' : 'Name or Phone' ?></label>
                <input type="text" name="search" class="form-control" placeholder="Type here to search..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div>
                <button type="submit" class="btn-search"><i class="fa-solid fa-search"></i> Search</button>
            </div>
            <div>
                <a href="view_guests.php?mode=<?= $mode ?>" class="btn-reset"><i class="fa-solid fa-rotate-right"></i> Reset</a>
            </div>
        </form>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <?php if ($mode === 'group'): ?>
                        <th>Company Name</th>
                        <th>Leader Name</th>
                        <th>Total Rooms</th>
                        <th>Room List</th>
                        <th>Total Rate (Night)</th>
                        <th>Status</th>
                    <?php else: ?>
                        <th>Full Name</th>
                        <th>Gender</th>
                        <th>Resident Status</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Country</th>
                        <th>Room Name</th>
                        <th>Room Type</th>
                        <th>Room Rate (TZS)</th>
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

                        <?php else: ?>
                            <td><strong><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></strong></td>
                            <td><?= htmlspecialchars($row['gender']) ?></td>
                            <td><?= htmlspecialchars($row['resident_status']) ?></td>
                            <td><?= htmlspecialchars($row['phone']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td><?= htmlspecialchars($row['country']) ?></td>
                            <td><span class="badge badge-room"><?= htmlspecialchars($row['room_name']) ?></span></td>
                            <td><?= htmlspecialchars($row['room_type']) ?></td>
                            <td><strong><?= number_format($row['room_rate'], 2) ?></strong></td>
                            <td>
                                <button type="button" class="btn-view" onclick="openModal(<?= $row['guest_id'] ?>)">
                                    <i class="fa-solid fa-eye"></i> View More
                                </button>
                            </td>
                        <?php endif; ?>
                    </tr>

                    <div class="modal" id="guestModal<?= $mode==='group' ? $row['guest_id_ref'] : $row['guest_id'] ?>">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <?php if($mode === 'group'): ?>
                                            <i class="fa-solid fa-building"></i> Group Details: <?= htmlspecialchars($row['company_name']) ?>
                                        <?php else: ?>
                                            <i class="fa-solid fa-user-circle"></i> Guest Details: <?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?>
                                        <?php endif; ?>
                                    </h5>
                                    <button type="button" class="btn-close" onclick="closeModal(<?= $mode==='group' ? $row['guest_id_ref'] : $row['guest_id'] ?>)">&times;</button>
                                </div>
                                <div class="modal-body">
                                    <?php if($mode === 'group'): ?>
                                        <h6><i class="fa-solid fa-user-tie"></i> Leader Information</h6>
                                        <p><strong>Name:</strong> <?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></p>
                                        <p><strong>Phone:</strong> <?= htmlspecialchars($row['phone']) ?></p>
                                        <p><strong>Email:</strong> <?= htmlspecialchars($row['email']) ?></p>

                                        <h6><i class="fa-solid fa-bed"></i> Booking Summary</h6>
                                        <p><strong>Total Rooms:</strong> <?= $row['total_rooms'] ?></p>
                                        <p><strong>Room Numbers:</strong> <?= $row['room_list'] ?></p>
                                        <p><strong>Room Types:</strong> <?= $row['type_list'] ?></p>
                                        <p><strong>Total Rate (Per Night):</strong> TZS <?= number_format($row['total_group_rate']) ?></p>

                                        <h6><i class="fa-solid fa-car"></i> Cars in Convoy</h6>
                                        <p><strong>Plate Numbers:</strong> <?= !empty($row['all_cars']) ? $row['all_cars'] : 'None' ?></p>
                                        
                                        <h6><i class="fa-solid fa-calendar"></i> Dates</h6>
                                        <p><strong>Check-in:</strong> <?= $row['checkin_date'] ?> @ <?= $row['checkin_time'] ?></p>
                                        <p><strong>Check-out:</strong> <?= $row['checkout_date'] ?></p>

                                    <?php else: ?>
                                        <h6><i class="fa-solid fa-passport"></i> Passport / ID Information</h6>
                                        <p><strong>ID/Passport Number:</strong> <?= htmlspecialchars($row['passport_id'] ?: 'N/A') ?></p>
                                        <p><strong>Issuing Country:</strong> <?= htmlspecialchars($row['passport_country'] ?: 'N/A') ?></p>
                                        <p><strong>Expiry Date:</strong> <?= htmlspecialchars($row['passport_expiry'] ?: 'N/A') ?></p>

                                        <h6><i class="fa-solid fa-building"></i> Company Information</h6>
                                        <p><strong>Company Name:</strong> <?= htmlspecialchars($row['company_name'] ?: 'N/A') ?></p>
                                        <p><strong>Company Address:</strong> <?= htmlspecialchars($row['company_address'] ?: 'N/A') ?></p>

                                        <h6><i class="fa-solid fa-car"></i> Car Information</h6>
                                        <p><strong>Car Available:</strong> <?= htmlspecialchars($row['car_available'] ?: 'No') ?></p>
                                        <p><strong>Car Plate Number:</strong> <?= htmlspecialchars($row['car_plate'] ?: 'N/A') ?></p>

                                        <h6><i class="fa-solid fa-calendar-check"></i> Check-in / Check-out</h6>
                                        <p><strong>Check-in Date:</strong> <?= htmlspecialchars($row['checkin_date']) ?></p>
                                        <p><strong>Check-in Time:</strong> <?= htmlspecialchars($row['checkin_time'] ?: 'N/A') ?></p>
                                        <p><strong>Check-out Date:</strong> <?= htmlspecialchars($row['checkout_date'] ?: 'N/A') ?></p>
                                        <p><strong>Check-out Time:</strong> <?= htmlspecialchars($row['checkout_time'] ?: 'N/A') ?></p>
                                        <p><strong>Status:</strong> <span class="badge badge-status"><?= htmlspecialchars($row['status']) ?></span></p>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn-secondary" onclick="closeModal(<?= $mode==='group' ? $row['guest_id_ref'] : $row['guest_id'] ?>)">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endwhile; else: ?>
                    <tr><td colspan="11" class="text-center">No records found for this view.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
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