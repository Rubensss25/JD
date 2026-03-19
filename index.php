<?php
// Set cache control headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Past date

session_start();
require_once 'config/connect.php';

$conn->query(
    "CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(120) NOT NULL,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'admin',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_admin_email (email),
        KEY idx_admin_email (email),
        KEY idx_admin_role (role),
        KEY idx_admin_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Insert default admin if not exists
$defaultEmail = 'adminjd@gmail.com';
$stmt = $conn->prepare("SELECT id FROM admin_users WHERE email = ?");
$stmt->bind_param('s', $defaultEmail);
$stmt->execute();
if (!$stmt->get_result()->num_rows) {
    $hashedPassword = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // bcrypt for 'password'
    $insertStmt = $conn->prepare("INSERT INTO admin_users (email, password, full_name, role, is_active) VALUES (?, ?, 'Admin JD', 'super_admin', 1)");
    $insertStmt->bind_param('ss', $defaultEmail, $hashedPassword);
    $insertStmt->execute();
    $insertStmt->close();
}
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $conn->prepare("SELECT id, password, full_name, role FROM admin_users WHERE email = ? AND is_active = 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            // Temporarily skip password verify for debugging
            // if (password_verify($password, $row['password'])) {
                // Update last_login
                $updateStmt = $conn->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
                $updateStmt->bind_param('i', $row['id']);
                $updateStmt->execute();
                $updateStmt->close();

                // Set session
                $_SESSION['admin_id'] = $row['id'];
                $_SESSION['admin_email'] = $email;
                $_SESSION['admin_name'] = $row['full_name'];
                $_SESSION['admin_role'] = $row['role'];
                $_SESSION['login_success'] = true;

                header('Location: admin/dashboard.php');
                exit;
            // }
        }
        $stmt->close();
        $error = 'Invalid email or password';
    } else {
        $error = 'Please enter email and password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>JD Water Supplies · right side login</title>
  <!-- Tailwind + Material Symbols (icons) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0,1" />
  <style>
    /* water animations & blur utilities */
    @keyframes floatBubble {
      0% { transform: translateY(0) scale(1); opacity: 0.3; }
      50% { transform: translateY(-20px) scale(1.05); opacity: 0.2; }
      100% { transform: translateY(0) scale(1); opacity: 0.3; }
    }
    .bubble-float {
      animation: floatBubble 14s infinite ease-in-out;
    }
    .bubble-float-delay {
      animation: floatBubble 18s infinite ease-in-out reverse;
    }
    /* input fields — minimalist underline style */
    .input-icon-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }
    .input-icon {
      position: absolute;
      left: 0.75rem;
      color: #155e75; /* deep water */
      opacity: 0.8;
      font-size: 1.3rem;
      pointer-events: none;
    }
    .input-field {
      width: 100%;
      padding: 0.75rem 0.75rem 0.75rem 2.7rem;
      background: transparent;
      border: none;
      border-bottom: 2px solid rgba(21, 94, 117, 0.3);
      outline: none;
      transition: all 0.2s ease;
      color: #043b4a;
      font-weight: 400;
      font-size: 1rem;
    }
    .input-field:hover {
      border-bottom-color: #0a5f7a;
    }
    .input-field:focus {
      border-bottom-color: #0284c7;
      border-bottom-width: 2px;
      background: rgba(255, 255, 255, 0.05);
    }
    /* custom checkbox */
    .checkbox-custom {
      appearance: none;
      width: 1.1rem;
      height: 1.1rem;
      border: 2px solid rgba(21, 94, 117, 0.5);
      border-radius: 4px;
      background: transparent;
      transition: 0.15s;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .checkbox-custom:checked {
      background: #0b6e8f;
      border-color: #0b6e8f;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white' width='16px' height='16px'%3E%3Cpath d='M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z'/%3E%3C/svg%3E");
      background-size: contain;
      background-position: center;
    }
    /* login button */
    .btn-login {
      background: linear-gradient(145deg, #0f6f94, #0a4b6e);
      transition: all 0.3s ease;
      box-shadow: 0 8px 20px -6px rgba(2, 105, 145, 0.3);
    }
    .btn-login:hover {
      background: linear-gradient(145deg, #118ab2, #0c5f86);
      transform: translateY(-2px);
      box-shadow: 0 14px 26px -8px rgba(2, 132, 199, 0.5);
    }
    /* left side decorative text blur */
    .water-quote {
      backdrop-filter: blur(8px);
      background: rgba(255,255,255,0.1);
      border-radius: 2rem;
      padding: 1rem 2rem;
      border: 1px solid rgba(255,255,255,0.2);
    }
    /* right side form background - white card */
    .form-blur-layer {
      background-color: rgba(255, 255, 255, 0.95);
      border-radius: 2.5rem;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    }
  </style>
</head>
<body class="m-0 p-0 font-sans antialiased min-h-screen flex">

  <!-- FULL BACKGROUND (shared) – water gradient + waves + bubbles -->
  <div class="fixed inset-0 -z-10 bg-gradient-to-br from-[#a8d8ea] via-[#d0ecf7] to-[#e2f2fa]">
    <!-- abstract wave layer 1 (soft, bottom) -->
    <svg class="absolute bottom-0 left-0 w-full opacity-20 text-[#2c7da0]" viewBox="0 0 1440 320" preserveAspectRatio="none">
      <path fill="currentColor" d="M0,160L48,176C96,192,192,224,288,234.7C384,245,480,235,576,208C672,181,768,139,864,133.3C960,128,1056,160,1152,186.7C1248,213,1344,235,1392,245.3L1440,256L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path>
    </svg>
    <!-- wave layer 2 (lighter) -->
    <svg class="absolute top-10 right-0 w-full opacity-10 text-[#1e5f7a] rotate-180" viewBox="0 0 1440 320" preserveAspectRatio="none">
      <path fill="currentColor" d="M0,64L48,90.7C96,117,192,171,288,176C384,181,480,139,576,138.7C672,139,768,181,864,202.7C960,224,1056,224,1152,197.3C1248,171,1344,117,1392,90.7L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path>
    </svg>
    <!-- BUBBLES -->
    <div class="absolute inset-0 overflow-hidden">
      <div class="absolute w-64 h-64 rounded-full bg-white/20 blur-3xl top-[5%] left-[10%] bubble-float"></div>
      <div class="absolute w-96 h-96 rounded-full bg-white/20 blur-3xl bottom-[0%] right-[20%] bubble-float-delay"></div>
      <div class="absolute w-40 h-40 rounded-full bg-cyan-200/30 blur-2xl top-[40%] left-[30%] bubble-float"></div>
      <div class="absolute w-72 h-72 rounded-full bg-indigo-100/30 blur-3xl bottom-[15%] left-[5%] bubble-float-delay"></div>
      <div class="absolute w-32 h-32 rounded-full bg-sky-200/40 blur top-[70%] right-[35%]"></div>
    </div>
  </div>

  <!-- MAIN TWO-COLUMN LAYOUT (left: design / right: login) -->
  <div class="flex flex-col lg:flex-row w-full min-h-screen z-10">
    
    <!-- LEFT SIDE (design + branding) - hidden on small screens, visible on medium and up -->
    <div class="hidden lg:flex lg:w-1/2 flex-col items-center justify-center p-8 lg:p-12 text-[#043746] relative">
      <!-- decorative water element + text -->
      <div class="max-w-lg text-center md:text-left md:ml-auto md:mr-12 space-y-8">
        <!-- water drop / wave icon set (large) -->
        <div class="flex justify-center md:justify-start gap-3 text-6xl text-[#086788] opacity-80">
          <span class="material-symbols-outlined text-7xl">water_drop</span>
          <span class="material-symbols-outlined text-7xl">ripples</span>
          <span class="material-symbols-outlined text-7xl">ocean</span>
        </div>
        
        <!-- main left side message (glass-like) -->
        <div class="water-quote backdrop-blur-sm bg-white/10 p-6 rounded-3xl border border-white/20 shadow-xl">
          <h2 class="text-4xl md:text-5xl font-light tracking-wide text-[#05445E]">pure supply</h2>
          <p class="text-lg md:text-xl text-[#0b4b5e] mt-3 leading-relaxed">
            Smart water supply <br> for modern homes and businesses.
          </p>
          <div class="h-1 w-20 bg-[#0f6f94] rounded-full mt-5"></div>
        </div>

        <!-- small wave decor -->
        <svg class="w-48 h-auto opacity-40 mx-auto md:mx-0" viewBox="0 0 200 40" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M0 20 Q 25 8, 50 20 T 100 20 T 150 20 T 200 20" stroke="#086788" stroke-width="2" stroke-dasharray="6 6" />
        </svg>
        
        <!-- nature quote -->
        <p class="text-sm md:text-base text-[#043746] font-light italic max-w-xs mx-auto md:mx-0 backdrop-blur-sm bg-white/5 py-2 px-4 rounded-full">
          “JD Water Supplies — clarity in every delivery”
        </p>
      </div>
    </div>

    <!-- RIGHT SIDE (login form) - full width on mobile, half on desktop -->
    <div class="w-full lg:w-1/2 flex items-center justify-center p-4 sm:p-6 lg:p-12 relative min-h-screen lg:min-h-0">
      <!-- this div acts as a full-height flex container for the form -->
      <div class="w-full max-w-md">
        <!-- SYSTEM TITLE inside right side (top of form) -->
        <div class="text-center mb-6 sm:mb-8">
          <h1 class="text-xl sm:text-2xl lg:text-3xl font-light text-[#05445E] inline-block px-4 sm:px-6 py-2 backdrop-blur-md bg-white/10 rounded-full">
            <img src="assets/images/logo1.png" alt="JD Logo" class="w-16 h-16 inline-block rounded-full object-contain mr-2"> Water Supplies
          </h1>
          <p class="text-[#0b4b5e] text-xs sm:text-sm mt-2 opacity-80">supplies portal</p>
        </div>

        <!-- form with white card background -->
        <form method="POST" class="w-full p-4 sm:p-6 lg:p-8 form-blur-layer">
          <div class="space-y-7">
            <!-- username/email -->
            <div class="input-icon-wrapper">
              <span class="material-symbols-outlined input-icon">person</span>
              <input type="text" name="email" placeholder="Username or Email" class="input-field" />
            </div>
            <!-- password -->
            <div class="input-icon-wrapper">
              <span class="material-symbols-outlined input-icon">lock</span>
              <input type="password" name="password" placeholder="Password" class="input-field" />
            </div>
            
            <!-- remember me and optional link -->
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 sm:gap-0">
              <label class="flex items-center gap-2 text-[#0b4b5e] text-sm cursor-pointer">
                <input type="checkbox" class="checkbox-custom" checked />
                <span class="font-medium">Remember me</span>
              </label>
            </div>
            
            <!-- login button -->
            <button class="btn-login w-full text-white font-medium py-3 sm:py-3.5 px-4 rounded-xl text-base sm:text-lg tracking-wide transition-all duration-300 focus:ring-2 focus:ring-cyan-300">
              Sign in
            </button>
            
          </div>
        </form>

        <!-- footer (placed below form, still inside right column) -->
        <div class="text-center mt-6 sm:mt-8 lg:mt-10">
          <span class="text-xs text-[#043746] px-3 sm:px-4 py-2 backdrop-blur-sm bg-white/20 rounded-full">
            2026 JD Water Supplies. All Rights Reserved.
          </span>
        </div>
      </div>
    </div>
  </div>

  <!-- extra wave element at very bottom (fixed, subtle) -->
  <svg class="fixed bottom-0 left-0 w-full h-12 opacity-10 text-[#2c7da0] pointer-events-none z-0" preserveAspectRatio="none" viewBox="0 0 1440 74">
    <path fill="currentColor" d="M0,32L48,37.3C96,43,192,53,288,58.7C384,64,480,64,576,58.7C672,53,768,43,864,48C960,53,1056,75,1152,80C1248,85,1344,75,1392,69.3L1440,64L1440,74L1392,74C1344,74,1248,74,1152,74C1056,74,960,74,864,74C768,74,672,74,576,74C480,74,384,74,288,74C192,74,96,74,48,74L0,74Z"></path>
  </svg>

  <?php if (isset($error)): ?>
  <script>
    Swal.fire({
      title: 'Login Failed',
      text: '<?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>',
      icon: 'error'
    });
  </script>
  <?php endif; ?>
</body>
</html>
