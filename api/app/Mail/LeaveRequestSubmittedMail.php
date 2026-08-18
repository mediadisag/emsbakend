<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public LeaveRequest $leave;
    public string $employeeName;

    /**
     * @param LeaveRequest $leave        The leave/WFH request that was submitted
     * @param string       $employeeName Display name of the employee who submitted it
     */
    public function __construct(LeaveRequest $leave, string $employeeName)
    {
        $this->leave = $leave;
        $this->employeeName = $employeeName;
    }

    public function build()
    {
        return $this->subject("New {$this->leave->type} request from {$this->employeeName}")
            ->view('emails.leave-request-submitted')
            ->with([
                'employeeName' => $this->employeeName,
                'type'         => $this->leave->type,
                'dayType'      => $this->leave->day_type ?? 'Full Day',
                'fromDate'     => $this->leave->from_date,
                'toDate'       => $this->leave->to_date,
                'reason'       => $this->leave->reason,
            ]);
    }
}
