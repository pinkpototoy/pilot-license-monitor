<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\NotificationService;
use App\Models\OutboundNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** FR-053 in-app notifications and per-user channel preferences. */
class InboxController extends Controller
{
    public const CATEGORIES = [
        'expiry_reminder' => 'Expiry reminders',
        'renewal_submitted' => 'Renewal received',
        'case_needs_correction' => 'Documents need correcting',
        'case_approved' => 'Documents approved',
        'case_completed' => 'Renewal completed',
        'draft_idle' => 'Unfinished renewal reminders',
    ];

    public const STAFF_CATEGORIES = [
        'expiry_reminder_staff' => 'Students expiring soon',
        'renewal_submitted_staff' => 'New renewals to verify',
        'staff_digest' => 'Weekday digest email',
        'delivery_failure' => 'Messages that failed to send',
    ];

    public function index(Request $request)
    {
        $items = OutboundNotification::where('user_id', $request->user()->id)->where('channel', 'in_app')
            ->whereIn('status', ['sent', 'pending'])->latest('id')->paginate(30);

        return view('inbox.index', ['items' => $items]);
    }

    public function read(Request $request, OutboundNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return back();
    }

    public function readAll(Request $request)
    {
        OutboundNotification::where('user_id', $request->user()->id)->where('channel', 'in_app')->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'All notifications marked as read.');
    }

    public function preferences(Request $request)
    {
        $user = $request->user();
        $cats = $user->role->isStaffSide() ? self::STAFF_CATEGORIES : self::CATEGORIES;
        $prefs = DB::table('notification_preferences')->where('user_id', $user->id)->get()
            ->mapWithKeys(fn ($p) => [$p->event_type.'|'.$p->channel => (bool) $p->enabled]);

        return view('inbox.preferences', ['cats' => $cats, 'prefs' => $prefs, 'mandatory' => NotificationService::MANDATORY_EMAIL]);
    }

    public function savePreferences(Request $request)
    {
        $user = $request->user();
        $cats = $user->role->isStaffSide() ? self::STAFF_CATEGORIES : self::CATEGORIES;
        $on = (array) $request->input('on', []);
        foreach ($cats as $code => $label) {
            foreach (['email', 'in_app'] as $channel) {
                if ($channel === 'email' && in_array($code, NotificationService::MANDATORY_EMAIL, true)) {
                    continue;
                }
                DB::table('notification_preferences')->updateOrInsert(
                    ['user_id' => $user->id, 'event_type' => $code, 'channel' => $channel],
                    ['enabled' => isset($on[$code][$channel])]);
            }
        }

        return back()->with('status', 'Notification settings saved.');
    }
}
