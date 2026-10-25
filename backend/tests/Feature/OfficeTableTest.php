<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OfficeTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/*
| Word and Excel lists (Approved Lists, Enrollment) are read by Laravel itself,
| so they work even when the AI service is not running. The files are built
| here; the names are fictional.
*/
class OfficeTableTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX = [
        'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="List" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Student No.</t></si><si><t>Name</t></si><si><r><t>NAVARRO, </t></r><r><t>Juan L.</t></r></si></sst>',
        'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="D1" t="inlineStr"><is><t>Date</t></is></c></row>'
            .'<row r="2"><c r="A2"><v>202600001</v></c><c r="B2" t="s"><v>2</v></c><c r="D2" s="1"><v>46317</v></c></row>'
            .'</sheetData></worksheet>',
    ];

    private const DOCX = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:tbl>'
        .'<w:tr><w:tc><w:p><w:r><w:t>Student No.</w:t></w:r></w:p></w:tc><w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr><w:p><w:r><w:t>Name</w:t></w:r></w:p></w:tc></w:tr>'
        .'<w:tr><w:tc><w:p><w:r><w:t>2026-00001</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>NAVARRO</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Juan L.</w:t></w:r></w:p></w:tc></w:tr>'
        .'</w:tbl></w:body></w:document>';

    private function zip(string $extension, array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ot').'.'.$extension;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();

        return $path;
    }

    public function test_excel_cells_numbers_and_dates_are_read(): void
    {
        $result = OfficeTable::read($this->zip('xlsx', self::XLSX), 'xlsx');

        $this->assertSame('excel', $result['method']);
        $this->assertSame(['Student No.', 'Name', '', 'Date'], $result['rows'][0]);
        $this->assertSame(['202600001', 'NAVARRO, Juan L.', '', '2026-10-22'], $result['rows'][1]);
    }

    public function test_word_tables_keep_their_columns_lined_up(): void
    {
        $result = OfficeTable::read($this->zip('docx', ['word/document.xml' => self::DOCX]), 'docx');

        $this->assertSame('word-table', $result['method']);
        $this->assertSame([['Student No.', 'Name', ''], ['2026-00001', 'NAVARRO', 'Juan L.']], $result['rows']);
    }

    public function test_staff_can_read_word_and_excel_lists_without_the_ai_service(): void
    {
        Http::preventStrayRequests();   // fails the test if the AI service is called
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));

        foreach (['xlsx' => $this->zip('xlsx', self::XLSX), 'docx' => $this->zip('docx', ['word/document.xml' => self::DOCX])] as $ext => $path) {
            $this->post('/api/staff/read-table', ['file' => new UploadedFile($path, "approved.{$ext}", null, null, true)], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('rows.1.1', $ext === 'xlsx' ? 'NAVARRO, Juan L.' : 'NAVARRO');
        }
    }

    public function test_a_renamed_old_file_gets_a_clear_message(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $path = tempnam(sys_get_temp_dir(), 'old').'.xlsx';
        file_put_contents($path, "\xD0\xCF\x11\xE0".str_repeat("\0", 100));   // how an old .xls starts

        $this->post('/api/staff/read-table', ['file' => new UploadedFile($path, 'old.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The file could not be read: This file is not a real .xlsx file (it may be an old .doc/.xls that was renamed). Open it in Word or Excel and use "Save As" .xlsx.']);
    }
}
