<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;


class LeaveRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $leave;
    public $employee;
    public $rejectionReason;

    public function __construct($leave, $employee, $rejectionReason = null)
    {
        $this->leave = $leave;
        $this->employee = $employee;
        $this->rejectionReason = $rejectionReason;
    }

    public function build()
    {
        return $this->subject('Leave Request Rejected')
                    ->view('emails.leave-rejected')
                    ->with([
                        'leave' => $this->leave,
                        'employee' => $this->employee,
                        'rejectionReason' => $this->rejectionReason
                    ]);
    }
}
