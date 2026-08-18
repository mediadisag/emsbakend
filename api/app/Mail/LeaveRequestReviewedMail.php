<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveRequestReviewedMail extends Mailable
{
    use Queueable, SerializesModels;

    public LeaveRequest $leave;
    public string $decision; // 'Approved' | 'Rejected'
    public ?string $reason;
    public string $reviewerName;

    public function __construct(LeaveRequest $leave, string $decision, ?string $reason, string $reviewerName)
    {
        $this->leave = $leave;
        $this->decision = $decision;
        $this->reason = $reason;
        $this->reviewerName = $reviewerName;
    }

    public function build()
    {
        return $this->subject("Your {$this->leave->type} request was {$this->decision}")
            ->view('emails.leave-request-reviewed')
            ->with([
                'type'         => $this->leave->type,
                'dayType'      => $this->leave->day_type ?? 'Full Day',
                'fromDate'     => $this->leave->from_date,
                'toDate'       => $this->leave->to_date,
                'decision'     => $this->decision,
                'reason'       => $this->reason,
                'reviewerName' => $this->reviewerName,
            ]);
    }
}
