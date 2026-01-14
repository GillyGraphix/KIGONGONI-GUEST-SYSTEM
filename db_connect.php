<?php
// Database Configuration
$servername = "localhost";
$username = "root";        // Default XAMPP username
$password = "";            // Default XAMPP password (usually empty)
$dbname = "hgmas_db";      // Jina la database yako

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to ensure special characters (like currency symbols) work
$conn->set_charset("utf8mb4");

// Set Timezone to Tanzania (East Africa Time) - Muhimu kwa ushahidi wa muda
date_default_timezone_set('Africa/Dar_es_Salaam');

// =========================================================
//  ACTIVITY LOGGING FUNCTION ("The Recorder")
// =========================================================
// Usage Simplified: logActivity($conn, "Delete", "Deleted Guest Juma");

if (!function_exists('logActivity')) {
    function logActivity($conn, $action, $description) {
        
        // 1. Hakikisha Session ipo ili kupata jina la mtumiaji
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 2. Chukua taarifa za aliyelogin AUTOMATICALLY (Ushahidi)
        // Kama hakuna aliyelogin (mfano Login page), itaweka 'System' au 'Guest'
        $user_id   = $_SESSION['user_id'] ?? 0;
        $username  = $_SESSION['username'] ?? 'System';
        $role      = $_SESSION['role'] ?? 'System';

        // 3. Get IP Address (Kujua wametumia computer gani)
        $ip_address = $_SERVER['REMOTE_ADDR'];
        if ($ip_address == '::1') {
            $ip_address = '127.0.0.1'; // Fix for localhost
        }

        // 4. Prepare SQL (Secure)
        // Hakikisha table yako ya 'activity_logs' ina columns hizi
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, role, action, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)");

        if ($stmt) {
            // Bind parameters: i=integer, s=string
            $stmt->bind_param("isssss", $user_id, $username, $role, $action, $description, $ip_address);
            $stmt->execute();
            $stmt->close();
        }
    }
}
?>