import { useCallback, useEffect, useState } from 'react';
import api, { errMsg } from '../../api';
import { APP_STATUS, DOC_STATUS } from '../../constants';
import { Alert, Badge, Button, Card, PageTitle } from '../../ui';

const LOCKED = ['approved', 'rejected'];

export default function MyApplicationsPage() {
  const [apps, setApps] = useState([]);
  const [busyKey, setBusyKey] = useState('');
  const [msg, setMsg] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try { setApps((await api.get('/my-applications')).data); }
    catch (err) { setError(errMsg(err)); }
  }, []);

  useEffect(() => { load(); }, [load]);

  const upload = async (app, req, file) => {
    if (!file) return;
    setMsg(''); setError('');
    setBusyKey(`${app.id}-${req.id}`);
    try {
      const form = new FormData();
      form.append('file', file);
      form.append('scholarship_requirement_id', req.id);
      form.append('document_type', req.name);
      await api.post(`/applications/${app.id}/documents`, form);
      setMsg(`"${req.name}" uploaded and checked.`);
      await load();
    } catch (err) { setError(errMsg(err)); }
    finally { setBusyKey(''); }
  };

  const submit = async (app) => {
    setMsg(''); setError('');
    try {
      await api.post(`/applications/${app.id}/submit`);
      setMsg('Application submitted!');
      load();
    } catch (err) { setError(errMsg(err)); }
  };

  const latestDoc = (app, reqId) => (app.documents || []).filter((d) => d.scholarship_requirement_id === reqId).slice(-1)[0];

  return (
    <div className="p-6 max-w-4xl mx-auto">
      <PageTitle sub="Upload required documents, then submit your application">My Applications</PageTitle>
      <Alert>{error}</Alert>
      <Alert type="success">{msg}</Alert>
      {apps.length === 0 && <p className="text-gray-500">You have not started any application yet.</p>}

      <div className="grid gap-4">
        {apps.map((app) => (
          <Card key={app.id}>
            <div className="flex justify-between items-start">
              <div>
                <h2 className="text-lg font-semibold">{app.scholarship?.name}</h2>
                <p className="text-xs text-gray-500">
                  {app.submitted_at ? `Submitted: ${new Date(app.submitted_at).toLocaleString()}` : 'Not submitted yet'}
                </p>
              </div>
              <Badge info={APP_STATUS[app.status]} fallback={app.status} />
            </div>

            {app.remarks && (
              <p className="text-sm mt-2 bg-yellow-50 border border-yellow-200 rounded p-2">
                <b>Note from OAS:</b> {app.remarks}
              </p>
            )}

            <table className="w-full text-sm mt-3">
              <thead>
                <tr className="text-left text-gray-500 border-b"><th className="py-1">Requirement</th><th>Status</th><th>Upload</th></tr>
              </thead>
              <tbody>
                {(app.scholarship?.requirements || []).map((req) => {
                  const doc = latestDoc(app, req.id);
                  const key = `${app.id}-${req.id}`;
                  return (
                    <tr key={req.id} className="border-b align-top">
                      <td className="py-2">{req.name}{!req.is_required && ' (optional)'}</td>
                      <td>
                        {doc ? <Badge info={DOC_STATUS[doc.status]} fallback={doc.status} /> : <span className="text-red-600 text-xs">Not uploaded</span>}
                        {doc?.validation_result?.flags && doc.status !== 'validated' && (
                          <p className="text-xs text-gray-600 mt-1">{doc.validation_result.flags}</p>
                        )}
                      </td>
                      <td>
                        {LOCKED.includes(app.status) ? <span className="text-xs text-gray-400">Locked</span>
                          : busyKey === key ? <span className="text-xs text-blue-700">Uploading and checking...</span>
                          : <input type="file" accept=".pdf,.jpg,.jpeg,.png" className="text-xs"
                              onChange={(e) => { upload(app, req, e.target.files[0]); e.target.value = ''; }} />}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>

            {app.status === 'draft' && (
              <div className="mt-3">
                {app.missing_requirements?.length > 0 ? (
                  <p className="text-xs text-orange-700">
                    Still missing: {app.missing_requirements.join(', ')} — upload these before submitting.
                  </p>
                ) : (
                  <Button onClick={() => submit(app)}>Submit Application</Button>
                )}
              </div>
            )}
          </Card>
        ))}
      </div>
    </div>
  );
}
