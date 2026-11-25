import { downloadFile } from "./download";

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

export function downloadPayrollFile(kind, body) {
  return downloadFile(`/payroll/form/${kind}`, body, `general-payroll.${kind}`);
}

export function peso(value) {
  if (value === null || value === undefined) return "";
  return Number(value).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
