<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color:#111827; padding:20px;">
    <h2 style="color: {{ $decision === 'Approved' ? '#16a34a' : '#dc2626' }};">
        Your {{ $type }} Request was {{ $decision }}
    </h2>
    <table style="border-collapse:collapse; margin:12px 0;">
        <tr><td style="padding:4px 12px 4px 0; color:#6b7280;">Type</td><td>{{ $type }} ({{ $dayType }})</td></tr>
        <tr><td style="padding:4px 12px 4px 0; color:#6b7280;">From</td><td>{{ $fromDate }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0; color:#6b7280;">To</td><td>{{ $toDate }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0; color:#6b7280;">Reviewed by</td><td>{{ $reviewerName }}</td></tr>
        @if($reason)
        <tr><td style="padding:4px 12px 4px 0; color:#6b7280;">Note</td><td>{{ $reason }}</td></tr>
        @endif
    </table>
</body>
</html>
