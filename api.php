<?php
declare(strict_types=1);

// Keep PHP warnings/fatal pages out of an endpoint that promises JSON.
// Errors are still written to the configured PHP error log.
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

use Smalot\PdfParser\Parser;
use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $status = 200): never
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json === false
        ? '{"ok":false,"error":"The server could not encode its response."}'
        : $json;
    exit;
}

set_exception_handler(function (Throwable $error): never {
    error_log((string) $error);
    respond(['ok' => false, 'error' => 'The extraction service encountered an internal error. Check the PHP error log for details.'], 500);
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log(sprintf('%s in %s:%d', $error['message'], $error['file'], $error['line']));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode(['ok' => false, 'error' => 'The extraction service encountered a fatal error. Check the PHP error log for details.']);
});

require_once __DIR__ . '/vendor/autoload.php';

function normalizeText(string $value): string
{
    $value = strip_tags($value);
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function normalizeDate(string $value): ?string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'm/d/Y', 'm-d-Y', 'n/j/Y', 'n-j-Y', 'd/m/y', 'd-m-y', 'n/j/y', 'n-j-y', 'd.M.Y', 'd-M-Y', 'd-M-y', 'M d, Y', 'd M Y', 'd M y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }
    return null;
}

function isDateToken(string $value): bool
{
    return normalizeDate($value) !== null;
}

function cleanCell(string $value): string
{
    return trim(preg_replace('/[ \t]+/u', ' ', str_replace("\xC2\xA0", ' ', $value)) ?? $value);
}

function redactedContext(string $text, string $identifier, int $radius = 2): array
{
    $lines = preg_split('/\R/u', $text) ?: [];
    $hit = 0;
    foreach ($lines as $index => $line) {
        if (str_contains($line, $identifier)) {
            $hit = $index;
            break;
        }
    }
    $result = [];
    for ($i = max(0, $hit - $radius); $i <= min(count($lines) - 1, $hit + $radius); $i++) {
        $line = cleanCell($lines[$i]);
        $line = preg_replace_callback('/\d{7}|\d{9}/', fn(array $m): string => $m[0] === $identifier ? $m[0] : '[NUMBER]', $line) ?? $line;
        $result[] = mb_substr($line, 0, 180, 'UTF-8');
    }
    return $result;
}

function positionedLines(object $page): array
{
    $items = [];
    foreach ($page->getDataTm() as $index => $entry) {
        $matrix = $entry[0] ?? [];
        $text = cleanCell((string) ($entry[1] ?? ''));
        if ($text === '' || !isset($matrix[4], $matrix[5])) {
            continue;
        }
        $items[] = ['x' => (float) $matrix[4], 'y' => (float) $matrix[5], 'text' => $text, 'index' => $index];
    }
    usort($items, fn(array $a, array $b): int => abs($a['y'] - $b['y']) > 2.5 ? ($b['y'] <=> $a['y']) : ($a['x'] <=> $b['x']));
    $lines = [];
    foreach ($items as $item) {
        $lineIndex = null;
        foreach ($lines as $index => $line) {
            if (abs($line['y'] - $item['y']) <= 2.5) {
                $lineIndex = $index;
                break;
            }
        }
        if ($lineIndex === null) {
            $lines[] = ['y' => $item['y'], 'items' => [$item]];
        } else {
            $lines[$lineIndex]['items'][] = $item;
        }
    }
    usort($lines, fn(array $a, array $b): int => $b['y'] <=> $a['y']);
    foreach ($lines as &$line) {
        usort($line['items'], fn(array $a, array $b): int => $a['x'] <=> $b['x']);
        $line['text'] = cleanCell(implode(' ', array_column($line['items'], 'text')));
    }
    unset($line);
    return $lines;
}

