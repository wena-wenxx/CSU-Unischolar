<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/*
| OAS announcements. Staff create, edit and delete them.
| Students see only the ones that have not expired, newest first.
*/
class AnnouncementController extends Controller
{
    private function staffOnly(Request $request): void
    {
        if ($request->user()->role !== 'staff') {
            abort(response()->json(['message' => 'Only OAS staff can manage announcements.'], 403));
        }
    }

    private function rules(): array
    {
        return [
            'title' => 'required|string|max:150',
            'body' => 'required|string|max:5000',
            'expires_at' => 'nullable|date',
            // Picture: JPG, PNG or WebP up to 2 MB (800 x 450 recommended).
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_image' => 'sometimes|boolean',
        ];
    }

    // Saves a new picture (replacing an uploaded one) or removes it.
    private function applyImage(Request $request, Announcement $announcement): void
    {
        $remove = $request->boolean('remove_image');

        if (($request->hasFile('image') || $remove) && $announcement->hasUploadedImage()) {
            Storage::disk('public')->delete($announcement->image_path);
        }

        if ($request->hasFile('image')) {
            $announcement->image_path = $request->file('image')->store('announcements', 'public');
        } elseif ($remove) {
            $announcement->image_path = null;
        }

        $announcement->save();
    }

    // GET /announcements/{id}: one announcement (students: only if not expired).
    public function show(Request $request, $id)
    {
        $query = Announcement::with('author:id,name');

        if ($request->user()->role === 'student') {
            $query->current();
        }

        return response()->json($query->findOrFail($id));
    }

    // GET /announcements?limit=3
    public function index(Request $request)
    {
        $query = Announcement::with('author:id,name')->latest('posted_at')->latest('id');

        if ($request->user()->role !== 'staff') {
            $query->current();
        }

        if ($request->filled('limit')) {
            $query->limit(min((int) $request->limit, 50));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $this->staffOnly($request);

        $data = $request->validate($this->rules());
        unset($data['image'], $data['remove_image']);

        $announcement = Announcement::create($data + [
            'posted_by' => $request->user()->id,
            'posted_at' => now(),
        ]);
        $this->applyImage($request, $announcement);

        ActivityLog::record($request->user(), 'announcement.created', "Posted announcement \"{$announcement->title}\".", $announcement);

        return response()->json($announcement->load('author:id,name'), 201);
    }

    public function update(Request $request, $id)
    {
        $this->staffOnly($request);

        $announcement = Announcement::findOrFail($id);
        $data = $request->validate($this->rules());
        unset($data['image'], $data['remove_image']);
        $announcement->fill($data);
        $this->applyImage($request, $announcement);
        ActivityLog::record($request->user(), 'announcement.updated', "Edited announcement \"{$announcement->title}\".", $announcement);

        return response()->json($announcement->load('author:id,name'));
    }

    public function destroy(Request $request, $id)
    {
        $this->staffOnly($request);

        $announcement = Announcement::findOrFail($id);
        if ($announcement->hasUploadedImage()) {
            Storage::disk('public')->delete($announcement->image_path);
        }
        $announcement->delete();
        ActivityLog::record($request->user(), 'announcement.deleted', "Deleted announcement \"{$announcement->title}\".");

        return response()->json(['message' => 'Announcement deleted.']);
    }
}
