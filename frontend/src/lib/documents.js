import api from "../services/api";

export async function loadMyDocuments() {
  const { data } = await api.get("/student/documents");
  return data;
}

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

    if (!file.flags && row.flags) file.flags = row.flags;
    if (file.status === "uploaded" && row.status !== "uploaded") file.status = row.status;
  }

  for (const files of byType.values()) {
    files.sort((a, b) => new Date(b.uploaded_at) - new Date(a.uploaded_at));
  }

  return [...byType.entries()].map(([type, files]) => ({ type, files }));
}

export function latestUsable(groups, type) {
  const group = groups.find((g) => g.type === type);
  return group?.files.find((file) => !file.is_expired) || null;
}

export function checkableRow(file) {
  return (
    file.rows.find((row) => !row.application) ||
    file.rows.find((row) => ["draft", "needs_action"].includes(row.application?.status)) ||
    null
  );
}