function headerColumns(array $lines): ?array
{
    foreach ($lines as $lineIndex => $line) {
        $anchors = [];
        foreach ($line['items'] as $item) {
            $label = strtolower(preg_replace('/[^a-z\/]/i', '', $item['text']) ?? '');
            if (preg_match('/^w\/?o$/i', $label)) $anchors['wo'] = $item['x'];
            elseif (preg_match('/^a\/?c$/i', $label)) $anchors['ac'] = $item['x'];
            elseif ($label === 'date') $anchors['date'] = $item['x'];
            elseif ($label === 'ata') $anchors['ata'] = $item['x'];
            elseif (str_starts_with($label, 'description')) $anchors['description'] = $item['x'];
        }
        if (count($anchors) === 5) {
            $ordered = ['wo', 'ac', 'date', 'ata', 'description'];
            foreach ($ordered as $i => $name) {
                if ($i > 0 && $anchors[$name] <= $anchors[$ordered[$i - 1]]) continue 2;
            }
            $descriptionEnd = null;
            $ignoredColumns = 0;
            foreach ($line['items'] as $item) {
                if ($item['x'] > $anchors['description']) {
                    $ignoredColumns++;
                    if ($descriptionEnd === null || $item['x'] < $descriptionEnd) {
                        $descriptionEnd = $item['x'];
                    }
                }
            }
            return ['line' => $lineIndex, 'anchors' => $anchors, 'bounds' => [
                ($anchors['wo'] + $anchors['ac']) / 2,
                ($anchors['ac'] + $anchors['date']) / 2,
                ($anchors['date'] + $anchors['ata']) / 2,
                ($anchors['ata'] + $anchors['description']) / 2,
                $descriptionEnd === null ? null : ($anchors['description'] + $descriptionEnd) / 2,
            ], 'ignoredColumns' => $ignoredColumns];
        }
    }
    return null;
}

function lineCells(array $line, array $bounds): array
{
    $cells = array_fill(0, 5, '');
    foreach ($line['items'] as $item) {
        $column = 0;
        while ($column < 4 && $item['x'] >= $bounds[$column]) $column++;
        if ($column === 4 && $bounds[4] !== null && $item['x'] >= $bounds[4]) continue;
        $cells[$column] = cleanCell($cells[$column] . ' ' . $item['text']);
    }
    return $cells;
}

function finishLayoutRecord(array $raw, int $page, array &$successful, array &$partial, array &$rejected): void
{
    $identifier = preg_replace('/\s+/u', '', $raw['cells'][0]);
    if (!preg_match('/^(\d{7}|\d{9})$/', $identifier)) {
        $rejected[] = ['page' => $page, 'row' => $raw['row'], 'reason' => 'Missing W/O identifier or column alignment failure.'];
        return;
    }
    $dateRaw = cleanCell($raw['cells'][2]);
    $date = normalizeDate($dateRaw);
    $reasons = [];
    if ($date === null) $reasons[] = $dateRaw === '' ? 'Missing date.' : 'Invalid date.';
    if ($raw['cells'][4] === '') $reasons[] = 'Missing description.';
    $record = [
        'identifier' => $identifier,
        'type' => strlen($identifier) === 7 ? 'Sequence' : 'ELB WO',
        'ac' => cleanCell($raw['cells'][1]),
        'date' => $date ?? $dateRaw,
        'ata' => cleanCell($raw['cells'][3]),
        'description' => cleanCell($raw['cells'][4]),
        'page' => $page,
        'row' => $raw['row'],
        'sourceText' => trim(implode("\n", $raw['source'])),
        'valid' => $reasons === [],
        'reviewReasons' => $reasons,
    ];
    if ($reasons === []) $successful[] = $record;
    else $partial[] = $record;
}

