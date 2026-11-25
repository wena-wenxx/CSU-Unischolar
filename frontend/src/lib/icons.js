export function iconFor(path = "") {
  const rules = [
    [/dashboard/, "home"],
    [/recommendations/, "sparkle"],
    [/scholarships/, "award"],
    [/documents/, "folder"],
    [/auto-review|applications/, "file"],
    [/history/, "history"],
    [/profile/, "user"],
    [/contact|messages/, "mail"],
    [/help/, "help"],
    [/batches/, "layers"],
    [/forwarded/, "send"],
    [/agency-lists/, "checklist"],
    [/enrollment/, "badge"],
    [/scholars/, "users"],
    [/payroll/, "wallet"],
    [/external-grantees/, "globe"],
    [/announcements/, "megaphone"],
    [/data-bank/, "database"],
    [/reports/, "chart"],
    [/settings/, "settings"],
    [/activity/, "activity"],
    [/staff|students/, "users"],
  ];
  const hit = rules.find(([pattern]) => pattern.test(path));
  return hit ? hit[1] : "file";
}
