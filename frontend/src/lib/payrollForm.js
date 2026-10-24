import { downloadFile } from "./download";

/*
  General Payroll (General Form No. 6-A): the choices made in the Payroll
  page travel to the printable page in the address (so a refresh keeps them),
  and the same choices are sent to the server for the Word file.
*/
const KEYS = ["batch", "voucher", "purpose", "sheet_start", "per_sheet", "prepared_name", "prepared_title"];

export function formAddress(ids, options) {
  const params = new URLSearchParams();
  params.set("ids", ids.join(","));
  KEYS.forEach((key) => {
    if (options[key] !== undefined && options[key] !== "") params.set(key, String(options[key]));
  });
  params.set("split_atm", options.split_atm ? "1" : "0");
  return `/staff/payroll/form?${params.toString()}`;
}

export function readFormAddress(search) {
  const params = new URLSearchParams(search);
  const body = {
    ids: (params.get("ids") || "")
      .split(",")
      .map((x) => Number(x))
      .filter(Boolean),
    split_atm: params.get("split_atm") !== "0",
  };
  KEYS.forEach((key) => {
    if (params.get(key)) body[key] = params.get(key);
  });
  return body;
}

// Downloads the form as a file: kind "pdf" or "docx". Returns the file name.
export function downloadPayrollFile(kind, body) {
  return downloadFile(`/payroll/form/${kind}`, body, `general-payroll.${kind}`);
}

export function peso(value) {
  if (value === null || value === undefined) return "";
  return Number(value).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
