<?php

/**
 * Regenerates the two sample import documents that ship with this repository.
 *
 * This is a development utility, not part of the application. It lives outside
 * app/ and is not autoloaded, so nothing the web process runs can reach it. Run
 * it only when the samples need to change:
 *
 *   php samples/imports/generate-samples.php
 *
 * The committed .docx and .xlsx are the artefacts that matter; this script
 * exists so a reviewer can see exactly how they were produced rather than
 * having to trust two opaque binaries.
 *
 * Both documents are written the way a real person would write them. Neither
 * parser expects a magic format: DocxImportParser extracts headings,
 * paragraphs, list items and tables, XlsxImportParser extracts a header row and
 * the rows beneath it, and the AI inference step turns that raw text into a
 * schema. The layouts below are simply the ones the prompt reads most reliably.
 */

require __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\PhpWord;

writeDocx(__DIR__.'/sample-form.docx');
writeXlsx(__DIR__.'/sample-form.xlsx');

echo "Wrote sample-form.docx and sample-form.xlsx\n";

/**
 * A Word document in the shape of a paper questionnaire.
 *
 * Exercises the four things the DOCX path has to cope with: a document title,
 * a section heading, free-text questions with a prompt line, a single-choice
 * question, and a multi-select written as a checkbox list.
 */
function writeDocx(string $path): void
{
    $word = new PhpWord;

    // Heading styles are how the parser tells a heading from a paragraph: it
    // reads the paragraph style name, not the font size.
    $word->addTitleStyle(1, ['size' => 18, 'bold' => true]);
    $word->addTitleStyle(2, ['size' => 14, 'bold' => true]);

    $section = $word->addSection();

    $section->addTitle('Volunteer Registration Form', 1);
    $section->addText('Please complete every question marked as required. We use these details only to plan the event.');

    $section->addTitle('Your details', 2);
    $section->addText('Full name (required)');
    $section->addText('Email address (required)');
    $section->addText('Contact phone number');
    $section->addText('Which date would you prefer to volunteer on?');

    $section->addTitle('Your availability', 2);
    $section->addText('Preferred shift (choose one):');
    $section->addListItem('Morning, 08:00 to 12:00', 0);
    $section->addListItem('Afternoon, 12:00 to 16:00', 0);
    $section->addListItem('Evening, 16:00 to 20:00', 0);

    $section->addText('Which activities can you help with? (tick all that apply)');
    $section->addListItem('Registration desk', 0);
    $section->addListItem('Catering', 0);
    $section->addListItem('Setup and pack down', 0);
    $section->addListItem('First aid', 0);

    $section->addTitle('Anything else', 2);
    $section->addText('Do you have any access requirements or other notes for us? (long answer)');

    $word->save($path, 'Word2007');
}

/**
 * A spreadsheet in the shape of a specification: one row per question.
 *
 * The header row names the columns, and every row beneath it describes a single
 * field. Options are pipe separated so a comma inside an option label does not
 * split it in two.
 */
function writeXlsx(string $path): void
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Fields');

    $rows = [
        ['Question', 'Type', 'Required', 'Options', 'Help Text', 'Placeholder'],
        ['Full Name', 'text', 'required', '', 'As it appears on your ID', 'Ada Lovelace'],
        ['Work Email', 'email', 'required', '', 'We only use this to confirm your booking', 'you@company.com'],
        ['Phone Number', 'phone', 'optional', '', '', '+44 20 7946 0958'],
        ['Preferred Start Date', 'date', 'optional', '', '', ''],
        ['Department', 'dropdown', 'required', 'Engineering|Design|Customer Support', 'Where you will be working', ''],
        ['Employment Type', 'radio', 'optional', 'Full time|Part time|Contract', '', ''],
        ['Equipment Needed', 'checkbox', 'optional', 'Laptop|Monitor|Headset|Docking station', 'Tick everything you need', ''],
        ['Years of Experience', 'number', 'optional', '', 'Whole years', '0'],
        ['How confident are you with our stack?', 'rating', 'optional', '', 'One to five', ''],
        ['Anything Else', 'textarea', 'optional', '', 'Optional, a short paragraph is plenty', ''],
    ];

    $sheet->fromArray($rows, null, 'A1');

    $sheet->getStyle('A1:F1')->getFont()->setBold(true);
    $sheet->getStyle('A1:F1')->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('E8EDF5');
    $sheet->getStyle('A1:F1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

    foreach (range('A', 'F') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $sheet->freezePane('A2');

    (new XlsxWriter($spreadsheet))->save($path);

    $spreadsheet->disconnectWorksheets();
}
