<?php
declare(strict_types=1);

/**
 * Lightweight, dependency-free XLSX preview reader.
 *
 * It reads cached values from an .xlsx file and never writes business data.
 * Column letters are preserved deliberately because the legacy workbook contains
 * duplicate headings (for example Formula-1 / Afresh / Order Amount / Profit).
 */
final class XlsxPreviewReader
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private ZipArchive $zip;
    /** @var array<int,string> */
    private array $sharedStrings = [];
    /** @var array<int,array{name:string,path:string}> */
    private array $sheets = [];

    public function __construct(private readonly string $xlsxPath)
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZIP extension is not enabled. Enable extension=zip in XAMPP PHP before using XLSX preview.');
        }
        if (!is_file($xlsxPath) || !is_readable($xlsxPath)) {
            throw new RuntimeException('The uploaded workbook could not be read.');
        }

        $this->zip = new ZipArchive();
        if ($this->zip->open($xlsxPath) !== true) {
            throw new RuntimeException('The uploaded file is not a readable XLSX workbook.');
        }

        $this->sharedStrings = $this->loadSharedStrings();
        $this->sheets = $this->loadWorkbookSheets();
    }

    public function __destruct()
    {
        if (isset($this->zip)) {
            $this->zip->close();
        }
    }

    /** @return array<int,array{name:string,path:string}> */
    public function sheets(): array
    {
        return $this->sheets;
    }

    /**
     * @return array{
     *   name:string,
     *   headers:array<string,string>,
     *   record_count:int,
     *   samples:array<int,array<string,string|null>>,
     *   duplicate_headers:array<int,string>,
     *   blank_header_columns:array<int,string>
     * }
     */
    public function previewSheet(string $sheetPath, string $sheetName, int $sampleLimit = 3): array
    {
        $xml = $this->readZipEntry($sheetPath);
        $doc = $this->xmlDocument($xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('m', self::MAIN_NS);

        /** @var array<int,array<string,string|null>> $rows */
        $rows = [];
        foreach ($xp->query('//m:sheetData/m:row') ?: [] as $rowNode) {
            if (!$rowNode instanceof DOMElement) {
                continue;
            }
            $rowNumber = (int)$rowNode->getAttribute('r');
            $row = [];
            foreach ($xp->query('./m:c', $rowNode) ?: [] as $cellNode) {
                if (!$cellNode instanceof DOMElement) {
                    continue;
                }
                $ref = $cellNode->getAttribute('r');
                if (!preg_match('/^([A-Z]+)/', $ref, $match)) {
                    continue;
                }
                $row[$match[1]] = $this->cellValue($cellNode, $xp);
            }
            $rows[$rowNumber] = $row;
        }

        $headerRow = $rows[1] ?? [];
        $columns = array_keys($headerRow);
        usort($columns, static fn(string $a, string $b): int => self::columnNumber($a) <=> self::columnNumber($b));

        $headers = [];
        $headerCounts = [];
        $blankHeaderColumns = [];
        foreach ($columns as $column) {
            $label = trim((string)($headerRow[$column] ?? ''));
            $headers[$column] = $label;
            if ($label === '') {
                $blankHeaderColumns[] = $column;
            } else {
                $headerCounts[$label] = ($headerCounts[$label] ?? 0) + 1;
            }
        }
        $duplicateHeaders = array_keys(array_filter($headerCounts, static fn(int $count): bool => $count > 1));

        $recordCount = 0;
        $samples = [];
        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber <= 1) {
                continue;
            }
            if (!$this->rowHasData($row)) {
                continue;
            }
            $recordCount++;
            if (count($samples) < $sampleLimit) {
                $sample = [];
                foreach ($headers as $column => $header) {
                    $sample[$column] = $row[$column] ?? null;
                }
                $samples[] = $sample;
            }
        }

        return [
            'name' => $sheetName,
            'headers' => $headers,
            'record_count' => $recordCount,
            'samples' => $samples,
            'duplicate_headers' => $duplicateHeaders,
            'blank_header_columns' => $blankHeaderColumns,
        ];
    }

    /** @return array<int,string> */
    private function loadSharedStrings(): array
    {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = $this->xmlDocument($xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('m', self::MAIN_NS);
        $result = [];
        foreach ($xp->query('//m:si') ?: [] as $si) {
            $text = '';
            foreach ($xp->query('.//m:t', $si) ?: [] as $t) {
                $text .= $t->textContent;
            }
            $result[] = $text;
        }
        return $result;
    }

    /** @return array<int,array{name:string,path:string}> */
    private function loadWorkbookSheets(): array
    {
        $workbook = $this->xmlDocument($this->readZipEntry('xl/workbook.xml'));
        $workbookXp = new DOMXPath($workbook);
        $workbookXp->registerNamespace('m', self::MAIN_NS);
        $workbookXp->registerNamespace('r', self::REL_NS);

        $rels = $this->xmlDocument($this->readZipEntry('xl/_rels/workbook.xml.rels'));
        $relsXp = new DOMXPath($rels);
        $relsXp->registerNamespace('p', self::PACKAGE_REL_NS);
        $targets = [];
        foreach ($relsXp->query('//p:Relationship') ?: [] as $rel) {
            if ($rel instanceof DOMElement) {
                $targets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }

        $sheets = [];
        foreach ($workbookXp->query('//m:sheets/m:sheet') ?: [] as $sheet) {
            if (!$sheet instanceof DOMElement) {
                continue;
            }
            $rid = $sheet->getAttributeNS(self::REL_NS, 'id');
            $target = $targets[$rid] ?? '';
            if ($target === '') {
                continue;
            }
            $sheets[] = [
                'name' => $sheet->getAttribute('name'),
                'path' => $this->normalizeWorkbookTarget($target),
            ];
        }
        return $sheets;
    }

    private function cellValue(DOMElement $cell, DOMXPath $xp): ?string
    {
        $type = $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            $text = '';
            foreach ($xp->query('./m:is//m:t', $cell) ?: [] as $node) {
                $text .= $node->textContent;
            }
            return $text;
        }

        $valueNode = $xp->query('./m:v', $cell)?->item(0);
        $value = $valueNode?->textContent;
        if ($value === null) {
            return null;
        }

        if ($type === 's') {
            $index = (int)$value;
            return $this->sharedStrings[$index] ?? '';
        }
        if ($type === 'b') {
            return $value === '1' ? 'TRUE' : 'FALSE';
        }

        return $value;
    }

    /** @param array<string,string|null> $row */
    private function rowHasData(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim($value) !== '') {
                return true;
            }
        }
        return false;
    }

    private function readZipEntry(string $entry): string
    {
        $xml = $this->zip->getFromName($entry);
        if ($xml === false) {
            throw new RuntimeException('Missing XLSX component: ' . $entry);
        }
        return $xml;
    }

    private function xmlDocument(string $xml): DOMDocument
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('The workbook contains invalid XML.');
        }
        return $doc;
    }

    private function normalizeWorkbookTarget(string $target): string
    {
        $target = str_replace('\\', '/', $target);
        $target = ltrim($target, '/');
        if (str_starts_with($target, 'xl/')) {
            return $target;
        }
        while (str_starts_with($target, '../')) {
            $target = substr($target, 3);
        }
        return 'xl/' . $target;
    }

    private static function columnNumber(string $column): int
    {
        $number = 0;
        foreach (str_split($column) as $character) {
            $number = ($number * 26) + (ord($character) - 64);
        }
        return $number;
    }
}
