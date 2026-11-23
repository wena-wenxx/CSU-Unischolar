# CSU UniScholar – Round 24 (Nov 23, 2026): invitations, queues and confirm dialogs

Based on commit 3c1011a (Nov 22). Nothing was committed or pushed. No database migration.

## What changed
- Invitations ("Send invitation" in AI Recommendations) are now saved in the system: the student sees a
  message that starts with "Dear <full name>," under the bell, even when e-mail is off.
- A second invitation to the same student for the same program asks "Send the invitation again?" first.
- Bulk scholarship notices always name the student, even if the [Student Name] tag was deleted.
- Student Messages has a new view, "Sent invitations": student, Student ID, program, sender, date and
  status (Sent, not opened / Opened / Applied / Program closed), with search.
- Completing a processing batch now also warns about approved students not yet checked for enrollment or
  not yet tagged. Work left in a completed batch no longer appears as pending (Dashboard, Enrollment,
  Applications "Needs action", Scholar Records "Ready to tag").
- Scholar Records "Ready to tag" compares the school year (scholar records are kept per school year).
- Yes/No dialogs now show their question (before, some showed only "Are you sure?").
- Dates in two server messages are shown in Philippine time.

## Files
backend/app/Http/Controllers/AgencyListController.php
backend/app/Http/Controllers/DashboardController.php
backend/app/Http/Controllers/EnrollmentListController.php
backend/app/Http/Controllers/RecommendationController.php
backend/app/Http/Controllers/StudentController.php
backend/app/Models/Application.php
backend/app/Models/ProcessingBatch.php
backend/routes/api.php
backend/tests/Feature/CycleAndInvitationTest.php   (NEW)
frontend/src/components/Modal.jsx
frontend/src/pages/staff/MessagesPage.jsx
frontend/src/pages/staff/RecommendationsPage.jsx
frontend/src/pages/staff/SentNotices.jsx           (NEW)
frontend/src/pages/staff/StaffApplicationsPage.jsx
frontend/src/pages/staff/StaffScholarRecordsPage.jsx
