{{-- Forwarded to Agency as a PDF (dompdf: tables and simple CSS only). A plain list, not an agency form. --}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0.45in 0.4in; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 8.5pt; color: #111; }
    h1 { font-size: 13pt; margin: 0 0 2px; color: #004d26; }
    .sub { color: #555; margin: 0 0 10px; font-size: 8pt; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #e3efe8; text-align: left; font-size: 7.5pt; padding: 4px; border-bottom: 1px solid #999; }
    td { padding: 4px; border-bottom: 1px solid #ddd; vertical-align: top; }
    tr:nth-child(even) td { background: #f7f7f7; }
    .group td { background: #fff !important; font-weight: bold; color: #004d26; padding-top: 10px; border-bottom: 1px solid #004d26; }
    .foot { margin-top: 10px; font-size: 7pt; color: #777; }
</style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="sub">Caraga State University · Office of Admission and Scholarship · exported {{ now('Asia/Manila')->format('F j, Y g:i A') }} · {{ $apps->count() }} application(s)</p>
    <table>
        <thead>
            <tr>
                <th style="width: 3%;">No.</th><th style="width: 9%;">Student ID</th><th style="width: 19%;">Name</th>
                <th style="width: 17%;">Course / year</th><th style="width: 14%;">College</th><th style="width: 10%;">Contact</th>
                <th style="width: 18%;">Email</th><th style="width: 10%;">Forwarded on</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($apps->groupBy(fn ($a) => $a->scholarship?->name) as $program => $group)
                <tr class="group"><td colspan="8">{{ $program }}{{ $group->first()->scholarship?->provider ? ' · '.$group->first()->scholarship->provider : '' }} ({{ $group->count() }})</td></tr>
                @foreach ($group->values() as $n => $a)
                    @php($s = $a->student)
                    <tr>
                        <td>{{ $n + 1 }}</td>
                        <td>{{ $s?->student_id }}</td>
                        <td>{{ mb_strtoupper((string) $s?->last_name) }}, {{ $s?->first_name }} {{ $s?->middle_name && $s->middle_name !== 'Demo' ? mb_substr($s->middle_name, 0, 1).'.' : '' }}</td>
                        <td>{{ $s?->course }}{{ $s?->year_level ? ' · '.$s->year_level : '' }}</td>
                        <td>{{ $s?->college }}</td>
                        <td>{{ $s?->contact_number }}</td>
                        <td>{{ $s?->user?->email }}</td>
                        <td>{{ $a->forwarded_at?->format('M j, Y') }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
    <p class="foot">A plain list of the student details OAS keeps; not an agency's official form.</p>
</body>
</html>
