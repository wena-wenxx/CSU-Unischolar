import { useCallback, useEffect, useState } from 'react';
import api, { errMsg } from '../../api';
import { Alert, Button, Card, PageTitle, inputClass } from '../../ui';

export default function PayrollPage() {
  const [scholarships, setScholarships] = useState([]);
  const [rows, setRows] = useState([]);
  const [gen, setGen] = useState({ scholarship_id: '', period: '', amount: '' });
  const [filter, setFilter] = useState({ scholarship_id: '', period: '' });
  const [msg, setMsg] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try {
      const params = Object.fromEntries(Object.entries(filter).filter(([, v]) => v));
      const res = await api.get('/payroll', { params });
      setRows(res.data);
    } catch (err) {
      setError(errMsg(err));
    }
  }, [filter]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { api.get('/scholarships').then((r) => setScholarships(r.data)).catch(() => {}); }, []);

  const generate = async (e) => {
    e.preventDefault();
    setMsg(''); setError('');
    try {
      const res = await api.post('/payroll/generate', gen);
      setMsg(res.data.message);
      load();
    } catch (err) { setError(errMsg(err)); }
  };

  const update = async (id, data) => {
    try { await api.patch(`/payroll/${id}`, data); load(); } catch (err) { setError(errMsg(err)); }
  };

  const exportCsv = async (onlyReady) => {
    try {
      const params = { ...Object.fromEntries(Object.entries(filter).filter(([, v]) => v)), ...(onlyReady ? { only_ready: 1 } : {}) };
      const res = await api.get('/payroll/export', { params, responseType: 'blob' });
      const url = URL.createObjectURL(res.data);
      const a = document.createElement('a');
      a.href = url; a.download = 'payroll-ready.csv'; a.click();
      URL.revokeObjectURL(url);
    } catch (err) { setError(errMsg(err)); }
  };

  return (
    <div className="p-6 max-w-6xl mx-auto">
      <PageTitle sub="Prepare payroll-ready lists. This system does NOT pay anyone or touch any bank.">Payroll Preparation</PageTitle>
      <Alert>{error}</Alert>
      <Alert type="success">{msg}</Alert>

      <Card className="mb-6">
        <h2 className="font-semibold mb-2">Generate payroll for a scholarship</h2>
        <p className="text-xs text-gray-500 mb-2">Uses all ACTIVE grantees of that scholarship. Students who are enrolled AND have an ATM become "ready"; the rest stay "draft".</p>
        <form onSubmit={generate} className="grid md:grid-cols-4 gap-2">
          <select className={inputClass} required value={gen.scholarship_id} onChange={(e) => setGen({ ...gen, scholarship_id: e.target.value })}>
            <option value="">Choose scholarship</option>
            {scholarships.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
          <input className={inputClass} required placeholder="Period (e.g. 1st Sem 2026-2027)" value={gen.period} onChange={(e) => setGen({ ...gen, period: e.target.value })} />
          <input className={inputClass} required type="number" min="0" placeholder="Amount per student" value={gen.amount} onChange={(e) => setGen({ ...gen, amount: e.target.value })} />
          <Button type="submit">Generate</Button>
        </form>
      </Card>

      <div className="flex flex-wrap gap-2 items-center mb-3">
        <select className="border rounded px-2 py-1 text-sm" value={filter.scholarship_id} onChange={(e) => setFilter({ ...filter, scholarship_id: e.target.value })}>
          <option value="">All scholarships</option>
          {scholarships.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        <input className="border rounded px-2 py-1 text-sm" placeholder="Filter by period" value={filter.period} onChange={(e) => setFilter({ ...filter, period: e.target.value })} />
        <Button variant="secondary" onClick={() => exportCsv(false)}>Export all (CSV)</Button>
        <Button onClick={() => exportCsv(true)}>Export ready only (CSV)</Button>
        <Button variant="secondary" onClick={() => window.print()}>Print</Button>
      </div>

      <Card className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead><tr className="text-left text-gray-500 border-b"><th className="py-1">Student</th><th>Student ID</th><th>Scholarship</th><th>Amount</th><th>ATM</th><th>Period</th><th>Status</th><th>Signature</th></tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-b">
                <td className="py-1">{r.scholar_record?.student?.first_name} {r.scholar_record?.student?.last_name}</td>
                <td>{r.scholar_record?.student?.student_id}</td>
                <td>{r.scholar_record?.scholarship?.name}</td>
                <td>{r.amount}</td>
                <td>{r.bank_atm_status}</td>
                <td>{r.period}</td>
                <td>
                  <select className="border rounded px-1 text-xs" value={r.status} onChange={(e) => update(r.id, { status: e.target.value })}>
                    <option value="draft">draft</option><option value="ready">ready</option><option value="processed">processed</option>
                  </select>
                </td>
                <td>
                  <input className="border rounded px-1 text-xs w-28" defaultValue={r.signature || ''} placeholder="signed?"
                    onBlur={(e) => e.target.value !== (r.signature || '') && update(r.id, { signature: e.target.value })} />
                </td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan="8" className="py-3 text-gray-500">No payroll records yet.</td></tr>}
          </tbody>
        </table>
      </Card>
    </div>
  );
}
