<?php

namespace App\Services;

use App\Enums\ImportSource;
use RuntimeException;
use ZipArchive;

/**
 * Inspects an uploaded DOCX or XLSX before either PhpWord or PhpSpreadsheet is
 * handed the path.
 *
 * Both formats are ZIP containers, so accepting one means accepting an
 * attacker-controlled archive. Extension and MIME checks are not enough on
 * their own: fileinfo reports plenty of OOXML files as application/zip, and a
 * renamed file carries whatever extension its author chose.
 *
 * Nothing is ever extracted. Only the central directory is read, and both
 * parsing libraries read entries in memory, so extractTo() is never called.
 */
class ImportArchiveGuard
{
    /** Present in every OOXML package, whatever the flavour. */
    private const CONTENT_TYPES = '[Content_Types].xml';

    /** Carries a macro project, which a docx/xlsx has no business holding. */
    private const MACRO_ENTRY = 'vbaProject.bin';

    /**
     * @throws RuntimeException when the archive is unreadable or unsafe
     */
    public function assertSafe(string $absolutePath, ImportSource $source): void
    {
        $zip = new ZipArchive;

        // A renamed PDF, text file, or executable dies here.
        if ($zip->open($absolutePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('That file is not a readable Word or Excel document.');
        }

        try {
            $this->inspect($zip, $source);
        } finally {
            $zip->close();
        }
    }

    /**
     * The disk import files are written to, refusing anything world readable.
     *
     * Mirrors SubmissionService::disk(). An import file is a user's document and
     * must never land somewhere web-served, so a misconfigured environment
     * fails loudly rather than quietly publishing it.
     */
    public function disk(): string
    {
        return $this->assertPrivate((string) config('formforge.uploads.disk', 'local'));
    }

    /**
     * @throws RuntimeException when the named disk is world readable
     */
    public function assertPrivate(string $disk): string
    {
        $isPublic = $disk === 'public'
            || config("filesystems.disks.{$disk}.visibility") === 'public';

        if ($isPublic) {
            throw new RuntimeException("Import files cannot be stored on the public disk [{$disk}].");
        }

        return $disk;
    }

    private function inspect(ZipArchive $zip, ImportSource $source): void
    {
        $maxEntries = max(1, (int) config('formforge.import.archive.max_entries', 512));
        $maxBytes = max(1, (int) config('formforge.import.archive.max_uncompressed_bytes', 67108864));

        // A real DOCX holds tens of entries; a bomb holds tens of thousands.
        if ($zip->numFiles > $maxEntries) {
            throw new RuntimeException('That document contains too many parts to import safely.');
        }

        $marker = $this->markerFor($source);
        $seenContentTypes = false;
        $seenMarker = false;
        $uncompressed = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw new RuntimeException('That file is not a readable Word or Excel document.');
            }

            $name = (string) $stat['name'];

            if ($this->isTraversal($name)) {
                throw new RuntimeException('That document contains an unsafe entry and was rejected.');
            }

            if (str_ends_with($name, self::MACRO_ENTRY)) {
                throw new RuntimeException('That document contains macros and cannot be imported.');
            }

            if ($name === self::CONTENT_TYPES) {
                $seenContentTypes = true;
            }

            if ($name === $marker) {
                $seenMarker = true;
            }

            $uncompressed += max(0, (int) $stat['size']);

            // Entry sizes come from the central directory, which an attacker can
            // understate. This is a necessary bound, not a sufficient one; the
            // job timeout, PHP's memory limit, and the XLSX read filter are what
            // catch an archive that lies about itself.
            if ($uncompressed > $maxBytes) {
                throw new RuntimeException('That document is too large to import safely.');
            }
        }

        if (! $seenContentTypes) {
            throw new RuntimeException('That file is not a readable Word or Excel document.');
        }

        // What actually stops an XLSX renamed .docx from reaching PhpWord.
        if (! $seenMarker) {
            throw new RuntimeException("That file's contents do not match its .{$source->value} extension.");
        }
    }

    private function markerFor(ImportSource $source): string
    {
        return match ($source) {
            ImportSource::Docx => 'word/document.xml',
            ImportSource::Xlsx => 'xl/workbook.xml',
        };
    }

    /**
     * We never extract, so this is belt and braces, but it costs two lines.
     */
    private function isTraversal(string $name): bool
    {
        return str_starts_with($name, '/')
            || str_contains($name, '..')
            || str_contains($name, '\\')
            || preg_match('/^[a-zA-Z]:/', $name) === 1;
    }
}
