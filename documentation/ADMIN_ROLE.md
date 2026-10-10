# The System Admin role (round 8)

ScholarGuide now has **three roles**. This page explains what each one may do,
why the admin does **not** process applications, and where the role must appear
in the thesis.

## Who does what

| Task | Student | OAS Staff | System Admin |
|---|:-:|:-:|:-:|
| Apply, upload documents, track status, Contact OAS | ✓ | | |
| Review applications, AI check, forward to agency, record the agency's decision | | ✓ | |
| Verify enrollment (one by one or Verify All), tag grantees, ATM status | | ✓ | |
| Prepare payroll, mark Ready / Processed | | ✓ | |
| Announcements, answer student messages, Approved Lists | | ✓ | |
| Create / edit scholarship programs and requirements | | ✓ | ✓ |
| Read reports and export CSV | | ✓ | ✓ |
| Create staff, admin and student accounts | | | ✓ |
| Reset passwords, deactivate / reactivate accounts | | | ✓ |
| Read and export the Activity Logs | | | ✓ |
| System Settings (school year, semester, OAS contact details) | | | ✓ |
| Change own password | ✓ | ✓ | ✓ |

**Hierarchy:** Admin (accounts and settings) → Staff (scholarship processing) →
Student. The admin is "above" staff for accounts, but **separate** for the
scholarship work: the admin cannot approve, tag or pay anyone.

**Why (answer for the panel):** *separation of duties.* Every decision in the
records (forwarding, agency decision, enrollment, grantee, payroll) is made by
an OAS staff account and is in the Activity Logs with that person's name. The
admin manages who can log in, so one account cannot both create users and
approve their scholarships.

**Not included on purpose:**
- *Approving scholarship programs created by staff:* the OAS has no such step
  today; adding it would invent a process.
- *Fairness audit:* the system cannot measure fairness. The Activity Logs and
  the Reports give the facts (who decided what, when) that a human audit needs.

## Accounts

- Accounts are **never deleted**, only deactivated, so the history stays.
- New accounts and password resets get a **temporary password**; the user must
  choose their own at first login (`must_change_password`).
- Deactivating signs the user out everywhere. You cannot deactivate yourself,
  and the last active admin cannot be deactivated or changed to staff.
- Demo admin: `admin@carsu.edu.ph` / `Admin@12345` (change it before real use).

## Database changes

| Table | Change |
|---|---|
| `users` | `role` enum now `student, staff, admin`; new `is_active`, `must_change_password`, `last_login_at` |
| `activity_logs` (new) | `user_id`, `user_name`, `role`, `action`, `description`, `subject_type`, `subject_id`, `ip_address`, `created_at` |
| `settings` (new) | `key`, `value`, `updated_by` |
| `contact_messages` (new) | student messages and OAS replies |
| `enrollment_lists`, `enrollment_list_entries` (new) | the Registrar's list used by Verify All Enrollments |
| `applications` | new `forwarded_at` |

## Where to update the thesis

1. **Chapter 1, Scope and Limitations:** three user types; the admin manages
   accounts, logs and settings and does not process scholarships. The system
   has no live link to the Registrar or to agencies (Registrar list = CSV upload).
2. **Chapter 3, Use Case Diagram:** add the *System Admin* actor with: Manage
   Staff Accounts, Manage Student Accounts, Reset Password, Deactivate Account,
   View Activity Logs, Manage System Settings, Manage Scholarship Programs,
   Export Reports. Add *Change Password* for all three actors and *Contact OAS*
   for students; *Auto-Review*, *Forward to Agency*, *Verify All Enrollments*
   and *Reply to Messages* for staff.
3. **Chapter 3, Context Diagram / DFD:** a new external entity *System Admin*;
   new data stores *Activity Logs*, *Settings*, *Contact Messages*,
   *Enrollment Lists*; the *Registrar* sends the enrollment list (CSV) to OAS.
4. **Chapter 3, ERD and data dictionary:** the tables above.
5. **Chapter 3, user roles / access matrix:** the "Who does what" table above.
6. **Chapter 4, testing:** TEST_PLAN.md Part F; add admin tasks to the usability
   test and the OAS staff questionnaire if an admin user will be tested.
7. **User manual / appendix:** Help → System Admin topics (in the app).
