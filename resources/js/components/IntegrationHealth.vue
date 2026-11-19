<script setup>
import { computed, onMounted, ref } from 'vue'

const props = defineProps({ data: { type: Object, required: true } })
const snapshot = ref(props.data)
const checking = ref(false)
const notice = ref('')
const error = ref('')
const queue = ref({ status: 'checking', connection: '', queue: '', backlog: null, failed_jobs: null, checked_at: null })
const checks = computed(() => [
  snapshot.value.application,
  checking.value ? { ...snapshot.value.service, status: 'checking', summary: 'Checking service health response…', latency_ms: null } : snapshot.value.service,
  checking.value ? { ...snapshot.value.authentication, status: 'checking', summary: 'Verifying signed service access…', latency_ms: null } : snapshot.value.authentication,
])

const statusText = (status) => ({
  connected: 'Connected', authenticated: 'Authenticated', unavailable: 'Unavailable', degraded: 'Degraded',
  checking: 'Checking', unknown: 'Unknown', not_configured: 'Not configured', rejected: 'Rejected',
}[status] || 'Unknown')

const iconPath = {
  app: 'M4 5h16v14H4z M8 9h8 M8 13h5',
  service: 'M4 6h16v12H4z M8 10h.01 M12 10h.01 M16 10h.01 M7 15h10',
  auth: 'M7 10V7a5 5 0 0 1 10 0v3 M5 10h14v10H5z M12 14v2',
}

async function loadQueueHealth() {
  try {
    const response = await fetch('/api/admin/queue-health', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    const result = await response.json()
    queue.value = result
  } catch {
    queue.value = { ...queue.value, status: 'unavailable', backlog: null, failed_jobs: null }
  }
}

onMounted(loadQueueHealth)

async function refresh() {
  if (checking.value) return
  checking.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await fetch('/admin/integrations/status', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    if (response.status === 403) throw new Error('Your account cannot view integration status.')
    if (response.status === 429) throw new Error('Too many checks were requested. Wait a minute, then try again.')
    if (!response.ok) throw new Error('The status check could not be completed. Check your connection and try again.')
    snapshot.value = await response.json()
    await loadQueueHealth()
    notice.value = 'Status refreshed.'
    window.setTimeout(() => { notice.value = '' }, 3500)
  } catch (exception) {
    error.value = exception instanceof TypeError
      ? 'The status check could not reach IFC. Check your connection and try again.'
      : exception.message
  } finally {
    checking.value = false
  }
}
</script>

<template>
  <div class="integration-page">
    <header class="integration-heading">
      <div>
        <div class="integration-kicker">ADMINISTRATION / SERVICE OVERSIGHT</div>
        <h1>Integration Health</h1>
        <p>Monitor communication between Laravel and connected internal services.</p>
      </div>
      <button class="integration-refresh" type="button" :disabled="checking" @click="refresh">
        <svg :class="{ spinning: checking }" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M5.6 9A7 7 0 0 1 18 6l2 6M4 12l2 6a7 7 0 0 0 12.4-3"/></svg>
        {{ checking ? 'Checking…' : 'Refresh Status' }}
      </button>
    </header>

    <div v-if="error" class="integration-notice integration-error" role="alert">{{ error }}</div>
    <div v-else-if="notice" class="integration-notice integration-success" role="status">{{ notice }}</div>

    <section class="integration-summary" aria-label="Integration status summary">
      <article v-for="(item, index) in checks" :key="item.name" class="integration-card" :class="`state-${item.status}`">
        <div class="integration-card-top">
          <span class="integration-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="iconPath[index === 0 ? 'app' : index === 1 ? 'service' : 'auth']"/></svg></span>
          <span class="integration-status" :class="`badge-${item.status}`"><i></i>{{ statusText(item.status) }}</span>
        </div>
        <span class="integration-category">{{ item.category }}</span>
        <h2>{{ item.name }}</h2>
        <p>{{ item.summary }}</p>
        <div v-if="item.latency_ms !== null && item.latency_ms !== undefined" class="integration-latency"><span>Response time</span><b>{{ item.latency_ms }} ms</b></div>
        <div v-if="snapshot.checked_at" class="integration-checked"><span>Checked</span><time :datetime="snapshot.checked_at">{{ new Date(snapshot.checked_at).toLocaleString('en-SG', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) }} PHT</time></div>
      </article>
      <article class="integration-card" :class="`state-${queue.status}`" aria-label="Queue processing health">
        <div class="integration-card-top"><span class="integration-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/><circle cx="8" cy="7" r="1"/><circle cx="16" cy="12" r="1"/></svg></span><span class="integration-status" :class="`badge-${queue.status}`"><i></i>{{ statusText(queue.status) }}</span></div>
        <span class="integration-category">Background processing</span>
        <h2>Queue Processing</h2>
        <p v-if="queue.status === 'available'">{{ queue.connection }} connection · {{ queue.queue }} queue</p>
        <p v-else-if="queue.status === 'checking'">Checking pending and failed job counts.</p>
        <p v-else>Queue service metrics are temporarily unavailable.</p>
        <div class="integration-latency"><span>Pending jobs</span><b>{{ queue.backlog ?? 'Unavailable' }}</b></div>
        <div class="integration-latency"><span>Failed jobs</span><b>{{ queue.failed_jobs ?? 'Unavailable' }}</b></div>
      </article>
    </section>

    <section class="integration-last-check">
      <span class="integration-last-mark"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg></span>
      <div><b>Last successful protected check</b><p>{{ snapshot.last_successful_check ? new Date(snapshot.last_successful_check).toLocaleString('en-SG', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) + ' PHT' : 'No successful protected check has been recorded yet.' }}</p></div>
      <span class="integration-secure-note"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></svg>Credentials remain private</span>
    </section>

    <aside class="integration-footnote"><span>Read only</span><p>Checks verify the public service health response and, separately, Laravel’s signed access to the protected diagnostic. No credentials or internal service addresses are shown.</p></aside>
  </div>
</template>
