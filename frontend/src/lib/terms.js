/*
  One source for academic years and semesters on every page (Approved Lists, Processing Batches,
  Enrollment, Reports...). The same values the server accepts (Setting::SEMESTERS) and the same
  "2026-2027" format. The current year and semester come from Admin > Settings (or today's date),
  sent by GET /settings/public as current_school_year and current_semester.
*/
export const SEMESTERS = ["1st Semester", "2nd Semester", "Summer"];

// Two years back, the current one and the next: "2024-2025" ... "2027-2028".
export function yearChoices(current) {
  const start = Number(String(current || "").slice(0, 4)) || new Date().getFullYear();
  return [start - 2, start - 1, start, start + 1].map((y) => `${y}-${y + 1}`);
}

// "1st Semester AY 2026-2027" (the wording used by payroll periods and enrollment lists).
export function termLabel(semester, schoolYear) {
  return [semester, schoolYear ? `AY ${schoolYear}` : ""].filter(Boolean).join(" ");
}
