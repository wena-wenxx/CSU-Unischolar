import { useCallback, useEffect, useState } from 'react';
import api, { errMsg } from '../../api';
import { Alert, Button, Card, PageTitle } from '../../ui';

export default function ScholarshipsPage() {
  const [scholarships, setScholarships] = useState([]);
  const [startedIds, setStartedIds] = useState([]);
  const [msg, setMsg] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try {
      const [s, a] = await Promise.all([api.get('/scholarships'), api.get('/my-applications')]);
      setScholarships(s.data);
      setStartedIds(a.data.map((x) => x.scholarship_id));
    } catch (err) { setError(errMsg(err)); }
  }, []);

  useEffect(() => { load(); }, [load]);

  const start = async (id) => {
    setMsg(''); setError('');
    try {
      await api.post('/applications', { scholarship_id: id });
      setMsg('Application started! Go to "My Applications" to upload documents and submit.');
      load();
    } catch (err) { setError(errMsg(err)); }
  };

  return (
    <div className="p-6 max-w-4xl mx-auto">
      <PageTitle sub="Browse the scholarships you can apply for">Available Scholarships</PageTitle>
      <Alert>{error}</Alert>
      <Alert type="success">{msg}</Alert>
      {scholarships.length === 0 && <p className="text-gray-500">No open scholarships right now.</p>}
      <div className="grid gap-4">
        {scholarships.map((s) => (
          <Card key={s.id}>
            <div className="flex justify-between items-start gap-4">
              <div>
                <h2 className="text-lg font-semibold">{s.name}</h2>
                <p className="text-sm text-gray-500">{s.provider || 'Provider not specified'}</p>
                {s.description && <p className="text-sm mt-2">{s.description}</p>}
                <p className="text-sm font-medium mt-3">Requirements:</p>
                <ul className="list-disc ml-5 text-sm">
                  {(s.requirements || []).map((r) => (
                    <li key={r.id}>{r.name}{!r.is_required && ' (optional)'}</li>
                  ))}
                  {(!s.requirements || s.requirements.length === 0) && <li className="text-gray-400">None listed yet</li>}
                </ul>
              </div>
              {startedIds.includes(s.id)
                ? <span className="text-sm text-green-700 font-medium">Started ✓</span>
                : <Button onClick={() => start(s.id)}>Start Application</Button>}
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}
