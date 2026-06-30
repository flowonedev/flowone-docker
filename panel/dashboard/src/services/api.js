import axios from "axios";

const api = axios.create({
  baseURL: "/api",
  headers: {
    "Content-Type": "application/json",
  },
});

// Request interceptor to add auth token
api.interceptors.request.use((config) => {
  const token = localStorage.getItem("token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Single-flight token refresh: when several requests 401 at once (token
// just expired), we must NOT fire one refresh per request - that races and
// can blow away a still-valid session. The first 401 starts a refresh; all
// others await the same promise.
let refreshPromise = null;

function refreshAccessToken() {
  if (refreshPromise) return refreshPromise;

  const refreshToken = localStorage.getItem("refreshToken");
  if (!refreshToken) return Promise.resolve(null);

  refreshPromise = axios
    .post("/api/auth/refresh", { refresh_token: refreshToken })
    .then((response) => {
      if (response.data?.success) {
        const newToken = response.data.data.access_token;
        localStorage.setItem("token", newToken);
        return newToken;
      }
      return null;
    })
    .catch(() => null)
    .finally(() => {
      refreshPromise = null;
    });

  return refreshPromise;
}

// Response interceptor for error handling
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config || {};

    // Skip auth redirect for the auth endpoints themselves - let them
    // handle their own errors (and avoid recursing on /auth/refresh).
    const isAuthEndpoint =
      originalRequest.url?.includes("/auth/login") ||
      originalRequest.url?.includes("/auth/2fa/verify") ||
      originalRequest.url?.includes("/auth/refresh");

    // Handle 401 errors (token expired or invalid). NOTE: tokens are only
    // ever cleared inside this 401 branch - a 400/403/422/5xx never logs
    // the user out.
    if (
      error.response?.status === 401 &&
      !originalRequest._retry &&
      !isAuthEndpoint
    ) {
      originalRequest._retry = true;

      const newToken = await refreshAccessToken();
      if (newToken) {
        originalRequest.headers = originalRequest.headers || {};
        originalRequest.headers.Authorization = `Bearer ${newToken}`;
        return api(originalRequest);
      }

      // No refresh token or refresh failed - clear tokens and redirect.
      localStorage.removeItem("token");
      localStorage.removeItem("refreshToken");
      if (!window.location.pathname.startsWith("/login")) {
        window.location.href = "/login";
      }
      return Promise.reject(error);
    }

    // Extract error message from response for better error handling
    const errorMessage =
      error.response?.data?.error ||
      error.response?.data?.message ||
      error.message ||
      "Request failed";

    // Create a more informative error, preserving response for catch blocks
    const enhancedError = new Error(errorMessage);
    enhancedError.status = error.response?.status;
    enhancedError.response = error.response; // Preserve so e.response?.data?.error works in catch blocks
    enhancedError.originalError = error;

    return Promise.reject(enhancedError);
  }
);

export default api;
