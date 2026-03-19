<?php
declare(strict_types=1);

if (!function_exists('excelXmlEscape')) {
    function excelXmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

if (!function_exists('excelXmlDateValue')) {
    function excelXmlDateValue(string $value): ?string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d\TH:i:s.000', $timestamp);
    }
}

if (!function_exists('excelXmlNormalizeFileName')) {
    function excelXmlNormalizeFileName(string $value, string $fallback = 'export'): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $value);
        $name = trim((string)$name, '_');
        return $name !== '' ? $name : $fallback;
    }
}

if (!function_exists('excelXmlNormalizeSheetName')) {
    function excelXmlNormalizeSheetName(string $value, string $fallback = 'Sheet1'): string
    {
        $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', '', $value);
        $name = trim((string)$name);
        if ($name === '') {
            return $fallback;
        }
        if (strlen($name) > 31) {
            return substr($name, 0, 31);
        }
        return $name;
    }
}

if (!function_exists('excelXmlOutputSalesRows')) {
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    function excelXmlOutputSalesRows(
        string $filenameBase,
        string $sheetName,
        array $rows,
        float $totalNetGross,
        string $totalLabel = 'TOTAL NET GROSS',
        ?float $salesTotalAmount = null,
        string $salesTotalLabel = 'SALES TOTAL'
    ): void
    {
        $safeFilename = excelXmlNormalizeFileName($filenameBase, 'sales_export') . '.xls';
        $safeSheetName = excelXmlNormalizeSheetName($sheetName, 'Sales Export');
        $safeTotalLabel = trim($totalLabel) !== '' ? $totalLabel : 'TOTAL NET GROSS';
        $safeSalesTotalLabel = trim($salesTotalLabel) !== '' ? $salesTotalLabel : 'SALES TOTAL';

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Pragma: public');
        header('Cache-Control: max-age=0');

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
           . 'xmlns:o="urn:schemas-microsoft-com:office:office" '
           . 'xmlns:x="urn:schemas-microsoft-com:office:excel" '
           . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" '
           . 'xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
        echo "<Styles>\n";
        echo '  <Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="11"/><Borders/>'
           . '<Interior/><NumberFormat/><Protection/></Style>' . "\n";
        echo '  <Style ss:ID="sHeader"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>'
           . '<Interior ss:Color="#0F6F94" ss:Pattern="Solid"/><Borders>'
           . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6E6EE"/>'
           . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6E6EE"/>'
           . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6E6EE"/>'
           . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6E6EE"/>'
           . '</Borders></Style>' . "\n";
        echo '  <Style ss:ID="sText"><Alignment ss:Vertical="Center"/><Borders>'
           . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '</Borders></Style>' . "\n";
        echo '  <Style ss:ID="sInt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><NumberFormat ss:Format="0"/><Borders>'
           . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '</Borders></Style>' . "\n";
        echo '  <Style ss:ID="sMoney"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><NumberFormat ss:Format="#,##0.00"/><Borders>'
           . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '</Borders></Style>' . "\n";
        echo '  <Style ss:ID="sDate"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><NumberFormat ss:Format="yyyy-mm-dd hh:mm"/><Borders>'
           . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/>'
           . '</Borders></Style>' . "\n";
        echo '  <Style ss:ID="sTotalLabel"><Font ss:Bold="1"/><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Interior ss:Color="#EEF7FB" ss:Pattern="Solid"/><Borders>'
           . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/>'
           . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/>'
           . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/>'
           . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/>'
           . '</Borders></Style>' . "\n";
        echo '  <Style ss:ID="sTotalMoney"><Font ss:Bold="1"/><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Interior ss:Color="#EEF7FB" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0.00"/><Borders>'
           . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/>'
           . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/>'
           . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/>'
           . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/>'
           . '</Borders></Style>' . "\n";
        echo "</Styles>\n";

        echo '<Worksheet ss:Name="' . excelXmlEscape($safeSheetName) . '">' . "\n";
        echo "<Table>\n";
        echo '  <Column ss:AutoFitWidth="0" ss:Width="220"/>' . "\n";
        echo '  <Column ss:AutoFitWidth="0" ss:Width="180"/>' . "\n";
        echo '  <Column ss:AutoFitWidth="0" ss:Width="90"/>' . "\n";
        echo '  <Column ss:AutoFitWidth="0" ss:Width="110"/>' . "\n";
        echo '  <Column ss:AutoFitWidth="0" ss:Width="120"/>' . "\n";
        echo '  <Column ss:AutoFitWidth="0" ss:Width="220"/>' . "\n";
        echo '  <Column ss:AutoFitWidth="0" ss:Width="120"/>' . "\n";
        echo '  <Column ss:AutoFitWidth="0" ss:Width="180"/>' . "\n";

        $headers = ['Product Name', 'Color / Specs', 'Quantity', 'Unit Price', 'Total Amount', 'Customer Name', 'Net Gross', 'Date'];
        echo '  <Row ss:AutoFitHeight="0" ss:Height="22">' . "\n";
        foreach ($headers as $header) {
            echo '    <Cell ss:StyleID="sHeader"><Data ss:Type="String">' . excelXmlEscape($header) . "</Data></Cell>\n";
        }
        echo "  </Row>\n";

        foreach ($rows as $row) {
            $productName = (string)($row['product_name'] ?? '');
            $colorSpecs = (string)($row['color_specs'] ?? '');
            $quantity = (int)($row['quantity'] ?? 0);
            $unitPrice = (float)($row['unit_price'] ?? 0);
            $totalAmount = (float)($row['total_amount'] ?? 0);
            $customerName = (string)($row['customer_name'] ?? '');
            $netGross = (float)($row['net_gross'] ?? 0);
            $dateRaw = (string)($row['date'] ?? '');
            $dateValue = excelXmlDateValue($dateRaw);

            echo "  <Row>\n";
            echo '    <Cell ss:StyleID="sText"><Data ss:Type="String">' . excelXmlEscape($productName) . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sText"><Data ss:Type="String">' . excelXmlEscape($colorSpecs) . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sInt"><Data ss:Type="Number">' . $quantity . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sMoney"><Data ss:Type="Number">' . number_format($unitPrice, 2, '.', '') . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sMoney"><Data ss:Type="Number">' . number_format($totalAmount, 2, '.', '') . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sText"><Data ss:Type="String">' . excelXmlEscape($customerName) . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sMoney"><Data ss:Type="Number">' . number_format($netGross, 2, '.', '') . "</Data></Cell>\n";
            if ($dateValue !== null) {
                echo '    <Cell ss:StyleID="sDate"><Data ss:Type="DateTime">' . $dateValue . "</Data></Cell>\n";
            } else {
                echo '    <Cell ss:StyleID="sText"><Data ss:Type="String">' . excelXmlEscape($dateRaw) . "</Data></Cell>\n";
            }
            echo "  </Row>\n";
        }

        if ($salesTotalAmount !== null) {
            echo "  <Row>\n";
            echo '    <Cell ss:StyleID="sText"/>' . "\n";
            echo '    <Cell ss:StyleID="sText"/>' . "\n";
            echo '    <Cell ss:StyleID="sText"/>' . "\n";
            echo '    <Cell ss:StyleID="sTotalLabel"><Data ss:Type="String">' . excelXmlEscape($safeSalesTotalLabel) . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sTotalMoney"><Data ss:Type="Number">' . number_format((float)$salesTotalAmount, 2, '.', '') . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sText"/>' . "\n";
            echo '    <Cell ss:StyleID="sText"/>' . "\n";
            echo '    <Cell ss:StyleID="sText"/>' . "\n";
            echo "  </Row>\n";
        }

        echo "  <Row>\n";
        echo '    <Cell ss:StyleID="sText"/>' . "\n";
        echo '    <Cell ss:StyleID="sText"/>' . "\n";
        echo '    <Cell ss:StyleID="sText"/>' . "\n";
        echo '    <Cell ss:StyleID="sText"/>' . "\n";
        echo '    <Cell ss:StyleID="sText"/>' . "\n";
        echo '    <Cell ss:StyleID="sTotalLabel"><Data ss:Type="String">' . excelXmlEscape($safeTotalLabel) . "</Data></Cell>\n";
        echo '    <Cell ss:StyleID="sTotalMoney"><Data ss:Type="Number">' . number_format($totalNetGross, 2, '.', '') . "</Data></Cell>\n";
        echo '    <Cell ss:StyleID="sText"/>' . "\n";
        echo "  </Row>\n";

        echo "</Table>\n";
        echo '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
           . '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane><ActivePane>2</ActivePane>'
           . '</WorksheetOptions>' . "\n";
        echo "</Worksheet>\n";
        echo "</Workbook>";
    }
}

