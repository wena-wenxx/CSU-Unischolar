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
};

export const HELP = {
  student: [
    {
      title: "How to apply for a scholarship",
      steps: [
        "Go to Scholarships. Use the filters to show only Government, CSU-funded, LGU or Private programs, or the ones closing soon.",
        "Press Details to read the description, the deadline and the list of required documents.",
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
      title: "Reports and the Data Bank",
      steps: [
        "Reports → choose a program (or all) and a status, then Export CSV. The file name includes the program and the date.",
        "Data Bank → search a student to see every application and grant they ever had.",
        "Profile correction requests from students appear on the Dashboard. Check them with the Registrar, then mark them resolved.",
      ],
    },
  ],
};
