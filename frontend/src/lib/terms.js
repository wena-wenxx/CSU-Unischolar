export const SEMESTERS = ["1st Semester", "2nd Semester", "Summer"];

export function yearChoices(current) {
  const start = Number(String(current || "").slice(0, 4)) || new Date().getFullYear();
  return [start - 2, start - 1, start, start + 1].map((y) => `${y}-${y + 1}`);
}

export function termLabel(semester, schoolYear) {
  return [semester, schoolYear ? `AY ${schoolYear}` : ""].filter(Boolean).join(" ");
}
