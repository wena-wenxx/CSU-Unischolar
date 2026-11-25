import { useMemo, useState } from "react";

export default function ListPreview({ headers, rows, cells, firstLine, pickedColumn, onEdit }) {
  const [onlyCheck, setOnlyCheck] = useState(false);
  const [editing, setEditing] = useState(null);
  const [draft, setDraft] = useState("");

  const needsCheck = (r, c) => Boolean(cells?.[r]?.[c]?.check);
  const rowsToCheck = useMemo(
    () => rows.map((_, r) => r).filter((r) => headers.some((_, c) => needsCheck(r, c))),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [rows, cells, headers]
  );
  const cellsToCheck = rowsToCheck.reduce((sum, r) => sum + headers.filter((_, c) => needsCheck(r, c)).length, 0);
  const shown = onlyCheck ? rowsToCheck : rows.map((_, r) => r);

  function start(r, c) {
    setEditing([r, c]);
    setDraft(rows[r][c] || "");
  }

  function save() {
    if (!editing) return;
    onEdit(editing[0], editing[1], draft.trim());
    setEditing(null);
  }

  return (
    <div className="list-preview">
      <div className="list-preview-bar">
        <span className="muted small">
          {rows.length} row{rows.length === 1 ? "" : "s"}
          {cells && (
            <>
              {" · "}
              <strong className={cellsToCheck ? "warning-text" : ""}>
                {cellsToCheck} cell{cellsToCheck === 1 ? "" : "s"} to check in {rowsToCheck.length} row
                {rowsToCheck.length === 1 ? "" : "s"}
              </strong>
            </>
          )}
          {" · "}Click a cell to correct it.
        </span>
        {cells && cellsToCheck > 0 && (
          <label className="choice">
            <input type="checkbox" checked={onlyCheck} onChange={(event) => setOnlyCheck(event.target.checked)} />
            Show only rows to check
          </label>
        )}
      </div>

      <div className="table-wrapper list-preview-table">
        <table>
          <thead>
            <tr>
              <th>Row</th>
              {headers.map((h, i) => (
                <th key={i} className={String(i) === pickedColumn ? "col-picked" : ""}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {shown.map((r) => (
              <tr key={r}>
                <td>{firstLine + r}</td>
                {headers.map((_, c) => {
                  const info = cells?.[r]?.[c];
                  const isEditing = editing && editing[0] === r && editing[1] === c;
                  const classes = [
                    String(c) === pickedColumn ? "col-picked" : "",
                    info?.check ? "cell-check" : "",
                    info?.edited ? "cell-edited" : "",
                  ].join(" ");
                  const hint = info?.edited
                    ? "Corrected by staff"
                    : info?.check
                      ? `Check this cell against the paper${info.note ? `: ${info.note}` : ""}`
                      : info?.note || "";
                  return (
                    <td key={c} className={classes} title={hint}>
                      {isEditing ? (
                        <input
                          className="cell-input"
                          autoFocus
                          value={draft}
                          aria-label={`Correct ${headers[c]} in row ${firstLine + r}`}
                          onChange={(event) => setDraft(event.target.value)}
                          onBlur={save}
                          onKeyDown={(event) => {
                            if (event.key === "Enter") save();
                            if (event.key === "Escape") setEditing(null);
                          }}
                        />
                      ) : (
                        <button type="button" className="cell-button" onClick={() => start(r, c)}>
                          {rows[r][c] || <span className="muted">—</span>}
                        </button>
                      )}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {cells && (
        <p className="muted small">
          <span className="legend cell-check">Highlighted</span> = hard to read on the scan (hover to see why).{" "}
          <span className="legend cell-edited">Green</span> = corrected by you.
        </p>
      )}
    </div>
  );
}
