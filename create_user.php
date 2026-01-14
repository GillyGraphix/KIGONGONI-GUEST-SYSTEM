<?php
include 'db_connect.php';

$users = [
  ['username'=>'manager1','fullname'=>'Hotel Manager','role'=>'manager','password'=>'12345'],
  ['username'=>'reception1','fullname'=>'Front Desk','role'=>'receptionist','password'=>'12345']
];

foreach ($users as $u) {
  $h = password_hash($u['password'], PASSWORD_DEFAULT);
  $stmt = $conn->prepare("INSERT INTO users (username, password, fullname, role) VALUES (?, ?, ?, ?)");
  $stmt->bind_param("ssss", $u['username'], $h, $u['fullname'], $u['role']);
  if ($stmt->execute()) echo "Created {$u['username']}\n";
  else echo "Failed {$u['username']}: " . $stmt->error . "\n";
  $stmt->close();
}
$conn->close();
