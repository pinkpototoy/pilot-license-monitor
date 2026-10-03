<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = ['code', 'channel', 'subject', 'body', 'version', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** The active, newest version of a template for a channel. */
    public static function current(string $code, string $channel): ?self
    {
        return static::where('code', $code)->where('channel', $channel)->where('active', true)
            ->orderByDesc('version')->first()
            ?? static::where('code', $code)->where('channel', 'email')->where('active', true)->orderByDesc('version')->first();
    }
}
