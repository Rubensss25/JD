<?php
declare(strict_types=1);

// Include session validation and cache control
require_once __DIR__ . '/../includes/auth_check.php';

require_once __DIR__ . '/../config/connect.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatMoney(float $value): string
{
    return 'PHP ' . number_format($value, 2);
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
        return 'bg-amber-100 text-amber-800';
    }
    if ($status === 'cancelled') {
        return 'bg-red-100 text-red-800';
    }
    return 'bg-emerald-100 text-emerald-800';
}

function formatMetricDelta(float $todayValue, float $yesterdayValue, string $metricName): array
{
    if ($yesterdayValue <= 0.0) {
        if ($todayValue > 0.0) {
            return [
                'text' => 'No ' . $metricName . ' yesterday',
                'class' => 'text-[#0f6f94] bg-[#dff3fc]',
            ];
        }

        return [
            'text' => 'No ' . $metricName . ' yet today',
            'class' => 'text-[#0f6f94] bg-[#dff3fc]',
        ];
    }

    $percent = (($todayValue - $yesterdayValue) / $yesterdayValue) * 100;
    $rounded = round($percent);

    if ($rounded >= 0) {
        return [
            'text' => '+' . number_format((float)$rounded) . '% vs yesterday',
            'class' => 'text-emerald-600 bg-emerald-50',
        ];
    }

    return [
        'text' => number_format((float)$rounded) . '% vs yesterday',
        'class' => 'text-red-600 bg-red-50',
    ];
}

function buildWeekRange(DateTimeImmutable $from, DateTimeImmutable $to, string $labelFormat): array
{
    $weeks = [];
    $labels = [];
    $cursor = $from;
    
    // Adjust cursor to the start of the week (Monday)
    $dayOfWeek = (int)$cursor->format('N'); // 1 = Monday, 7 = Sunday
    if ($dayOfWeek > 1) {
        $cursor = $cursor->modify('-' . ($dayOfWeek - 1) . ' days');
    }
    
    while ($cursor <= $to) {
        $weekStart = $cursor;
        $weekEnd = $cursor->modify('+6 days');
        
        if ($weekStart > $to) break;
        
        $weeks[] = [
            'start' => $weekStart->format('Y-m-d'),
            'end' => $weekEnd->format('Y-m-d')
        ];
        $labels[] = 'Week ' . $weekStart->format('W');
        
        $cursor = $weekEnd->modify('+1 day');
    }

    return [
        'weeks' => $weeks,
        'labels' => $labels,
    ];
}

function fetchSalesSeriesByWeek(mysqli $conn, string $fromDate, string $toDate): array
{
    $values = [];

    $stmt = $conn->prepare(
        'SELECT YEARWEEK(DATE(created_at), 1) AS week_key, 
                COALESCE(SUM(total_amount), 0) AS total,
                MIN(DATE(created_at)) AS week_start,
                MAX(DATE(created_at)) AS week_end
         FROM sales_orders
         WHERE DATE(created_at) BETWEEN ? AND ?
           AND payment_amount >= total_amount
           AND total_amount > 0
         GROUP BY YEARWEEK(DATE(created_at), 1)
         ORDER BY week_key'
    );
    $stmt->bind_param('ss', $fromDate, $toDate);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $weekKey = (string)($row['week_key'] ?? '');
        if ($weekKey === '') {
            continue;
        }
        $values[$weekKey] = [
            'total' => (float)($row['total'] ?? 0),
            'start' => (string)($row['week_start'] ?? ''),
            'end' => (string)($row['week_end'] ?? '')
        ];
    }
    $stmt->close();

    return $values;
}

function fetchGrossProfitSeriesByDate(mysqli $conn, string $fromDate, string $toDate, bool $canJoinProducts): array
{
    $values = [];
    $costSql = $canJoinProducts ? 'COALESCE(p.cost, 0)' : '0';
    $sql = 'SELECT DATE(so.created_at) AS sale_date,
                   COALESCE(SUM(soi.line_total - (' . $costSql . ' * soi.quantity)), 0) AS total
            FROM sales_orders so
            INNER JOIN sales_order_items soi ON soi.order_id = so.id';
    if ($canJoinProducts) {
        $sql .= ' LEFT JOIN products p ON p.id = soi.product_id';
    }
    $sql .= ' WHERE DATE(so.created_at) BETWEEN ? AND ?
              AND so.payment_amount >= so.total_amount
              AND so.total_amount > 0
              GROUP BY DATE(so.created_at)';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $fromDate, $toDate);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $dateKey = (string)($row['sale_date'] ?? '');
        if ($dateKey === '') {
            continue;
        }
        $values[$dateKey] = (float)($row['total'] ?? 0);
    }
    $stmt->close();

    return $values;
}

