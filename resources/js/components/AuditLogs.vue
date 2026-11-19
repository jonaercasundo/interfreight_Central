<script setup>
import { computed, nextTick, ref } from 'vue'

const props = defineProps({ data: { type: Object, required: true } })
const filters = ref({ ...props.data.filters })
const selectedEvent = ref(null)
const detailsDialog = ref(null)
const loading = ref(false)
const page = props.data.logs
const events = computed(() => page.data || [])
const paginationPages = computed(() => [...new Set([1, page.current_page - 1, page.current_page, page.current_page + 1, page.last_page].filter((number) => number >= 1 && number <= page.last_page))].sort((a, b) => a - b))
const exportUrl = computed(() => `/admin/audit/export${queryString() ? `?${queryString()}` : ''}`)

function queryString(extra = {}) {
  const params = new URLSearchParams()
  for (const key of ['search', 'category', 'actor', 'from', 'to', 'direction']) {
    const value = extra[key] ?? filters.value[key]
    if (value) params.set(key, String(value))
  }
  if (extra.page) params.set('page', String(extra.page))
  return params.toString()
}

function applyFilters() {
  loading.value = true
  const query = queryString({ page: 1 })
  window.location.assign(`/admin/audit${query ? `?${query}` : ''}`)
}

function resetFilters() {
  loading.value = true
  window.location.assign('/admin/audit')
}

function refresh() {
  window.location.reload()
}

function goToPage(number) {
  if (number < 1 || number > page.last_page || loading.value) return
  loading.value = true
  window.location.assign(`/admin/audit?${queryString({ page: number })}`)
}

function toggleSort() {
  filters.value.direction = filters.value.direction === 'asc' ? 'desc' : 'asc'
  applyFilters()
}

async function showDetails(event) {
  selectedEvent.value = event
  await nextTick()
  if (!detailsDialog.value?.open) detailsDialog.value?.showModal()
  detailsDialog.value?.querySelector('button')?.focus()
}

function closeDetails() {
  if (detailsDialog.value?.open) detailsDialog.value.close()
  selectedEvent.value = null
}

function formatTime(value, options = {}) {
  if (!value) return 'Timestamp unavailable'
  return new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short', ...options }).format(new Date(value))
}

