import { lazy, Suspense } from "react";
import { Navigate, Route, Routes } from "react-router-dom";
import Loading from "./components/Loading";
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

const StaffDashboard = lazy(() => import("./pages/staff/StaffDashboard"));
const StaffApplicationsPage = lazy(() => import("./pages/staff/StaffApplicationsPage"));
const StaffScholarRecordsPage = lazy(() => import("./pages/staff/StaffScholarRecordsPage"));
const StaffPayrollPage = lazy(() => import("./pages/staff/StaffPayrollPage"));
const DataBankPage = lazy(() => import("./pages/staff/DataBankPage"));
const StaffScholarshipsPage = lazy(() => import("./pages/staff/StaffScholarshipsPage"));
const StaffReportsPage = lazy(() => import("./pages/staff/StaffReportsPage"));
const StaffAnnouncementsPage = lazy(() => import("./pages/staff/StaffAnnouncementsPage"));
const AgencyListsPage = lazy(() => import("./pages/staff/AgencyListsPage"));
const ProcessingBatchesPage = lazy(() => import("./pages/staff/ProcessingBatchesPage"));
import HelpPage from "./pages/HelpPage";
const StaffProfilePage = lazy(() => import("./pages/staff/StaffProfilePage"));
const AutoReviewPage = lazy(() => import("./pages/staff/AutoReviewPage"));
const ForwardedPage = lazy(() => import("./pages/staff/ForwardedPage"));
const RecommendationsPage = lazy(() => import("./pages/staff/RecommendationsPage"));
const ExternalGranteesPage = lazy(() => import("./pages/staff/ExternalGranteesPage"));
const GeneralPayrollPage = lazy(() => import("./pages/staff/GeneralPayrollPage"));
const EnrollmentPage = lazy(() => import("./pages/staff/EnrollmentPage"));
const MessagesPage = lazy(() => import("./pages/staff/MessagesPage"));
import ContactPage from "./pages/student/ContactPage";
import ChangePasswordPage from "./pages/ChangePasswordPage";
import AnnouncementDetailPage from "./pages/AnnouncementDetailPage";

const AdminDashboard = lazy(() => import("./pages/admin/AdminDashboard"));
const ManageAccountsPage = lazy(() => import("./pages/admin/ManageAccountsPage"));
const ActivityLogPage = lazy(() => import("./pages/admin/ActivityLogPage"));
const SettingsPage = lazy(() => import("./pages/admin/SettingsPage"));

function HomeRedirect() {
  const { user } = useAuth();
  return <Navigate to={homePathFor(user)} replace />;
}

export default function App() {
  return (
    <AuthProvider>
      <ToastProvider>
        <ConfirmProvider>
          <Suspense fallback={<Loading />}>
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
                <Route path="/staff/batches" element={<ProcessingBatchesPage />} />
                <Route path="/staff/announcements" element={<StaffAnnouncementsPage />} />
                <Route path="/staff/help" element={<HelpPage />} />
                <Route path="/staff/profile" element={<StaffProfilePage />} />
                <Route path="/staff/auto-review" element={<AutoReviewPage />} />
                <Route path="/staff/forwarded" element={<ForwardedPage />} />
                <Route path="/staff/recommendations" element={<RecommendationsPage />} />
                <Route path="/staff/external-grantees" element={<ExternalGranteesPage />} />
                <Route path="/staff/enrollment" element={<EnrollmentPage />} />
                <Route path="/staff/messages" element={<MessagesPage />} />
                <Route path="/staff/announcements/:id" element={<AnnouncementDetailPage />} />
              </Route>
              <Route path="/staff/payroll/form" element={<GeneralPayrollPage />} />
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

            <Route element={<ProtectedRoute />}>
              <Route path="/account/password" element={<ChangePasswordPage />} />
            </Route>

            <Route path="*" element={<HomeRedirect />} />
          </Routes>
          </Suspense>
        </ConfirmProvider>
      </ToastProvider>
    </AuthProvider>
  );
}
