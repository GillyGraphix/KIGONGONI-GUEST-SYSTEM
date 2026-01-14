<?php
session_start();
include 'db_connect.php';

// Kagua kama user ame-login na role ni receptionist
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

// Fetch bookings
$sql = "SELECT b.id, g.fullname AS guest_name, r.room_number, u.username AS booked_by, b.check_in, b.check_out, b.status
        FROM bookings b
        JOIN guests g ON b.guest_id = g.id
        JOIN rooms r ON b.room_id = r.id
        JOIN users u ON b.booked_by = u.id
        ORDER BY b.created_at DESC";

$result = $conn->query($sql);

// Fetch rooms and guests for dropdowns
$rooms = $conn->query("SELECT id, room_number FROM rooms");
$guests = $conn->query("SELECT id, fullname FROM guests");

// Handle Add Booking
if (isset($_POST['add_booking'])) {
    $guest_id = $_POST['guest_id'];
    $room_id = $_POST['room_id'];
    $check_in = $_POST['check_in'];
    $check_out = $_POST['check_out'];
    $booked_by = $_SESSION['user_id'];

    $stmt = $conn->prepare("INSERT INTO bookings (guest_id, room_id, booked_by, check_in, check_out) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiss", $guest_id, $room_id, $booked_by, $check_in, $check_out);

    if ($stmt->execute()) {
        $message = "Booking added successfully!";
        header("Location: bookings.php");
        exit();
    } else {
        $error = "Failed to add booking: " . $stmt->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receptionist Booking - HGMA</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body { font-family: 'Poppins', sans-serif; margin:0; background:#f4f4f4; }
.container { padding: 20px; }
h2 { color: #007bff; }
table { width:100%; border-collapse: collapse; margin-top: 20px; }
th, td { padding: 10px; border:1px solid #ccc; text-align:left; }
th { background:#007bff; color:#fff; }
form { background:#fff; padding:15px; border-radius:8px; margin-top:20px; }
input, select { padding:8px; margin:5px 0; width:100%; }
button { padding:10px 15px; background:#007bff; color:#fff; border:none; border-radius:5px; cursor:pointer; }
button:hover { background:#0056b3; }
.error { color:red; }
.message { color:green; }
</style>
</head>
<body>
<div class="container">
<h2>Bookings</h2>

<?php if(isset($error)) echo "<p class='error'>$error</p>"; ?>
<?php if(isset($message)) echo "<p class='message'>$message</p>"; ?>

<!-- Booking Table -->
<table>
<tr>
<th>ID</th>
<th>Guest</th>
<th>Room</th>
<th>Booked By</th>
<th>Check-in</th>
<th>Check-out</th>
<th>Status</th>
<th>Actions</th>
</tr>

<?php if($result && $result->num_rows>0): ?>
    <?php while($row = $result->fetch_assoc()): ?>
    <tr>
        <td><?= $row['id'] ?></td>
        <td><?= htmlspecialchars($row['guest_name']) ?></td>
        <td><?= htmlspecialchars($row['room_number']) ?></td>
        <td><?= htmlspecialchars($row['booked_by']) ?></td>
        <td><?= $row['check_in'] ?></td>
        <td><?= $row['check_out'] ?></td>
        <td><?= $row['status'] ?></td>
        <td>
            <a href="edit_booking.php?id=<?= $row['id'] ?>">Edit</a> |
            <a href="delete_booking.php?id=<?= $row['id'] ?>" onclick="return confirm('Are you sure?')">Delete</a>
        </td>
    </tr>
    <?php endwhile; ?>
<?php else: ?>
<tr><td colspan="8">No bookings found.</td></tr>
<?php endif; ?>
</table>

<!-- Add Booking Form -->
<h3>Add Booking</h3>
<form method="POST">
    <label>Guest:</label>
    <select name="guest_id" required>
        <option value="">-- Select Guest --</option>
        <?php while($g = $guests->fetch_assoc()): ?>
            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['fullname']) ?></option>
        <?php endwhile; ?>
    </select>

    <label>Room:</label>
    <select name="room_id" required>
        <option value="">-- Select Room --</option>
        <?php while($r = $rooms->fetch_assoc()): ?>
            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['room_number']) ?></option>
        <?php endwhile; ?>
    </select>

    <label>Check-in:</label>
    <input type="date" name="check_in" required>

    <label>Check-out:</label>
    <input type="date" name="check_out" required>

    <button type="submit" name="add_booking">Add Booking</button>
</form>
</div>
</body>
</html>
