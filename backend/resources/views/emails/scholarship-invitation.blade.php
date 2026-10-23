<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scholarship invitation</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e3e3e3;">
        <tr>
            <td style="background:#004d26;color:#ffffff;padding:20px 24px;border-bottom:4px solid #d4af37;">
                <div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#d4af37;">Caraga State University &middot; Office of Admission and Scholarship</div>
                <div style="font-size:22px;font-weight:bold;margin-top:6px;">ScholarGuide</div>
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 14px;">Hello {{ $studentName }} ({{ $studentId }}),</p>

                @if($mode === 'added')
                    <p style="margin:0 0 14px;">The OAS started an application for you for <strong>{{ $programName }}</strong>@if($provider) ({{ $provider }})@endif,
                        because your records show you may be eligible.</p>
                @else
                    <p style="margin:0 0 14px;">Based on your records, you <strong>may be eligible</strong> for
                        <strong>{{ $programName }}</strong>@if($provider) ({{ $provider }})@endif. The OAS invites you to look at it.</p>
                @endif

                @if($note)
                    <p style="margin:0 0 14px;padding:10px 14px;background:#f4f6f8;border-radius:8px;"><strong>Note from the OAS:</strong> {{ $note }}</p>
                @endif

                <p style="margin:0 0 8px;"><strong>What to do next</strong></p>
                <ol style="margin:0 0 16px;padding-left:20px;line-height:1.6;">
                    @if($agencyDirect)
                        <li>This scholarship is applied for directly at {{ $provider ?: 'the agency' }}. Open the link to read how.</li>
                    @elseif($mode === 'added')
                        <li>Open your application in ScholarGuide.</li>
                        <li>Attach the required documents (you can reuse the ones in My Documents).</li>
                        <li>Press <strong>Submit application</strong>@if($deadline) before {{ $deadline }}@endif.</li>
                    @else
                        <li>Open the scholarship in ScholarGuide and read the requirements.</li>
                        <li>If you want to apply, press <strong>Apply</strong> and submit your application{{ $deadline ? ' before '.$deadline : '' }}.</li>
                    @endif
                    <li>This is an invitation, not an approval. The OAS still reviews your documents, and the scholarship's agency makes the final decision.</li>
                </ol>

                <p style="margin:0 0 20px;">
                    <a href="{{ $link }}" style="display:inline-block;background:#004d26;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:bold;">
                        {{ $mode === 'added' ? 'Open my application' : 'View the scholarship' }}
                    </a>
                </p>

                <p style="margin:0;font-size:12px;color:#5f5f5f;">This is an automatic message from ScholarGuide. Please do not reply.
                    Capstone prototype: all accounts and records are fictional.</p>
            </td>
        </tr>
        <tr>
            <td style="padding:12px 24px;background:#f4f6f8;font-size:12px;color:#5f5f5f;text-align:center;">Competence. Service. Uprightness.</td>
        </tr>
    </table>
</body>
</html>