function fetchGrossProfitSeriesByWeek(mysqli $conn, string $fromDate, string $toDate, bool $canJoinProducts): array
{
    $values = [];
    $costSql = $canJoinProducts ? 'COALESCE(p.cost, 0)' : '0';
    $sql = 'SELECT YEARWEEK(DATE(so.created_at), 1) AS week_key,
                   COALESCE(SUM(soi.line_total - (' . $costSql . ' * soi.quantity)), 0) AS total,
                   MIN(DATE(so.created_at)) AS week_start,
                   MAX(DATE(so.created_at)) AS week_end
            FROM sales_orders so
            INNER JOIN sales_order_items soi ON soi.order_id = so.id';
    if ($canJoinProducts) {
        $sql .= ' LEFT JOIN products p ON p.id = soi.product_id';
    }
    $sql .= ' WHERE DATE(so.created_at) BETWEEN ? AND ?
              AND so.payment_amount >= so.total_amount
              AND so.total_amount > 0
              GROUP BY YEARWEEK(DATE(so.created_at), 1)
              ORDER BY week_key';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $fromDate, $toDate);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $weekKey = (string)($row['week_key'] ?? '');
        if ($weekKey === '') {
            continue;
        }
        $values[$weekKey] = [
            'total' => (float)($row['total'] ?? 0),
            'start' => (string)($row['week_start'] ?? ''),
            'end' => (string)($row['week_end'] ?? '')
        ];
    }
    $stmt->close();

    return $values;
}

function seriesForWeeks(array $weeks, array $sourceMap): array
{
    $series = [];
    foreach ($weeks as $week) {
        // Find the week key that matches this week's date range
        $weekValue = 0.0;
        foreach ($sourceMap as $weekKey => $weekData) {
            if (is_array($weekData) && 
                isset($weekData['start'], $weekData['end']) &&
                ($week['start'] >= $weekData['start'] && $week['start'] <= $weekData['end']) ||
                ($week['end'] >= $weekData['start'] && $week['end'] <= $weekData['end']) ||
                ($week['start'] <= $weekData['start'] && $week['end'] >= $weekData['end'])) {
                $weekValue = (float)$weekData['total'];
                break;
            }
        }
        $series[] = $weekValue;
    }
    return $series;
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

 $canJoinProducts = tableExists($conn, 'products');

 $todaySales = 0.0;
 $yesterdaySales = 0.0;
 $salesTodayRow = $conn->query(
    'SELECT
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total_amount ELSE 0 END), 0) AS today_sales,
        COALESCE(SUM(CASE WHEN DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN total_amount ELSE 0 END), 0) AS yesterday_sales
     FROM sales_orders
     WHERE payment_amount >= total_amount
       AND total_amount > 0'
)->fetch_assoc();
if ($salesTodayRow) {
    $todaySales = (float)($salesTodayRow['today_sales'] ?? 0);
    $yesterdaySales = (float)($salesTodayRow['yesterday_sales'] ?? 0);
}

 $todayDate = new DateTimeImmutable('today');
 $todayDateKey = $todayDate->format('Y-m-d');
 $yesterdayDateKey = $todayDate->modify('-1 day')->format('Y-m-d');
 $grossProfitMapForToday = fetchGrossProfitSeriesByDate($conn, $yesterdayDateKey, $todayDateKey, $canJoinProducts);
 $todayGrossProfit = (float)($grossProfitMapForToday[$todayDateKey] ?? 0.0);
 $yesterdayGrossProfit = (float)($grossProfitMapForToday[$yesterdayDateKey] ?? 0.0);

 $salesDelta = formatMetricDelta($todaySales, $yesterdaySales, 'sales');
 $grossProfitDelta = formatMetricDelta($todayGrossProfit, $yesterdayGrossProfit, 'gross profit');

 $pendingSummary = [
    'count' => 0,
    'balance' => 0.0,
];
 $pendingSummaryRow = $conn->query(
    'SELECT
        COUNT(*) AS pending_count,
        COALESCE(SUM(total_amount - payment_amount), 0) AS pending_balance
     FROM sales_orders
     WHERE payment_amount < total_amount
       AND total_amount > 0'
)->fetch_assoc();
if ($pendingSummaryRow) {
    $pendingSummary['count'] = (int)($pendingSummaryRow['pending_count'] ?? 0);
    $pendingSummary['balance'] = (float)($pendingSummaryRow['pending_balance'] ?? 0.0);
}

 $lowStockSummary = [
    'count' => 0,
];
 $lowStockItems = [];
if ($canJoinProducts) {
    $lowStockCountRow = $conn->query(
        'SELECT COUNT(*) AS low_count
         FROM products
         WHERE (stock_store + stock_stockroom) > 0
           AND (stock_store + stock_stockroom) <= 5'
    )->fetch_assoc();
    if ($lowStockCountRow) {
        $lowStockSummary['count'] = (int)($lowStockCountRow['low_count'] ?? 0);
    }

    $lowStockResult = $conn->query(
        'SELECT product_name, stock_store, stock_stockroom, (stock_store + stock_stockroom) AS total_stock
         FROM products
         WHERE (stock_store + stock_stockroom) > 0
           AND (stock_store + stock_stockroom) <= 5
         ORDER BY total_stock ASC, product_name ASC
         LIMIT 8'
    );
    if ($lowStockResult instanceof mysqli_result) {
        while ($row = $lowStockResult->fetch_assoc()) {
            $lowStockItems[] = [
                'name' => (string)($row['product_name'] ?? ''),
                'store' => (int)($row['stock_store'] ?? 0),
                'stockroom' => (int)($row['stock_stockroom'] ?? 0),
                'total' => (int)($row['total_stock'] ?? 0),
            ];
        }
        $lowStockResult->close();
    }
}

 $registeredCustomers = 0;
 $newCustomersThisWeek = 0;
 $customerRow = $conn->query(
    "SELECT
        COUNT(DISTINCT CASE
            WHEN TRIM(customer_name) <> '' AND LOWER(TRIM(customer_name)) <> 'walk-in customer' THEN LOWER(TRIM(customer_name))
            ELSE NULL
        END) AS total_customers,
        COUNT(DISTINCT CASE
            WHEN TRIM(customer_name) <> ''
              AND LOWER(TRIM(customer_name)) <> 'walk-in customer'
              AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            THEN LOWER(TRIM(customer_name))
            ELSE NULL
        END) AS week_customers
     FROM sales_orders"
)->fetch_assoc();
if ($customerRow) {
    $registeredCustomers = (int)($customerRow['total_customers'] ?? 0);
    $newCustomersThisWeek = (int)($customerRow['week_customers'] ?? 0);
}

 $ordersToday = 0;
 $pendingToday = 0;
 $orderRow = $conn->query(
    'SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN payment_amount < total_amount AND total_amount > 0 THEN 1 ELSE 0 END) AS pending_orders
     FROM sales_orders
     WHERE DATE(created_at) = CURDATE()'
)->fetch_assoc();
if ($orderRow) {
    $ordersToday = (int)($orderRow['total_orders'] ?? 0);
    $pendingToday = (int)($orderRow['pending_orders'] ?? 0);
}

 $last7From = $todayDate->modify('-6 days');
 $monthFrom = $todayDate->modify('first day of this month');

