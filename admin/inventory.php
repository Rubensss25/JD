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
    return '&#8369;' . number_format($value, 2);
}

function stockStatus(int $totalStock): string
{
    if ($totalStock <= 0) {
        return 'out';
    }
    if ($totalStock <= 50) {
        return 'low';
    }
    return 'in';
}

function statusLabel(string $status): string
{
    return $status === 'out' ? 'Out of Stock' : ($status === 'low' ? 'Low Stock' : 'In Stock');
}

function statusBadgeClass(string $status): string
{
    return $status === 'out' ? 'stock-low' : ($status === 'low' ? 'stock-medium' : 'stock-high');
}

function barClass(string $status): string
{
    return $status === 'out' ? 'bg-[#dc2626]' : ($status === 'low' ? 'bg-[#d97706]' : 'bg-[#059669]');
}

function iconForCategory(string $category): string
{
    $value = strtolower($category);
    if (str_contains($value, 'water') || str_contains($value, 'gallon') || str_contains($value, 'faucet')) {
        return 'water_drop';
    }
    if (str_contains($value, 'filter')) {
        return 'filter_alt';
    }
    if (str_contains($value, 'pipe') || str_contains($value, 'fitting')) {
        return 'plumbing';
    }
    if (str_contains($value, 'pump') || str_contains($value, 'switch') || str_contains($value, 'head')) {
        return 'settings';
    }
    return 'inventory_2';
}

function parseNonNegativeInt(string $value): ?int
{
    $value = trim($value);
    return ($value !== '' && preg_match('/^\d+$/', $value)) ? (int)$value : null;
}

function parseNonNegativeMoney(string $value): ?float
{
    $value = trim($value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    $num = (float)$value;
    return $num < 0 ? null : round($num, 2);
}

function parseValueBasis(string $value): string
{
    return $value === 'price' ? 'price' : 'cost';
}

function buildPageUrl(string $search, int $category, string $stock, string $valueBasis, int $perPage, int $page): string
{
    return 'inventory.php?' . http_build_query([
        'q' => $search,
        'category' => $category,
        'stock' => $stock,
        'value_basis' => $valueBasis,
        'per_page' => $perPage,
        'page' => $page,
    ]);
}

function redirectWithAlert(string $type, string $message): never
{
    header('Location: inventory.php?' . http_build_query(['alert' => $type, 'message' => $message]));
    exit;
}

function resolveCategoryId(mysqli $conn, int $categoryId, string $newCategory): ?int
{
    $newCategory = trim($newCategory);
    if ($newCategory !== '') {
        $stmt = $conn->prepare(
            'INSERT INTO inventory_categories (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $stmt->bind_param('s', $newCategory);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        return $id > 0 ? $id : null;
    }

    if ($categoryId <= 0) {
        return null;
    }
    $stmt = $conn->prepare('SELECT id FROM inventory_categories WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
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
    "CREATE TABLE IF NOT EXISTS inventory_restock_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        added_store INT NOT NULL DEFAULT 0,
        added_stockroom INT NOT NULL DEFAULT 0,
        notes VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_restock_product_id (product_id),
        CONSTRAINT fk_restock_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'add_category') {
            $categoryName = trim((string)($_POST['category_name'] ?? ''));
            if ($categoryName === '') {
                redirectWithAlert('error', 'Category name is required.');
            }
            $stmt = $conn->prepare(
                'INSERT INTO inventory_categories (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $stmt->bind_param('s', $categoryName);
            $stmt->execute();
            $stmt->close();
            redirectWithAlert('success', 'Category saved successfully.');
        }

        if ($action === 'create' || $action === 'update') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $productName = trim((string)($_POST['product_name'] ?? ''));
            $categoryIdInput = (int)($_POST['category_id'] ?? 0);
            $newCategory = trim((string)($_POST['new_category'] ?? ''));
            $colorSpecs = trim((string)($_POST['color_specs'] ?? ''));
            $brand = trim((string)($_POST['brand'] ?? ''));
            $supplier = trim((string)($_POST['supplier'] ?? ''));
            $stockStore = parseNonNegativeInt((string)($_POST['stock_store'] ?? ''));
            $stockStockroom = parseNonNegativeInt((string)($_POST['stock_stockroom'] ?? ''));
            $cost = parseNonNegativeMoney((string)($_POST['cost'] ?? ''));
            $price = parseNonNegativeMoney((string)($_POST['price'] ?? ''));

            if ($productName === '' || $colorSpecs === '' || $brand === '' || $supplier === '') {
                redirectWithAlert('error', 'Please complete all required fields.');
            }
            if ($stockStore === null || $stockStockroom === null || $cost === null || $price === null) {
                redirectWithAlert('error', 'Stocks, cost, and price must be valid non-negative values.');
            }

            $resolvedCategoryId = resolveCategoryId($conn, $categoryIdInput, $newCategory);
            if ($resolvedCategoryId === null) {
                redirectWithAlert('error', 'Select a category or type a new one.');
            }

            if ($action === 'create') {
                $autoActive = ($stockStore + $stockStockroom) > 0 ? 1 : 0;
                $stmt = $conn->prepare(
                    'INSERT INTO products (product_name, category_id, color_specs, brand, stock_store, stock_stockroom, cost, price, supplier, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'sissiiddsi',
                    $productName,
                    $resolvedCategoryId,
                    $colorSpecs,
                    $brand,
                    $stockStore,
                    $stockStockroom,
                    $cost,
                    $price,
                    $supplier,
                    $autoActive
                );
                $stmt->execute();
                $stmt->close();
                redirectWithAlert('success', 'Product added successfully.');
            }

            if ($productId <= 0) {
                redirectWithAlert('error', 'Invalid product selected for update.');
            }
            $stmt = $conn->prepare(
                'UPDATE products
                 SET product_name = ?, category_id = ?, color_specs = ?, brand = ?, stock_store = ?, stock_stockroom = ?, cost = ?, price = ?, supplier = ?,
                     is_active = CASE WHEN (? + ?) <= 0 THEN 0 ELSE is_active END
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'sissiiddsiii',
                $productName,
                $resolvedCategoryId,
                $colorSpecs,
                $brand,
                $stockStore,
                $stockStockroom,
                $cost,
                $price,
                $supplier,
                $stockStore,
                $stockStockroom,
                $productId
            );
            $stmt->execute();
            $stmt->close();
            redirectWithAlert('success', 'Product updated successfully.');
        }

        if ($action === 'set_inactive' || $action === 'set_active') {
            $productId = (int)($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                redirectWithAlert('error', 'Invalid product selected.');
            }

            $targetActive = $action === 'set_active' ? 1 : 0;
            $checkStmt = $conn->prepare(
                'SELECT is_active, stock_store, stock_stockroom
                 FROM products
                 WHERE id = ?
                 LIMIT 1'
            );
            $checkStmt->bind_param('i', $productId);
            $checkStmt->execute();
            $row = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if (!$row) {
                redirectWithAlert('error', 'Product not found.');
            }

            $currentActive = (int)$row['is_active'];
            $totalStock = (int)$row['stock_store'] + (int)$row['stock_stockroom'];
            if ($targetActive === 1 && $totalStock <= 0) {
                redirectWithAlert('error', 'Cannot mark as available while stock is 0.');
            }
            if ($currentActive === $targetActive) {
                $alreadyMessage = $targetActive === 1
                    ? 'Product is already marked as available.'
                    : 'Product is already marked as not available.';
                redirectWithAlert('error', $alreadyMessage);
            }

            $stmt = $conn->prepare('UPDATE products SET is_active = ? WHERE id = ?');
            $stmt->bind_param('ii', $targetActive, $productId);
            $stmt->execute();
            $stmt->close();

            $successMessage = $targetActive === 1
                ? 'Product marked as available successfully.'
                : 'Product marked as not available successfully.';
            redirectWithAlert('success', $successMessage);
        }

        if ($action === 'restock') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $addStore = parseNonNegativeInt((string)($_POST['restock_store'] ?? ''));
            $addStockroom = parseNonNegativeInt((string)($_POST['restock_stockroom'] ?? ''));
            $notes = substr(trim((string)($_POST['restock_notes'] ?? '')), 0, 255);

            if ($productId <= 0) {
                redirectWithAlert('error', 'Invalid product selected for restocking.');
            }
            if ($addStore === null || $addStockroom === null) {
                redirectWithAlert('error', 'Restock quantities must be valid non-negative whole numbers.');
            }
            if ($addStore === 0 && $addStockroom === 0) {
                redirectWithAlert('error', 'Enter at least one quantity to restock.');
            }

            $conn->begin_transaction();
            try {
                $update = $conn->prepare(
                    'UPDATE products
                     SET stock_store = stock_store + ?, stock_stockroom = stock_stockroom + ?
                     WHERE id = ?'
                );
                $update->bind_param('iii', $addStore, $addStockroom, $productId);
                $update->execute();
                $affected = $update->affected_rows;
                $update->close();

                if ($affected < 1) {
                    throw new RuntimeException('Product not found.');
                }

                $insertLog = $conn->prepare(
                    'INSERT INTO inventory_restock_logs (product_id, added_store, added_stockroom, notes)
                     VALUES (?, ?, ?, ?)'
                );
                $insertLog->bind_param('iiis', $productId, $addStore, $addStockroom, $notes);
                $insertLog->execute();
                $insertLog->close();

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }

            redirectWithAlert('success', 'Stocks were restocked successfully.');
        }

        redirectWithAlert('error', 'Invalid request.');
    } catch (Throwable $e) {
        redirectWithAlert('error', 'Operation failed: ' . $e->getMessage());
    }
}

