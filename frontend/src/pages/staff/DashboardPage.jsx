import { useEffect, useState } from 'react';
import api, { errMsg } from '../../api';
import { APP_STATUS } from '../../constants';
import { Alert, Badge, Card, PageTitle } from '../../ui';

function Stat({ label, value }) {
  return (
    <Card>
      <p className="text-3xl font-bold text-green-800">{value ?? '-'}</p>
      <p className="text-sm text-gray-600">{label}</p>
    </Card>
  );
}

export default function DashboardPage() {
  const [d, setD] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get('/dashboard').then((r) => setD(r.data)).catch((e) => setError(errMsg(e)));
  }, []);

  return (
    <div className="p-6 max-w-6xl mx-auto">
      <PageTitle sub="Overview of scholarship applications and grantees">OAS Dashboard</PageTitle>
      <Alert>{error}</Alert>
      {d && (
        <>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Stat label="Total applicants" value={d.total_applicants} />
            <Stat label="Applications" value={d.total_applications} />
            <Stat label="With missing documents" value={d.applications_with_missing_docs} />
            <Stat label="AI-flagged documents" value={d.ai_flagged_documents} />
            <Stat label="Students needing review" value={d.students_needing_review} />
            <Stat label="Approved (by agency)" value={d.approved_applications} />
            <Stat label="Active grantees" value={d.active_grantees} />
            <Stat label="Currently enrolled grantees" value={d.currently_enrolled_grantees} />
            <Stat label="Students with an active scholarship" value={d.students_with_active_scholarship} />
            <Stat label="Payroll-ready" value={d.payroll_ready} />
            <Stat label="Scholarship programs" value={d.scholarship_programs} />
            <Stat label="Documents needing review" value={d.documents_needing_review} />
          </div>

          <h2 className="font-semibold mt-6 mb-2">Applications by status</h2>
          <div className="flex flex-wrap gap-2">
            {Object.entries(d.applications_by_status || {}).map(([k, v]) => (
              <span key={k} className="flex items-center gap-1 text-sm"><Badge info={APP_STATUS[k]} fallback={k} /> {v}</span>
            ))}
            {Object.keys(d.applications_by_status || {}).length === 0 && <span className="text-gray-500 text-sm">No applications yet.</span>}
          </div>

          <h2 className="font-semibold mt-6 mb-2">Recent applications</h2>
          <Card>
            <table className="w-full text-sm">
              <thead><tr className="text-left text-gray-500 border-b"><th className="py-1">Student</th><th>Scholarship</th><th>Status</th></tr></thead>
              <tbody>
                {(d.recent_applications || []).map((a) => (
                  <tr key={a.id} className="border-b">
                    <td className="py-1">{a.student?.first_name} {a.student?.last_name}</td>
                    <td>{a.scholarship?.name}</td>
                    <td><Badge info={APP_STATUS[a.status]} fallback={a.status} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
        </>
      )}
    </div>
  );
}
