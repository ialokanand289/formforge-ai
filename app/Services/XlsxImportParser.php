<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Turns an XLSX into the bounded structured payload the model is shown.
 *
 * Two things keep this safe rather than merely small. The reader runs in
 * data-only mode, so a formula is read as the value Excel last cached and is
 * never calculated, which is what "handle formulas without executing arbitrary
 * code" requires. And the row/column bound is applied through an IReadFilter,
 * so an oversized workbook is never materialised in memory at all — trimming
 * after the fact would already have cost the memory.
 */
class XlsxImportParser implements IReadFilter
{
    private int $maxRows = 50;

    private int $maxColumns = 60;

    /**
     * @return array{source: string, sheets: list<array{name: string, headers: list<string>, rows: list<list<string>>}>}
     */
    public function parse(string $absolutePath): array
    {
        $this->maxRows = $this->limit('max_rows', 50);
        $this->maxColumns = $this->limit('max_columns', 60);

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $reader->setReadFilter($this);

            $spreadsheet = $reader->load($absolutePath);
        } catch (Throwable) {
            throw new RuntimeException('That Excel workbook could not be read.');
        }

        $sheets = [];
        $maxSheets = $this->limit('max_sheets', 5);

        foreach ($spreadsheet->getAllSheets() as $worksheet) {
            if (count($sheets) >= $maxSheets) {
                break;
            }

            $sheet = $this->readSheet($worksheet);

            if ($sheet !== null) {
                $sheets[] = $sheet;
            }
        }

        $spreadsheet->disconnectWorksheets();

        return [
            'source' => 'xlsx',
            'sheets' => $sheets,
        ];
    }

    /**
     * Called by PhpSpreadsheet for every cell it considers reading.
     */
    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        // One spare row so a leading blank row cannot cost the last data row.
        if ($row > $this->maxRows + 2) {
            return false;
        }

        return Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumns;
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<list<string>>}|null
     */
    private function readSheet(Worksheet $worksheet): ?array
    {
        $rows = [];

        foreach ($worksheet->getRowIterator() as $row) {
            if (count($rows) >= $this->maxRows + 1) {
                break;
            }

            $cells = [];
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);

            foreach ($iterator as $cell) {
                if (count($cells) >= $this->maxColumns) {
                    break;
                }

                // A merged range stores its value in the top-left cell and
                // leaves the rest genuinely empty, which is what this reads.
                // Cells stay positional either way, so no column shifts.
                $cells[] = $this->clip($this->valueOf($cell));
            }

            $cells = $this->trimTrailingBlanks($cells);

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            // An empty sheet describes no fields, so it is not worth a prompt.
            return null;
        }

        // The header is the first row carrying at least two populated cells: a
        // single populated cell is far more often a stray caption than a header.
        $headerIndex = $this->headerIndex($rows);
        $headers = $rows[$headerIndex];

        return [
            'name' => $this->clip($worksheet->getTitle()),
            'headers' => $headers,
            'rows' => array_slice(array_values(array_slice($rows, $headerIndex + 1)), 0, $this->maxRows),
        ];
    }

    /**
     * The value a cell holds, without ever running the calculation engine.
     *
     * Data-only mode still hands back the raw formula string, so a formula cell
     * is read through its cached result instead. getCalculatedValue() would
     * evaluate the expression, which is precisely what an untrusted workbook
     * must not be allowed to make us do.
     */
    private function valueOf(Cell $cell): mixed
    {
        return $cell->getDataType() === DataType::TYPE_FORMULA
            ? $cell->getOldCalculatedValue()
            : $cell->getValue();
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function headerIndex(array $rows): int
    {
        foreach ($rows as $index => $row) {
            if (count(array_filter($row, fn (string $cell): bool => $cell !== '')) >= 2) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * @param  list<string>  $cells
     * @return list<string>
     */
    private function trimTrailingBlanks(array $cells): array
    {
        while ($cells !== [] && end($cells) === '') {
            array_pop($cells);
        }

        return array_values($cells);
    }

    private function clip(mixed $value): string
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        if ($value === null || is_array($value) || is_object($value)) {
            $value = '';
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
        $max = $this->limit('max_cell_chars', 300);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    private function limit(string $key, int $fallback): int
    {
        return max(1, (int) config("formforge.import.{$key}", $fallback));
    }
}
