import { Navigate, Route, Routes } from "react-router-dom";
import { AuthProvider, homePathFor, useAuth } from "./lib/auth";
import { ToastProvider } from "./components/Toast";
import { ConfirmProvider } from "./components/Modal";
import Layout from "./components/Layout";
import ProtectedRoute from "./components/ProtectedRoute";

import LoginPage from "./pages/LoginPage";

import StudentDashboard from "./pages/student/StudentDashboard";
import ScholarshipsPage from "./pages/student/ScholarshipsPage";
import ScholarshipDetailPage from "./pages/student/ScholarshipDetailPage";
import MyApplicationsPage from "./pages/student/MyApplicationsPage";
import ApplicationDetailPage from "./pages/student/ApplicationDetailPage";
import StudentProfilePage from "./pages/student/StudentProfilePage";
import StudentHistoryPage from "./pages/student/StudentHistoryPage";
import MyDocumentsPage from "./pages/student/MyDocumentsPage";

import StaffDashboard from "./pages/staff/StaffDashboard";
import StaffApplicationsPage from "./pages/staff/StaffApplicationsPage";
import StaffScholarRecordsPage from "./pages/staff/StaffScholarRecordsPage";
import StaffPayrollPage from "./pages/staff/StaffPayrollPage";
import DataBankPage from "./pages/staff/DataBankPage";
import StaffScholarshipsPage from "./pages/staff/StaffScholarshipsPage";
import StaffReportsPage from "./pages/staff/StaffReportsPage";
import StaffAnnouncementsPage from "./pages/staff/StaffAnnouncementsPage";
import AgencyListsPage from "./pages/staff/AgencyListsPage";
import HelpPage from "./pages/HelpPage";
import StaffProfilePage from "./pages/staff/StaffProfilePage";

/*
  Every page has its own address, so the browser Back button and
  page refresh work:

    /login
    /student/dashboard        /staff/dashboard
    /student/scholarships     /staff/applications
    /student/scholarships/:id /staff/scholars
    /student/applications     /staff/payroll
    /student/applications/:id /staff/data-bank
    /student/profile          /staff/scholarships
    /student/history          /staff/reports
    /student/help             /staff/agency-lists
    /student/documents        /staff/profile
                              /staff/announcements
                              /staff/help
*/

function HomeRedirect() {
  const { user } = useAuth();
  return <Navigate to={homePathFor(user)} replace />;
}

export default function App() {
  return (
    <AuthProvider>
      <ToastProvider>
        <ConfirmProvider>
          <Routes>
            <Route path="/login" element={<LoginPage />} />

            <Route element={<ProtectedRoute role="student" />}>
              <Route element={<Layout />}>
                <Route path="/student/dashboard" element={<StudentDashboard />} />
                <Route path="/student/scholarships" element={<ScholarshipsPage />} />
                <Route path="/student/scholarships/:id" element={<ScholarshipDetailPage />} />
                <Route path="/student/applications" element={<MyApplicationsPage />} />
                <Route path="/student/applications/:id" element={<ApplicationDetailPage />} />
                <Route path="/student/profile" element={<StudentProfilePage />} />
                <Route path="/student/history" element={<StudentHistoryPage />} />
                <Route path="/student/help" element={<HelpPage />} />
                <Route path="/student/documents" element={<MyDocumentsPage />} />
              </Route>
            </Route>

            <Route element={<ProtectedRoute role="staff" />}>
              <Route element={<Layout />}>
                <Route path="/staff/dashboard" element={<StaffDashboard />} />
                <Route path="/staff/applications" element={<StaffApplicationsPage />} />
                <Route path="/staff/scholars" element={<StaffScholarRecordsPage />} />
                <Route path="/staff/payroll" element={<StaffPayrollPage />} />
                <Route path="/staff/data-bank" element={<DataBankPage />} />
                <Route path="/staff/scholarships" element={<StaffScholarshipsPage />} />
                <Route path="/staff/reports" element={<StaffReportsPage />} />
                <Route path="/staff/agency-lists" element={<AgencyListsPage />} />
                <Route path="/staff/announcements" element={<StaffAnnouncementsPage />} />
                <Route path="/staff/help" element={<HelpPage />} />
                <Route path="/staff/profile" element={<StaffProfilePage />} />
              </Route>
            </Route>

            {/* "/", "/student", "/staff" or an unknown address -> your dashboard (or login) */}
            <Route path="*" element={<HomeRedirect />} />
          </Routes>
        </ConfirmProvider>
      </ToastProvider>
    </AuthProvider>
  );
}
