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
        return 'text-amber-700 bg-amber-100';
    }
    if ($status === 'cancelled') {
        return 'text-red-700 bg-red-100';
    }
    return 'text-emerald-700 bg-emerald-100';
}

function parseDateInput(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$date) {
        return null;
    }
    return $date;
}

function resolveRange(string $range, string $start, string $end): array
{
    $now = new DateTimeImmutable('now');
    $dayEnd = $now->setTime(23, 59, 59);
    $range = in_array($range, ['today', 'this_month', 'this_week', 'last_2_days', 'this_year', 'all_time', 'custom'], true)
        ? $range
        : 'this_month';

    $from = null;
    $to = $dayEnd;
    $label = 'Whole Month';

    if ($range === 'today') {
        $from = $now->setTime(0, 0, 0);
        $label = 'Today';
    } elseif ($range === 'this_week') {
        $from = $now->modify('monday this week')->setTime(0, 0, 0);
        $label = 'Week';
    } elseif ($range === 'last_2_days') {
        $from = $now->modify('-1 day')->setTime(0, 0, 0);
        $label = 'Last 2 Days';
    } elseif ($range === 'this_year') {
        $from = $now->setDate((int)$now->format('Y'), 1, 1)->setTime(0, 0, 0);
        $label = 'One Year';
    } elseif ($range === 'all_time') {
        $from = null;
        $to = null;
        $label = 'All Time';
    } elseif ($range === 'custom') {
        $startDate = parseDateInput($start);
        $endDate = parseDateInput($end);
        if ($startDate && $endDate) {
            if ($startDate > $endDate) {
                $tmp = $startDate;
                $startDate = $endDate;
                $endDate = $tmp;
            }
            $from = $startDate->setTime(0, 0, 0);
            $to = $endDate->setTime(23, 59, 59);
            $label = 'Custom Range';
        } else {
            $range = 'this_month';
            $from = $now->modify('first day of this month')->setTime(0, 0, 0);
            $to = $dayEnd;
            $label = 'Whole Month';
        }
    } else {
        $from = $now->modify('first day of this month')->setTime(0, 0, 0);
        $to = $dayEnd;
        $label = 'Whole Month';
    }

    return [
        'range' => $range,
        'label' => $label,
        'from' => $from,
        'to' => $to,
    ];
}

function buildWhereClause(
    ?DateTimeImmutable $from,
    ?DateTimeImmutable $to,
    string $search,
    string $status,
    string &$bindTypes,
    array &$bindValues
): string {
    $bindTypes = '';
    $bindValues = [];
    $whereParts = [];

    if ($from !== null && $to !== null) {
        $whereParts[] = 'so.created_at BETWEEN ? AND ?';
        $bindTypes .= 'ss';
        $bindValues[] = $from->format('Y-m-d H:i:s');
        $bindValues[] = $to->format('Y-m-d H:i:s');
    }

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

    if ($status === 'paid') {
        $whereParts[] = 'so.payment_amount >= so.total_amount';
    } elseif ($status === 'pending') {
        $whereParts[] = 'so.payment_amount < so.total_amount';
    } elseif ($status === 'cancelled') {
        $whereParts[] = 'so.total_amount <= 0';
    }

    return $whereParts === [] ? '' : (' WHERE ' . implode(' AND ', $whereParts));
}

function buildReportUrl(array $params): string
{
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $clean[$key] = $value;
    }
    $query = http_build_query($clean);
    return 'reports.php' . ($query !== '' ? ('?' . $query) : '');
}

function getReportPeriodText(
    string $rangeKey,
    string $rangeLabel,
    ?string $startDate,
    ?string $endDate,
    ?string $generatedAt = null
): string
{
    $baseLabel = $rangeLabel;
    if ($rangeKey === 'custom' && $startDate !== null && $startDate !== '' && $endDate !== null && $endDate !== '') {
        $baseLabel = date('M d, Y', strtotime($startDate)) . ' to ' . date('M d, Y', strtotime($endDate));
    }

    $generatedAt = trim((string)($generatedAt ?? ''));
    if ($generatedAt === '') {
        return $baseLabel;
    }
    $generatedTs = strtotime($generatedAt);
    if ($generatedTs === false) {
        return $baseLabel;
    }

    return $baseLabel . ' at ' . date('M d, Y h:i A', $generatedTs);
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

function resolveStoredReportDateWindow(string $rangeKey, ?string $startDate, ?string $endDate): array
{
    $from = null;
    $to = null;

    $manualStart = parseDateInput((string)($startDate ?? ''));
    $manualEnd = parseDateInput((string)($endDate ?? ''));
    if ($manualStart !== null && $manualEnd !== null) {
        if ($manualStart > $manualEnd) {
            $tmp = $manualStart;
            $manualStart = $manualEnd;
            $manualEnd = $tmp;
        }
        $from = $manualStart->setTime(0, 0, 0);
        $to = $manualEnd->setTime(23, 59, 59);
    } else {
        $resolved = resolveRange($rangeKey, (string)($startDate ?? ''), (string)($endDate ?? ''));
        $from = $resolved['from'] instanceof DateTimeImmutable ? $resolved['from'] : null;
        $to = $resolved['to'] instanceof DateTimeImmutable ? $resolved['to'] : null;
    }

    return [
        'from' => $from,
        'to' => $to,
    ];
}

function topCustomerForReportRange(
    mysqli $conn,
    string $rangeKey,
    ?string $startDate,
    ?string $endDate
): array {
    static $cache = [];
    $cacheKey = $rangeKey . '|' . (string)($startDate ?? '') . '|' . (string)($endDate ?? '');
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $window = resolveStoredReportDateWindow($rangeKey, $startDate, $endDate);
    $from = $window['from'] instanceof DateTimeImmutable ? $window['from'] : null;
    $to = $window['to'] instanceof DateTimeImmutable ? $window['to'] : null;

    $sql = 'SELECT so.customer_name, COUNT(*) AS order_count, COALESCE(SUM(so.total_amount), 0) AS total_amount
            FROM sales_orders so';
    $types = '';
    $values = [];
    if ($from !== null && $to !== null) {
        $sql .= ' WHERE so.created_at BETWEEN ? AND ?';
        $types = 'ss';
        $values[] = $from->format('Y-m-d H:i:s');
        $values[] = $to->format('Y-m-d H:i:s');
    }
    $sql .= ' GROUP BY so.customer_name
              ORDER BY order_count DESC, total_amount DESC
              LIMIT 1';

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $name = $row ? trim((string)$row['customer_name']) : '';
    if ($name === '') {
        $name = 'No data';
    }
    $result = [
        'name' => $name,
        'orders' => $row ? (int)$row['order_count'] : 0,
        'amount' => $row ? (float)$row['total_amount'] : 0.0,
    ];
    $cache[$cacheKey] = $result;
    return $result;
}

function loadSalesRowsForReportRange(
    mysqli $conn,
    string $rangeKey,
    ?string $startDate,
    ?string $endDate
): array {
    $window = resolveStoredReportDateWindow($rangeKey, $startDate, $endDate);
    $from = $window['from'] instanceof DateTimeImmutable ? $window['from'] : null;
    $to = $window['to'] instanceof DateTimeImmutable ? $window['to'] : null;

    $sql = 'SELECT so.id, so.receipt_no, so.customer_name, so.total_amount, so.payment_amount, so.created_at,
                   COALESCE(SUM(soi.quantity), 0) AS total_qty,
                   GROUP_CONCAT(DISTINCT soi.product_name ORDER BY soi.product_name SEPARATOR "||") AS product_names
            FROM sales_orders so
            LEFT JOIN sales_order_items soi ON soi.order_id = so.id';
    $types = '';
    $values = [];
    if ($from !== null && $to !== null) {
        $sql .= ' WHERE so.created_at BETWEEN ? AND ?';
        $types = 'ss';
        $values[] = $from->format('Y-m-d H:i:s');
        $values[] = $to->format('Y-m-d H:i:s');
    }
    $sql .= ' GROUP BY so.id, so.receipt_no, so.customer_name, so.total_amount, so.payment_amount, so.created_at
              ORDER BY so.created_at DESC, so.id DESC';

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $totalAmount = (float)$row['total_amount'];
        $paymentAmount = (float)$row['payment_amount'];
        $status = paymentStatus($totalAmount, $paymentAmount);
        $rows[] = [
            'receipt_no' => (string)$row['receipt_no'],
            'customer_name' => (string)$row['customer_name'],
            'products' => array_values(array_filter(explode('||', (string)($row['product_names'] ?? '')))),
            'total_qty' => (int)$row['total_qty'],
            'total_amount' => $totalAmount,
            'payment_amount' => $paymentAmount,
            'status' => paymentStatusLabel($status),
            'created_at' => (string)$row['created_at'],
            'display_date' => date('M d, Y h:i A', strtotime((string)$row['created_at'])),
        ];
    }
    $stmt->close();
    return $rows;
}

