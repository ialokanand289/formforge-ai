<?php

use App\Services\XlsxImportParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->xlsxDir = sys_get_temp_dir().'/formforge-xlsx-'.Str::random(8);
    mkdir($this->xlsxDir);
});

afterEach(function () {
    foreach (glob($this->xlsxDir.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->xlsxDir);
});

/**
 * Build an XLSX with the library that will read it back.
 */
function xlsxFrom(callable $build): string
{
    $spreadsheet = new Spreadsheet;
    $build($spreadsheet);

    $path = test()->xlsxDir.'/'.Str::random(8).'.xlsx';
    (new XlsxWriter($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

/**
 * @param  list<list<mixed>>  $rows
 */
function xlsxGrid(array $rows, string $title = 'Employees'): string
{
    return xlsxFrom(function (Spreadsheet $spreadsheet) use ($rows, $title) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($rows, null, 'A1');
    });
}

it('extracts the worksheet name, its header row and its rows', function () {
    $path = xlsxGrid([
        ['Full Name', 'Work Email', 'Department'],
        ['Ada Lovelace', 'ada@example.com', 'Engineering'],
        ['Grace Hopper', 'grace@example.com', 'Engineering'],
    ], 'Employee Registration');

    $result = (new XlsxImportParser)->parse($path);

    expect($result['source'])->toBe('xlsx')
        ->and($result['sheets'])->toHaveCount(1)
        ->and($result['sheets'][0]['name'])->toBe('Employee Registration')
        ->and($result['sheets'][0]['headers'])->toBe(['Full Name', 'Work Email', 'Department'])
        ->and($result['sheets'][0]['rows'])->toBe([
            ['Ada Lovelace', 'ada@example.com', 'Engineering'],
            ['Grace Hopper', 'grace@example.com', 'Engineering'],
        ]);
});

it('skips a title row above the header', function () {
    // A single populated cell is far more often a caption than a header row.
    $path = xlsxGrid([
        ['Q3 Intake'],
        [],
        ['Full Name', 'Email'],
        ['Ada', 'ada@example.com'],
    ]);

    $result = (new XlsxImportParser)->parse($path);

    expect($result['sheets'][0]['headers'])->toBe(['Full Name', 'Email'])
        ->and($result['sheets'][0]['rows'])->toBe([['Ada', 'ada@example.com']]);
});

it('reads every worksheet in the workbook', function () {
    $path = xlsxFrom(function (Spreadsheet $spreadsheet) {
        $first = $spreadsheet->getActiveSheet();
        $first->setTitle('People');
        $first->fromArray([['Full Name', 'Email'], ['Ada', 'ada@example.com']], null, 'A1');

        $second = $spreadsheet->createSheet();
        $second->setTitle('Equipment');
        $second->fromArray([['Asset', 'Serial'], ['Laptop', 'X1']], null, 'A1');
    });

    $result = (new XlsxImportParser)->parse($path);

    expect(array_column($result['sheets'], 'name'))->toBe(['People', 'Equipment'])
        ->and($result['sheets'][1]['headers'])->toBe(['Asset', 'Serial']);
});

it('drops a worksheet that holds nothing', function () {
    $path = xlsxFrom(function (Spreadsheet $spreadsheet) {
        $first = $spreadsheet->getActiveSheet();
        $first->setTitle('People');
        $first->fromArray([['Full Name', 'Email'], ['Ada', 'ada@example.com']], null, 'A1');

        $spreadsheet->createSheet()->setTitle('Blank');
    });

    $result = (new XlsxImportParser)->parse($path);

    expect(array_column($result['sheets'], 'name'))->toBe(['People']);
});

it('stops after the configured number of worksheets', function () {
    config(['formforge.import.max_sheets' => 2]);

    $path = xlsxFrom(function (Spreadsheet $spreadsheet) {
        for ($i = 0; $i < 6; $i++) {
            $sheet = $i === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle("Sheet{$i}");
            $sheet->fromArray([['Name', 'Email'], ['Ada', 'ada@example.com']], null, 'A1');
        }
    });

    $result = (new XlsxImportParser)->parse($path);

    expect($result['sheets'])->toHaveCount(2);
});

it('reads a merged range as its top left value without shifting any column', function () {
    $path = xlsxFrom(function (Spreadsheet $spreadsheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Contact Details', null, 'Notes'],
            ['Full Name', 'Email', 'Comment'],
            ['Ada', 'ada@example.com', 'Founder'],
        ], null, 'A1');
        $sheet->mergeCells('A1:B1');
    });

    $result = (new XlsxImportParser)->parse($path);

    // A merged range stores its value in the top-left cell only. What matters
    // is that the covered cell stays in place as a blank rather than
    // collapsing, so every later column still lines up with its header.
    expect($result['sheets'][0]['headers'])->toBe(['Contact Details', '', 'Notes'])
        ->and($result['sheets'][0]['rows'])->toBe([
            ['Full Name', 'Email', 'Comment'],
            ['Ada', 'ada@example.com', 'Founder'],
        ]);
});

it('keeps duplicate headers rather than collapsing them', function () {
    $path = xlsxGrid([
        ['Email', 'Email', 'Phone'],
        ['a@example.com', 'b@example.com', '0100'],
    ]);

    $result = (new XlsxImportParser)->parse($path);

    expect($result['sheets'][0]['headers'])->toBe(['Email', 'Email', 'Phone']);
});

it('reads a formula as the value excel cached rather than calculating it', function () {
    $path = xlsxFrom(function (Spreadsheet $spreadsheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Name', 'Score', 'Doubled'], ['Ada', 21, null]], null, 'A1');
        $sheet->setCellValue('C2', '=B2*2');
    });

    $result = (new XlsxImportParser)->parse($path);

    // The cached result, not the formula text, and no calculation engine ran.
    expect($result['sheets'][0]['rows'][0])->toBe(['Ada', '21', '42']);
});

it('bounds the rows it reads', function () {
    config(['formforge.import.max_rows' => 4]);

    $rows = [['Full Name', 'Email']];

    for ($i = 0; $i < 500; $i++) {
        $rows[] = ["Person {$i}", "person{$i}@example.com"];
    }

    $result = (new XlsxImportParser)->parse(xlsxGrid($rows));

    expect($result['sheets'][0]['rows'])->toHaveCount(4);
});

it('bounds the columns it reads', function () {
    config(['formforge.import.max_columns' => 3]);

    $header = [];
    $row = [];

    for ($i = 0; $i < 40; $i++) {
        $header[] = "Column {$i}";
        $row[] = "value {$i}";
    }

    $result = (new XlsxImportParser)->parse(xlsxGrid([$header, $row]));

    expect($result['sheets'][0]['headers'])->toBe(['Column 0', 'Column 1', 'Column 2'])
        ->and($result['sheets'][0]['rows'][0])->toHaveCount(3);
});

it('clips a cell that runs longer than the configured maximum', function () {
    config(['formforge.import.max_cell_chars' => 15]);

    $result = (new XlsxImportParser)->parse(xlsxGrid([
        ['Full Name', 'Email'],
        [str_repeat('a', 400), 'ada@example.com'],
    ]));

    expect(mb_strlen($result['sheets'][0]['rows'][0][0]))->toBe(15);
});

it('raises a safe error for a workbook it cannot read', function () {
    $path = test()->xlsxDir.'/broken.xlsx';
    file_put_contents($path, 'this is not a spreadsheet');

    expect(fn () => (new XlsxImportParser)->parse($path))
        ->toThrow(RuntimeException::class, 'That Excel workbook could not be read.');
});

it('never names the file or the library when a read fails', function () {
    $path = test()->xlsxDir.'/payroll-2026.xlsx';
    file_put_contents($path, 'garbage');

    try {
        (new XlsxImportParser)->parse($path);

        $this->fail('The parser accepted a file that is not a workbook.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->not->toContain('payroll-2026')
            ->not->toContain(sys_get_temp_dir())
            ->not->toContain('PhpOffice');
    }
});
