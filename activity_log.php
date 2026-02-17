<?php
session_start();
include 'db_connect.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: login.php");
    exit();
}

// Session timeout management
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

// --- HANDLE DELETE (Individual) ---
if (isset($_GET['delete_id'])) {
    $log_id = intval($_GET['delete_id']);
    $conn->query("DELETE FROM activity_logs WHERE log_id = $log_id");
    header("Location: activity_log.php?msg=deleted");
    exit();
}

// --- HANDLE BULK DELETE (By Date Range) ---
if (isset($_POST['bulk_delete'])) {
    $del_from = $_POST['del_from'];
    $del_to = $_POST['del_to'];
    if($del_from && $del_to) {
        $stmt = $conn->prepare("DELETE FROM activity_logs WHERE DATE(timestamp) BETWEEN ? AND ?");
        $stmt->bind_param("ss", $del_from, $del_to);
        $stmt->execute();
        header("Location: activity_log.php?msg=bulk_deleted");
        exit();
    }
}

// --- FILTERS ---
$filter_date = $_GET['filter_date'] ?? '';
$filter_user = $_GET['filter_user'] ?? '';
$filter_action = $_GET['filter_action'] ?? '';
$search_term = $_GET['search'] ?? '';

$where_clauses = [];
$params = [];
$types = "";

if ($filter_date) {
    $where_clauses[] = "DATE(timestamp) = ?";
    $params[] = $filter_date;
    $types .= "s";
}

if ($filter_user) {
    $where_clauses[] = "username = ?";
    $params[] = $filter_user;
    $types .= "s";
}

if ($filter_action) {
    $where_clauses[] = "action LIKE ?";
    $params[] = "%$filter_action%";
    $types .= "s";
}

if ($search_term) {
    $where_clauses[] = "(description LIKE ? OR action LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// --- FETCH LOGS ---
$sql = "SELECT * FROM activity_logs $where_sql ORDER BY timestamp DESC LIMIT 100";

$result = null;
if ($types) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $bind_params = array_merge([$types], $params);
        $ref_params = [];
        foreach ($bind_params as $key => $value) {
            $ref_params[$key] = &$bind_params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $ref_params);
        $stmt->execute();
        $result = $stmt->get_result();
    }
} else {
    $result = $conn->query($sql);
}

// Get unique users for filter
$users_query = $conn->query("SELECT DISTINCT username FROM activity_logs ORDER BY username");
$users = [];
if ($users_query) {
    while ($row = $users_query->fetch_assoc()) {
        $users[] = $row['username'];
    }
}

// --- STATISTICS ---
$stats_sql = "SELECT 
                COUNT(*) as total_activities,
                COUNT(DISTINCT username) as unique_users,
                COUNT(CASE WHEN action LIKE '%login%' THEN 1 END) as login_count,
                COUNT(CASE WHEN action LIKE '%guest%' THEN 1 END) as guest_actions,
                COUNT(CASE WHEN action LIKE '%check%in%' THEN 1 END) as checkin_count,
                COUNT(CASE WHEN action LIKE '%check%out%' THEN 1 END) as checkout_count,
                COUNT(CASE WHEN action LIKE '%payment%' THEN 1 END) as payment_count
              FROM activity_logs $where_sql";