if (!function_exists('excelXmlOutputInventoryRows')) {
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    function excelXmlOutputInventoryRows(
        string $filenameBase,
        string $sheetName,
        array $rows,
        float $totalCostValue,
        float $totalPriceValue
    ): void
    {
        $safeFilename = excelXmlNormalizeFileName($filenameBase, 'inventory_export') . '.xls';
        $safeSheetName = excelXmlNormalizeSheetName($sheetName, 'Inventory Export');

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Pragma: public');
        header('Cache-Control: max-age=0');

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
           . 'xmlns:o="urn:schemas-microsoft-com:office:office" '
           . 'xmlns:x="urn:schemas-microsoft-com:office:excel" '
           . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" '
           . 'xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
        echo "<Styles>\n";
        echo '  <Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="11"/><Borders/><Interior/><NumberFormat/><Protection/></Style>' . "\n";
        echo '  <Style ss:ID="sHeader"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Interior ss:Color="#0F6F94" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6E6EE"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6E6EE"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6E6EE"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6E6EE"/></Borders></Style>' . "\n";
        echo '  <Style ss:ID="sText"><Alignment ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/></Borders></Style>' . "\n";
        echo '  <Style ss:ID="sInt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><NumberFormat ss:Format="0"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/></Borders></Style>' . "\n";
        echo '  <Style ss:ID="sMoney"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><NumberFormat ss:Format="#,##0.00"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/></Borders></Style>' . "\n";
        echo '  <Style ss:ID="sDate"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><NumberFormat ss:Format="yyyy-mm-dd hh:mm"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E8EEF2"/></Borders></Style>' . "\n";
        echo '  <Style ss:ID="sTotalLabel"><Font ss:Bold="1"/><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Interior ss:Color="#EEF7FB" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/></Borders></Style>' . "\n";
        echo '  <Style ss:ID="sTotalMoney"><Font ss:Bold="1"/><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Interior ss:Color="#EEF7FB" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0.00"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BCD8E7"/></Borders></Style>' . "\n";
        echo "</Styles>\n";

        echo '<Worksheet ss:Name="' . excelXmlEscape($safeSheetName) . '">' . "\n";
        echo "<Table>\n";
        echo '  <Column ss:AutoFitWidth="0" ss:Width="220"/>' . "\n"; // Product
        echo '  <Column ss:AutoFitWidth="0" ss:Width="150"/>' . "\n"; // Category
        echo '  <Column ss:AutoFitWidth="0" ss:Width="140"/>' . "\n"; // Brand
        echo '  <Column ss:AutoFitWidth="0" ss:Width="200"/>' . "\n"; // Color / Specs
        echo '  <Column ss:AutoFitWidth="0" ss:Width="95"/>' . "\n";  // Store
        echo '  <Column ss:AutoFitWidth="0" ss:Width="110"/>' . "\n"; // Stock room
        echo '  <Column ss:AutoFitWidth="0" ss:Width="95"/>' . "\n";  // Total stock
        echo '  <Column ss:AutoFitWidth="0" ss:Width="110"/>' . "\n"; // Unit cost
        echo '  <Column ss:AutoFitWidth="0" ss:Width="110"/>' . "\n"; // Unit price
        echo '  <Column ss:AutoFitWidth="0" ss:Width="140"/>' . "\n"; // Stock value cost
        echo '  <Column ss:AutoFitWidth="0" ss:Width="140"/>' . "\n"; // Stock value price
        echo '  <Column ss:AutoFitWidth="0" ss:Width="180"/>' . "\n"; // Supplier
        echo '  <Column ss:AutoFitWidth="0" ss:Width="140"/>' . "\n"; // Created at
        echo '  <Column ss:AutoFitWidth="0" ss:Width="140"/>' . "\n"; // Updated at

        $headers = [
            'Product Name',
            'Category',
            'Brand',
            'Color / Specs',
            'Store Stock',
            'Stock Room',
            'Total Stock',
            'Unit Cost',
            'Unit Price',
            'Stock Value (Cost)',
            'Stock Value (Price)',
            'Supplier',
            'Created At',
            'Updated At',
        ];
        echo '  <Row ss:AutoFitHeight="0" ss:Height="22">' . "\n";
        foreach ($headers as $header) {
            echo '    <Cell ss:StyleID="sHeader"><Data ss:Type="String">' . excelXmlEscape($header) . "</Data></Cell>\n";
        }
        echo "  </Row>\n";

        foreach ($rows as $row) {
            $createdRaw = (string)($row['created_at'] ?? '');
            $updatedRaw = (string)($row['updated_at'] ?? '');
            $createdValue = excelXmlDateValue($createdRaw);
            $updatedValue = excelXmlDateValue($updatedRaw);

            echo "  <Row>\n";
            echo '    <Cell ss:StyleID="sText"><Data ss:Type="String">' . excelXmlEscape((string)($row['product_name'] ?? '')) . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sText"><Data ss:Type="String">' . excelXmlEscape((string)($row['category_name'] ?? '')) . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sText"><Data ss:Type="String">' . excelXmlEscape((string)($row['brand'] ?? '')) . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sText"><Data ss:Type="String">' . excelXmlEscape((string)($row['color_specs'] ?? '')) . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sInt"><Data ss:Type="Number">' . (int)($row['stock_store'] ?? 0) . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sInt"><Data ss:Type="Number">' . (int)($row['stock_stockroom'] ?? 0) . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sInt"><Data ss:Type="Number">' . (int)($row['total_stock'] ?? 0) . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sMoney"><Data ss:Type="Number">' . number_format((float)($row['unit_cost'] ?? 0), 2, '.', '') . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sMoney"><Data ss:Type="Number">' . number_format((float)($row['unit_price'] ?? 0), 2, '.', '') . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sMoney"><Data ss:Type="Number">' . number_format((float)($row['stock_value_cost'] ?? 0), 2, '.', '') . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sMoney"><Data ss:Type="Number">' . number_format((float)($row['stock_value_price'] ?? 0), 2, '.', '') . "</Data></Cell>\n";
            echo '    <Cell ss:StyleID="sText"><Data ss:Type="String">' . excelXmlEscape((string)($row['supplier'] ?? '')) . "</Data></Cell>\n";
            if ($createdValue !== null) {
                echo '    <Cell ss:StyleID="sDate"><Data ss:Type="DateTime">' . $createdValue . "</Data></Cell>\n";
            } else {
                echo '    <Cell ss:StyleID="sText"><Data ss:Type="String">' . excelXmlEscape($createdRaw) . "</Data></Cell>\n";
            }
            if ($updatedValue !== null) {
                echo '    <Cell ss:StyleID="sDate"><Data ss:Type="DateTime">' . $updatedValue . "</Data></Cell>\n";
            } else {
                echo '    <Cell ss:StyleID="sText"><Data ss:Type="String">' . excelXmlEscape($updatedRaw) . "</Data></Cell>\n";
            }
            echo "  </Row>\n";
        }

        echo "  <Row>\n";
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 1
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 2
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 3
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 4
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 5
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 6
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 7
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 8
        echo '    <Cell ss:StyleID="sTotalLabel"><Data ss:Type="String">TOTAL VALUE (COST BASIS)</Data></Cell>' . "\n"; // 9
        echo '    <Cell ss:StyleID="sTotalMoney"><Data ss:Type="Number">' . number_format($totalCostValue, 2, '.', '') . "</Data></Cell>\n"; // 10
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 11
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 12
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 13
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 14
        echo "  </Row>\n";

        echo "  <Row>\n";
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 1
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 2
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 3
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 4
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 5
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 6
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 7
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 8
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 9
        echo '    <Cell ss:StyleID="sTotalLabel"><Data ss:Type="String">TOTAL VALUE (PRICE BASIS)</Data></Cell>' . "\n"; // 10
        echo '    <Cell ss:StyleID="sTotalMoney"><Data ss:Type="Number">' . number_format($totalPriceValue, 2, '.', '') . "</Data></Cell>\n"; // 11
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 12
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 13
        echo '    <Cell ss:StyleID="sText"/>' . "\n"; // 14
        echo "  </Row>\n";

        echo "</Table>\n";
        echo '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
           . '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane><ActivePane>2</ActivePane>'
           . '</WorksheetOptions>' . "\n";
        echo "</Worksheet>\n";
        echo "</Workbook>";
    }
}


