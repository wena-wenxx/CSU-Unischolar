{{--
  General Payroll (General Form No. 6-A) as a PDF, made with dompdf.
  dompdf understands tables and simple CSS (no flex or grid), so the layout
  is built from tables. Same content as the printable page and the Word file.
  Paper: long bond 8.5 x 13 in, landscape (set in GeneralPayrollForm::pdf).
--}}
@php
    $peso = fn ($v) => $v === null ? '' : number_format((float) $v, 2);
    $s = $form['signatories'];
    $person = fn ($p) => '<div class="person"><strong>'.e($p['name'] ? mb_strtoupper($p['name']) : ' ').'</strong><span>'.e($p['title'] ?? '').'</span></div>';
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0.3in 0.3in; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 8.5pt; color: #111; margin: 0; }
    p { margin: 0; }
    .sheet { page-break-after: always; }
    .sheet.last { page-break-after: auto; }
    table { border-collapse: collapse; width: 100%; }
    .head td { vertical-align: top; padding: 0; }
    .formno { font-size: 7.5pt; }
    h1 { font-size: 16pt; text-align: center; margin: 4px 0 5px; letter-spacing: 0.5px; }
    .ack { font-size: 8pt; }
    .right p { margin-bottom: 3px; font-size: 8pt; }
    .voucher { background: #ffeb3b; border: 1px solid #333; color: #c25400; font-weight: bold; font-size: 10pt; padding: 1px 10px; }
    .banner { background: #ffeb3b; padding: 3px 4px; }
    .batch { color: #c25400; font-weight: bold; font-size: 10.5pt; text-decoration: underline; }
    .purpose { border-bottom: 1px solid #333; padding: 0 4px 2px; }
    .atm { color: #c0392b; font-weight: bold; text-align: right; }
    .grid { table-layout: fixed; margin-top: 6px; }
    .grid th, .grid td { border: 1px solid #333; padding: 2px 3px; }
    .grid th { font-size: 7pt; text-align: center; vertical-align: middle; }
    .grid th small { display: block; font-weight: normal; font-size: 6pt; }
    .grid td { height: 18px; font-size: 8pt; white-space: nowrap; overflow: hidden; }
    .grid td.nothing { white-space: normal; }
    .num { text-align: right; }
    .center { text-align: center; }
    .red { color: #c0392b; font-weight: bold; }
    .nothing { text-align: center; font-weight: bold; font-style: italic; }
    .witness { font-size: 6pt; vertical-align: top; }
    .signs { margin-top: 8px; table-layout: fixed; }
    .signs td { vertical-align: bottom; padding: 1px 10px 1px 0; font-size: 7.5pt; }
    .signs .cert-row td { font-size: 7pt; padding-top: 5px; }
    .signs .label-row td { font-weight: bold; font-size: 8pt; }
    .signs .people td { height: 34px; }
    .signs .rule td { border-bottom: 1px solid #333; padding-bottom: 3px; }
    .person strong { display: block; font-size: 8pt; }
    .person span { font-size: 7.5pt; }
    .wline { border-bottom: 1px solid #333; height: 12px; margin-bottom: 4px; }
    .foot { text-align: right; font-size: 6pt; color: #777; margin-top: 4px; }
</style>
</head>
<body>
@foreach ($form['sheets'] as $i => $sheet)
    @php
        $firstUsed = collect($sheet['rows'])->contains(fn ($r) => $r['first_sem'] !== null);
        $secondUsed = collect($sheet['rows'])->contains(fn ($r) => $r['second_sem'] !== null);
        $dash = '<span class="red">-</span>';
    @endphp
    <div class="sheet {{ $loop->last ? 'last' : '' }}">
        <table class="head">
            <tr>
                <td style="width: 55%; padding-right: 14px;">
                    <p class="formno">General Form No.(6 A)<br>(Revised July 1,1995)</p>
                    <h1>GENERAL PAYROLL</h1>
                    <p class="ack">We hereby acknowledge to have received of the sum herein specified opposite our respective names being full compensation for our services for the period</p>
                </td>
                <td class="right" style="width: 45%;">
                    <p>Sheet No: {{ $sheet['sheet_no'] }}</p>
                    <p>Journal Voucher: <span class="voucher">{{ $form['voucher'] ?: ' ' }}</span></p>
                    <p class="banner">Release of <span class="batch">{{ $form['batch'] }}</span> balance for the sum herein specified</p>
                    <p class="purpose">{{ $form['purpose'] }}</p>
                    @if ($sheet['atm_label'])
                        <p class="atm">{{ $sheet['atm_label'] }}</p>
                    @endif
                </td>
            </tr>
        </table>

        <table class="grid">
            <thead>
                @php
                    // Column widths (%): dompdf reads them from the first header row.
                    $sem = fn ($label) => e($label).'<small>'.$peso($form['rate']).'</small>';
                @endphp
                {{-- Two header rows, like the OAS form: "Less: Miscellaneous / CHED Grants" over two empty sub-columns. --}}
                <tr>
                    <th rowspan="2" style="width: 2.8%;">No.</th>
                    <th rowspan="2" style="width: 6.4%;">ID NO.</th>
                    <th rowspan="2" style="width: 16%;">NAME</th>
                    <th rowspan="2" style="width: 4.2%;">Position</th>
                    <th rowspan="2" style="width: 6.1%;">{!! $sem($form['first_sem_label']) !!}</th>
                    <th rowspan="2" style="width: 6.1%;">{!! $sem($form['second_sem_label']) !!}</th>
                    <th rowspan="2" style="width: 2.2%;"></th>
                    <th rowspan="2" style="width: 2.2%;"></th>
                    <th rowspan="2" style="width: 6.4%;">TOTAL</th>
                    <th rowspan="2" style="width: 2.2%;"></th>
                    <th colspan="2" style="width: 11.4%; text-align: left;">Less: Miscellaneous<br>CHED Grants</th>
                    <th rowspan="2" style="width: 2.2%;"></th>
                    <th rowspan="2" style="width: 2.2%;"></th>
                    <th rowspan="2" style="width: 6.4%;">TOTAL DEDUCTIONS</th>
                    <th rowspan="2" style="width: 7.2%;">Net Amount Received</th>
                    <th rowspan="2" style="width: 15.8%;">Initial of Witness to Payment</th>
                </tr>
                <tr>
                    <th style="width: 5.8%; height: 8px; padding: 0;"></th>
                    <th style="width: 5.6%; height: 8px; padding: 0;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sheet['rows'] as $row)
                    <tr>
                        <td class="num">{{ $row['no'] }}</td>
                        <td>{{ $row['student_id'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td></td>
                        <td class="num">{{ $peso($row['first_sem']) }}</td>
                        <td class="num">{{ $peso($row['second_sem']) }}</td>
                        <td></td><td></td>
                        <td class="num">{{ $peso($row['total']) }}</td>
                        <td></td><td></td><td></td><td></td><td></td><td></td>
                        <td class="num">{{ $peso($row['net']) }}</td>
                        <td class="witness">{{ $row['no'] }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td></td><td></td>
                    <td class="nothing">{{ $sheet['is_last'] ? '***NOTHING FOLLOWS***' : 'continued on the next sheet' }}</td>
                    <td><strong>TOTAL</strong></td>
                    <td class="num red">{!! $firstUsed ? e($peso($sheet['total'])) : $dash !!}</td>
                    <td class="num red">{!! $secondUsed ? e($peso($sheet['total'])) : $dash !!}</td>
                    <td class="center">{!! $dash !!}</td><td class="center">{!! $dash !!}</td>
                    <td class="num red">{{ $peso($sheet['total']) }}</td>
                    <td></td>
                    <td class="center">{!! $dash !!}</td><td class="center">{!! $dash !!}</td>
                    <td></td><td></td>
                    <td class="center">{!! $dash !!}</td>
                    <td class="num red">{{ $peso($sheet['total']) }}</td>
                    <td></td>
                </tr>
                @if ($sheet['grand_total'] !== null)
                    <tr>
                        <td colspan="8" class="num"><strong>GRAND TOTAL ({{ $sheet['atm_label'] ?: 'all' }}, all sheets)</strong></td>
                        <td class="num red">{{ $peso($sheet['grand_total']) }}</td>
                        <td colspan="6"></td>
                        <td class="num red">{{ $peso($sheet['grand_total']) }}</td>
                        <td></td>
                    </tr>
                @endif
            </tbody>
        </table>

        {{-- Signatures in the OAS layout: 5 across, a line, then 3 + the witness lines. --}}
        <table class="signs">
            <tr class="cert-row">
                <td colspan="2">(1.) I certify on my oath above Payroll is correct and that the services have been duly rendered as stated.</td>
                <td></td>
                <td colspan="2">(3.) I certify on my oath that I have paid to each employee whose name appears on the roll the amount set opposite his name, he having presented his Residence Certificate.</td>
            </tr>
            <tr class="label-row">
                <td style="width: 20%;">Prepared By:</td><td style="width: 20%;">Noted by:</td><td style="width: 20%;"></td>
                <td style="width: 20%;">As to correctness of enrollment data:</td><td style="width: 20%;"></td>
            </tr>
            <tr class="people rule">
                <td>{!! $person($s['prepared']) !!}</td><td>{!! $person($s['coordinator']) !!}</td><td>{!! $person($s['director']) !!}</td>
                <td>{!! $person($s['registrar']) !!}</td><td>{!! $person($s['vp']) !!}</td>
            </tr>
            <tr class="cert-row">
                <td colspan="3">(2.) Approved from appropriate for</td>
                <td colspan="2">(4.) I certify on my oath that I have witnessed payment to each person whose name appears hereon, of the amount set opposite his name my initials.</td>
            </tr>
            <tr class="label-row"><td colspan="3">Funds Available:</td><td colspan="2"></td></tr>
            <tr class="people">
                <td>{!! $person($s['accountant']) !!}</td><td>{!! $person($s['president']) !!}</td><td></td>
                <td>{!! $person($s['cashier']) !!}</td>
                <td><div class="wline"></div><div class="wline"></div></td>
            </tr>
        </table>

        <p class="foot">Prepared in ScholarGuide · {{ $form['program'] }} · {{ $form['period'] }} · sheet {{ $sheet['sheet_no'] }}</p>
    </div>
@endforeach
</body>
</html>
