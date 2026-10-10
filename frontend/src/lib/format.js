import { FILES_URL } from "../services/api";

/* Small helpers shared by every page. */

export function formatDate(date) {
  if (!date) return "—";

  return new Date(date).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

export function formatMoney(value) {
  // Amounts can be empty until OAS enters the official figure.
  if (value === null || value === undefined || value === "") {
    return "Amount not set";
  }

  return `₱${Number(value).toLocaleString("en-PH", {
    minimumFractionDigits: 2,
  })}`;
}

// "needs_action" -> "needs action"
export function statusLabel(status) {
  return String(status || "—").replaceAll("_", " ");
}

// Which colour family a status badge uses.
export function statusTone(status) {
  const value = String(status || "").toLowerCase();

  if (
    ["approved", "complete", "active", "ready", "validated", "processed", "completed"].includes(value)
  ) {
    return "success";
  }

  if (
    ["needs_action", "flagged", "needs_review", "under_review", "submitted", "processing"].includes(value)
  ) {
    return "warning";
  }

  if (["rejected", "inactive", "closed"].includes(value)) {
    return "danger";
  }

  return "neutral";
}

export function statusClass(status) {
  return `status status-${statusTone(status)}`;
}

export function fullName(student) {
  if (!student) return "—";

  return [student.first_name, student.middle_name, student.last_name]
    .filter(Boolean)
    .join(" ");
}

export function fileUrl(document) {
  return `${FILES_URL}/${document.file_path}`;
}

// Announcement picture: "/announcements/x.jpg" is a sample shipped with the
// frontend; "announcements/abc.jpg" was uploaded by staff. null = no picture.
export function announcementImage(item) {
  if (!item?.image_path) return null;
  return item.image_path.startsWith("/") ? item.image_path : `${FILES_URL}/${item.image_path}`;
}

// Number of documents the AI flagged in an application (list or full record).
export function flaggedCount(application) {
  if (typeof application.flagged_count === "number") return application.flagged_count;
  return (application.documents || []).filter((document) => document.status === "flagged").length;
}

// First words of a long text, for previews.
export function preview(text, length = 140) {
  const clean = String(text || "").replace(/\s+/g, " ").trim();
  return clean.length > length ? `${clean.slice(0, length).replace(/\s+\S*$/, "").replace(/[.,;:]+$/, "")}…` : clean;
}

// Required requirements that have no uploaded document yet.
// (The staff list sends missing_requirements as names, to stay small.)
export function missingRequirements(application) {
  if (Array.isArray(application.missing_requirements)) {
    return application.missing_requirements.map((name) => ({ name }));
  }

  const uploaded = new Set(
    (application.documents || []).map(
      (document) => document.scholarship_requirement_id
    )
  );

  return (application.scholarship?.requirements || []).filter(
    (requirement) => requirement.is_required && !uploaded.has(requirement.id)
  );
}

export function enrollmentText(application) {
  if (application.status !== "approved") return "—";

  return application.enrollment_verified
    ? `Verified ${formatDate(application.enrollment_verified_at)}`
    : "Not yet verified";
}

// Builds a CSV file in the browser and downloads it.
// A "\uFEFF" mark at the start makes Excel read it as UTF-8 (keeps ñ and Ñ intact).
// Returns false when there is nothing to export.
export function downloadCSV(filename, rows) {
  if (!rows.length) return false;

  const headers = Object.keys(rows[0]);

  const csv = [
    headers.join(","),
    ...rows.map((row) =>
      headers
        .map((header) => `"${String(row[header] ?? "").replaceAll('"', '""')}"`)
        .join(",")
    ),
  ].join("\n");

  const blob = new Blob(["\uFEFF" + csv], {
    type: "text/csv;charset=utf-8;",
  });

  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = filename;
  link.click();

  URL.revokeObjectURL(url);

  return true;
}

/* ---------- Time ---------- */

// "just now", "5 minutes ago", "2 hours ago", "3 days ago", or a date.
export function timeAgo(date) {
  if (!date) return "—";

  const seconds = Math.round((Date.now() - new Date(date).getTime()) / 1000);

  if (seconds < 60) return "just now";

  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes} minute${minutes === 1 ? "" : "s"} ago`;

  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} hour${hours === 1 ? "" : "s"} ago`;

  const days = Math.round(hours / 24);
  if (days < 30) return `${days} day${days === 1 ? "" : "s"} ago`;

  return formatDate(date);
}

