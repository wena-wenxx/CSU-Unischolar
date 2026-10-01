import { NavLink, Outlet, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";

export default function Layout() {
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    const handleLogout = async () => {
        await logout();
        navigate("/login");
    };

    const studentLinks = [
        {
            to: "/student",
            label: "Dashboard",
            icon: "⌂",
        },
        {
            to: "/student/scholarships",
            label: "Scholarships",
            icon: "🎓",
        },
        {
            to: "/student/applications",
            label: "My Applications",
            icon: "📄",
        },
    ];

    const staffLinks = [
        {
            to: "/staff",
            label: "Dashboard",
            icon: "⌂",
        },
        {
            to: "/staff/applications",
            label: "Applications",
            icon: "📄",
        },
        {
            to: "/staff/scholarships",
            label: "Scholarships",
            icon: "🎓",
        },
    ];

    const links =
        user?.role === "staff"
            ? staffLinks
            : studentLinks;

    return (
        <div className="app-shell">
            <aside className="sidebar">
                <div className="brand">
                    <div className="brand-mark">
                        CSU
                    </div>

                    <div>
                        <strong>UniScholar</strong>
                        <small>
                            Scholarship Management
                        </small>
                    </div>
                </div>

                <nav className="sidebar-nav">
                    {links.map((link) => (
                        <NavLink
                            key={link.to}
                            to={link.to}
                            end={link.to === "/student" || link.to === "/staff"}
                            className={({ isActive }) =>
                                `nav-link ${
                                    isActive
                                        ? "active"
                                        : ""
                                }`
                            }
                        >
                            <span>{link.icon}</span>
                            {link.label}
                        </NavLink>
                    ))}
                </nav>

                <div className="sidebar-footer">
                    <div className="user-mini">
                        <div className="avatar">
                            {user?.name
                                ?.charAt(0)
                                ?.toUpperCase() || "U"}
                        </div>

                        <div>
                            <strong>
                                {user?.name || "User"}
                            </strong>

                            <small>
                                {user?.role === "staff"
                                    ? "OAS Staff"
                                    : "Student"}
                            </small>
                        </div>
                    </div>

                    <button
                        className="logout-button"
                        onClick={handleLogout}
                    >
                        Log out
                    </button>
                </div>
            </aside>

            <main className="main-content">
                <header className="topbar">
                    <div>
                        <span className="topbar-label">
                            CARAGA STATE UNIVERSITY
                        </span>

                        <h1>
                            {user?.role === "staff"
                                ? "Office of Admission and Scholarship"
                                : "Student Portal"}
                        </h1>
                    </div>

                    <div className="topbar-account">
                        <span>
                            {user?.email}
                        </span>
                    </div>
                </header>

                <section className="page-content">
                    <Outlet />
                </section>
            </main>
        </div>
    );
}