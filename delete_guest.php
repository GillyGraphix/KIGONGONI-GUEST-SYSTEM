<?php
session_start();
include 'db_connect.php';

// 1. Ulinzi: Hakikisha mtu amelogin
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 2. Angalia kama tumepokea ID ya kufuta
if (isset($_GET['id'])) {
    $guest_id = intval($_GET['id']);

    // --- HATUA YA KWANZA: CHUKUA TAARIFA KABLA HUJAFUTA (HUO NDO USHAHIDI) ---
    // Tunataka kujua anafuta nani? Jina lake nani? Chumba gani?
    $stmt_check = $conn->prepare("SELECT first_name, last_name, room_id, phone FROM guest WHERE guest_id = ?");
    $stmt_check->bind_param("i", $guest_id);
    $stmt_check->execute();
    $result = $stmt_check->get_result();

    if ($row = $result->fetch_assoc()) {
        $fullname = $row['first_name'] . ' ' . $row['last_name'];
        $room_id = $row['room_id'];
        $phone = $row['phone'];

        // --- HATUA YA PILI: FUTA SASA ---
        $stmt_delete = $conn->prepare("DELETE FROM guest WHERE guest_id = ?");
        $stmt_delete->bind_param("i", $guest_id);

        if ($stmt_delete->execute()) {
            
            // Pia inabidi tubadilishe status ya chumba iwe 'Available' kama mgeni kafutwa
            if($room_id > 0) {
                $conn->query("UPDATE rooms SET status='Available' WHERE room_id=$room_id");
            }

            // --- HATUA YA TATU: REKODI KWENYE CCTV (LOG ACTIVITY) ---
            // Hapa ndipo tunamnasa. Hata akifuta, log inabaki.
            $log_desc = "Amemdelete mgeni: $fullname (Simu: $phone) aliyekuwa chumba ID: $room_id";
            logActivity($conn, "DELETE GUEST", $log_desc);

            // Rudisha ujumbe wa mafanikio
            echo "<script>
                alert('Guest has been deleted permanently.');
                window.location.href = 'view_guests.php'; 
            </script>";
        } else {
            echo "Error deleting record: " . $conn->error;
        }
    } else {
        echo "<script>alert('Guest not found!'); window.location.href = 'view_guests.php';</script>";
    }
} else {
    // Kama hakuna ID iliyotumwa, rudi home
    header("Location: view_guests.php");
}
?>