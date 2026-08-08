<?php

use App\Enums\ImportSource;
use App\Services\ImportArchiveGuard;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->guardDir = sys_get_temp_dir().'/formforge-guard-'.Str::random(8);
    mkdir($this->guardDir);
});

afterEach(function () {
    foreach (glob($this->guardDir.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->guardDir);
});

function guardPath(string $name): string
{
    return test()->guardDir.'/'.$name;
}

/**
 * A genuine DOCX, written by the same library that will later read it.
 */
function guardDocx(string $name = 'real.docx'): string
{
    $word = new PhpWord;
    $section = $word->addSection();
    $section->addTitle('Employee Registration', 1);
    $section->addText('Full Name');

    $path = guardPath($name);
    WordIOFactory::createWriter($word, 'Word2007')->save($path);

    return $path;
}

function guardXlsx(string $name = 'real.xlsx'): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'Full Name');
    $sheet->setCellValue('B1', 'Email');

    $path = guardPath($name);
    (new XlsxWriter($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

/**
 * A hand-built archive, so an entry name or size can be anything we like.
 *
 * @param  array<string, string>  $entries
 */
function guardZip(string $name, array $entries): string
{
    $path = guardPath($name);

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $entry => $contents) {
        $zip->addFromString($entry, $contents);
    }

    $zip->close();

    return $path;
}

/**
 * The minimum an archive needs to look like a real DOCX to the guard.
 *
 * @return array<string, string>
 */
function guardDocxSkeleton(): array
{
    return [
        '[Content_Types].xml' => '<Types/>',
        'word/document.xml' => '<document/>',
    ];
}

it('accepts a genuine docx and a genuine xlsx', function () {
    $guard = new ImportArchiveGuard;

    $guard->assertSafe(guardDocx(), ImportSource::Docx);
    $guard->assertSafe(guardXlsx(), ImportSource::Xlsx);
})->throwsNoExceptions();

it('rejects a docx renamed as an xlsx on the marker entry', function () {
    // Both are valid OOXML packages, so only the marker separates them.
    $path = guardDocx('disguised.xlsx');

    expect(fn () => (new ImportArchiveGuard)->assertSafe($path, ImportSource::Xlsx))
        ->toThrow(RuntimeException::class, 'do not match its .xlsx extension');
});

it('rejects an xlsx renamed as a docx on the marker entry', function () {
    $path = guardXlsx('disguised.docx');

    expect(fn () => (new ImportArchiveGuard)->assertSafe($path, ImportSource::Docx))
        ->toThrow(RuntimeException::class, 'do not match its .docx extension');
});

it('rejects bytes that are not an archive at all', function (string $bytes) {
    $path = guardPath('renamed.docx');
    file_put_contents($path, $bytes);

    expect(fn () => (new ImportArchiveGuard)->assertSafe($path, ImportSource::Docx))
        ->toThrow(RuntimeException::class, 'not a readable Word or Excel document');
})->with([
    'plain text' => ['Dear hiring manager, please find my details below.'],
    'pdf' => ["%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n%%EOF"],
    'executable' => ["MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00"],
]);

it('rejects an archive holding more entries than the cap allows', function () {
    config(['formforge.import.archive.max_entries' => 3]);

    $entries = guardDocxSkeleton();

    for ($i = 0; $i < 10; $i++) {
        $entries["word/media/image{$i}.bin"] = 'x';
    }

    $path = guardZip('many.docx', $entries);

    expect(fn () => (new ImportArchiveGuard)->assertSafe($path, ImportSource::Docx))
        ->toThrow(RuntimeException::class, 'too many parts');
});

it('rejects an archive whose uncompressed total exceeds the cap', function () {
    config(['formforge.import.archive.max_uncompressed_bytes' => 1024]);

    // Highly compressible, which is exactly the shape of a zip bomb: tiny on
    // disk, enormous once expanded.
    $path = guardZip('bomb.docx', guardDocxSkeleton() + [
        'word/theme/theme1.xml' => str_repeat('A', 200000),
    ]);

    expect(filesize($path))->toBeLessThan(1024);

    expect(fn () => (new ImportArchiveGuard)->assertSafe($path, ImportSource::Docx))
        ->toThrow(RuntimeException::class, 'too large to import safely');
});

it('rejects an archive carrying a macro project', function () {
    $path = guardZip('macro.docx', guardDocxSkeleton() + [
        'word/vbaProject.bin' => 'binary',
    ]);

    expect(fn () => (new ImportArchiveGuard)->assertSafe($path, ImportSource::Docx))
        ->toThrow(RuntimeException::class, 'contains macros');
});

it('rejects an entry name that tries to escape its directory', function (string $entry) {
    $path = guardZip('traversal.docx', guardDocxSkeleton() + [$entry => 'x']);

    expect(fn () => (new ImportArchiveGuard)->assertSafe($path, ImportSource::Docx))
        ->toThrow(RuntimeException::class, 'unsafe entry');
})->with([
    'parent' => ['../../etc/passwd'],
    'nested parent' => ['word/../../escape.xml'],
    'absolute' => ['/etc/passwd'],
]);

it('rejects an archive with no content types part', function () {
    $path = guardZip('nocontenttypes.docx', ['word/document.xml' => '<document/>']);

    expect(fn () => (new ImportArchiveGuard)->assertSafe($path, ImportSource::Docx))
        ->toThrow(RuntimeException::class, 'not a readable Word or Excel document');
});

it('never extracts anything while inspecting', function () {
    $path = guardDocx();
    $before = glob(test()->guardDir.'/*');

    (new ImportArchiveGuard)->assertSafe($path, ImportSource::Docx);

    expect(glob(test()->guardDir.'/*'))->toBe($before);
});

it('never names a path or a library internal in a rejection', function () {
    $path = guardPath('secret-resume.docx');
    file_put_contents($path, 'not an archive');

    try {
        (new ImportArchiveGuard)->assertSafe($path, ImportSource::Docx);

        $this->fail('The guard accepted a file that is not an archive.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->not->toContain($path)
            ->not->toContain('secret-resume')
            ->not->toContain(sys_get_temp_dir())
            ->not->toContain('ZipArchive')
            ->not->toContain('PhpOffice');
    }
});

it('refuses to resolve a world readable disk', function () {
    config(['formforge.uploads.disk' => 'public']);

    expect(fn () => (new ImportArchiveGuard)->disk())
        ->toThrow(RuntimeException::class, 'cannot be stored on the public disk');
});

it('refuses a disk declaring public visibility', function () {
    config([
        'formforge.uploads.disk' => 'shared',
        'filesystems.disks.shared' => ['driver' => 'local', 'root' => '/tmp', 'visibility' => 'public'],
    ]);

    expect(fn () => (new ImportArchiveGuard)->disk())
        ->toThrow(RuntimeException::class, 'cannot be stored on the public disk');
});

it('resolves the configured private disk', function () {
    expect((new ImportArchiveGuard)->disk())->toBe('local');
});
