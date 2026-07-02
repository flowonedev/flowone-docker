/**
 * Frontend Cache Service
 *
 * Provides caching with TTL for API responses to speed up the panel.
 * Data is cached in memory with optional localStorage persistence.
 */

const DEFAULT_TTL = 60 * 60 * 1000; // 1 hour in milliseconds

class CacheService {
  constructor() {
    this.cache = new Map();
    this.loadFromStorage();
  }

  /**
   * Load persisted cache from localStorage
   */
  loadFromStorage() {
    try {
      const stored = localStorage.getItem("vps_cache");
      if (stored) {
        const data = JSON.parse(stored);
        const now = Date.now();
        // Only load non-expired entries
        Object.entries(data).forEach(([key, entry]) => {
          if (entry.expires > now) {
            this.cache.set(key, entry);
          }
        });
      }
    } catch (e) {
      console.warn("Failed to load cache from storage:", e);
    }
  }

  /**
   * Persist cache to localStorage
   */
  saveToStorage() {
    try {
      const data = {};
      this.cache.forEach((value, key) => {
        data[key] = value;
      });
      localStorage.setItem("vps_cache", JSON.stringify(data));
    } catch (e) {
      console.warn("Failed to save cache to storage:", e);
    }
  }

  /**
   * Get cached data
   * @param {string} key - Cache key
   * @returns {any|null} - Cached data or null if expired/missing
   */
  get(key) {
    const entry = this.cache.get(key);
    if (!entry) return null;

    if (Date.now() > entry.expires) {
      this.cache.delete(key);
      this.saveToStorage();
      return null;
    }

    return entry.data;
  }

  /**
   * Set cache data
   * @param {string} key - Cache key
   * @param {any} data - Data to cache
   * @param {number} ttl - Time to live in ms (default: 1 hour)
   */
  set(key, data, ttl = DEFAULT_TTL) {
    this.cache.set(key, {
      data,
      expires: Date.now() + ttl,
      cachedAt: Date.now(),
    });
    this.saveToStorage();
  }

  /**
   * Check if cache entry exists and is valid
   * @param {string} key - Cache key
   * @returns {boolean}
   */
  has(key) {
    return this.get(key) !== null;
  }

  /**
   * Get cache age in seconds
   * @param {string} key - Cache key
   * @returns {number|null} - Age in seconds or null if not cached
   */
  getAge(key) {
    const entry = this.cache.get(key);
    if (!entry) return null;
    return Math.floor((Date.now() - entry.cachedAt) / 1000);
  }

  /**
   * Get human-readable cache age
   * @param {string} key - Cache key
   * @returns {string} - e.g. "5 min ago", "1 hour ago"
   */
  getAgeHuman(key) {
    const seconds = this.getAge(key);
    if (seconds === null) return "not cached";

    if (seconds < 60) return "just now";
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
    if (seconds < 86400)
      return `${Math.floor(seconds / 3600)} hour${
        Math.floor(seconds / 3600) > 1 ? "s" : ""
      } ago`;
    return `${Math.floor(seconds / 86400)} day${
      Math.floor(seconds / 86400) > 1 ? "s" : ""
    } ago`;
  }

  /**
   * Invalidate specific cache entry
   * @param {string} key - Cache key
   */
  invalidate(key) {
    this.cache.delete(key);
    this.saveToStorage();
  }

  /**
   * Invalidate all entries matching a prefix
   * @param {string} prefix - Key prefix to match
   */
  invalidatePrefix(prefix) {
    const keysToDelete = [];
    this.cache.forEach((_, key) => {
      if (key.startsWith(prefix)) {
        keysToDelete.push(key);
      }
    });
    keysToDelete.forEach((key) => this.cache.delete(key));
    this.saveToStorage();
  }

  /**
   * Clear all cache
   */
  clear() {
    this.cache.clear();
    localStorage.removeItem("vps_cache");
  }

  /**
   * Get all cache keys
   * @returns {string[]}
   */
  keys() {
    return Array.from(this.cache.keys());
  }
}

// Export singleton instance
export const cache = new CacheService();

// Cache key constants
export const CACHE_KEYS = {
  // Overview
  SERVICES: "overview:services",
  DATABASES: "overview:databases",
  SSL: "overview:ssl",
  MAIL_ACCOUNTS: "overview:mail:accounts",
  MAIL_DOMAINS: "overview:mail:domains",
  DNS_ZONES: "overview:dns:zones",
  WORDPRESS: "overview:wordpress",
  DOCKER_STATUS: "overview:docker:status",
  DOCKER_OVERVIEW: "overview:docker:overview",

  // Sites
  SITES: "sites:list",

  // Files
  FILES_PREFIX: "files:",

  // Security
  FAIL2BAN_STATUS: "security:fail2ban:status",
  FAIL2BAN_JAILS: "security:fail2ban:jails",
  FIREWALL_STATUS: "security:firewall:status",
  FIREWALL_ZONES: "security:firewall:zones",
  MODSEC_STATUS: "security:modsec:status",
  CPGUARD_STATUS: "security:cpguard:status",

  // System
  SYSTEM_INFO: "system:info",
};

// TTL constants (in milliseconds)
export const TTL = {
  SHORT: 5 * 60 * 1000, // 5 minutes - for frequently changing data
  MEDIUM: 30 * 60 * 1000, // 30 minutes
  LONG: 60 * 60 * 1000, // 1 hour - default
  VERY_LONG: 4 * 60 * 60 * 1000, // 4 hours - for rarely changing data
};

export default cache;
