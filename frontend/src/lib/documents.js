import api from "../services/api";

/*
  Helpers for "My Documents".
  The server returns one row per document record. The same stored file can
  appear more than once (reused for several applications), so files are
  grouped by type, then by stored file.
*/

export async function loadMyDocuments() {
  const { data } = await api.get("/student/documents");
  return data; // { types, validity_months, documents }
}

// [{ type, files: [{ ...latest row, rows: [...], applications: [...] }] }]
export function groupDocuments(data) {
  const byType = new Map();

  for (const type of data.types || []) byType.set(type, []);

  for (const row of data.documents || []) {
    if (!byType.has(row.type)) byType.set(row.type, []);

    const files = byType.get(row.type);
    let file = files.find((f) => f.file_path === row.file_path);

    if (!file) {
      file = { ...row, rows: [], applications: [] };
      files.push(file);
    }

    file.rows.push(row);
    if (row.application) file.applications.push(row.application);

    // Show the most informative AI result for the file.
    if (!file.flags && row.flags) file.flags = row.flags;
    if (file.status === "uploaded" && row.status !== "uploaded") file.status = row.status;
  }

  for (const files of byType.values()) {
    files.sort((a, b) => new Date(b.uploaded_at) - new Date(a.uploaded_at));
  }

  return [...byType.entries()].map(([type, files]) => ({ type, files }));
}

// Newest saved file of a type that has not expired (for reuse when applying).
export function latestUsable(groups, type) {
  const group = groups.find((g) => g.type === type);
  return group?.files.find((file) => !file.is_expired) || null;
}

// A row of this file that the student may run the AI check on:
// saved in My Documents, or in a draft / needs-action application.
export function checkableRow(file) {
  return (
    file.rows.find((row) => !row.application) ||
    file.rows.find((row) => ["draft", "needs_action"].includes(row.application?.status)) ||
    null
  );
}
