<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application approved</title>
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

                <p style="margin:0 0 14px;">Good news! Your application for <strong>{{ $scholarship }}</strong> has been
                    <strong style="color:#004d26;">APPROVED</strong>@if($provider) by {{ $provider }}@endif.</p>

                <table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;margin:0 0 16px;background:#f4f6f8;border-radius:8px;">
                    <tr><td style="padding:12px 14px;font-size:14px;">
                        <strong>Scholarship:</strong> {{ $scholarship }}<br>
                        <strong>Status:</strong> Approved
                    </td></tr>
                </table>

                <p style="margin:0 0 8px;"><strong>What happens next</strong></p>
                <ol style="margin:0 0 16px;padding-left:20px;line-height:1.6;">
                    <li>OAS will verify that you are currently enrolled this semester.</li>
                    <li>After that, OAS tags you as a grantee of this scholarship.</li>
                    <li>Wait for the payroll schedule; it will be posted in ScholarGuide announcements.</li>
                    <li>If you have no ATM card yet, or OAS asks for it, visit the OAS office with a valid ID.</li>
                </ol>

                <p style="margin:0 0 20px;">
                    <a href="{{ $link }}" style="display:inline-block;background:#004d26;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:bold;">Open my application</a>
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
