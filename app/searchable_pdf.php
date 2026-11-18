<?php
declare(strict_types=1);

/**
 * Builds a text-based, searchable PDF report without an external PDF package.
 * Text is encoded with Windows-1252 for the built-in PDF fonts.
 */
function coachingPdfText(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text) ?? $text;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if (is_string($converted)) {
            return $converted;
        }
    }
    $text = strtr($text, [
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        '€' => 'EUR', '–' => '-', '—' => '-', '’' => "'", '“' => '"', '”' => '"',
    ]);
    return preg_replace('/[^\x20-\x7E\n]/', '?', $text) ?? $text;
}

function coachingPdfEscape(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function coachingSearchablePdf(array $records, string $clientName): string
{
    $lines = [
        '# Coaching Tool - Klienten-Datenexport',
        '',
        'Klient: ' . $clientName,
        'Erstellt am: ' . date('d.m.Y H:i'),
        '',
        'Der Bericht enthält die Klienten- und Coaching-Datensätze als durchsuchbaren Text.',
        'Originaldateien und Anhänge sind im vollständigen ZIP-Datenexport enthalten.',
        '',
        '## Klientendaten',
    ];
    $tableLabels = [
        'klienten' => 'Klientendaten',
        'coaching_auftraege' => 'Coaching-Aufträge',
        'termine' => 'Termine',
        'coaching_sitzungsnotizen' => 'Private Sitzungsnotizen',
        'coaching_ziele' => 'Ziele',
        'coaching_aufgaben' => 'Aufgaben',
        'coaching_nachrichten' => 'Nachrichten',
        'coaching_klientendokumente' => 'Dokumente',
        'coaching_dokumentdateien' => 'Dokumentdateien',
        'coaching_rechnungen' => 'Rechnungen',
        'coaching_portal_zugaenge' => 'Coachee-Zugang',
    ];
    $sensitiveField = '/pass|password|token|secret|hash|speicher_schluessel|^ip_adresse$|ip_address/i';

    foreach ($records as $table => $rows) {
        if ($table === '_export' || !is_array($rows) || $rows === []) {
            continue;
        }
        $heading = $tableLabels[$table] ?? ucwords(str_replace('_', ' ', (string) $table));
        $lines[] = '## ' . $heading;
        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (count($rows) > 1) {
                $lines[] = 'Datensatz ' . ((int) $rowIndex + 1);
            }
            foreach ($row as $field => $value) {
                if (preg_match($sensitiveField, (string) $field) === 1 || is_resource($value)) {
                    continue;
                }
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                } elseif (is_bool($value)) {
                    $value = $value ? 'Ja' : 'Nein';
                } else {
                    $value = (string) $value;
                }
                $label = ucwords(str_replace('_', ' ', (string) $field));
                $value = coachingPdfText((string) $value);
                foreach (explode("\n", $value) as $valueLine) {
                    $wrapped = wordwrap($label . ': ' . $valueLine, 94, "\n", true);
                    foreach (explode("\n", $wrapped) as $wrappedLine) {
                        $lines[] = $wrappedLine;
                        if (count($lines) > 20000) {
                            throw new RuntimeException('Für diese Klientenakte ist der PDF-Bericht zu umfangreich. Bitte nutze den vollständigen ZIP-Datenexport.');
                        }
                        $label = '  ';
                    }
                }
            }
            $lines[] = '';
        }
    }

    $linesPerPage = 48;
    $pages = array_chunk($lines, $linesPerPage);
    if ($pages === []) {
        $pages = [['Kein exportierbarer Inhalt vorhanden.']];
    }

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $pageReferences = [];
    $nextObject = 5;

    foreach ($pages as $pageNumber => $pageLines) {
        $pageObject = $nextObject++;
        $contentObject = $nextObject++;
        $pageReferences[] = $pageObject . ' 0 R';
        $stream = '';
        $y = 790;
        foreach ($pageLines as $line) {
            $line = (string) $line;
            $isTitle = str_starts_with($line, '# ');
            $isHeading = str_starts_with($line, '## ');
            if ($isTitle || $isHeading) {
                $line = substr($line, $isTitle ? 2 : 3);
            }
            $font = $isTitle || $isHeading ? 'F2' : 'F1';
            $fontSize = $isTitle ? 15 : ($isHeading ? 11 : 9);
            $stream .= 'BT /' . $font . ' ' . $fontSize . ' Tf 1 0 0 1 48 ' . $y . ' Tm (' . coachingPdfEscape($line) . ") Tj ET\n";
            $y -= $isTitle ? 20 : ($isHeading ? 17 : 14);
        }
        $stream .= 'BT /F1 8 Tf 1 0 0 1 48 24 Tm (' . coachingPdfEscape('Klienten-Datenexport - Seite ' . ($pageNumber + 1) . ' von ' . count($pages)) . ") Tj ET\n";
        $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentObject . ' 0 R >>';
        $objects[$contentObject] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences) . '] /Count ' . count($pageReferences) . ' >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $lastObject = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($lastObject + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $lastObject; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
    }
    $pdf .= "trailer\n<< /Size " . ($lastObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
    return $pdf;
}
