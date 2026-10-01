import { useCallback, useEffect, useState } from 'react';
import api, { errMsg, FILES_URL } from '../../api';
import { APP_STATUS, DOC_STATUS, REVIEW_STATUSES } from '../../constants';
import { Alert, Badge, Button, Card, PageTitle, inputClass } from '../../ui';

export default function ApplicationsReviewPage() {
  const [apps, setApps] = useState([]);
  const [filter, setFilter] = useState('');
  const [selectedId, setSelectedId] = useState(null);
  const [reviewForm, setReviewForm] = useState({ status: 'under_review', remarks: '' });
  const [msg, setMsg] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try { setApps((await api.get('/applications', { params: filter ? { status: filter } : {} })).data); }
    catch (err) { setError(errMsg(err)); }
  }, [filter]);
  useEffect(() => { load(); }, [load]);

  const selected = apps.find((a) => a.id === selectedId);

  const open = (a) => {
    setSelectedId(a.id);
    setReviewForm({ status: 'under_review', remarks: a.remarks || '' });
    setMsg(''); setError('');
  };

  const run = async (fn, okMsg) => {
    setMsg(''); setError('');
    try { await fn(); if (okMsg) setMsg(okMsg); await load(); } catch (err) { setError(errMsg(err)); }
  };

  const saveReview = () => run(() => api.put(`/applications/${selected.id}/review`, reviewForm), 'Review saved.');
  const reviewDoc = (docId, status) => run(() => api.patch(`/documents/${docId}/review`, { status }), 'Your decision was saved.');
  const revalidate = (docId) => run(() => api.post(`/documents/${docId}/revalidate`), 'AI check re-run.');
  const createScholar = () => run(() => api.post(`/applications/${selected.id}/create-scholar`), 'Scholar record created. Now verify enrollment.');

  return (
    <div className="p-6 max-w-6xl mx-auto">
      <PageTitle sub="Check documents, see AI flags, record the review outcome">Applications Review</PageTitle>
      <Alert>{error}</Alert>
      <Alert type="success">{msg}</Alert>

      <div className="mb-3 flex gap-2 items-center">
        <span className="text-sm">Filter:</span>
        <select className="border rounded px-2 py-1 text-sm" value={filter} onChange={(e) => setFilter(e.target.value)}>
          <option value="">All statuses</option>
          {Object.keys(APP_STATUS).map((s) => <option key={s} value={s}>{APP_STATUS[s].label}</option>)}
        </select>
      </div>

      <Card className="mb-6 overflow-x-auto">
        <table className="w-full text-sm">
          <thead><tr className="text-left text-gray-500 border-b"><th className="py-1">Student</th><th>ID</th><th>Scholarship</th><th>Status</th><th>Missing docs</th><th>AI flags</th><th></th></tr></thead>
          <tbody>
            {apps.map((a) => {
              const flags = (a.documents || []).filter((d) => d.status === 'flagged' || d.status === 'needs_review').length;
              return (
                <tr key={a.id} className={`border-b ${a.id === selectedId ? 'bg-green-50' : ''}`}>
                  <td className="py-1">{a.student?.first_name} {a.student?.last_name}</td>
                  <td>{a.student?.student_id}</td>
                  <td>{a.scholarship?.name}</td>
                  <td><Badge info={APP_STATUS[a.status]} fallback={a.status} /></td>
                  <td>{a.missing_requirements?.length || 0}</td>
                  <td>{flags}</td>
                  <td><Button variant="secondary" onClick={() => open(a)}>Review</Button></td>
                </tr>
              );
            })}
            {apps.length === 0 && <tr><td colSpan="7" className="py-3 text-gray-500">No applications found.</td></tr>}
          </tbody>
        </table>
      </Card>

      {selected && (
        <Card>
          <h2 className="text-lg font-semibold">{selected.student?.first_name} {selected.student?.last_name} — {selected.scholarship?.name}</h2>
          <p className="text-sm text-gray-500 mb-3">
            Student ID: {selected.student?.student_id} · {selected.student?.course} · {selected.student?.year_level}
          </p>

          {selected.status === 'draft' && (
            <p className="text-sm bg-gray-50 border rounded p-2 mb-3">This application is still a draft — the student hasn't submitted it yet.</p>
          )}
          {selected.missing_requirements?.length > 0 && (
            <p className="text-sm bg-orange-50 border border-orange-200 rounded p-2 mb-3">
              <b>Missing required documents:</b> {selected.missing_requirements.join(', ')}
            </p>
          )}

          <h3 className="font-medium mb-1">Documents</h3>
          {(selected.documents || []).length === 0 && <p className="text-sm text-gray-500">Nothing uploaded yet.</p>}
          {(selected.documents || []).map((d) => {
            const v = d.validation_result;
            return (
              <div key={d.id} className="border rounded p-3 mb-2">
                <div className="flex justify-between flex-wrap gap-2">
                  <div>
                    <a href={`${FILES_URL}/${d.file_path}`} target="_blank" rel="noreferrer" className="text-green-700 underline text-sm">{d.original_filename}</a>
                    <span className="text-xs text-gray-500"> · {d.document_type || 'document'}</span>
                  </div>
                  <Badge info={DOC_STATUS[d.status]} fallback={d.status} />
                </div>
                {v && (
                  <div className="text-xs mt-2 text-gray-700">
                    {v.flags ? <p className="text-red-700"><b>AI flags:</b> {v.flags}</p> : <p className="text-green-700">AI found no issues.</p>}
                    <p>Name match score: {v.confidence_score ?? '-'} · Name mismatch: {v.has_name_mismatch ? 'yes' : 'no'} ·
                      Missing info: {v.has_missing_information ? 'yes' : 'no'} · Wrong document: {v.has_wrong_document ? 'yes' : 'no'}</p>
                  </div>
                )}
                <div className="flex gap-2 mt-2 flex-wrap">
                  <Button variant="secondary" onClick={() => revalidate(d.id)}>Re-run AI check</Button>
                  <Button onClick={() => reviewDoc(d.id, 'validated')}>I checked: OK</Button>
                  <Button variant="danger" onClick={() => reviewDoc(d.id, 'flagged')}>I checked: Problem</Button>
                </div>
              </div>
            );
          })}
          <p className="text-xs text-gray-500 mb-4">The AI only suggests. You make the final decision on every document.</p>

          {['submitted', 'under_review', 'needs_action', 'complete'].includes(selected.status) && (
            <>
              <h3 className="font-medium mb-1">Application review</h3>
              <p className="text-xs text-gray-500 mb-2">
                Final scholarship approval is done by the external agency (e.g. CHED). Record the result here when it returns to OAS.
              </p>
              <div className="grid md:grid-cols-3 gap-2 mb-4">
                <select className={inputClass} value={reviewForm.status} onChange={(e) => setReviewForm({ ...reviewForm, status: e.target.value })}>
                  {REVIEW_STATUSES.map((s) => <option key={s} value={s}>{APP_STATUS[s].label}</option>)}
                </select>
                <input className={`${inputClass} md:col-span-2`} placeholder="Remarks / note to the student (needed for 'Needs action')"
                  value={reviewForm.remarks} onChange={(e) => setReviewForm({ ...reviewForm, remarks: e.target.value })} />
                <div><Button onClick={saveReview}>Save review</Button></div>
              </div>
            </>
          )}

          {selected.status === 'approved' && (
            <div className="border-t pt-3">
              <h3 className="font-medium mb-1">Create scholar record</h3>
              <p className="text-xs text-gray-500 mb-2">
                Next step after this: go to the "Scholars" tab to verify enrollment and ATM status before payroll.
              </p>
              <Button onClick={createScholar}>Create scholar record</Button>
            </div>
          )}
        </Card>
      )}
    </div>
  );
}
