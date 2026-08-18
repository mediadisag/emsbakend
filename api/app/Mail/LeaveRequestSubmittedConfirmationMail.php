<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveRequestSubmittedConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public LeaveRequest $leave;

    public function __construct(LeaveRequest $leave)
    {
        $this->leave = $leave;
    }

    public function build()
    {
        return $this->subject("Your {$this->leave->type} request has been submitted")
            ->view('emails.leave-request-submitted-confirmation')
            ->with([
                'type'     => $this->leave->type,
                'fromDate' => $this->leave->from_date,
                'toDate'   => $this->leave->to_date,
                'reason'   => $this->leave->reason,
            ]);
    }
}
