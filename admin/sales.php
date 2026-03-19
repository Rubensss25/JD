<?php
declare(strict_types=1);

// Include session validation and cache control
require_once __DIR__ . '/../includes/auth_check.php';

require_once __DIR__ . '/../config/connect.php';
require_once __DIR__ . '/../includes/excel_xml_export.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function peso(float $value): string
{
    return 'PHP ' . number_format($value, 2);
}

function paymentStatus(float $totalAmount, float $paymentAmount): string
{
    if ($totalAmount <= 0.0) {
        return 'cancelled';
    }
    return $paymentAmount >= $totalAmount ? 'paid' : 'pending';
}

function paymentStatusLabel(string $status): string
{
    if ($status === 'pending') {
        return 'Pending';
    }
    if ($status === 'cancelled') {
        return 'Cancelled';
    }
    return 'Paid';
}

function paymentStatusClass(string $status): string
{
    if ($status === 'pending') {
        return 'payment-pending';
    }
    if ($status === 'cancelled') {
        return 'payment-cancelled';
    }
    return 'payment-paid';
}

function tableExists(mysqli $conn, string $tableName): bool
{
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return (bool)$cache[$tableName];
    }
    $safeName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$safeName}'");
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $cache[$tableName] = $exists;
    return $exists;
}

function buildWhereClause(string $search, string $period, string $status, string &$bindTypes, array &$bindValues): string
{
    $whereParts = [];
    $bindTypes = '';
    $bindValues = [];

    if ($search !== '') {
        $whereParts[] = '(so.receipt_no LIKE CONCAT("%", ?, "%")
            OR so.customer_name LIKE CONCAT("%", ?, "%")
            OR EXISTS (
                SELECT 1
                FROM sales_order_items soi2
                WHERE soi2.order_id = so.id
                  AND soi2.product_name LIKE CONCAT("%", ?, "%")
            ))';
        $bindTypes .= 'sss';
        $bindValues[] = $search;
        $bindValues[] = $search;
        $bindValues[] = $search;
    }

    if ($period === 'today') {
        $whereParts[] = 'DATE(so.created_at) = CURDATE()';
    } elseif ($period === 'week') {
        $whereParts[] = 'so.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
    } elseif ($period === 'month') {
        $whereParts[] = 'so.created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")';
    } elseif ($period === 'year') {
        $whereParts[] = 'YEAR(so.created_at) = YEAR(CURDATE())';
    }

    if ($status === 'paid') {
        $whereParts[] = 'so.payment_amount >= so.total_amount';
    } elseif ($status === 'pending') {
        $whereParts[] = 'so.payment_amount < so.total_amount';
    } elseif ($status === 'cancelled') {
        $whereParts[] = 'so.total_amount <= 0';
    }

    return $whereParts === [] ? '' : (' WHERE ' . implode(' AND ', $whereParts));
}

function buildUrl(array $params): string
{
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $clean[$key] = $value;
    }
    $query = http_build_query($clean);
    return 'sales.php' . ($query !== '' ? ('?' . $query) : '');
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS sales_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_no VARCHAR(50) NOT NULL,
        customer_name VARCHAR(150) NOT NULL DEFAULT 'Walk-in Customer',
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        change_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_receipt_no (receipt_no),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS sales_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(150) NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        quantity INT NOT NULL DEFAULT 1,
        line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_order_id (order_id),
        KEY idx_product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$exportTodayRequested = isset($_GET['export_today']) && (string)$_GET['export_today'] === '1';
