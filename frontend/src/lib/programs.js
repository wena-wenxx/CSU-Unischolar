
export function programLabel(scholarship) {
  if (!scholarship) return "—";
  if (scholarship.short_name) return scholarship.short_name;
  const bracket = String(scholarship.name || "").match(/\(([^)]+)\)\s*$/);
  return bracket ? bracket[1] : scholarship.name;
}

export function programTabs(rows, getProgram, allLabel = "All programs") {
  const counts = new Map();
  rows.forEach((row) => {
    const program = getProgram(row);
    if (!program) return;
    const current = counts.get(program.id) || { program, count: 0 };
    current.count += 1;
    counts.set(program.id, current);
  });

  const tabs = [...counts.values()]
    .map(({ program, count }) => ({ key: String(program.id), label: programLabel(program), title: program.name, count }))
    .sort((a, b) => a.label.localeCompare(b.label));

  return [{ key: "all", label: allLabel, count: rows.length }, ...tabs];
}

export function byLastName(a, b) {
  const x = `${a?.last_name || ""} ${a?.first_name || ""}`.trim().toLowerCase();
  const y = `${b?.last_name || ""} ${b?.first_name || ""}`.trim().toLowerCase();
  return x.localeCompare(y);
}

export function lastFirst(student) {
  if (!student) return "—";
  return [student.last_name, [student.first_name, student.middle_name].filter(Boolean).join(" ")].filter(Boolean).join(", ");
}
