import { Navigate, Route, Routes } from "react-router-dom";
import AuthProvider from "./components/AuthProvider";
import { homePathFor, useAuth } from "./lib/auth";
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
import AutoReviewPage from "./pages/staff/AutoReviewPage";
import ForwardedPage from "./pages/staff/ForwardedPage";
import EnrollmentPage from "./pages/staff/EnrollmentPage";
import MessagesPage from "./pages/staff/MessagesPage";
import ContactPage from "./pages/student/ContactPage";
import ChangePasswordPage from "./pages/ChangePasswordPage";
import AnnouncementDetailPage from "./pages/AnnouncementDetailPage";

import AdminDashboard from "./pages/admin/AdminDashboard";
import ManageAccountsPage from "./pages/admin/ManageAccountsPage";
import ActivityLogPage from "./pages/admin/ActivityLogPage";
import SettingsPage from "./pages/admin/SettingsPage";

/*
  Every page has its own address, so the browser Back button and
  page refresh work:

    /login                    /account/password (change password, any role)
    /student/dashboard        /staff/dashboard          /admin/dashboard
    /student/scholarships     /staff/applications       /admin/staff
    /student/scholarships/:id /staff/auto-review        /admin/students
    /student/applications     /staff/agency-lists       /admin/activity
    /student/applications/:id /staff/forwarded          /admin/settings
    /student/documents        /staff/scholars           /admin/scholarships
    /student/history          /staff/enrollment         /admin/reports
    /student/profile          /staff/payroll            /admin/help
    /student/contact          /staff/scholarships       /admin/profile
    /student/help             /staff/announcements
                              /staff/messages
                              /staff/data-bank
                              /staff/reports
                              /staff/help
                              /staff/profile
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
                <Route path="/student/contact" element={<ContactPage />} />
                <Route path="/student/announcements/:id" element={<AnnouncementDetailPage />} />
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
                <Route path="/staff/auto-review" element={<AutoReviewPage />} />
                <Route path="/staff/forwarded" element={<ForwardedPage />} />
                <Route path="/staff/enrollment" element={<EnrollmentPage />} />
                <Route path="/staff/messages" element={<MessagesPage />} />
                <Route path="/staff/announcements/:id" element={<AnnouncementDetailPage />} />
              </Route>
            </Route>

            <Route element={<ProtectedRoute role="admin" />}>
              <Route element={<Layout />}>
                <Route path="/admin/dashboard" element={<AdminDashboard />} />
                <Route path="/admin/staff" element={<ManageAccountsPage kind="office" />} />
                <Route path="/admin/students" element={<ManageAccountsPage kind="student" />} />
                <Route path="/admin/activity" element={<ActivityLogPage />} />
                <Route path="/admin/settings" element={<SettingsPage />} />
                <Route path="/admin/scholarships" element={<StaffScholarshipsPage />} />
                <Route path="/admin/reports" element={<StaffReportsPage />} />
                <Route path="/admin/help" element={<HelpPage />} />
                <Route path="/admin/profile" element={<StaffProfilePage />} />
              </Route>
            </Route>

            {/* Any role: forced after a temporary password, and from My Profile. */}
            <Route element={<ProtectedRoute />}>
              <Route path="/account/password" element={<ChangePasswordPage />} />
            </Route>

            {/* "/", "/student", "/staff" or an unknown address -> your dashboard (or login) */}
            <Route path="*" element={<HomeRedirect />} />
          </Routes>
        </ConfirmProvider>
      </ToastProvider>
    </AuthProvider>
  );
}
