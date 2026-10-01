import { useEffect, useState } from 'react';
import api, { errMsg } from '../../api';
import { Alert, Card, PageTitle } from '../../ui';

export default function MyHistoryPage() {
  const [records, setRecords] = useState([]);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get('/my-history').then((r) => setRecords(r.data)).catch((e) => setError(errMsg(e)));
  }, []);

  return (
    <div className="p-6 max-w-4xl mx-auto">
      <PageTitle sub="Scholarships you were tagged as a grantee of">My Scholarship History</PageTitle>
      <Alert>{error}</Alert>
      {records.length === 0 && <p className="text-gray-500">No scholarship records yet.</p>}
      <div className="grid gap-3">
        {records.map((r) => (
          <Card key={r.id}>
            <h2 className="font-semibold">{r.scholarship?.name}</h2>
            <p className="text-sm">Status: <b>{r.status}</b> · Enrolled: {r.currently_enrolled ? 'Yes' : 'Not yet verified'}</p>
            <p className="text-xs text-gray-500">Tagged: {r.grantee_tagged_at ? new Date(r.grantee_tagged_at).toLocaleDateString() : '-'}</p>
          </Card>
        ))}
      </div>
    </div>
  );
}
