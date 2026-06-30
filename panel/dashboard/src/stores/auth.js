import { defineStore } from "pinia";
import { ref, computed } from "vue";
import api from "@/services/api";

export const useAuthStore = defineStore("auth", () => {
  const user = ref(null);
  const token = ref(localStorage.getItem("token"));
  const refreshToken = ref(localStorage.getItem("refreshToken"));

  const isAuthenticated = computed(() => !!token.value);
  const isSuperAdmin = computed(() => user.value?.role === 'super_admin');
  const isAdmin = computed(() => ['admin', 'super_admin'].includes(user.value?.role));
  const userRole = computed(() => user.value?.role || 'user');
  const allowedSites = computed(() => user.value?.allowed_sites || null);

  async function login(username, password) {
    const deviceToken = localStorage.getItem("deviceToken");
    const payload = { username, password };
    if (deviceToken) payload.device_token = deviceToken;

    const response = await api.post("/auth/login", payload);

    if (response.data.success) {
      const data = response.data.data;
      
      // Check if 2FA is required
      if (data.pending_2fa) {
        return {
          pending_2fa: true,
          temp_token: data.temp_token,
        };
      }
      
      token.value = data.access_token;
      refreshToken.value = data.refresh_token;
      user.value = data.user;

      localStorage.setItem("token", data.access_token);
      localStorage.setItem("refreshToken", data.refresh_token);

      return { success: true };
    }

    throw new Error(response.data.error || "Login failed");
  }

  async function verify2FA(tempToken, totpCode, trustDevice = false) {
    const response = await api.post("/auth/2fa/verify", {
      temp_token: tempToken,
      totp_code: totpCode,
      trust_device: trustDevice,
    });

    if (response.data.success) {
      const data = response.data.data;
      token.value = data.access_token;
      refreshToken.value = data.refresh_token;
      user.value = data.user;

      localStorage.setItem("token", data.access_token);
      localStorage.setItem("refreshToken", data.refresh_token);

      // Store trusted device token if returned
      if (data.device_token) {
        localStorage.setItem("deviceToken", data.device_token);
      }

      return true;
    }

    throw new Error(response.data.error || "Verification failed");
  }

  async function logout() {
    try {
      await api.post("/auth/logout");
    } catch (e) {
      // Ignore errors
    }

    token.value = null;
    refreshToken.value = null;
    user.value = null;

    localStorage.removeItem("token");
    localStorage.removeItem("refreshToken");
  }

  async function checkAuth() {
    if (!token.value) return false;

    try {
      const response = await api.get("/auth/me");
      if (response.data.success) {
        user.value = response.data.data;
        return true;
      }
    } catch (e) {
      // Try refresh
      if (refreshToken.value) {
        try {
          const response = await api.post("/auth/refresh", {
            refresh_token: refreshToken.value,
          });

          if (response.data.success) {
            token.value = response.data.data.access_token;
            localStorage.setItem("token", token.value);
            return true;
          }
        } catch (e) {
          // Refresh failed
        }
      }

      await logout();
    }

    return false;
  }

  async function refreshAccessToken() {
    if (!refreshToken.value) return false;

    try {
      const response = await api.post("/auth/refresh", {
        refresh_token: refreshToken.value,
      });

      if (response.data.success) {
        token.value = response.data.data.access_token;
        localStorage.setItem("token", token.value);
        return true;
      }
    } catch (e) {
      await logout();
    }

    return false;
  }

  return {
    user,
    token,
    refreshToken,
    isAuthenticated,
    isSuperAdmin,
    isAdmin,
    userRole,
    allowedSites,
    login,
    verify2FA,
    logout,
    checkAuth,
    refreshAccessToken,
  };
});