$alertType = trim((string)($_GET['alert'] ?? ''));
$alertMessage = trim((string)($_GET['message'] ?? ''));
if (!in_array($alertType, ['success', 'error'], true)) {
    $alertType = '';
    $alertMessage = '';
}

$search = trim((string)($_GET['q'] ?? ''));
$categoryFilter = (int)($_GET['category'] ?? 0);
$stockFilter = trim((string)($_GET['stock'] ?? ''));
$valueBasis = parseValueBasis(trim((string)($_GET['value_basis'] ?? 'cost')));
$exportRequested = isset($_GET['export']) && (string)$_GET['export'] === '1';

if (!in_array($stockFilter, ['', 'in', 'low', 'out'], true)) {
    $stockFilter = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPageOptions = [10, 25, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 10;
}

$categories = [];
$catResult = $conn->query('SELECT id, name FROM inventory_categories ORDER BY name ASC');
while ($catRow = $catResult->fetch_assoc()) {
    $categories[] = ['id' => (int)$catRow['id'], 'name' => (string)$catRow['name']];
}

$categoryIds = array_column($categories, 'id');
if ($categoryFilter > 0 && !in_array($categoryFilter, $categoryIds, true)) {
    $categoryFilter = 0;
}

$exportCategoryFilter = (int)($_GET['export_category'] ?? $categoryFilter);
if ($exportCategoryFilter > 0 && !in_array($exportCategoryFilter, $categoryIds, true)) {
    $exportCategoryFilter = 0;
}

$summary = [
    'total_products' => 0,
    'low_stock_items' => 0,
    'total_value' => 0.0,
    'recent_additions' => 0,
    'total_categories' => 0,
];
$summaryValueColumn = $valueBasis === 'price' ? 'price' : 'cost';
$valueBasisLabel = $valueBasis === 'price' ? 'Price' : 'Cost';
$summaryResult = $conn->query(
    "SELECT
        COUNT(*) AS total_products,
        SUM(CASE WHEN (stock_store + stock_stockroom) > 0 AND (stock_store + stock_stockroom) <= 5 THEN 1 ELSE 0 END) AS low_stock_items,
        COALESCE(SUM((stock_store + stock_stockroom) * {$summaryValueColumn}), 0) AS total_value,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS recent_additions,
        COUNT(DISTINCT category_id) AS total_categories
     FROM products"
);
if ($summaryResult) {
    $s = $summaryResult->fetch_assoc();
    if ($s) {
        $summary['total_products'] = (int)($s['total_products'] ?? 0);
        $summary['low_stock_items'] = (int)($s['low_stock_items'] ?? 0);
        $summary['total_value'] = (float)($s['total_value'] ?? 0);
        $summary['recent_additions'] = (int)($s['recent_additions'] ?? 0);
        $summary['total_categories'] = (int)($s['total_categories'] ?? 0);
    }
}

$whereParts = [];
$bindTypes = '';
$bindValues = [];
if ($search !== '') {
    $terms = array_filter(preg_split('/\s+/', $search));
    foreach ($terms as $term) {
        $whereParts[] = '(p.product_name LIKE CONCAT("%", ?, "%") OR p.brand LIKE CONCAT("%", ?, "%") OR p.color_specs LIKE CONCAT("%", ?, "%") OR p.supplier LIKE CONCAT("%", ?, "%") OR c.name LIKE CONCAT("%", ?, "%"))';
        $bindTypes .= 'sssss';
        $bindValues[] = $term;
        $bindValues[] = $term;
        $bindValues[] = $term;
        $bindValues[] = $term;
        $bindValues[] = $term;
    }
}
if ($categoryFilter > 0) {
    $whereParts[] = 'p.category_id = ?';
    $bindTypes .= 'i';
    $bindValues[] = $categoryFilter;
}
if ($stockFilter === 'in') {
    $whereParts[] = '(p.stock_store + p.stock_stockroom) > 5';
} elseif ($stockFilter === 'low') {
    $whereParts[] = '(p.stock_store + p.stock_stockroom) > 0 AND (p.stock_store + p.stock_stockroom) <= 5';
} elseif ($stockFilter === 'out') {
    $whereParts[] = '(p.stock_store + p.stock_stockroom) <= 0';
}

$whereSql = $whereParts !== [] ? ' WHERE ' . implode(' AND ', $whereParts) : '';

$countSql = 'SELECT COUNT(*) AS total FROM products p LEFT JOIN inventory_categories c ON c.id = p.category_id' . $whereSql;
$countStmt = $conn->prepare($countSql);
if ($bindTypes !== '') {
    $countStmt->bind_param($bindTypes, ...$bindValues);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalMatching = 0;
if ($countResult) {
    $countRow = $countResult->fetch_assoc();
    $totalMatching = (int)($countRow['total'] ?? 0);
}
$countStmt->close();

$totalsSql = 'SELECT
        COALESCE(SUM((p.stock_store + p.stock_stockroom) * p.cost), 0) AS total_cost_value,
        COALESCE(SUM((p.stock_store + p.stock_stockroom) * p.price), 0) AS total_price_value
    FROM products p
    LEFT JOIN inventory_categories c ON c.id = p.category_id' . $whereSql;
$totalsStmt = $conn->prepare($totalsSql);
if ($bindTypes !== '') {
    $totalsStmt->bind_param($bindTypes, ...$bindValues);
}
$totalsStmt->execute();
$totalsResult = $totalsStmt->get_result();
$filteredValueCost = 0.0;
$filteredValuePrice = 0.0;
if ($totalsResult) {
    $tRow = $totalsResult->fetch_assoc();
    $filteredValueCost = (float)($tRow['total_cost_value'] ?? 0);
    $filteredValuePrice = (float)($tRow['total_price_value'] ?? 0);
}
$totalsStmt->close();

$totalPages = max(1, (int)ceil($totalMatching / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pageStart = $totalMatching > 0 ? $offset + 1 : 0;
$pageEnd = $totalMatching > 0 ? min($offset + $perPage, $totalMatching) : 0;

$sql = 'SELECT p.id, p.product_name, p.category_id, p.color_specs, p.brand, p.stock_store, p.stock_stockroom, p.cost, p.price, p.supplier, p.is_active, p.created_at, p.updated_at, c.name AS category_name
        FROM products p
        LEFT JOIN inventory_categories c ON c.id = p.category_id' . $whereSql . '
        ORDER BY p.product_name ASC, p.id DESC
        LIMIT ? OFFSET ?';

$stmt = $conn->prepare($sql);
$bindValuesWithPagination = $bindValues;
$bindValuesWithPagination[] = $perPage;
$bindValuesWithPagination[] = $offset;
$stmt->bind_param($bindTypes . 'ii', ...$bindValuesWithPagination);
$stmt->execute();
$result = $stmt->get_result();

$products = [];
$productsForJson = [];
$maxStock = 1;
while ($row = $result->fetch_assoc()) {
    $storeStock = (int)$row['stock_store'];
    $stockroomStock = (int)$row['stock_stockroom'];
    $totalStock = $storeStock + $stockroomStock;
    $categoryName = (string)($row['category_name'] ?? '');
    $statusText = ((int)($row['is_active'] ?? 1)) === 1 ? statusLabel(stockStatus($totalStock)) : 'Not Available';
    $isActive = ((int)($row['is_active'] ?? 1)) === 1;
    $statusValue = stockStatus($totalStock);
    $statusClassName = $isActive ? statusBadgeClass($statusValue) : 'bg-slate-200 text-slate-700';
    $stockBarClass = $isActive ? barClass($statusValue) : 'bg-slate-400';
    $createdAtLabel = date('M d, Y h:i A', strtotime((string)$row['created_at']));
    $updatedAtLabel = date('M d, Y h:i A', strtotime((string)$row['updated_at']));
    $products[] = [
        'id' => (int)$row['id'],
        'product_name' => (string)$row['product_name'],
        'category_id' => (int)($row['category_id'] ?? 0),
        'category_name' => $categoryName,
        'color_specs' => (string)$row['color_specs'],
        'brand' => (string)$row['brand'],
        'stock_store' => $storeStock,
        'stock_stockroom' => $stockroomStock,
        'total_stock' => $totalStock,
        'cost' => (float)$row['cost'],
        'price' => (float)$row['price'],
        'supplier' => (string)$row['supplier'],
        'is_active' => $isActive,
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
        'status' => $statusValue,
    ];
    $productsForJson[] = [
        'id' => (int)$row['id'],
        'product_name' => (string)$row['product_name'],
        'category_name' => $categoryName,
        'brand' => (string)$row['brand'],
        'color_specs' => (string)$row['color_specs'],
        'stock_store' => $storeStock,
        'stock_stockroom' => $stockroomStock,
        'total_stock' => $totalStock,
        'cost' => (float)$row['cost'],
        'price' => (float)$row['price'],
        'supplier' => (string)$row['supplier'],
        'is_active' => $isActive,
        'status' => $statusValue,
        'status_label' => $statusText,
        'status_badge_class' => $statusClassName,
        'bar_class' => $stockBarClass,
        'icon' => iconForCategory($categoryName),
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
        'created_label' => $createdAtLabel,
        'updated_label' => $updatedAtLabel,
    ];
    $maxStock = max($maxStock, $totalStock);
}
$stmt->close();

$filteredCount = $totalMatching;
$exportUrl = 'inventory.php?' . http_build_query([
    'q' => $search,
    'category' => $categoryFilter,
    'export_category' => $exportCategoryFilter,
    'stock' => $stockFilter,
    'value_basis' => $valueBasis,
    'export' => 1,
]);

if ($exportRequested) {
    $exportWhereParts = [];
    $exportBindTypes = '';
    $exportBindValues = [];

    if ($search !== '') {
        $exportTerms = array_filter(preg_split('/\\s+/', $search));
        foreach ($exportTerms as $term) {
            $exportWhereParts[] = '(p.product_name LIKE CONCAT("%", ?, "%") OR p.brand LIKE CONCAT("%", ?, "%") OR p.color_specs LIKE CONCAT("%", ?, "%") OR p.supplier LIKE CONCAT("%", ?, "%") OR c.name LIKE CONCAT("%", ?, "%"))';
            $exportBindTypes .= 'sssss';
            $exportBindValues[] = $term;
            $exportBindValues[] = $term;
            $exportBindValues[] = $term;
            $exportBindValues[] = $term;
            $exportBindValues[] = $term;
        }
    }
    if ($exportCategoryFilter > 0) {
        $exportWhereParts[] = 'p.category_id = ?';
        $exportBindTypes .= 'i';
        $exportBindValues[] = $exportCategoryFilter;
    }
    if ($stockFilter === 'in') {
        $exportWhereParts[] = '(p.stock_store + p.stock_stockroom) > 5';
    } elseif ($stockFilter === 'low') {
        $exportWhereParts[] = '(p.stock_store + p.stock_stockroom) > 0 AND (p.stock_store + p.stock_stockroom) <= 5';
    } elseif ($stockFilter === 'out') {
        $exportWhereParts[] = '(p.stock_store + p.stock_stockroom) <= 0';
    }

    $exportWhereSql = $exportWhereParts !== [] ? ' WHERE ' . implode(' AND ', $exportWhereParts) : '';

    $exportSql = 'SELECT p.product_name, p.color_specs, p.brand, p.stock_store, p.stock_stockroom, p.cost, p.price, p.supplier, p.created_at, p.updated_at, c.name AS category_name
                  FROM products p
                  LEFT JOIN inventory_categories c ON c.id = p.category_id' . $exportWhereSql . '
                  ORDER BY p.product_name ASC, p.id DESC';
    $exportStmt = $conn->prepare($exportSql);
    if ($exportBindTypes !== '') {
        $exportStmt->bind_param($exportBindTypes, ...$exportBindValues);
    }
    $exportStmt->execute();
    $exportResult = $exportStmt->get_result();

    $rowsForExport = [];
    $totalCostValue = 0.0;
    $totalPriceValue = 0.0;

    while ($product = $exportResult->fetch_assoc()) {
        $storeStock = (int)$product['stock_store'];
        $stockroomStock = (int)$product['stock_stockroom'];
        $totalStock = $storeStock + $stockroomStock;
        $stockValueCost = $totalStock * (float)$product['cost'];
        $stockValuePrice = $totalStock * (float)$product['price'];
        $totalCostValue += $stockValueCost;
        $totalPriceValue += $stockValuePrice;

        $rowsForExport[] = [
            'product_name' => (string)($product['product_name'] ?? ''),
            'category_name' => (string)($product['category_name'] ?? ''),
            'brand' => (string)($product['brand'] ?? ''),
            'color_specs' => (string)($product['color_specs'] ?? ''),
            'stock_store' => $storeStock,
            'stock_stockroom' => $stockroomStock,
            'total_stock' => $totalStock,
            'unit_cost' => (float)$product['cost'],
            'unit_price' => (float)$product['price'],
            'stock_value_cost' => $stockValueCost,
            'stock_value_price' => $stockValuePrice,
            'supplier' => (string)($product['supplier'] ?? ''),
            'created_at' => (string)($product['created_at'] ?? ''),
            'updated_at' => (string)($product['updated_at'] ?? ''),
        ];
    }
    $exportStmt->close();

    $filename = 'Inventory';
    excelXmlOutputInventoryRows($filename, 'Inventory', $rowsForExport, $totalCostValue, $totalPriceValue);
    exit;
}

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'products' => $productsForJson,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalMatching,
            'total_pages' => $totalPages,
            'page_start' => $pageStart,
            'page_end' => $pageEnd,
        ],
        'max_stock' => $maxStock,
        'totals' => [
            'cost' => $filteredValueCost,
            'price' => $filteredValuePrice,
        ],
        'value_basis' => $valueBasis,
        'per_page_options' => $perPageOptions,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inventory</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0,1" />
  <style>
    .stat-card { transition: transform .15s ease, box-shadow .2s ease; }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 20px 25px -5px rgba(0, 148, 195, .15), 0 10px 10px -5px rgba(0, 148, 195, .1); }
    .inventory-item { transition: all .2s ease; }
    .inventory-item:hover { background: #f8fafc; border-color: #0284c7; }
    .stock-high { color: #059669; background: #d1fae5; }
    .stock-medium { color: #d97706; background: #fed7aa; }
    .stock-low { color: #dc2626; background: #fecaca; }
    .modal-backdrop { background: rgba(15, 23, 42, .55); backdrop-filter: blur(2px); }
    .detail-card { border: 1px solid #dff0f7; border-radius: .9rem; background: linear-gradient(180deg, #f8fcff 0%, #f1f8fc 100%); padding: .8rem .9rem; }
    .detail-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; color: #2c7da0; margin-bottom: .2rem; }
    .detail-value { font-size: .95rem; font-weight: 600; color: #043b4a; }
  </style>
</head>
<body class="bg-[#e6f4fa] font-sans antialiased text-[#043b4a] min-h-screen flex">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="flex-1 flex flex-col w-full min-w-0">
    <header class="bg-white/90 backdrop-blur-md border-b border-white/40 px-3 sm:px-4 lg:px-6 py-2 sm:py-3 flex items-center justify-between sticky top-0 z-10 shadow-sm">
      <h1 class="text-sm sm:text-base lg:text-lg xl:text-xl font-light text-[#05445E] lg:ml-0 ml-12"><span class="font-semibold">Inventory</span> </h1>
      <div class="flex items-center gap-2 sm:gap-4">
        <div class="hidden lg:block text-sm text-[#2c7da0]"><?= date('l, F j, Y') ?></div>
        <div class="flex items-center gap-1.5 sm:gap-2">
          <img src="../assets/images/logo.jpg" alt="JD Logo" class="w-6 h-6 sm:w-8 sm:h-8 rounded-full object-contain shadow-md">
          <span class="hidden sm:block text-xs sm:text-sm font-medium text-[#05445E]">Admin</span>
        </div>
      </div>
    </header>

    <main class="flex-1 p-4 sm:p-6 lg:p-8 overflow-y-auto">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5 mb-8">
        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40"><p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Total Products</p><p class="text-3xl font-semibold text-[#05445E] mt-1"><?= number_format($summary['total_products']) ?></p><span class="text-xs text-[#0f6f94] bg-[#dff3fc] px-2 py-0.5 rounded-full mt-2 inline-block"><?= number_format($summary['total_categories']) ?> categories</span></div>
        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40"><p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Low Stock Items</p><p class="text-3xl font-semibold text-[#05445E] mt-1"><?= number_format($summary['low_stock_items']) ?></p><span class="text-xs text-red-600 bg-red-50 px-2 py-0.5 rounded-full mt-2 inline-block">needs restock</span></div>
        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40"><p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Total Value</p><p id="totalValueDisplay" class="text-3xl font-semibold text-[#05445E] mt-1"><?= peso((float)$summary['total_value']) ?></p><span id="valueBasisBadge" class="text-xs text-[#0f6f94] bg-[#dff3fc] px-2 py-0.5 rounded-full mt-2 inline-block"><?= h($valueBasisLabel) ?> basis</span></div>
        <div class="stat-card bg-white rounded-2xl p-5 shadow-md border border-white/40"><p class="text-xs uppercase tracking-wider text-[#2c7da0]/70">Recent Additions</p><p class="text-3xl font-semibold text-[#05445E] mt-1"><?= number_format($summary['recent_additions']) ?></p></div>
      </div>

      <div class="bg-white rounded-2xl p-5 shadow-md border border-white/40 mb-6">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <h2 class="text-lg font-medium text-[#05445E] flex items-center gap-2"><span class="material-symbols-outlined text-[#0f6f94]">inventory</span> Inventory Management</h2>
          <div class="flex flex-wrap items-center gap-3">
            <button id="openAddProductModal" type="button" class="flex items-center gap-2 px-4 py-2 bg-[#0f6f94] text-white rounded-lg hover:bg-[#0a4b6e] transition"><span class="material-symbols-outlined">add</span>Add Product</button>
            <label class="flex items-center gap-2 px-3 py-2 bg-white border border-[#d5e7f1] rounded-lg shadow-sm text-sm text-[#05445E]">
              <span class="material-symbols-outlined text-[#0f6f94]">category</span>
              <span class="text-xs uppercase tracking-wide text-[#2c7da0]">Export</span>
              <select id="exportCategorySelect" class="h-9 rounded-md border border-[#c8dfec] bg-white px-2 text-sm text-[#05445E] focus:outline-none focus:ring-2 focus:ring-[#0f6f94]">
                <option value="0" <?= $exportCategoryFilter === 0 ? 'selected' : '' ?>>All categories</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int)$cat['id'] ?>" <?= $exportCategoryFilter === (int)$cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <a id="exportBtn" href="<?= h($exportUrl) ?>" class="flex items-center gap-2 px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5] transition"><span class="material-symbols-outlined">download</span>Export</a>
          </div>
        </div>
        <form method="get" id="inventoryFilterForm" class="mt-4 flex flex-col sm:flex-row gap-4">
          <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
          <div class="flex-1"><input type="text" name="q" value="<?= h($search) ?>" placeholder="Search products, category, brand, specs, supplier..." class="w-full px-4 py-2 border border-[#b7d9e9] rounded-lg focus:outline-none focus:border-[#0f6f94]"></div>
          <select name="category" class="filter-auto-submit px-4 py-2 border border-[#b7d9e9] rounded-lg bg-white"><option value="0">All Categories</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>" <?= $categoryFilter === (int)$category['id'] ? 'selected' : '' ?>><?= h((string)$category['name']) ?></option><?php endforeach; ?></select>
          <select name="stock" class="filter-auto-submit px-4 py-2 border border-[#b7d9e9] rounded-lg bg-white"><option value="" <?= $stockFilter === '' ? 'selected' : '' ?>>All Stock Levels</option><option value="in" <?= $stockFilter === 'in' ? 'selected' : '' ?>>In Stock</option><option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low Stock</option><option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of Stock</option></select>
          <select name="value_basis" class="filter-auto-submit px-4 py-2 border border-[#b7d9e9] rounded-lg bg-white">
            <option value="cost" <?= $valueBasis === 'cost' ? 'selected' : '' ?>>Total Value by Cost</option>
            <option value="price" <?= $valueBasis === 'price' ? 'selected' : '' ?>>Total Value by Price</option>
          </select>
          <button type="button" id="resetFiltersBtn" class="px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5] text-center">Reset</button>
        </form>
      </div>

      <div class="bg-white rounded-2xl p-5 shadow-md border border-white/40">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-lg font-medium text-[#05445E]">Product Inventory</h2>
          <span id="resultsCountText" class="text-sm text-[#2c7da0]">
            <?php if ($filteredCount === 0): ?>
              Showing 0 results
            <?php else: ?>
              Showing <?= number_format($pageStart) ?>–<?= number_format($pageEnd) ?> of <?= number_format($filteredCount) ?> result<?= $filteredCount === 1 ? '' : 's' ?> (page <?= $page ?> of <?= $totalPages ?>)
            <?php endif; ?>
          </span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-[#2c7da0] border-b border-[#d0e7f2]"><tr><th class="text-left py-3 font-medium">Product Name</th><th class="text-left py-3 font-medium">Category</th><th class="text-left py-3 font-medium">Brand</th><th class="text-left py-3 font-medium">Color / Specs</th><th class="text-left py-3 font-medium">Stock</th><th class="text-left py-3 font-medium">Price</th><th class="text-left py-3 font-medium">Status</th><th class="text-left py-3 font-medium">Actions</th></tr></thead>
            <tbody class="divide-y divide-[#dff0f7]">
              <?php if ($products === []): ?>
                <tr id="noResultsRow"><td colspan="8" class="py-10 text-center text-[#2c7da0]">No products found.</td></tr>
              <?php else: ?>
                <?php foreach ($products as $product): ?>
                  <?php
                    $status = (string)$product['status'];
                    $isActive = (bool)$product['is_active'];
                    $totalStock = (int)$product['total_stock'];
                    $barWidth = $maxStock > 0 ? (int)round(($totalStock / $maxStock) * 100) : 0;
                    $barWidth = max(0, min(100, $barWidth));
                    $stockBarClass = $isActive ? barClass($status) : 'bg-slate-400';
                    $statusText = $isActive ? statusLabel($status) : 'Not Available';
                    $statusClassName = $isActive ? statusBadgeClass($status) : 'bg-slate-200 text-slate-700';
                    $categoryName = trim((string)$product['category_name']) !== '' ? (string)$product['category_name'] : 'Uncategorized';
                    $createdAtLabel = date('M d, Y h:i A', strtotime((string)$product['created_at']));
                    $updatedAtLabel = date('M d, Y h:i A', strtotime((string)$product['updated_at']));
                  ?>
                  <tr class="inventory-item <?= $isActive ? '' : 'opacity-70' ?>"
                    data-name="<?= h(strtolower((string)$product['product_name'])) ?>"
                    data-category="<?= h(strtolower($categoryName)) ?>"
                    data-category-id="<?= (int)$product['category_id'] ?>"
                    data-brand="<?= h(strtolower((string)$product['brand'])) ?>"
                    data-color="<?= h(strtolower((string)$product['color_specs'])) ?>"
                    data-supplier="<?= h(strtolower((string)$product['supplier'])) ?>"
                    data-total-stock="<?= (int)$product['total_stock'] ?>"
                    data-is-active="<?= $isActive ? '1' : '0' ?>"
                    data-cost="<?= number_format((float)$product['cost'], 2, '.', '') ?>"
                    data-price="<?= number_format((float)$product['price'], 2, '.', '') ?>">
                    <td class="py-3"><div class="flex items-center gap-2"><span class="material-symbols-outlined text-[#0f6f94]"><?= h(iconForCategory($categoryName)) ?></span><span class="font-medium"><?= h((string)$product['product_name']) ?></span></div></td>
                    <td class="py-3"><?= h($categoryName) ?></td>
                    <td class="py-3"><?= h((string)$product['brand']) ?></td>
                    <td class="py-3 text-[#05445E]"><?= h((string)$product['color_specs']) ?></td>
                    <td class="py-3"><div class="font-medium"><?= (int)$product['stock_store'] ?> in store / <?= (int)$product['stock_stockroom'] ?> in stock room</div><div class="w-full bg-gray-200 rounded-full h-2 mt-1"><div class="<?= h($stockBarClass) ?> h-2 rounded-full" style="width: <?= $barWidth ?>%"></div></div></td>
                    <td class="py-3"><?= peso((float)$product['price']) ?></td>
                    <td class="py-3"><span class="<?= h($statusClassName) ?> px-2 py-1 rounded-full text-xs font-medium"><?= h($statusText) ?></span></td>
                    <td class="py-3">
                      <div class="flex items-center gap-2">
                        <button type="button" class="view-btn text-[#0f6f94] hover:text-[#05445E] p-1" title="View"
                          data-id="<?= (int)$product['id'] ?>" data-name="<?= h((string)$product['product_name']) ?>" data-category="<?= h($categoryName) ?>"
                          data-color_specs="<?= h((string)$product['color_specs']) ?>" data-brand="<?= h((string)$product['brand']) ?>"
                          data-stock_store="<?= (int)$product['stock_store'] ?>" data-stock_stockroom="<?= (int)$product['stock_stockroom'] ?>"
                          data-cost="<?= number_format((float)$product['cost'], 2, '.', '') ?>" data-price="<?= number_format((float)$product['price'], 2, '.', '') ?>"
                          data-supplier="<?= h((string)$product['supplier']) ?>" data-created_at="<?= h($createdAtLabel) ?>" data-updated_at="<?= h($updatedAtLabel) ?>">
                          <span class="material-symbols-outlined">visibility</span>
                        </button>
                        <button type="button" class="edit-btn text-[#0f6f94] hover:text-[#05445E] p-1" title="Edit"
                          data-id="<?= (int)$product['id'] ?>" data-name="<?= h((string)$product['product_name']) ?>" data-category="<?= h((string)$product['category_name']) ?>"
                          data-color_specs="<?= h((string)$product['color_specs']) ?>" data-brand="<?= h((string)$product['brand']) ?>"
                          data-stock_store="<?= (int)$product['stock_store'] ?>" data-stock_stockroom="<?= (int)$product['stock_stockroom'] ?>"
                          data-cost="<?= number_format((float)$product['cost'], 2, '.', '') ?>" data-price="<?= number_format((float)$product['price'], 2, '.', '') ?>"
                          data-supplier="<?= h((string)$product['supplier']) ?>">
                          <span class="material-symbols-outlined">edit</span>
                        </button>
                        <form method="post" class="inline availability-form">
                          <input type="hidden" name="action" value="<?= $isActive ? 'set_inactive' : 'set_active' ?>">
                          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                          <button
                            type="button"
                            class="availability-btn <?= $isActive ? 'text-red-600 hover:text-red-800' : 'text-emerald-600 hover:text-emerald-800' ?> p-1"
                            data-mode="<?= $isActive ? 'set_inactive' : 'set_active' ?>"
                            data-name="<?= h((string)$product['product_name']) ?>"
                            title="<?= $isActive ? 'Mark as Not Available' : 'Mark as Available' ?>"
                          >
                            <span class="material-symbols-outlined"><?= $isActive ? 'close' : 'check_circle' ?></span>
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <tr id="noResultsRow" class="hidden"><td colspan="8" class="py-10 text-center text-[#2c7da0]">No products found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div id="paginationControls" class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mt-4 <?= $filteredCount > 0 ? '' : 'hidden' ?>">
          <div id="pageStats" class="text-sm text-[#2c7da0]">
            <?php if ($filteredCount === 0): ?>
              Showing 0 results
            <?php else: ?>
              Showing <?= number_format($pageStart) ?>–<?= number_format($pageEnd) ?> of <?= number_format($filteredCount) ?> result<?= $filteredCount === 1 ? '' : 's' ?> (page <?= $page ?> of <?= $totalPages ?>)
            <?php endif; ?>
          </div>
          <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
              <label for="perPageSelect" class="text-sm text-[#2c7da0]">Rows per page</label>
              <select id="perPageSelect" class="px-3 py-2 border border-[#b7d9e9] rounded-lg bg-white">
                <?php foreach ($perPageOptions as $option): ?>
                  <option value="<?= (int)$option ?>" <?= $perPage === (int)$option ? 'selected' : '' ?>><?= (int)$option ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" id="prevPageBtn" class="px-3 py-2 border border-[#b7d9e9] rounded-lg text-[#05445E] hover:bg-[#e2f0f7] <?= $page <= 1 ? 'opacity-60 pointer-events-none' : '' ?>">Previous</button>
              <span id="pageIndicator" class="text-sm text-[#2c7da0]">Page <?= $page ?> of <?= $totalPages ?></span>
              <button type="button" id="nextPageBtn" class="px-3 py-2 border border-[#b7d9e9] rounded-lg text-[#05445E] hover:bg-[#e2f0f7] <?= $page >= $totalPages ? 'opacity-60 pointer-events-none' : '' ?>">Next</button>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <div id="productModal" class="fixed inset-0 z-40 hidden modal-backdrop items-center justify-center p-4">
    <div class="bg-white w-full max-w-3xl rounded-2xl shadow-xl border border-[#d0e7f2] overflow-hidden">
      <div class="px-6 py-4 border-b border-[#dff0f7] flex items-center justify-between"><h3 id="productModalTitle" class="text-lg font-semibold text-[#05445E]">Add Product</h3><button type="button" class="close-modal text-[#2c7da0] hover:text-[#05445E]" data-modal-id="productModal"><span class="material-symbols-outlined">close</span></button></div>
      <form method="post" id="productForm" class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        <input type="hidden" name="action" id="productFormAction" value="create"><input type="hidden" name="product_id" id="productId" value="0">
        <div class="md:col-span-2"><label class="block text-sm font-medium mb-1">Name</label><input type="text" name="product_name" id="productName" required class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg"></div>
        <div><label class="block text-sm font-medium mb-1">Category</label><select name="category_id" id="productCategoryId" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg"><option value="0">Select category</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>" data-name="<?= h((string)$category['name']) ?>"><?= h((string)$category['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="block text-sm font-medium mb-1">New Category (optional)</label><input type="text" name="new_category" id="newCategory" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg"></div>
        <div><label class="block text-sm font-medium mb-1">Color / Specs</label><input type="text" name="color_specs" id="colorSpecs" required class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg"></div>
        <div><label class="block text-sm font-medium mb-1">Brand</label><input type="text" name="brand" id="brand" required class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg"></div>
        <div><label class="block text-sm font-medium mb-1">Stocks in Store</label><input type="number" min="0" name="stock_store" id="stockStore" required class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg"></div>
        <div><label class="block text-sm font-medium mb-1">Stocks in Stock Room</label><input type="number" min="0" name="stock_stockroom" id="stockStockroom" required class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg"></div>
        <div><label class="block text-sm font-medium mb-1">Cost</label><input type="number" min="0" step="0.01" name="cost" id="cost" required class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg"></div>
        <div><label class="block text-sm font-medium mb-1">Price</label><input type="number" min="0" step="0.01" name="price" id="price" required class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg"></div>
        <div class="md:col-span-2"><label class="block text-sm font-medium mb-1">Supplier</label><input type="text" name="supplier" id="supplier" required class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg"></div>
        <div class="md:col-span-2 flex justify-end gap-3"><button type="button" class="close-modal px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg" data-modal-id="productModal">Cancel</button><button type="submit" class="px-4 py-2 bg-[#0f6f94] text-white rounded-lg">Save Product</button></div>
      </form>
    </div>
  </div>

  <div id="categoryModal" class="fixed inset-0 z-40 hidden modal-backdrop items-center justify-center p-4">
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-xl border border-[#d0e7f2] overflow-hidden">
      <div class="px-6 py-4 border-b border-[#dff0f7] flex items-center justify-between"><h3 class="text-lg font-semibold text-[#05445E]">Add Category</h3><button type="button" class="close-modal text-[#2c7da0] hover:text-[#05445E]" data-modal-id="categoryModal"><span class="material-symbols-outlined">close</span></button></div>
      <form method="post" class="p-6 space-y-4"><input type="hidden" name="action" value="add_category"><div><label class="block text-sm font-medium mb-1">Category Name</label><input type="text" name="category_name" required class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg"></div><div class="flex justify-end gap-3"><button type="button" class="close-modal px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg" data-modal-id="categoryModal">Cancel</button><button type="submit" class="px-4 py-2 bg-[#0f6f94] text-white rounded-lg">Save Category</button></div></form>
    </div>
  </div>

  <div id="viewModal" class="fixed inset-0 z-40 hidden modal-backdrop items-center justify-center p-4">
    <div class="bg-white w-full max-w-4xl rounded-2xl shadow-xl border border-[#d0e7f2] overflow-hidden">
      <div class="px-6 py-4 border-b border-[#dff0f7] bg-gradient-to-r from-[#f4fbff] to-[#ecf7fd] flex items-center justify-between">
        <div>
          <h3 class="text-lg font-semibold text-[#05445E]">Inventory Product Profile</h3>
          <p id="viewModalSubTitle" class="text-xs text-[#2c7da0] mt-0.5">Complete product details and stock information.</p>
        </div>
        <button type="button" class="close-modal text-[#2c7da0] hover:text-[#05445E]" data-modal-id="viewModal"><span class="material-symbols-outlined">close</span></button>
      </div>
      <div class="p-6 text-sm">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="detail-card"><p class="detail-label">Product Name</p><p id="viewName" class="detail-value"></p></div>
          <div class="detail-card"><p class="detail-label">Category</p><p id="viewCategory" class="detail-value"></p></div>
          <div class="detail-card"><p class="detail-label">Brand</p><p id="viewBrand" class="detail-value"></p></div>
          <div class="detail-card"><p class="detail-label">Color / Specs</p><p id="viewColorSpecs" class="detail-value"></p></div>
          <div class="detail-card"><p class="detail-label">Unit Cost</p><p id="viewCost" class="detail-value"></p></div>
          <div class="detail-card"><p class="detail-label">Unit Price</p><p id="viewPrice" class="detail-value"></p></div>
          <div class="detail-card md:col-span-2"><p class="detail-label">Supplier</p><p id="viewSupplier" class="detail-value"></p></div>
        </div>
        <div class="mt-5 border border-[#dff0f7] rounded-xl p-4 bg-[#fbfeff]">
          <p class="text-xs uppercase tracking-wider text-[#2c7da0] mb-3">Stock Distribution</p>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="detail-card"><p class="detail-label">In Store</p><p id="viewStockStore" class="detail-value"></p></div>
            <div class="detail-card"><p class="detail-label">In Stock Room</p><p id="viewStockStockroom" class="detail-value"></p></div>
            <div class="detail-card"><p class="detail-label">Total Stock</p><p id="viewTotalStock" class="detail-value"></p></div>
          </div>
        </div>
        <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="detail-card"><p class="detail-label">Created</p><p id="viewCreatedAt" class="detail-value"></p></div>
          <div class="detail-card"><p class="detail-label">Last Updated</p><p id="viewUpdatedAt" class="detail-value"></p></div>
        </div>
        <div class="mt-6 flex justify-end gap-3">
          <button type="button" class="close-modal px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5]" data-modal-id="viewModal">Close</button>
          <button type="button" id="openRestockFromView" class="px-4 py-2 bg-[#0f6f94] text-white rounded-lg hover:bg-[#0a4b6e] flex items-center gap-2"><span class="material-symbols-outlined text-[20px]">inventory</span>Restock</button>
        </div>
      </div>
    </div>
  </div>

  <div id="restockModal" class="fixed inset-0 z-40 hidden modal-backdrop items-center justify-center p-4">
    <div class="bg-white w-full max-w-xl rounded-2xl shadow-xl border border-[#d0e7f2] overflow-hidden">
      <div class="px-6 py-4 border-b border-[#dff0f7] bg-gradient-to-r from-[#f4fbff] to-[#ecf7fd] flex items-center justify-between">
        <div>
          <h3 class="text-lg font-semibold text-[#05445E]">Restock Product</h3>
          <p class="text-xs text-[#2c7da0] mt-0.5">Add quantities for store and stock room inventory.</p>
        </div>
        <button type="button" class="close-modal text-[#2c7da0] hover:text-[#05445E]" data-modal-id="restockModal"><span class="material-symbols-outlined">close</span></button>
      </div>
      <form method="post" id="restockForm" class="p-6 space-y-4">
        <input type="hidden" name="action" value="restock">
        <input type="hidden" name="product_id" id="restockProductId" value="0">
        <div class="detail-card">
          <p class="detail-label">Product</p>
          <p id="restockProductName" class="detail-value"></p>
          <p class="text-xs text-[#2c7da0] mt-1">Color / Specs: <span id="restockProductColor" class="font-semibold text-[#05445E]">-</span></p>
          <p class="text-xs text-[#2c7da0] mt-2">Current: <span id="restockCurrentStore" class="font-semibold text-[#05445E]">0</span> in store and <span id="restockCurrentStockroom" class="font-semibold text-[#05445E]">0</span> in stock room.</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-1 text-[#05445E]">Add to Store</label>
            <input type="number" min="0" step="1" name="restock_store" id="restockStoreAdd" required class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1 text-[#05445E]">Add to Stock Room</label>
            <input type="number" min="0" step="1" name="restock_stockroom" id="restockStockroomAdd" required class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1 text-[#05445E]">Notes (optional)</label>
          <input type="text" name="restock_notes" id="restockNotes" maxlength="255" placeholder="Example: Supplier delivery batch #12" class="w-full px-3 py-2 border border-[#b7d9e9] rounded-lg">
        </div>
        <div class="flex justify-end gap-3">
          <button type="button" class="close-modal px-4 py-2 bg-[#e2f0f7] text-[#05445E] rounded-lg hover:bg-[#cee9f5]" data-modal-id="restockModal">Cancel</button>
          <button type="submit" class="px-4 py-2 bg-[#0f6f94] text-white rounded-lg hover:bg-[#0a4b6e]">Confirm Restock</button>
        </div>
      </form>
    </div>
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

    const openModal = (id) => { const m = document.getElementById(id); if (m) { m.classList.remove('hidden'); m.classList.add('flex'); } };
    const closeModal = (id) => { const m = document.getElementById(id); if (m) { m.classList.add('hidden'); m.classList.remove('flex'); } };
    document.querySelectorAll('.close-modal').forEach((btn) => btn.addEventListener('click', () => closeModal(btn.dataset.modalId)));
    ['productModal', 'categoryModal', 'viewModal', 'restockModal'].forEach((id) => {
      const modal = document.getElementById(id);
      if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(id); });
    });

    const formAction = document.getElementById('productFormAction');
    const productId = document.getElementById('productId');
    const productName = document.getElementById('productName');
    const productCategoryId = document.getElementById('productCategoryId');
    const newCategory = document.getElementById('newCategory');
    const colorSpecs = document.getElementById('colorSpecs');
    const brand = document.getElementById('brand');
    const stockStore = document.getElementById('stockStore');
    const stockStockroom = document.getElementById('stockStockroom');
    const cost = document.getElementById('cost');
    const price = document.getElementById('price');
    const supplier = document.getElementById('supplier');
    const modalTitle = document.getElementById('productModalTitle');
    const viewModalSubTitle = document.getElementById('viewModalSubTitle');
    const openRestockFromView = document.getElementById('openRestockFromView');
    const restockProductId = document.getElementById('restockProductId');
    const restockProductName = document.getElementById('restockProductName');
    const restockProductColor = document.getElementById('restockProductColor');
    const restockCurrentStore = document.getElementById('restockCurrentStore');
    const restockCurrentStockroom = document.getElementById('restockCurrentStockroom');
    const restockStoreAdd = document.getElementById('restockStoreAdd');
    const restockStockroomAdd = document.getElementById('restockStockroomAdd');
    const restockNotes = document.getElementById('restockNotes');
    const restockForm = document.getElementById('restockForm');
    const filterForm = document.getElementById('inventoryFilterForm');
    const searchInput = filterForm?.querySelector('input[name="q"]');
    const categorySelect = filterForm?.querySelector('select[name="category"]');
    const stockSelect = filterForm?.querySelector('select[name="stock"]');
    const valueBasisSelect = filterForm?.querySelector('select[name="value_basis"]');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');
    const exportCategorySelect = document.getElementById('exportCategorySelect');
    const exportBtn = document.getElementById('exportBtn');
    const perPageSelect = document.getElementById('perPageSelect');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    const pageStats = document.getElementById('pageStats');
    const pageIndicator = document.getElementById('pageIndicator');
    const paginationControls = document.getElementById('paginationControls');
    const resultsCountText = document.getElementById('resultsCountText');
    const tableBody = document.querySelector('tbody.divide-y');
    const totalValueDisplay = document.getElementById('totalValueDisplay');
    const valueBasisBadge = document.getElementById('valueBasisBadge');

    const state = {
      page: <?= (int)$page ?>,
      perPage: <?= (int)$perPage ?>,
      search: <?= json_encode($search, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
      category: <?= (int)$categoryFilter ?>,
      stock: <?= json_encode($stockFilter, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
      valueBasis: <?= json_encode($valueBasis, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
      exportCategory: <?= (int)$exportCategoryFilter ?>,
    };

    let maxStockCurrent = <?= (int)$maxStock ?>;
    let totalMatching = <?= (int)$filteredCount ?>;
    let totalValueCost = <?= json_encode($filteredValueCost) ?>;
    let totalValuePrice = <?= json_encode($filteredValuePrice) ?>;
    let totalPagesCurrent = <?= (int)$totalPages ?>;
    const perPageOptions = <?= json_encode($perPageOptions) ?>;
    const initialProducts = <?= json_encode($productsForJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    const escapeHtml = (str) => (str ?? '').toString()
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

    const formatPeso = (value) => {
      const amount = Number.isFinite(value) ? value : 0;
      return `PHP ${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    const updateExportHref = () => {
      if (!exportBtn) return;
      const params = new URLSearchParams();
      if ((state.search || '').trim()) params.set('q', state.search.trim());
      if (state.category && state.category !== 0) params.set('category', String(state.category));
      if ((state.stock || '') !== '') params.set('stock', state.stock);
      params.set('value_basis', state.valueBasis === 'price' ? 'price' : 'cost');
      params.set('export_category', String(Number.isFinite(state.exportCategory) ? state.exportCategory : 0));
      params.set('export', '1');
      exportBtn.href = `inventory.php?${params.toString()}`;
    };

    const renderRows = (products) => {
      if (!tableBody) return;
      if (!products || products.length === 0) {
        tableBody.innerHTML = '<tr id="noResultsRow"><td colspan="8" class="py-10 text-center text-[#2c7da0]">No products found.</td></tr>';
        return;
      }
      const rowsHtml = products.map((product) => {
        const isActive = Boolean(product.is_active);
        const statusText = product.status_label || (isActive ? 'In Stock' : 'Not Available');
        const statusClass = product.status_badge_class || 'bg-slate-200 text-slate-700';
        const barClass = product.bar_class || 'bg-slate-400';
        const barWidth = maxStockCurrent > 0 ? Math.max(0, Math.min(100, Math.round((product.total_stock / maxStockCurrent) * 100))) : 0;
        const categoryName = (product.category_name || 'Uncategorized').toString();
        return `<tr class="inventory-item ${isActive ? '' : 'opacity-70'}"
          data-name="${escapeHtml((product.product_name || '').toLowerCase())}"
          data-category="${escapeHtml(categoryName.toLowerCase())}"
          data-category-id="0"
          data-brand="${escapeHtml((product.brand || '').toLowerCase())}"
          data-color="${escapeHtml((product.color_specs || '').toLowerCase())}"
          data-supplier="${escapeHtml((product.supplier || '').toLowerCase())}"
          data-total-stock="${product.total_stock}"
          data-is-active="${isActive ? '1' : '0'}"
          data-cost="${Number(product.cost || 0).toFixed(2)}"
          data-price="${Number(product.price || 0).toFixed(2)}">
          <td class="py-3"><div class="flex items-center gap-2"><span class="material-symbols-outlined text-[#0f6f94]">${escapeHtml(product.icon || 'inventory_2')}</span><span class="font-medium">${escapeHtml(product.product_name)}</span></div></td>
          <td class="py-3">${escapeHtml(categoryName)}</td>
          <td class="py-3">${escapeHtml(product.brand || '')}</td>
          <td class="py-3 text-[#05445E]">${escapeHtml(product.color_specs || '')}</td>
          <td class="py-3"><div class="font-medium">${product.stock_store} in store / ${product.stock_stockroom} in stock room</div><div class="w-full bg-gray-200 rounded-full h-2 mt-1"><div class="${escapeHtml(barClass)} h-2 rounded-full" style="width: ${barWidth}%"></div></div></td>
          <td class="py-3">${formatPeso(Number(product.price || 0))}</td>
          <td class="py-3"><span class="${escapeHtml(statusClass)} px-2 py-1 rounded-full text-xs font-medium">${escapeHtml(statusText)}</span></td>
          <td class="py-3">
            <div class="flex items-center gap-2">
              <button type="button" class="view-btn text-[#0f6f94] hover:text-[#05445E] p-1" title="View"
                data-id="${product.id}" data-name="${escapeHtml(product.product_name || '')}" data-category="${escapeHtml(categoryName)}"
                data-color_specs="${escapeHtml(product.color_specs || '')}" data-brand="${escapeHtml(product.brand || '')}"
                data-stock_store="${product.stock_store}" data-stock_stockroom="${product.stock_stockroom}"
                data-cost="${Number(product.cost || 0).toFixed(2)}" data-price="${Number(product.price || 0).toFixed(2)}"
                data-supplier="${escapeHtml(product.supplier || '')}" data-created_at="${escapeHtml(product.created_label || '')}" data-updated_at="${escapeHtml(product.updated_label || '')}">
                <span class="material-symbols-outlined">visibility</span>
              </button>
              <button type="button" class="edit-btn text-[#0f6f94] hover:text-[#05445E] p-1" title="Edit"
                data-id="${product.id}" data-name="${escapeHtml(product.product_name || '')}" data-category="${escapeHtml(product.category_name || '')}"
                data-color_specs="${escapeHtml(product.color_specs || '')}" data-brand="${escapeHtml(product.brand || '')}"
                data-stock_store="${product.stock_store}" data-stock_stockroom="${product.stock_stockroom}"
                data-cost="${Number(product.cost || 0).toFixed(2)}" data-price="${Number(product.price || 0).toFixed(2)}"
                data-supplier="${escapeHtml(product.supplier || '')}">
                <span class="material-symbols-outlined">edit</span>
              </button>
              <form method="post" class="inline availability-form">
                <input type="hidden" name="action" value="${isActive ? 'set_inactive' : 'set_active'}">
                <input type="hidden" name="product_id" value="${product.id}">
                <button type="button" class="availability-btn ${isActive ? 'text-red-600 hover:text-red-800' : 'text-emerald-600 hover:text-emerald-800'} p-1"
                  data-mode="${isActive ? 'set_inactive' : 'set_active'}"
                  data-name="${escapeHtml(product.product_name || '')}"
                  title="${isActive ? 'Mark as Not Available' : 'Mark as Available'}">
                  <span class="material-symbols-outlined">${isActive ? 'close' : 'check_circle'}</span>
                </button>
              </form>
            </div>
          </td>
        </tr>`;
      }).join('');
      tableBody.innerHTML = rowsHtml;
      bindRowActions();
    };

    const updateStats = (pageStart, pageEnd, total, totalPages) => {
      if (resultsCountText) {
        resultsCountText.textContent = total === 0
          ? 'Showing 0 results'
          : `Showing ${pageStart.toLocaleString()}–${pageEnd.toLocaleString()} of ${total.toLocaleString()} result${total === 1 ? '' : 's'} (page ${state.page} of ${totalPages})`;
      }
      if (pageStats) {
        pageStats.textContent = resultsCountText ? resultsCountText.textContent : '';
      }
      if (pageIndicator) {
        pageIndicator.textContent = `Page ${state.page} of ${totalPages}`;
      }
      if (paginationControls) {
        paginationControls.classList.toggle('hidden', total === 0);
      }
      if (prevPageBtn) {
        prevPageBtn.classList.toggle('opacity-60', state.page <= 1);
        prevPageBtn.classList.toggle('pointer-events-none', state.page <= 1);
      }
      if (nextPageBtn) {
        nextPageBtn.classList.toggle('opacity-60', state.page >= totalPages);
        nextPageBtn.classList.toggle('pointer-events-none', state.page >= totalPages);
      }
    };

    const updateTotalsDisplay = () => {
      if (!totalValueDisplay || !valueBasisBadge) return;
      const basis = state.valueBasis === 'price' ? 'price' : 'cost';
      const amount = basis === 'price' ? totalValuePrice : totalValueCost;
      totalValueDisplay.textContent = formatPeso(amount);
      valueBasisBadge.textContent = `${basis === 'price' ? 'Price' : 'Cost'} basis`;
    };

    const buildQueryParams = () => {
      const params = new URLSearchParams();
      if ((state.search || '').trim() !== '') params.set('q', state.search.trim());
      if (state.category) params.set('category', String(state.category));
      if (state.stock) params.set('stock', state.stock);
      params.set('value_basis', state.valueBasis === 'price' ? 'price' : 'cost');
      params.set('page', String(state.page));
      params.set('per_page', String(state.perPage));
      params.set('ajax', '1');
      return params;
    };

    let activeFetchController = null;
    const fetchInventory = async () => {
      const params = buildQueryParams();
      const url = `inventory.php?${params.toString()}`;
      if (activeFetchController) activeFetchController.abort();
      activeFetchController = new AbortController();
      if (tableBody) {
        tableBody.innerHTML = '<tr><td colspan="8" class="py-6 text-center text-[#2c7da0]">Loading…</td></tr>';
      }
      try {
        const response = await fetch(url, { signal: activeFetchController.signal, cache: 'no-store' });
        if (!response.ok) throw new Error('Request failed');
        const data = await response.json();
        maxStockCurrent = Number(data.max_stock || 0) || 1;
        totalMatching = Number(data.pagination?.total || 0) || 0;
        totalPagesCurrent = Number(data.pagination?.total_pages || 1) || 1;
        totalValueCost = Number(data.totals?.cost || 0);
        totalValuePrice = Number(data.totals?.price || 0);
        state.page = Number(data.pagination?.page || state.page);
        state.perPage = Number(data.pagination?.per_page || state.perPage);
        renderRows(data.products || []);
        updateStats(
          Number(data.pagination?.page_start || 0),
          Number(data.pagination?.page_end || 0),
          totalMatching,
          totalPagesCurrent,
        );
        updateTotalsDisplay();
        updateExportHref();
        if (perPageSelect) perPageSelect.value = String(state.perPage);
      } catch (e) {
        if (e.name === 'AbortError') return;
        if (tableBody) {
          tableBody.innerHTML = '<tr><td colspan="8" class="py-6 text-center text-red-600">Failed to load data.</td></tr>';
        }
      }
    };

    const debounce = (fn, delay = 250) => {
      let t;
      return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), delay);
      };
    };

    const applySearch = debounce(() => {
      state.search = searchInput?.value || '';
      state.page = 1;
      fetchInventory();
    }, 250);

    searchInput?.addEventListener('input', applySearch);
    categorySelect?.addEventListener('change', () => {
      state.category = Number(categorySelect.value || 0);
      state.page = 1;
      fetchInventory();
    });
    stockSelect?.addEventListener('change', () => {
      state.stock = stockSelect.value || '';
      state.page = 1;
      fetchInventory();
    });
    valueBasisSelect?.addEventListener('change', () => {
      state.valueBasis = valueBasisSelect.value === 'price' ? 'price' : 'cost';
      state.page = 1;
      fetchInventory();
    });
    exportCategorySelect?.addEventListener('change', () => {
      state.exportCategory = Number(exportCategorySelect.value || 0);
      updateExportHref();
    });
    perPageSelect?.addEventListener('change', () => {
      state.perPage = Number(perPageSelect.value || 10);
      state.page = 1;
      fetchInventory();
    });
    prevPageBtn?.addEventListener('click', () => {
      if (state.page <= 1) return;
      state.page -= 1;
      fetchInventory();
    });
    nextPageBtn?.addEventListener('click', () => {
      if (state.page >= totalPagesCurrent) return;
      state.page += 1;
      fetchInventory();
    });
    resetFiltersBtn?.addEventListener('click', () => {
      if (searchInput) searchInput.value = '';
      if (categorySelect) categorySelect.value = '0';
      if (stockSelect) stockSelect.value = '';
      if (valueBasisSelect) valueBasisSelect.value = 'cost';
      if (exportCategorySelect) exportCategorySelect.value = '0';
      state.search = '';
      state.category = 0;
      state.stock = '';
      state.valueBasis = 'cost';
      state.exportCategory = 0;
      state.page = 1;
      fetchInventory();
      updateExportHref();
    });

    document.getElementById('openAddProductModal')?.addEventListener('click', () => {
      modalTitle.textContent = 'Add Product';
      formAction.value = 'create';
      productId.value = '0';
      productName.value = '';
      productCategoryId.value = '0';
      newCategory.value = '';
      colorSpecs.value = '';
      brand.value = '';
      stockStore.value = '0';
      stockStockroom.value = '0';
      cost.value = '0.00';
      price.value = '0.00';
      supplier.value = '';
      openModal('productModal');
    });

    const categoryNameToId = {};
    Array.from(productCategoryId.options).forEach((option) => {
      const key = (option.dataset.name || '').trim().toLowerCase();
      if (key) categoryNameToId[key] = option.value;
    });

    const bindRowActions = () => {
      document.querySelectorAll('.edit-btn').forEach((btn) => {
        btn.onclick = () => {
          const d = btn.dataset;
          modalTitle.textContent = 'Edit Product';
          formAction.value = 'update';
          productId.value = d.id || '0';
          productName.value = d.name || '';
          productCategoryId.value = categoryNameToId[(d.category || '').trim().toLowerCase()] || '0';
          newCategory.value = '';
          colorSpecs.value = d.color_specs || '';
          brand.value = d.brand || '';
          stockStore.value = d.stock_store || '0';
          stockStockroom.value = d.stock_stockroom || '0';
          cost.value = d.cost || '0.00';
          price.value = d.price || '0.00';
          supplier.value = d.supplier || '';
          openModal('productModal');
        };
      });

      document.querySelectorAll('.view-btn').forEach((btn) => {
        btn.onclick = () => {
          const d = btn.dataset;
          const currentStore = Number(d.stock_store || 0);
          const currentStockroom = Number(d.stock_stockroom || 0);
          const totalStock = currentStore + currentStockroom;
          document.getElementById('viewName').textContent = d.name || '-';
          document.getElementById('viewCategory').textContent = d.category || '-';
          document.getElementById('viewColorSpecs').textContent = d.color_specs || '-';
          document.getElementById('viewBrand').textContent = d.brand || '-';
          document.getElementById('viewStockStore').textContent = String(currentStore);
          document.getElementById('viewStockStockroom').textContent = String(currentStockroom);
          document.getElementById('viewTotalStock').textContent = String(totalStock);
          document.getElementById('viewCost').textContent = `PHP ${Number(d.cost || 0).toFixed(2)}`;
          document.getElementById('viewPrice').textContent = `PHP ${Number(d.price || 0).toFixed(2)}`;
          document.getElementById('viewSupplier').textContent = d.supplier || '-';
          document.getElementById('viewCreatedAt').textContent = d.created_at || '-';
          document.getElementById('viewUpdatedAt').textContent = d.updated_at || '-';
          if (viewModalSubTitle) {
            viewModalSubTitle.textContent = `Product ID #${d.id || '-'} | Last updated ${d.updated_at || '-'}`;
          }
          if (openRestockFromView) {
            openRestockFromView.dataset.productId = d.id || '0';
            openRestockFromView.dataset.productName = d.name || '';
            openRestockFromView.dataset.productColor = d.color_specs || '-';
            openRestockFromView.dataset.currentStore = String(currentStore);
            openRestockFromView.dataset.currentStockroom = String(currentStockroom);
          }
          openModal('viewModal');
        };
      });

      document.querySelectorAll('.availability-btn').forEach((btn) => {
        btn.onclick = () => {
          const form = btn.closest('.availability-form');
          if (!form) return;
          const mode = btn.dataset.mode === 'set_active' ? 'set_active' : 'set_inactive';
          const name = (btn.dataset.name || 'this product').trim();

          const title = mode === 'set_active'
            ? 'Mark as available?'
            : 'Mark as not available?';
          const text = mode === 'set_active'
            ? `Are you sure you want to mark "${name}" as available?`
            : `Are you sure you want to mark "${name}" as not available?`;
          const confirmButtonText = mode === 'set_active'
            ? 'Mark as Available'
            : 'Mark as Not Available';
          const confirmButtonColor = mode === 'set_active' ? '#059669' : '#dc2626';

          Swal.fire({
            title,
            text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor,
            cancelButtonColor: '#64748b',
            confirmButtonText,
      }).then((r) => { if (r.isConfirmed) form.submit(); });
    };
    });
  };

    if (initialProducts.length === 0) {
      renderRows([]);
      updateStats(0, 0, totalMatching, totalPagesCurrent);
    } else {
      renderRows(initialProducts);
      updateStats(<?= (int)$pageStart ?>, <?= (int)$pageEnd ?>, totalMatching, totalPagesCurrent);
      updateTotalsDisplay();
      updateExportHref();
    }

    openRestockFromView?.addEventListener('click', () => {
      const d = openRestockFromView.dataset;
      restockProductId.value = d.productId || '0';
      restockProductName.textContent = d.productName || '-';
      if (restockProductColor) restockProductColor.textContent = d.productColor || '-';
      restockCurrentStore.textContent = d.currentStore || '0';
      restockCurrentStockroom.textContent = d.currentStockroom || '0';
      restockStoreAdd.value = '0';
      restockStockroomAdd.value = '0';
      restockNotes.value = '';
      closeModal('viewModal');
      openModal('restockModal');
    });

    restockForm?.addEventListener('submit', (e) => {
      const addStore = Number(restockStoreAdd.value || 0);
      const addStockroom = Number(restockStockroomAdd.value || 0);
      if (addStore <= 0 && addStockroom <= 0) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'Restock quantity required',
          text: 'Enter quantity for store or stock room before submitting.',
          confirmButtonColor: '#0f6f94',
        });
      }
    });

    const flashType = <?= json_encode($alertType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const flashMessage = <?= json_encode($alertMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    if (flashType && flashMessage) {
      Swal.fire({ icon: flashType, title: flashType === 'success' ? 'Success' : 'Failed', text: flashMessage, confirmButtonColor: '#0f6f94' });
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
