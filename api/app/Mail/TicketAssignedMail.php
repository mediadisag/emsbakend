<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketAssignedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket) {}

    public function build()
    {
        return $this->subject("[{$this->ticket->code}] Ticket assigned to you: {$this->ticket->heading}")
            ->view('emails.ticket-assigned')
            ->with([
                'ticket' => $this->ticket,
                'url'    => "https://ems1.disagglobal.com/tickets.html?ticket={$this->ticket->code}",
            ]);
    }
}
