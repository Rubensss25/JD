<?php
// Session validation and cache control
session_start();

// Check if user is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_email'])) {
    // Redirect to login page
    header('Location: ../index.php');
    exit();
}

// Set cache control headers to prevent back navigation
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Past date
?>
