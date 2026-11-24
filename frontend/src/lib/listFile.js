import api from "../services/api";
import { parseCSV } from "./csv";

/*
  Reads a list file chosen on Approved Lists or Enrollment into rows of cells.
    .csv                       read in the browser (no server needed)
    .xlsx .docx                read by Laravel (POST /staff/read-table), no AI service needed
    .pdf .png .jpg             read by the AI service (through the same address)
  For files read on the server, title lines above the header (agency name,
  school year...) are dropped: the list starts at the widest row near the top.
  Returns { rows, warnings, metadata, cells, method }. cells (scanned forms only) has, for each row,
  { conf, note, check } per cell: check = true means "hard to read, compare with the paper".
*/
export const LIST_ACCEPT = ".csv,.xlsx,.docx,.pdf,.png,.jpg,.jpeg";

// Excel on Windows often saves "CSV" in the old Windows-1252 code page: Ñ would come out as "�".
// Read the bytes as UTF-8 first; if they are not valid UTF-8, read them as Windows-1252.
export async function csvText(file) {
  const bytes = await file.arrayBuffer();
  try {
    return new TextDecoder("utf-8", { fatal: true }).decode(bytes);
  } catch {
    return new TextDecoder("windows-1252").decode(bytes);
  }
}

// options.signal: an AbortController signal, for the "Cancel" button while a file is read.
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
  // A header repeated further down (a second sheet, a table that continues on the next page) is not a row.
  const headerKey = (kept[widest]?.row || []).map((cell) => String(cell || "").trim().toLowerCase()).join("|");
  const list = kept
    .slice(widest)
    .filter(({ row }, index) => index === 0 || row.map((cell) => String(cell || "").trim().toLowerCase()).join("|") !== headerKey);
  const width = Math.max(0, ...list.map(({ row }) => row.length));
  const rows = list.map(({ row }) => [...row.map((cell) => String(cell ?? "")), ...Array(width - row.length).fill("")]);
  const cells = allCells ? list.map(({ cells: c }) => c || null) : null;

  return { rows, cells, warnings: data.warnings || [], metadata: data.metadata || {}, method: data.method || "" };
}
