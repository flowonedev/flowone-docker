<script setup>
/**
 * Phase 8 — Admin route wrapper for the storage dashboard.
 *
 * The dashboard component itself is layout-agnostic and lives under
 * components/storage/ so it can be embedded elsewhere (e.g. a
 * settings panel) without dragging in AppHeader. This view is the
 * standalone /admin/storage entry point and just adds the chrome.
 *
 * Auth + admin gating is done two ways:
 *  1. Route guard sets requiresAuth (handled by router)
 *  2. The backend /admin/storage/dashboard endpoint enforces the
 *     ColleagueService::isAdmin check; a non-admin caller will see
 *     the "admin only" 403 surfaced as an error banner in the
 *     dashboard component.
 */
import AppHeader from '@/components/shared/AppHeader.vue'
import StorageAdminDashboard from '@/components/storage/StorageAdminDashboard.vue'
</script>

<template>
  <div class="min-h-screen bg-surface-50 dark:bg-surface-900">
    <AppHeader :current-view="'storage'" />
    <div class="p-6 max-w-6xl mx-auto">
      <StorageAdminDashboard />
    </div>
  </div>
</template>