// For weekly view, we'll show the last 4 weeks instead of last 7 days
 $last4WeeksFrom = $todayDate->modify('-4 weeks');
 $last4WeeksRange = buildWeekRange($last4WeeksFrom, $todayDate, 'W');

// For monthly view, we'll show weeks in the current month
 $monthWeeksRange = buildWeekRange($monthFrom, $todayDate, 'W');

 $last4WeeksSalesMap = fetchSalesSeriesByWeek($conn, $last4WeeksFrom->format('Y-m-d'), $todayDateKey);
 $last4WeeksGrossProfitMap = fetchGrossProfitSeriesByWeek($conn, $last4WeeksFrom->format('Y-m-d'), $todayDateKey, $canJoinProducts);
 $monthWeeksSalesMap = fetchSalesSeriesByWeek($conn, $monthFrom->format('Y-m-d'), $todayDateKey);
 $monthWeeksGrossProfitMap = fetchGrossProfitSeriesByWeek($conn, $monthFrom->format('Y-m-d'), $todayDateKey, $canJoinProducts);

$last4WeeksSalesSeries = seriesForWeeks($last4WeeksRange['weeks'], $last4WeeksSalesMap);
$last4WeeksGrossProfitSeries = seriesForWeeks($last4WeeksRange['weeks'], $last4WeeksGrossProfitMap);
$monthWeeksSalesSeries = seriesForWeeks($monthWeeksRange['weeks'], $monthWeeksSalesMap);
$monthWeeksGrossProfitSeries = seriesForWeeks($monthWeeksRange['weeks'], $monthWeeksGrossProfitMap);

$formatWeekRangeLabels = function(array $weeks): array {
    $labels = [];
    foreach ($weeks as $week) {
        $startTs = strtotime($week['start']);
        $endTs = strtotime($week['end']);
        $weekNo = date('W', $startTs);
        $startText = date('M d', $startTs);
        $endText = date('M d', $endTs);
        $labels[] = "Week {$weekNo} ({$startText}–{$endText})";
    }
    return $labels;
};

$last4WeeksDisplayLabels = $formatWeekRangeLabels($last4WeeksRange['weeks']);
$monthWeeksDisplayLabels = $formatWeekRangeLabels($monthWeeksRange['weeks']);

$recentRows = [];
 $recentStmt = $conn->prepare(
    'SELECT
        so.id,
        so.receipt_no,
        so.customer_name,
        so.total_amount,
        so.payment_amount,
        so.created_at,
        COALESCE(SUM(soi.quantity), 0) AS total_qty,
        GROUP_CONCAT(DISTINCT soi.product_name ORDER BY soi.product_name SEPARATOR "||") AS product_names
     FROM sales_orders so
     LEFT JOIN sales_order_items soi ON soi.order_id = so.id
     GROUP BY so.id, so.receipt_no, so.customer_name, so.total_amount, so.payment_amount, so.created_at
     ORDER BY so.created_at DESC, so.id DESC
     LIMIT 4'
);
 $recentStmt->execute();
 $recentResult = $recentStmt->get_result();
while ($row = $recentResult->fetch_assoc()) {
    $products = array_values(array_filter(explode('||', (string)($row['product_names'] ?? ''))));
    $primaryProduct = $products !== [] ? $products[0] : 'No items';
    if (count($products) > 1) {
        $primaryProduct .= ' +' . (count($products) - 1) . ' more';
    }

    $totalAmount = (float)($row['total_amount'] ?? 0);
    $paymentAmount = (float)($row['payment_amount'] ?? 0);
    $status = paymentStatus($totalAmount, $paymentAmount);

    $recentRows[] = [
        'receipt_no' => (string)($row['receipt_no'] ?? ''),
        'customer_name' => (string)($row['customer_name'] ?? ''),
        'product_text' => $primaryProduct,
        'qty' => (int)($row['total_qty'] ?? 0),
        'status_label' => paymentStatusLabel($status),
        'status_class' => paymentStatusClass($status),
        'display_date' => date('d M Y', strtotime((string)($row['created_at'] ?? 'now'))),
    ];
}
 $recentStmt->close();

