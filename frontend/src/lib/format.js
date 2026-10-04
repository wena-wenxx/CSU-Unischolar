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

// Required requirements that have no uploaded document yet.
export function missingRequirements(application) {
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
// "﻿" at the start makes Excel read it as UTF-8 (keeps ñ and Ñ intact).
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

  const blob = new Blob(["﻿" + csv], {
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
