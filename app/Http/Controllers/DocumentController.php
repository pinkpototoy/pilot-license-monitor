<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** NFR-006 — files are never public; each request is authorized, and every staff view is audited (SRS 26.2). */
class DocumentController extends Controller
{
    public function file(Request $request, Document $document, AuditLogger $audit)
    {
        $user = $request->user();
        $document->loadMissing('student', 'renewalCase');
        $isStaff = $user->hasRole(Role::Admin, Role::Staff);
        abort_unless($isStaff || ($user->role === Role::Student && $document->student->user_id === $user->id), 404);
        abort_unless($document->isViewable(), 409, 'This file has not passed the virus scan.');

        if ($isStaff) {
            $audit->record('document.viewed', 'document', $document->id, $document->student_id, null, null, $user);
        }
        $download = $request->boolean('download');
        $name = preg_replace('/[^\w.\-]+/', '_', $document->original_filename);

        return response()->file(Storage::disk('documents')->path($document->storage_key), [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'",
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
