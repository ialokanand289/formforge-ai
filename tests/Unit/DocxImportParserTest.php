<?php

use App\Services\DocxImportParser;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->docxDir = sys_get_temp_dir().'/formforge-docx-'.Str::random(8);
    mkdir($this->docxDir);
});

afterEach(function () {
    foreach (glob($this->docxDir.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->docxDir);
});

/**
 * Build a DOCX with the library that will read it back, so the test exercises
 * the real reader rather than a checked-in binary nobody can inspect.
 */
function docxFrom(callable $build): string
{
    $word = new PhpWord;
    $build($word->addSection(), $word);

    $path = test()->docxDir.'/'.Str::random(8).'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($path);

    return $path;
}

function docxTinyPng(): string
{
    $path = test()->docxDir.'/pixel.png';

    file_put_contents($path, (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    return $path;
}

it('extracts paragraphs', function () {
    $path = docxFrom(function ($section) {
        $section->addText('Please complete every field below.');
        $section->addText('Your full legal name is required.');
    });

    $result = (new DocxImportParser)->parse($path);

    expect($result['source'])->toBe('docx')
        ->and($result['paragraphs'])->toBe([
            'Please complete every field below.',
            'Your full legal name is required.',
        ]);
});

it('extracts headings from title elements and from heading styled paragraphs', function () {
    $path = docxFrom(function ($section, PhpWord $word) {
        $word->addTitleStyle(1, ['bold' => true]);
        $section->addTitle('Employee Registration', 1);
        $section->addText('Contact Details', null, 'Heading2');
        $section->addText('Just a paragraph.');
    });

    $result = (new DocxImportParser)->parse($path);

    expect($result['headings'])->toBe(['Employee Registration', 'Contact Details'])
        ->and($result['paragraphs'])->toBe(['Just a paragraph.']);
});

it('offers the leading headings as title candidates', function () {
    $path = docxFrom(function ($section, PhpWord $word) {
        $word->addTitleStyle(1, ['bold' => true]);
        $section->addTitle('Employee Registration', 1);
        $section->addText('Complete in full.');
    });

    $result = (new DocxImportParser)->parse($path);

    expect($result['title_candidates'])->toContain('Employee Registration');
});

it('extracts a table with its first row as the header', function () {
    $path = docxFrom(function ($section) {
        $table = $section->addTable();

        foreach ([
            ['Field', 'Type', 'Required'],
            ['Full Name', 'Text', 'Yes'],
            ['Department', 'HR / Engineering', 'No'],
        ] as $row) {
            $table->addRow();

            foreach ($row as $cell) {
                $table->addCell()->addText($cell);
            }
        }
    });

    $result = (new DocxImportParser)->parse($path);

    expect($result['tables'])->toHaveCount(1)
        ->and($result['tables'][0]['headers'])->toBe(['Field', 'Type', 'Required'])
        ->and($result['tables'][0]['rows'])->toBe([
            ['Full Name', 'Text', 'Yes'],
            ['Department', 'HR / Engineering', 'No'],
        ]);
});

it('skips a table row that holds nothing', function () {
    $path = docxFrom(function ($section) {
        $table = $section->addTable();

        $table->addRow();
        $table->addCell()->addText('Field');
        $table->addCell()->addText('Notes');

        $table->addRow();
        $table->addCell()->addText('');
        $table->addCell()->addText('');

        $table->addRow();
        $table->addCell()->addText('Email');
        $table->addCell()->addText('Work address');
    });

    $result = (new DocxImportParser)->parse($path);

    expect($result['tables'][0]['rows'])->toBe([['Email', 'Work address']]);
});

it('drops images and keeps the text around them', function () {
    $png = docxTinyPng();

    $path = docxFrom(function ($section) use ($png) {
        $section->addText('Attach a photograph.');
        $section->addImage($png);
        $section->addText('Then sign below.');
    });

    $result = (new DocxImportParser)->parse($path);

    expect($result['paragraphs'])->toBe(['Attach a photograph.', 'Then sign below.']);
});

it('reads list items as paragraphs', function () {
    $path = docxFrom(function ($section) {
        $section->addListItem('Date of birth');
        $section->addListItem('Nationality');
    });

    $result = (new DocxImportParser)->parse($path);

    expect($result['paragraphs'])->toBe(['Date of birth', 'Nationality']);
});

it('stops collecting paragraphs at the configured bound', function () {
    config(['formforge.import.max_paragraphs' => 5]);

    $path = docxFrom(function ($section) {
        for ($i = 1; $i <= 40; $i++) {
            $section->addText("Question number {$i}");
        }
    });

    $result = (new DocxImportParser)->parse($path);

    expect($result['paragraphs'])->toHaveCount(5)
        ->and($result['paragraphs'][0])->toBe('Question number 1');
});

it('stops collecting tables and table rows at the configured bounds', function () {
    config(['formforge.import.max_tables' => 2, 'formforge.import.max_table_rows' => 3]);

    $path = docxFrom(function ($section) {
        for ($t = 0; $t < 5; $t++) {
            $table = $section->addTable();

            for ($r = 0; $r < 20; $r++) {
                $table->addRow();
                $table->addCell()->addText("t{$t}r{$r}");
                $table->addCell()->addText('value');
            }
        }
    });

    $result = (new DocxImportParser)->parse($path);

    expect($result['tables'])->toHaveCount(2)
        ->and($result['tables'][0]['rows'])->toHaveCount(3);
});

it('clips a cell that runs longer than the configured maximum', function () {
    config(['formforge.import.max_cell_chars' => 20]);

    $path = docxFrom(function ($section) {
        $section->addText(str_repeat('long ', 200));
    });

    $result = (new DocxImportParser)->parse($path);

    expect(mb_strlen($result['paragraphs'][0]))->toBe(20);
});

it('collapses runs of whitespace so the payload stays compact', function () {
    $path = docxFrom(function ($section) {
        $section->addText("Full     Name\t\tand  title");
    });

    $result = (new DocxImportParser)->parse($path);

    expect($result['paragraphs'][0])->toBe('Full Name and title');
});

it('raises a safe error for a file it cannot read', function () {
    $path = test()->docxDir.'/broken.docx';
    file_put_contents($path, 'this is not a word document');

    expect(fn () => (new DocxImportParser)->parse($path))
        ->toThrow(RuntimeException::class, 'That Word document could not be read.');
});

it('never names the file or the library when a read fails', function () {
    $path = test()->docxDir.'/candidate-cv.docx';
    file_put_contents($path, 'garbage');

    try {
        (new DocxImportParser)->parse($path);

        $this->fail('The parser accepted a file that is not a document.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->not->toContain('candidate-cv')
            ->not->toContain(sys_get_temp_dir())
            ->not->toContain('PhpOffice');
    }
});
