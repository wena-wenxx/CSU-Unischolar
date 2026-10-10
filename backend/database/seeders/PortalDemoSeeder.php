<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\ContactMessage;
use App\Models\ProfileChangeRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * FICTIONAL DEMO DATA: OAS announcements, one pending
 * "request a correction" and three Contact OAS messages from demo students.
 * Safe to run again (skips what already exists).
 */
class PortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $staff = User::where('role', 'staff')->first();

        $announcements = [
            [
                'title' => 'Now open: CSU Cultural and Athletic Grants',
                'body' => "Members of the CSU Choir, Dance Troupe, Kayam Ethno Band and varsity teams may now apply online until November 15, 2026. Prepare your COR, Certificate of Grades, Valid ID, Birth Certificate, Certificate of Good Moral Character and a recommendation letter from your coach or adviser.",
                'posted_days_ago' => 10,
                'expires_at' => '2026-11-15',
            ],
            [
                'title' => 'Reminder: TES and CSU Student Assistance deadlines',
                'body' => "Applications for the Tertiary Education Subsidy (TES) close on October 31, 2026 and the CSU Student Assistance Scholarship on October 30, 2026. Drafts that are not submitted by the deadline can no longer be submitted.",
                'posted_days_ago' => 3,
                'expires_at' => '2026-10-31',
            ],
            [
                'title' => 'Payroll for 1st Semester AY 2026-2027',
                'body' => "OAS is preparing the payroll for active grantees whose enrollment has been verified. The payout date will be posted here once the funding agency releases it. Grantees without an ATM card should coordinate with the OAS office.",
                'posted_days_ago' => 1,
                'expires_at' => null,
            ],
            [
                'title' => 'Continuing grantees: submit your COR',
                'body' => "Continuing grantees were asked to submit their Certificate of Registration for enrollment verification. This notice has expired and is shown only to staff.",
                'posted_days_ago' => 40,
                'expires_at' => '2026-09-15',
            ],
        ];

        foreach ($announcements as $a) {
            Announcement::firstOrCreate(
                ['title' => $a['title']],
                [
                    'body' => $a['body'],
                    'posted_by' => $staff?->id,
                    'posted_at' => now()->subDays($a['posted_days_ago']),
                    'expires_at' => $a['expires_at'],
                ]
            );
        }

        $ana = Student::where('student_id', '2026-00003')->first();

        if ($ana) {
            ProfileChangeRequest::firstOrCreate(
                ['student_id' => $ana->id, 'field' => 'year_level', 'status' => 'pending'],
                [
                    'requested_value' => '3rd Year',
                    'reason' => 'My COR for this semester shows I am already in 3rd year.',
                ]
            );
        }

        // Contact OAS: two waiting for an answer, one already answered.
        $messages = [
            ['2026-00004', 'documents', 'Barangay clearance is still being processed', "Good day po. My barangay clearance will only be released next week. Can I submit my application first and upload it later?", 2, null],
            ['2026-00006', 'payroll', 'No ATM card yet', "I was tagged as a grantee but I do not have an ATM card yet. Where should I apply for one?", 1, null],
            ['2026-00009', 'application', 'Status says Needs action', "What does Needs action mean on my application?", 5,
                "It means OAS found something to fix. Open the application to read the remarks, upload the corrected file and press Submit again."],
        ];

        foreach ($messages as [$studentId, $topic, $subject, $body, $daysAgo, $reply]) {
            $student = Student::where('student_id', $studentId)->first();
            if (!$student) {
                continue;
            }

            // unguarded: the seeder sets the dates too
            ContactMessage::unguarded(fn () => ContactMessage::firstOrCreate(
                ['student_id' => $student->id, 'subject' => $subject],
                [
                    'topic' => $topic,
                    'message' => $body,
                    'status' => $reply ? 'answered' : 'open',
                    'reply' => $reply,
                    'replied_by' => $reply ? $staff?->id : null,
                    'replied_at' => $reply ? now()->subDays($daysAgo - 1) : null,
                    'reply_read_at' => $reply ? now()->subDays($daysAgo - 1) : null,
                    'created_at' => now()->subDays($daysAgo),
                    'updated_at' => now()->subDays($daysAgo),
                ]
            ));
        }
    }
}
