/*
  Activity log words for people (the admin's Activity Logs page and dashboard).
  The server stores codes like "application.approved"; these are the labels.
*/
export const ACTION_GROUPS = {
  "": "All activity",
  auth: "Logins and passwords",
  account: "Account changes",
  application: "Applications",
  enrollment: "Enrollment",
  grantee: "Grantees",
  payroll: "Payroll",
  scholarship: "Scholarship programs",
  agency_list: "Approved lists",
  announcement: "Announcements",
  message: "Student messages",
  profile: "Student profiles",
  settings: "Settings",
};

const LABELS = {
  "auth.login": "Login",
  "auth.logout": "Logout",
  "auth.password_changed": "Password changed",
  "account.created": "Account created",
  "account.updated": "Account updated",
  "account.password_reset": "Password reset",
  "account.deactivated": "Deactivated",
  "account.reactivated": "Reactivated",
  "application.started": "Application started",
  "application.submitted": "Submitted",
  "application.under_review": "Under review",
  "application.needs_action": "Needs action",
  "application.forwarded": "Forwarded",
  "application.approved": "Approved",
  "application.rejected": "Rejected",
  "enrollment.verified": "Enrolled",
  "enrollment.not_enrolled": "Not enrolled",
  "enrollment.list_uploaded": "Registrar list",
  "grantee.tagged": "Grantee tagged",
  "grantee.updated": "Grantee updated",
  "payroll.prepared": "Payroll prepared",
  "payroll.ready": "Payroll ready",
  "payroll.processed": "Payroll processed",
  "payroll.draft": "Payroll to draft",
  "settings.updated": "Settings",
  "message.sent": "Message sent",
  "message.replied": "Message replied",
};

export function actionLabel(action) {
  if (LABELS[action]) return LABELS[action];
  const [group, rest] = String(action || "").split(".");
  const name = (ACTION_GROUPS[group] || group || "").replace(/s$/, "");
  return `${name} ${String(rest || "").replaceAll("_", " ")}`.trim();
}
