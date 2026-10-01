import { useCallback, useEffect, useState } from 'react';
import api, { errMsg } from '../../api';
import { Alert, Badge, Button, Card, PageTitle } from '../../ui';

export default function ScholarsPage() {
  const [records, setRecords] = useState([]);
  const [filter, setFilter] = useState('active');
  const [msg, setMsg] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try { setRecords((await api.get('/scholars', { params: filter ? { status: filter } : {} })).data); }
    catch (err) { setError(errMsg(err)); }
  }, [filter]);
  useEffect(() => { load(); }, [load]);

  const run = async (fn, okMsg) => {
    setMsg(''); setError('');
    try { await fn(); setMsg(okMsg); await load(); } catch (err) { setError(errMsg(err)); }
  };

  const verifyEnrollment = (r, value) =>
    run(() => api.put(`/scholars/${r.id}/enrollment`, { currently_enrolled: value }), 'Enrollment status updated.');
  const setAtm = (r, value) =>
    run(() => api.put(`/scholars/${r.id}/atm`, { has_atm: value }), 'ATM status updated.');

  return (
    <div className="p-6 max-w-5xl mx-auto">
      <PageTitle sub="Verify enrollment and ATM status before a grantee is payroll-ready">Scholars / Grantees</PageTitle>
      <Alert>{error}</Alert>
      <Alert type="success">{msg}</Alert>

      <div className="mb-3 flex gap-2 items-center">
        <span className="text-sm">Filter:</span>
        <select className="border rounded px-2 py-1 text-sm" value={filter} onChange={(e) => setFilter(e.target.value)}>
          <option value="">All</option><option value="active">Active</option>
          <option value="inactive">Inactive</option><option value="completed">Completed</option>
        </select>
      </div>

      <Card className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-gray-500 border-b">
              <th className="py-1">Student</th><th>Scholarship</th><th>Status</th><th>Enrolled</th><th>ATM</th><th>Payroll rows</th>
            </tr>
          </thead>
          <tbody>
            {records.map((r) => (
              <tr key={r.id} className="border-b align-top">
                <td className="py-2">{r.student?.first_name} {r.student?.last_name}<br /><span className="text-xs text-gray-500">{r.student?.student_id}</span></td>
                <td>{r.scholarship?.name}</td>
                <td><Badge fallback={r.status} /></td>
                <td>
                  {r.currently_enrolled
                    ? <span className="text-green-700 font-medium">Yes</span>
                    : <span className="flex items-center gap-2"><span className="text-red-600">Not verified</span>
                        <Button onClick={() => verifyEnrollment(r, true)}>Mark enrolled</Button></span>}
                </td>
                <td>
                  {r.has_atm
                    ? <span className="text-green-700 font-medium">Yes</span>
                    : <span className="flex items-center gap-2"><span className="text-red-600">No / unset</span>
                        <Button onClick={() => setAtm(r, true)}>Confirm has ATM</Button></span>}
                </td>
                <td>{r.payroll_records?.length || 0}</td>
              </tr>
            ))}
            {records.length === 0 && <tr><td colSpan="6" className="py-3 text-gray-500">No scholar records yet.</td></tr>}
          </tbody>
        </table>
      </Card>
      <p className="text-xs text-gray-500 mt-2">Only students who are both enrolled AND have an ATM become "ready" when payroll is generated.</p>
    </div>
  );
}