function loadSalesItemRowsForReportRange(
    mysqli $conn,
    string $rangeKey,
    ?string $startDate,
    ?string $endDate
): array {
    $window = resolveStoredReportDateWindow($rangeKey, $startDate, $endDate);
    $from = $window['from'] instanceof DateTimeImmutable ? $window['from'] : null;
    $to = $window['to'] instanceof DateTimeImmutable ? $window['to'] : null;

    $canJoinProducts = tableExists($conn, 'products');
    $productCostSelect = $canJoinProducts ? 'COALESCE(p.cost, 0)' : '0';
    $productColorSpecsSelect = $canJoinProducts ? 'COALESCE(p.color_specs, "")' : '""';
    $sql = 'SELECT so.id AS order_id, so.customer_name, soi.product_name, soi.quantity, soi.unit_price, soi.line_total, '
         . $productCostSelect . ' AS product_cost, '
         . $productColorSpecsSelect . ' AS color_specs, so.created_at
            FROM sales_orders so
            INNER JOIN sales_order_items soi ON soi.order_id = so.id';
    if ($canJoinProducts) {
        $sql .= ' LEFT JOIN products p ON p.id = soi.product_id';
    }
    $types = '';
    $values = [];
    if ($from !== null && $to !== null) {
        $sql .= ' WHERE so.created_at BETWEEN ? AND ?';
        $types = 'ss';
        $values[] = $from->format('Y-m-d H:i:s');
        $values[] = $to->format('Y-m-d H:i:s');
    }
    $sql .= ' ORDER BY so.created_at DESC, so.id DESC, soi.id ASC';

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $quantity = (int)$row['quantity'];
        $unitPrice = (float)$row['unit_price'];
        $totalAmount = (float)$row['line_total'];
        $productCost = (float)$row['product_cost'];
        $netGross = $totalAmount - ($productCost * $quantity);
        $rows[] = [
            'order_id' => (int)$row['order_id'],
            'customer_name' => (string)$row['customer_name'],
            'product_name' => (string)$row['product_name'],
            'color_specs' => (string)$row['color_specs'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => $totalAmount,
            'net_gross' => $netGross,
            'date' => (string)$row['created_at'],
            'display_date' => date('M d, Y h:i A', strtotime((string)$row['created_at'])),
        ];
    }
    $stmt->close();
    return $rows;
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

$conn->query(
    "CREATE TABLE IF NOT EXISTS reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_code VARCHAR(60) NOT NULL,
        report_type VARCHAR(60) NOT NULL DEFAULT 'Sales',
        range_key VARCHAR(40) NOT NULL,
        range_label VARCHAR(120) NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'Completed',
        total_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_orders INT NOT NULL DEFAULT 0,
        total_customers INT NOT NULL DEFAULT 0,
        average_order_value DECIMAL(12,2) NOT NULL DEFAULT 0,
        notes VARCHAR(255) NOT NULL DEFAULT '',
        generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_report_code (report_code),
        KEY idx_range_key (range_key),
        KEY idx_generated_at (generated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$rangeInput = trim((string)($_GET['range'] ?? 'all_time'));
$startInput = trim((string)($_GET['start'] ?? ''));
$endInput = trim((string)($_GET['end'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$exportRequested = isset($_GET['export']) && (string)$_GET['export'] === '1';
$detailsJsonRequested = isset($_GET['details_json']) && (string)$_GET['details_json'] === '1';
$downloadReportId = (int)($_GET['download_report_id'] ?? 0);
$generateRequested = isset($_GET['generate']) && (string)$_GET['generate'] === '1';
$createdReportCode = trim((string)($_GET['report_code'] ?? ''));
$reportTitleInput = trim((string)($_GET['title'] ?? ''));
$deleteSuccess = isset($_GET['deleted']) && (string)$_GET['deleted'] === '1';
$deleteReportRequested = $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_report';

if (!in_array($statusFilter, ['all', 'paid', 'pending', 'cancelled'], true)) {
    $statusFilter = 'all';
}

$resolvedRange = resolveRange($rangeInput, $startInput, $endInput);
$range = (string)$resolvedRange['range'];
$rangeLabel = (string)$resolvedRange['label'];
$from = $resolvedRange['from'] instanceof DateTimeImmutable ? $resolvedRange['from'] : null;
$to = $resolvedRange['to'] instanceof DateTimeImmutable ? $resolvedRange['to'] : null;

$bindTypes = '';
$bindValues = [];
$whereClause = buildWhereClause($from, $to, $search, $statusFilter, $bindTypes, $bindValues);

$baseParams = [
    'range' => $range,
    'start' => $range === 'custom' ? $startInput : '',
    'end' => $range === 'custom' ? $endInput : '',
    'q' => $search,
    'status' => $statusFilter,
];

if ($deleteReportRequested) {
    $deleteReportId = (int)($_POST['delete_report_id'] ?? 0);
    if ($deleteReportId > 0) {
        $deleteStmt = $conn->prepare('DELETE FROM reports WHERE id = ? LIMIT 1');
        $deleteStmt->bind_param('i', $deleteReportId);
        $deleteStmt->execute();
        $deleteStmt->close();
    }
    $redirectPage = max(1, (int)($_POST['page'] ?? 1));
    header('Location: ' . buildReportUrl(array_merge($baseParams, ['page' => $redirectPage, 'deleted' => '1'])));
    exit;
}

if ($detailsJsonRequested) {
    header('Content-Type: application/json; charset=UTF-8');
    $reportId = (int)($_GET['report_id'] ?? 0);
    if ($reportId <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Invalid report ID.']);
        exit;
    }

    $reportStmt = $conn->prepare(
        'SELECT id, report_code, report_type, range_key, range_label, start_date, end_date, status,
                total_sales, total_orders, total_customers, average_order_value, notes, generated_at
         FROM reports
         WHERE id = ?
         LIMIT 1'
    );
    $reportStmt->bind_param('i', $reportId);
    $reportStmt->execute();
    $report = $reportStmt->get_result()->fetch_assoc();
    $reportStmt->close();

    if (!$report) {
        echo json_encode(['ok' => false, 'message' => 'Report not found.']);
        exit;
    }

    $periodText = getReportPeriodText(
        (string)$report['range_key'],
        (string)$report['range_label'],
        isset($report['start_date']) ? (string)$report['start_date'] : null,
        isset($report['end_date']) ? (string)$report['end_date'] : null,
        isset($report['generated_at']) ? (string)$report['generated_at'] : null
    );
    $salesRowsForReport = loadSalesItemRowsForReportRange(
        $conn,
        (string)$report['range_key'],
        isset($report['start_date']) ? (string)$report['start_date'] : null,
        isset($report['end_date']) ? (string)$report['end_date'] : null
    );
    $realTotalSales = 0.0;
    $realNetGross = 0.0;
    $orderIds = [];
    $customerNames = [];
    foreach ($salesRowsForReport as $row) {
        $realTotalSales += (float)($row['total_amount'] ?? 0);
        $realNetGross += (float)($row['net_gross'] ?? 0);
        $orderId = (int)($row['order_id'] ?? 0);
        if ($orderId > 0) {
            $orderIds[$orderId] = true;
        }
        $customerName = trim((string)($row['customer_name'] ?? ''));
        if ($customerName !== '') {
            $customerNames[strtolower($customerName)] = true;
        }
    }
    $totalOrdersFromReport = count($orderIds);
    $totalCustomersFromReport = count($customerNames);
    $realAverageOrder = $totalOrdersFromReport > 0 ? ($realTotalSales / $totalOrdersFromReport) : 0.0;

    echo json_encode([
        'ok' => true,
        'report' => [
            'report_code' => (string)$report['report_code'],
            'report_type' => (string)$report['report_type'],
            'period_text' => $periodText,
            'status' => (string)$report['status'],
            'total_sales' => $realTotalSales,
            'net_gross' => $realNetGross,
            'total_orders' => $totalOrdersFromReport,
            'total_customers' => $totalCustomersFromReport,
            'average_order_value' => $realAverageOrder,
            'notes' => (string)$report['notes'],
            'generated_at' => date('M d, Y h:i A', strtotime((string)$report['generated_at'])),
        ],
        'rows' => $salesRowsForReport,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($downloadReportId > 0) {
    $reportStmt = $conn->prepare(
        'SELECT id, report_code, report_type, range_key, range_label, start_date, end_date, status,
                total_sales, total_orders, total_customers, average_order_value, notes, generated_at
         FROM reports
         WHERE id = ?
         LIMIT 1'
    );
    $reportStmt->bind_param('i', $downloadReportId);
    $reportStmt->execute();
    $report = $reportStmt->get_result()->fetch_assoc();
    $reportStmt->close();

    if (!$report) {
        http_response_code(404);
        exit('Report not found.');
    }

    $salesItemRowsForReport = loadSalesItemRowsForReportRange(
        $conn,
        (string)$report['range_key'],
        isset($report['start_date']) ? (string)$report['start_date'] : null,
        isset($report['end_date']) ? (string)$report['end_date'] : null
    );

    $filename = '';
    if (!empty($report['notes'])) {
        $filename = trim((string)$report['notes']);
    }
    if ($filename === '') {
        $filename = trim((string)$report['report_type'] . ' ' . (string)$report['range_label']);
    }
    $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $filename) ?: 'report';
    $rowsForExport = [];
    $totalNetGross = 0.0;
    $salesTotalAmount = 0.0;
    foreach ($salesItemRowsForReport as $row) {
        $netGross = (float)$row['net_gross'];
        $totalNetGross += $netGross;
        $salesTotalAmount += (float)$row['total_amount'];
        $rowsForExport[] = [
            'product_name' => (string)$row['product_name'],
            'color_specs' => (string)$row['color_specs'],
            'quantity' => (int)$row['quantity'],
            'unit_price' => (float)$row['unit_price'],
            'total_amount' => (float)$row['total_amount'],
            'customer_name' => (string)$row['customer_name'],
            'net_gross' => $netGross,
            'date' => (string)$row['date'],
        ];
    }
    excelXmlOutputSalesRows(
        $filename,
        'Detailed Report',
        $rowsForExport,
        $totalNetGross,
        'GROSS PROFIT',
        $salesTotalAmount,
        'SALES TOTAL'
    );
    exit;
}

if ($generateRequested) {
    $generateSummarySql = 'SELECT
        COUNT(*) AS total_orders,
        COALESCE(AVG(so.total_amount), 0) AS avg_order_value,
        COUNT(DISTINCT so.customer_name) AS total_customers
        FROM sales_orders so' . $whereClause;
    $generateSummaryStmt = $conn->prepare($generateSummarySql);
    if ($bindTypes !== '') {
        $generateSummaryStmt->bind_param($bindTypes, ...$bindValues);
    }
    $generateSummaryStmt->execute();
    $generateSummary = $generateSummaryStmt->get_result()->fetch_assoc() ?: [];
    $generateSummaryStmt->close();
    $generateRealSalesSql = 'SELECT COALESCE(SUM(soi.line_total - (COALESCE(p.cost, 0) * soi.quantity)), 0) AS total_real_sales
                             FROM sales_orders so
                             INNER JOIN sales_order_items soi ON soi.order_id = so.id
                             LEFT JOIN products p ON p.id = soi.product_id' . $whereClause;
    $generateRealSalesStmt = $conn->prepare($generateRealSalesSql);
    if ($bindTypes !== '') {
        $generateRealSalesStmt->bind_param($bindTypes, ...$bindValues);
    }
    $generateRealSalesStmt->execute();
    $generateRealSalesRow = $generateRealSalesStmt->get_result()->fetch_assoc() ?: [];
    $generateRealSalesStmt->close();

    $reportCode = 'RPT-' . date('Ymd-His') . '-' . random_int(100, 999);
    $reportType = 'Sales';
    $rangeKey = $range;
    $rangeLabelValue = $rangeLabel;
    $startDate = $from?->format('Y-m-d');
    $endDate = $to?->format('Y-m-d');
    $reportStatus = 'Completed';
    $totalSalesForReport = (float)($generateRealSalesRow['total_real_sales'] ?? 0);
    $totalOrdersForReport = (int)($generateSummary['total_orders'] ?? 0);
    $totalCustomersForReport = (int)($generateSummary['total_customers'] ?? 0);
    $avgOrderForReport = $totalOrdersForReport > 0 ? ($totalSalesForReport / $totalOrdersForReport) : 0.0;
    $reportTitle = substr($reportTitleInput, 0, 255);
    $notes = $reportTitle !== ''
        ? $reportTitle
        : ($range === 'custom' ? 'Generated from custom date range.' : ('Generated for ' . $rangeLabelValue . '.'));

    $insertReportStmt = $conn->prepare(
        'INSERT INTO reports
            (report_code, report_type, range_key, range_label, start_date, end_date, status, total_sales, total_orders, total_customers, average_order_value, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertReportStmt->bind_param(
        'sssssssdiids',
        $reportCode,
        $reportType,
        $rangeKey,
        $rangeLabelValue,
        $startDate,
        $endDate,
        $reportStatus,
        $totalSalesForReport,
        $totalOrdersForReport,
        $totalCustomersForReport,
        $avgOrderForReport,
        $notes
    );
    $insertReportStmt->execute();
    $insertReportStmt->close();

    $redirectParams = $baseParams;
    $redirectParams['report_code'] = $reportCode;
    header('Location: ' . buildReportUrl($redirectParams));
    exit;
}

if ($exportRequested) {
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
    $exportSql .= $whereClause . ' ORDER BY so.created_at DESC, so.id DESC, soi.id ASC';
    $exportStmt = $conn->prepare($exportSql);
    if ($bindTypes !== '') {
        $exportStmt->bind_param($bindTypes, ...$bindValues);
    }
    $exportStmt->execute();
    $result = $exportStmt->get_result();

    $filename = 'reports_' . $range . '_' . date('Ymd_His');
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
        'Reports Export',
        $rowsForExport,
        $totalNetGross,
        'GROSS PROFIT',
        $salesTotalAmount,
        'SALES TOTAL'
    );
    exit;
}

$summarySql = 'SELECT
    COUNT(*) AS total_orders,
    COALESCE(SUM(so.total_amount), 0) AS total_sales,
    COALESCE(AVG(so.total_amount), 0) AS avg_order_value,
    COUNT(DISTINCT so.customer_name) AS total_customers
    FROM sales_orders so' . $whereClause;
$summaryStmt = $conn->prepare($summarySql);
if ($bindTypes !== '') {
    $summaryStmt->bind_param($bindTypes, ...$bindValues);
}
$summaryStmt->execute();
$summaryRow = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$topCustomerSql = 'SELECT so.customer_name, COUNT(*) AS order_count, COALESCE(SUM(so.total_amount), 0) AS total_amount
                   FROM sales_orders so' . $whereClause . '
                   GROUP BY so.customer_name
                   ORDER BY order_count DESC, total_amount DESC
                   LIMIT 1';
$topCustomerStmt = $conn->prepare($topCustomerSql);
if ($bindTypes !== '') {
    $topCustomerStmt->bind_param($bindTypes, ...$bindValues);
}
$topCustomerStmt->execute();
$topCustomer = $topCustomerStmt->get_result()->fetch_assoc();
$topCustomerStmt->close();

$reportWhereParts = [];
$reportBindTypes = '';
$reportBindValues = [];

if ($search !== '') {
    $reportWhereParts[] = '(r.report_code LIKE CONCAT("%", ?, "%")
        OR r.range_label LIKE CONCAT("%", ?, "%")
        OR r.notes LIKE CONCAT("%", ?, "%"))';
    $reportBindTypes .= 'sss';
    $reportBindValues[] = $search;
    $reportBindValues[] = $search;
    $reportBindValues[] = $search;
}

$reportWhereClause = $reportWhereParts === [] ? '' : (' WHERE ' . implode(' AND ', $reportWhereParts));

$reportCountSql = 'SELECT COUNT(*) AS total_rows FROM reports r' . $reportWhereClause;
$reportCountStmt = $conn->prepare($reportCountSql);
if ($reportBindTypes !== '') {
    $reportCountStmt->bind_param($reportBindTypes, ...$reportBindValues);
}
$reportCountStmt->execute();
$reportCountRow = $reportCountStmt->get_result()->fetch_assoc();
$reportCountStmt->close();
$reportTotalRows = (int)($reportCountRow['total_rows'] ?? 0);
$allReportsCountRow = $conn->query('SELECT COUNT(*) AS total_rows FROM reports')->fetch_assoc();
$allReportsCount = (int)($allReportsCountRow['total_rows'] ?? 0);
$reportTotalPages = max(1, (int)ceil($reportTotalRows / $perPage));
$page = min($page, $reportTotalPages);
$offset = ($page - 1) * $perPage;

$reportsSql = 'SELECT r.id, r.report_code, r.report_type, r.range_key, r.range_label, r.start_date, r.end_date, r.status,
                      r.total_sales, r.total_orders, r.total_customers, r.average_order_value, r.notes, r.generated_at
               FROM reports r' . $reportWhereClause . '
               ORDER BY r.generated_at DESC, r.id DESC
               LIMIT ? OFFSET ?';
$reportsStmt = $conn->prepare($reportsSql);
$reportsTypes = $reportBindTypes . 'ii';
$reportsValues = array_merge($reportBindValues, [$perPage, $offset]);
$reportsStmt->bind_param($reportsTypes, ...$reportsValues);
$reportsStmt->execute();
$reportsResult = $reportsStmt->get_result();

$reportRows = [];
while ($row = $reportsResult->fetch_assoc()) {
    $rowRange = (string)$row['range_key'];
    $rowStart = (string)($row['start_date'] ?? '');
    $rowEnd = (string)($row['end_date'] ?? '');
    $rowTopCustomer = topCustomerForReportRange(
        $conn,
        $rowRange,
        $rowStart !== '' ? $rowStart : null,
        $rowEnd !== '' ? $rowEnd : null
    );
    $periodText = getReportPeriodText(
        $rowRange,
        (string)$row['range_label'],
        $rowStart !== '' ? $rowStart : null,
        $rowEnd !== '' ? $rowEnd : null,
        isset($row['generated_at']) ? (string)$row['generated_at'] : null
    );

    $reportRows[] = [
        'id' => (int)$row['id'],
        'report_code' => (string)$row['report_code'],
        'report_type' => (string)$row['report_type'],
        'range_key' => $rowRange,
        'start_date' => $rowStart,
        'end_date' => $rowEnd,
        'period_text' => $periodText,
        'status' => (string)$row['status'],
        'total_sales' => (float)$row['total_sales'],
        'total_orders' => (int)$row['total_orders'],
        'total_customers' => (int)$row['total_customers'],
        'avg_order' => (float)$row['average_order_value'],
        'top_customer_name' => (string)$rowTopCustomer['name'],
        'top_customer_orders' => (int)$rowTopCustomer['orders'],
        'top_customer_amount' => (float)$rowTopCustomer['amount'],
        'notes' => (string)$row['notes'],
        'generated_at' => (string)$row['generated_at'],
        'display_generated_at' => date('M d, Y h:i A', strtotime((string)$row['generated_at'])),
        'download_url' => buildReportUrl(['download_report_id' => (int)$row['id']]),
    ];
}
$reportsStmt->close();

$topProductsSql = 'SELECT soi.product_name, COALESCE(SUM(soi.quantity), 0) AS qty, COALESCE(SUM(soi.line_total), 0) AS revenue
                   FROM sales_orders so
                   INNER JOIN sales_order_items soi ON soi.order_id = so.id'
                   . $whereClause . '
                   GROUP BY soi.product_name
                   ORDER BY qty DESC, revenue DESC
                   LIMIT 6';
$topProductsStmt = $conn->prepare($topProductsSql);
if ($bindTypes !== '') {
    $topProductsStmt->bind_param($bindTypes, ...$bindValues);
}
$topProductsStmt->execute();
$topProductsResult = $topProductsStmt->get_result();
$topProducts = [];
while ($row = $topProductsResult->fetch_assoc()) {
    $topProducts[] = [
        'product_name' => (string)$row['product_name'],
        'qty' => (int)$row['qty'],
        'revenue' => (float)$row['revenue'],
    ];
}
$topProductsStmt->close();
$topQtyMax = 1;
foreach ($topProducts as $item) {
    $topQtyMax = max($topQtyMax, (int)$item['qty']);
}

$dailyTrendSql = 'SELECT DATE(so.created_at) AS day_key, COALESCE(SUM(so.total_amount), 0) AS total_sales
                  FROM sales_orders so'
                  . $whereClause .
                 ' GROUP BY DATE(so.created_at)
                   ORDER BY DATE(so.created_at) DESC
                   LIMIT 7';
$dailyTrendStmt = $conn->prepare($dailyTrendSql);
if ($bindTypes !== '') {
    $dailyTrendStmt->bind_param($bindTypes, ...$bindValues);
}
$dailyTrendStmt->execute();
$dailyTrendResult = $dailyTrendStmt->get_result();
$dailyTrend = [];
while ($row = $dailyTrendResult->fetch_assoc()) {
    $dailyTrend[] = [
        'label' => date('d M', strtotime((string)$row['day_key'])),
        'total_sales' => (float)$row['total_sales'],
    ];
}
$dailyTrendStmt->close();

$totalOrders = (int)($summaryRow['total_orders'] ?? 0);
$totalSales = (float)($summaryRow['total_sales'] ?? 0);
$avgOrderValue = (float)($summaryRow['avg_order_value'] ?? 0);
$totalCustomers = (int)($summaryRow['total_customers'] ?? 0);
$topCustomerName = $topCustomer ? (string)$topCustomer['customer_name'] : 'No data';
$topCustomerOrders = $topCustomer ? (int)$topCustomer['order_count'] : 0;
$topCustomerTotal = $topCustomer ? (float)$topCustomer['total_amount'] : 0.0;

$startItem = $reportTotalRows > 0 ? ($offset + 1) : 0;
$endItem = min($offset + $perPage, $reportTotalRows);

$paginationParams = $baseParams;
if ($createdReportCode !== '') {
    $paginationParams['report_code'] = $createdReportCode;
}
$prevPageUrl = buildReportUrl(array_merge($paginationParams, ['page' => max(1, $page - 1)]));
$nextPageUrl = buildReportUrl(array_merge($paginationParams, ['page' => min($reportTotalPages, $page + 1)]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reports</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0,1" />
  <style>
    .stat-card { transition: transform 0.15s ease, box-shadow 0.2s ease; }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 20px 25px -5px rgba(0, 148, 195, 0.15), 0 10px 10px -5px rgba(0, 148, 195, 0.1); }
    .report-item { transition: all 0.2s ease; }
    .report-item:hover { background: #f8fafc; border-color: #0284c7; }
    .modal-backdrop { background: rgba(2, 36, 52, .45); backdrop-filter: blur(2px); }
    @keyframes floatBubble {
      0% { transform: translateY(0) scale(1); opacity: 0.2; }
      50% { transform: translateY(-20px) scale(1.05); opacity: 0.15; }
      100% { transform: translateY(0) scale(1); opacity: 0.2; }
    }
    .bg-bubble-float { animation: floatBubble 18s infinite ease-in-out; }
  </style>
</head>
<body class="bg-[#e6f4fa] font-sans antialiased text-[#043b4a] min-h-screen flex">
  <?php include '../includes/sidebar.php'; ?>

  <div class="flex-1 flex flex-col w-full min-w-0">
    <header class="bg-white/90 backdrop-blur-md border-b border-white/40 px-3 sm:px-4 lg:px-6 py-2 sm:py-3 flex items-center justify-between sticky top-0 z-10 shadow-sm">
      <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1 lg:ml-0 ml-12">
        <h1 class="text-sm sm:text-base lg:text-lg xl:text-xl font-light text-[#05445E] truncate">
          <span class="font-semibold">Reports</span>
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
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40">
          <h3 class="text-lg font-medium text-[#05445E] mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#0f6f94]">description</span>
            Total Reports
          </h3>
          <p id="reportsCountCard" class="text-3xl font-semibold text-[#05445E] mt-1"><?= number_format($reportTotalRows) ?></p>
          <p class="text-xs text-[#2c7da0] mt-2">Updates while you search/filter the report table.</p>
        </div>

        <div class="bg-white rounded-2xl p-5 shadow-md border border-white/40">
          <h3 class="text-lg font-medium text-[#05445E] mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#0f6f94]">trending_up</span>
            Last 7 Days Trend
          </h3>
          <?php if ($dailyTrend === []): ?>
            <p class="text-sm text-[#2c7da0]">No trend data for this period.</p>
          <?php else: ?>
            <div class="space-y-3">
              <?php
              $trendMax = 1.0;
              foreach ($dailyTrend as $trend) {
                  $trendMax = max($trendMax, (float)$trend['total_sales']);
              }
              foreach ($dailyTrend as $trend):
                  $width = (int)round(((float)$trend['total_sales'] / $trendMax) * 100);
              ?>
                <div>
                  <div class="flex justify-between text-xs text-[#2c7da0] mb-1">
                    <span><?= h((string)$trend['label']) ?></span>
                    <span><?= h(peso((float)$trend['total_sales'])) ?></span>
                  </div>
                  <div class="h-2 rounded-full bg-[#e2f0f7] overflow-hidden">
                    <div class="h-full bg-[#0f6f94]" style="width: <?= $width ?>%"></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="bg-white rounded-2xl p-5 shadow-md border border-white/40">
          <h3 class="text-lg font-medium text-[#05445E] mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-[#0f6f94]">inventory</span>
            Top Products
          </h3>
          <?php if ($topProducts === []): ?>
            <p class="text-sm text-[#2c7da0]">No product sales for this period.</p>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($topProducts as $item): ?>
                <?php $width = (int)round(((int)$item['qty'] / $topQtyMax) * 100); ?>
                <div>
                  <div class="flex justify-between text-xs text-[#2c7da0] mb-1">
                    <span class="truncate max-w-[70%]"><?= h((string)$item['product_name']) ?></span>
                    <span><?= number_format((int)$item['qty']) ?> pcs</span>
                  </div>
                  <div class="h-2 rounded-full bg-[#e2f0f7] overflow-hidden">
                    <div class="h-full bg-[#1e8ab3]" style="width: <?= $width ?>%"></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="bg-white rounded-2xl p-5 shadow-md border border-white/40">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-[#05445E] flex items-center gap-2">
            <span class="material-symbols-outlined text-[#0f6f94]">table_view</span>
            Detailed Reports
          </h3>
          <span class="text-sm text-[#2c7da0]">Showing <?= number_format($startItem) ?>-<?= number_format($endItem) ?> of <?= number_format($reportTotalRows) ?></span>
        </div>
        <form method="get" id="reportFiltersForm" class="mb-4 flex flex-col md:flex-row md:items-center md:justify-end gap-3">
          <div class="relative w-full md:w-[360px]">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#2c7da0]">search</span>
            <input type="text" id="reportSearchInput" name="q" value="<?= h($search) ?>" placeholder="Search receipt, customer, products..." class="w-full pl-10 pr-4 py-2 border border-[#b7d9e9] rounded-lg focus:outline-none focus:border-[#0f6f94]">
          </div>
          <select name="range" id="reportRangeFilter" class="px-4 py-2 border border-[#b7d9e9] rounded-lg bg-white focus:outline-none focus:border-[#0f6f94] md:w-[180px]">
            <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
            <option value="this_month" <?= $range === 'this_month' ? 'selected' : '' ?>>Whole Month</option>
            <option value="this_week" <?= $range === 'this_week' ? 'selected' : '' ?>>Week</option>
            <option value="last_2_days" <?= $range === 'last_2_days' ? 'selected' : '' ?>>Last 2 Days</option>
            <option value="this_year" <?= $range === 'this_year' ? 'selected' : '' ?>>One Year</option>
            <option value="all_time" <?= $range === 'all_time' ? 'selected' : '' ?>>All Time</option>
            <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom</option>
          </select>
          <div id="customDateFilterInputs" class="<?= $range === 'custom' ? '' : 'hidden' ?> grid grid-cols-1 sm:grid-cols-2 gap-3 md:w-auto">
            <input type="date" id="reportStartFilter" name="start" value="<?= h($startInput) ?>" class="px-4 py-2 border border-[#b7d9e9] rounded-lg bg-white focus:outline-none focus:border-[#0f6f94] md:w-[170px]">
            <input type="date" id="reportEndFilter" name="end" value="<?= h($endInput) ?>" class="px-4 py-2 border border-[#b7d9e9] rounded-lg bg-white focus:outline-none focus:border-[#0f6f94] md:w-[170px]">
          </div>
        </form>
        <p class="text-xs text-[#2c7da0] mb-3">Shows all generated reports. Use search to narrow this list.</p>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-[#2c7da0] border-b border-[#d0e7f2]">
              <tr>
                <th class="text-left py-3 font-medium">Report ID</th>
                <th class="text-left py-3 font-medium">Report Type</th>
                <th class="text-left py-3 font-medium">Description</th>
                <th class="text-left py-3 font-medium">Period</th>
                <th class="text-left py-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody id="reportTableBody" class="divide-y divide-[#dff0f7]">
              <?php if ($reportRows === []): ?>
                <?php if ($allReportsCount === 0): ?>
                  <tr><td colspan="5" class="py-8 text-center text-[#2c7da0]">No generated reports yet. Create one from Sales using Generate Report.</td></tr>
                <?php else: ?>
                  <tr><td colspan="5" class="py-8 text-center text-[#2c7da0]">No generated reports matched your search. Try clearing the search field.</td></tr>
                <?php endif; ?>
              <?php else: ?>
                <?php foreach ($reportRows as $report): ?>
                  <tr class="report-item <?= $createdReportCode !== '' && $createdReportCode === (string)$report['report_code'] ? 'bg-[#eef8fc]' : '' ?>"
                      data-range-key="<?= h((string)$report['range_key']) ?>"
                      data-start-date="<?= h((string)$report['start_date']) ?>"
                      data-end-date="<?= h((string)$report['end_date']) ?>"
                      data-top-customer-name="<?= h((string)$report['top_customer_name']) ?>"
                      data-top-customer-orders="<?= (int)$report['top_customer_orders'] ?>"
                      data-top-customer-amount="<?= number_format((float)$report['top_customer_amount'], 2, '.', '') ?>">
                    <td class="py-3 font-medium text-[#05445E]"><?= h((string)$report['report_code']) ?></td>
                    <td class="py-3"><?= h((string)$report['report_type']) ?></td>
                    <td class="py-3 text-sm">
                      <p class="text-[#05445E]"><?= h((string)$report['notes']) ?></p>
                      <p class="text-[#2c7da0] text-xs mt-1">Sales: <?= h(peso((float)$report['total_sales'])) ?> | Orders: <?= number_format((int)$report['total_orders']) ?> | Customers: <?= number_format((int)$report['total_customers']) ?> | Avg: <?= h(peso((float)$report['avg_order'])) ?></p>
                    </td>
                    <td class="py-3"><?= h((string)$report['period_text']) ?></td>
                    <td class="py-3">
                      <div class="flex items-center gap-2">
                        <button type="button" class="view-report-btn text-[#0f6f94] hover:text-[#05445E] p-1" data-report-id="<?= (int)$report['id'] ?>" title="View">
                          <span class="material-symbols-outlined">visibility</span>
                        </button>
                        <a href="<?= h((string)$report['download_url']) ?>" class="text-[#0f6f94] hover:text-[#05445E] p-1" title="Download Excel">
                          <span class="material-symbols-outlined">download</span>
                        </a>
                        <form method="post" action="<?= h(buildReportUrl(array_merge($baseParams, ['page' => $page]))) ?>" class="inline delete-report-form">
                          <input type="hidden" name="action" value="delete_report">
                          <input type="hidden" name="delete_report_id" value="<?= (int)$report['id'] ?>">
                          <input type="hidden" name="page" value="<?= (int)$page ?>">
                          <button type="submit" class="text-red-600 hover:text-red-700 p-1" title="Delete Report">
                            <span class="material-symbols-outlined">delete</span>
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="flex justify-between items-center mt-6 pt-4 border-t border-[#dff0f7]">
          <span class="text-sm text-[#2c7da0]">Top customer: <?= h($topCustomerName) ?> (<?= h(peso($topCustomerTotal)) ?>)</span>
          <div class="flex items-center gap-2">
            <?php if ($page > 1): ?>
              <a href="<?= h($prevPageUrl) ?>" class="px-3 py-1 border border-[#b7d9e9] rounded-lg hover:bg-[#f3fafd]"><span class="material-symbols-outlined">chevron_left</span></a>
            <?php else: ?>
              <button disabled class="px-3 py-1 border border-[#b7d9e9] rounded-lg opacity-50"><span class="material-symbols-outlined">chevron_left</span></button>
            <?php endif; ?>
            <span class="text-sm text-[#05445E]">Page <?= number_format($page) ?> of <?= number_format($reportTotalPages) ?></span>
            <?php if ($page < $reportTotalPages): ?>
              <a href="<?= h($nextPageUrl) ?>" class="px-3 py-1 border border-[#b7d9e9] rounded-lg hover:bg-[#f3fafd]"><span class="material-symbols-outlined">chevron_right</span></a>
            <?php else: ?>
              <button disabled class="px-3 py-1 border border-[#b7d9e9] rounded-lg opacity-50"><span class="material-symbols-outlined">chevron_right</span></button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </main>
  </div>

  <div class="fixed inset-0 -z-10 pointer-events-none overflow-hidden">
    <div class="absolute w-96 h-96 rounded-full bg-white/20 blur-3xl top-[10%] left-[5%] bg-bubble-float"></div>
    <div class="absolute w-64 h-64 rounded-full bg-cyan-200/20 blur-2xl bottom-[5%] right-[10%] bg-bubble-float" style="animation-delay: -5s;"></div>
  </div>

  <div id="reportDetailsModal" class="fixed inset-0 z-50 hidden modal-backdrop items-center justify-center p-4">
    <div class="bg-white w-full max-w-5xl rounded-2xl shadow-xl border border-[#d0e7f2] overflow-hidden">
      <div class="px-6 py-4 border-b border-[#dff0f7] bg-gradient-to-r from-[#f4fbff] to-[#ecf7fd] flex items-center justify-between">
        <div>
          <h3 class="text-lg font-semibold text-[#05445E]">Report Details</h3>
          <p id="reportDetailsSubtitle" class="text-xs text-[#2c7da0] mt-0.5">Detailed generated report view.</p>
        </div>
        <button type="button" id="closeReportDetailsModal" class="text-[#2c7da0] hover:text-[#05445E]">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <div class="p-6 space-y-5 max-h-[85vh] overflow-y-auto">
        <div id="reportSummaryCards" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3"></div>
        <div>
          <h4 class="text-sm font-semibold text-[#05445E] mb-2">Sales Rows</h4>
          <div class="overflow-x-auto border border-[#dff0f7] rounded-xl">
            <table class="w-full text-sm">
              <thead class="text-[#2c7da0] border-b border-[#d0e7f2] bg-[#f8fcff]">
                <tr>
                  <th class="text-left py-2 px-3 font-medium">Product Name</th>
                  <th class="text-left py-2 px-3 font-medium">Color / Specs</th>
                  <th class="text-left py-2 px-3 font-medium">Quantity</th>
                  <th class="text-left py-2 px-3 font-medium">Unit Price</th>
                  <th class="text-left py-2 px-3 font-medium">Total Amount</th>
                  <th class="text-left py-2 px-3 font-medium">Customer Name</th>
                  <th class="text-left py-2 px-3 font-medium">Net Gross</th>
                  <th class="text-left py-2 px-3 font-medium">Date</th>
                </tr>
              </thead>
              <tbody id="reportSalesRowsBody" class="divide-y divide-[#ecf5fa]"></tbody>
            </table>
          </div>
          <div id="reportTotalsBar" class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="flex justify-between items-center rounded-lg border border-[#dff0f7] bg-white px-4 py-3">
              <span class="text-sm text-[#2c7da0]">Total Sales</span>
              <span id="reportTotalSalesValue" class="font-semibold text-[#05445E]">PHP 0.00</span>
            </div>
            <div class="flex justify-between items-center rounded-lg border border-[#dff0f7] bg-white px-4 py-3">
              <span class="text-sm text-[#2c7da0]">Net Gross</span>
              <span id="reportNetGrossValue" class="font-semibold text-[#05445E]">PHP 0.00</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    const reportDetailsModal = document.getElementById('reportDetailsModal');
    const closeReportDetailsModal = document.getElementById('closeReportDetailsModal');
    const reportDetailsSubtitle = document.getElementById('reportDetailsSubtitle');
    const reportSummaryCards = document.getElementById('reportSummaryCards');
    const reportSalesRowsBody = document.getElementById('reportSalesRowsBody');
    const reportTotalSalesValue = document.getElementById('reportTotalSalesValue');
    const reportNetGrossValue = document.getElementById('reportNetGrossValue');

    function formatMoney(value) {
      const amount = Number(value || 0);
      return `PHP ${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, (char) => {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return map[char] || char;
      });
    }

    function openReportModal() {
      if (!reportDetailsModal) return;
      reportDetailsModal.classList.remove('hidden');
      reportDetailsModal.classList.add('flex');
    }

    function closeReportModal() {
      if (!reportDetailsModal) return;
      reportDetailsModal.classList.add('hidden');
      reportDetailsModal.classList.remove('flex');
    }

    function renderReportDetails(payload) {
      const report = payload?.report || {};
      const rows = Array.isArray(payload?.rows) ? payload.rows : [];

      if (reportDetailsSubtitle) {
        const period = report.period_text ? ` • ${report.period_text}` : '';
        reportDetailsSubtitle.textContent = `${report.report_code || '-'} | ${report.report_type || 'Sales'} report${period}`;
      }

      if (reportSummaryCards) {
        const summaryCards = [
          { label: 'Report ID', value: escapeHtml(report.report_code || '-') },
          { label: 'Total Orders', value: Number(report.total_orders || 0).toLocaleString() },
          { label: 'Total Customers', value: Number(report.total_customers || 0).toLocaleString() }
        ];
        reportSummaryCards.innerHTML = summaryCards.map((item) => `
          <div class="rounded-xl border border-[#dff0f7] bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-[#2c7da0]">${item.label}</p>
            <p class="text-base font-semibold text-[#05445E] mt-1">${item.value}</p>
          </div>
        `).join('');
      }

      if (reportSalesRowsBody) {
        if (rows.length === 0) {
          reportSalesRowsBody.innerHTML = `<tr><td colspan="8" class="py-3 px-3 text-center text-[#2c7da0]">No sales rows found for this report.</td></tr>`;
        } else {
          reportSalesRowsBody.innerHTML = rows.map((row) => `
            <tr>
              <td class="py-2 px-3">${escapeHtml(row.product_name || '-')}</td>
              <td class="py-2 px-3">${escapeHtml(row.color_specs || '-')}</td>
              <td class="py-2 px-3">${Number(row.quantity || 0).toLocaleString()}</td>
              <td class="py-2 px-3">${formatMoney(row.unit_price)}</td>
              <td class="py-2 px-3">${formatMoney(row.total_amount)}</td>
              <td class="py-2 px-3">${escapeHtml(row.customer_name || '-')}</td>
              <td class="py-2 px-3">${formatMoney(row.net_gross)}</td>
              <td class="py-2 px-3">${escapeHtml(row.display_date || '-')}</td>
            </tr>
            `).join('');
        }
      }

      if (reportTotalSalesValue) {
        reportTotalSalesValue.textContent = formatMoney(report.total_sales);
      }
      if (reportNetGrossValue) {
        reportNetGrossValue.textContent = formatMoney(report.net_gross);
      }
    }

    document.querySelectorAll('.view-report-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const reportId = Number(btn.dataset.reportId || 0);
        if (reportId <= 0) return;
        try {
          const response = await fetch(`reports.php?details_json=1&report_id=${reportId}`);
          const payload = await response.json();
          if (!payload?.ok) {
            alert(payload?.message || 'Unable to load report details.');
            return;
          }
          renderReportDetails(payload);
          openReportModal();
        } catch (error) {
          alert('Unable to load report details. Please try again.');
        }
      });
    });

    closeReportDetailsModal?.addEventListener('click', closeReportModal);
    reportDetailsModal?.addEventListener('click', (event) => {
      if (event.target === reportDetailsModal) {
        closeReportModal();
      }
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

    const filtersForm = document.getElementById('reportFiltersForm');
    const searchInput = document.getElementById('reportSearchInput');
    const rangeFilter = document.getElementById('reportRangeFilter');
    const customInputs = document.getElementById('customDateFilterInputs');
    const startFilter = document.getElementById('reportStartFilter');
    const endFilter = document.getElementById('reportEndFilter');
    const reportTableBody = document.getElementById('reportTableBody');
    const reportsCountCard = document.getElementById('reportsCountCard');
    const deleteReportForms = document.querySelectorAll('.delete-report-form');
    const deleteSuccess = <?= json_encode($deleteSuccess, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    deleteReportForms.forEach((form) => {
      form.addEventListener('submit', (event) => {
        if (form.dataset.confirmed === '1') {
          return;
        }
        event.preventDefault();
        const proceed = () => {
          form.dataset.confirmed = '1';
          form.submit();
        };
        if (window.Swal) {
          Swal.fire({
            title: 'Delete report?',
            text: 'This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Delete'
          }).then((result) => {
            if (result.isConfirmed) {
              proceed();
            }
          });
        } else if (confirm('Delete this generated report? This cannot be undone.')) {
          proceed();
        }
      });
    });

    if (deleteSuccess && window.Swal) {
      Swal.fire({
        icon: 'success',
        title: 'Report Deleted',
        text: 'The report was removed successfully.',
        confirmButtonColor: '#0f6f94'
      });
      if (window.history?.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.delete('deleted');
        const cleanQuery = url.searchParams.toString();
        const cleanUrl = `${url.pathname}${cleanQuery ? `?${cleanQuery}` : ''}${url.hash}`;
        window.history.replaceState({}, document.title, cleanUrl);
      }
    }

    filtersForm?.addEventListener('submit', (event) => {
      event.preventDefault();
      applyLocalReportFilters();
    });

    const applyLocalReportFilters = () => {
      if (!reportTableBody) return;
      const query = (searchInput?.value || '').trim().toLowerCase();
      const selectedRange = (rangeFilter?.value || 'all_time').trim();
      const selectedStart = (startFilter?.value || '').trim();
      const selectedEnd = (endFilter?.value || '').trim();
      const reportRows = Array.from(reportTableBody.querySelectorAll('tr.report-item'));
      const existingEmpty = document.getElementById('reportSearchEmptyRow');
      let visibleCount = 0;

      reportRows.forEach((row) => {
        const text = (row.textContent || '').toLowerCase();
        const rowRangeKey = (row.dataset.rangeKey || '').trim();
        const rowStartDate = (row.dataset.startDate || '').trim();
        const rowEndDate = (row.dataset.endDate || '').trim();

        const matchesQuery = query === '' || text.includes(query);
        let matchesRange = true;

        if (selectedRange !== 'all_time') {
          if (selectedRange === 'custom') {
            if (selectedStart !== '' && selectedEnd !== '' && rowStartDate !== '' && rowEndDate !== '') {
              matchesRange = rowStartDate <= selectedEnd && rowEndDate >= selectedStart;
            } else if (selectedStart !== '' || selectedEnd !== '') {
              matchesRange = false;
            }
          } else {
            matchesRange = rowRangeKey === selectedRange;
          }
        }

        const isVisible = matchesQuery && matchesRange;
        row.classList.toggle('hidden', !isVisible);
        if (isVisible) {
          visibleCount += 1;
        }
      });

      if (existingEmpty) {
        existingEmpty.remove();
      }

      if (reportRows.length > 0 && visibleCount === 0) {
        const emptyRow = document.createElement('tr');
        emptyRow.id = 'reportSearchEmptyRow';
        emptyRow.innerHTML = '<td colspan="5" class="py-8 text-center text-[#2c7da0]">No generated reports matched your filters.</td>';
        reportTableBody.appendChild(emptyRow);
      }

      if (reportsCountCard) {
        reportsCountCard.textContent = visibleCount.toLocaleString();
      }
    };

    searchInput?.addEventListener('input', applyLocalReportFilters);
    applyLocalReportFilters();

    rangeFilter?.addEventListener('change', () => {
      const showCustom = rangeFilter.value === 'custom';
      customInputs?.classList.toggle('hidden', !showCustom);
      if (!showCustom) {
        if (startFilter) startFilter.value = '';
        if (endFilter) endFilter.value = '';
        applyLocalReportFilters();
      } else {
        applyLocalReportFilters();
      }
    });

    const onCustomDateChange = () => {
      if ((rangeFilter?.value || '') !== 'custom') return;
      applyLocalReportFilters();
    };
    startFilter?.addEventListener('input', onCustomDateChange);
    endFilter?.addEventListener('input', onCustomDateChange);
  </script>
</body>
</html>
