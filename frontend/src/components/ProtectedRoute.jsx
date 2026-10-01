import { Navigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";

export default function ProtectedRoute({
    children,
    allowedRole,
}) {
    const { user, loading } = useAuth();

    if (loading) {
        return (
            <div className="full-page-loader">
                <div className="spinner"></div>
                <p>Loading CSU UniScholar...</p>
            </div>
        );
    }

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    if (allowedRole && user.role !== allowedRole) {
        if (user.role === "student") {
            return <Navigate to="/student" replace />;
        }

        if (user.role === "staff") {
            return <Navigate to="/staff" replace />;
        }

        return <Navigate to="/login" replace />;
    }

    return children;
}