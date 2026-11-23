{{-- Billing statement as a PDF (dompdf: tables and simple CSS only). Column order of the CHED TES billing list; not the official form. --}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0.4in 0.35in; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 7.5pt; color: #111; }
    h1 { font-size: 12pt; margin: 0 0 2px; }
    .sub { color: #444; margin: 0 0 8px; font-size: 7.5pt; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 6.8pt; padding: 3px; border: 1px solid #555; background: #eee; }
    td { padding: 3px; border: 1px solid #999; vertical-align: top; }
    td.num { text-align: right; white-space: nowrap; }
    tr.total td { font-weight: bold; border-top: 2px solid #111; }
    .fill { color: #a00; font-weight: bold; }
    .foot { margin-top: 8px; font-size: 7pt; color: #555; }
</style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="sub">Caraga State University · Office of Admission and Scholarship · prepared {{ now('Asia/Manila')->format('F j, Y g:i A') }} · {{ count($rows) }} grantee(s)</p>
    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $i => $value)
                        <td class="{{ in_array($i, [13, 15], true) ? 'num' : '' }}">
                            @if ($value === 'TO FILL IN')
                                <span class="fill">to fill in</span>
                            @else
                                {{ $value }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="15">TOTAL</td>
                <td class="num">{{ number_format($total, 2) }}</td>
            </tr>
        </tbody>
    </table>
    <p class="foot">{{ $note }}</p>
</body>
</html>
