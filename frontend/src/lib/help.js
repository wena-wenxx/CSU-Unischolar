/*
  Text for the first-login welcome guide and the Help page.
  Kept in one place so both stay the same.
*/

// The Help page's "Show the welcome guide again" button sends this event.
export const WELCOME_EVENT = "scholarguide:welcome";

export function showWelcomeAgain() {
  window.dispatchEvent(new Event(WELCOME_EVENT));
}

export const WELCOME_STEPS = {
  student: [
    {
      title: "Find a scholarship",
      text: "Open Scholarships to see programs that are open now, their deadlines and the documents each one needs.",
    },
    {
      title: "Apply and upload",
      text: "Press Apply. Your application is saved as a draft. Upload one file for each required document, or press Use my saved documents if they are already in My Documents.",
    },
    {
      title: "Submit",
      text: "The Submit application button turns green only when every required document is uploaded. Press it to send everything to OAS.",
    },
    {
      title: "Follow your application",
      text: "My Applications shows each step. The bell at the top tells you when OAS changes your status, for example when they need something from you.",
    },
    {
      title: "One active scholarship",
      text: "You can hold only one active scholarship at a time. Your grants and payroll appear in My Scholarship History.",
    },
  ],
  staff: [
    {
      title: "Start at the Dashboard",
      text: "The numbers show what needs attention: applications waiting, AI flags, payroll ready. Recent activity lists the latest changes.",
    },
    {
      title: "Review applications",
      text: "Applications lists the newest activity first. Press Review to see documents, run the AI check, and choose the next step. Only the steps allowed right now are shown.",
    },
    {
      title: "The AI only flags",
      text: "Run AI check reads a document and flags possible problems (wrong name, missing ID, wrong document). You decide; the AI never approves or rejects.",
    },
    {
      title: "After the agency decides",
      text: "Record Approved or Rejected (or upload the agency's approved list in Approved Lists), verify enrollment, then tag the grantee in Scholar Records.",
    },
    {
      title: "Payroll and reports",
      text: "Payroll: choose the program and period, preview, then confirm. Each scholar gets their own program's amount. Reports exports CSV files for Excel.",
    },
  ],
  admin: [
    {
      title: "You manage accounts and settings",
      text: "As System Admin you create and look after accounts, read the activity log and set the school year and OAS contact details. OAS staff process the applications.",
    },
    {
      title: "Create accounts",
      text: "Manage Staff and Manage Students → New account. The system gives a temporary password; the user must change it when they first log in.",
    },
    {
      title: "Reset or deactivate",
      text: "Forgot a password? Reset password gives a new temporary one. Someone left the office? Deactivate: they cannot log in, but their records stay.",
    },
    {
      title: "Activity Logs",
      text: "Who did what and when: logins, account changes, application steps, payroll and settings. Filter it and export it to CSV.",
    },
    {
      title: "System Settings",
      text: "Set the current school year and semester (used as the payroll period) and the OAS office hours, e-mail and phone shown to students.",
    },
  ],
};

