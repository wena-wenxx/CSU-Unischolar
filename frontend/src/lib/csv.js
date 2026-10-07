/*
  Small CSV reader (no extra library needed).
  Handles quoted values ("Dela Cruz, Juan"), doubled quotes (""),
  Windows line endings, Excel's UTF-8 marker, and comma, semicolon or tab
  separators (Excel in some regions saves CSV with semicolons).
  Returns an array of rows; each row is an array of strings.
*/
export function parseCSV(text) {
  const clean = text.replace(/^\uFEFF/, "");
  const firstLine = clean.split(/\r?\n/, 1)[0] || "";
  const delimiter = [",", ";", "\t"]
    .map((d) => [d, firstLine.split(d).length])
    .sort((a, b) => b[1] - a[1])[0][0];

  const rows = [];
  let row = [];
  let value = "";
  let inQuotes = false;

  for (let i = 0; i < clean.length; i++) {
    const ch = clean[i];

    if (inQuotes) {
      if (ch === '"' && clean[i + 1] === '"') {
        value += '"';
        i++;
      } else if (ch === '"') {
        inQuotes = false;
      } else {
        value += ch;
      }
    } else if (ch === '"') {
      inQuotes = true;
    } else if (ch === delimiter) {
      row.push(value);
      value = "";
    } else if (ch === "\n" || ch === "\r") {
      if (ch === "\r" && clean[i + 1] === "\n") i++;
      row.push(value);
      rows.push(row);
      row = [];
      value = "";
    } else {
      value += ch;
    }
  }

  if (value !== "" || row.length) {
    row.push(value);
    rows.push(row);
  }

  // Drop completely empty lines.
  return rows.filter((r) => r.some((cell) => cell.trim() !== ""));
}