function parseLayoutPage(object $pageObject, string $text, int $page): array
{
    $lines = positionedLines($pageObject);
    $header = headerColumns($lines);
    if ($header === null) return [[], [], [['page' => $page, 'row' => null, 'reason' => 'Table header/column alignment not found.']], ['mode' => 'text-order-ambiguous']];
    $successful = $partial = $rejected = [];
    $current = null;
    foreach ($lines as $lineIndex => $line) {
        $cells = lineCells($line, $header['bounds']);
        $compactWo = preg_replace('/\s+/u', '', $cells[0]);
        $isHeader = preg_match('/w\s*\/?\s*o/i', $line['text']) && preg_match('/description/i', $line['text']);
        if ($isHeader) continue;
        $isIdentifier = preg_match('/^(\d{7}|\d{9})$/', $compactWo) === 1;
        if ($isIdentifier) {
            if ($current !== null) finishLayoutRecord($current, $page, $successful, $partial, $rejected);
            $current = ['cells' => $cells, 'source' => [$line['text']], 'row' => $lineIndex + 1];
        } elseif ($current !== null) {
            if ($cells[0] !== '' && preg_match('/\d{7}|\d{9}/', $cells[0])) {
                finishLayoutRecord($current, $page, $successful, $partial, $rejected);
                $rejected[] = ['page' => $page, 'row' => $lineIndex + 1, 'reason' => 'Ambiguous W/O column content; record boundary not inferred.'];
                $current = null;
                continue;
            }
            foreach ($cells as $column => $cell) {
                if ($cell !== '') $current['cells'][$column] = cleanCell($current['cells'][$column] . ($column === 4 ? "\n" : ' ') . $cell);
            }
            $current['source'][] = $line['text'];
        }
    }
    if ($current !== null) finishLayoutRecord($current, $page, $successful, $partial, $rejected);
    return [$successful, $partial, $rejected, ['mode' => 'positioned', 'headerLine' => $header['line'] + 1, 'ignoredColumns' => $header['ignoredColumns']]];
}

function parseTextOrderPage(string $text, int $page): array
{
    $datePattern = '(?:\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}|\d{1,2}[-.]?[A-Za-z]{3}[-.]?\d{2,4})';
    $identifierPattern = '(?:\d{7}|\d{9})';
    $pattern = '/(?<!\d)(?<identifier>' . $identifierPattern . ')(?!\d)\s+'
        . '(?<ac>\S+)\s+(?<date>' . $datePattern . ')\s+(?<ata>\S+)\s+'
        . '(?<description>.+?)(?=\s+' . $identifierPattern . '\s+\S+\s+' . $datePattern . '\s+|\z)/isu';
    preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
    $records = [];
    $rejected = [];
    foreach ($matches as $index => $match) {
        $date = normalizeDate(cleanCell($match['date']));
        $description = cleanCell(preg_replace('/\s+/u', ' ', $match['description']) ?? $match['description']);
        if ($date === null || $description === '') {
            $rejected[] = ['page' => $page, 'row' => $index + 1, 'reason' => 'Text-order fallback found a row with an invalid date or empty description.'];
            continue;
        }
        $identifier = $match['identifier'];
        $records[] = [
            'identifier' => $identifier,
            'type' => strlen($identifier) === 7 ? 'Sequence' : 'ELB WO',
            'ac' => cleanCell($match['ac']),
            'date' => $date,
            'ata' => cleanCell($match['ata']),
            'description' => $description,
            'page' => $page,
            'row' => $index + 1,
            'sourceText' => cleanCell($match[0]),
            'valid' => true,
            'reviewReasons' => [],
        ];
    }
    return [$records, $rejected];
}

function splitMetadataRows(object $page): array
{
    $rows = [];
    foreach (positionedLines($page) as $lineIndex => $line) {
        $items = array_values(array_filter(array_column($line['items'], 'text'), static fn(string $value): bool => $value !== ''));
        if (count($items) !== 4) continue;
        $identifier = preg_replace('/\s+/u', '', $items[0]);
        if (!preg_match('/^(\d{7}|\d{9})$/', $identifier)) continue;
        $date = normalizeDate($items[2]);
        if ($date === null) continue;
        $rows[] = [
            'identifier' => $identifier,
            'ac' => cleanCell($items[1]),
            'date' => $date,
            'ata' => cleanCell($items[3]),
            'row' => $lineIndex + 1,
            'sourceText' => $line['text'],
        ];
    }
    return $rows;
}

