<?php

namespace App\Domain\Notifications;

use App\Models\OutboundNotification;
use RuntimeException;

/**
 * FR-054 (Could) — placeholder adapter. SMS stays off until a gateway is contracted;
 * implement send() against that provider's API and set SPLMS_SMS_ENABLED=true.
 */
class SmsSender implements ChannelSender
{
    public function send(OutboundNotification $n): ?string
    {
        throw new RuntimeException('SMS is not configured.');
    }

    public function name(): string
    {
        return 'sms:none';
    }
}
