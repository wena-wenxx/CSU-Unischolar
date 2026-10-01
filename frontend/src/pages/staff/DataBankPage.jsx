import { useCallback, useEffect, useState } from 'react';
import api, { errMsg } from '../../api';
import { APP_STATUS } from '../../constants';
import { Alert, Badge, Button, Card, PageTitle, inputClass } from '../../ui';

export default function DataBankPage() {
  const [q, setQ] = useState('');
  const [students, setStudents] = useState([]);
  const [detail, setDetail] = useState(null);
  const [error, setError] = useState('');

  const search = useCallback(async (term) => {
    try {
      const res = await api.get('/data-bank/search', { params: { q: term } });
      setStudents(res.data);
    } catch (err) {
      setError(errMsg(err));
    }
  }, []);

  useEffect(() => { search(''); }, [search]);

  const open = async (id) => {
    try {
      const res = await api.get(`/data-bank/students/${id}`);
      setDetail(res.data);
    } catch (err) {
      setError(errMsg(err));
    }
  };

  return (
    <div className="p-6 max-w-6xl mx-auto">
      <PageTitle sub="Search a student to see their complete scholarship history">Scholarship Data Bank</PageTitle>
      <Alert>{error}</Alert>

      <form className="flex gap-2 mb-4" onSubmit={(e) => { e.preventDefault(); search(q); }}>
        <input className={inputClass} placeholder="Search by name, student ID or course" value={q} onChange={(e) => setQ(e.target.value)} />
        <Button type="submit">Search</Button>
      </form>

      <Card className="mb-6 overflow-x-auto">
        <table className="w-full text-sm">
          <thead><tr className="text-left text-gray-500 border-b"><th className="py-1">Name</th><th>Student ID</th><th>Course</th><th>Applications</th><th>Active scholarship</th><th></th></tr></thead>
          <tbody>
            {students.map((s) => (
              <tr key={s.id} className="border-b">
                <td className="py-1">{s.first_name} {s.last_name}</td>
                <td>{s.student_id}</td>
                <td>{s.course}</td>
                <td>{s.applications?.length || 0}</td>
                <td>{s.has_active_scholarship ? <span className="text-green-700 font-medium">Yes</span> : 'No'}</td>
                <td><Button variant="secondary" onClick={() => open(s.id)}>View history</Button></td>
              </tr>
            ))}
            {students.length === 0 && <tr><td colSpan="6" className="py-3 text-gray-500">No students found.</td></tr>}
          </tbody>
        </table>
      </Card>

      {detail && (
        <Card>
          <h2 className="text-lg font-semibold">{detail.first_name} {detail.last_name}</h2>
          <p className="text-sm text-gray-500 mb-3">{detail.student_id} · {detail.course} · {detail.year_level} · {detail.user?.email}</p>
          {detail.has_active_scholarship && <p className="text-sm bg-green-50 border border-green-200 rounded p-2 mb-3">This student currently has an ACTIVE scholarship, so cannot get another one.</p>}

          <h3 className="font-medium mb-1">Applications</h3>
          <table className="w-full text-sm mb-4">
            <thead><tr className="text-left text-gray-500 border-b"><th className="py-1">Scholarship</th><th>Status</th><th>Documents</th><th>Submitted</th></tr></thead>
            <tbody>
              {(detail.applications || []).map((a) => (
                <tr key={a.id} className="border-b">
                  <td className="py-1">{a.scholarship?.name}</td>
                  <td><Badge info={APP_STATUS[a.status]} fallback={a.status} /></td>
                  <td>{a.documents?.length || 0}</td>
                  <td>{a.submitted_at ? new Date(a.submitted_at).toLocaleDateString() : '-'}</td>
                </tr>
              ))}
              {(detail.applications || []).length === 0 && <tr><td colSpan="4" className="py-2 text-gray-500">No applications.</td></tr>}
            </tbody>
          </table>

          <h3 className="font-medium mb-1">Grantee / scholarship records</h3>
          <table className="w-full text-sm">
            <thead><tr className="text-left text-gray-500 border-b"><th className="py-1">Scholarship</th><th>Status</th><th>Enrolled</th><th>ATM</th><th>Tagged</th><th>Payroll rows</th></tr></thead>
            <tbody>
              {(detail.scholar_records || []).map((r) => (
                <tr key={r.id} className="border-b">
                  <td className="py-1">{r.scholarship?.name}</td>
                  <td>{r.status}</td>
                  <td>{r.currently_enrolled ? 'Yes' : 'No'}</td>
                  <td>{r.has_atm ? 'Yes' : 'No'}</td>
                  <td>{r.grantee_tagged_at ? new Date(r.grantee_tagged_at).toLocaleDateString() : '-'}</td>
                  <td>{r.payroll_records?.length || 0}</td>
                </tr>
              ))}
              {(detail.scholar_records || []).length === 0 && <tr><td colSpan="6" className="py-2 text-gray-500">No grantee records.</td></tr>}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}