export function formatDateTime(date) {
  if (!date) return "—";

  return new Date(date).toLocaleString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

/* ---------- Scholarship programs ---------- */

// Grouped like the OAS list of scholarship programs.
export const CATEGORY_LABELS = {
  ched: "CHED-funded",
  government: "Other Government",
  private: "Private-funded",
  csu: "University-funded",
};

export function categoryLabel(category) {
  return CATEGORY_LABELS[category] || (category === "lgu" ? "LGU" : "Other");
}

// Agency-direct: students apply directly at the agency, not in ScholarGuide.
export const isAgencyDirect = (scholarship) => scholarship?.application_mode === "agency_direct";

// Whole days from today (Manila) until a YYYY-MM-DD date. Negative = past.
function daysUntil(day) {
  if (!day) return null;

  const today = new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });
  const ms = new Date(`${String(day).slice(0, 10)}T00:00:00Z`) - new Date(`${today}T00:00:00Z`);

  return Math.round(ms / 86400000);
}

// Label + colour for a program's availability (computed by the backend).
export function availabilityInfo(scholarship) {
  if (isAgencyDirect(scholarship)) return { label: "Apply at the agency", tone: "neutral" };

  const left = daysUntil(scholarship.application_end);

  switch (scholarship.availability) {
    case "open":
      if (left === null) return { label: "Open", tone: "success" };
      if (left === 0) return { label: "Open · closes today", tone: "warning" };
      if (left <= 7) return { label: `Open · ${left} day${left === 1 ? "" : "s"} left`, tone: "warning" };
      return { label: "Open", tone: "success" };
    case "upcoming":
      return { label: `Opens ${formatDate(scholarship.application_start)}`, tone: "neutral" };
    case "deadline_passed":
      return { label: "Closed · deadline passed", tone: "danger" };
    case "closed":
      return { label: "Closed", tone: "danger" };
    default:
      return { label: "Not open", tone: "danger" };
  }
}

export function deadlineText(scholarship) {
  if (isAgencyDirect(scholarship)) return "Apply directly at the agency";
  if (!scholarship.application_end) return "No deadline set";

  return `Deadline: ${formatDate(scholarship.application_end)}`;
}

/* ---------- What students see ---------- */

// Plain-language meaning of each application status (shown to students).
export const STATUS_HELP = {
  draft: {
    title: "Draft",
    text: "Not sent yet. Upload every required document, then press Submit application.",
  },
  submitted: {
    title: "Submitted",
    text: "OAS has received your application. Nothing to do now; staff will check your documents.",
  },
  under_review: {
    title: "Under review",
    text: "OAS staff are checking your documents. Nothing to do now.",
  },
  needs_action: {
    title: "Needs action",
    text: "OAS needs something from you. Read their remarks, upload the corrected document, and submit again.",
  },
  complete: {
    title: "Complete",
    text: "Your documents are complete. OAS has forwarded your application to the scholarship provider, who makes the final decision.",
  },
  approved: {
    title: "Approved",
    text: "The provider approved you. OAS will verify that you are currently enrolled, then tag you as a grantee.",
  },
  rejected: {
    title: "Not approved",
    text: "The provider did not approve this application. See the remarks. You may apply to other open scholarships.",
  },
};

// Students see a simple document state, not the raw AI flags:
// the AI only helps OAS staff, and staff decide what to ask the student.
export function studentDocumentState(document) {
  if (!document) return { label: "Not uploaded", tone: "neutral" };
  if (document.status === "validated") return { label: "Checked", tone: "success" };
  if (document.status === "uploaded") return { label: "Uploaded", tone: "success" };
  return { label: "Being checked by OAS", tone: "warning" };
}

// Wording for one step of an application's history.
export function stepLabel(toStatus) {
  const labels = {
    draft: "Application started",
    submitted: "Submitted to OAS",
    under_review: "Under review by OAS",
    needs_action: "OAS asked for action",
    complete: "Forwarded to the scholarship provider",
    approved: "Approved by the provider",
    rejected: "Not approved",
    enrollment_verified: "Enrollment verified by OAS",
    enrollment_not_verified: "Enrollment could not be verified",
    grantee_tagged: "Tagged as grantee",
  };

  return labels[toStatus] || statusLabel(toStatus);
}

// "Liza Demo Mendoza" -> "LM" (sidebar avatar)
export function initials(name) {
  return String(name || "?")
    .split(/\s+/)
    .filter(Boolean)
    .filter((part, index, parts) => index === 0 || index === parts.length - 1)
    .map((part) => part[0].toUpperCase())
    .join("");
}
