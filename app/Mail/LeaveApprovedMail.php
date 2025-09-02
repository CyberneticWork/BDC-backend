<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class LeaveApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $leave;
    public $employee;

    public function __construct($leave, $employee)
    {
        $this->leave = $leave;
        $this->employee = $employee;
    }

    public function build()
    {
        return $this->subject('Leave Request Approved')
                    ->view('emails.leave-approved')
                    ->with([
                        'leave' => $this->leave,
                        'employee' => $this->employee
                    ]);
    }
}