$chartData = [
    'last4weeks' => [
        'labels' => $last4WeeksRange['labels'],
        'display_labels' => $last4WeeksDisplayLabels,
        'sales' => $last4WeeksSalesSeries,
        'gross_profit' => $last4WeeksGrossProfitSeries,
    ],
    'month' => [
        'labels' => $monthWeeksRange['labels'],
        'display_labels' => $monthWeeksDisplayLabels,
        'sales' => $monthWeeksSalesSeries,
        'gross_profit' => $monthWeeksGrossProfitSeries,
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title> Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0,1" />
  <style>
    .stat-card {
      transition: transform 0.15s ease, box-shadow 0.2s ease;
    }
    .stat-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 20px 25px -5px rgba(0, 148, 195, 0.15), 0 10px 10px -5px rgba(0, 148, 195, 0.1);
    }
    @keyframes floatBubble {
      0% { transform: translateY(0) scale(1); opacity: 0.2; }
      50% { transform: translateY(-20px) scale(1.05); opacity: 0.15; }
      100% { transform: translateY(0) scale(1); opacity: 0.2; }
    }
    .bg-bubble-float {
      animation: floatBubble 18s infinite ease-in-out;
    }
    .checkbox-custom:checked {
      background: #0b6e8f;
      border-color: #0b6e8f;
    }
    
    /* Chart Styles */
    .chart-container {
      position: relative;
      width: 100%;
      min-height: 280px;
    }
    
    .chart-line {
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    
    .chart-area {
      opacity: 0.3;
    }
    
    .chart-dot {
      cursor: pointer;
      transition: r 0.2s ease, filter 0.2s ease;
    }
    
    .chart-dot:hover {
      filter: drop-shadow(0 0 6px rgba(15, 111, 148, 0.6));
    }
    
    .chart-tooltip {
      position: absolute;
      background: linear-gradient(135deg, #05445E 0%, #0f6f94 100%);
      color: white;
      padding: 10px 14px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 500;
      pointer-events: none;
      opacity: 0;
      transform: translateX(-50%) translateY(-10px);
      transition: opacity 0.2s ease, transform 0.2s ease;
      box-shadow: 0 8px 24px rgba(5, 68, 94, 0.3);
      white-space: nowrap;
      z-index: 50;
    }
    
    .chart-tooltip.visible {
      opacity: 1;
      transform: translateX(-50%) translateY(0);
    }
    
    .chart-tooltip::after {
      content: '';
      position: absolute;
      bottom: -6px;
      left: 50%;
      transform: translateX(-50%);
      border-left: 6px solid transparent;
      border-right: 6px solid transparent;
      border-top: 6px solid #0f6f94;
    }
    
    .chart-grid-line {
      stroke: #e5f2f7;
      stroke-dasharray: 4 4;
    }
    
    .chart-label {
      font-size: 11px;
      fill: #6b9aaa;
      font-weight: 500;
    }
    
    .chart-value-label {
      font-size: 10px;
      fill: #2c7da0;
      font-weight: 600;
    }
    
    .metric-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      transition: all 0.2s ease;
    }
    
    .metric-badge.sales {
      background: linear-gradient(135deg, #e0f4f8 0%, #c7e9f2 100%);
      color: #05445E;
      border: 1px solid #b7d9e9;
    }
    
    .metric-badge.gross_profit {
      background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
      color: #166534;
      border: 1px solid #86efac;
    }
  </style>
</head>
<body class="bg-[#e6f4fa] font-sans antialiased text-[#043b4a] min-h-screen flex">

  <?php include '../includes/sidebar.php'; ?>

  <div class="flex-1 flex flex-col w-full min-w-0">

    <header class="bg-white/90 backdrop-blur-md border-b border-white/40 px-3 sm:px-4 lg:px-6 py-2 sm:py-3 flex items-center justify-between sticky top-0 z-10 shadow-sm">
      <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1 lg:ml-0 ml-12">
        <h1 class="text-sm sm:text-base lg:text-lg xl:text-xl font-light text-[#05445E] truncate">
          <span class="font-semibold">Dashboard</span> 
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
            <p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Sales Today</p>
            <select id="todayMetricFilter" class="mt-2 text-xs px-2 py-1 border border-[#b7d9e9] rounded-lg bg-white text-[#05445E] focus:outline-none focus:border-[#0f6f94]">
              <option value="sales" selected>Total Sales</option>
              <option value="gross_profit">Gross Profit</option>
            </select>
            <p id="todayMetricValue"
               data-sales="<?= number_format($todaySales, 2, '.', '') ?>"
               data-gross-profit="<?= number_format($todayGrossProfit, 2, '.', '') ?>"
               class="text-3xl font-semibold text-[#05445E] mt-2"><?= h(formatMoney($todaySales)) ?></p>
            <span id="todayMetricDelta"
                  data-sales-text="<?= h((string)$salesDelta['text']) ?>"
                  data-sales-class="<?= h((string)$salesDelta['class']) ?>"
                  data-gross-profit-text="<?= h((string)$grossProfitDelta['text']) ?>"
                  data-gross-profit-class="<?= h((string)$grossProfitDelta['class']) ?>"
                  class="text-xs px-2 py-0.5 rounded-full mt-2 inline-block <?= h((string)$salesDelta['class']) ?>"><?= h((string)$salesDelta['text']) ?></span>
          </div>
          <div class="bg-[#e0f0f9] p-3 rounded-full">
            <span class="material-symbols-outlined text-3xl text-[#0f6f94]">payments</span>
          </div>
        </div>

        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40">
          <div class="flex items-start justify-between">
            <div>
              <p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Pending Payments</p>
              <p class="text-3xl font-semibold text-[#05445E] mt-1"><?= number_format($pendingSummary['count']) ?></p>
              <span class="text-xs text-[#0f6f94] bg-[#dff3fc] px-2 py-0.5 rounded-full mt-2 inline-block">Unpaid balance: <?= h(formatMoney($pendingSummary['balance'])) ?></span>
            </div>
            <div class="bg-[#e0f0f9] p-3 rounded-full">
              <span class="material-symbols-outlined text-3xl text-[#0f6f94]">pending_actions</span>
            </div>
          </div>
          
        </div>

        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40 flex items-start justify-between">
          <div>
            <p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Registered customers</p>
            <p class="text-3xl font-semibold text-[#05445E] mt-1"><?= number_format($registeredCustomers) ?></p>
            <span class="text-xs text-[#0f6f94] bg-[#dff3fc] px-2 py-0.5 rounded-full mt-2 inline-block">+<?= number_format($newCustomersThisWeek) ?> this week</span>
          </div>
          <div class="bg-[#e0f0f9] p-3 rounded-full">
            <span class="material-symbols-outlined text-3xl text-[#0f6f94]">groups</span>
          </div>
        </div>

        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40 flex items-start justify-between">
          <div>
            <p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Orders Today</p>
            <p class="text-3xl font-semibold text-[#05445E] mt-1"><?= number_format($ordersToday) ?></p>
            <span class="text-xs text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full mt-2 inline-block"><?= number_format($pendingToday) ?> pending</span>
          </div>
          <div class="bg-[#e0f0f9] p-3 rounded-full">
            <span class="material-symbols-outlined text-3xl text-[#0f6f94]">assignment</span>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="lg:col-span-2 bg-white rounded-2xl p-5 shadow-md border border-white/40">
          <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-3">
            <div class="flex items-center gap-3">
              <div class="bg-gradient-to-br from-[#0f6f94] to-[#05445E] p-2 rounded-xl">
                <span class="material-symbols-outlined text-white text-xl">insights</span>
              </div>
              <div>
                <h2 class="text-lg font-semibold text-[#05445E]">Weekly Sales</h2>
                <p class="text-xs text-[#6b9aaa]">Performance overview</p>
              </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
              <span id="chartMetricBadge" class="metric-badge sales">
                <span class="w-2 h-2 rounded-full bg-[#0f6f94]"></span>
                Total Sales
              </span>
              <select id="chartMetricFilter" class="hidden">
                <option value="sales" selected>Total Sales</option>
                <option value="gross_profit">Gross Profit</option>
              </select>
              <select id="chartRangeFilter" class="text-sm border border-[#b7d9e9] rounded-lg px-3 py-1.5 bg-white text-[#05445E] focus:outline-none focus:ring-2 focus:ring-[#0f6f94]/20">
                <option value="last4weeks" selected>Last 4 weeks</option>
                <option value="month">This month</option>
              </select>
            </div>
          </div>
          
          <div class="chart-container" id="chartContainer">
            <div id="chartTooltip" class="chart-tooltip">
              <div id="tooltipLabel" class="text-[#a0d2db] text-xs mb-1"></div>
              <div id="tooltipValue" class="text-lg font-bold"></div>
            </div>
            <svg id="chartSvg" width="100%" height="280" viewBox="0 0 600 280" preserveAspectRatio="xMidYMid meet" class="overflow-visible">
              <defs>
                <linearGradient id="lineGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                  <stop offset="0%" style="stop-color:#05445E;stop-opacity:1" />
                  <stop offset="50%" style="stop-color:#0f6f94;stop-opacity:1" />
                  <stop offset="100%" style="stop-color:#189ABD;stop-opacity:1" />
                </linearGradient>
                <linearGradient id="areaGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                  <stop offset="0%" style="stop-color:#0f6f94;stop-opacity:0.4" />
                  <stop offset="50%" style="stop-color:#0f6f94;stop-opacity:0.15" />
                  <stop offset="100%" style="stop-color:#0f6f94;stop-opacity:0.02" />
                </linearGradient>
                <linearGradient id="profitLineGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                  <stop offset="0%" style="stop-color:#166534;stop-opacity:1" />
                  <stop offset="50%" style="stop-color:#22c55e;stop-opacity:1" />
                  <stop offset="100%" style="stop-color:#4ade80;stop-opacity:1" />
                </linearGradient>
                <linearGradient id="profitAreaGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                  <stop offset="0%" style="stop-color:#22c55e;stop-opacity:0.4" />
                  <stop offset="50%" style="stop-color:#22c55e;stop-opacity:0.15" />
                  <stop offset="100%" style="stop-color:#22c55e;stop-opacity:0.02" />
                </linearGradient>
                <filter id="glow">
                  <feGaussianBlur stdDeviation="3" result="coloredBlur"/>
                  <feMerge>
                    <feMergeNode in="coloredBlur"/>
                    <feMergeNode in="SourceGraphic"/>
                  </feMerge>
                </filter>
                <filter id="dropShadow">
                  <feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="#0f6f94" flood-opacity="0.3"/>
                </filter>
              </defs>
              <g id="chartGrid"></g>
              <g id="chartArea"></g>
              <g id="chartLine"></g>
              <g id="chartDots"></g>
              <g id="chartLabels"></g>
            </svg>
          </div>
          
          <div class="flex justify-between items-center mt-4 pt-4 border-t border-[#e5f2f7]">
            <div class="flex items-center gap-2">
              <span class="text-xs text-[#6b9aaa]">Period Total:</span>
              <span id="chartTotalLabel" class="text-sm font-bold text-[#05445E]">PHP 0.00</span>
            </div>
            <div class="flex items-center gap-4">
              <div class="flex items-center gap-1.5">
                <span class="w-3 h-1 rounded-full bg-gradient-to-r from-[#05445E] to-[#189ABD]"></span>
                <span class="text-xs text-[#6b9aaa]">Sales</span>
              </div>
              <div class="flex items-center gap-1.5">
                <span class="w-3 h-1 rounded-full bg-gradient-to-r from-[#166534] to-[#4ade80]"></span>
                <span class="text-xs text-[#6b9aaa]">Profit</span>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl p-5 shadow-md border border-[#f1f5f9]">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-[#7c2d12] flex items-center gap-2">
              <span class="material-symbols-outlined text-[#dc2626]">warning</span>
              Inventory Alerts
            </h2>
            <span class="text-[11px] font-semibold text-[#b91c1c] bg-[#fee2e2] px-3 py-1 rounded-full tracking-wide">LOW STOCK</span>
          </div>
          <?php if (!$canJoinProducts): ?>
            <p class="text-sm text-[#9a3412]">Products table not available.</p>
          <?php elseif ($lowStockSummary['count'] === 0): ?>
            <p class="text-sm text-[#9a3412]">No low stock items right now.</p>
          <?php else: ?>
            <div class="space-y-3 max-h-[320px] min-h-[240px] overflow-y-auto pr-1">
              <?php foreach ($lowStockItems as $item): ?>
                <?php
                  $percent = min(100, max(0, $item['total'] / 5 * 100));
                ?>
                <div class="bg-white rounded-xl border border-[#f1f5f9] p-3 shadow-sm">
                  <div class="flex items-center justify-between text-sm font-semibold text-[#7c2d12]">
                    <span class="truncate pr-3"><?= h($item['name']) ?></span>
                    <span class="text-[#b91c1c]"><?= number_format((int)$item['total']) ?></span>
                  </div>
                  <div class="mt-2 h-2 bg-[#ffece5] rounded-full overflow-hidden">
                    <div style="width: <?= $percent ?>%;" class="h-full bg-[#f87171]"></div>
                  </div>
                  <p class="mt-2 text-[11px] uppercase tracking-wide text-[#b91c1c] font-semibold">Restock immediately</p>
                  <p class="text-[11px] text-[#9a3412]">Store <?= (int)$item['store'] ?> · Stockroom <?= (int)$item['stockroom'] ?></p>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <a href="inventory.php" class="mt-5 block w-full text-center text-[12px] font-semibold text-[#7c2d12] border border-[#fcd9bd] bg-white/60 hover:bg-white rounded-xl py-3 transition">
            GO TO INVENTORY
          </a>
        </div>
      </div>

      <div class="bg-white rounded-2xl p-5 shadow-md border border-white/40">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-lg font-medium text-[#05445E] flex items-center gap-2">
            <span class="material-symbols-outlined text-[#0f6f94]">receipt_long</span> Recent transactions
          </h2>
          <a href="sales.php" class="text-sm text-[#0f6f94] underline-offset-2 hover:underline">view all</a>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-[#2c7da0] border-b border-[#d0e7f2]">
              <tr>
                <th class="text-left py-3 font-medium">Transaction ID</th>
                <th class="text-left py-3 font-medium">Customer</th>
                <th class="text-left py-3 font-medium">Product</th>
                <th class="text-left py-3 font-medium">Qty</th>
                <th class="text-left py-3 font-medium">Payment</th>
                <th class="text-left py-3 font-medium">Date</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[#dff0f7]">
              <?php if ($recentRows === []): ?>
                <tr>
                  <td class="py-6 text-center text-[#2c7da0]" colspan="6">No transactions found yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentRows as $row): ?>
                  <tr class="hover:bg-[#f3fafd]">
                    <td class="py-3"><?= h((string)$row['receipt_no']) ?></td>
                    <td><?= h((string)$row['customer_name']) ?></td>
                    <td><?= h((string)$row['product_text']) ?></td>
                    <td><?= number_format((int)$row['qty']) ?></td>
                    <td><span class="<?= h((string)$row['status_class']) ?> px-2 py-1 rounded-full text-xs"><?= h((string)$row['status_label']) ?></span></td>
                    <td><?= h((string)$row['display_date']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <div class="fixed inset-0 -z-10 pointer-events-none overflow-hidden">
    <div class="absolute w-96 h-96 rounded-full bg-white/20 blur-3xl top-[10%] left-[5%] bg-bubble-float"></div>
    <div class="absolute w-64 h-64 rounded-full bg-cyan-200/20 blur-2xl bottom-[5%] right-[10%] bg-bubble-float" style="animation-delay: -5s;"></div>
  </div>

  <script>
    const sidebar = document.getElementById('sidebar');
    const collapseBtn = document.getElementById('collapseSidebar');
    if (collapseBtn && sidebar) {
      collapseBtn.addEventListener('click', () => {
        sidebar.classList.toggle('sidebar-collapsed');
        const icon = collapseBtn.querySelector('.material-symbols-outlined');
        if (sidebar.classList.contains('sidebar-collapsed')) {
          if (icon) icon.textContent = 'menu';
          sidebar.style.width = '5rem';
        } else {
          if (icon) icon.textContent = 'menu_open';
          sidebar.style.width = '18rem';
        }
      });
    }

    const todayMetricFilter = document.getElementById('todayMetricFilter');
    const todayMetricValue = document.getElementById('todayMetricValue');
    const todayMetricDelta = document.getElementById('todayMetricDelta');
    const dashboardChartData = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const chartRangeFilter = document.getElementById('chartRangeFilter');
    const chartMetricFilter = document.getElementById('chartMetricFilter');
    const chartMetricBadge = document.getElementById('chartMetricBadge');
    const chartSvg = document.getElementById('chartSvg');
    const chartGrid = document.getElementById('chartGrid');
    const chartArea = document.getElementById('chartArea');
    const chartLine = document.getElementById('chartLine');
    const chartDots = document.getElementById('chartDots');
    const chartLabels = document.getElementById('chartLabels');
    const chartTooltip = document.getElementById('chartTooltip');
    const tooltipLabel = document.getElementById('tooltipLabel');
    const tooltipValue = document.getElementById('tooltipValue');
    const chartTotalLabel = document.getElementById('chartTotalLabel');
    const chartContainer = document.getElementById('chartContainer');

    const formatMoney = (value) => {
      const amount = Number(value || 0);
      return `PHP ${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    const formatCompactMoney = (value) => {
      const amount = Number(value || 0);
      if (amount >= 1000) {
        return `PHP ${(amount / 1000).toFixed(1)}k`;
      }
      return `PHP ${amount.toFixed(0)}`;
    };

    const applyTodayMetric = () => {
      const metricKey = todayMetricFilter?.value === 'gross_profit' ? 'gross_profit' : 'sales';
      if (todayMetricValue) {
        const raw = metricKey === 'gross_profit'
          ? Number(todayMetricValue.dataset.grossProfit || 0)
          : Number(todayMetricValue.dataset.sales || 0);
        todayMetricValue.textContent = formatMoney(raw);
      }

      if (todayMetricDelta) {
        const currentClass = metricKey === 'gross_profit'
          ? (todayMetricDelta.dataset.grossProfitClass || '')
          : (todayMetricDelta.dataset.salesClass || '');
        const currentText = metricKey === 'gross_profit'
          ? (todayMetricDelta.dataset.grossProfitText || '')
          : (todayMetricDelta.dataset.salesText || '');
        ['text-[#0f6f94]', 'bg-[#dff3fc]', 'text-emerald-600', 'bg-emerald-50', 'text-red-600', 'bg-red-50']
          .forEach((className) => todayMetricDelta.classList.remove(className));
        currentClass.split(' ').filter(Boolean).forEach((className) => todayMetricDelta.classList.add(className));
        todayMetricDelta.textContent = currentText;
      }
    };

    // Smooth curve path calculation using cardinal spline
    const getCurvePath = (points, tension = 0.4) => {
      if (points.length < 2) return '';
      
      let path = `M ${points[0].x} ${points[0].y}`;
      
      for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i - 1] || points[i];
        const p1 = points[i];
        const p2 = points[i + 1];
        const p3 = points[i + 2] || p2;
        
        const cp1x = p1.x + (p2.x - p0.x) * tension / 2;
        const cp1y = p1.y + (p2.y - p0.y) * tension / 2;
        const cp2x = p2.x - (p3.x - p1.x) * tension / 2;
        const cp2y = p2.y - (p3.y - p1.y) * tension / 2;
        
        path += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${p2.x} ${p2.y}`;
      }
      
      return path;
    };

    const applyChartRange = () => {
      const rangeKey = chartRangeFilter?.value === 'month' ? 'month' : 'last4weeks';
      const metricKey = chartMetricFilter?.value === 'gross_profit' ? 'gross_profit' : 'sales';
      const payload = dashboardChartData?.[rangeKey] || {};
      const weekLabels = Array.isArray(payload?.labels) ? payload.labels : [];
      const displayLabels = Array.isArray(payload?.display_labels) ? payload.display_labels : weekLabels;
      const dateLabels = displayLabels.map((text) => {
        const match = String(text || '').match(/\((.+)\)/);
        return match ? match[1] : '';
      });
      const rawSeries = Array.isArray(payload?.[metricKey]) ? payload[metricKey] : [];
      const series = displayLabels.map((_, index) => Number(rawSeries[index] || 0));
      
      // Update badge
      if (chartMetricBadge) {
        if (metricKey === 'gross_profit') {
          chartMetricBadge.className = 'metric-badge gross_profit';
          chartMetricBadge.innerHTML = '<span class="w-2 h-2 rounded-full bg-[#22c55e]"></span> Gross Profit';
        } else {
          chartMetricBadge.className = 'metric-badge sales';
          chartMetricBadge.innerHTML = '<span class="w-2 h-2 rounded-full bg-[#0f6f94]"></span> Total Sales';
        }
      }
      
      // Chart dimensions
      const svgWidth = 600;
      const svgHeight = 280;
      const padding = { top: 30, right: 30, bottom: 50, left: 60 };
      const chartWidth = svgWidth - padding.left - padding.right;
      const chartHeight = svgHeight - padding.top - padding.bottom;
      
      const maxValue = Math.max(...series, 1);
      const niceMax = Math.ceil(maxValue / 5) * 5 || 5;
      
      // Calculate points
        const points = series.map((value, index) => {
          const weekLabel = weekLabels[index] || displayLabels[index] || '';
          const dateLabel = dateLabels[index] || '';
          const combinedLabel = dateLabel ? `${weekLabel} • ${dateLabel}` : weekLabel;
          return {
            x: padding.left + (index / Math.max(series.length - 1, 1)) * chartWidth,
            y: padding.top + chartHeight - (value / niceMax) * chartHeight,
            value: value,
            label: weekLabel,
            dateLabel,
            combinedLabel
          };
        });
      
      // Draw grid lines
      if (chartGrid) {
        let gridHtml = '';
        const gridLines = 5;
        for (let i = 0; i <= gridLines; i++) {
          const y = padding.top + (i / gridLines) * chartHeight;
          const gridValue = niceMax - (i / gridLines) * niceMax;
          gridHtml += `
            <line x1="${padding.left}" y1="${y}" x2="${svgWidth - padding.right}" y2="${y}" class="chart-grid-line" />
            <text x="${padding.left - 10}" y="${y + 4}" text-anchor="end" class="chart-value-label">${formatCompactMoney(gridValue)}</text>
          `;
        }
        chartGrid.innerHTML = gridHtml;
      }
      
      // Draw area fill
      if (chartArea && points.length > 0) {
        const areaPath = getCurvePath(points) + 
          ` L ${points[points.length - 1].x} ${padding.top + chartHeight}` +
          ` L ${points[0].x} ${padding.top + chartHeight} Z`;
        
        chartArea.innerHTML = `<path d="${areaPath}" fill="url(#${metricKey === 'gross_profit' ? 'profitAreaGradient' : 'areaGradient'})" />`;
      }
      
      // Draw line
      if (chartLine && points.length > 0) {
        const linePath = getCurvePath(points);
        chartLine.innerHTML = `<path d="${linePath}" fill="none" stroke="url(#${metricKey === 'gross_profit' ? 'profitLineGradient' : 'lineGradient'})" stroke-width="3" class="chart-line" filter="url(#dropShadow)" />`;
      }
      
      // Draw dots and labels
      if (chartDots && points.length > 0) {
        chartDots.innerHTML = points.map((point, index) => `
          <circle 
            cx="${point.x}" 
            cy="${point.y}" 
            r="6" 
            fill="white" 
            stroke="${metricKey === 'gross_profit' ? '#22c55e' : '#0f6f94'}" 
            stroke-width="3"
            class="chart-dot"
            data-index="${index}"
            data-value="${point.value}"
              data-label="${point.combinedLabel}"
            />
          `).join('');
        
        // Add hover events
        chartDots.querySelectorAll('.chart-dot').forEach(dot => {
          dot.addEventListener('mouseenter', (e) => {
            const value = parseFloat(e.target.dataset.value);
            const label = e.target.dataset.label;
            const index = parseInt(e.target.dataset.index);
            
            tooltipLabel.textContent = label;
            tooltipValue.textContent = formatMoney(value);
            
            chartTooltip.style.left = `${points[index].x}px`;
            chartTooltip.style.top = `${points[index].y - 50}px`;
            chartTooltip.classList.add('visible');
            
            e.target.setAttribute('r', '8');
          });
          
          dot.addEventListener('mouseleave', (e) => {
            chartTooltip.classList.remove('visible');
            e.target.setAttribute('r', '6');
          });
        });
      }
      
      // Draw x-axis labels
      if (chartLabels) {
          chartLabels.innerHTML = points.map((point) => `
            <text x="${point.x}" y="${svgHeight - 8}" text-anchor="middle" class="chart-label">
              <tspan x="${point.x}" dy="0">${point.label}</tspan>
              ${point.dateLabel ? `<tspan x="${point.x}" dy="14" class="fill-[#6b7280] text-[11px]">${point.dateLabel}</tspan>` : ''}
            </text>
          `).join('');
        }
      
      // Update total
      if (chartTotalLabel) {
        const total = series.reduce((sum, value) => sum + value, 0);
        chartTotalLabel.textContent = formatMoney(total);
      }
    };

    todayMetricFilter?.addEventListener('change', applyTodayMetric);
    chartRangeFilter?.addEventListener('change', applyChartRange);
    chartMetricBadge?.addEventListener('click', () => {
      const current = chartMetricFilter.value;
      chartMetricFilter.value = current === 'sales' ? 'gross_profit' : 'sales';
      applyChartRange();
    });
    
    applyTodayMetric();
    applyChartRange();
  </script>

  <?php if (isset($_SESSION['login_success'])): ?>
  <script>
    Swal.fire({
      title: 'Login Successful',
      text: 'Welcome back, <?php echo htmlspecialchars($_SESSION['admin_name'], ENT_QUOTES, 'UTF-8'); ?>!',
      icon: 'success',
      timer: 2000,
      showConfirmButton: false
    });
    <?php unset($_SESSION['login_success']); ?>
  </script>
  <?php endif; ?>
</body>
</html>
