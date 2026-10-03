<?php

namespace App\Domain\Notifications;

use App\Models\OutboundNotification;

/** One delivery channel (SRS 24: providers sit behind an adapter, swapped by configuration). */
interface ChannelSender
{
    /** @return string|null provider message id. Throw on failure; the dispatcher retries. */
    public function send(OutboundNotification $n): ?string;

    public function name(): string;
}
