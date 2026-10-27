/*
  Labels shared by AI Recommendations, External Grantees, the Data Bank
  and My Profile.
*/

// What the rule engine concluded (it only suggests; staff decide).
export const VERDICTS = {
  eligible: ["Meets the rules", "success"],
  no_data: ["Not enough data", "warning"],
  not_eligible: ["Fails a rule", "danger"],
};

// What OAS staff did with the recommendation.
export const STAFF_STATUS = {
  new: ["New", "neutral"],
  invited: ["Invited", "success"],
  added: ["Added (draft)", "success"],
  dismissed: ["Dismissed", "neutral"],
  wrong: ["Marked wrong", "danger"],
};

// "Show" choices on AI Recommendations.
export const VIEWS = {
  recommended: "Meets the rules (recommended)",
  no_data: "Not enough data",
  not_eligible: "Fails a must-have rule",
  acted: "Invited or added",
  dismissed: "Dismissed",
  wrong: "Marked wrong",
  all: "Everything",
};

// Score ranges for the filter: [label, min, max]
export const SCORE_RANGES = {
  "": ["Any score", null, null],
  high: ["90–100%", 90, 100],
  good: ["70–89%", 70, 89],
  fair: ["50–69%", 50, 69],
  low: ["Below 50%", 0, 49],
  follow: ["Below 70% (needs follow-up)", 0, 69],
};

// The three score groups shown as chips above the AI Recommendations table.
export const SCORE_GROUPS = [
  ["", "All scores", null],
  ["high", "Ready (90–100%)", "ready"],
  ["good", "Almost there (70–89%)", "almost"],
  ["follow", "Needs follow-up (below 70%)", "follow_up"],
];

// AI field fit (round 12): ranks and explains only; the rules decide eligibility.
export const FIT_LEVELS = {
  high: ["Matches the field", "success"],
  medium: ["Close to the field", "neutral"],
  low: ["Other fields", "neutral"],
  any: ["Open to all fields", "neutral"],
};

export function fitMethod(method) {
  return method === "embeddings" ? "AI embedding model" : "AI word matching";
}

export const CHECK_RESULT = {
  met: ["✓", "Met", "success"],
  not_met: ["✗", "Not met", "danger"],
  no_data: ["?", "No data", "warning"],
};

// External Grantees: what the AI suggested for each line of an uploaded list.
export const MATCH_RESULT = {
  matched: ["Matched", "success"],
  check: ["Check", "warning"],
  unmatched: ["Did not match", "neutral"],
  error: ["Error", "danger"],
};

export const EXTERNAL_CATEGORIES = {
  lgu: "Local government (LGU)",
  ched: "CHED",
  government: "Other government agency",
  private: "Private foundation / company",
  other: "Other",
};

export function peso(value) {
  if (value === null || value === undefined || value === "") return "—";
  return `₱${Number(value).toLocaleString("en-PH", { maximumFractionDigits: 0 })}`;
}

export function gwaText(value) {
  if (value === null || value === undefined || value === "") return "—";
  return Number(value).toFixed(2);
}

// The student's application for the program, if any ("Not yet" when none).
export function appliedLabel(status) {
  if (!status) return "Not yet";
  const text = status.replaceAll("_", " ");
  return text.charAt(0).toUpperCase() + text.slice(1);
}

// "Contiga, Wena Rose" from a student object.
export function studentLabel(student) {
  if (!student) return "—";
  return `${student.last_name}, ${student.first_name}`;
}