function splitDescriptionRows(object $page): array
{
    $rows = [];
    foreach ($page->getDataTm() as $entry) {
        $text = (string) ($entry[1] ?? '');
        if (strcasecmp(trim(strip_tags($text)), 'Description') === 0) continue;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rows[] = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
    }
    return $rows;
}

function parseSplitLayoutDocument(array $pages): array
{
    $metadataPages = [];
    foreach ($pages as $pageIndex => $page) {
        $rows = splitMetadataRows($page);
        if ($rows === []) break;
        $metadataPages[] = ['page' => $pageIndex + 1, 'rows' => $rows];
    }
    if ($metadataPages === []) return [[], []];

    $records = [];
    $rejected = [];
    $descriptionStart = count($metadataPages);
    foreach ($metadataPages as $offset => $metadataPage) {
        $descriptionPage = $pages[$descriptionStart + $offset] ?? null;
        if ($descriptionPage === null) return [[], []];
        $descriptions = splitDescriptionRows($descriptionPage);
        if (count($descriptions) !== count($metadataPage['rows'])) return [[], []];
        foreach ($metadataPage['rows'] as $rowIndex => $row) {
            $description = $descriptions[$rowIndex];
            if ($description === '') {
                $rejected[] = ['page' => $metadataPage['page'], 'row' => $row['row'], 'reason' => 'Missing description on the paired split-layout page.'];
                continue;
            }
            $identifier = $row['identifier'];
            $records[] = [
                'identifier' => $identifier,
                'type' => strlen($identifier) === 7 ? 'Sequence' : 'ELB WO',
                'ac' => $row['ac'],
                'date' => $row['date'],
                'ata' => $row['ata'],
                'description' => $description,
                'page' => $metadataPage['page'],
                'row' => $row['row'],
                'sourceText' => $row['sourceText'] . "\n" . $description,
                'valid' => true,
                'reviewReasons' => [],
            ];
        }
    }
    return [$records, $rejected];
}

function diagnostics(string $fileType, int $characters, int $detected, array $records, array $rejected, array $warnings = [], int $partialRows = 0, array $development = []): array
{
    return [
        'fileType' => $fileType,
        'characters' => $characters,
        'detectedIdentifiers' => $detected,
        'parsedRows' => count($records),
        'successfulRows' => count($records) - $partialRows,
        'partialRows' => $partialRows,
        'rejectedRows' => count($rejected),
        'sampleRejected' => array_slice($rejected, 0, 5),
        'warnings' => array_slice($warnings, 0, 10),
        'development' => $development,
    ];
}