function formatKey(key) {
  return key.replaceAll('_', ' ').replaceAll('.', ' · ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function displayValue(value) {
  if (Array.isArray(value)) return value
  if (value && typeof value === 'object') return [JSON.stringify(value, null, 2)]
  return [String(value ?? '—')]
}

const firstFilterError = computed(() => Object.values(props.data.errors || {}).flat()[0] || '')
</script>

<template>
  <div class="audit-page">
    <header class="audit-heading">
      <div><span class="ifc-kicker">ADMINISTRATION / SECURITY RECORDS</span><h1>Audit Logs</h1><p>Monitor system activities, user actions, and security events.</p></div>
      <div class="audit-heading-actions"><button class="audit-refresh" type="button" aria-label="Refresh audit logs" title="Refresh audit logs" @click="refresh"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M5.6 9A7 7 0 0 1 18 6l2 2M4 16l2 2a7 7 0 0 0 12.4-3"/></svg></button><a v-if="data.canExport" class="audit-export" :href="exportUrl"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v3h16v-3"/></svg>Export Logs</a></div>
    </header>

    <section class="audit-summary" aria-label="Audit event summary">
      <article class="audit-stat"><span class="audit-stat-icon icon-total"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg></span><div><small>Total Audit Events</small><b>{{ data.summary.total.toLocaleString() }}</b></div><i>All records</i></article>
      <article class="audit-stat"><span class="audit-stat-icon icon-login"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5m5 5H3m10-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg></span><div><small>Login Events</small><b>{{ data.summary.login_events.toLocaleString() }}</b></div><i>Success and failure</i></article>
      <article class="audit-stat"><span class="audit-stat-icon icon-change"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2m3-10h5m-2.5-2.5v5"/></svg></span><div><small>Account &amp; Permission Changes</small><b>{{ data.summary.changes.toLocaleString() }}</b></div><i>Users, roles and permissions</i></article>
      <article class="audit-stat"><span class="audit-stat-icon icon-security"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="M12 8v4m0 4h.01"/></svg></span><div><small>Security Failures</small><b>{{ data.summary.security_failures.toLocaleString() }}</b></div><i>Recorded failure events</i></article>
    </section>
    <p class="audit-scope">{{ data.summary.scope }}</p>

    <form class="audit-filters" @submit.prevent="applyFilters">
      <label class="audit-filter-search"><span>Search activity</span><div class="audit-input-wrap"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input v-model="filters.search" type="search" maxlength="120" placeholder="Actor, event or target"></div></label>
      <label><span>Category</span><select v-model="filters.category"><option value="">All categories</option><option v-for="(label, key) in data.categories" :key="key" :value="key">{{ label }}</option></select></label>
      <label><span>Actor</span><select v-model="filters.actor"><option value="">All actors</option><option v-for="actor in data.actors" :key="actor.id" :value="actor.id">{{ actor.name }} · {{ actor.email }}</option></select></label>
      <label><span>From date</span><input v-model="filters.from" type="date"></label>
      <label><span>To date</span><input v-model="filters.to" type="date"></label>
      <div class="audit-filter-actions"><button type="button" class="audit-reset" @click="resetFilters">Reset</button><button class="audit-apply" type="submit" :disabled="loading">{{ loading ? 'Loading…' : 'Apply filters' }}</button></div>
      <p v-if="firstFilterError" class="audit-filter-error" role="alert">{{ firstFilterError }}</p>
    </form>

    <section class="audit-table-card" aria-label="Audit events">
      <header class="audit-table-head"><div><h2>Activity history</h2><p>{{ page.total.toLocaleString() }} event{{ page.total === 1 ? '' : 's' }} match the selected filters</p></div><span v-if="page.total" class="audit-page-indicator">{{ page.from }}–{{ page.to }} of {{ page.total }}</span></header>
      <div class="audit-table-wrap" :aria-busy="loading">
        <table class="audit-table">
          <thead><tr><th><button type="button" class="audit-sort" @click="toggleSort">Timestamp <span aria-hidden="true">{{ filters.direction === 'asc' ? '↑' : '↓' }}</span></button></th><th>Actor</th><th>Event</th><th>Target</th><th class="audit-ip-column">Source IP</th><th>Details</th></tr></thead>
          <tbody>
            <tr v-for="event in events" :key="event.id">
              <td data-label="Timestamp"><time :datetime="event.occurred_at_utc">{{ formatTime(event.occurred_at_utc) }}</time></td>
              <td data-label="Actor"><div class="audit-actor"><span>{{ (event.actor?.name || 'S').split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase() }}</span><div><b>{{ event.actor?.name || 'System / unauthenticated' }}</b><small>{{ event.actor?.email || 'System event' }}</small></div></div></td>
              <td data-label="Event"><div class="audit-event"><span :class="['audit-category', `category-${event.category}`]">{{ event.category_label }}</span><b>{{ event.event }}</b><small>{{ event.action }}</small></div></td>
              <td data-label="Target"><span v-if="event.target" class="audit-target"><b>{{ event.target.type }}</b><small>#{{ event.target.id }}</small></span><span v-else class="audit-muted">—</span></td>
              <td class="audit-ip-column" data-label="Source IP"><code>{{ event.source_ip || '—' }}</code></td>
              <td data-label="Details"><button class="audit-details-button" type="button" :aria-label="`View details for ${event.event}`" @click="showDetails(event)"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg><span>View details</span></button></td>
            </tr>
            <tr v-if="!events.length"><td colspan="6"><div class="audit-empty"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM8 9h8m-8 4h5"/></svg></span><b>No audit events found</b><small>Adjust the search or filters to see matching activity.</small><button type="button" @click="resetFilters">Clear filters</button></div></td></tr>
          </tbody>
        </table>
      </div>
      <footer v-if="page.last_page > 1" class="audit-pagination"><span>Page {{ page.current_page }} of {{ page.last_page }}</span><div><button type="button" :disabled="!page.prev_page_url || loading" aria-label="Previous page" @click="goToPage(page.current_page - 1)">‹</button><button v-for="number in paginationPages" :key="number" type="button" :class="{ current: number === page.current_page }" :aria-current="number === page.current_page ? 'page' : undefined" @click="goToPage(number)">{{ number }}</button><button type="button" :disabled="!page.next_page_url || loading" aria-label="Next page" @click="goToPage(page.current_page + 1)">›</button></div></footer>
    </section>

    <dialog ref="detailsDialog" class="audit-dialog" aria-labelledby="audit-detail-title" @cancel.prevent="closeDetails" @click.self="closeDetails">
      <template v-if="selectedEvent"><header class="audit-dialog-head"><div><span class="audit-overline">AUDIT EVENT DETAILS</span><h2 id="audit-detail-title">{{ selectedEvent.event }}</h2></div><button type="button" aria-label="Close event details" @click="closeDetails">×</button></header>
        <div class="audit-dialog-content"><div class="audit-detail-event"><span :class="['audit-category', `category-${selectedEvent.category}`]">{{ selectedEvent.category_label }}</span><b>{{ selectedEvent.action }}</b></div>
          <section class="audit-detail-grid"><div><small>Timestamp · Asia/Manila</small><b>{{ formatTime(selectedEvent.occurred_at_utc, { dateStyle: 'full', timeStyle: 'medium' }) }}</b></div><div><small>Stored timestamp · UTC</small><b>{{ formatTime(selectedEvent.occurred_at_utc, { timeZone: 'UTC', dateStyle: 'medium', timeStyle: 'medium' }) }} UTC</b></div><div><small>Actor</small><b>{{ selectedEvent.actor?.name || 'System / unauthenticated' }}</b><span>{{ selectedEvent.actor?.email || 'No actor account' }}</span></div><div><small>Affected target</small><b>{{ selectedEvent.target ? `${selectedEvent.target.type} #${selectedEvent.target.id}` : 'No target entity' }}</b></div><div><small>Source IP</small><b>{{ selectedEvent.source_ip || 'Not recorded' }}</b></div><div><small>Request / correlation ID</small><b>{{ selectedEvent.request_id || 'Not recorded' }}</b></div></section>
          <section class="audit-metadata"><h3>Event details</h3><div v-if="Object.keys(selectedEvent.metadata || {}).length" class="audit-metadata-list"><div v-for="(value, key) in selectedEvent.metadata" :key="key" class="audit-meta-row"><b>{{ formatKey(key) }}</b><div><span v-for="(item, index) in displayValue(value)" :key="index" :class="{ 'audit-meta-code': typeof value === 'object' }">{{ item }}</span></div></div></div><p v-else class="audit-no-metadata">No additional event details were recorded.</p></section>
        </div><footer class="audit-dialog-foot"><span>Audit records are read-only.</span><button type="button" @click="closeDetails">Close</button></footer>
      </template>
    </dialog>
    <div v-if="loading" class="audit-loading" role="status">Loading audit activity…</div>
  </div>
</template>
