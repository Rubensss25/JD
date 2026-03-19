<?php
declare(strict_types=1);

// Include session validation and cache control
require_once __DIR__ . '/../includes/auth_check.php';

require_once __DIR__ . '/../config/connect.php';


function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function peso(float $value): string
{
    return 'PHP ' . number_format($value, 2);
}

function parseMoney(string $value): ?float
{
    $value = trim($value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    $num = round((float)$value, 2);
    return $num < 0 ? null : $num;
}

function parseDiscountPercent(string $value): ?float
{
    $value = trim($value);
    if ($value === '') {
        return 0.0;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $num = round((float)$value, 2);
    if ($num < 0 || $num > 100) {
        return null;
    }
    return $num;
}

function normalizeCartItems(array $rawItems): array
{
    $normalized = [];
    foreach ($rawItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $productId = (int)($item['id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) {
            continue;
        }
        if (!isset($normalized[$productId])) {
            $normalized[$productId] = 0;
        }
        $normalized[$productId] += $quantity;
    }
    return $normalized;
}

function redirectWithAlert(string $type, string $message, array $extra = []): never
{
    $params = array_merge(['alert' => $type, 'message' => $message], $extra);
    header('Location: registry.php?' . http_build_query($params));
    exit;
}

function fetchReceipt(mysqli $conn, int $orderId): ?array
{
    if ($orderId <= 0) {
        return null;
    }

    $orderStmt = $conn->prepare(
        'SELECT id, receipt_no, customer_name, subtotal, discount_percent, discount_amount, tax_rate, tax_amount, total_amount, payment_amount, change_amount, created_at
         FROM sales_orders
         WHERE id = ?
         LIMIT 1'
    );
    $orderStmt->bind_param('i', $orderId);
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();
    $orderStmt->close();

    if (!$order) {
        return null;
    }

    $items = [];
    $itemStmt = $conn->prepare(
        'SELECT soi.product_name, soi.unit_price, soi.quantity, soi.line_total, COALESCE(p.color_specs, \'\') AS color_specs
         FROM sales_order_items soi
         LEFT JOIN products p ON p.id = soi.product_id
         WHERE soi.order_id = ?
         ORDER BY soi.id ASC'
    );
    $itemStmt->bind_param('i', $orderId);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    while ($row = $itemResult->fetch_assoc()) {
        $items[] = [
            'product_name' => (string)$row['product_name'],
            'unit_price' => (float)$row['unit_price'],
            'quantity' => (int)$row['quantity'],
            'line_total' => (float)$row['line_total'],
            'color_specs' => (string)$row['color_specs'],
        ];
    }
    $itemStmt->close();

    return [
        'order' => [
            'id' => (int)$order['id'],
            'receipt_no' => (string)$order['receipt_no'],
            'customer_name' => (string)$order['customer_name'],
            'subtotal' => (float)$order['subtotal'],
            'discount_percent' => (float)$order['discount_percent'],
            'discount_amount' => (float)$order['discount_amount'],
            'tax_rate' => (float)$order['tax_rate'],
            'tax_amount' => (float)$order['tax_amount'],
            'total_amount' => (float)$order['total_amount'],
            'payment_amount' => (float)$order['payment_amount'],
            'change_amount' => (float)$order['change_amount'],
            'created_at' => (string)$order['created_at'],
        ],
        'items' => $items,
    ];
}

function iconForCategory(string $category): string
{
    $value = strtolower($category);
    if (str_contains($value, 'gallon')) {
        return 'local_drink';
    }
    if (str_contains($value, 'bottle')) {
        return 'water_bottle';
    }
    if (str_contains($value, 'mineral') || str_contains($value, 'purified') || str_contains($value, 'water')) {
        return 'water_drop';
    }
    if (str_contains($value, 'accessor') || str_contains($value, 'container')) {
        return 'inventory_2';
    }
    return 'inventory';
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS inventory_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_category_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_name VARCHAR(150) NOT NULL,
        category_id INT NULL,
        color_specs VARCHAR(255) NOT NULL,
        brand VARCHAR(120) NOT NULL,
        stock_store INT NOT NULL DEFAULT 0,
        stock_stockroom INT NOT NULL DEFAULT 0,
        cost DECIMAL(10,2) NOT NULL DEFAULT 0,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        supplier VARCHAR(150) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_product_name (product_name),
        KEY idx_category_id (category_id),
        KEY idx_is_active (is_active),
        CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES inventory_categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$hasIsActiveColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'is_active'");
if ($hasIsActiveColumn && $hasIsActiveColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER supplier");
    $conn->query("ALTER TABLE products ADD KEY idx_is_active (is_active)");
}
if ($hasIsActiveColumn instanceof mysqli_result) {
    $hasIsActiveColumn->close();
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS sales_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_no VARCHAR(50) NOT NULL,
        customer_name VARCHAR(150) NOT NULL DEFAULT 'Walk-in Customer',
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        tax_rate DECIMAL(5,2) NOT NULL DEFAULT 12,
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
        KEY idx_product_id (product_id),
        CONSTRAINT fk_sales_item_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_sales_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if (isset($_GET['print']) && (string)$_GET['print'] === '1') {
    $orderId = (int)($_GET['order_id'] ?? 0);
    $receipt = fetchReceipt($conn, $orderId);
    if (!$receipt) {
        http_response_code(404);
        echo '<h2>Receipt not found.</h2>';
        exit;
    }
    $order = $receipt['order'];
    $items = $receipt['items'];
    $receiptDate = $order['created_at'];
    try {
        $dt = new DateTime((string)$order['created_at']);
        // Adjust to local timezone if needed; default to system timezone
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $receiptDate = $dt->format('F j, Y g:i A');
    } catch (Throwable $e) {
        // keep original created_at on parse failure
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Receipt <?= h($order['receipt_no']) ?></title>
  <style>
    /* POS thermal receipt setup: 80mm receipt width; keep centered on any sheet */
    @page { size: auto; margin: 0; }
    @media print {
      html, body {
        width: 100% !important;
        min-height: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: visible;
        background: #ffffff !important;
        -webkit-print-color-adjust: exact;
        display: flex;
        justify-content: center;
        align-items: flex-start;
      }
      body > :not(.wrap) { display: none !important; }
      .wrap {
        box-shadow: none !important;
        border: none !important;
        margin: 0 auto;
      }
    }
    :root {
      --ink: #0f172a;
      --muted: #475569;
      --line: #cbd5e1;
    }
    body {
      font-family: "Arial", sans-serif;
      margin: 0;
      color: var(--ink);
      background: #f6f7fb;
      min-height: 100vh;
      display: flex;
      align-items: flex-start;
      justify-content: center;
      padding: 18mm 0;
    }
    .wrap {
      position: relative;
      width: 72mm;
      margin: 0 auto;
      padding: 8mm 6mm 10mm;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      box-shadow: 0 6px 22px rgba(15, 23, 42, 0.12);
    }
    .wrap::before,
    .wrap::after {
      content: "";
      position: absolute;
      left: 0;
      right: 0;
      height: 6px;
      background: repeating-linear-gradient(
        90deg,
        transparent 0,
        transparent 10px,
        #e2e8f0 10px,
        #e2e8f0 18px
      );
    }
    .wrap::before { top: 0; }
    .wrap::after { bottom: 0; }
    h1, h2, p { margin: 0; }
    .center { text-align: center; }
    .mt-6 { margin-top: 6px; }
    .mt-10 { margin-top: 10px; }
    .mt-16 { margin-top: 16px; }
    .divider {
      margin: 10px 0;
      height: 1px;
      border-top: 1px dashed var(--line);
    }
    .row {
      display: flex;
      justify-content: space-between;
      gap: 8px;
      font-size: 13px;
      align-items: baseline;
    }
    .small { font-size: 12px; color: var(--muted); }
    .label { letter-spacing: 0.02em; text-transform: uppercase; font-size: 11px; color: var(--muted); }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 8px;
      font-size: 13px;
    }
    th, td {
      padding: 6px 2px;
      border-bottom: 1px dashed #e2e8f0;
      text-align: left;
    }
    th:last-child, td:last-child { text-align: right; }
    .bold { font-weight: 700; }
    .totals .row { font-size: 14px; }
    .totals .row strong { font-size: 15px; }
    .barcode {
      font-family: "Courier New", monospace;
      letter-spacing: 3px;
      font-size: 18px;
      color: var(--ink);
      text-align: center;
      margin-top: 12px;
    }
  </style>
</head>
<body onload="window.print()">
  <div class="wrap">
    <div class="center">
      <h1 style="letter-spacing: 0.04em;">JD Water Refilling Supplies Store</h1>
      <p class="label mt-6">Cash Receipt</p>
      <div class="divider"></div>
      <p class="small">Receipt #: <?= h($order['receipt_no']) ?></p>
      <p class="small">Date: <?= h($receiptDate) ?></p>
      <p class="small">Customer: <?= h($order['customer_name']) ?></p>
    </div>

    <div class="divider"></div>
    <div class="label center">Items</div>

    <table>
      <thead>
        <tr>
          <th>Description</th>
          <th>Price</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td>
              <?= h($item['product_name']) ?> x <?= (int)$item['quantity'] ?><br>
              <?php if (trim((string)$item['color_specs']) !== ''): ?><span class="small">Specs: <?= h((string)$item['color_specs']) ?></span><br><?php endif; ?>
              <span class="small">@ <?= peso((float)$item['unit_price']) ?></span>
            </td>
            <td><?= peso((float)$item['line_total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="divider"></div>
    <div class="totals">
      <div class="row"><span>Subtotal</span><span><?= peso((float)$order['subtotal']) ?></span></div>
      <div class="row mt-6"><span>Discount (<?= number_format((float)$order['discount_percent'], 2) ?>%)</span><span>-<?= peso((float)$order['discount_amount']) ?></span></div>
      <div class="row mt-10 bold"><span>Total</span><span><?= peso((float)$order['total_amount']) ?></span></div>
      <div class="row mt-10"><span>Cash</span><span><?= peso((float)$order['payment_amount']) ?></span></div>
      <div class="row mt-6"><span>Change</span><span><?= peso((float)$order['change_amount']) ?></span></div>
    </div>

    <div class="divider"></div>
    <p class="center small">Thank you for your purchase!</p>
    <p class="center small mt-6">Please keep this receipt.</p>
    <div class="barcode mt-10"><?= h(str_replace('-', '', $order['receipt_no'])) ?></div>
  </div>
</body>
</html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action !== 'process_order') {
        redirectWithAlert('error', 'Invalid transaction request.');
    }

    $customerNameInput = trim((string)($_POST['customer_name'] ?? ''));
    $customerName = $customerNameInput !== '' ? $customerNameInput : 'Walk-in Customer';
    if (mb_strlen($customerName) > 150) {
        redirectWithAlert('error', 'Customer name must be 150 characters or less.');
    }

    $discountPercent = parseDiscountPercent((string)($_POST['discount_percent'] ?? '0'));
    if ($discountPercent === null) {
        redirectWithAlert('error', 'Discount must be a valid value from 0 to 100.');
    }

    $paymentAmount = parseMoney((string)($_POST['payment_amount'] ?? ''));
    if ($paymentAmount === null) {
        redirectWithAlert('error', 'Payment amount must be a valid non-negative number.');
    }
    $isPending = isset($_POST['is_pending']) && (string)$_POST['is_pending'] === '1';

    $cartJson = trim((string)($_POST['cart_json'] ?? ''));
    if ($cartJson === '') {
        redirectWithAlert('error', 'Cart is empty.');
    }

    try {
        $decodedCart = json_decode($cartJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        redirectWithAlert('error', 'Invalid cart payload.');
    }
    if (!is_array($decodedCart)) {
        redirectWithAlert('error', 'Invalid cart format.');
    }

    $cartItems = normalizeCartItems($decodedCart);
    if ($cartItems === []) {
        redirectWithAlert('error', 'Add at least one product before checkout.');
    }

    $startedTransaction = false;
    try {
        $conn->begin_transaction();
        $startedTransaction = true;

        $lockStmt = $conn->prepare(
            'SELECT id, product_name, price, stock_store, stock_stockroom
             FROM products
             WHERE id = ? AND is_active = 1
             LIMIT 1
             FOR UPDATE'
        );
        $insertOrderStmt = $conn->prepare(
            'INSERT INTO sales_orders
                (receipt_no, customer_name, subtotal, discount_percent, discount_amount, tax_rate, tax_amount, total_amount, payment_amount, change_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertItemStmt = $conn->prepare(
            'INSERT INTO sales_order_items
                (order_id, product_id, product_name, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $updateStockStmt = $conn->prepare(
            'UPDATE products
             SET stock_store = ?, stock_stockroom = ?
             WHERE id = ?'
        );

        $lineItems = [];
        $stockUpdates = [];
        $subtotal = 0.0;

        foreach ($cartItems as $productId => $quantity) {
            $lockStmt->bind_param('i', $productId);
            $lockStmt->execute();
            $row = $lockStmt->get_result()->fetch_assoc();
            if (!$row) {
                throw new RuntimeException("Product #{$productId} is no longer available.");
            }

            $productName = (string)$row['product_name'];
            $unitPrice = (float)$row['price'];
            $stockStore = (int)$row['stock_store'];
            $stockStockroom = (int)$row['stock_stockroom'];
            $totalStock = $stockStore + $stockStockroom;

            if ($quantity > $totalStock) {
                throw new RuntimeException("Insufficient stock for {$productName}. Available: {$totalStock}.");
            }

            $deductFromStore = min($stockStore, $quantity);
            $remaining = $quantity - $deductFromStore;
            $newStore = $stockStore - $deductFromStore;
            $newStockroom = $stockStockroom - $remaining;

            $lineTotal = round($unitPrice * $quantity, 2);
            $subtotal = round($subtotal + $lineTotal, 2);

            $lineItems[] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
            ];
            $stockUpdates[] = [
                'product_id' => $productId,
                'stock_store' => $newStore,
                'stock_stockroom' => $newStockroom,
            ];
        }

        $discountAmount = round($subtotal * ($discountPercent / 100), 2);
        $discountedSubtotal = max(0, round($subtotal - $discountAmount, 2));
        $taxRatePercent = 0.0;
        $taxAmount = 0.0;
        $totalAmount = $discountedSubtotal;

        if ($totalAmount <= 0) {
            throw new RuntimeException('Total amount must be greater than zero.');
        }
        if (!$isPending && $paymentAmount < $totalAmount) {
            throw new RuntimeException('Payment amount is not enough for this transaction.');
        }

        $changeAmount = round(max(0, $paymentAmount - $totalAmount), 2);
        $receiptNo = 'OR-' . date('YmdHis') . '-' . random_int(100, 999);

        $insertOrderStmt->bind_param(
            'ssdddddddd',
            $receiptNo,
            $customerName,
            $subtotal,
            $discountPercent,
            $discountAmount,
            $taxRatePercent,
            $taxAmount,
            $totalAmount,
            $paymentAmount,
            $changeAmount
        );
        $insertOrderStmt->execute();
        $orderId = (int)$conn->insert_id;
        if ($orderId <= 0) {
            throw new RuntimeException('Unable to save transaction header.');
        }

        foreach ($lineItems as $item) {
            $productId = (int)$item['product_id'];
            $productName = (string)$item['product_name'];
            $unitPrice = (float)$item['unit_price'];
            $quantity = (int)$item['quantity'];
            $lineTotal = (float)$item['line_total'];
            $insertItemStmt->bind_param('iisdid', $orderId, $productId, $productName, $unitPrice, $quantity, $lineTotal);
            $insertItemStmt->execute();
        }

        foreach ($stockUpdates as $update) {
            $stockStore = (int)$update['stock_store'];
            $stockStockroom = (int)$update['stock_stockroom'];
            $productId = (int)$update['product_id'];
            $updateStockStmt->bind_param('iii', $stockStore, $stockStockroom, $productId);
            $updateStockStmt->execute();
        }

        $lockStmt->close();
        $insertOrderStmt->close();
        $insertItemStmt->close();
        $updateStockStmt->close();

        $conn->commit();
        $startedTransaction = false;

        redirectWithAlert(
            'success',
            "Transaction completed. Receipt {$receiptNo} is ready.",
            ['print_order' => (string)$orderId]
        );
    } catch (Throwable $e) {
        if ($startedTransaction) {
            $conn->rollback();
        }
        redirectWithAlert('error', 'Transaction failed: ' . $e->getMessage());
    }
}

$alertType = trim((string)($_GET['alert'] ?? ''));
$alertMessage = trim((string)($_GET['message'] ?? ''));
if (!in_array($alertType, ['success', 'error'], true)) {
    $alertType = '';
    $alertMessage = '';
}

$initialSearch = trim((string)($_GET['q'] ?? ''));
$initialCategory = trim((string)($_GET['category'] ?? ''));
if ($initialCategory !== '' && !preg_match('/^\d+$/', $initialCategory)) {
    $initialCategory = '';
}
$initialStock = trim((string)($_GET['stock'] ?? ''));
if (!in_array($initialStock, ['', 'in', 'low', 'out'], true)) {
    $initialStock = '';
}

$printOrderId = (int)($_GET['print_order'] ?? 0);
$printReceipt = $printOrderId > 0 ? fetchReceipt($conn, $printOrderId) : null;
if (!$printReceipt) {
    $printOrderId = 0;
}

$categories = [];
$catResult = $conn->query('SELECT id, name FROM inventory_categories ORDER BY name ASC');
while ($catRow = $catResult->fetch_assoc()) {
    $categories[] = [
        'id' => (int)$catRow['id'],
        'name' => (string)$catRow['name'],
    ];
}

$products = [];
$productsForJs = [];
$hasUncategorized = false;
$productResult = $conn->query(
    'SELECT p.id, p.product_name, p.color_specs, p.brand, p.price, p.stock_store, p.stock_stockroom, p.category_id, c.name AS category_name
     FROM products p
     LEFT JOIN inventory_categories c ON c.id = p.category_id
     WHERE p.is_active = 1
     ORDER BY p.product_name ASC, p.id ASC'
);
while ($row = $productResult->fetch_assoc()) {
    $categoryId = (int)($row['category_id'] ?? 0);
    $categoryName = trim((string)($row['category_name'] ?? ''));
    if ($categoryName === '') {
        $categoryId = 0;
        $categoryName = 'Uncategorized';
        $hasUncategorized = true;
    }

    $stockStore = (int)$row['stock_store'];
    $stockStockroom = (int)$row['stock_stockroom'];
    $totalStock = $stockStore + $stockStockroom;
    $stockStatus = 'in';
    if ($totalStock <= 0) {
        $stockStatus = 'out';
    } elseif ($totalStock <= 5) {
        $stockStatus = 'low';
    }

    $product = [
        'id' => (int)$row['id'],
        'product_name' => (string)$row['product_name'],
        'color_specs' => (string)$row['color_specs'],
        'brand' => (string)$row['brand'],
        'price' => (float)$row['price'],
        'category_id' => $categoryId,
        'category_name' => $categoryName,
        'stock_store' => $stockStore,
        'stock_stockroom' => $stockStockroom,
        'total_stock' => $totalStock,
        'stock_status' => $stockStatus,
    ];

    $products[] = $product;
    $productsForJs[] = [
        'id' => $product['id'],
        'name' => $product['product_name'],
        'price' => $product['price'],
        'category_id' => $product['category_id'],
        'category_name' => $product['category_name'],
        'brand' => $product['brand'],
        'specs' => $product['color_specs'],
        'stock_store' => $product['stock_store'],
        'stock_stockroom' => $product['stock_stockroom'],
        'total_stock' => $product['total_stock'],
        'stock_status' => $product['stock_status'],
    ];
}

if ($hasUncategorized) {
    $alreadyIncluded = false;
    foreach ($categories as $category) {
        if ((int)$category['id'] === 0) {
            $alreadyIncluded = true;
            break;
        }
    }
    if (!$alreadyIncluded) {
        $categories[] = ['id' => 0, 'name' => 'Uncategorized'];
    }
}

$totalProducts = count($products);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title> POS Sales</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0,1" />
  <style>
    .product-card { transition: all .2s ease; }
    .product-card:hover { transform: translateY(-2px); box-shadow: 0 12px 20px -8px rgba(0, 148, 195, .2); }
    .product-card.is-out { opacity: .55; }
    .qty-btn { transition: all .15s ease; }
    .qty-btn:hover { background-color: #0f6f94; color: #fff; transform: scale(1.05); }
    .checkout-btn { transition: all .2s ease; }
    .checkout-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(15, 111, 148, .4); }
    .category-btn.active { background: #0f6f94; color: #fff; border-color: #0f6f94; }
    .cart-item h4 { word-break: break-word; }
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #e6f4fa; border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #0f6f94; border-radius: 10px; }
    .stock-in { color: #047857; background: #d1fae5; }
    .stock-low { color: #b45309; background: #fef3c7; }
    .stock-out { color: #b91c1c; background: #fee2e2; }
    .product-scroll { max-height: 72vh; overflow-y: auto; }
    .cart-scroll { max-height: 86vh; overflow-y: auto; padding-right: 6px; }
    .cart-panel { max-height: 98vh; display: flex; flex-direction: column; }
    @media (max-width: 1024px) {
      .product-scroll, .cart-scroll, .cart-panel { max-height: none; }
    }
    .spec-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 10px;
      border-radius: 14px;
      background: #e0f2fe;
      color: #0f6f94;
      font-weight: 600;
      font-size: 12px;
      letter-spacing: .01em;
    }
  </style>
</head>
<body class="bg-[#e6f4fa] font-sans antialiased text-[#043b4a] min-h-screen flex">

  <?php include '../includes/sidebar.php'; ?>

  <div class="flex-1 flex flex-col w-full min-w-0 min-h-screen overflow-x-hidden">
    <header class="bg-white/90 backdrop-blur-md border-b border-white/40 px-3 sm:px-4 lg:px-6 py-2 sm:py-3 flex items-center justify-between sticky top-0 z-10 shadow-sm">
      <div class="flex items-center gap-3 min-w-0 flex-1 lg:ml-0 ml-12">
        <h1 class="text-sm sm:text-base lg:text-lg xl:text-xl font-light text-[#05445E] truncate">
          <span class="font-semibold">Point of Sale</span> 
        </h1>
        <span class="hidden md:inline-block bg-[#0f6f94]/10 text-[#0f6f94] text-xs px-2 py-1 rounded-full">POS Active</span>
      </div>
      <div class="flex items-center gap-4 flex-shrink-0">
        <div class="hidden lg:block text-sm text-[#2c7da0]"><?= date('l, F j, Y') ?></div>
        <div class="flex items-center gap-2">
          <img src="../assets/images/logo.jpg" alt="JD Logo" class="w-8 h-8 rounded-full object-contain shadow-md">
          <span class="hidden sm:block text-sm font-medium text-[#05445E]">Admin</span>
        </div>
      </div>
    </header>

      <main class="flex-1 min-h-0 flex flex-col lg:flex-row items-stretch overflow-visible lg:overflow-hidden p-4 sm:p-6 gap-4 lg:gap-6 lg:min-h-[calc(100vh-140px)] lg:h-[calc(100vh-140px)]">
      <section class="lg:w-3/5 lg:flex-1 min-h-0 h-full flex flex-col bg-white/90 backdrop-blur-sm rounded-2xl shadow-lg border border-white/40 overflow-hidden">
        <div class="p-4 border-b border-[#d0e7f2] bg-gradient-to-r from-[#f8fafc] to-white">
          <h2 class="text-lg font-medium text-[#05445E] flex items-center gap-2 mb-3">
            <span class="material-symbols-outlined text-[#0f6f94]">inventory</span>
            Select Products
          </h2>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="relative md:col-span-2">
              <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#2c7da0] text-lg">search</span>
              <input type="text" id="productSearch" value="<?= h($initialSearch) ?>" placeholder="Search by product, brand, specs, category..." class="w-full pl-10 pr-4 py-3 border border-[#b7d9e9] rounded-xl focus:outline-none focus:border-[#0f6f94] focus:ring-1 focus:ring-[#0f6f94]/20 transition">
            </div>
            <select id="stockFilter" class="w-full px-3 py-3 border border-[#b7d9e9] rounded-xl bg-white focus:outline-none focus:border-[#0f6f94]">
              <option value="" <?= $initialStock === '' ? 'selected' : '' ?>>All Stock Levels</option>
              <option value="in" <?= $initialStock === 'in' ? 'selected' : '' ?>>In Stock</option>
              <option value="low" <?= $initialStock === 'low' ? 'selected' : '' ?>>Low Stock</option>
              <option value="out" <?= $initialStock === 'out' ? 'selected' : '' ?>>Out of Stock</option>
            </select>
          </div>
          <div class="mt-3 flex flex-wrap gap-2" id="categoryFilters">
            <button type="button" class="category-btn active px-4 py-2 rounded-lg border border-[#0f6f94] text-sm font-medium transition" data-category="">All Products</button>
            <?php foreach ($categories as $category): ?>
              <button type="button" class="category-btn px-4 py-2 rounded-lg border border-[#b7d9e9] text-sm font-medium transition hover:border-[#0f6f94]" data-category="<?= (int)$category['id'] ?>"><?= h((string)$category['name']) ?></button>
            <?php endforeach; ?>
          </div>
          <p id="productResultCount" class="text-xs text-[#2c7da0] mt-3">Showing <?= number_format($totalProducts) ?> product<?= $totalProducts === 1 ? '' : 's' ?></p>
        </div>

        <div class="flex-1 overflow-y-auto p-4 custom-scrollbar product-scroll" id="productGrid">
          <?php if ($products === []): ?>
            <div class="h-full flex items-center justify-center text-center text-[#2c7da0]">
              <div>
                <span class="material-symbols-outlined text-5xl">inventory_2</span>
                <p class="mt-2 font-medium text-[#05445E]">No products found in inventory.</p>
                <p class="text-sm mt-1">Add products in Inventory first.</p>
              </div>
            </div>
          <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4" id="productCardList">
              <?php foreach ($products as $product): ?>
                <?php
                $status = (string)$product['stock_status'];
                $statusLabel = $status === 'out' ? 'Out of stock' : ($status === 'low' ? 'Low stock' : 'In stock');
                $statusClass = $status === 'out' ? 'stock-out' : ($status === 'low' ? 'stock-low' : 'stock-in');
                $isOut = $status === 'out';
                ?>
                <article
                  class="product-card bg-white rounded-xl p-4 border border-[#d0e7f2] shadow-sm cursor-pointer <?= $isOut ? 'is-out' : '' ?>"
                  data-product-id="<?= (int)$product['id'] ?>"
                  data-category-id="<?= (int)$product['category_id'] ?>"
                  data-stock-status="<?= h($status) ?>"
                  data-search="<?= h(strtolower($product['product_name'] . ' ' . $product['brand'] . ' ' . $product['color_specs'] . ' ' . $product['category_name'])) ?>"
                >
                  <div class="flex items-start justify-between mb-3">
                    <div class="w-12 h-12 rounded-full bg-[#e0f0f9] flex items-center justify-center">
                      <span class="material-symbols-outlined text-3xl text-[#0f6f94]"><?= h(iconForCategory((string)$product['category_name'])) ?></span>
                    </div>
                    <span class="text-xs px-2 py-1 rounded-full <?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
                  </div>
                  <div class="mt-1 mb-2">
                    <span class="spec-pill" title="Color / Specs">
                      <?= h((string)$product['color_specs']) ?>
                    </span>
                  </div>
                  <h3 class="font-semibold text-[#05445E]"><?= h((string)$product['product_name']) ?></h3>
                  <p class="text-sm text-[#2c7da0] mb-1">Brand: <?= h((string)$product['brand']) ?></p>
                  <p class="text-sm text-[#2c7da0] mb-1">Stock: <?= (int)$product['total_stock'] ?> pcs</p>
                  <p class="text-xs text-[#6b7280] mb-2">Store <?= (int)$product['stock_store'] ?> · Stockroom <?= (int)$product['stock_stockroom'] ?></p>
                  <div class="flex items-center justify-between mt-2">
                    <span class="text-xl font-bold text-[#05445E]"><?= peso((float)$product['price']) ?></span>
                    <button type="button" class="add-to-cart-btn qty-btn w-8 h-8 rounded-full bg-[#0f6f94] text-white flex items-center justify-center <?= $isOut ? 'opacity-50 cursor-not-allowed' : '' ?>" data-product-id="<?= (int)$product['id'] ?>" <?= $isOut ? 'disabled' : '' ?>>
                      <span class="material-symbols-outlined text-lg">add</span>
                    </button>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
            <div id="noMatchState" class="hidden h-full items-center justify-center text-center text-[#2c7da0]">
              <div>
                <span class="material-symbols-outlined text-5xl">search_off</span>
                <p class="mt-2 font-medium text-[#05445E]">No matching products.</p>
                <p class="text-sm mt-1">Try another search term or filter.</p>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </section>
     <section class="lg:w-2/5 lg:flex-none min-h-[850px] h-full flex flex-col bg-white/90 backdrop-blur-sm rounded-2xl shadow-lg border border-white/40 overflow-hidden cart-panel">
        <div class="p-4 border-b border-[#d0e7f2] bg-gradient-to-r from-[#f8fafc] to-white">
          <h2 class="text-lg font-medium text-[#05445E] flex items-center gap-2">
            <span class="material-symbols-outlined text-[#0f6f94]">shopping_cart</span>
            Current Order
          </h2>
          <?php if ($printOrderId > 0 && $printReceipt): ?>
            <div id="printReceiptBanner" class="mt-3 p-3 rounded-lg border border-[#cde7f3] bg-[#f4fbff] flex items-center justify-between gap-2">
              <div class="text-xs">
                <p class="text-[#05445E] font-semibold">Receipt <?= h($printReceipt['order']['receipt_no']) ?></p>
                <p class="text-[#2c7da0]">Customer: <?= h($printReceipt['order']['customer_name']) ?></p>
              </div>
              <a href="registry.php?print=1&amp;order_id=<?= (int)$printOrderId ?>" onclick="event.preventDefault(); printReceiptInPlace(this.href);" class="px-3 py-2 rounded-lg bg-[#0f6f94] text-white text-xs font-medium hover:bg-[#0a4f6b]">Print Receipt</a>
            </div>
          <?php endif; ?>
        </div>

        <div class="p-4 border-b border-[#d0e7f2] bg-[#f8fafc] space-y-3">
          <div>
            <label for="customerName" class="text-sm font-medium text-[#05445E] block mb-1">Customer Name</label>
            <input type="text" id="customerName" name="customer_name" form="checkoutForm" maxlength="150" placeholder="Walk-in Customer" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg bg-white focus:outline-none focus:border-[#0f6f94] text-sm">
          </div>
          <div>
            <label for="discountPercent" class="text-sm font-medium text-[#05445E] block mb-1">Discount (%)</label>
            <input type="number" id="discountPercent" min="0" max="100" step="0.01" value="0" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg bg-white focus:outline-none focus:border-[#0f6f94] text-sm">
          </div>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto p-4 custom-scrollbar cart-scroll" id="cartItems">
          <div class="text-center text-[#2c7da0] py-8">
            <span class="material-symbols-outlined text-5xl mb-2">shopping_cart</span>
            <p>No items in cart</p>
            <p class="text-sm mt-1">Select products from the left panel</p>
          </div>
        </div>

        <div class="p-4 border-t border-[#d0e7f2] bg-[#f8fafc] space-y-3">
          <div class="flex justify-between text-sm"><span class="text-[#2c7da0]">Subtotal:</span><span class="font-medium text-[#05445E]" id="subtotal">PHP 0.00</span></div>
          <div class="flex justify-between text-sm"><span class="text-[#2c7da0]">Discount:</span><span class="font-medium text-[#05445E]" id="discountAmount">PHP 0.00</span></div>
          <div class="flex justify-between text-sm"><span class="text-[#2c7da0]">Total Items:</span><span class="font-medium text-[#05445E]" id="totalItems">0</span></div>
          <div class="flex justify-between text-base font-semibold pt-2 border-t border-[#d0e7f2]"><span class="text-[#05445E]">Total:</span><span class="text-[#0f6f94] text-xl" id="totalAmount">PHP 0.00</span></div>

          <div class="pt-2">
            <div class="flex items-center justify-between mb-2">
              <label for="paymentAmount" class="text-sm font-medium text-[#05445E]">Payment Amount</label>
              <button type="button" id="pendingBtn" class="text-xs px-3 py-1 rounded-lg border border-[#b7d9e9] text-[#0f6f94] bg-white hover:bg-[#e2f3fb] transition disabled:opacity-50 disabled:cursor-not-allowed">Pending</button>
            </div>
            <input type="number" id="paymentAmount" value="0" min="0" step="0.01" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg focus:outline-none focus:border-[#0f6f94]">
            <div class="flex justify-between items-center mt-2 p-3 bg-white rounded-lg border border-[#d0e7f2]">
              <span class="text-sm text-[#2c7da0]">Change:</span>
              <span class="text-xl font-bold text-[#05445E]" id="changeAmount">PHP 0.00</span>
            </div>
          </div>

          <form method="post" id="checkoutForm" class="hidden">
            <input type="hidden" name="action" value="process_order">
            <input type="hidden" name="discount_percent" id="checkoutDiscountPercent">
            <input type="hidden" name="payment_amount" id="checkoutPaymentAmount">
            <input type="hidden" name="cart_json" id="checkoutCartJson">
            <input type="hidden" name="is_pending" id="checkoutIsPending" value="0">
          </form>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <button type="button" id="clearCartBtn" class="w-full border border-[#b7d9e9] text-[#05445E] py-3 rounded-xl font-semibold hover:bg-[#e9f5fb]">Clear Cart</button>
            <button type="button" id="checkoutBtn" class="checkout-btn w-full bg-gradient-to-r from-[#0f6f94] to-[#05445E] text-white py-3 rounded-xl font-semibold flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
              <span class="material-symbols-outlined">payment</span>
              Complete Transaction
            </button>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script>
    const productCatalog = <?= json_encode($productsForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]' ?>;
    const productMap = new Map(productCatalog.map((item) => [Number(item.id), item]));
    const cart = [];

    const productSearchInput = document.getElementById('productSearch');
    const stockFilter = document.getElementById('stockFilter');
    const categoryButtons = Array.from(document.querySelectorAll('.category-btn'));
    const productCards = Array.from(document.querySelectorAll('.product-card'));
    const productResultCount = document.getElementById('productResultCount');
    const noMatchState = document.getElementById('noMatchState');
    const productCardList = document.getElementById('productCardList');

    const customerNameInput = document.getElementById('customerName');
    const discountInput = document.getElementById('discountPercent');
    const paymentInput = document.getElementById('paymentAmount');
    const cartItemsContainer = document.getElementById('cartItems');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const pendingBtn = document.getElementById('pendingBtn');
    const clearCartBtn = document.getElementById('clearCartBtn');

    const subtotalEl = document.getElementById('subtotal');
    const discountAmountEl = document.getElementById('discountAmount');
    const totalItemsEl = document.getElementById('totalItems');
    const totalAmountEl = document.getElementById('totalAmount');
    const changeAmountEl = document.getElementById('changeAmount');

    const checkoutForm = document.getElementById('checkoutForm');
    const checkoutDiscountPercent = document.getElementById('checkoutDiscountPercent');
    const checkoutPaymentAmount = document.getElementById('checkoutPaymentAmount');
    const checkoutCartJson = document.getElementById('checkoutCartJson');
    const checkoutIsPending = document.getElementById('checkoutIsPending');

    let activeCategory = <?= json_encode($initialCategory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || '';

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

    function clearPreviousReceiptState() {
      const receiptBanner = document.getElementById('printReceiptBanner');
      if (receiptBanner) {
        receiptBanner.remove();
      }

      if (window.history?.replaceState) {
        const url = new URL(window.location.href);
        let changed = false;
        if (url.searchParams.has('print_order')) {
          url.searchParams.delete('print_order');
          changed = true;
        }
        if (changed) {
          const cleanQuery = url.searchParams.toString();
          const cleanUrl = `${url.pathname}${cleanQuery ? `?${cleanQuery}` : ''}${url.hash}`;
          window.history.replaceState({}, document.title, cleanUrl);
        }
      }
    }

    function formatMoney(value) {
      const amount = Number.isFinite(value) ? value : 0;
      return `PHP ${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }

    function escapeHtml(value) {
      return String(value).replace(/[&<>"']/g, (char) => {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return map[char] || char;
      });
    }

    function toast(icon, title, text) {
      if (window.Swal) {
        Swal.fire({ icon, title, text, confirmButtonColor: '#0f6f94' });
      } else {
        alert(text || title);
      }
    }

    function getDiscountPercent() {
      let value = Number.parseFloat(discountInput.value);
      if (!Number.isFinite(value) || value < 0) value = 0;
      if (value > 100) value = 100;
      return Number(value.toFixed(2));
    }

    function getPaymentAmount() {
      const value = Number.parseFloat(paymentInput.value);
      return Number.isFinite(value) && value >= 0 ? Number(value.toFixed(2)) : 0;
    }

    function calculateTotals() {
      let subtotal = 0;
      let totalItems = 0;
      for (const item of cart) {
        subtotal += item.price * item.quantity;
        totalItems += item.quantity;
      }
      subtotal = Number(subtotal.toFixed(2));
      const discountPercent = getDiscountPercent();
      const discountAmount = Number((subtotal * (discountPercent / 100)).toFixed(2));
      const total = Number(Math.max(0, subtotal - discountAmount).toFixed(2));
      return { subtotal, discountPercent, discountAmount, total, totalItems };
    }

    function findCartItem(productId) {
      return cart.find((item) => item.id === productId);
    }

    function addToCart(productId) {
      const product = productMap.get(productId);
      if (!product) {
        toast('error', 'Missing product', 'Selected product was not found.');
        return;
      }

      const existing = findCartItem(productId);
      const currentQty = existing ? existing.quantity : 0;
      if (currentQty >= Number(product.total_stock || 0)) {
        toast('warning', 'Stock limit reached', `${product.name} has no more available stock.`);
        return;
      }

      if (existing) {
        existing.quantity += 1;
      } else {
        cart.push({
          id: productId,
          name: product.name,
          specs: String(product.specs || ''),
          price: Number(product.price || 0),
          quantity: 1,
          maxStock: Number(product.total_stock || 0)
        });
      }
      updateCartDisplay();
    }

    function updateQuantity(productId, delta) {
      const item = findCartItem(productId);
      if (!item) return;

      const nextQty = item.quantity + delta;
      if (nextQty <= 0) {
        removeFromCart(productId);
        return;
      }
      if (nextQty > item.maxStock) {
        toast('warning', 'Stock limit reached', `${item.name} only has ${item.maxStock} available.`);
        return;
      }
      item.quantity = nextQty;
      updateCartDisplay();
    }

    function removeFromCart(productId) {
      const index = cart.findIndex((item) => item.id === productId);
      if (index >= 0) {
        cart.splice(index, 1);
      }
      updateCartDisplay();
    }

    function calculateChange() {
      const totals = calculateTotals();
      const payment = getPaymentAmount();
      const change = Number(Math.max(0, payment - totals.total).toFixed(2));
      changeAmountEl.textContent = formatMoney(change);
      checkoutBtn.disabled = totals.total <= 0 || payment < totals.total;
      if (pendingBtn) {
        pendingBtn.disabled = totals.total <= 0 || cart.length === 0;
      }
    }

    function updateCartDisplay() {
      if (cart.length === 0) {
        cartItemsContainer.innerHTML = `
          <div class="text-center text-[#2c7da0] py-8">
            <span class="material-symbols-outlined text-5xl mb-2">shopping_cart</span>
            <p>No items in cart</p>
            <p class="text-sm mt-1">Select products from the left panel</p>
          </div>
        `;
      } else {
        const html = cart.map((item) => `
          <div class="cart-item flex flex-wrap sm:flex-nowrap items-start sm:items-center gap-3 p-3 border-b border-[#d0e7f2] last:border-0">
            <div class="flex-1 min-w-[160px]">
              <h4 class="font-medium text-[#05445E]">${escapeHtml(item.name)}</h4>
              ${item.specs ? `<p class="text-xs text-[#0f6f94]">Specs: ${escapeHtml(item.specs)}</p>` : ''}
              <p class="text-sm text-[#2c7da0]">${formatMoney(item.price)} each</p>
              <p class="text-xs text-[#2c7da0]">Available: ${item.maxStock}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <button type="button" data-action="minus" data-id="${item.id}" class="qty-btn w-7 h-7 rounded-full border border-[#b7d9e9] flex items-center justify-center">
                <span class="material-symbols-outlined text-sm">remove</span>
              </button>
              <span class="w-8 text-center font-medium">${item.quantity}</span>
              <button type="button" data-action="plus" data-id="${item.id}" class="qty-btn w-7 h-7 rounded-full border border-[#b7d9e9] flex items-center justify-center">
                <span class="material-symbols-outlined text-sm">add</span>
              </button>
            </div>
            <div class="text-right min-w-[72px] sm:min-w-[86px] ml-auto sm:ml-0 shrink-0">
              <p class="font-semibold text-[#05445E]">${formatMoney(item.price * item.quantity)}</p>
            </div>
            <button type="button" data-action="delete" data-id="${item.id}" class="text-red-500 hover:text-red-700 p-1 shrink-0">
              <span class="material-symbols-outlined text-lg">delete</span>
            </button>
          </div>
        `).join('');
        cartItemsContainer.innerHTML = html;
      }

      const totals = calculateTotals();
      subtotalEl.textContent = formatMoney(totals.subtotal);
      discountAmountEl.textContent = formatMoney(totals.discountAmount);
      totalItemsEl.textContent = String(totals.totalItems);
      totalAmountEl.textContent = formatMoney(totals.total);

      calculateChange();
    }

    function completeTransaction(isPending = false) {
      const totals = calculateTotals();
      if (cart.length === 0) {
        toast('warning', 'Cart is empty', 'Add products before completing a transaction.');
        return;
      }

      const payment = getPaymentAmount();
      if (!isPending && payment < totals.total) {
        toast('warning', 'Insufficient payment', 'Payment amount is lower than total amount.');
        return;
      }

      customerNameInput.value = customerNameInput.value.trim() || 'Walk-in Customer';
      checkoutDiscountPercent.value = totals.discountPercent.toFixed(2);
      checkoutPaymentAmount.value = payment.toFixed(2);
      checkoutCartJson.value = JSON.stringify(cart.map((item) => ({ id: item.id, quantity: item.quantity })));
      if (checkoutIsPending) {
        checkoutIsPending.value = isPending ? '1' : '0';
      }
      checkoutForm.submit();
    }

    function applyProductFilters() {
      const q = (productSearchInput?.value || '').trim().toLowerCase();
      const terms = q === '' ? [] : q.split(/\s+/).filter(Boolean);
      const stock = stockFilter?.value || '';
      let visibleCount = 0;

      for (const card of productCards) {
        const categoryId = card.dataset.categoryId || '';
        const stockStatus = card.dataset.stockStatus || '';
        const searchText = card.dataset.search || '';

        const matchSearch = terms.length === 0 || terms.every((t) => searchText.includes(t));
        const matchCategory = activeCategory === '' || categoryId === activeCategory;
        const matchStock = stock === '' || stockStatus === stock;
        const visible = matchSearch && matchCategory && matchStock;
        card.classList.toggle('hidden', !visible);
        if (visible) visibleCount += 1;
      }

      if (productResultCount) {
        productResultCount.textContent = `Showing ${visibleCount.toLocaleString()} product${visibleCount === 1 ? '' : 's'}`;
      }
      if (noMatchState && productCardList) {
        const showEmpty = visibleCount === 0 && productCards.length > 0;
        noMatchState.classList.toggle('hidden', !showEmpty);
        noMatchState.classList.toggle('flex', showEmpty);
        productCardList.classList.toggle('hidden', showEmpty);
      }
    }

    document.querySelectorAll('.product-card').forEach((card) => {
      card.addEventListener('click', () => {
        addToCart(Number(card.dataset.productId || 0));
      });
    });
    document.querySelectorAll('.add-to-cart-btn').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.stopPropagation();
        addToCart(Number(btn.dataset.productId || 0));
      });
    });

    categoryButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        activeCategory = btn.dataset.category || '';
        categoryButtons.forEach((b) => b.classList.toggle('active', b === btn));
        applyProductFilters();
      });
      if ((btn.dataset.category || '') === activeCategory) {
        categoryButtons.forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
      }
    });

    productSearchInput?.addEventListener('input', applyProductFilters);
    stockFilter?.addEventListener('change', applyProductFilters);

    discountInput?.addEventListener('input', () => {
      const discount = getDiscountPercent();
      discountInput.value = discount.toFixed(2);
      updateCartDisplay();
    });
    paymentInput?.addEventListener('input', calculateChange);

    cartItemsContainer?.addEventListener('click', (event) => {
      const btn = event.target.closest('button[data-action]');
      if (!btn) return;
      const action = btn.dataset.action || '';
      const id = Number(btn.dataset.id || 0);
      if (id <= 0) return;
      if (action === 'minus') updateQuantity(id, -1);
      if (action === 'plus') updateQuantity(id, 1);
      if (action === 'delete') removeFromCart(id);
    });

    clearCartBtn?.addEventListener('click', () => {
      if (cart.length > 0) {
        cart.splice(0, cart.length);
        updateCartDisplay();
      }
      clearPreviousReceiptState();
    });
    checkoutBtn?.addEventListener('click', () => completeTransaction(false));
    pendingBtn?.addEventListener('click', () => completeTransaction(true));

    applyProductFilters();
    updateCartDisplay();

    const flashType = <?= json_encode($alertType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const flashMessage = <?= json_encode($alertMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    if (flashType && flashMessage) {
      toast(flashType, flashType === 'success' ? 'Success' : 'Failed', flashMessage);
      if (window.history?.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.delete('alert');
        url.searchParams.delete('message');
        const cleanQuery = url.searchParams.toString();
        const cleanUrl = `${url.pathname}${cleanQuery ? `?${cleanQuery}` : ''}${url.hash}`;
        window.history.replaceState({}, document.title, cleanUrl);
      }
    }
  </script>
</body>
</html>
