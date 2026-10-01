import {
    BrowserRouter,
    Navigate,
    Route,
    Routes,
} from "react-router-dom";

import { AuthProvider, useAuth } from "./context/AuthContext";

import ProtectedRoute from "./components/ProtectedRoute";
import Layout from "./components/Layout";

import Login from "./pages/Login";
import StudentDashboard from "./pages/StudentDashboard";
import Scholarships from "./pages/Scholarships";
import ScholarshipDetails from "./pages/ScholarshipDetails";
import MyApplications from "./pages/MyApplications";

import StaffDashboard from "./pages/StaffDashboard";
import StaffApplications from "./pages/StaffApplications";
import StaffScholarships from "./pages/StaffScholarships";

function HomeRedirect() {
    const { user, loading } = useAuth();

    if (loading) {
        return (
            <div className="full-page-loader">
                <div className="spinner"></div>
                <p>
                    Loading CSU UniScholar...
                </p>
            </div>
        );
    }

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    if (user.role === "staff") {
        return <Navigate to="/staff" replace />;
    }

    return <Navigate to="/student" replace />;
}

function AppRoutes() {
    return (
        <Routes>
            <Route
                path="/"
                element={<HomeRedirect />}
            />

            <Route
                path="/login"
                element={<Login />}
            />

            <Route
                path="/student"
                element={
                    <ProtectedRoute allowedRole="student">
                        <Layout />
                    </ProtectedRoute>
                }
            >
                <Route
                    index
                    element={<StudentDashboard />}
                />

                <Route
                    path="scholarships"
                    element={<Scholarships />}
                />

                <Route
                    path="scholarships/:id"
                    element={
                        <ScholarshipDetails />
                    }
                />

                <Route
                    path="applications"
                    element={<MyApplications />}
                />
            </Route>

            <Route
                path="/staff"
                element={
                    <ProtectedRoute allowedRole="staff">
                        <Layout />
                    </ProtectedRoute>
                }
            >
                <Route
                    index
                    element={<StaffDashboard />}
                />

                <Route
                    path="applications"
                    element={
                        <StaffApplications />
                    }
                />

                <Route
                    path="scholarships"
                    element={
                        <StaffScholarships />
                    }
                />
            </Route>

            <Route
                path="*"
                element={
                    <Navigate
                        to="/"
                        replace
                    />
                }
            />
        </Routes>
    );
}

export default function App() {
    return (
        <BrowserRouter>
            <AuthProvider>
                <AppRoutes />
            </AuthProvider>
        </BrowserRouter>
    );
}