if ($exportTodayRequested) {
    $canJoinProducts = tableExists($conn, 'products');
    $productCostSelect = $canJoinProducts ? 'COALESCE(p.cost, 0)' : '0';
    $productColorSpecsSelect = $canJoinProducts ? 'COALESCE(p.color_specs, "")' : '""';
    $exportSql = 'SELECT so.customer_name, soi.product_name, soi.quantity, soi.unit_price, soi.line_total, '
                 . $productCostSelect . ' AS product_cost, '
                 . $productColorSpecsSelect . ' AS color_specs, so.created_at
                  FROM sales_orders so
                  INNER JOIN sales_order_items soi ON soi.order_id = so.id';
    if ($canJoinProducts) {
        $exportSql .= ' LEFT JOIN products p ON p.id = soi.product_id';
    }
    $exportSql .= ' WHERE DATE(so.created_at) = CURDATE()
                    ORDER BY so.created_at DESC, so.id DESC, soi.id ASC';
    $exportStmt = $conn->prepare($exportSql);
    $exportStmt->execute();
    $result = $exportStmt->get_result();

    $filename = 'sales_today_' . date('Ymd_His');
    $rowsForExport = [];
    $totalNetGross = 0.0;
    $salesTotalAmount = 0.0;
    while ($row = $result->fetch_assoc()) {
        $quantity = (int)$row['quantity'];
        $unitPrice = (float)$row['unit_price'];
        $totalAmount = (float)$row['line_total'];
        $productCost = (float)$row['product_cost'];
        $netGross = $totalAmount - ($productCost * $quantity);
        $totalNetGross += $netGross;
        $salesTotalAmount += $totalAmount;
        $rowsForExport[] = [
            'product_name' => (string)$row['product_name'],
            'color_specs' => (string)$row['color_specs'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => $totalAmount,
            'customer_name' => (string)$row['customer_name'],
            'net_gross' => $netGross,
            'date' => (string)$row['created_at'],
        ];
    }
    $exportStmt->close();
    excelXmlOutputSalesRows(
        $filename,
        'Sales Today',
        $rowsForExport,
        $totalNetGross,
        'GROSS PROFIT',
        $salesTotalAmount,
        'SALES TOTAL'
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    $orderId = (int)($_POST['mark_paid'] ?? 0);
    if ($orderId > 0) {
        $updateStmt = $conn->prepare(
            'UPDATE sales_orders
             SET payment_amount = total_amount
             WHERE id = ? AND total_amount > 0 AND payment_amount < total_amount'
        );
        $updateStmt->bind_param('i', $orderId);
        $updateStmt->execute();
        $affected = $updateStmt->affected_rows;
        $updateStmt->close();
        $message = $affected > 0 ? 'Sale marked as paid.' : 'No update performed.';
    } else {
        $message = 'Invalid sale selected.';
    }
    header('Location: sales.php?alert=info&message=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_sale'])) {
    $orderId = (int)($_POST['delete_sale'] ?? 0);
    if ($orderId > 0) {
        $conn->begin_transaction();
        try {
            $deleteItems = $conn->prepare('DELETE FROM sales_order_items WHERE order_id = ?');
            $deleteItems->bind_param('i', $orderId);
            $deleteItems->execute();
            $deleteItems->close();

            $deleteOrder = $conn->prepare('DELETE FROM sales_orders WHERE id = ?');
            $deleteOrder->bind_param('i', $orderId);
            $deleteOrder->execute();
            $affected = $deleteOrder->affected_rows;
            $deleteOrder->close();

            $conn->commit();
            $message = $affected > 0 ? 'Sale deleted.' : 'Sale not found or already removed.';
        } catch (Throwable $e) {
            $conn->rollback();
            $message = 'Delete failed: ' . $e->getMessage();
        }
    } else {
        $message = 'Invalid sale selected.';
    }
    header('Location: sales.php?alert=info&message=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_sale'])) {
    $orderId = (int)($_POST['cancel_sale'] ?? 0);
    if ($orderId > 0) {
        $conn->begin_transaction();
        try {
            // Only allow cancel on pending sales
            $checkStmt = $conn->prepare(
                'SELECT total_amount, payment_amount FROM sales_orders WHERE id = ? FOR UPDATE'
            );
            $checkStmt->bind_param('i', $orderId);
            $checkStmt->execute();
            $checkRow = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if (!$checkRow || (float)$checkRow['total_amount'] <= 0 || (float)$checkRow['payment_amount'] >= (float)$checkRow['total_amount']) {
                throw new RuntimeException('Sale is not pending or already cancelled.');
            }

            // Restore inventory for each item
            $itemsStmt = $conn->prepare(
                'SELECT product_id, quantity FROM sales_order_items WHERE order_id = ?'
            );
            $itemsStmt->bind_param('i', $orderId);
            $itemsStmt->execute();
            $itemsRes = $itemsStmt->get_result();
            $updates = [];
            while ($row = $itemsRes->fetch_assoc()) {
                $pid = (int)$row['product_id'];
                $qty = (int)$row['quantity'];
                if ($pid > 0 && $qty > 0) {
                    $updates[] = ['pid' => $pid, 'qty' => $qty];
                }
            }
            $itemsStmt->close();

            if ($updates !== []) {
                $updateStockStmt = $conn->prepare(
                    'UPDATE products SET stock_store = stock_store + ? WHERE id = ?'
                );
                foreach ($updates as $u) {
                    $updateStockStmt->bind_param('ii', $u['qty'], $u['pid']);
                    $updateStockStmt->execute();
                }
                $updateStockStmt->close();
            }

            // Mark order as cancelled by zeroing totals
            $cancelStmt = $conn->prepare(
                'UPDATE sales_orders
                 SET total_amount = 0, payment_amount = 0, change_amount = 0
                 WHERE id = ?'
            );
            $cancelStmt->bind_param('i', $orderId);
            $cancelStmt->execute();
            $affected = $cancelStmt->affected_rows;
            $cancelStmt->close();

            $conn->commit();
            $message = $affected > 0 ? 'Sale cancelled and stock restored.' : 'No changes made.';
        } catch (Throwable $e) {
            $conn->rollback();
            $message = 'Cancel failed: ' . $e->getMessage();
        }
    } else {
        $message = 'Invalid sale selected.';
    }
    header('Location: sales.php?alert=info&message=' . urlencode($message));
    exit;
}

$search = '';
$periodFilter = 'all';
$statusFilter = 'all';

$bindTypes = '';
$bindValues = [];
$whereClause = '';

$countSql = 'SELECT COUNT(*) AS total_rows FROM sales_orders so';
$countStmt = $conn->prepare($countSql);
if ($bindTypes !== '') {
    $countStmt->bind_param($bindTypes, ...$bindValues);
}
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$countStmt->close();
$totalRows = (int)($countRow['total_rows'] ?? 0);
$overallSalesCountRow = $conn->query('SELECT COUNT(*) AS total_rows FROM sales_orders')->fetch_assoc();
$overallSalesCount = (int)($overallSalesCountRow['total_rows'] ?? 0);

$salesSql = 'SELECT so.id, so.receipt_no, so.customer_name, so.total_amount, so.payment_amount, so.created_at,
                    COALESCE(SUM(soi.quantity), 0) AS total_qty,
                    GROUP_CONCAT(DISTINCT soi.product_name ORDER BY soi.product_name SEPARATOR "||") AS product_names
             FROM sales_orders so
             LEFT JOIN sales_order_items soi ON soi.order_id = so.id'
             . $whereClause .
            ' GROUP BY so.id, so.receipt_no, so.customer_name, so.total_amount, so.payment_amount, so.created_at
              ORDER BY so.created_at DESC, so.id DESC';
$salesStmt = $conn->prepare($salesSql);
if ($bindTypes !== '') {
    $salesStmt->bind_param($bindTypes, ...$bindValues);
}
$salesStmt->execute();
$salesResult = $salesStmt->get_result();

$salesRows = [];
while ($row = $salesResult->fetch_assoc()) {
    $products = array_values(array_filter(explode('||', (string)($row['product_names'] ?? ''))));
    $totalAmount = (float)$row['total_amount'];
    $paymentAmount = (float)$row['payment_amount'];
    $status = paymentStatus($totalAmount, $paymentAmount);
    $salesRows[] = [
        'id' => (int)$row['id'],
        'receipt_no' => (string)$row['receipt_no'],
        'customer_name' => (string)$row['customer_name'],
        'products' => $products,
        'quantity' => (int)$row['total_qty'],
        'total_amount' => $totalAmount,
        'payment_amount' => $paymentAmount,
        'status' => $status,
        'status_label' => paymentStatusLabel($status),
        'status_class' => paymentStatusClass($status),
        'created_at' => (string)$row['created_at'],
        'display_date' => date('d M Y', strtotime((string)$row['created_at'])),
    ];
}
$salesStmt->close();

$statsToday = $conn->query(
    'SELECT COALESCE(SUM(total_amount), 0) AS sales_today
     FROM sales_orders
     WHERE DATE(created_at) = CURDATE()
       AND payment_amount >= total_amount
       AND total_amount > 0'
)->fetch_assoc();
$todaySales = (float)($statsToday['sales_today'] ?? 0);

