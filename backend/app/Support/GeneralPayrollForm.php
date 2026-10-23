<?php

namespace App\Support;

use App\Models\PayrollRecord;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/*
|--------------------------------------------------------------------------
| General Payroll (General Form No. 6-A, revised July 1, 1995)
|--------------------------------------------------------------------------
| The OAS's official payroll sheet, filled in from ScholarGuide payroll
| entries of ONE program and ONE period:
|   build()  the sheets as data (used by the printable page and by Word)
|   docx()   the same sheets as a Word document (.docx)
| Each sheet holds up to N students (15 on the OAS paper form) with its own
| total; the last sheet ends with "***NOTHING FOLLOWS***" and, when there
| is more than one sheet, the grand total. Students with and without an ATM
| are put on separate sheets ("WITH ATM" / "WITHOUT ATM"), like the form.
| The deduction and witness columns are printed empty, to be filled by hand.
*/
class GeneralPayrollForm
{
    /*
    | $ids      payroll_records ids (one program, one period)
    | $options  batch, voucher, purpose, sheet_start, per_sheet, split_atm,
    |           prepared_name, prepared_title
    */
    public static function build(array $ids, array $options, User $user): array
    {
        $records = PayrollRecord::with([
            'scholarRecord:id,student_id,scholarship_id,has_atm',
            'scholarRecord.student:id,student_id,first_name,middle_name,last_name',
            'scholarRecord.scholarship:id,name,short_name,amount',
        ])->whereIn('id', $ids)->get();

        if ($records->isEmpty()) {
            throw ValidationException::withMessages(['ids' => 'Choose at least one payroll entry.']);
        }
        if ($records->pluck('scholarRecord.scholarship_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['ids' => 'One form is for ONE program. Choose a program tab first.']);
        }
        $periods = $records->pluck('period')->map(fn ($p) => mb_strtolower(trim($p)))->unique();
        if ($periods->count() > 1) {
            throw ValidationException::withMessages(['ids' => 'One form is for ONE period. Choose a period first.']);
        }

        $program = $records->first()->scholarRecord->scholarship;
        $period = trim($records->first()->period);
        [$semIndex, $ay] = self::semester($period);
        $rate = (float) ($program->amount ?? $records->max('amount'));

        // A to Z by last name, like the OAS lists.
        $records = $records->sortBy(fn ($r) => mb_strtolower(
            ($r->scholarRecord->student->last_name ?? '').' '.($r->scholarRecord->student->first_name ?? '')
        ))->values();

        $groups = ($options['split_atm'] ?? true)
            ? [
                'WITH ATM' => $records->filter(fn ($r) => $r->scholarRecord->has_atm)->values(),
                'WITHOUT ATM' => $records->reject(fn ($r) => $r->scholarRecord->has_atm)->values(),
            ]
            : ['' => $records];

        $perSheet = max(5, min(40, (int) ($options['per_sheet'] ?? 15)));
        $sheetNo = max(1, (int) ($options['sheet_start'] ?? 1));
        $sheets = [];

        foreach ($groups as $atmLabel => $group) {
            if ($group->isEmpty()) {
                continue;
            }
            $chunks = $group->chunk($perSheet)->values();
            $groupTotal = 0.0;
            $number = 0;

            foreach ($chunks as $index => $chunk) {
                $rows = [];
                $sheetTotal = 0.0;
                foreach ($chunk as $record) {
                    $amount = (float) $record->amount;
                    $sheetTotal += $amount;
                    $student = $record->scholarRecord->student;
                    $rows[] = [
                        'no' => ++$number,
                        'student_id' => $student->student_id,
                        'name' => self::name($student),
                        'first_sem' => $semIndex === 1 ? $amount : null,
                        'second_sem' => $semIndex === 2 ? $amount : null,
                        'total' => $amount,
                        'net' => $amount,
                    ];
                }
                $groupTotal += $sheetTotal;
                $last = $index === $chunks->count() - 1;

                $sheets[] = [
                    'sheet_no' => $sheetNo++,
                    'atm_label' => $atmLabel,
                    'rows' => $rows,
                    'total' => $sheetTotal,
                    'is_last' => $last,
                    // Grand total of this ATM group, printed on its last sheet when it has several.
                    'grand_total' => $last && $chunks->count() > 1 ? $groupTotal : null,
                ];
            }
        }

        $signatories = Setting::payrollSignatories();
        $signatories = ['prepared' => [
            'name' => trim((string) ($options['prepared_name'] ?? '')) ?: $user->name,
            'title' => trim((string) ($options['prepared_title'] ?? '')) ?: 'OAS Staff',
        ]] + $signatories;

        $short = $program->short_name ?: $program->name;

        return [
            'program' => $program->name,
            'period' => $period,
            'batch' => trim((string) ($options['batch'] ?? '')) ?: mb_strtoupper($short).' GRANTEES BATCH 1',
            'voucher' => trim((string) ($options['voucher'] ?? '')),
            'purpose' => trim((string) ($options['purpose'] ?? '')) ?: $period.' Stipend',
            'first_sem_label' => '1ST SEM.'.($ay ? ' '.$ay : ''),
            'second_sem_label' => '2ND SEM.'.($ay ? ' '.$ay : ''),
            'rate' => $rate,
            'count' => $records->count(),
            'grand_total' => (float) $records->sum('amount'),
            'sheets' => $sheets,
            'signatories' => $signatories,
        ];
    }

    /** [1 or 2, "2026-2027" or null] from "1st Semester AY 2026-2027". Summer goes in the first column. */
    private static function semester(string $period): array
    {
        $ay = preg_match('/(\d{4})\s*-\s*(\d{4})/', $period, $m) ? "{$m[1]}-{$m[2]}" : null;

        return [str_starts_with(mb_strtolower($period), '2nd') ? 2 : 1, $ay];
    }

    // "DELA CRUZ, JUAN S."
    private static function name($student): string
    {
        $middle = trim((string) $student->middle_name);
        $initial = $middle !== '' && mb_strtolower($middle) !== 'demo' ? ' '.mb_substr($middle, 0, 1).'.' : '';

        return mb_strtoupper(trim($student->last_name).', '.trim($student->first_name).$initial);
    }

    public static function money(?float $value): string
    {
        return $value === null ? '' : number_format($value, 2);
    }

    /* ------------------------------------------------------------------ Word */

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** One run of text. $o: b (bold), i (italic), sz (half-points), color, u (underline). */
    private static function run(string $text, array $o = []): string
    {
        $pr = '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
            .(!empty($o['b']) ? '<w:b/>' : '')
            .(!empty($o['i']) ? '<w:i/>' : '')
            .(!empty($o['u']) ? '<w:u w:val="single"/>' : '')
            .(!empty($o['color']) ? '<w:color w:val="'.$o['color'].'"/>' : '')
            .'<w:sz w:val="'.($o['sz'] ?? 15).'"/><w:szCs w:val="'.($o['sz'] ?? 15).'"/>';

        return '<w:r><w:rPr>'.$pr.'</w:rPr><w:t xml:space="preserve">'.self::esc($text).'</w:t></w:r>';
    }

    /** A paragraph. $align: left|center|right. */
    private static function p(string $runs, string $align = 'left', int $after = 0): string
    {
        $jc = ['left' => 'left', 'center' => 'center', 'right' => 'right'][$align];

        return '<w:p><w:pPr><w:spacing w:before="0" w:after="'.$after.'" w:line="220" w:lineRule="auto"/><w:jc w:val="'.$jc.'"/></w:pPr>'.$runs.'</w:p>';
    }

    /** A table cell. $o: w (twips), fill, span, align, vmerge, noborder. */
    private static function cell(string $content, array $o = []): string
    {
        $pr = '<w:tcW w:w="'.($o['w'] ?? 600).'" w:type="dxa"/>'
            .(!empty($o['span']) ? '<w:gridSpan w:val="'.$o['span'].'"/>' : '')
            .(isset($o['vmerge']) ? '<w:vMerge'.($o['vmerge'] === 'restart' ? ' w:val="restart"' : '').'/>' : '')
            // Borders on every cell of the main table (some Word viewers ignore table-level borders).
            .(!empty($o['noborder'])
                ? '<w:tcBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders>'
                : '<w:tcBorders>'.implode('', array_map(fn ($side) => "<w:{$side} w:val=\"single\" w:sz=\"6\" w:space=\"0\" w:color=\"333333\"/>", ['top', 'left', 'bottom', 'right'])).'</w:tcBorders>')
            .(!empty($o['fill']) ? '<w:shd w:val="clear" w:color="auto" w:fill="'.$o['fill'].'"/>' : '')
            .'<w:vAlign w:val="'.($o['valign'] ?? 'center').'"/>';

        return '<w:tc><w:tcPr>'.$pr.'</w:tcPr>'.($content !== '' ? $content : self::p('')).'</w:tc>';
    }

    /** Word document (.docx) with every sheet on its own landscape long-bond page. */
    public static function docx(array $form): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('PHP zip extension is off. Turn on "extension=zip" in php.ini to make Word files.');
        }