export const HELP = {
  student: [
    {
      title: "How to apply for a scholarship",
      steps: [
        "Go to Scholarships. Use the filters to show CHED-funded, Other Government, Private-funded or University-funded programs, or only the ones open now.",
        "Press Details to read the description, the deadline and the list of required documents.",
        "Agency-direct programs (for example DOST, Iskolar ng Landbank, NGCP) are applied for directly at the agency, not here. Their page tells you how.",
        "Press Apply. A draft application opens with a checklist of the required documents.",
        "For each document, press Choose file, pick the file, then press Upload. The row turns green when it is uploaded.",
        "When every required document is uploaded, the progress bar is full and Submit application turns green. Press it and confirm.",
      ],
    },
    {
      title: "My Documents: upload once, reuse",
      steps: [
        "My Documents lists every file you have uploaded, by type (COR, grades, valid ID, and so on).",
        "Each file shows if it is still valid. COR, grades, indigency, barangay clearance, good moral and recommendation letters count for 6 months; the income tax return for 1 year; the birth certificate and valid ID do not expire.",
        "Press Check with AI to have the system read a file. If it finds something (for example your name is not on it), the reason is shown so you can fix it before applying.",
        "To update a file, choose the new file and press Replace. Your draft applications get the new copy too.",
        "When you apply for another scholarship, press Use my saved documents (or Use saved file on one row) instead of uploading again.",
      ],
    },
    {
      title: "Documents: tips",
      steps: [
        "Accepted files: PDF, JPG or PNG, up to 10 MB each.",
        "Make sure your full name (and, on school documents, your Student ID) is clearly readable. Blurry photos are hard to check.",
        "Uploaded the wrong file? Upload again for the same document; the new file replaces the old one (only while the application is a draft or needs action).",
      ],
    },
    {
      title: "What the statuses mean",
      statusHelp: true,
    },
    {
      title: "Deadlines",
      steps: [
        "Each program shows its deadline. A draft must be submitted on or before that date.",
        "After the deadline, the program disappears from the Scholarships list and new applications are not accepted.",
        "If OAS returns your application (Needs action), you can still fix and resubmit it after the deadline.",
      ],
    },
    {
      title: "One active scholarship",
      steps: [
        "If you are currently a grantee, you can still view other programs but you cannot apply until your current grant ends.",
        "Your grants, including completed ones, and their payroll entries are in My Scholarship History.",
      ],
    },
    {
      title: "Your profile",
      steps: [
        "Your name, Student ID, course, year level and college come from the Registrar, so you cannot edit them here.",
        "If something is wrong, use Request a correction on My Profile. OAS will check it with the Registrar.",
        "You can update your contact number yourself.",
        "To change your password, open My Profile → Change password.",
      ],
    },
    {
      title: "Contact OAS",
      steps: [
        "Contact OAS shows the office hours, location, e-mail and phone of the Office of Admission and Scholarship.",
        "To ask a question, choose a topic, write a short subject and your message, then press Send.",
        "OAS replies on the same page. The bell at the top tells you when there is a reply.",
      ],
    },
  ],
  staff: [
    {
      title: "Daily review",
      steps: [
        "Dashboard → look at Needs Action, AI Flags and Recent activity.",
        "Applications → the list shows the newest activity first. Use the search box (name, Student ID or program) and the status filters.",
        "Press Review. Open each document with View, and press Run AI check to read it automatically.",
        "If something is wrong, type what the student must fix in Remarks and press Needs Action. The student sees your remarks and gets a notification.",
        "When the documents are complete, press Complete (forward to agency).",
      ],
    },
    {
      title: "Reading AI flags",
      steps: [
        "Validated: no problem found. Flagged: at least one possible problem; read the messages and open \"Text read by the AI\".",
        "Needs review: the file could not be read (for example the AI service is offline). Check it by hand.",
        "The AI never changes an application's status. Staff make every decision.",
      ],
    },
    {
      title: "Recording the agency's decision",
      steps: [
        "First forward the application: press Complete: forward to agency (only possible when every required document is uploaded).",
        "One at a time: when the agency answers, open the application and press Approved by agency or Rejected by agency. A mistake can be undone until the student is tagged as a grantee.",
        "Many at once: go to Approved Lists, choose the program, upload the agency's list as a CSV file, pick the column with the Student ID, and press Process. A summary shows matched, unmatched and error rows.",
      ],
    },
    {
      title: "Enrollment, grantees and payroll",
      steps: [
        "For an approved application, press Verify enrollment and confirm the student is currently enrolled.",
        "Scholar Records → Ready to Tag as Grantee → Tag as Grantee. A student can hold only one active scholarship.",
        "Scholar Records → ATM status: record whether the grantee has an ATM and whether the stipend has reached it (status only; no banking).",
        "Payroll → 1. Prepare: choose one program or all, and the period → Preview → Confirm. Students not enrolled or already in that period are skipped; ATM problems are shown as warnings.",
        "Payroll → 2. Payroll list: tick entries and press Mark Ready, then Mark Processed after the payout. Export CSV or Print / Save as PDF.",
      ],
    },
    {
      title: "Programs and announcements",
      steps: [
        "Scholarships → Create a program with its type, deadline and amount, and tick its required documents in the same form (search the list, or add a custom requirement).",
        "To stop new applications, set the status to Closed or set the deadline. Programs with applications cannot be deleted, so their records are kept.",
        "Announcements → post deadline reminders and payout schedules. Students see the latest three on their dashboard until the expiry date.",
      ],
    },
    {
      title: "Finding things quickly",
      steps: [
        "Use the search box at the top of every page: type part of a student's name or Student ID, a program name, or a page name such as \"payroll\".",
        "Click a number on the Dashboard to open the matching list (for example Needs Action or Payroll Ready).",
        "Click your name at the top of the menu for View Profile, Help and Sign Out.",
      ],
    },
    {
      title: "Auto-Review and forwarding",
      steps: [
        "Applications → Auto-Review sorts every submitted application into three lists: Ready to forward, Needs manual review, and Probably incomplete. It changes nothing by itself.",
        "Ready to forward: every required document is uploaded and checked by the AI with no flag. Tick them and press Forward selected to the agency.",
        "Needs manual review: open each one (an AI flag, a file the AI could not read, a file not checked yet, or an expired document).",
        "Probably incomplete: a required document is missing, or the student already holds another scholarship. Edit the remark if needed and press Send back (Needs action).",
        "Forwarded to Agency lists everything sent to agencies, by program, with the days waiting. Export a CSV for each agency. When the agency answers, record it in Approved Lists.",
      ],
    },
    {
      title: "Enrollment: Registrar list",
      steps: [
        "Enrollment → upload the Registrar's list of enrolled students as a CSV file and choose the Student ID column (names are optional but recommended).",
        "Press Verify All Enrollments. Everyone is sorted into Enrolled, Not on the list, and Needs manual check (the Student ID is on the list but the name is different).",
        "Nothing is saved until you press Record. Record the Enrolled group in one click; check the others one by one.",
        "Use “Active grantees” at the start of each semester, so payroll only includes students who are still enrolled.",
      ],
    },
    {
      title: "Student messages",
      steps: [
        "Students send questions from Contact OAS. Open messages appear on the Dashboard and in Student Messages, oldest first.",
        "Type a reply and press Send reply. The student sees it on their Contact OAS page and their bell shows it.",
      ],
    },
    {
      title: "Reports and the Data Bank",
      steps: [
        "Reports → choose a program (or all) and a status, then Export CSV. The file name includes the program and the date.",
        "Data Bank → search a student to see every application and grant they ever had.",
        "Profile correction requests from students appear on the Dashboard. Check them with the Registrar, then mark them resolved.",
      ],
    },
  ],
  admin: [
    {
      title: "What the System Admin does",
      steps: [
        "Creates, updates and deactivates staff, admin and student accounts, and resets passwords.",
        "Reads the Activity Logs (who did what and when) and exports them.",
        "Sets the current school year and semester and the OAS contact details in System Settings.",
        "Can manage scholarship programs and export reports, but does not review applications, tag grantees or prepare payroll; those stay with OAS staff.",
      ],
    },
    {
      title: "Creating an account",
      steps: [
        "Manage Staff (or Manage Students) → New account. Fill in the form; for students, the Student ID must be unique.",
        "Leave the password empty to get a temporary one, or type your own. Give it to the user privately.",
        "The user must change the temporary password the first time they log in.",
      ],
    },
    {
      title: "Passwords and deactivation",
      steps: [
        "Reset password gives a new temporary password and signs the user out everywhere.",
        "Deactivate stops someone from logging in. Their applications, decisions and logs stay. Reactivate to undo.",
        "You cannot deactivate yourself, and the last active admin cannot be deactivated or changed to staff.",
      ],
    },
    {
      title: "Activity Logs",
      steps: [
        "Filter by type (accounts, applications, payroll, settings, logins), by role or by date, or type a name.",
        "Export CSV saves everything that matches the filters.",
      ],
    },
    {
      title: "System Settings",
      steps: [
        "School year and semester: the current term. Payroll uses it as the default period. Leave both empty to let the system work it out from today's date.",
        "OAS office hours, location, e-mail and phone: shown to students on Contact OAS and at the bottom of every page. Only enter details confirmed by the OAS.",
      ],
    },
  ],
};
