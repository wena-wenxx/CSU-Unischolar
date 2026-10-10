<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use Illuminate\Http\Request;

/* Staff: the e-mails the system sent (newest first). */
class ReportController extends Controller
{
    public function emailLogs(Request $request)
    {
        if ($request->user()->role !== 'staff' && !$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json(
            EmailLog::with(['student:id,student_id,first_name,last_name', 'application.scholarship:id,name'])
                ->latest()
                ->latest('id')
                ->limit(100)
                ->get()
        );
    }
}
