<script setup>
// CreateSiteV2Modal
// ---------------------------------------------------------------
// Minimal provisioning form for the async v2 endpoint.
//
// UX contract (matches the legacy SitesView creation modal):
//   - Only `domain` is required.
//   - The home directory is ALWAYS /home/{domain} - derived
//     server-side, never typed by the operator.
//   - SFTP user, group, db name, db user, db password are all
//     auto-derived from the domain by the backend (see
//     SftpUserCreateStep::deriveFromDomain,
//     DatabaseUserCreateStep::deriveFromDomain, etc.).
//     The fields are exposed as optional overrides in the
//     "Advanced" disclosure - we ONLY send them in the payload
//     when the operator explicitly types a value.
//   - DB is always created. There is no toggle for it: that was a
//     mistake in the legacy UI and we're not repeating it.
//   - SFTP user is opt-out. When unchecked the saga skips the SFTP
//     steps and the docroot is owned by www-data:www-data.
//   - SSL is opt-out, on by default.

import { ref, computed } from 'vue'
import Modal from '@/components/Modal.vue'
import CreateSiteV2WordPressFields from '@/components/CreateSiteV2WordPressFields.vue'
import { createSite } from '@/services/sitesV2'

const props = defineProps({
  show: Boolean,
})
const emit = defineEmits(['close', 'created'])

const emptyWordPress = () => ({
  install: false,
  admin_user: '',
  admin_email: '',
  admin_password: '',
  site_title: '',
})

const form = ref({
  domain: '',
  php_version: 'lsphp83',
  auto_ssl: true,
  create_sftp_user: true,
  dns_enabled: true,
  sftp_user: '',
  sftp_ssh_key: '',
  db_name: '',
  db_user: '',
  db_password: '',
  wordpress: emptyWordPress(),
})

const showAdvanced = ref(false)
const submitting = ref(false)
const errorMessage = ref('')

const trimmedDomain = computed(() => String(form.value.domain ?? '').trim())

// Mirrors Validator::domain() server-side: must contain at least one
// dot AND end in a >=2-char TLD. Single-label hostnames (e.g. "test6")
// can't have a public DNS zone, so we surface that here so the operator
// understands why the toggle is disabled.
const isFqdn = computed(() => {
  const d = trimmedDomain.value.toLowerCase()
  return /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/.test(d)
})

const homeDirPreview = computed(() => {
  return trimmedDomain.value
    ? `/home/${trimmedDomain.value}`
    : '/home/{domain}'
})

const sftpUserPlaceholder = computed(() => {
  const d = trimmedDomain.value
  if (!d) return 'auto from domain'
  const first = d.split('.')[0] ?? ''
  return first || 'auto from domain'
})

const dbSlugPlaceholder = computed(() => {
  const d = trimmedDomain.value.toLowerCase()
  if (!d) return 'auto from domain'
  const slug = d.replace(/[^a-z0-9]/g, '_').replace(/^_+|_+$/g, '')
  return slug ? `fo_${slug}`.slice(0, 32) : 'auto from domain'
})