$stats = [];
if ($types) {
    $stmt_stats = $conn->prepare($stats_sql);
    if ($stmt_stats) {
        $bind_params = array_merge([$types], $params);
        $ref_params = [];
        foreach ($bind_params as $key => $value) {
            $ref_params[$key] = &$bind_params[$key];
        }
        call_user_func_array([$stmt_stats, 'bind_param'], $ref_params);
        $stmt_stats->execute();
        $res_stats = $stmt_stats->get_result();
        $stats = $res_stats->fetch_assoc();
    }
} else {
    $stats_result = $conn->query($stats_sql);
    $stats = $stats_result ? $stats_result->fetch_assoc() : [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Activity Log - HGMA System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; overflow-x: hidden; }

    /* --- SIDEBAR STYLES (Responsive) --- */
    .sidebar { 
        position: fixed; left: 0; top: 0; width: 260px; height: 100vh; 
        background: #1e3a5f; color: #fff; padding: 30px 0; 
        display: flex; flex-direction: column; 
        box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1); 
        z-index: 1000; overflow-y: auto;
        transition: transform 0.3s ease-in-out; 
    }
    
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
    .logout-btn:hover { background: #c82333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3); }

    /* --- MAIN CONTENT & HEADER --- */
    .main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; transition: margin-left 0.3s ease-in-out; }
    
    .header { margin-bottom: 35px; background: #fff; padding: 20px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; align-items: center; justify-content: space-between; }
    .page-title h1 { font-size: 1.5rem; font-weight: 700; color: #1e3a5f; margin: 0; }
    .page-title p { color: #7f8c8d; font-size: 0.9rem; margin-top: 5px; margin-bottom: 0; }
    
    .menu-toggle { display: none; font-size: 1.5rem; color: #1e3a5f; cursor: pointer; margin-right: 15px; }

    /* --- STATS GRID --- */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background: #fff; border-radius: 15px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px; transition: all 0.3s ease; }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
    .stat-icon { width: 50px; height: 50px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
    .stat-content h4 { font-size: 0.8rem; color: #7f8c8d; font-weight: 500; margin-bottom: 5px; }
    .stat-content p { font-size: 1.4rem; font-weight: 700; color: #1e3a5f; }

    /* Stat Colors */
    .icon-total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
    .icon-users { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: #fff; }
    .icon-login { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: #fff; }
    .icon-guest { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: #fff; }
    .icon-checkin { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: #fff; }
    .icon-checkout { background: linear-gradient(135deg, #30cfd0 0%, #330867 100%); color: #fff; }
    .icon-payment { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333; }

    /* --- FILTERS & FORMS --- */
    .filter-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
    .filter-grid { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .form-group { flex: 1; min-width: 150px; display: flex; flex-direction: column; }
    .form-label { font-weight: 600; margin-bottom: 8px; color: #1e3a5f; font-size: 0.9rem; }
    .form-control { padding: 10px 15px; border: 2px solid #e9ecef; border-radius: 10px; font-size: 0.95rem; background: #f8f9fa; width: 100%; }
    
    .btn-group { display: flex; gap: 10px; }
    .btn-filter { padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; white-space: nowrap; }
    .btn-clear { padding: 10px 20px; background: #6c757d; color: #fff; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; white-space: nowrap; }
    
    /* Bulk Delete Section */
    .bulk-del-card { background: #fff5f5; padding: 15px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #feb2b2; display: flex; flex-wrap: wrap; align-items: center; gap: 15px; }
    .bulk-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

    /* --- TABLE (Responsive) --- */
    .table-container { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 900px; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid #edf2f7; white-space: nowrap; }
    th { background-color: #f8fafc; color: #64748b; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; }
    tbody tr:hover { background-color: #f8fafc; }

    /* Badges */
    .badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; }
    .badge-login { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: #fff; }
    .badge-guest { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: #fff; }
    .badge-checkin { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: #fff; }
    .badge-checkout { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: #fff; }
    .badge-payment { background: linear-gradient(135deg, #28a745, #20c997); color: #fff; }
    .badge-default { background: #e2e3e5; color: #383d41; }
    
    .btn-del { color: #dc3545; background: none; border: none; cursor: pointer; font-size: 1.1rem; }

    /* --- MOBILE OVERLAY --- */
    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 900; backdrop-filter: blur(2px); }

    /* --- RESPONSIVE MEDIA QUERIES --- */
    @media (max-width: 768px) {
        /* Sidebar Logic */
        .sidebar { transform: translateX(-100%); width: 260px; } 
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        
        /* Content Adjustments */
        .main-content { margin-left: 0; padding: 20px 15px; }
        .header { padding: 15px; justify-content: flex-start; gap: 15px; }
        .menu-toggle { display: block; }
        
        /* Stacking Filters */
        .filter-grid { flex-direction: column; align-items: stretch; }
        .btn-group { width: 100%; }
        .btn-filter, .btn-clear { flex: 1; text-align: center; }
        
        /* Stats Grid */
        .stats-grid { grid-template-columns: 1fr 1fr; }
        
        /* Bulk Delete Stacking */
        .bulk-del-card { flex-direction: column; align-items: stretch; }
        .bulk-form { flex-direction: column; width: 100%; }
        .bulk-form input { width: 100%; }
        .bulk-form button { width: 100%; }
        
        .table-container { padding: 15px; }
    }
    
    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr; }
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
        <a href="manager_dashboard.php" class="<?= $currentPage=='manager_dashboard.php'?'active':'' ?>">
            <i class="fa-solid fa-house"></i> <span>Dashboard</span>
        </a>
        <a href="manage_staff.php" class="<?= $currentPage=='manage_staff.php'?'active':'' ?>">
            <i class="fa-solid fa-user-shield"></i> <span>Manage Staff</span>
        </a>
        <a href="room_details.php" class="<?= $currentPage=='room_details.php'?'active':'' ?>">
            <i class="fa-solid fa-bed"></i> <span>Room Details</span>
        </a>
        <a href="payment_reports.php" class="<?= $currentPage=='payment_reports.php'?'active':'' ?>">
            <i class="fa-solid fa-credit-card"></i> <span>Payment Reports</span>
        </a>
        <a href="analytics.php" class="<?= $currentPage=='analytics.php'?'active':'' ?>">
            <i class="fa-solid fa-chart-line"></i> <span>Analytics</span>
        </a>
        <a href="activity_log.php" class="<?= $currentPage=='activity_log.php'?'active':'' ?>">
            <i class="fa-solid fa-clipboard-list"></i> <span>Activity Log</span>
        </a>
        <a href="db_maintenance.php" class="<?= $currentPage=='db_maintenance.php'?'active':'' ?>">
            <i class="fa-solid fa-screwdriver-wrench"></i> <span>Maintenance</span>
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
        <div style="display:flex; align-items:center;">
            <i class="fa-solid fa-bars menu-toggle" onclick="toggleSidebar()"></i>
            <div class="page-title">
                <h1><i class="fa-solid fa-clipboard-list"></i> Activity Log</h1>
                <p>Monitor all system activities</p>
            </div>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon icon-total"><i class="fa-solid fa-list-check"></i></div>
            <div class="stat-content"><h4>Total Activities</h4><p><?= number_format($stats['total_activities'] ?? 0) ?></p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-users"><i class="fa-solid fa-users"></i></div>
            <div class="stat-content"><h4>Active Users</h4><p><?= $stats['unique_users'] ?? 0 ?></p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-login"><i class="fa-solid fa-right-to-bracket"></i></div>
            <div class="stat-content"><h4>Logins</h4><p><?= number_format($stats['login_count'] ?? 0) ?></p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-guest"><i class="fa-solid fa-user-plus"></i></div>
            <div class="stat-content"><h4>Guest Actions</h4><p><?= number_format($stats['guest_actions'] ?? 0) ?></p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-checkin"><i class="fa-solid fa-door-open"></i></div>
            <div class="stat-content"><h4>Check-ins</h4><p><?= number_format($stats['checkin_count'] ?? 0) ?></p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-checkout"><i class="fa-solid fa-door-closed"></i></div>
            <div class="stat-content"><h4>Check-outs</h4><p><?= number_format($stats['checkout_count'] ?? 0) ?></p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-payment"><i class="fa-solid fa-credit-card"></i></div>
            <div class="stat-content"><h4>Payments</h4><p><?= number_format($stats['payment_count'] ?? 0) ?></p></div>
        </div>
    </div>

    <div class="filter-card">
        <form method="GET" class="filter-grid">
            <div class="form-group"><label class="form-label">Date</label><input type="date" name="filter_date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>"></div>
            <div class="form-group">
                <label class="form-label">User</label>
                <select name="filter_user" class="form-control">
                    <option value="">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= htmlspecialchars($user) ?>" <?= $filter_user == $user ? 'selected' : '' ?>><?= htmlspecialchars($user) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Action Type</label>
                <select name="filter_action" class="form-control">
                    <option value="">All Actions</option>
                    <option value="login" <?= $filter_action == 'login' ? 'selected' : '' ?>>Login</option>
                    <option value="guest" <?= $filter_action == 'guest' ? 'selected' : '' ?>>Guest Actions</option>
                    <option value="check-in" <?= $filter_action == 'check-in' ? 'selected' : '' ?>>Check-in</option>
                    <option value="check-out" <?= $filter_action == 'check-out' ? 'selected' : '' ?>>Check-out</option>
                    <option value="payment" <?= $filter_action == 'payment' ? 'selected' : '' ?>>Payment</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Search..." value="<?= htmlspecialchars($search_term) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <div class="btn-group">
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                    <a href="activity_log.php" class="btn-clear"><i class="fa-solid fa-times"></i> Clear</a>
                </div>
            </div>
        </form>
    </div>

    <div class="bulk-del-card">
        <div style="flex:1;"><strong><i class="fa-solid fa-trash-can"></i> Bulk Delete:</strong> Delete Multiple Logs at Once </div>
        <form method="POST" class="bulk-form">
            <input type="date" name="del_from" class="form-control" style="padding:5px;" required>
            <span> to </span>
            <input type="date" name="del_to" class="form-control" style="padding:5px;" required>
            <button type="submit" name="bulk_delete" class="btn-filter" style="background:#dc3545; padding:8px 15px;" onclick="return confirm('Do you want to delete all this logs?')">Delete</button>
        </form>
    </div>

    <div class="table-container">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th style="width:50px;">Del</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): while($row = $result->fetch_assoc()): 
                        $act = strtolower($row['action']);
                        $action_class = 'badge-default';
                        if (strpos($act, 'login') !== false) $action_class = 'badge-login';
                        elseif (strpos($act, 'payment') !== false) $action_class = 'badge-payment';
                        elseif (strpos($act, 'check-in') !== false) $action_class = 'badge-checkin';
                        elseif (strpos($act, 'check-out') !== false) $action_class = 'badge-checkout';
                        elseif (strpos($act, 'guest') !== false) $action_class = 'badge-guest';
                    ?>
                        <tr>
                            <td><?= date("M d, Y H:i:s", strtotime($row['timestamp'])) ?></td>
                            <td><strong><?= htmlspecialchars($row['username']) ?></strong></td>
                            <td><?= htmlspecialchars(ucfirst($row['role'])) ?></td>
                            <td><span class="badge <?= $action_class ?>"><?= htmlspecialchars($row['action']) ?></span></td>
                            <td><?= htmlspecialchars($row['description']) ?></td>
                            <td><a href="activity_log.php?delete_id=<?= $row['log_id'] ?>" class="btn-del" onclick="return confirm('Delete this?')"><i class="fa-solid fa-trash"></i></a></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="6" class="text-center no-data">No activities found.</td></tr>
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
</script>

</body>
</html>