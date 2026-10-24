import { useState } from "react";
import MemoReader from "./MemoReader";

/*
  Eligibility rules of one scholarship, used by AI Recommendations.
  "Read the program memo with AI" fills them in from a memo (round 12);
  staff check every value and press Save.
  Empty = no rule. Students are checked against every rule that is set;
  each rule is a "must-have".

    <EligibilityRules value={form} onChange={(patch) => setForm({ ...form, ...patch })}
      options={{ year_levels: [...], colleges: [...] }} idPrefix="new" />
*/
export default function EligibilityRules({ value, onChange, options, idPrefix }) {
  const toggle = (key, item) => {
    const list = value[key] || [];
    onChange({ [key]: list.includes(item) ? list.filter((x) => x !== item) : [...list, item] });
  };

  const [reading, setReading] = useState(false);

  return (
    <div className="rules-grid">
      <div className="full-column memo-launch">
        <button type="button" className="button button-small button-secondary" onClick={() => setReading(true)}>
          Read the program memo with AI
        </button>
        <small className="muted">The AI suggests the rules from the memo; you check them.</small>
      </div>
      {reading && <MemoReader form={value} onApply={onChange} onClose={() => setReading(false)} />}

      <div>
        <label htmlFor={`${idPrefix}-gwa`}>Highest GWA allowed</label>
        <input
          id={`${idPrefix}-gwa`}
          type="number"
          min="1"
          max="5"
          step="0.01"
          value={value.max_gwa}
          onChange={(event) => onChange({ max_gwa: event.target.value })}
          placeholder="e.g. 2.50 (empty = any)"
        />
        <small className="muted">1.00 is the highest grade, so 2.50 means 2.50 or better.</small>
      </div>

      <div>
        <label htmlFor={`${idPrefix}-income`}>Highest annual family income (₱)</label>
        <input
          id={`${idPrefix}-income`}
          type="number"
          min="0"
          step="1000"
          value={value.max_family_income}
          onChange={(event) => onChange({ max_family_income: event.target.value })}
          placeholder="e.g. 300000 (empty = any)"
        />
      </div>

      <fieldset className="rules-choices">
        <legend>Year levels (none ticked = any)</legend>
        {(options.year_levels || []).map((year) => (
          <label key={year} className="choice">
            <input
              type="checkbox"
              checked={(value.year_levels || []).includes(year)}
              onChange={() => toggle("year_levels", year)}
            />
            {year}
          </label>
        ))}
      </fieldset>

      <fieldset className="rules-choices">
        <legend>Colleges (none ticked = any)</legend>
        {(options.colleges || []).map((college) => (
          <label key={college} className="choice">
            <input
              type="checkbox"
              checked={(value.colleges || []).includes(college)}
              onChange={() => toggle("colleges", college)}
            />
            {college}
          </label>
        ))}
      </fieldset>

      <label className="choice full-column">
        <input
          type="checkbox"
          checked={Boolean(value.one_scholarship_only)}
          onChange={(event) => onChange({ one_scholarship_only: event.target.checked })}
        />
        The student must not hold another active scholarship (including outside scholarships in External Grantees)
      </label>
    </div>
  );
}
