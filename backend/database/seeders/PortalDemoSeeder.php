<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\ContactMessage;
use App\Models\ProfileChangeRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * FICTIONAL DEMO DATA: eight OAS announcements with pictures, one pending
 * "request a correction" and three Contact OAS messages from demo students.
 * Safe to run again (skips what already exists).
 */
class PortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $staff = User::where('role', 'staff')->first();

        // Eight sample announcements. Pictures are original drawings in
        // frontend/public/announcements/ (no agency logos are used).
        // Dates fit the demo programs: expired ones are shown to staff only.
        $announcements = [
            [
                'title' => 'OAS Office Closed on November 5',
                'body' => "The Office of Admission and Scholarship will be closed on Thursday, November 5, 2026. Online applications in ScholarGuide stay open. Messages sent through Contact OAS will be answered on the next working day.",
                'image' => '/announcements/office-closed.jpg',
                'posted_days_ago' => 0,
                'expires_at' => '2026-11-05',
            ],
            [
                'title' => 'Payroll Schedule for 1st Semester',
                'body' => "OAS is preparing the payroll for active grantees whose enrollment has been verified for the 1st Semester AY 2026-2027. The payout date will be posted here once the funding agency releases it. Grantees without an ATM card yet should message OAS through Contact OAS.",
                'image' => '/announcements/payroll-schedule.jpg',
                'posted_days_ago' => 1,
                'expires_at' => null,
            ],
            [
                'title' => 'Congratulations to New Scholars',
                'body' => "Congratulations to the students who were approved this semester! Please check My Applications: OAS will verify your enrollment and tag you as a grantee. Keep your contact number in My Profile up to date.",
                'image' => '/announcements/new-scholars.jpg',
                'posted_days_ago' => 3,
                'expires_at' => '2026-11-30',
            ],
            [
                'title' => 'Agency-Direct Scholarships: Apply at the Agency',
                'body' => "Iskolar ng Landbank, DA-ACEF, ACEF-GIAHEP, DOST, LGU-Bayugan Graduate School, NGCP and Enfants du Mekong scholarships are agency-direct. Submit your application directly to the scholarship-giving agency and transact requirements and allowances with its office. The OAS posts announcements and updates when an agency requests it.",
                'image' => '/announcements/orientation.jpg',
                'posted_days_ago' => 5,
                'expires_at' => '2026-10-24',
            ],
            [
                'title' => 'Reminder: TES and TDP-TES Deadline',
                'body' => "Applications for the CHED Tertiary Education Subsidy (TES) and the CHED Tulong-Dunong Program (TDP-TES) close on October 31, 2026 (demo date). Drafts that are not submitted by the deadline can no longer be submitted, so press Submit application once every required document is uploaded.",
                'image' => '/announcements/deadline-extended.jpg',
                'posted_days_ago' => 6,
                'expires_at' => '2026-10-31',
            ],
            [
                'title' => 'Culture and Arts Financial Assistance',
                'body' => "Members of CSU culture and arts groups may apply for the university-funded Culture and Arts financial assistance until November 15, 2026 (demo date). Prepare your COR, Certificate of Grades, Valid ID, Birth Certificate, Certificate of Good Moral Character and a recommendation letter from your group's adviser.",
                'image' => '/announcements/cultural-auditions.jpg',
                'posted_days_ago' => 10,
                'expires_at' => '2026-11-15',
            ],
            [
                'title' => 'DOST Scholarship Updates',
                'body' => "The DOST scholarship is agency-direct: applications and allowances are handled by DOST. This notice has expired and is shown only to staff.",
                'image' => '/announcements/science-slots.jpg',
                'posted_days_ago' => 80,
                'expires_at' => '2026-08-14',
            ],
            [
                'title' => 'CHED Merit Scholarship Now Open',
                'body' => "Applications for the CHED Merit Scholarship Program (CMSP), Full SSP and Half SSP, were open until August 31, 2026 (demo date). This notice has expired and is shown only to staff.",
                'image' => '/announcements/scholarship-open.jpg',
                'posted_days_ago' => 85,
                'expires_at' => '2026-08-31',
            ],
        ];

        foreach ($announcements as $a) {
            Announcement::firstOrCreate(
                ['title' => $a['title']],
                [
                    'body' => $a['body'],
                    'image_path' => $a['image'],
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
