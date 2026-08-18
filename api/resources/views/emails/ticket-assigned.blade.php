<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $ticket->code }} — {{ $ticket->heading }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f5f7; font-family: Arial, Helvetica, sans-serif;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7; padding:24px 0;">
<tr>
<td align="center">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="background:#ffffff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">

  <!-- Title / subject line -->
  <tr>
    <td style="padding:22px 26px 4px 26px;">
      <a href="{{ $url }}" style="color:#1a5dc8; font-size:17px; font-weight:700; text-decoration:none;">
        #{{ $ticket->code }}: {{ $ticket->heading }}
      </a>
    </td>
  </tr>

  <!-- Who it was assigned to -->
  <tr>
    <td style="padding:6px 26px 0 26px; font-size:13px; color:#374151;">
      Has been assigned to <strong>{{ $ticket->assignedUser->username ?? '' }}</strong>
    </td>
  </tr>

  <!-- Description -->
  @if($ticket->description ?? null)
  <tr>
    <td style="padding:14px 26px 0 26px; font-size:13.5px; color:#111827; line-height:1.6;">
      {!! $ticket->description !!}
    </td>
  </tr>
  @endif

  <!-- Attachment, if any -->
  @if($ticket->attachment ?? null)
  <tr>
    <td style="padding:10px 26px 0 26px;">
      <a href="{{ $ticket->attachment->url ?? '' }}" style="color:#1a5dc8; font-size:13px; text-decoration:none;">
        📎 {{ $ticket->attachment->name ?? 'Attachment' }}
      </a>
    </td>
  </tr>
  @endif

  <!-- Open ticket link -->
  <tr>
    <td style="padding:14px 26px 20px 26px;">
      <a href="{{ $url }}" style="color:#1a5dc8; font-size:13px; font-weight:600; text-decoration:none;">
        Open ticket
      </a>
    </td>
  </tr>

  <!-- Divider -->
  <tr><td style="border-top:1px solid #e5e7eb;"></td></tr>

  <!-- Details grid -->
  <tr>
    <td style="padding:18px 26px 22px 26px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:12.5px; color:#374151;">
        <tr>
          <td style="padding:4px 10px 4px 0; width:50%; vertical-align:top;">
            <span style="color:#6b7280;">Created on:</span> {{ optional($ticket->created_at)->format('Y-m-d') }}
          </td>
          <td style="padding:4px 0 4px 10px; width:50%; vertical-align:top;">
            <span style="color:#6b7280;">Reported by:</span> {{ $ticket->creator->username ?? '—' }}
          </td>
        </tr>
        <tr>
          <td style="padding:4px 10px 4px 0; vertical-align:top;">
            <span style="color:#6b7280;">Status:</span> {{ $ticket->status ?? '—' }}
          </td>
          <td style="padding:4px 0 4px 10px; vertical-align:top;">
            <span style="color:#6b7280;">Priority:</span> {{ $ticket->priority ?? '—' }}
          </td>
        </tr>
        <tr>
          <td style="padding:4px 10px 4px 0; vertical-align:top;">
            <span style="color:#6b7280;">Assigned to:</span> {{ $ticket->assignedUser->username ?? '—' }}
          </td>
          <td style="padding:4px 0 4px 10px; vertical-align:top;">
            <span style="color:#6b7280;">Milestone:</span> {{ $ticket->milestoneRelation->name ?? $ticket->milestone ?? '—' }}
          </td>
        </tr>
        <tr>
          <td style="padding:4px 10px 4px 0; vertical-align:top;">
            <span style="color:#6b7280;">Support view:</span> {{ $ticket->permission_type ?? '—' }}
          </td>
          <td style="padding:4px 0 4px 10px; vertical-align:top;">
            <span style="color:#6b7280;">Due Date:</span> {{ $ticket->due_date ?? '—' }}
          </td>
        </tr>
        <tr>
          <td style="padding:4px 10px 4px 0; vertical-align:top;">
            <span style="color:#6b7280;">Estimated Time:</span> {{ $ticket->estimated_time ?? '—' }}
          </td>
          <td style="padding:4px 0 4px 10px; vertical-align:top;">
            <span style="color:#6b7280;">Responsible 2:</span> {{ $ticket->responsible2User->username ?? '—' }}
          </td>
        </tr>
        <tr>
          <td style="padding:4px 10px 4px 0; vertical-align:top;">
            <span style="color:#6b7280;">Project:</span> {{ $ticket->project->name ?? '—' }}
          </td>
          <td style="padding:4px 0 4px 10px; vertical-align:top;"></td>
        </tr>
      </table>
    </td>
  </tr>

</table>

<!-- Footer -->
<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="margin-top:14px;">
  <tr>
    <td style="font-size:11px; color:#9ca3af; padding:0 26px;">
      This message was sent by EMS.
    </td>
  </tr>
</table>

</td>
</tr>
</table>

</body>
</html>