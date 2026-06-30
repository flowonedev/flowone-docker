<script setup>
import ProjectHubLayout from '@/addons/project-hub/components/ProjectHubLayout.vue'
import DirectorDashboard from '@/addons/project-hub/components/DirectorDashboard.vue'
</script>

<template>
  <ProjectHubLayout title="Director Dashboard" icon="leaderboard">
    <div class="w-full p-6">
      <DirectorDashboard />
    </div>
  </ProjectHubLayout>
</template>
