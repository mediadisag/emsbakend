<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color:#111827; padding:20px;">
    <h2 style="color:#1a5dc8;">New {{ $type }} Request</h2>
    <p><strong>{{ $employeeName }}</strong> has requested {{ $type }} ({{ $dayType }}).</p>
    <table style="border-collapse:collapse; margin:12px 0;">
        <tr><td style="padding:4px 12px 4px 0; color:#6b7280;">From</td><td>{{ $fromDate }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0; color:#6b7280;">To</td><td>{{ $toDate }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0; color:#6b7280;">Reason</td><td>{{ $reason }}</td></tr>
    </table>
    <p>Please log in to the EMS dashboard to approve or reject this request.</p>
</body>
</html>
