<?php
declare(strict_types=1);

/**
 * Pure PHP XLSX writer - no dependencies
 * Generates minimal valid Excel 2007+ files
 */

function xlsx_export(string $filename, array $headers, array $rows, string $title = ''): void
{
    // Collect unique strings
    $strings = [];
    $string_map = [];
    foreach ($rows as $row) {
        foreach ($row as $cell) {
            if (is_string($cell) && !is_numeric($cell)) {
                if (!isset($string_map[$cell])) {
                    $string_map[$cell] = count($strings);
                    $strings[] = $cell;
                }
            }
        }
    }
    foreach ($headers as $h) {
        if (!isset($string_map[$h])) {
            $string_map[$h] = count($strings);
            $strings[] = $h;
        }
    }

    // Build OOXML files
    $sheet_data = xlsx_sheet($headers, $rows, $string_map);
    $shared_strings = xlsx_shared_strings($strings);
    
    // Create ZIP
    $zip = [];
    xlsx_add_file($zip, '[Content_Types].xml', xlsx_content_types());
    xlsx_add_file($zip, '_rels/.rels', xlsx_rels());
    xlsx_add_file($zip, 'xl/workbook.xml', xlsx_workbook());
    xlsx_add_file($zip, 'xl/_rels/workbook.xml.rels', xlsx_workbook_rels());
    xlsx_add_file($zip, 'xl/worksheets/sheet1.xml', $sheet_data);
    xlsx_add_file($zip, 'xl/sharedStrings.xml', $shared_strings);
    
    $output = xlsx_finalize($zip);
    
    // Output
    $ascii = preg_replace('/[^a-zA-Z0-9_.\\-]/', '_', $filename);
    $encoded = rawurlencode($filename);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''{$encoded}");
    header('Content-Length: ' . strlen($output));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $output;
}

function xlsx_sheet(array $headers, array $rows, array $string_map): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetData>';
    
    // Header row
    $xml .= '<row r="1">';
    foreach ($headers as $idx => $header) {
        $col = xlsx_col_letter($idx);
        $xml .= '<c r="' . $col . '1" t="s"><v>' . $string_map[$header] . '</v></c>';
    }
    $xml .= '</row>';
    
    // Data rows
    foreach ($rows as $r_idx => $row) {
        $row_num = $r_idx + 2;
        $xml .= '<row r="' . $row_num . '">';
        foreach ($row as $c_idx => $cell) {
            $col = xlsx_col_letter($c_idx);
            $ref = $col . $row_num;
            
            if (is_numeric($cell)) {
                $xml .= '<c r="' . $ref . '"><v>' . xlsx_escape($cell) . '</v></c>';
            } else if (is_string($cell)) {
                $xml .= '<c r="' . $ref . '" t="s"><v>' . $string_map[$cell] . '</v></c>';
            }
        }
        $xml .= '</row>';
    }
    
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

function xlsx_shared_strings(array $strings): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $str) {
        $xml .= '<si><t>' . xlsx_escape($str) . '</t></si>';
    }
    $xml .= '</sst>';
    return $xml;
}

function xlsx_content_types(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' .
        '</Types>';
}

function xlsx_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>';
}

function xlsx_workbook(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>';
}

function xlsx_workbook_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>' .
        '</Relationships>';
}

function xlsx_col_letter(int $idx): string
{
    $letter = '';
    $idx++;
    while ($idx > 0) {
        $idx--;
        $letter = chr(65 + ($idx % 26)) . $letter;
        $idx = (int) ($idx / 26);
    }
    return $letter;
}

function xlsx_escape(string $str): string
{
    // Strip control chars that Excel rejects
    $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str);
    return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_add_file(array &$zip, string $path, string $content): void
{
    $zip[] = [
        'path' => $path,
        'content' => $content,
        'size' => strlen($content),
        'crc32' => crc32($content),
    ];
}

function xlsx_finalize(array $files): string
{
    $output = '';
    $offset = 0;
    $central_dir = '';
    
    foreach ($files as $file) {
        $path = $file['path'];
        $content = $file['content'];
        $size = $file['size'];
        $crc = $file['crc32'];
        
        // Local file header
        $local_header = pack('VvvvvvVVVvv',
            0x04034b50,  // signature
            20,          // version
            0,           // flags
            0,           // compression (stored)
            0,           // mod time
            0,           // mod date
            $crc,        // crc32
            $size,       // compressed size
            $size,       // uncompressed size
            strlen($path), // filename length
            0            // extra field length
        );
        
        $output .= $local_header . $path . $content;
        
        // Central directory entry
        $central_dir .= pack('VvvvvvvVVVvvvvvVV',
            0x02014b50,  // signature
            20,          // version made by
            20,          // version needed
            0,           // flags
            0,           // compression
            0,           // mod time
            0,           // mod date
            $crc,        // crc32
            $size,       // compressed size
            $size,       // uncompressed size
            strlen($path), // filename length
            0,           // extra field length
            0,           // comment length
            0,           // disk number
            0,           // internal attr
            0,           // external attr
            $offset      // local header offset
        );
        $central_dir .= $path;
        
        $offset += strlen($local_header) + strlen($path) + $size;
    }
    
    // End of central directory
    $end_of_central = pack('VvvvvVVv',
        0x06054b50,          // signature
        0,                   // disk number
        0,                   // disk with central dir
        count($files),       // entries on this disk
        count($files),       // total entries
        strlen($central_dir), // central dir size
        $offset,             // central dir offset
        0                    // comment length
    );
    
    return $output . $central_dir . $end_of_central;
}
