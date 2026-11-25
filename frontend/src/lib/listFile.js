import api from "../services/api";
import { parseCSV } from "./csv";

export const LIST_ACCEPT = ".csv,.xlsx,.docx,.pdf,.png,.jpg,.jpeg";

export async function csvText(file) {
  const bytes = await file.arrayBuffer();
  try {
    return new TextDecoder("utf-8", { fatal: true }).decode(bytes);
  } catch {
    return new TextDecoder("windows-1252").decode(bytes);
  }
}

export async function readListFile(file, { signal } = {}) {
  const name = file.name.toLowerCase();

  if (file.size <= 0) {
    throw new Error("The selected file is empty.");
  }
  if (file.size > 20 * 1024 * 1024) {
    throw new Error("The file is larger than 20 MB. Compress or split it, then try again.");
  }

  if (name.endsWith(".csv")) {
    return { rows: parseCSV(await csvText(file)), warnings: [] };
  }

  if (/\.(xls|doc)$/.test(name)) {
    throw new Error("Old Office format: open the file and Save As .xlsx (Excel) or .docx (Word) first.");
  }

  if (!/\.(xlsx|docx|pdf|png|jpe?g)$/.test(name)) {
    throw new Error("Choose a CSV, Excel (.xlsx), Word (.docx), PDF, PNG or JPG file.");
  }

  const body = new FormData();
  body.append("file", file);
  const { data } = await api.post("/staff/read-table", body, { signal });

  const allCells = Array.isArray(data.cells) ? data.cells : null;
  const kept = (data.rows || [])
    .map((row, index) => ({ row, cells: allCells ? allCells[index] : null }))
    .filter(({ row }) => row.some((cell) => String(cell || "").trim()));
  const top = kept.slice(0, 30);
  const widest = top.reduce((best, item, index) => (item.row.length > (top[best]?.row.length || 0) ? index : best), 0);
  const headerKey = (kept[widest]?.row || []).map((cell) => String(cell || "").trim().toLowerCase()).join("|");
  const list = kept
    .slice(widest)
    .filter(({ row }, index) => index === 0 || row.map((cell) => String(cell || "").trim().toLowerCase()).join("|") !== headerKey);
  const width = Math.max(0, ...list.map(({ row }) => row.length));
  const rows = list.map(({ row }) => [...row.map((cell) => String(cell ?? "")), ...Array(width - row.length).fill("")]);
  const cells = allCells ? list.map(({ cells: c }) => c || null) : null;

  return { rows, cells, warnings: data.warnings || [], metadata: data.metadata || {}, method: data.method || "" };
}
