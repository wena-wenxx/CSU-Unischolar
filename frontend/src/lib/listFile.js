import api from "../services/api";
import { parseCSV } from "./csv";

/*
  Reads a list file chosen on Approved Lists or Enrollment into rows of cells.
    .csv                       read in the browser (no server needed)
    .xlsx .docx                read by Laravel (POST /staff/read-table), no AI service needed
    .pdf .png .jpg             read by the AI service (through the same address)
  For files read on the server, title lines above the header (agency name,
  school year...) are dropped: the list starts at the widest row near the top.
  Returns { rows, warnings }.
*/
export const LIST_ACCEPT = ".csv,.xlsx,.docx,.pdf,.png,.jpg,.jpeg";

export async function readListFile(file) {
  const name = file.name.toLowerCase();

  if (name.endsWith(".csv")) {
    const text = await file.text();
    return { rows: parseCSV(text), warnings: [] };
  }

  if (/\.(xls|doc)$/.test(name)) {
    throw new Error("Old Office format: open the file and Save As .xlsx (Excel) or .docx (Word) first.");
  }

  if (!/\.(xlsx|docx|pdf|png|jpe?g)$/.test(name)) {
    throw new Error("Choose a CSV, Excel (.xlsx), Word (.docx), PDF, PNG or JPG file.");
  }

  const body = new FormData();
  body.append("file", file);
  const { data } = await api.post("/staff/read-table", body);

  let rows = (data.rows || []).filter((row) => row.some((cell) => String(cell || "").trim()));
  const top = rows.slice(0, 30);
  const widest = top.reduce((best, row, index) => (row.length > (top[best]?.length || 0) ? index : best), 0);
  rows = rows.slice(widest);
  const width = Math.max(0, ...rows.map((row) => row.length));
  rows = rows.map((row) => [...row.map((cell) => String(cell ?? "")), ...Array(width - row.length).fill("")]);

  return { rows, warnings: data.warnings || [] };
}
