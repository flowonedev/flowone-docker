<script setup>
import { watch } from 'vue'
import { useAddons } from '@/composables/useAddons'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useMailingListsStore } from '@/addons/email-marketing/stores/mailingLists'
import * as windowService from '@/services/composeWindowService'
import ComposeInlineWindow from './ComposeInlineWindow.vue'

const windows = windowService.getWindows()

const { emailMarketingEnabled, teamEnabled } = useAddons()
const colleaguesStore = useColleaguesStore()
const mailingListsStore = useMailingListsStore()

watch(() => windows.value.length, (len, oldLen) => {
  if (len > 0 && oldLen === 0) {
    if (teamEnabled.value && colleaguesStore.groups.length === 0) colleaguesStore.fetchGroups()
    if (emailMarketingEnabled.value && mailingListsStore.lists.length === 0) mailingListsStore.fetchLists()
  }
})
</script>

<template>
  <Teleport to="body">
    <template v-for="(win, idx) in windows" :key="win.id">
      <ComposeInlineWindow
        :win="win"
        :offset-index="idx"
        :total-windows="windows.length"
      />
    </template>
  </Teleport>
</template>
