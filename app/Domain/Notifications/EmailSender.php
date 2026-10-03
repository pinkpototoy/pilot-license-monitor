<?php

namespace App\Domain\Notifications;

use App\Models\OutboundNotification;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class EmailSender implements ChannelSender
{
    public function send(OutboundNotification $n): ?string
    {
        $to = $n->recipientAddress() ?? throw new RuntimeException('No email address');
        $body = $n->body_rendered."\n\n--\n".config('app.name')."\n".rtrim((string) config('app.url'), '/')
            ."\nThis is an automated message from the records office.";

        $sent = Mail::raw($body, function (Message $m) use ($to, $n) {
            $m->to($to)->subject($n->subject ?? config('app.name'));
        });

        return $sent?->getMessageId();
    }

    public function name(): string
    {
        return 'mail:'.config('mail.default');
    }
}
