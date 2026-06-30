<script setup>
// CreateSiteV2WordPressFields
// ---------------------------------------------------------------
// Optional WordPress install fields for the V2 site-creation modal.
//
// Why this is its own component:
//   - Keeps CreateSiteV2Modal under the 400-line soft warn.
//   - Encapsulates the v-model state for one logical concern (whether
//     to install WP + the admin/site fields) so the parent passes a
//     single object back and forth.
//   - Makes the eventual /apps refactor (Phase 4) trivially possible:
//     the same component can drop into AppsView for a standalone
//     install flow with no copy-paste.
//
// Contract:
//   props.modelValue : {
//     install: boolean,
//     admin_user: string,
//     admin_email: string,
//     admin_password: string,
//     site_title: string,
//   }
//   emits('update:modelValue', next)
//   props.domain : string         // used for placeholder defaults
//
// The parent decides whether to actually include this section in
// the payload - we just expose the state.

import { computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  domain: {
    type: String,
    default: '',
  },
})
const emit = defineEmits(['update:modelValue'])

const update = (patch) => {
  emit('update:modelValue', { ...props.modelValue, ...patch })
}

const trimmedDomain = computed(() => String(props.domain ?? '').trim())

const adminEmailPlaceholder = computed(() => {
  const d = trimmedDomain.value
  return d ? `admin@${d}` : 'admin@<your-domain>'
})

const siteTitlePlaceholder = computed(() => trimmedDomain.value || '<your-domain>')

const generatePassword = () => {
  // Shell-safe charset mirrors InstallAppStep::generatePassword so what
  // the operator sees in the form matches what the saga would have
  // generated automatically (helpful for "is this the password I just
  // typed?" sanity checks).
  const chars =
    'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-+=@'
  let pw = ''
  for (let i = 0; i < 20; i++) {
    pw += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  update({ admin_password: pw })
}
</script>

<template>
  <div class="space-y-3">
    <label
      class="flex items-center justify-between p-3 rounded-xl cursor-pointer transition-all
             border border-indigo-200 dark:border-indigo-500/30
             bg-gradient-to-br from-indigo-50 to-violet-50
             dark:from-indigo-500/10 dark:to-violet-500/10
             hover:from-indigo-100 hover:to-violet-100
             dark:hover:from-indigo-500/20 dark:hover:to-violet-500/20"
    >
      <div class="flex items-center gap-3">
        <div
          class="w-9 h-9 rounded-xl flex items-center justify-center
                 bg-white dark:bg-[rgb(var(--color-surface))]
                 text-indigo-600 dark:text-indigo-400 shadow-sm"
        >
          <span class="material-symbols-rounded">extension</span>
        </div>
        <div>
          <p class="font-medium text-sm">Install WordPress</p>
          <p class="text-xs text-surface-500 dark:text-surface-400">
            Runs WP-CLI to set up WordPress in the new document root
            after the site is live. Leave any field blank to use the
            default.
          </p>
        </div>
      </div>
      <div class="relative shrink-0">
        <input
          type="checkbox"
          :checked="modelValue.install"
          class="sr-only peer"
          @change="update({ install: $event.target.checked })"
        />
        <div
          class="w-11 h-6 bg-surface-300 dark:bg-surface-600 rounded-full peer peer-checked:bg-indigo-500 transition-colors"
        ></div>
        <div
          class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"
        ></div>
      </div>
    </label>

    <div
      v-if="modelValue.install"
      class="space-y-3 pl-4 border-l-2 border-indigo-300 dark:border-indigo-500/40 animate-fade-in"
    >
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold mb-1">Admin user</label>
          <input
            :value="modelValue.admin_user"
            type="text"
            class="input w-full"
            placeholder="admin"
            autocomplete="off"
            @input="update({ admin_user: $event.target.value })"
          />
        </div>
        <div>
          <label class="block text-xs font-semibold mb-1">Admin email</label>
          <input
            :value="modelValue.admin_email"
            type="email"
            class="input w-full"
            :placeholder="adminEmailPlaceholder"
            autocomplete="off"
            @input="update({ admin_email: $event.target.value })"
          />
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold mb-1">Site title</label>
        <input
          :value="modelValue.site_title"
          type="text"
          class="input w-full"
          :placeholder="siteTitlePlaceholder"
          autocomplete="off"
          @input="update({ site_title: $event.target.value })"
        />
      </div>
      <div>
        <label class="block text-xs font-semibold mb-1">
          Admin password
          <span class="text-surface-400 font-normal"
            >(blank = generated &amp; vaulted)</span
          >
        </label>
        <div class="flex gap-2">
          <input
            :value="modelValue.admin_password"
            type="text"
            class="input w-full font-mono text-sm"
            placeholder="auto-generated"
            autocomplete="off"
            @input="update({ admin_password: $event.target.value })"
          />
          <button
            type="button"
            class="btn-secondary btn-sm"
            title="Generate strong password"
            @click="generatePassword"
          >
            <span class="material-symbols-rounded text-sm">casino</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
