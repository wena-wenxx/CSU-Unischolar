import {
    createContext,
    useContext,
    useEffect,
    useState,
} from "react";

import api from "../services/api";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const token = localStorage.getItem("unischolar_token");

        if (!token) {
            setLoading(false);
            return;
        }

        api.get("/user")
            .then((response) => {
                setUser(response.data);
            })
            .catch(() => {
                localStorage.removeItem("unischolar_token");
                setUser(null);
            })
            .finally(() => {
                setLoading(false);
            });
    }, []);

    const login = async (email, password) => {
        const response = await api.post("/login", {
            email,
            password,
        });

        const { token, user } = response.data;

        localStorage.setItem("unischolar_token", token);
        setUser(user);

        return user;
    };

    const logout = async () => {
        try {
            await api.post("/logout");
        } catch {
            // Even if the API request fails,
            // clear the local session.
        }

        localStorage.removeItem("unischolar_token");
        setUser(null);
    };

    return (
        <AuthContext.Provider
            value={{
                user,
                loading,
                login,
                logout,
                isAuthenticated: !!user,
            }}
        >
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    return useContext(AuthContext);
}