        // Column widths in twips (1 inch = 1440). Total = 18000 (12.5 in).
        $w = [500, 1150, 2900, 750, 1100, 1100, 400, 400, 1150, 400, 1050, 1000, 400, 400, 1150, 1300, 2850];
        $grid = '<w:tblGrid>'.implode('', array_map(fn ($x) => '<w:gridCol w:w="'.$x.'"/>', $w)).'</w:tblGrid>';
        $border = '<w:tblBorders>'.implode('', array_map(fn ($s) => "<w:{$s} w:val=\"single\" w:sz=\"6\" w:space=\"0\" w:color=\"333333\"/>",
            ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'])).'</w:tblBorders>';
        $noBorder = '<w:tblBorders>'.implode('', array_map(fn ($s) => "<w:{$s} w:val=\"nil\"/>",
            ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'])).'</w:tblBorders>';
        $money = fn ($v) => self::money($v);
        $red = 'C0392B';
        $sig = $form['signatories'];
        $person = fn (string $slot, int $sz = 16) => self::p(self::run(mb_strtoupper($sig[$slot]['name'] ?: '______________________________'), ['b' => true, 'sz' => $sz]))
            .self::p(self::run((string) $sig[$slot]['title'], ['sz' => 15]));

        $body = '';
        foreach ($form['sheets'] as $i => $sheet) {
            // ---- top: form number, title, sheet no, voucher, release banner
            $left = self::p(self::run('General Form No.(6 A)', ['sz' => 15]))
                .self::p(self::run('(Revised July 1,1995)', ['sz' => 15]), 'left', 80)
                .self::p(self::run('GENERAL PAYROLL', ['b' => true, 'sz' => 28]), 'center', 80)
                .self::p(self::run('We hereby acknowledge to have received of the sum herein specified opposite our respective names being full compensation for our services for the period', ['sz' => 15]));
            $right = self::p(self::run('Sheet No: '.$sheet['sheet_no'], ['sz' => 15]))
                .self::p(self::run('Journal Voucher: ', ['sz' => 15]).self::run($form['voucher'] ?: '__________', ['b' => true, 'sz' => 20, 'color' => 'D35400']), 'left', 60)
                .self::p(self::run('Release of ', ['sz' => 16]).self::run($form['batch'], ['b' => true, 'u' => true, 'sz' => 22, 'color' => 'D35400']).self::run(' balance for the sum herein specified', ['sz' => 16]))
                .self::p(self::run($form['purpose'], ['sz' => 16]))
                .($sheet['atm_label'] ? self::p(self::run($sheet['atm_label'], ['b' => true, 'sz' => 16, 'color' => $red]), 'right') : '');
            $body .= '<w:tbl><w:tblPr><w:tblW w:w="18000" w:type="dxa"/>'.$noBorder.'<w:tblLayout w:type="fixed"/></w:tblPr>'
                .'<w:tblGrid><w:gridCol w:w="9600"/><w:gridCol w:w="8400"/></w:tblGrid><w:tr>'
                .self::cell($left, ['w' => 9600, 'valign' => 'top', 'noborder' => true])
                .self::cell($right, ['w' => 8400, 'valign' => 'top', 'fill' => 'FFF3B0', 'noborder' => true])
                .'</w:tr></w:tbl>';

            // ---- the table
            $h = fn (string $t, int $wi, array $o = []) => self::cell(self::p(self::run($t, ['b' => true, 'sz' => 14]), 'center'), ['w' => $wi] + $o);
            $rows = '<w:tr><w:trPr><w:tblHeader/></w:trPr>'
                .$h('No.', $w[0]).$h('ID NO.', $w[1]).$h('NAME', $w[2]).$h('Position', $w[3])
                .self::cell(self::p(self::run($form['first_sem_label'], ['b' => true, 'sz' => 14]), 'center').self::p(self::run($money($form['rate']), ['sz' => 14]), 'center'), ['w' => $w[4]])
                .self::cell(self::p(self::run($form['second_sem_label'], ['b' => true, 'sz' => 14]), 'center').self::p(self::run($money($form['rate']), ['sz' => 14]), 'center'), ['w' => $w[5]])
                .$h('', $w[6]).$h('', $w[7]).$h('TOTAL', $w[8]).$h('', $w[9])
                .self::cell(self::p(self::run('Less: Miscellaneous', ['b' => true, 'sz' => 12])), ['w' => $w[10]])
                .self::cell(self::p(self::run('CHED Grants', ['b' => true, 'sz' => 12])), ['w' => $w[11]])
                .$h('', $w[12]).$h('', $w[13]).$h('TOTAL DEDUCTIONS', $w[14]).$h('Net Amount Received', $w[15]).$h('Initial of Witness to Payment', $w[16])
                .'</w:tr>';

            $num = fn ($v, array $o = []) => self::p(self::run($money($v), ['sz' => 17] + $o), 'right');
            foreach ($sheet['rows'] as $r) {
                $rows .= '<w:tr><w:trPr><w:trHeight w:val="400"/></w:trPr>'
                    .self::cell(self::p(self::run((string) $r['no'], ['sz' => 17]), 'right'), ['w' => $w[0]])
                    .self::cell(self::p(self::run($r['student_id'], ['sz' => 17])), ['w' => $w[1]])
                    .self::cell(self::p(self::run($r['name'], ['sz' => 17])), ['w' => $w[2]])
                    .self::cell('', ['w' => $w[3]])
                    .self::cell($num($r['first_sem']), ['w' => $w[4]])
                    .self::cell($num($r['second_sem']), ['w' => $w[5]])
                    .self::cell('', ['w' => $w[6]]).self::cell('', ['w' => $w[7]])
                    .self::cell($num($r['total']), ['w' => $w[8]])
                    .self::cell('', ['w' => $w[9]]).self::cell('', ['w' => $w[10]]).self::cell('', ['w' => $w[11]])
                    .self::cell('', ['w' => $w[12]]).self::cell('', ['w' => $w[13]]).self::cell('', ['w' => $w[14]])
                    .self::cell($num($r['net']), ['w' => $w[15]])
                    .self::cell(self::p(self::run((string) $r['no'], ['sz' => 13])), ['w' => $w[16]])
                    .'</w:tr>';
            }

            $dash = fn ($wi) => self::cell(self::p(self::run('-', ['sz' => 15, 'color' => $red]), 'center'), ['w' => $wi]);
            $firstTotal = $sheet['rows'] && $sheet['rows'][0]['first_sem'] !== null ? $sheet['total'] : null;
            $secondTotal = $sheet['rows'] && $sheet['rows'][0]['second_sem'] !== null ? $sheet['total'] : null;
            $rows .= '<w:tr>'
                .self::cell('', ['w' => $w[0]]).self::cell('', ['w' => $w[1]])
                .self::cell(self::p(self::run($sheet['is_last'] ? '***NOTHING FOLLOWS***' : 'continued on the next sheet', ['b' => true, 'i' => true, 'sz' => 15]), 'center'), ['w' => $w[2]])
                .self::cell(self::p(self::run('TOTAL', ['b' => true, 'sz' => 15])), ['w' => $w[3]])
                .($firstTotal !== null ? self::cell($num($firstTotal, ['b' => true, 'color' => $red]), ['w' => $w[4]]) : $dash($w[4]))
                .($secondTotal !== null ? self::cell($num($secondTotal, ['b' => true, 'color' => $red]), ['w' => $w[5]]) : $dash($w[5]))
                .$dash($w[6]).$dash($w[7])
                .self::cell($num($sheet['total'], ['b' => true, 'color' => $red]), ['w' => $w[8]])
                .self::cell('', ['w' => $w[9]]).$dash($w[10]).$dash($w[11]).self::cell('', ['w' => $w[12]]).self::cell('', ['w' => $w[13]])
                .$dash($w[14])
                .self::cell($num($sheet['total'], ['b' => true, 'color' => $red]), ['w' => $w[15]])
                .self::cell('', ['w' => $w[16]])
                .'</w:tr>';
            if ($sheet['grand_total'] !== null) {
                $rows .= '<w:tr>'.self::cell(self::p(self::run('GRAND TOTAL ('.$sheet['atm_label'].', all sheets)', ['b' => true, 'sz' => 15]), 'right'), ['w' => array_sum(array_slice($w, 0, 8)), 'span' => 8])
                    .self::cell($num($sheet['grand_total'], ['b' => true, 'color' => $red]), ['w' => $w[8]])
                    .self::cell('', ['w' => array_sum(array_slice($w, 9, 6)), 'span' => 6])
                    .self::cell($num($sheet['grand_total'], ['b' => true, 'color' => $red]), ['w' => $w[15]])
                    .self::cell('', ['w' => $w[16]]).'</w:tr>';
            }
            $body .= '<w:tbl><w:tblPr><w:tblW w:w="18000" w:type="dxa"/>'.$border.'<w:tblLayout w:type="fixed"/>'
                .'<w:tblCellMar><w:left w:w="50" w:type="dxa"/><w:right w:w="50" w:type="dxa"/></w:tblCellMar></w:tblPr>'.$grid.$rows.'</w:tbl>';

            $body .= self::p('', 'left', 120);

            // ---- certifications and signatures (borderless table, 4 columns)
            $c = fn (string $content, int $wi) => self::cell($content, ['w' => $wi, 'valign' => 'top', 'noborder' => true]);
            $small = fn (string $t) => self::p(self::run($t, ['sz' => 14]), 'left', 40);
            $label = fn (string $t) => self::p(self::run($t, ['b' => true, 'sz' => 15]), 'left', 120);
            $body .= '<w:tbl><w:tblPr><w:tblW w:w="18000" w:type="dxa"/>'.$noBorder.'<w:tblLayout w:type="fixed"/></w:tblPr>'
                .'<w:tblGrid><w:gridCol w:w="4300"/><w:gridCol w:w="4300"/><w:gridCol w:w="4300"/><w:gridCol w:w="5100"/></w:tblGrid>'
                .'<w:tr>'
                .$c($small('(1.) I certify on my oath above Payroll is correct and that the services have been duly rendered as stated.').$label('Prepared By:').$person('prepared'), 4300)
                .$c($small(' ').$label('Noted by:').$person('coordinator'), 4300)
                .$c($small(' ').$label(' ').$person('director'), 4300)
                .$c($small('(3.) I certify on my oath that I have paid to each employee whose name appears on the roll the amount set opposite his name, he having presented his Residence Certificate.')
                    .$label('As to correctness of enrollment data:').$person('registrar'), 5100)
                .'</w:tr><w:tr>'
                .$c($small('(2.) Approved from appropriate for').$label('Funds Available:').$person('accountant'), 4300)
                .$c($small(' ').$label(' ').$person('president'), 4300)
                .$c($small(' ').$label(' ').$person('vp'), 4300)
                .$c($small('(4.) I certify on my oath that I have witnessed payment to each person whose name appears hereon, of the amount set opposite his name my initials.')
                    .$label(' ').$person('cashier').$small('______________________________   ______________________________'), 5100)
                .'</w:tr></w:tbl>';

            $body .= self::p(self::run('Prepared in ScholarGuide · '.$form['program'].' · '.$form['period'].' · sheet '.$sheet['sheet_no'], ['sz' => 12, 'color' => '888888']), 'right');

            if ($i < count($form['sheets']) - 1) {
                $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            }
        }

        // Long bond (8.5 x 13 in), landscape, 0.4 in margins.
        $section = '<w:sectPr><w:pgSz w:w="18720" w:h="12240" w:orient="landscape"/>'
            .'<w:pgMar w:top="500" w:right="360" w:bottom="500" w:left="360" w:header="0" w:footer="0" w:gutter="0"/></w:sectPr>';

        $path = tempnam(sys_get_temp_dir(), 'payroll');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.$section.'</w:body></w:document>');
        $zip->close();

        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
