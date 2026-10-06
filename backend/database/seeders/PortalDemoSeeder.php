<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\ProfileChangeRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * FICTIONAL DEMO DATA: OAS announcements and one pending
 * "request a correction" from a demo student.
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
    }
}