$canJoinProductsForRevenue = tableExists($conn, 'products');
$todayRevenueSql = 'SELECT COALESCE(SUM(soi.line_total - ('
    . ($canJoinProductsForRevenue ? 'COALESCE(p.cost, 0)' : '0')
    . ' * soi.quantity)), 0) AS revenue_today
     FROM sales_orders so
     INNER JOIN sales_order_items soi ON soi.order_id = so.id';
if ($canJoinProductsForRevenue) {
    $todayRevenueSql .= ' LEFT JOIN products p ON p.id = soi.product_id';
}
$todayRevenueSql .= ' WHERE DATE(so.created_at) = CURDATE()
                     AND so.payment_amount >= so.total_amount
                     AND so.total_amount > 0';
$statsTodayRevenue = $conn->query($todayRevenueSql)->fetch_assoc();
$todayRevenue = (float)($statsTodayRevenue['revenue_today'] ?? 0);

$statsTransactions = $conn->query(
    'SELECT
        COUNT(*) AS total_transactions,
        SUM(CASE WHEN payment_amount < total_amount THEN 1 ELSE 0 END) AS pending_transactions
     FROM sales_orders'
)->fetch_assoc();
$totalTransactions = (int)($statsTransactions['total_transactions'] ?? 0);
$pendingTransactions = (int)($statsTransactions['pending_transactions'] ?? 0);

$statsCustomersToday = $conn->query(
    'SELECT COUNT(DISTINCT customer_name) AS total_customers_today
     FROM sales_orders
     WHERE DATE(created_at) = CURDATE()'
)->fetch_assoc();
$totalCustomersToday = (int)($statsCustomersToday['total_customers_today'] ?? 0);

$topCustomer = $conn->query(
    'SELECT customer_name, COALESCE(SUM(total_amount), 0) AS total_amount
     FROM sales_orders
     GROUP BY customer_name
     ORDER BY total_amount DESC
     LIMIT 1'
)->fetch_assoc();
$topCustomerName = $topCustomer ? (string)$topCustomer['customer_name'] : 'No data';
$topCustomerTotal = $topCustomer ? (float)$topCustomer['total_amount'] : 0.0;

