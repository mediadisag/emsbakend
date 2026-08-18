<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket, public TicketComment $comment) {}

    public function build()
    {
        return $this->subject("[{$this->ticket->code}] New reply: {$this->ticket->heading}")
            ->view('emails.ticket-reply')
            ->with([
                'ticket'  => $this->ticket,
                'comment' => $this->comment,
                'url'     => "https://ems1.disagglobal.com/tickets.html?ticket={$this->ticket->code}",
            ]);
    }
}
