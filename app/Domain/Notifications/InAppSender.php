<?php

namespace App\Domain\Notifications;

use App\Models\OutboundNotification;

/** In-app messages are delivered by existing in the inbox. */
class InAppSender implements ChannelSender
{
    public function send(OutboundNotification $n): ?string
    {
        return null;
    }

    public function name(): string
    {
        return 'in_app';
    }
}
