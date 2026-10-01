import { useState } from 'react';
import api from './api';
import AuthPage from './pages/AuthPage';
import ScholarshipsPage from './pages/student/ScholarshipsPage';
import MyApplicationsPage from './pages/student/MyApplicationsPage';
import MyHistoryPage from './pages/student/MyHistoryPage';
import DashboardPage from './pages/staff/DashboardPage';
import ScholarshipsManagePage from './pages/staff/ScholarshipsManagePage';
import ApplicationsReviewPage from './pages/staff/ApplicationsReviewPage';
import ScholarsPage from './pages/staff/ScholarsPage';
import DataBankPage from './pages/staff/DataBankPage';
import PayrollPage from './pages/staff/PayrollPage';

const MENUS = {
  student: [
    { key: 'scholarships', label: 'Scholarships', Page: ScholarshipsPage },
    { key: 'applications', label: 'My Applications', Page: MyApplicationsPage },
    { key: 'history', label: 'My History', Page: MyHistoryPage },
  ],
  staff: [
    { key: 'dashboard', label: 'Dashboard', Page: DashboardPage },
    { key: 'review', label: 'Applications', Page: ApplicationsReviewPage },
    { key: 'scholars', label: 'Scholars', Page: ScholarsPage },
    { key: 'scholarships', label: 'Scholarships', Page: ScholarshipsManagePage },
    { key: 'databank', label: 'Data Bank', Page: DataBankPage },
    { key: 'payroll', label: 'Payroll', Page: PayrollPage },
  ],
};

export default function App() {
  const [user, setUser] = useState(() => {
    try { return JSON.parse(localStorage.getItem('user')) || null; } catch { return null; }
  });
  const [page, setPage] = useState(null);

  const logout = async () => {
    try { await api.post('/logout'); } catch { /* token may already be invalid */ }
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    setUser(null);
    setPage(null);
  };

  if (!user) return <AuthPage onLogin={(u) => { setUser(u); setPage(null); }} />;

  const menu = MENUS[user.role] || MENUS.student;
  const current = menu.find((m) => m.key === page) || menu[0];
  const CurrentPage = current.Page;

  return (
    <div className="min-h-screen bg-gray-50">
      <nav className="bg-green-800 text-white px-4 py-3 flex flex-wrap justify-between items-center gap-2 print:hidden">
        <div className="font-bold">CSU UniScholar <span className="text-xs font-normal opacity-80">({user.role === 'staff' ? 'OAS Staff' : 'Student'})</span></div>
        <div className="flex flex-wrap gap-4 items-center text-sm">
          {menu.map((m) => (
            <button key={m.key} onClick={() => setPage(m.key)}
              className={current.key === m.key ? 'underline font-semibold' : 'opacity-90 hover:underline'}>{m.label}</button>
          ))}
          <span className="opacity-70">{user.name}</span>
          <button onClick={logout} className="bg-white/20 px-3 py-1 rounded hover:bg-white/30">Logout</button>
        </div>
      </nav>
      <CurrentPage />
    </div>
  );
}