$exportTodayUrl = buildUrl(['export_today' => 1]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0,1" />
  <style>
    .stat-card { transition: transform 0.15s ease, box-shadow 0.2s ease; }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 20px 25px -5px rgba(0, 148, 195, 0.15), 0 10px 10px -5px rgba(0, 148, 195, 0.1); }
    @keyframes floatBubble {
      0% { transform: translateY(0) scale(1); opacity: 0.2; }
      50% { transform: translateY(-20px) scale(1.05); opacity: 0.15; }
      100% { transform: translateY(0) scale(1); opacity: 0.2; }
    }
    .bg-bubble-float { animation: floatBubble 18s infinite ease-in-out; }
    .checkbox-custom:checked { background: #0b6e8f; border-color: #0b6e8f; }
    .sales-item { transition: all 0.2s ease; }
    .sales-item:hover { background: #f8fafc; border-color: #0284c7; }
    .payment-paid { color: #059669; background: #d1fae5; }
    .payment-pending { color: #d97706; background: #fed7aa; }
    .payment-cancelled { color: #dc2626; background: #fecaca; }
    .modal-backdrop { background: rgba(2, 36, 52, .45); backdrop-filter: blur(2px); }
  </style>
</head>
<body class="bg-[#e6f4fa] font-sans antialiased text-[#043b4a] min-h-screen flex">
  <?php include '../includes/sidebar.php'; ?>

  <div class="flex-1 flex flex-col w-full min-w-0">
    <header class="bg-white/90 backdrop-blur-md border-b border-white/40 px-3 sm:px-4 lg:px-6 py-2 sm:py-3 flex items-center justify-between sticky top-0 z-10 shadow-sm">
      <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1 lg:ml-0 ml-12">
        <h1 class="text-sm sm:text-base lg:text-lg xl:text-xl font-light text-[#05445E] truncate">
          <span class="font-semibold">Sales</span> 
        </h1>
      </div>
      <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
        <div class="hidden lg:block text-sm text-[#2c7da0]"><?= date('l, F j, Y') ?></div>
        <div class="flex items-center gap-1.5 sm:gap-2 cursor-pointer group">
          <img src="../assets/images/logo.jpg" alt="JD Logo" class="w-6 h-6 sm:w-8 sm:h-8 rounded-full object-contain shadow-md">
          <span class="hidden sm:block text-xs sm:text-sm font-medium text-[#05445E] group-hover:text-[#0f6f94] whitespace-nowrap">Admin</span>
        </div>
      </div>
    </header>

    <main class="flex-1 p-4 sm:p-6 lg:p-8 overflow-y-auto">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5 mb-8">
        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40 flex items-start justify-between">
          <div>
            <p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Today's Sales</p>
            <select id="todaySalesMetricFilter" class="mt-2 text-xs px-2 py-1 border border-[#b7d9e9] rounded-lg bg-white text-[#05445E] focus:outline-none focus:border-[#0f6f94]">
              <option value="sales" selected>Total Sales</option>
              <option value="revenue">Gross Profit</option>
            </select>
            <p id="todaySalesValue"
               data-sales="<?= number_format($todaySales, 2, '.', '') ?>"
               data-revenue="<?= number_format($todayRevenue, 2, '.', '') ?>"
               class="text-3xl font-semibold text-[#05445E] mt-2"><?= h(peso($todaySales)) ?></p>
            <span id="todaySalesMetricBadge" class="text-xs text-[#0f6f94] bg-[#dff3fc] px-2 py-0.5 rounded-full mt-2 inline-block">Total Sales</span>
          </div>
          <div class="bg-[#e0f0f9] p-3 rounded-full">
            <span class="material-symbols-outlined text-3xl text-[#0f6f94]">trending_up</span>
          </div>
        </div>
        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40 flex items-start justify-between">
          <div>
            <p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Transactions</p>
            <p class="text-3xl font-semibold text-[#05445E] mt-1"><?= number_format($totalTransactions) ?></p>
            <span class="text-xs text-[#0f6f94] bg-[#dff3fc] px-2 py-0.5 rounded-full mt-2 inline-block"><?= number_format($pendingTransactions) ?> pending</span>
          </div>
          <div class="bg-[#e0f0f9] p-3 rounded-full">
            <span class="material-symbols-outlined text-3xl text-[#0f6f94]">receipt_long</span>
          </div>
        </div>
        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40 flex items-start justify-between">
          <div>
            <p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Total Customers Today</p>
            <p class="text-3xl font-semibold text-[#05445E] mt-1"><?= number_format($totalCustomersToday) ?></p>
            <span class="text-xs text-[#0f6f94] bg-[#dff3fc] px-2 py-0.5 rounded-full mt-2 inline-block">as of <?= h(date('M d, Y')) ?></span>
          </div>
          <div class="bg-[#e0f0f9] p-3 rounded-full">
            <span class="material-symbols-outlined text-3xl text-[#0f6f94]">groups</span>
          </div>
        </div>
        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40 flex items-start justify-between">
          <div>
            <p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Top Customer</p>
            <p class="text-2xl font-semibold text-[#05445E] mt-1 truncate max-w-[140px]"><?= h($topCustomerName) ?></p>
            <span class="text-xs text-[#0f6f94] bg-[#dff3fc] px-2 py-0.5 rounded-full mt-2 inline-block"><?= h(peso($topCustomerTotal)) ?> total</span>
          </div>
          <div class="bg-[#e0f0f9] p-3 rounded-full">
            <span class="material-symbols-outlined text-3xl text-[#0f6f94]">person</span>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-2xl p-5 shadow-md border border-white/40 mb-6">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <h2 class="text-lg font-medium text-[#05445E] flex items-center gap-2">
            <span class="material-symbols-outlined text-[#0f6f94]">point_of_sale</span> Sales Management
          </h2>
          <div class="flex flex-wrap gap-3">
            <button type="button" id="openGenerateReportModal" class="flex items-center gap-2 px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5] transition">
              <span class="material-symbols-outlined">description</span>
              Generate Report
            </button>
          </div>
        </div>

        <form method="get" id="salesFilterForm" class="mt-4 flex flex-col lg:flex-row flex-wrap gap-4">
          <div class="flex-1 min-w-[220px]">
            <div class="relative">
              <span class="material-symbols-outlined absolute left-3 top-1/2 transform -translate-y-1/2 text-[#2c7da0]">search</span>
              <input type="text" id="salesSearchInput" name="q" value="<?= h($search) ?>" placeholder="Search transactions..." class="w-full pl-10 pr-4 py-2 border border-[#b7d9e9] rounded-lg focus:outline-none focus:border-[#0f6f94]">
            </div>
          </div>
          <select id="salesPeriodFilter" name="period" class="px-4 py-2 border border-[#b7d9e9] rounded-lg bg-white focus:outline-none focus:border-[#0f6f94]">
            <option value="all" <?= $periodFilter === 'all' ? 'selected' : '' ?>>All Time Period</option>
            <option value="today" <?= $periodFilter === 'today' ? 'selected' : '' ?>>Today</option>
            <option value="week" <?= $periodFilter === 'week' ? 'selected' : '' ?>>This Week</option>
            <option value="month" <?= $periodFilter === 'month' ? 'selected' : '' ?>>This Month</option>
            <option value="year" <?= $periodFilter === 'year' ? 'selected' : '' ?>>This Year</option>
            <option value="custom">Custom Range…</option>
          </select>
          <select id="salesStatusFilter" name="status" class="px-4 py-2 border border-[#b7d9e9] rounded-lg bg-white focus:outline-none focus:border-[#0f6f94]">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Payment Status</option>
            <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
          </select>
        </form>
      </div>

      <div class="bg-white rounded-2xl p-5 shadow-md border border-white/40">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-lg font-medium text-[#05445E] flex items-center gap-2">
            <span class="material-symbols-outlined text-[#0f6f94]">receipt_long</span> Recent Sales
          </h2>
          <div class="flex items-center gap-2">
            <span id="salesResultsSummaryTop" class="text-sm text-[#2c7da0]">Showing <?= number_format($totalRows) ?> of <?= number_format($totalRows) ?> transactions</span>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-[#2c7da0] border-b border-[#d0e7f2]">
              <tr>
                <th class="text-left py-3 font-medium"><input type="checkbox" class="checkbox-custom"></th>
                <th class="text-left py-3 font-medium">Transaction ID</th>
                <th class="text-left py-3 font-medium">Customer</th>
                <th class="text-left py-3 font-medium">Products</th>
                <th class="text-left py-3 font-medium">Quantity</th>
                <th class="text-left py-3 font-medium">Total</th>
                <th class="text-left py-3 font-medium">Payment</th>
                <th class="text-left py-3 font-medium">Date</th>
                <th class="text-left py-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody id="salesTableBody" class="divide-y divide-[#dff0f7]">
              <?php foreach ($salesRows as $sale): ?>
                <?php $searchBlob = strtolower($sale['receipt_no'] . ' ' . $sale['customer_name'] . ' ' . implode(' ', $sale['products'])); ?>
                <tr class="sales-item hover:bg-[#f3fafd]"
                    data-search="<?= h($searchBlob) ?>"
                    data-status="<?= h((string)$sale['status']) ?>"
                    data-created-at="<?= h((string)$sale['created_at']) ?>">
                    <td class="py-3"><input type="checkbox" class="checkbox-custom"></td>
                    <td class="py-3">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#0f6f94]">receipt</span>
                        <span class="font-medium"><?= h($sale['receipt_no']) ?></span>
                      </div>
                    </td>
                    <td class="py-3"><?= h($sale['customer_name']) ?></td>
                    <td class="py-3">
                      <div class="flex flex-wrap gap-1">
                        <?php if ($sale['products'] === []): ?>
                          <span class="bg-[#e2f0f7] px-2 py-1 rounded text-xs">No items</span>
                        <?php else: ?>
                          <?php foreach (array_slice($sale['products'], 0, 3) as $productName): ?>
                            <span class="bg-[#e2f0f7] px-2 py-1 rounded text-xs"><?= h($productName) ?></span>
                          <?php endforeach; ?>
                          <?php if (count($sale['products']) > 3): ?>
                            <span class="bg-[#e2f0f7] px-2 py-1 rounded text-xs">+<?= count($sale['products']) - 3 ?> more</span>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td class="py-3"><?= number_format($sale['quantity']) ?></td>
                    <td class="py-3"><?= h(peso($sale['total_amount'])) ?></td>
                    <td class="py-3"><span class="<?= h($sale['status_class']) ?> px-2 py-1 rounded-full text-xs font-medium"><?= h($sale['status_label']) ?></span></td>
                    <td class="py-3"><?= h($sale['display_date']) ?></td>
                    <td class="py-3">
                      <div class="flex items-center gap-2">
                        <a href="registry.php?print=1&amp;order_id=<?= (int)$sale['id'] ?>" onclick="event.preventDefault(); printReceiptInPlace(this.href);" class="text-[#0f6f94] hover:text-[#05445E] p-1">
                          <span class="material-symbols-outlined">visibility</span>
                        </a>
                        <?php if ($sale['status'] === 'pending'): ?>
                          <button type="button"
                                  class="mark-paid-btn text-emerald-700 hover:text-emerald-900 p-1"
                                  data-order-id="<?= (int)$sale['id'] ?>"
                                  title="Mark as paid">
                            <span class="material-symbols-outlined">check_circle</span>
                          </button>
                          <button type="button"
                                  class="cancel-sale-btn text-orange-600 hover:text-orange-800 p-1"
                                  data-order-id="<?= (int)$sale['id'] ?>"
                                  title="Cancel and restock">
                            <span class="material-symbols-outlined">cancel</span>
                          </button>
                        <?php endif; ?>
                        <button type="button"
                                class="delete-sale-btn text-red-600 hover:text-red-800 p-1"
                                data-order-id="<?= (int)$sale['id'] ?>"
                                title="Delete sale">
                          <span class="material-symbols-outlined">delete</span>
                        </button>
                      </div>
                    </td>
                </tr>
              <?php endforeach; ?>
              <tr id="salesEmptyRow" class="<?= $salesRows === [] ? '' : 'hidden' ?>">
                <?php if ($overallSalesCount > 0): ?>
                  <td colspan="9" class="py-8 text-center text-[#2c7da0]">No sales matched your current filters.</td>
                <?php else: ?>
                  <td colspan="9" class="py-8 text-center text-[#2c7da0]">No sales found yet.</td>
                <?php endif; ?>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-3 mt-6 pt-4 border-t border-[#dff0f7]">
          <span id="salesResultsSummaryBottom" class="text-sm text-[#2c7da0]">Showing <?= number_format($totalRows) ?> of <?= number_format($totalRows) ?> transactions</span>
          <div class="flex items-center gap-2">
            <button type="button" id="salesPrevPage" class="px-3 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5] disabled:opacity-50 disabled:cursor-not-allowed">Previous</button>
            <span id="salesPageInfo" class="text-sm text-[#2c7da0]">Page 1</span>
            <button type="button" id="salesNextPage" class="px-3 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5] disabled:opacity-50 disabled:cursor-not-allowed">Next</button>
          </div>
        </div>
      </div>
    </main>
  </div>

  <form id="markPaidForm" method="post" class="hidden">
    <input type="hidden" name="mark_paid" id="markPaidInput" value="">
  </form>
  <form id="deleteSaleForm" method="post" class="hidden">
    <input type="hidden" name="delete_sale" id="deleteSaleInput" value="">
  </form>
  <form id="cancelSaleForm" method="post" class="hidden">
    <input type="hidden" name="cancel_sale" id="cancelSaleInput" value="">
  </form>

  <div class="fixed inset-0 -z-10 pointer-events-none overflow-hidden">
    <div class="absolute w-96 h-96 rounded-full bg-white/20 blur-3xl top-[10%] left-[5%] bg-bubble-float"></div>
    <div class="absolute w-64 h-64 rounded-full bg-cyan-200/20 blur-2xl bottom-[5%] right-[10%] bg-bubble-float" style="animation-delay: -5s;"></div>
  </div>

  <div id="generateReportModal" class="fixed inset-0 z-50 hidden modal-backdrop items-center justify-center p-4">
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-xl border border-[#d0e7f2] overflow-hidden">
      <div class="px-6 py-4 border-b border-[#dff0f7] bg-gradient-to-r from-[#f4fbff] to-[#ecf7fd] flex items-center justify-between">
        <div>
          <h3 class="text-lg font-semibold text-[#05445E]">Generate Report</h3>
          <p class="text-xs text-[#2c7da0] mt-0.5">Select a reporting date range before generating.</p>
        </div>
        <button type="button" id="closeGenerateReportModal" class="text-[#2c7da0] hover:text-[#05445E]"><span class="material-symbols-outlined">close</span></button>
      </div>
      <form id="generateReportForm" class="p-6 space-y-4">
        <div>
          <label for="reportRange" class="block text-sm font-medium mb-1 text-[#05445E]">Date Range</label>
          <select id="reportRange" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg bg-white focus:outline-none focus:border-[#0f6f94]">
            <option value="today">Today</option>
            <option value="this_month">Whole Month</option>
            <option value="this_week">Week</option>
            <option value="last_2_days">Last 2 Days</option>
            <option value="this_year">One Year</option>
            <option value="all_time">All Time</option>
            <option value="custom">Custom Date Range</option>
          </select>
        </div>
        <div>
          <label for="reportTitle" class="block text-sm font-medium mb-1 text-[#05445E]">Report Title</label>
          <input type="text" id="reportTitle" maxlength="255" placeholder="Example: March 2026 Sales Review" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg focus:outline-none focus:border-[#0f6f94]">
        </div>
        <div id="customDateRangeInputs" class="hidden grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label for="reportStartDate" class="block text-sm font-medium mb-1 text-[#05445E]">Start Date</label>
            <input type="date" id="reportStartDate" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg focus:outline-none focus:border-[#0f6f94]">
          </div>
          <div>
            <label for="reportEndDate" class="block text-sm font-medium mb-1 text-[#05445E]">End Date</label>
            <input type="date" id="reportEndDate" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg focus:outline-none focus:border-[#0f6f94]">
          </div>
        </div>
        <p class="text-xs text-[#2c7da0]">You will be redirected to `reports.php` with the selected period.</p>
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" id="cancelGenerateReport" class="px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5]">Cancel</button>
          <button type="submit" class="px-4 py-2 bg-[#0f6f94] text-white rounded-lg hover:bg-[#0a4b6e]">Confirm</button>
        </div>
      </form>
    </div>
  </div>

  <div id="customRangeModal" class="fixed inset-0 z-50 hidden modal-backdrop items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-2xl shadow-xl border border-[#d0e7f2] overflow-hidden">
      <div class="px-6 py-4 border-b border-[#dff0f7] bg-gradient-to-r from-[#f4fbff] to-[#ecf7fd] flex items-center justify-between">
        <div>
          <h3 class="text-lg font-semibold text-[#05445E]">Custom Date Range</h3>
          <p class="text-xs text-[#2c7da0] mt-0.5">Choose start and end dates to filter sales.</p>
        </div>
        <button type="button" id="closeCustomRangeModal" class="text-[#2c7da0] hover:text-[#05445E]"><span class="material-symbols-outlined">close</span></button>
      </div>
      <form id="customRangeForm" class="p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label for="customStartDate" class="block text-sm font-medium mb-1 text-[#05445E]">Start Date</label>
            <input type="date" id="customStartDate" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg bg-white focus:outline-none focus:border-[#0f6f94]">
          </div>
          <div>
            <label for="customEndDate" class="block text-sm font-medium mb-1 text-[#05445E]">End Date</label>
            <input type="date" id="customEndDate" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg bg-white focus:outline-none focus:border-[#0f6f94]">
          </div>
        </div>
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" id="cancelCustomRange" class="px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5]">Cancel</button>
          <button type="submit" class="px-4 py-2 bg-[#0f6f94] text-white rounded-lg hover:bg-[#0a4b6e]">Apply</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function printReceiptInPlace(url) {
      if (!url) return;
      let frame = document.getElementById('printReceiptFrame');
      if (!frame) {
        frame = document.createElement('iframe');
        frame.id = 'printReceiptFrame';
        frame.style.position = 'fixed';
        frame.style.right = '0';
        frame.style.bottom = '0';
        frame.style.width = '0';
        frame.style.height = '0';
        frame.style.border = '0';
        frame.style.opacity = '0';
        frame.setAttribute('aria-hidden', 'true');
        document.body.appendChild(frame);
      }
      const separator = url.includes('?') ? '&' : '?';
      frame.src = `${url}${separator}_=${Date.now()}`;
    }
    window.printReceiptInPlace = printReceiptInPlace;

    const openGenerateReportModal = document.getElementById('openGenerateReportModal');
    const generateReportModal = document.getElementById('generateReportModal');
    const closeGenerateReportModal = document.getElementById('closeGenerateReportModal');
    const cancelGenerateReport = document.getElementById('cancelGenerateReport');
    const generateReportForm = document.getElementById('generateReportForm');
    const reportRange = document.getElementById('reportRange');
    const reportTitle = document.getElementById('reportTitle');
    const customDateRangeInputs = document.getElementById('customDateRangeInputs');
    const reportStartDate = document.getElementById('reportStartDate');
    const reportEndDate = document.getElementById('reportEndDate');
    const customRangeModal = document.getElementById('customRangeModal');
    const closeCustomRangeModal = document.getElementById('closeCustomRangeModal');
    const cancelCustomRange = document.getElementById('cancelCustomRange');
    const customRangeForm = document.getElementById('customRangeForm');
    const customStartDate = document.getElementById('customStartDate');
    const customEndDate = document.getElementById('customEndDate');

    const showGenerateReportModal = () => {
      if (!generateReportModal) return;
      generateReportModal.classList.remove('hidden');
      generateReportModal.classList.add('flex');
    };

    const hideGenerateReportModal = () => {
      if (!generateReportModal) return;
      generateReportModal.classList.add('hidden');
      generateReportModal.classList.remove('flex');
    };

    reportRange?.addEventListener('change', () => {
      const isCustom = reportRange.value === 'custom';
      customDateRangeInputs?.classList.toggle('hidden', !isCustom);
      if (!isCustom) {
        reportStartDate.value = '';
        reportEndDate.value = '';
      }
    });

    openGenerateReportModal?.addEventListener('click', showGenerateReportModal);
    closeGenerateReportModal?.addEventListener('click', hideGenerateReportModal);
    cancelGenerateReport?.addEventListener('click', hideGenerateReportModal);
    generateReportModal?.addEventListener('click', (event) => {
      if (event.target === generateReportModal) {
        hideGenerateReportModal();
      }
    });

    generateReportForm?.addEventListener('submit', (event) => {
      event.preventDefault();
      const rangeValue = reportRange?.value || 'this_month';
      const titleValue = (reportTitle?.value || '').trim();
      if (!titleValue) {
        alert('Please enter a report title.');
        reportTitle?.focus();
        return;
      }
      const params = new URLSearchParams();
      params.set('generate', '1');
      params.set('range', rangeValue);
      params.set('title', titleValue);
      if (rangeValue === 'custom') {
        const startDate = reportStartDate?.value || '';
        const endDate = reportEndDate?.value || '';
        if (!startDate || !endDate) {
          alert('Please select both start and end dates for custom range.');
          return;
        }
        if (startDate > endDate) {
          alert('Start date cannot be later than end date.');
          return;
        }
        params.set('start', startDate);
        params.set('end', endDate);
      }
      const targetUrl = `reports.php?${params.toString()}`;
      if (window.Swal) {
        Swal.fire({
          icon: 'success',
          title: 'Report Generated',
          text: 'Redirecting to Reports...',
          confirmButtonColor: '#0f6f94',
          timer: 1200,
          showConfirmButton: false
        }).then(() => {
          window.location.href = targetUrl;
        });
      } else {
        window.location.href = targetUrl;
      }
    });

    const todaySalesMetricFilter = document.getElementById('todaySalesMetricFilter');
    const todaySalesValue = document.getElementById('todaySalesValue');
    const todaySalesMetricBadge = document.getElementById('todaySalesMetricBadge');

    const formatPeso = (value) => {
      const amount = Number(value || 0);
      return `PHP ${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    const applyTodaySalesMetric = () => {
      if (!todaySalesValue || !todaySalesMetricFilter) return;
      const salesAmount = Number(todaySalesValue.dataset.sales || 0);
      const revenueAmount = Number(todaySalesValue.dataset.revenue || 0);
      const isRevenue = todaySalesMetricFilter.value === 'revenue';
      const displayAmount = isRevenue ? revenueAmount : salesAmount;

      todaySalesValue.textContent = formatPeso(displayAmount);
      if (todaySalesMetricBadge) {
        todaySalesMetricBadge.textContent = isRevenue ? 'Total Revenue' : 'Total Sales';
      }
    };

    todaySalesMetricFilter?.addEventListener('change', applyTodaySalesMetric);
    applyTodaySalesMetric();

    const salesFilterForm = document.getElementById('salesFilterForm');
    const salesSearchInput = document.getElementById('salesSearchInput');
    const salesPeriodFilter = document.getElementById('salesPeriodFilter');
    const salesStatusFilter = document.getElementById('salesStatusFilter');
    const salesPrevPage = document.getElementById('salesPrevPage');
    const salesNextPage = document.getElementById('salesNextPage');
    const salesPageInfo = document.getElementById('salesPageInfo');
    const salesTableRows = Array.from(document.querySelectorAll('tr.sales-item'));
    const salesEmptyRow = document.getElementById('salesEmptyRow');
    const salesResultsSummaryTop = document.getElementById('salesResultsSummaryTop');
    const salesResultsSummaryBottom = document.getElementById('salesResultsSummaryBottom');
    const markPaidButtons = Array.from(document.querySelectorAll('.mark-paid-btn'));
    const markPaidForm = document.getElementById('markPaidForm');
    const markPaidInput = document.getElementById('markPaidInput');
    const deleteSaleButtons = Array.from(document.querySelectorAll('.delete-sale-btn'));
    const deleteSaleForm = document.getElementById('deleteSaleForm');
    const deleteSaleInput = document.getElementById('deleteSaleInput');
    const cancelSaleButtons = Array.from(document.querySelectorAll('.cancel-sale-btn'));
    const cancelSaleForm = document.getElementById('cancelSaleForm');
    const cancelSaleInput = document.getElementById('cancelSaleInput');
    const pageSize = 10;
    let currentPage = 1;
    let filteredRows = salesTableRows.slice();
    let customRangeStart = '';
    let customRangeEnd = '';
    let lastNonCustomPeriod = salesPeriodFilter?.value || 'all';

    const parseMySqlDateTime = (value) => {
      const parts = String(value || '').trim().split(' ');
      if (parts.length === 0 || !parts[0]) return null;
      const dateBits = parts[0].split('-').map(Number);
      if (dateBits.length !== 3 || dateBits.some((n) => !Number.isFinite(n))) return null;
      const timeBits = (parts[1] || '00:00:00').split(':').map(Number);
      const year = dateBits[0];
      const month = dateBits[1] - 1;
      const day = dateBits[2];
      const hour = Number.isFinite(timeBits[0]) ? timeBits[0] : 0;
      const minute = Number.isFinite(timeBits[1]) ? timeBits[1] : 0;
      const second = Number.isFinite(timeBits[2]) ? timeBits[2] : 0;
      return new Date(year, month, day, hour, minute, second);
    };

    const matchesPeriod = (createdAt, period) => {
      if (period === 'all' || period === 'custom') return true;
      const saleDate = parseMySqlDateTime(createdAt);
      if (!saleDate || Number.isNaN(saleDate.getTime())) return false;

      const now = new Date();
      const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      const saleDay = new Date(saleDate.getFullYear(), saleDate.getMonth(), saleDate.getDate());

      if (period === 'today') {
        return saleDay.getTime() === today.getTime();
      }
      if (period === 'week') {
        const startOfWindow = new Date(today);
        startOfWindow.setDate(startOfWindow.getDate() - 6);
        return saleDay >= startOfWindow && saleDay <= today;
      }
      if (period === 'month') {
        return saleDay.getFullYear() === today.getFullYear() && saleDay.getMonth() === today.getMonth();
      }
      if (period === 'year') {
        return saleDay.getFullYear() === today.getFullYear();
      }
      return true;
    };

    const matchesDateRange = (createdAt, startValue, endValue) => {
      const saleDate = parseMySqlDateTime(createdAt);
      if (!saleDate || Number.isNaN(saleDate.getTime())) return false;
      const saleDay = new Date(saleDate.getFullYear(), saleDate.getMonth(), saleDate.getDate());

      if (startValue) {
        const startDate = new Date(`${startValue}T00:00:00`);
        if (saleDay < startDate) return false;
      }
      if (endValue) {
        const endDate = new Date(`${endValue}T23:59:59`);
        if (saleDay > endDate) return false;
      }
      return true;
    };

    const updateSalesSummary = (start, end, total) => {
      const summaryText = total > 0
        ? `Showing ${start}-${end} of ${total.toLocaleString()} transaction${total === 1 ? '' : 's'}`
        : 'No transactions found';
      if (salesResultsSummaryTop) {
        salesResultsSummaryTop.textContent = summaryText;
      }
      if (salesResultsSummaryBottom) {
        salesResultsSummaryBottom.textContent = summaryText;
      }
    };

    const renderSalesPage = () => {
      const totalFiltered = filteredRows.length;
      const totalPages = Math.max(1, Math.ceil(totalFiltered / pageSize));
      if (currentPage > totalPages) {
        currentPage = totalPages;
      }
      const startIndex = (currentPage - 1) * pageSize;
      const endIndex = Math.min(startIndex + pageSize, totalFiltered);

      salesTableRows.forEach((row) => row.classList.add('hidden'));
      filteredRows.slice(startIndex, endIndex).forEach((row) => row.classList.remove('hidden'));

      const hasRows = totalFiltered > 0;
      if (salesEmptyRow) {
        salesEmptyRow.classList.toggle('hidden', hasRows);
      }
      updateSalesSummary(hasRows ? startIndex + 1 : 0, hasRows ? endIndex : 0, totalFiltered);

      if (salesPageInfo) {
        const displayTotalPages = totalFiltered === 0 ? 0 : totalPages;
        const displayCurrent = totalFiltered === 0 ? 0 : currentPage;
        salesPageInfo.textContent = `Page ${displayCurrent} of ${displayTotalPages}`;
      }
      if (salesPrevPage) {
        salesPrevPage.disabled = currentPage <= 1 || totalFiltered === 0;
      }
      if (salesNextPage) {
        salesNextPage.disabled = currentPage >= totalPages || totalFiltered === 0;
      }
    };

    const applySalesFilters = () => {
      const query = (salesSearchInput?.value || '').trim().toLowerCase();
      const period = (salesPeriodFilter?.value || 'all').trim();
      const status = (salesStatusFilter?.value || 'all').trim();
      const startValue = period === 'custom' ? customRangeStart : '';
      const endValue = period === 'custom' ? customRangeEnd : '';

      filteredRows = salesTableRows.filter((row) => {
        const text = (row.dataset.search || '').toLowerCase();
        const rowStatus = (row.dataset.status || '').trim();
        const rowCreatedAt = row.dataset.createdAt || '';

        const matchesSearch = query === '' || text.includes(query);
        const matchesStatusValue = status === 'all' || rowStatus === status;
        const matchesPeriodValue = matchesPeriod(rowCreatedAt, period);
        const matchesCustomRange = matchesDateRange(rowCreatedAt, startValue, endValue);

        return matchesSearch && matchesStatusValue && matchesPeriodValue && matchesCustomRange;
      });

      currentPage = 1;
      renderSalesPage();
    };

    salesFilterForm?.addEventListener('submit', (event) => {
      event.preventDefault();
      applySalesFilters();
    });
    salesSearchInput?.addEventListener('input', applySalesFilters);
    salesStatusFilter?.addEventListener('change', applySalesFilters);

    salesPrevPage?.addEventListener('click', () => {
      if (currentPage > 1) {
        currentPage -= 1;
        renderSalesPage();
      }
    });

    salesNextPage?.addEventListener('click', () => {
      const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
      if (currentPage < totalPages) {
        currentPage += 1;
        renderSalesPage();
      }
    });

    const openCustomRangeModal = () => {
      if (!customRangeModal) return;
      customRangeModal.classList.remove('hidden');
      customRangeModal.classList.add('flex');
      customStartDate.value = customRangeStart;
      customEndDate.value = customRangeEnd;
      customStartDate.focus();
    };

    const closeCustomRange = () => {
      if (!customRangeModal) return;
      customRangeModal.classList.add('hidden');
      customRangeModal.classList.remove('flex');
    };

    salesPeriodFilter?.addEventListener('change', () => {
      const value = (salesPeriodFilter.value || 'all').trim();
      if (value === 'custom') {
        openCustomRangeModal();
      } else {
        lastNonCustomPeriod = value;
        applySalesFilters();
      }
    });

    customRangeForm?.addEventListener('submit', (event) => {
      event.preventDefault();
      const startVal = customStartDate?.value || '';
      const endVal = customEndDate?.value || '';
      if (!startVal || !endVal) {
        alert('Please select both start and end dates.');
        return;
      }
      if (startVal > endVal) {
        alert('Start date cannot be later than end date.');
        return;
      }
      customRangeStart = startVal;
      customRangeEnd = endVal;
      salesPeriodFilter.value = 'custom';
      closeCustomRange();
      applySalesFilters();
    });

    cancelCustomRange?.addEventListener('click', () => {
      salesPeriodFilter.value = lastNonCustomPeriod;
      closeCustomRange();
    });

    closeCustomRangeModal?.addEventListener('click', () => {
      salesPeriodFilter.value = lastNonCustomPeriod;
      closeCustomRange();
    });

    customRangeModal?.addEventListener('click', (event) => {
      if (event.target === customRangeModal) {
        salesPeriodFilter.value = lastNonCustomPeriod;
        closeCustomRange();
      }
    });

    applySalesFilters();

    const confirmMarkPaid = (orderId) => {
      if (!orderId || !markPaidForm || !markPaidInput) return;
      const proceed = () => {
        markPaidInput.value = orderId;
        markPaidForm.submit();
      };
      if (window.Swal) {
        Swal.fire({
          icon: 'question',
          title: 'Mark as paid?',
          text: 'Are you sure you want to mark this sale as paid?',
          showCancelButton: true,
          confirmButtonColor: '#0f6f94',
          cancelButtonColor: '#6b7280',
          confirmButtonText: 'Yes, mark paid'
        }).then((result) => {
          if (result.isConfirmed) {
            proceed();
          }
        });
      } else if (confirm('Mark this sale as paid?')) {
        proceed();
      }
    };

    markPaidButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = Number(btn.dataset.orderId || 0);
        if (id > 0) {
          confirmMarkPaid(id);
        }
      });
    });

    const confirmDeleteSale = (orderId) => {
      if (!orderId || !deleteSaleForm || !deleteSaleInput) return;
      const proceed = () => {
        deleteSaleInput.value = orderId;
        deleteSaleForm.submit();
      };
      if (window.Swal) {
        Swal.fire({
          icon: 'warning',
          title: 'Delete sale?',
          text: 'Are you sure you want to delete this sale? This cannot be undone.',
          showCancelButton: true,
          confirmButtonColor: '#dc2626',
          cancelButtonColor: '#6b7280',
          confirmButtonText: 'Yes, delete it'
        }).then((result) => {
          if (result.isConfirmed) {
            proceed();
          }
        });
      } else if (confirm('Delete this sale? This cannot be undone.')) {
        proceed();
      }
    };

    deleteSaleButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = Number(btn.dataset.orderId || 0);
        if (id > 0) {
          confirmDeleteSale(id);
        }
      });
    });

    const confirmCancelSale = (orderId) => {
      if (!orderId || !cancelSaleForm || !cancelSaleInput) return;
      const proceed = () => {
        cancelSaleInput.value = orderId;
        cancelSaleForm.submit();
      };
      if (window.Swal) {
        Swal.fire({
          icon: 'warning',
          title: 'Cancel sale?',
          text: 'This will mark the sale as cancelled and return items to inventory.',
          showCancelButton: true,
          confirmButtonColor: '#f97316',
          cancelButtonColor: '#6b7280',
          confirmButtonText: 'Yes, cancel sale'
        }).then((result) => {
          if (result.isConfirmed) {
            proceed();
          }
        });
      } else if (confirm('Cancel this sale and return items to inventory?')) {
        proceed();
      }
    };

    cancelSaleButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = Number(btn.dataset.orderId || 0);
        if (id > 0) {
          confirmCancelSale(id);
        }
      });
    });

    const sidebar = document.getElementById('sidebar');
    const collapseBtn = document.getElementById('collapseSidebar');
    if (collapseBtn) {
      collapseBtn.addEventListener('click', () => {
        sidebar.classList.toggle('sidebar-collapsed');
        const icon = collapseBtn.querySelector('.material-symbols-outlined');
        if (sidebar.classList.contains('sidebar-collapsed')) {
          icon.textContent = 'menu';
          sidebar.style.width = '5rem';
        } else {
          icon.textContent = 'menu_open';
          sidebar.style.width = '18rem';
        }
      });
    }
  </script>
</body>
</html>
