<?php
session_start();
include 'db_connect.php';

// Security: Only Admin/Manager
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'admin', 'ceo'])) {
    header("Location: login.php");
    exit();
}

$fullname = $_SESSION['fullname'] ?? 'Manager';
$currentPage = basename($_SERVER['PHP_SELF']);
$message = "";
$msg_type = "";
$results = [];

// --- 1. HANDLE BACKUP ---
if (isset($_POST['action']) && $_POST['action'] == 'backup') {
    $tables = array();
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) { $tables[] = $row[0]; }

    $sqlScript = "-- HGMA SYSTEM BACKUP\n-- Generated: " . date("Y-m-d H:i:s") . "\n\n";
    foreach ($tables as $table) {
        $result = $conn->query("SELECT * FROM $table");
        $num_fields = $result->field_count;
        $sqlScript .= "DROP TABLE IF EXISTS $table;";
        $row2 = $conn->query("SHOW CREATE TABLE $table")->fetch_row();
        $sqlScript .= "\n\n" . $row2[1] . ";\n\n";
        
        for ($i = 0; $i < $num_fields; $i++) {
            while ($row = $result->fetch_row()) {
                $sqlScript .= "INSERT INTO $table VALUES(";
                for ($j = 0; $j < $num_fields; $j++) {
                    $row[$j] = $row[$j] ? addslashes($row[$j]) : $row[$j];
                    if (isset($row[$j])) { $sqlScript .= '"' . $row[$j] . '"'; } else { $sqlScript .= '""'; }
                    if ($j < ($num_fields - 1)) { $sqlScript .= ','; }
                }
                $sqlScript .= ");\n";
            }
        }
        $sqlScript .= "\n";
    }

    $backup_name = "backup_hgma_" . date("Y-m-d_H-i-s") . ".sql";
    header('Content-Type: application/octet-stream');
    header("Content-Transfer-Encoding: Binary");
    header("Content-disposition: attachment; filename=\"".$backup_name."\"");
    echo $sqlScript;
    exit;
}

// --- 2. HANDLE OPTIMIZATION (Feature iliyokosekana imerudi) ---
if (isset($_POST['action']) && $_POST['action'] == 'optimize') {
    $tables_query = $conn->query("SHOW TABLES");
    if ($tables_query) {
        while ($row = $tables_query->fetch_array()) {
            $table = $row[0];
            $optimize_result = $conn->query("OPTIMIZE TABLE $table");
            if ($optimize_result) {
                while ($res_row = $optimize_result->fetch_assoc()) {
                    $results[] = [
                        'table' => $table,
                        'op' => $res_row['Op'],
                        'status' => $res_row['Msg_text']
                    ];
                }
            }
        }
        $message = "System Optimized Successfully!";
        $msg_type = "success";
    }
}

// --- 3. HANDLE SYSTEM RESET (WIPE DATA) ---
if (isset($_POST['action']) && $_POST['action'] == 'reset_system') {
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $tables_to_wipe = ['guest', 'payments', 'bookings', 'activity_log'];
    $success = true;
    foreach ($tables_to_wipe as $table) {
        if (!$conn->query("TRUNCATE TABLE $table")) {
            $success = false;
            $message = "Error wiping table $table: " . $conn->error;
            $msg_type = "error";
            break;
        }
    }
    if ($success) {
        $conn->query("UPDATE rooms SET status = 'AVAILABLE'");
        $user_id = $_SESSION['user_id'];
        $conn->query("INSERT INTO activity_log (user_id, action, details) VALUES ('$user_id', 'SYSTEM RESET', 'All operational data wiped for fresh start.')");
        $message = "System Reset Successful! Data wiped.";
        $msg_type = "success";
    }
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
}

