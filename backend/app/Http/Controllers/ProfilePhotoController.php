<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/*
| Profile photo (round 12): every user (student, staff, admin) can upload a
| photo of themselves; it is shown in the sidebar. Optional: without one the
| initials are shown. Saved in storage/app/public/avatars with a random file
| name (needs "php artisan storage:link" once, like announcement pictures).
*/
class ProfilePhotoController extends Controller
{
    // POST /profile/photo   form-data: photo (JPG, PNG or WebP, up to 2 MB)
    public function store(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:min_width=100,min_height=100',
        ], [
            'photo.max' => 'The photo must be 2 MB or smaller.',
            'photo.dimensions' => 'The photo is too small. Use one at least 100 x 100 pixels.',
            'photo.mimes' => 'Use a JPG, PNG or WebP picture.',
        ]);

        $user = $request->user();
        $old = $user->photo_path;
        $user->update(['photo_path' => $request->file('photo')->store('avatars', 'public')]);
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        ActivityLog::record($user, 'account.photo_changed', "{$user->name} changed their profile photo.");

        return response()->json(['message' => 'Profile photo saved.', 'user' => $user->fresh()->load('student')]);
    }

    // DELETE /profile/photo
    public function destroy(Request $request)
    {
        $user = $request->user();
        if ($user->photo_path) {
            Storage::disk('public')->delete($user->photo_path);
            $user->update(['photo_path' => null]);
        }

        return response()->json(['message' => 'Profile photo removed.', 'user' => $user->fresh()->load('student')]);
    }
}