function extractPdf(string $path): array
{
    if (!class_exists(Parser::class)) {
        respond(['ok' => false, 'error' => 'PDF extraction is unavailable. Run composer install first.'], 503);
    }
    try {
        $pdf = (new Parser())->parseFile($path);
    } catch (Throwable $error) {
        respond(['ok' => false, 'error' => 'The PDF could not be read. It may be encrypted, corrupt, or scanned.'], 422);
    }
    $pages = $pdf->getPages();
    [$splitRecords, $splitRejected] = parseSplitLayoutDocument($pages);
    if ($splitRecords !== []) {
        $allText = implode("\n", array_map(static fn(object $page): string => $page->getText(), $pages));
        $characters = mb_strlen($allText, 'UTF-8');
        preg_match_all('/(?<!\d)(\d{7}|\d{9})(?!\d)/', $allText, $identifierMatches);
        return [$splitRecords, diagnostics('pdf', $characters, count($identifierMatches[1] ?? []), $splitRecords, $splitRejected, ['Split PDF columns were paired by page and row position.'])];
    }
    $records = [];
    $partial = [];
    $rejected = [];
    $warnings = [];
    $characters = 0;
    $detected = 0;
    $development = [];
    $isDevelopment = filter_var(getenv('WOM_DEV_DIAGNOSTICS') ?: 'false', FILTER_VALIDATE_BOOL);
    foreach ($pages as $pageNumber => $page) {
        $text = $page->getText();
        $characters += mb_strlen($text, 'UTF-8');
        preg_match_all('/(?<!\d)(\d{7}|\d{9})(?!\d)/', $text, $identifierMatches);
        $detected += count($identifierMatches[1] ?? []);
        [$pageRecords, $pagePartial, $pageRejected, $pageInfo] = parseLayoutPage($page, $text, $pageNumber + 1);
        if ($pageRecords === [] && $pagePartial === []) {
            [$fallbackRecords, $fallbackRejected] = parseTextOrderPage($text, $pageNumber + 1);
            if ($fallbackRecords !== []) {
                $pageRecords = $fallbackRecords;
                $pageRejected = $fallbackRejected;
                $pageInfo = ['mode' => 'text-order-fallback'];
            }
        }
        $records = array_merge($records, $pageRecords, $pagePartial);
        $partial = array_merge($partial, $pagePartial);
        $rejected = array_merge($rejected, $pageRejected);
        if ($isDevelopment) {
            $sampleId = $identifierMatches[1][0] ?? null;
            $rawLines = preg_split('/\R/u', $text) ?: [];
            $development[] = [
                'page' => $pageNumber + 1,
                'lineCount' => count($rawLines),
                'layout' => $pageInfo,
                // Returned only to the uploading developer when explicitly enabled;
                // never written to PHP/server logs.
                'firstLines' => array_map(fn(string $line): string => mb_substr($line, 0, 500, 'UTF-8'), array_slice($rawLines, 0, 50)),
                'identifierContext' => $sampleId ? redactedContext($text, $sampleId) : [],
            ];
        }
    }
    if ($characters === 0) {
        respond(['ok' => false, 'error' => 'No text was extracted from this PDF. It may be scanned or image-only and require OCR.', 'diagnostics' => diagnostics('pdf', 0, 0, [], [], $warnings)], 422);
    }
    if ($records === []) {
        respond(['ok' => false, 'error' => 'Text was extracted, but the W/O table structure could not be reconstructed reliably.', 'diagnostics' => diagnostics('pdf', $characters, $detected, [], $rejected, $warnings, 0, $development)], 422);
    }
    return [$records, diagnostics('pdf', $characters, $detected, $records, $rejected, $warnings, count($partial), $development)];
}

