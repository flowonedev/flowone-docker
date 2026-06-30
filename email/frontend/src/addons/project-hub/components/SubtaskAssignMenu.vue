<script setup>
import UserAvatar from '@/components/shared/UserAvatar.vue'

defineProps({
  cardAssigneeEmails: { type: Set, required: true },
  membersList: { type: Array, required: true },
  groups: { type: Array, default: () => [] },
  subtask: { type: Object, required: true },
  isAssignedFn: { type: Function, required: true },
  getAssigneesFn: { type: Function, required: true },
})

const emit = defineEmits(['close', 'toggle-assignee', 'assign-group', 'clear-assignees'])
</script>

<template>
  <div class="absolute right-0 top-full mt-1 w-52 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-xl z-[60] py-1 max-h-56 overflow-y-auto">
    <div class="px-3 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-wide flex items-center justify-between">
      <span>Assign members</span>
      <button type="button" @click.stop="emit('close')" class="p-0.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded">
        <span class="material-symbols-rounded text-[12px]">close</span>
      </button>
    </div>

    <template v-if="cardAssigneeEmails.size > 0">
      <div class="px-3 py-1 text-[10px] font-semibold text-surface-400 uppercase tracking-wide">Card Assignees</div>
      <button
        v-for="member in membersList.filter(m => cardAssigneeEmails.has(m.user_email || m.email))"
        :key="'sca-' + (member.user_email || member.email)"
        type="button"
        class="w-full px-3 py-2 text-left text-xs hover:bg-primary-50 dark:hover:bg-primary-900/20 flex items-center gap-2 text-surface-700 dark:text-surface-300"
        @click.stop="emit('toggle-assignee', member.user_email || member.email)"
      >
        <UserAvatar :email="member.user_email || member.email" size="xs" class="shrink-0" />
        <span class="truncate flex-1">{{ (member.user_email || member.email).split('@')[0] }}</span>
        <span
          v-if="isAssignedFn(subtask, member.user_email || member.email)"
          class="material-symbols-rounded text-[14px] text-primary-500 shrink-0"
        >check_circle</span>
      </button>
      <div class="px-3 py-1 text-[10px] font-semibold text-surface-400 uppercase tracking-wide border-t border-surface-200 dark:border-surface-700 mt-0.5">Others</div>
    </template>

    <button
      v-for="member in membersList.filter(m => !cardAssigneeEmails.has(m.user_email || m.email))"
      :key="'sm-' + (member.user_email || member.email)"
      type="button"
      class="w-full px-3 py-2 text-left text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300"
      @click.stop="emit('toggle-assignee', member.user_email || member.email)"
    >
      <UserAvatar :email="member.user_email || member.email" size="xs" class="shrink-0" />
      <span class="truncate flex-1">{{ (member.user_email || member.email).split('@')[0] }}</span>
      <span
        v-if="isAssignedFn(subtask, member.user_email || member.email)"
        class="material-symbols-rounded text-[14px] text-primary-500 shrink-0"
      >check_circle</span>
    </button>

    <template v-if="groups?.length">
      <div class="px-3 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-wide border-t border-surface-200 dark:border-surface-700 mt-0.5">
        Groups
      </div>
      <button
        v-for="group in groups"
        :key="'sg-' + group.id"
        type="button"
        class="w-full px-3 py-2 text-left text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300"
        @click.stop="emit('assign-group', group.id)"
      >
        <span class="material-symbols-rounded text-[14px] text-surface-400 shrink-0">group</span>
        <span class="truncate flex-1">{{ group.name }}</span>
        <span class="text-[10px] text-surface-400 shrink-0">+ all</span>
      </button>
    </template>

    <button
      v-if="getAssigneesFn(subtask).length"
      type="button"
      class="w-full px-3 py-2 text-left text-xs text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2 border-t border-surface-200 dark:border-surface-700 mt-0.5"
      @click.stop="emit('clear-assignees')"
    >
      <span class="material-symbols-rounded text-[14px]">person_remove</span>
      Remove all
    </button>
  </div>
</template>
