<?php
session_start();
include 'db_connect.php';

if (isset($_POST['login'])) {

    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, username, fullname, role, password FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {

        $user = $result->fetch_assoc();

        // Verify encrypted password
        if (password_verify($password, $user['password'])) {

            session_regenerate_id(true);

            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role'] = $user['role'];

            // Log login activity
            logActivity($conn, "Login", "User logged in");

            // Redirect by role
            if ($user['role'] == 'manager') {
                header("Location: manager_dashboard.php");
                exit();
            }
            elseif ($user['role'] == 'receptionist') {
                header("Location: receptionist_dashboard.php");
                exit();
            }
            else {
                $error = "Invalid role";
            }

        } else {
            $error = "Invalid username or password!";
        }

    } else {
        $error = "Invalid username or password!";
    }

    $stmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>HGMA System Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
body {
  font-family: 'Poppins', sans-serif;
  background: linear-gradient(to right, #ededed, #b5b5b5);
  display: flex;
  justify-content: center;
  align-items: center;
  height: 100vh;
  margin: 0;
}
.login-container {
  background: white;
  padding: 40px 30px;
  border-radius: 15px;
  width: 350px;
  box-shadow: 0 8px 20px rgba(0,0,0,0.2);
  text-align: center;
}
.logo {
  width: 150px;
  height: 150px;
  border-radius: 50%;
  object-fit: cover;
  margin-bottom: 10px;
  box-shadow: 0 0 10px rgba(21, 22, 0, 0.45);
}
.login-container h2 {
  margin-bottom: 25px;
  color: #19355c;
}
.input-group {
  position: relative;
}
.input-field {
  width: 90%;
  padding: 12px;
  margin: 10px 0;
  border: 1px solid #ccc;
  border-radius: 8px;
  outline: none;
  transition: 0.3s;
  font-size: 15px;
}
.input-field:focus {
  border-color: #007bff;
  box-shadow: 0 0 5px #007bff;
}
.eye-icon {
  position: absolute;
  right: 15px;
  top: 50%;
  transform: translateY(-50%);
  cursor: pointer;
  color: gray;
  font-size: 18px;
}
button {
  width: 100%;
  padding: 12px;
  background: #19355c;
  color: white;
  border: none;
  font-weight: bold;
  border-radius: 8px;
  margin-top: 10px;
  cursor: pointer;
  transition: 0.3s;
}
button:hover {
  background: #0056b3;
}
.error {
  color: red;
  margin-top: 10px;
}
.footer-text {
  margin-top: 20px;
  font-size: 0.9em;
  color: gray;
}
@media(max-width: 400px) {
  .login-container {
    width: 90%;
    padding: 25px;
  }
}
</style>
</head>
<body>

<div class="login-container">
  <img src="assets/img/logo.png" alt="Hotel Logo" class="logo">
  <h2>Kigongoni Gazella Hotel Guest Monitoring System Login</h2>
  <form method="POST" autocomplete="off">
    <input type="text" name="username" class="input-field" placeholder="Enter Username" required>

    <div class="input-group">
      <input type="password" name="password" id="password" class="input-field" placeholder="Enter Password" required>
      <i class="fa-solid fa-eye eye-icon" id="togglePassword" style="user-select:none;"></i>
    </div>

    <button type="submit" name="login">Login</button>
    <?php if (isset($error)) echo "<p class='error'>".htmlspecialchars($error)."</p>"; ?>
  </form>
  <p class="footer-text">© <?php echo date('Y'); ?> Hotel Guest Monitoring & Analytics System</p>
</div>

<script>
const togglePassword = document.querySelector('#togglePassword');
const password = document.querySelector('#password');
togglePassword.addEventListener('click', function () {
  const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
  password.setAttribute('type', type);
  this.classList.toggle('fa-eye-slash');
});
</script>

</body>
</html>