const generatePassword = () => {
  const chars =
    'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&*'
  let pw = ''
  for (let i = 0; i < 24; i++) {
    pw += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  form.value.db_password = pw
}

const submit = async () => {
  errorMessage.value = ''
  const domain = trimmedDomain.value
  if (!domain) {
    errorMessage.value = 'Domain is required'
    return
  }

  submitting.value = true
  try {
    const payload = {
      php_version: form.value.php_version,
      ssl_enabled: !!form.value.auto_ssl,
    }

    if (form.value.create_sftp_user) {
      const sftpUser = form.value.sftp_user.trim()
      if (sftpUser) {
        payload.sftp_user = sftpUser
        payload.sftp_group = sftpUser
      }
      const sshKey = form.value.sftp_ssh_key.trim()
      if (sshKey) payload.sftp_ssh_key = sshKey
    } else {
      payload.skip_sftp = true
    }

    const dbName = form.value.db_name.trim()
    const dbUser = form.value.db_user.trim()
    const dbPassword = form.value.db_password
    if (dbName) payload.db_name = dbName
    if (dbUser) payload.db_user = dbUser
    if (dbPassword) payload.db_password = dbPassword

    // Only send dns_enabled when the operator opted out OR the domain
    // is single-label (where the saga would skip DNS anyway, but
    // sending false makes the intent unambiguous in the audit log).
    if (!form.value.dns_enabled || !isFqdn.value) {
      payload.dns_enabled = false
    }

    // Optional WordPress install. We only attach install_app when the
    // operator toggled it on; the saga skips the InstallAppStep
    // entirely when the key is absent. Empty admin_* fields are
    // dropped client-side so the server-side defaults
    // (admin@<domain>, "admin", generated password) kick in.
    if (form.value.wordpress.install) {
      const wp = { app_slug: 'wordpress' }
      const adminUser = form.value.wordpress.admin_user.trim()
      const adminEmail = form.value.wordpress.admin_email.trim()
      const adminPassword = form.value.wordpress.admin_password
      const siteTitle = form.value.wordpress.site_title.trim()
      if (adminUser) wp.admin_user = adminUser
      if (adminEmail) wp.admin_email = adminEmail
      if (adminPassword) wp.admin_password = adminPassword
      if (siteTitle) wp.site_title = siteTitle
      payload.install_app = wp
    }

    const data = await createSite({ domain, payload })
    const jobId = data?.job?.id
    emit('created', { jobId, domain, data })
    resetForm()
  } catch (e) {
    errorMessage.value = e?.message ?? 'Failed to enqueue create job'
  } finally {
    submitting.value = false
  }
}

const resetForm = () => {
  form.value = {
    domain: '',
    php_version: 'lsphp83',
    auto_ssl: true,
    create_sftp_user: true,
    dns_enabled: true,
    sftp_user: '',
    sftp_ssh_key: '',
    db_name: '',
    db_user: '',
    db_password: '',
    wordpress: emptyWordPress(),
  }
  showAdvanced.value = false
}

const close = () => {
  if (submitting.value) return
  emit('close')
}
</script>

<template>
  <Modal :show="show" title="Provision new site" size="lg" @close="close">
    <div class="space-y-5">
      <div
        v-if="errorMessage"
        class="rounded-xl border border-red-300 bg-red-50 dark:bg-red-500/10 dark:border-red-500/30 px-3 py-2 text-sm text-red-700 dark:text-red-200 flex items-start gap-2"
      >
        <span class="material-symbols-rounded text-base shrink-0 mt-0.5">error</span>
        <span>{{ errorMessage }}</span>
      </div>

      <!-- Domain + PHP version -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold mb-1">Domain</label>
          <input
            v-model="form.domain"
            type="text"
            class="input w-full"
            placeholder="example.com"
            autocomplete="off"
            required
          />
          <p class="text-xs text-surface-500 mt-1">
            Home directory: <span class="font-mono">{{ homeDirPreview }}</span>
          </p>
        </div>
        <div>
          <label class="block text-xs font-semibold mb-1">PHP version</label>
          <select v-model="form.php_version" class="input w-full">
            <option value="lsphp82">PHP 8.2</option>
            <option value="lsphp83">PHP 8.3</option>
            <option value="lsphp84">PHP 8.4</option>
          </select>
        </div>
      </div>

      <!-- Always-on info -->
      <div
        class="rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))] px-4 py-3 text-xs text-surface-600 dark:text-surface-300"
      >
        <p class="font-semibold mb-1.5 text-surface-700 dark:text-surface-200 flex items-center gap-1.5">
          <span class="material-symbols-rounded text-sm text-primary-500">verified</span>
          Always provisioned
        </p>
        <ul class="space-y-0.5 pl-1">
          <li>· vhost on OpenLiteSpeed (PHP {{ form.php_version }})</li>
          <li>· MariaDB database + user (auto-derived from domain)</li>
          <li>· DB password generated and stored in the SecretVault</li>
        </ul>
      </div>

      <!-- SSL toggle -->
      <label
        class="flex items-center justify-between p-3 rounded-xl cursor-pointer transition-colors
               border border-surface-200 dark:border-[rgb(var(--color-border))]
               bg-white dark:bg-[rgb(var(--color-surface))]
               hover:border-green-300 dark:hover:border-green-500/30"
      >
        <div class="flex items-center gap-3">
          <div
            class="w-9 h-9 rounded-xl flex items-center justify-center
                   bg-green-100 text-green-600 dark:bg-green-500/15 dark:text-green-400"
          >
            <span class="material-symbols-rounded">lock</span>
          </div>
          <div>
            <p class="font-medium text-sm">Request Let's Encrypt SSL</p>
            <p class="text-xs text-surface-500">
              Issued automatically once the vhost is live
            </p>
          </div>
        </div>
        <div class="relative shrink-0">
          <input
            v-model="form.auto_ssl"
            type="checkbox"
            class="sr-only peer"
          />
          <div
            class="w-11 h-6 bg-surface-300 dark:bg-surface-600 rounded-full peer peer-checked:bg-green-500 transition-colors"
          ></div>
          <div
            class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"
          ></div>
        </div>
      </label>

      <!-- SFTP toggle -->
      <label
        class="flex items-center justify-between p-3 rounded-xl cursor-pointer transition-colors
               border border-surface-200 dark:border-[rgb(var(--color-border))]
               bg-white dark:bg-[rgb(var(--color-surface))]
               hover:border-orange-300 dark:hover:border-orange-500/30"
      >
        <div class="flex items-center gap-3">
          <div
            class="w-9 h-9 rounded-xl flex items-center justify-center
                   bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400"
          >
            <span class="material-symbols-rounded">terminal</span>
          </div>
          <div>
            <p class="font-medium text-sm">Create SFTP user</p>
            <p class="text-xs text-surface-500">
              Linux user for SFTP / SSH access. When off, docroot is owned
              by <span class="font-mono">www-data:www-data</span>.
            </p>
          </div>
        </div>
        <div class="relative shrink-0">
          <input
            v-model="form.create_sftp_user"
            type="checkbox"
            class="sr-only peer"
          />
          <div
            class="w-11 h-6 bg-surface-300 dark:bg-surface-600 rounded-full peer peer-checked:bg-orange-500 transition-colors"
          ></div>
          <div
            class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"
          ></div>
        </div>
      </label>

      <!-- DNS toggle -->
      <label
        class="flex items-center justify-between p-3 rounded-xl transition-colors
               border border-surface-200 dark:border-[rgb(var(--color-border))]
               bg-white dark:bg-[rgb(var(--color-surface))]"
        :class="
          isFqdn
            ? 'cursor-pointer hover:border-blue-300 dark:hover:border-blue-500/30'
            : 'opacity-60 cursor-not-allowed'
        "
      >
        <div class="flex items-center gap-3">
          <div
            class="w-9 h-9 rounded-xl flex items-center justify-center
                   bg-blue-100 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400"
          >
            <span class="material-symbols-rounded">dns</span>
          </div>
          <div>
            <p class="font-medium text-sm">Auto-create DNS zone</p>
            <p class="text-xs text-surface-500">
              <template v-if="isFqdn">
                Seeds SOA, NS, A, MX, SPF and DMARC records on this server's
                authoritative PowerDNS. Turn off if your domain uses external
                DNS (Cloudflare, Route53, etc.).
              </template>
              <template v-else-if="trimmedDomain">
                Single-label hostnames can't have a public DNS zone. The
                saga will skip DNS for
                <span class="font-mono">{{ trimmedDomain }}</span>.
              </template>
              <template v-else>
                Available once you enter a fully-qualified domain.
              </template>
            </p>
          </div>
        </div>
        <div class="relative shrink-0">
          <input
            v-model="form.dns_enabled"
            type="checkbox"
            class="sr-only peer"
            :disabled="!isFqdn"
          />
          <div
            class="w-11 h-6 bg-surface-300 dark:bg-surface-600 rounded-full peer peer-checked:bg-blue-500 transition-colors"
          ></div>
          <div
            class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"
          ></div>
        </div>
      </label>

      <!-- WordPress install (optional) -->
      <CreateSiteV2WordPressFields
        v-model="form.wordpress"
        :domain="trimmedDomain"
      />

      <!-- Advanced disclosure -->
      <div>
        <button
          type="button"
          class="text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline flex items-center gap-1"
          @click="showAdvanced = !showAdvanced"
        >
          <span class="material-symbols-rounded text-sm">
            {{ showAdvanced ? 'expand_less' : 'expand_more' }}
          </span>
          Advanced overrides
        </button>
        <p class="text-xs text-surface-500 mt-1">
          Leave any field blank to use the auto-derived value.
        </p>
      </div>

      <div
        v-if="showAdvanced"
        class="space-y-4 border-t border-surface-200 dark:border-surface-700 pt-4"
      >
        <!-- SFTP overrides -->
        <div v-if="form.create_sftp_user" class="space-y-3">
          <h4
            class="text-xs font-semibold uppercase tracking-wide text-surface-500"
          >
            SFTP overrides
          </h4>
          <div>
            <label class="block text-xs font-semibold mb-1">SFTP user</label>
            <input
              v-model="form.sftp_user"
              type="text"
              class="input w-full"
              :placeholder="sftpUserPlaceholder"
              autocomplete="off"
            />
          </div>
          <div>
            <label class="block text-xs font-semibold mb-1">
              SSH public key
              <span class="text-surface-400 font-normal">(optional)</span>
            </label>
            <textarea
              v-model="form.sftp_ssh_key"
              rows="3"
              class="input w-full font-mono text-xs"
              placeholder="ssh-ed25519 AAAAC3... or ssh-rsa AAAAB3..."
            ></textarea>
          </div>
        </div>

        <!-- Database overrides -->
        <div class="space-y-3">
          <h4
            class="text-xs font-semibold uppercase tracking-wide text-surface-500"
          >
            Database overrides
          </h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold mb-1">DB name</label>
              <input
                v-model="form.db_name"
                type="text"
                class="input w-full"
                :placeholder="dbSlugPlaceholder"
                autocomplete="off"
              />
            </div>
            <div>
              <label class="block text-xs font-semibold mb-1">DB user</label>
              <input
                v-model="form.db_user"
                type="text"
                class="input w-full"
                :placeholder="dbSlugPlaceholder"
                autocomplete="off"
              />
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold mb-1">
              DB password
              <span class="text-surface-400 font-normal"
                >(blank = generated &amp; vaulted)</span
              >
            </label>
            <div class="flex gap-2">
              <input
                v-model="form.db_password"
                type="text"
                class="input w-full font-mono text-sm"
                placeholder="auto-generated"
                autocomplete="off"
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
    </div>

    <template #footer>
      <button class="btn-secondary" :disabled="submitting" @click="close">
        Cancel
      </button>
      <button class="btn-primary" :disabled="submitting" @click="submit">
        <span v-if="submitting" class="spinner-sm" />
        <span v-else class="material-symbols-rounded text-sm">rocket_launch</span>
        Create site
      </button>
    </template>
  </Modal>
</template>
