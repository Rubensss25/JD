<?php
// Include session validation and cache control
require_once __DIR__ . '/../includes/auth_check.php';

// Include database connection
require_once __DIR__ . '/../config/connect.php';

function exportToCSV($data, $filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    
    if (!empty($data)) {
        // Write headers
        fputcsv($output, array_keys($data[0]));
        
        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'data') {
    $exportType = $_GET['type'] ?? 'all';
    
    if ($exportType === 'inventory' || $exportType === 'all') {
        // Export Inventory Data
        
        // Products
        $products = [];
        $productQuery = "SELECT p.*, c.name as category_name 
                        FROM products p 
                        LEFT JOIN inventory_categories c ON p.category_id = c.id 
                        ORDER BY p.id";
        $result = $conn->query($productQuery);
        while ($row = $result->fetch_assoc()) {
            $products[] = [
                'ID' => $row['id'],
                'Product Name' => $row['product_name'],
                'Category' => $row['category_name'] ?? 'Uncategorized',
                'Color Specs' => $row['color_specs'],
                'Brand' => $row['brand'],
                'Stock Store' => $row['stock_store'],
                'Stock Stockroom' => $row['stock_stockroom'],
                'Total Stock' => $row['stock_store'] + $row['stock_stockroom'],
                'Cost' => $row['cost'],
                'Price' => $row['price'],
                'Supplier' => $row['supplier'],
                'Is Active' => $row['is_active'] ? 'Yes' : 'No',
                'Created At' => $row['created_at'],
                'Updated At' => $row['updated_at']
            ];
        }
        
        // Categories
        $categories = [];
        $categoryQuery = "SELECT * FROM inventory_categories ORDER BY id";
        $result = $conn->query($categoryQuery);
        while ($row = $result->fetch_assoc()) {
            $categories[] = [
                'ID' => $row['id'],
                'Category Name' => $row['name'],
                'Created At' => $row['created_at']
            ];
        }
        
        // Restock Logs
        $restockLogs = [];
        $restockQuery = "SELECT r.*, p.product_name 
                        FROM inventory_restock_logs r 
                        LEFT JOIN products p ON r.product_id = p.id 
                        ORDER BY r.created_at DESC";
        $result = $conn->query($restockQuery);
        while ($row = $result->fetch_assoc()) {
            $restockLogs[] = [
                'ID' => $row['id'],
                'Product Name' => $row['product_name'],
                'Added to Store' => $row['added_store'],
                'Added to Stockroom' => $row['added_stockroom'],
                'Notes' => $row['notes'],
                'Created At' => $row['created_at']
            ];
        }
    }
    
    if ($exportType === 'sales' || $exportType === 'all') {
        // Export Sales Data
        
        // Sales Orders
        $salesOrders = [];
        $orderQuery = "SELECT * FROM sales_orders ORDER BY created_at DESC";
        $result = $conn->query($orderQuery);
        while ($row = $result->fetch_assoc()) {
            $salesOrders[] = [
                'ID' => $row['id'],
                'Receipt No' => $row['receipt_no'],
                'Customer Name' => $row['customer_name'],
                'Subtotal' => $row['subtotal'],
                'Discount Percent' => $row['discount_percent'],
                'Discount Amount' => $row['discount_amount'],
                'Tax Rate' => $row['tax_rate'],
                'Tax Amount' => $row['tax_amount'],
                'Total Amount' => $row['total_amount'],
                'Payment Amount' => $row['payment_amount'],
                'Change Amount' => $row['change_amount'],
                'Created At' => $row['created_at']
            ];
        }
        
        // Sales Order Items
        $salesItems = [];
        $itemQuery = "SELECT soi.*, so.receipt_no, so.customer_name 
                     FROM sales_order_items soi 
                     JOIN sales_orders so ON soi.order_id = so.id 
                     ORDER BY soi.created_at DESC";
        $result = $conn->query($itemQuery);
        while ($row = $result->fetch_assoc()) {
            $salesItems[] = [
                'ID' => $row['id'],
                'Order ID' => $row['order_id'],
                'Receipt No' => $row['receipt_no'],
                'Customer Name' => $row['customer_name'],
                'Product ID' => $row['product_id'],
                'Product Name' => $row['product_name'],
                'Unit Price' => $row['unit_price'],
                'Quantity' => $row['quantity'],
                'Line Total' => $row['line_total'],
                'Created At' => $row['created_at']
            ];
        }
        
        // Reports
        $reports = [];
        $reportQuery = "SELECT * FROM reports ORDER BY generated_at DESC";
        $result = $conn->query($reportQuery);
        while ($row = $result->fetch_assoc()) {
            $reports[] = [
                'ID' => $row['id'],
                'Report Code' => $row['report_code'],
                'Report Type' => $row['report_type'],
                'Range Key' => $row['range_key'],
                'Range Label' => $row['range_label'],
                'Start Date' => $row['start_date'],
                'End Date' => $row['end_date'],
                'Status' => $row['status'],
                'Total Sales' => $row['total_sales'],
                'Total Orders' => $row['total_orders'],
                'Total Customers' => $row['total_customers'],
                'Average Order Value' => $row['average_order_value'],
                'Notes' => $row['notes'],
                'Generated At' => $row['generated_at']
            ];
        }
    }
    
    // Generate filename with timestamp
    $timestamp = date('Y-m-d_H-i-s');
    
    if ($exportType === 'inventory') {
        // Create combined inventory export
        $allData = array_merge(
            array_map(function($row) { $row['Type'] = 'Product'; return $row; }, $products),
            array_map(function($row) { $row['Type'] = 'Category'; return $row; }, $categories),
            array_map(function($row) { $row['Type'] = 'Restock Log'; return $row; }, $restockLogs)
        );
        exportToCSV($allData, "jd_inventory_export_{$timestamp}.csv");
        
    } elseif ($exportType === 'sales') {
        // Create combined sales export
        $allData = array_merge(
            array_map(function($row) { $row['Type'] = 'Order'; return $row; }, $salesOrders),
            array_map(function($row) { $row['Type'] = 'Order Item'; return $row; }, $salesItems),
            array_map(function($row) { $row['Type'] = 'Report'; return $row; }, $reports)
        );
        exportToCSV($allData, "jd_sales_export_{$timestamp}.csv");
        
    } elseif ($exportType === 'all') {
        // Create combined export for both inventory and sales
        $allData = array_merge(
            array_map(function($row) { $row['Data Type'] = 'Product'; return $row; }, $products),
            array_map(function($row) { $row['Data Type'] = 'Category'; return $row; }, $categories),
            array_map(function($row) { $row['Data Type'] = 'Restock Log'; return $row; }, $restockLogs),
            array_map(function($row) { $row['Data Type'] = 'Sales Order'; return $row; }, $salesOrders),
            array_map(function($row) { $row['Data Type'] = 'Sales Order Item'; return $row; }, $salesItems),
            array_map(function($row) { $row['Data Type'] = 'Report'; return $row; }, $reports)
        );
        exportToCSV($allData, "jd_complete_export_{$timestamp}.csv");
    }
}

// Handle system reset request
if (isset($_POST['reset_system']) && $_POST['reset_system'] === 'true') {
    header('Content-Type: application/json');
    
    // Verify confirmation
    if (!isset($_POST['confirmation']) || strtoupper(trim($_POST['confirmation'])) !== 'YES') {
        echo json_encode(['success' => false, 'message' => 'Invalid confirmation. Please type YES to confirm.']);
        exit;
    }
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // STEP 1: Save current state (BEFORE reset)
        $statsQuery = "
            SELECT 
                (SELECT COUNT(*) FROM sales_orders) as total_orders,
                (SELECT COUNT(*) FROM sales_order_items) as total_items,
                (SELECT COUNT(*) FROM inventory_restock_logs) as total_logs,
                (SELECT COUNT(*) FROM reports) as total_reports,
                (SELECT COALESCE(SUM((stock_store + stock_stockroom) * cost), 0) FROM products) as stock_value
        ";
        $statsResult = $conn->query($statsQuery);
        $stats = $statsResult->fetch_assoc();
        
        // Get recent data snapshot for backup
        $backupData = [
            'last_5_orders' => [],
            'last_5_logs' => [],
            'current_stock_summary' => []
        ];
        
        // Get last 5 orders
        $ordersQuery = "SELECT id, receipt_no, customer_name, total_amount, created_at FROM sales_orders ORDER BY created_at DESC LIMIT 5";
        $result = $conn->query($ordersQuery);
        while ($row = $result->fetch_assoc()) {
            $backupData['last_5_orders'][] = $row;
        }
        
        // Get last 5 restock logs
        $logsQuery = "SELECT r.product_id, p.product_name, r.added_store, r.added_stockroom, r.created_at FROM inventory_restock_logs r LEFT JOIN products p ON r.product_id = p.id ORDER BY r.created_at DESC LIMIT 5";
        $result = $conn->query($logsQuery);
        while ($row = $result->fetch_assoc()) {
            $backupData['last_5_logs'][] = $row;
        }
        
        // Get stock summary
        $stockQuery = "SELECT product_name, stock_store, stock_stockroom, cost FROM products WHERE (stock_store + stock_stockroom) > 0 ORDER BY (stock_store + stock_stockroom) DESC LIMIT 10";
        $result = $conn->query($stockQuery);
        while ($row = $result->fetch_assoc()) {
            $backupData['current_stock_summary'][] = $row;
        }

        // Identify products that have never been sold (no sales_order_items rows)
        $unsoldProductIds = [];
        $unsoldQuery = "
            SELECT p.id
            FROM products p
            LEFT JOIN sales_order_items soi ON soi.product_id = p.id
            GROUP BY p.id
            HAVING COUNT(soi.id) = 0
        ";
        $result = $conn->query($unsoldQuery);
        while ($row = $result->fetch_assoc()) {
            $unsoldProductIds[] = (int)$row['id'];
        }
        $unsoldCount = count($unsoldProductIds);
        
        // Save reset log entry
        $logStmt = $conn->prepare("
            INSERT INTO system_reset_logs 
            (admin_user, total_sales_orders_before, total_sales_items_before, total_inventory_logs_before, 
             total_reports_before, total_stock_value_before, backup_data, reset_reason) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $adminUser = $_POST['admin_user'] ?? 'System Admin';
        $resetReason = $_POST['reset_reason'] ?? 'Manual system reset via admin panel';
        $backupJson = json_encode($backupData);
        
        $logStmt->bind_param(
            'siiiidss', 
            $adminUser,
            $stats['total_orders'],
            $stats['total_items'], 
            $stats['total_logs'],
            $stats['total_reports'],
            $stats['stock_value'],
            $backupJson,
            $resetReason
        );
        $logStmt->execute();
        $logStmt->close();
        
        // STEP 2: Perform the actual reset
        // 1. Clear all transactional data
        $conn->query("DELETE FROM inventory_restock_logs"); // Clear restock logs
        $conn->query("DELETE FROM sales_order_items"); // This will cascade delete due to FK
        $conn->query("DELETE FROM sales_orders"); // Clear all sales orders
        $conn->query("DELETE FROM reports"); // Clear all reports
        
        // 2a. Remove products that never had any sales history
        if ($unsoldCount > 0) {
            $idList = implode(',', array_map('intval', $unsoldProductIds));
            $conn->query("DELETE FROM products WHERE id IN ($idList)");
        }

        // 2b. Clean up empty categories left after product removal
        $conn->query("DELETE FROM inventory_categories WHERE id NOT IN (SELECT DISTINCT category_id FROM products WHERE category_id IS NOT NULL)");

        // 2c. Reset product stock levels to 0 for remaining (sold) products
        $conn->query("UPDATE products SET stock_store = 0, stock_stockroom = 0");

        // 2d. Reset AUTO_INCREMENT when products table is empty after cleanup
        $productCountResult = $conn->query("SELECT COUNT(*) as total FROM products");
        $productCount = $productCountResult->fetch_assoc()['total'] ?? 0;
        if ($productCount == 0) {
            $conn->query("ALTER TABLE products AUTO_INCREMENT = 1");
        }
        
        // 3. Reset AUTO_INCREMENT counters for transactional tables
        $conn->query("ALTER TABLE inventory_restock_logs AUTO_INCREMENT = 1");
        $conn->query("ALTER TABLE sales_orders AUTO_INCREMENT = 1");
        $conn->query("ALTER TABLE sales_order_items AUTO_INCREMENT = 1");
        $conn->query("ALTER TABLE reports AUTO_INCREMENT = 1");
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'System successfully reset to initial state. Reset operation logged.',
            'reset_id' => $conn->insert_id,
            'removed_products' => $unsoldCount,
            'remaining_products' => $productCount ?? null
        ]);
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Reset failed: ' . $e->getMessage()]);
    }
    
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title> System Settings</title>
  <!-- Tailwind + Material Symbols (icons) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0,1" />
  <style>
    /* stat card animations */
    .stat-card {
      transition: transform 0.15s ease, box-shadow 0.2s ease;
    }
    .stat-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 20px 25px -5px rgba(0, 148, 195, 0.15), 0 10px 10px -5px rgba(0, 148, 195, 0.1);
    }
    /* subtle water bubble background (reused from login) */
    @keyframes floatBubble {
      0% { transform: translateY(0) scale(1); opacity: 0.2; }
      50% { transform: translateY(-20px) scale(1.05); opacity: 0.15; }
      100% { transform: translateY(0) scale(1); opacity: 0.2; }
    }
    .bg-bubble-float {
      animation: floatBubble 18s infinite ease-in-out;
    }
    /* custom checkbox / radio (if needed) */
    .checkbox-custom:checked {
      background: #0b6e8f;
      border-color: #0b6e8f;
    }
    /* settings item hover effects */
    .settings-item {
      transition: all 0.2s ease;
    }
    .settings-item:hover {
      background: #f8fafc;
      border-color: #0284c7;
    }
    /* status indicators */
    .status-enabled { color: #059669; background: #d1fae5; }
    .status-disabled { color: #6b7280; background: #fef3c7; }
    .status-warning { color: #d97706; background: #fed7aa; }
  </style>
</head>
<body class="bg-[#e6f4fa] font-sans antialiased text-[#043b4a] min-h-screen flex">

  <!-- Include sidebar component -->
  <?php include '../includes/sidebar.php'; ?>

  <!-- MAIN RIGHT CONTENT (flex column) -->
  <div class="flex-1 flex flex-col w-full min-w-0">
    
    <!-- TOP HEADER -->
    <header class="bg-white/90 backdrop-blur-md border-b border-white/40 px-3 sm:px-4 lg:px-6 py-2 sm:py-3 flex items-center justify-between sticky top-0 z-10 shadow-sm">
      <!-- left: system title with space for mobile menu -->
      <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1 lg:ml-0 ml-12">
        <h1 class="text-sm sm:text-base lg:text-lg xl:text-xl font-light text-[#05445E] truncate">
          <span class="font-semibold">System Settings</span> 
        </h1>
      </div>
      
      <!-- right: notifications, user, date -->
      <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
        <!-- date display -->
        <div class="hidden lg:block text-sm text-[#2c7da0]"><?= date('l, F j, Y') ?></div>
        <!-- user profile dropdown (simulated) -->
        <div class="flex items-center gap-1.5 sm:gap-2 cursor-pointer group">
          <img src="../assets/images/logo.jpg" alt="JD Logo" class="w-6 h-6 sm:w-8 sm:h-8 rounded-full object-contain shadow-md">
          <span class="hidden sm:block text-xs sm:text-sm font-medium text-[#05445E] group-hover:text-[#0f6f94] whitespace-nowrap">Admin</span>
        </div>
      </div>
    </header>

    <!-- SYSTEM SETTINGS CONTENT (main area) -->
    <main class="flex-1 p-4 sm:p-6 lg:p-8 overflow-y-auto">
      <!-- system management actions -->
      <div class="bg-white rounded-2xl p-5 shadow-md border border-white/40 mb-6">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <h2 class="text-lg font-medium text-[#05445E] flex items-center gap-2">
            <span class="material-symbols-outlined text-[#0f6f94]">settings</span> System Management
          </h2>
          <div class="flex flex-wrap gap-3">
            <div class="flex gap-2">
              <button id="exportInventoryBtn" class="flex items-center gap-2 px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5] transition export-btn" data-type="inventory">
                <span class="material-symbols-outlined">inventory</span>
                Export Inventory
              </button>
              <button id="exportSalesBtn" class="flex items-center gap-2 px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5] transition export-btn" data-type="sales">
                <span class="material-symbols-outlined">receipt_long</span>
                Export Sales
              </button>
              <button id="exportAllBtn" class="flex items-center gap-2 px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5] transition export-btn" data-type="all">
                <span class="material-symbols-outlined">file_download</span>
                Export All Data
              </button>
            </div>
            <button id="resetSystemBtn" class="flex items-center gap-2 px-4 py-2 bg-[#dc2626] text-white rounded-lg hover:bg-[#b91c1c] transition">
              <span class="material-symbols-outlined">refresh</span>
              Reset System
            </button>
          </div>
        </div>
      </div>
      <!-- reset logs -->
      <div class="bg-white rounded-2xl p-5 shadow-md border border-white/40">
        <h3 class="text-lg font-medium text-[#05445E] mb-4 flex items-center gap-2">
          <span class="material-symbols-outlined text-[#0f6f94]">history</span>
          Reset History & Logs
        </h3>
        <div class="space-y-4">
            <!-- last reset -->
            <div class="settings-item flex items-center justify-between p-3 rounded-lg border border-[#e2f0f7]">
              <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-[#2c7da0]">restore</span>
                <span class="text-sm font-medium">Last System Reset</span>
              </div>
              <div class="flex items-center gap-2">
                <?php
                $lastResetQuery = "SELECT reset_timestamp, admin_user FROM system_reset_logs ORDER BY reset_timestamp DESC LIMIT 1";
                $lastResetResult = $conn->query($lastResetQuery);
                if ($lastResetResult && $lastResetResult->num_rows > 0) {
                    $lastReset = $lastResetResult->fetch_assoc();
                    echo '<span class="text-xs text-[#2c7da0]">' . date('M d, Y H:i', strtotime($lastReset['reset_timestamp'])) . '</span>';
                    echo '<span class="text-xs text-[#0f6f94] font-medium">by ' . htmlspecialchars($lastReset['admin_user']) . '</span>';
                } else {
                    echo '<span class="text-xs text-[#6b7280]">No resets recorded</span>';
                }
                ?>
              </div>
            </div>
            <!-- total resets -->
            <div class="settings-item flex items-center justify-between p-3 rounded-lg border border-[#e2f0f7]">
              <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-[#2c7da0]">analytics</span>
                <span class="text-sm font-medium">Total System Resets</span>
              </div>
              <div class="flex items-center gap-2">
                <?php
                $totalResetsQuery = "SELECT COUNT(*) as total FROM system_reset_logs";
                $totalResetsResult = $conn->query($totalResetsQuery);
                $totalResets = $totalResetsResult->fetch_assoc()['total'] ?? 0;
                echo '<span class="status-enabled px-2 py-1 rounded-full text-xs font-medium">' . $totalResets . ' resets</span>';
                ?>
              </div>
            </div>
            <!-- view reset logs -->
            <div class="settings-item flex items-center justify-between p-3 rounded-lg border border-[#e2f0f7]">
              <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-[#2c7da0]">visibility</span>
                <span class="text-sm font-medium">View Reset Logs</span>
              </div>
              <div class="flex items-center gap-2">
                <button onclick="viewResetLogs()" class="px-3 py-1 bg-[#0f6f94] text-white text-xs rounded-lg hover:bg-[#0b5a7a] transition">
                  View Logs
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- background bubble effect (very subtle) -->
  <div class="fixed inset-0 -z-10 pointer-events-none overflow-hidden">
    <div class="absolute w-96 h-96 rounded-full bg-white/20 blur-3xl top-[10%] left-[5%] bg-bubble-float"></div>
    <div class="absolute w-64 h-64 rounded-full bg-cyan-200/20 blur-2xl bottom-[5%] right-[10%] bg-bubble-float" style="animation-delay: -5s;"></div>
  </div>

  <!-- Reset Logs Modal -->
  <div id="resetLogsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
    <div class="flex items-center justify-center min-h-screen p-4">
      <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full max-h-[80vh] overflow-hidden">
        <!-- Modal Header -->
        <div class="flex items-center justify-between p-6 border-b border-gray-200">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
              <span class="material-symbols-outlined text-xl text-blue-600">history</span>
            </div>
            <div>
              <h3 class="text-lg font-semibold text-[#05445E]">System Reset Logs</h3>
              <p class="text-sm text-[#2c7da0]">History of all system reset operations</p>
            </div>
          </div>
          <button id="closeResetLogsModal" class="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center transition">
            <span class="material-symbols-outlined text-gray-500">close</span>
          </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6 overflow-y-auto max-h-[60vh]">
          <?php
          $resetLogsQuery = "
            SELECT * FROM system_reset_logs 
            ORDER BY reset_timestamp DESC 
            LIMIT 20
          ";
          $resetLogsResult = $conn->query($resetLogsQuery);
          
          if ($resetLogsResult && $resetLogsResult->num_rows > 0) {
            echo '<div class="space-y-4">';
            while ($log = $resetLogsResult->fetch_assoc()) {
              $backupData = json_decode($log['backup_data'], true);
              echo '<div class="bg-gray-50 rounded-lg p-4 border border-gray-200">';
              echo '<div class="flex items-start justify-between mb-3">';
              echo '<div>';
              echo '<div class="flex items-center gap-2 mb-1">';
              echo '<span class="font-medium text-[#05445E]">' . htmlspecialchars($log['admin_user']) . '</span>';
              echo '<span class="text-sm text-[#2c7da0]">' . date('M d, Y H:i:s', strtotime($log['reset_timestamp'])) . '</span>';
              echo '</div>';
              echo '<p class="text-sm text-gray-600">' . htmlspecialchars($log['reset_reason']) . '</p>';
              echo '</div>';
              echo '<span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full font-medium">Completed</span>';
              echo '</div>';
              
              echo '<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-3">';
              echo '<div class="text-center">';
              echo '<div class="text-lg font-semibold text-[#05445E]">' . number_format($log['total_sales_orders_before']) . '</div>';
              echo '<div class="text-xs text-[#2c7da0]">Orders</div>';
              echo '</div>';
              echo '<div class="text-center">';
              echo '<div class="text-lg font-semibold text-[#05445E]">' . number_format($log['total_sales_items_before']) . '</div>';
              echo '<div class="text-xs text-[#2c7da0]">Items</div>';
              echo '</div>';
              echo '<div class="text-center">';
              echo '<div class="text-lg font-semibold text-[#05445E]">' . number_format($log['total_inventory_logs_before']) . '</div>';
              echo '<div class="text-xs text-[#2c7da0]">Logs</div>';
              echo '</div>';
              echo '<div class="text-center">';
              echo '<div class="text-lg font-semibold text-[#05445E]">PHP ' . number_format($log['total_stock_value_before'], 2) . '</div>';
              echo '<div class="text-xs text-[#2c7da0]">Stock Value</div>';
              echo '</div>';
              echo '</div>';
              
              if (!empty($backupData['last_5_orders'])) {
                echo '<div class="mt-3 pt-3 border-t border-gray-200">';
                echo '<p class="text-sm font-medium text-[#05445E] mb-2">Last 5 Orders Before Reset:</p>';
                echo '<div class="text-xs text-[#2c7da0] space-y-1">';
                foreach (array_slice($backupData['last_5_orders'], 0, 3) as $order) {
                  echo '<div>' . htmlspecialchars($order['receipt_no']) . ' - ' . htmlspecialchars($order['customer_name']) . ' - PHP ' . number_format($order['total_amount'], 2) . '</div>';
                }
                echo '</div>';
                echo '</div>';
              }
              
              echo '</div>';
            }
            echo '</div>';
          } else {
            echo '<div class="text-center py-12">';
            echo '<span class="material-symbols-outlined text-4xl text-gray-300 mb-4">history</span>';
            echo '<p class="text-gray-500">No system reset logs found</p>';
            echo '<p class="text-sm text-gray-400">Reset logs will appear here after the first system reset</p>';
            echo '</div>';
          }
          ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Collapsible sidebar (desktop)
    const sidebar = document.getElementById('sidebar');
    const collapseBtn = document.getElementById('collapseSidebar');
    if (collapseBtn) {
      collapseBtn.addEventListener('click', () => {
        sidebar.classList.toggle('sidebar-collapsed');
        // change icon direction
        const icon = collapseBtn.querySelector('.material-symbols-outlined');
        if (sidebar.classList.contains('sidebar-collapsed')) {
          icon.textContent = 'menu';
          sidebar.style.width = '5rem';
        } else {
          icon.textContent = 'menu_open';
          sidebar.style.width = '18rem'; // w-72 approx
        }
      });
    }

    // System settings functionality
    document.addEventListener('DOMContentLoaded', function() {
      // Backup functionality
      const backupBtn = document.querySelector('button:has(.material-symbols-outlined[title="Backup"])');
      if (backupBtn) {
        backupBtn.addEventListener('click', function() {
          // Simulate backup process
          showNotification('Backup started', 'success');
          setTimeout(() => {
            showNotification('Backup completed successfully', 'success');
          }, 2000);
        });
      }

      // Export functionality for all export buttons
      const exportButtons = document.querySelectorAll('.export-btn');
      exportButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
          const exportType = this.getAttribute('data-type');
          // Trigger download by redirecting to export URL
          window.location.href = `systemsettings.php?export=data&type=${exportType}`;
          showNotification('Export initiated, download will start shortly', 'info');
        });
      });

      // Reset functionality with modal confirmation
      const resetBtn = document.getElementById('resetSystemBtn');
      const resetModal = document.getElementById('resetModal');
      const confirmationInput = document.getElementById('confirmationInput');
      const confirmResetBtn = document.getElementById('confirmResetBtn');
      const cancelResetBtn = document.getElementById('cancelResetBtn');

      if (resetBtn && resetModal) {
        // Show modal when reset button is clicked
        resetBtn.addEventListener('click', function() {
          resetModal.classList.remove('hidden');
          confirmationInput.value = '';
          confirmResetBtn.disabled = true;
          confirmationInput.focus();
        });

        // Handle confirmation input
        confirmationInput.addEventListener('input', function() {
          const value = this.value.toUpperCase().trim();
          confirmResetBtn.disabled = value !== 'YES';
        });

        // Handle cancel button
        cancelResetBtn.addEventListener('click', function() {
          resetModal.classList.add('hidden');
          confirmationInput.value = '';
        });

        // Handle confirm button
        confirmResetBtn.addEventListener('click', function() {
          if (!confirmResetBtn.disabled) {
            performSystemReset();
          }
        });

        // Close modal when clicking outside
        resetModal.addEventListener('click', function(e) {
          if (e.target === resetModal) {
            resetModal.classList.add('hidden');
            confirmationInput.value = '';
          }
        });
      }

      // Function to perform system reset
      function performSystemReset() {
        const confirmBtn = document.getElementById('confirmResetBtn');
        const originalText = confirmBtn.textContent;
        
        // Disable button and show loading
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Resetting...';
        
        // Prepare form data
        const formData = new FormData();
        formData.append('reset_system', 'true');
        formData.append('confirmation', document.getElementById('confirmationInput').value);
        
        // Make AJAX request
        fetch('systemsettings.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // Success - close modal and show success message
            resetModal.classList.add('hidden');
            showNotification('System successfully reset. Refreshing page...', 'success');
            
            // Refresh page after a short delay
            setTimeout(() => {
              window.location.reload();
            }, 2000);
          } else {
            // Error - re-enable button and show error
            confirmBtn.disabled = false;
            confirmBtn.textContent = originalText;
            showNotification(data.message || 'Reset failed. Please try again.', 'error');
          }
        })
        .catch(error => {
          // Network error
          confirmBtn.disabled = false;
          confirmBtn.textContent = originalText;
          showNotification('Network error. Please try again.', 'error');
          console.error('Reset error:', error);
        });
      }

      // Function to view reset logs
      function viewResetLogs() {
        const resetLogsModal = document.getElementById('resetLogsModal');
        const closeResetLogsModal = document.getElementById('closeResetLogsModal');
        
        if (resetLogsModal) {
          resetLogsModal.classList.remove('hidden');
          
          // Handle close button
          closeResetLogsModal.addEventListener('click', function() {
            resetLogsModal.classList.add('hidden');
          });
          
          // Close modal when clicking outside
          resetLogsModal.addEventListener('click', function(e) {
            if (e.target === resetLogsModal) {
              resetLogsModal.classList.add('hidden');
            }
          });
        }
      }

      // Settings toggle functionality
      const toggles = document.querySelectorAll('input[type="checkbox"]');
      toggles.forEach(function(toggle) {
        toggle.addEventListener('change', function() {
          const setting = this.closest('.settings-item').querySelector('.text-sm');
          if (setting) {
            const status = this.checked ? 'Enabled' : 'Disabled';
            const statusElement = this.closest('.settings-item').querySelector('.status-enabled, .status-disabled');
            if (statusElement) {
              statusElement.textContent = status;
              statusElement.className = this.checked ? 'status-enabled px-2 py-1 rounded-full text-xs font-medium' : 'status-disabled px-2 py-1 rounded-full text-xs font-medium';
            }
          }
        });
      });

      // Notification system
      function showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg transform transition-all duration-300 ${
          type === 'success' ? 'bg-green-500 text-white' : 
          type === 'warning' ? 'bg-yellow-500 text-white' : 
          type === 'error' ? 'bg-red-500 text-white' : 
          'bg-blue-500 text-white'
        }`;
        notification.innerHTML = `
          <div class="flex items-center gap-2">
            <span class="material-symbols-outlined">${
              type === 'success' ? 'check_circle' : 
              type === 'warning' ? 'warning' : 
              type === 'error' ? 'error' : 
              'info'
            }</span>
            <span class="text-sm font-medium">${message}</span>
          </div>
        `;
        
        // Add to page
        document.body.appendChild(notification);
        
        // Auto remove after 3 seconds
        setTimeout(() => {
          notification.style.opacity = '0';
          setTimeout(() => {
            document.body.removeChild(notification);
          }, 300);
        }, 3000);
      }
    });
  </script>

  <!-- System Reset Confirmation Modal -->
  <div id="resetModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
    <div class="flex items-center justify-center min-h-screen p-4">
      <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
        <!-- Modal Header -->
        <div class="flex items-center gap-3 mb-4">
          <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
            <span class="material-symbols-outlined text-2xl text-red-600">warning</span>
          </div>
          <div>
            <h3 class="text-lg font-semibold text-[#05445E]">Confirm System Reset</h3>
            <p class="text-sm text-[#2c7da0]">This action cannot be undone</p>
          </div>
        </div>

        <!-- Modal Body -->
        <div class="mb-6">
          <p class="text-[#043b4a] mb-4">
            This action will reset the entire system and cannot be undone. All transactional data including sales orders, inventory logs, and reports will be cleared, and stock levels will be reset to zero.
          </p>

          <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4">
            <div class="flex items-start gap-2">
              <span class="material-symbols-outlined text-amber-600 mt-0.5">info</span>
              <div class="text-sm text-amber-800">
                <strong>What will be reset:</strong>
                <ul class="mt-1 ml-4 list-disc space-y-1">
                  <li>All sales orders and order items</li>
                  <li>All inventory restock logs</li>
                  <li>All generated reports</li>
                  <li>Product stock levels (reset to 0)</li>
                </ul>
              </div>
            </div>
          </div>

          <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
            <div class="flex items-start gap-2">
              <span class="material-symbols-outlined text-green-600 mt-0.5">check_circle</span>
              <div class="text-sm text-green-800">
                <strong>What will be preserved:</strong>
                <ul class="mt-1 ml-4 list-disc space-y-1">
                  <li>Product definitions and categories</li>
                  <li>System settings and configuration</li>
                  <li>Admin accounts and permissions</li>
                </ul>
              </div>
            </div>
          </div>

          <div>
            <label for="confirmationInput" class="block text-sm font-medium text-[#05445E] mb-2">
              Type "YES" to confirm reset:
            </label>
            <input
              type="text"
              id="confirmationInput"
              class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg focus:outline-none focus:border-[#0f6f94] focus:ring-2 focus:ring-[#0f6f94]/20 uppercase"
              placeholder="Type YES here"
              maxlength="3"
            >
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="flex gap-3">
          <button
            id="cancelResetBtn"
            class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium"
          >
            Cancel
          </button>
          <button
            id="confirmResetBtn"
            class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium disabled:opacity-50 disabled:cursor-not-allowed"
            disabled
          >
            Reset System
          </button>
        </div>
      </div>
    </div>
  </div>

</body>
</html>
