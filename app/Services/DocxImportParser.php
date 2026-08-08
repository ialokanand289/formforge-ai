<?php

namespace App\Services;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Row;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;
use Throwable;

/**
 * Turns a DOCX into the bounded structured payload the model is shown.
 *
 * The binary is never sent anywhere. Only text that could plausibly describe a
 * form survives: paragraphs, headings, and tables. Images, embedded objects,
 * and style metadata carry no schema meaning and are dropped on the floor.
 */
class DocxImportParser
{
    /**
     * @return array{source: string, title_candidates: list<string>, paragraphs: list<string>, headings: list<string>, tables: list<array{headers: list<string>, rows: list<list<string>>}>}
     */
    public function parse(string $absolutePath): array
    {
        try {
            $document = IOFactory::load($absolutePath, 'Word2007');
        } catch (Throwable) {
            // The library's own message names internal paths and XML offsets.
            throw new RuntimeException('That Word document could not be read.');
        }

        $paragraphs = [];
        $headings = [];
        $tables = [];

        foreach ($document->getSections() as $section) {
            $this->collect($section, $paragraphs, $headings, $tables);
        }

        return [
            'source' => 'docx',
            'title_candidates' => $this->titleCandidates($headings, $paragraphs),
            'paragraphs' => array_slice($paragraphs, 0, $this->limit('max_paragraphs', 300)),
            'headings' => array_slice($headings, 0, $this->limit('max_headings', 100)),
            'tables' => array_slice($tables, 0, $this->limit('max_tables', 20)),
        ];
    }

    /**
     * @param  list<string>  $paragraphs
     * @param  list<string>  $headings
     * @param  list<array{headers: list<string>, rows: list<list<string>>}>  $tables
     */
    private function collect(AbstractContainer $container, array &$paragraphs, array &$headings, array &$tables): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof Title) {
                $this->push($headings, $this->textOf($element->getText()));

                continue;
            }

            if ($element instanceof Table) {
                $table = $this->readTable($element);

                if ($table !== null) {
                    $tables[] = $table;
                }

                continue;
            }

            if ($element instanceof TextRun || $element instanceof Text || $element instanceof ListItemRun) {
                $text = $this->textOf($element);

                // A Word heading is a paragraph wearing a Heading style, which
                // is the only signal available once the file is parsed.
                if ($this->isHeadingStyled($element)) {
                    $this->push($headings, $text);

                    continue;
                }

                $this->push($paragraphs, $text);

                continue;
            }

            if ($element instanceof ListItem) {
                $this->push($paragraphs, $this->textOf($element->getTextObject()));

                continue;
            }

            // Text boxes and similar wrappers hold real content; images, OLE
            // objects, charts and line breaks do not and fall through here.
            if ($element instanceof AbstractContainer) {
                $this->collect($element, $paragraphs, $headings, $tables);
            }
        }
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>}|null
     */
    private function readTable(Table $table): ?array
    {
        $maxRows = $this->limit('max_table_rows', 50);
        $maxColumns = $this->limit('max_columns', 60);

        $rows = [];

        foreach ($table->getRows() as $row) {
            if (count($rows) >= $maxRows + 1) {
                break;
            }

            $cells = [];

            foreach ($this->cellsOf($row) as $cell) {
                if (count($cells) >= $maxColumns) {
                    break;
                }

                $cells[] = $this->clip($this->textOf($cell));
            }

            if (array_filter($cells, fn (string $cell): bool => $cell !== '') !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return null;
        }

        // The first populated row is the header, which is the convention Word
        // authors follow even when the table carries no header formatting.
        $headers = array_shift($rows);

        return [
            'headers' => $headers,
            'rows' => array_slice($rows, 0, $maxRows),
        ];
    }

    /**
     * @return list<Cell>
     */
    private function cellsOf(Row $row): array
    {
        return array_values(array_filter(
            $row->getCells(),
            fn (mixed $cell): bool => $cell instanceof Cell,
        ));
    }

    /**
     * Flatten any element down to its visible text.
     */
    private function textOf(mixed $element): string
    {
        if ($element === null) {
            return '';
        }

        if (is_scalar($element)) {
            return trim((string) $element);
        }

        if ($element instanceof Text) {
            return trim((string) $element->getText());
        }

        if ($element instanceof AbstractContainer) {
            $parts = [];

            foreach ($element->getElements() as $child) {
                $text = $this->textOf($child);

                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return trim(implode(' ', $parts));
        }

        if (method_exists($element, 'getText')) {
            return $this->textOf($element->getText());
        }

        return '';
    }

    private function isHeadingStyled(mixed $element): bool
    {
        if (! method_exists($element, 'getParagraphStyle')) {
            return false;
        }

        $style = $element->getParagraphStyle();

        if (is_object($style) && method_exists($style, 'getStyleName')) {
            $style = $style->getStyleName();
        }

        return is_string($style) && str_starts_with(strtolower($style), 'heading');
    }

    /**
     * What the model should consider naming the form.
     *
     * @param  list<string>  $headings
     * @param  list<string>  $paragraphs
     * @return list<string>
     */
    private function titleCandidates(array $headings, array $paragraphs): array
    {
        return array_values(array_unique(array_filter(array_merge(
            array_slice($headings, 0, 2),
            array_slice($paragraphs, 0, 1),
        ))));
    }

    /**
     * @param  list<string>  $bucket
     */
    private function push(array &$bucket, string $text): void
    {
        $text = $this->clip($text);

        if ($text !== '') {
            $bucket[] = $text;
        }
    }

    private function clip(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $max = $this->limit('max_cell_chars', 300);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    private function limit(string $key, int $fallback): int
    {
        return max(1, (int) config("formforge.import.{$key}", $fallback));
    }
}