function extractXlsx(string $path): array
{
    $records = [];
    $rejected = [];
    try {
        $workbook = IOFactory::load($path);
    } catch (Throwable $error) {
        respond(['ok' => false, 'error' => 'The XLSX workbook could not be read.', 'diagnostics' => diagnostics('xlsx', 0, 0, [], [$error->getMessage()])], 422);
    }
    $sheet = $workbook->getActiveSheet();
    $header = [];
    foreach ($sheet->getRowIterator() as $rowNumber => $row) {
        $values = [];
        foreach ($row->getCellIterator() as $cell) {
            $values[$cell->getColumn()] = trim((string) $cell->getFormattedValue());
        }
        if ($rowNumber === 1) {
            $header = array_map(fn(string $value): string => strtolower(trim($value)), $values);
            continue;
        }
        $columns = array_flip($header);
        $woColumn = $columns['w/o'] ?? $columns['wo'] ?? 'A';
        $acColumn = $columns['a/c'] ?? $columns['ac'] ?? 'B';
        $dateColumn = $columns['date'] ?? 'C';
        $ataColumn = $columns['ata'] ?? 'D';
        $descriptionColumn = $columns['description'] ?? 'E';
        $identifier = trim((string) ($values[$woColumn] ?? ''));
        if (!preg_match('/^\d{7}$|^\d{9}$/', $identifier)) {
            if ($identifier !== '') {
                $rejected[] = "Row {$rowNumber}: W/O '{$identifier}' is not exactly 7 or 9 digits.";
            }
            continue;
        }
        $description = (string) ($values[$descriptionColumn] ?? '');
        $description = html_entity_decode((string) preg_replace('/<br\s*\/?>/i', "\n", $description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $dateValue = (string) ($values[$dateColumn] ?? '');
        $date = normalizeDate($dateValue);
        if ($date === null && trim($dateValue) !== '') {
            $excelDate = $sheet->getCell($dateColumn . $rowNumber)->getValue();
            if (is_numeric($excelDate)) {
                $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $excelDate)->format('Y-m-d');
            }
        }
        if ($date === null) {
            $rejected[] = "Row {$rowNumber}: invalid or missing Date.";
            continue;
        }
        $records[] = [
            'identifier' => (string) $identifier,
            'type' => strlen($identifier) === 7 ? 'Sequence' : 'ELB WO',
            'ac' => (string) ($values[$acColumn] ?? ''),
            'date' => $date,
            'ata' => (string) ($values[$ataColumn] ?? ''),
            'description' => trim($description),
            'page' => null,
            'row' => $rowNumber,
            'valid' => true,
        ];
    }
    $characters = array_sum(array_map(
        fn(array $record): int => mb_strlen(implode(' ', [
            $record['identifier'],
            $record['ac'],
            $record['date'],
            $record['ata'],
            $record['description'],
        ]), 'UTF-8'),
        $records
    ));
    $highestRow = min(100, $sheet->getHighestDataRow());
    $highestColumn = min(30, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
    $previewRows = [];
    for ($rowNumber = 1; $rowNumber <= $highestRow; $rowNumber++) {
        $previewRow = [];
        for ($columnNumber = 1; $columnNumber <= $highestColumn; $columnNumber++) {
            $previewRow[] = (string) $sheet->getCell([$columnNumber, $rowNumber])->getFormattedValue();
        }
        $previewRows[] = $previewRow;
    }
    $preview = [
        'sheet' => $sheet->getTitle(),
        'rows' => $previewRows,
        'totalRows' => $sheet->getHighestDataRow(),
        'totalColumns' => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
        'truncated' => $sheet->getHighestDataRow() > $highestRow || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > $highestColumn,
    ];
    return [$records, diagnostics('xlsx', $characters, count($records) + count($rejected), $records, $rejected), $preview];
}

function similarity(string $left, string $right): float
{
    similar_text(normalizeText($left), normalizeText($right), $percent);
    return round((float) $percent, 1);
}

function makeMatch(string $id, ?string $sequence, ?string $elb, float $score, string $status, string $explanation): array
{
    return compact('id', 'sequence', 'elb', 'score', 'status', 'explanation');
}

function matchRecords(array $records): array
{
    $sequences = array_values(array_filter($records, fn(array $r): bool => $r['type'] === 'Sequence'));
    $elbs = array_values(array_filter($records, fn(array $r): bool => $r['type'] === 'ELB WO'));
    $usedElbs = [];
    $matches = [];

    foreach ($sequences as $sequence) {
        $direct = array_values(array_filter($elbs, fn(array $elb): bool => !isset($usedElbs[$elb['identifier']]) && preg_match('/(?<!\d)' . preg_quote($sequence['identifier'], '/') . '(?!\d)/', $elb['description']) === 1));
        if (count($direct) === 1) {
            $candidate = $direct[0];
            $usedElbs[$candidate['identifier']] = true;
            $matches[] = makeMatch('m-' . $sequence['identifier'], $sequence['identifier'], $candidate['identifier'], 100, 'Confirmed', 'Explicit 7-digit Sequence reference found in the ELB WO description with no conflicting reference.');
            continue;
        }
        $candidates = [];
        foreach ($elbs as $elb) {
            if (isset($usedElbs[$elb['identifier']])) {
                continue;
            }
            $textScore = similarity($sequence['description'], $elb['description']);
            $dateBonus = $sequence['date'] !== '' && $sequence['date'] === $elb['date'] ? 15 : 0;
            $score = min(99, round($textScore * 0.85 + $dateBonus, 1));
            $candidates[] = ['elb' => $elb, 'score' => $score, 'reason' => sprintf('Description similarity %.1f%%; date %s.', $textScore, $dateBonus ? 'matches' : 'differs')];
        }
        usort($candidates, fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $candidates[0] ?? null;
        $secondScore = $candidates[1]['score'] ?? 0;
        if ($best !== null && $best['score'] >= 95) {
            $usedElbs[$best['elb']['identifier']] = true;
            $matches[] = makeMatch('m-' . $sequence['identifier'], $sequence['identifier'], $best['elb']['identifier'], $best['score'], 'Confirmed', $best['reason'] . ' Automatically matched because the score is at least 95%.');
        } elseif ($best !== null && $best['score'] >= 68 && $best['score'] - $secondScore >= 8) {
            $matches[] = makeMatch('m-' . $sequence['identifier'], $sequence['identifier'], $best['elb']['identifier'], $best['score'], 'Needs Review', $best['reason'] . ' Review is required; similarity is not proof of identity.');
        } else {
            $matches[] = makeMatch('m-' . $sequence['identifier'], $sequence['identifier'], $best['elb']['identifier'] ?? null, $best['score'] ?? 0, 'Unmatched', $best ? 'Multiple or insufficient candidates; manual review is required.' : 'No ELB WO candidate was found.');
        }
    }
    foreach ($elbs as $elb) {
        if (!array_key_exists($elb['identifier'], $usedElbs) && !in_array($elb['identifier'], array_column($matches, 'elb'), true)) {
            $matches[] = makeMatch('m-elb-' . $elb['identifier'], null, $elb['identifier'], 0, 'Unmatched', 'ELB WO has no confident Sequence candidate.');
        }
    }
    return $matches;
}

// Allows parser functions to be exercised without dispatching an HTTP response.
if (defined('WOM_API_LIBRARY_ONLY') && WOM_API_LIBRARY_ONLY === true) {
    return;
}

$action = $_GET['action'] ?? '';
if ($action === 'extract') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
            respond(['ok' => false, 'error' => 'The upload is larger than the PHP server allows. Increase upload_max_filesize and post_max_size, then restart PHP.'], 413);
        }
        respond(['ok' => false, 'error' => 'Upload one PDF or XLSX file to extract.'], 400);
    }
    $file = $_FILES['file'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file['error'] !== UPLOAD_ERR_OK || !in_array($extension, ['pdf', 'xlsx'], true)) {
        respond(['ok' => false, 'error' => 'Only valid PDF or XLSX files are accepted.'], 422);
    }
    if ($extension === 'pdf' && mime_content_type($file['tmp_name']) !== 'application/pdf') {
        respond(['ok' => false, 'error' => 'The uploaded file is not a valid PDF.', 'diagnostics' => diagnostics('pdf', 0, 0, [], ['MIME type validation failed.'])], 422);
    }
    [$records, $diagnostics, $preview] = $extension === 'xlsx'
        ? extractXlsx($file['tmp_name'])
        : [...extractPdf($file['tmp_name']), null];
    if ($records === []) {
        $message = $diagnostics['characters'] === 0 && $extension === 'pdf'
            ? 'No text was extracted from this PDF. It may be scanned or image-only and require OCR.'
            : 'Text was extracted, but zero W/O rows matched the expected table structure.';
        respond(['ok' => false, 'error' => $message, 'diagnostics' => $diagnostics], 422);
    }
    respond(['ok' => true, 'records' => $records, 'diagnostics' => $diagnostics, 'preview' => $preview]);
}
if ($action === 'match') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input['records'] ?? null)) {
        respond(['ok' => false, 'error' => 'No extracted records were supplied.'], 400);
    }
    respond(['ok' => true, 'matches' => matchRecords($input['records'])]);
}
respond(['ok' => false, 'error' => 'Unknown API action.'], 404);
