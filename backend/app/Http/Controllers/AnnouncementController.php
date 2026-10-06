<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;

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
        ];
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

        $announcement = Announcement::create($request->validate($this->rules()) + [
            'posted_by' => $request->user()->id,
            'posted_at' => now(),
        ]);

        return response()->json($announcement->load('author:id,name'), 201);
    }

    public function update(Request $request, $id)
    {
        $this->staffOnly($request);

        $announcement = Announcement::findOrFail($id);
        $announcement->update($request->validate($this->rules()));

        return response()->json($announcement->load('author:id,name'));
    }

    public function destroy(Request $request, $id)
    {
        $this->staffOnly($request);

        Announcement::findOrFail($id)->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }
}