// --- 4. GET SYSTEM STATS (Size & Rows) ---
$db_size = 0;
$total_rows = 0;
$stats_query = $conn->query("SHOW TABLE STATUS");
while ($row = $stats_query->fetch_assoc()) {
    $db_size += $row['Data_length'] + $row['Index_length'];
    $total_rows += $row['Rows'];
}
function formatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>System Maintenance - HGMA System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    /* --- CSS FROM DASHBOARD (Consolidated) --- */
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { background: #f5f7fa; color: #2c3e50; min-height: 100vh; overflow-x: hidden; }

    /* SIDEBAR Styles */
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
    .logout-btn:hover { background: #c82333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3); }

    /* MAIN CONTENT Styles */
    .main-content { margin-left: 260px; padding: 35px 40px; min-height: 100vh; transition: margin-left 0.3s ease-in-out; }
    
    /* HEADER Styles */
    .header { margin-bottom: 35px; background: #fff; padding: 25px 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); display: flex; align-items: center; gap: 15px; }
    .welcome { font-size: 1.6rem; font-weight: 700; color: #1e3a5f; }
    .menu-toggle { display: none; font-size: 1.5rem; color: #1e3a5f; cursor: pointer; }
    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 900; }

    /* --- MAINTENANCE SPECIFIC STYLES (Compatible with Dashboard) --- */
    
    /* Health Stats (Reusing Dashboard Stats Style) */
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 35px; }
    .stat-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); border-left: 4px solid #667eea; transition: all 0.3s ease; }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1); }
    .stat-card h3 { font-size: 0.9rem; font-weight: 600; color: #7f8c8d; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
    .stat-card p { font-size: 1.8rem; font-weight: 700; color: #1e3a5f; }

    /* Action Cards (Reusing Dashboard Cards Style) */
    .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; margin-bottom: 35px; }
    .card { background: #fff; border-radius: 15px; padding: 35px 30px; text-align: center; cursor: pointer; transition: all 0.4s ease; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); border: 2px solid transparent; position: relative; }
    .card:hover { transform: translateY(-8px); box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15); border-color: #667eea; }
    .card-icon { width: 70px; height: 70px; margin: 0 auto 20px auto; background: #eee; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #fff; }
    .card h3 { margin: 15px 0 10px 0; font-weight: 600; font-size: 1.2rem; color: #1e3a5f; }
    .card p { font-size: 0.9rem; color: #7f8c8d; line-height: 1.5; margin-bottom: 15px; }
    .btn-action { width: 100%; padding: 10px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; color: #fff; margin-top: 10px; }

    /* Result Table Style */
    .results-card { background: #fff; padding: 25px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-top: 30px; }
    .results-card h3 { margin-bottom: 15px; color: #1e3a5f; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; padding: 15px; background: #f8fafc; color: #64748b; font-size: 0.85rem; text-transform: uppercase; }
    td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 0.9rem; }
    .status-ok { color: #10b981; font-weight: 600; }

    @media (max-width: 768px) { 
        .sidebar { transform: translateX(-100%); width: 250px; } 
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        .main-content { margin-left: 0; padding: 20px 15px; }
        .header { padding: 15px; }
        .welcome { font-size: 1.3rem; }
        .menu-toggle { display: block; }
        .cards { grid-template-columns: 1fr; }
        .stats { grid-template-columns: 1fr; }
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
        <i class="fa-solid fa-bars menu-toggle" onclick="toggleSidebar()"></i>
        <div class="welcome">System Maintenance</div>
    </div>

    <div class="stats">
        <div class="stat-card" style="border-left-color: #667eea;">
            <h3>Database Size</h3>
            <p><?= formatSize($db_size) ?></p>
        </div>
        <div class="stat-card" style="border-left-color: #f093fb;">
            <h3>Total Rows</h3>
            <p><?= number_format($total_rows) ?></p>
        </div>
        <div class="stat-card" style="border-left-color: #4facfe;">
            <h3>Optimization Status</h3>
            <p style="font-size: 1.2rem; margin-top: 5px;">Ready</p>
        </div>
    </div>

    <div class="cards">
        <div class="card">
            <div class="card-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <i class="fa-solid fa-rocket"></i>
            </div>
            <h3>Optimize System</h3>
            <p>Defragment tables to speed up the system.</p>
            <form method="POST">
                <input type="hidden" name="action" value="optimize">
                <button type="submit" class="btn-action" style="background: #4facfe;">Run Optimization</button>
            </form>
        </div>

        <div class="card">
            <div class="card-icon" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                <i class="fa-solid fa-cloud-arrow-down"></i>
            </div>
            <h3>Backup Database</h3>
            <p>Download SQL file to save your data.</p>
            <form method="POST">
                <input type="hidden" name="action" value="backup">
                <button type="submit" class="btn-action" style="background: #11998e;">Download Backup</button>
            </form>
        </div>

        <div class="card" style="border-color: #fee; background: #fff5f5;">
            <div class="card-icon" style="background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);">
                <i class="fa-solid fa-trash-can"></i>
            </div>
            <h3 style="color: #c0392b;">System Reset</h3>
            <p><strong>Warning:</strong> Deletes Guests & Payments.</p>
            <form method="POST" id="resetForm">
                <input type="hidden" name="action" value="reset_system">
                <button type="button" class="btn-action" style="background: #c0392b;" onclick="confirmReset()">Wipe Data</button>
            </form>
        </div>
    </div>

    <?php if(!empty($results)): ?>
    <div class="results-card">
        <h3><i class="fa-solid fa-clipboard-check"></i> Optimization Results</h3>
        <table>
            <thead>
                <tr>
                    <th>Table Name</th>
                    <th>Operation</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($results as $res): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($res['table']) ?></strong></td>
                    <td><?= htmlspecialchars($res['op']) ?></td>
                    <td class="<?= strpos($res['status'], 'OK') !== false ? 'status-ok' : '' ?>">
                        <?= htmlspecialchars($res['status']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('active');
        document.querySelector('.sidebar-overlay').classList.toggle('active');
    }

    <?php if ($message != ""): ?>
        Swal.fire({
            icon: '<?= $msg_type ?>',
            title: '<?= $msg_type == "success" ? "Done!" : "Error" ?>',
            text: '<?= $message ?>'
        });
    <?php endif; ?>

    function confirmReset() {
        Swal.fire({
            title: 'Are you absolutely sure?',
            text: "You are about to delete ALL operational data (Guests, Payments, History). This cannot be undone!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Wipe Everything!'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Type "DELETE" to confirm',
                    input: 'text',
                    showCancelButton: true,
                    confirmButtonText: 'Confirm Wipe',
                    confirmButtonColor: '#dc3545',
                    preConfirm: (text) => {
                        if (text !== 'DELETE') {
                            Swal.showValidationMessage('Please type DELETE exactly.')
                        }
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.getElementById('resetForm').submit();
                    }
                })
            }
        })
    }
</script>

</body>
</html>