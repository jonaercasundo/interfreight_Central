<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { acknowledgeBusinessAction, listenForBusinessActions, pendingBusinessActions } from '../businessActionSync.js'

const props = defineProps({ page: { type: String, required: true }, permissions: { type: Array, default: () => [] }, userId: { type: Number, default: null } })
const allowed = computed(() => new Set(props.permissions))
const can = (permission) => allowed.value.has(permission)
const title = 'Sales Dashboard'
const subtitle = 'Plan client demand, confirm shipment schedules and review verified capacity.'
const busy = ref(false)
const calendarBusy = ref(false)
const availabilityBusy = ref(false)
const saving = ref(false)
const error = ref('')
const calendarError = ref('')
const availabilityError = ref('')
const notice = ref('')
const summary = ref(null)
const events = ref([])
const calendarClients = ref([])
const calendarPageInfo = ref({ current_page: 1, last_page: 1, total: 0 })
const clients = ref([])
const calendarView = ref('month')
const month = ref(localDate(new Date()).slice(0, 7))
const weekStart = ref(localDate(mondayOf(new Date())))
const calendarFilters = ref({ client_id: '', route: '', mode: '', status: '' })
const availabilityFilters = ref({ search: '', route: '', mode: '', status: '' })
const availabilityRows = ref([])
const availabilityMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const availabilityPage = ref(1)
const dialog = ref('')
const selectedEvent = ref(null)
const selectedDetail = ref(null)
const detailBusy = ref(false)
const capacityForm = ref(emptyCapacity())
const editingAvailability = ref(null)
let availabilitySearchTimer

function emptyCapacity() {
  const today = localDate(new Date())
  return { origin: '', destination: '', transport_mode: 'Sea', service_type: 'FCL', departure_date: '', confirmed_capacity: '', reserved_capacity: '0', capacity_unit: 'TEU', booking_cutoff: '', source_type: 'carrier_verified', source_reference: '', valid_from: today, valid_until: '', lock_version: null }
}

function mondayOf(date) {
  const result = new Date(date.getFullYear(), date.getMonth(), date.getDate())
  result.setDate(result.getDate() - ((result.getDay() + 6) % 7))
  return result
}

function localDate(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function rangeForView() {
  if (calendarView.value === 'week') {
    const from = new Date(`${weekStart.value}T00:00:00`)
    const to = new Date(from)
    to.setDate(to.getDate() + 6)
    return { from: localDate(from), to: localDate(to) }
  }
  if (calendarView.value === 'list') {
    const from = new Date(`${month.value}-01T00:00:00`)
    const to = new Date(from)
    to.setDate(to.getDate() + 92)
    return { from: localDate(from), to: localDate(to) }
  }
  const [year, monthNumber] = month.value.split('-').map(Number)
  return { from: `${month.value}-01`, to: localDate(new Date(year, monthNumber, 0)) }
}

async function api(url, options = {}) {
  const response = await fetch(url, {
    credentials: 'same-origin', ...options,
    headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } : {}), ...(options.headers || {}) },
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    const errors = Object.values(payload.errors || {}).flat()
    throw new Error(errors.join(' ') || payload.message || `Request failed (${response.status}).`)
  }
  return payload
}

async function loadSummary() {
  if (!can('sales.dashboard.view')) return true
  busy.value = true
  error.value = ''
  try { summary.value = (await api('/api/sales/dashboard/summary')).data; return true } catch (cause) { error.value = cause.message || 'Dashboard data could not be loaded.'; return false } finally { busy.value = false }
}

async function loadCalendar() {
  if (!can('sales.calendar.view')) return
  calendarBusy.value = true
  calendarError.value = ''
  const range = rangeForView()
  const query = new URLSearchParams({ ...range, page: String(calendarPageInfo.value.current_page || 1), per_page: '100' })
  for (const [key, value] of Object.entries(calendarFilters.value)) if (value) query.set(key, value)
  try {
    const payload = await api(`/api/sales/calendar?${query}`)
    events.value = payload.data?.data || []
    calendarPageInfo.value = { current_page: payload.data?.current_page || 1, last_page: payload.data?.last_page || 1, total: payload.data?.total || 0 }
    calendarClients.value = payload.clients || []
  } catch (cause) { calendarError.value = cause.message || 'Calendar events could not be loaded.' } finally { calendarBusy.value = false }
}

async function loadAvailability() {
  if (!can('sales.availability.view')) return
  availabilityBusy.value = true
  availabilityError.value = ''
  const query = new URLSearchParams({ page: String(availabilityPage.value), per_page: '10' })
  for (const [key, value] of Object.entries(availabilityFilters.value)) if (value) query.set(key, value)
  try {
    const payload = await api(`/api/sales/availability?${query}`)
    const page = payload.data || {}
    availabilityRows.value = page.data || []
    availabilityMeta.value = { current_page: page.current_page || 1, last_page: page.last_page || 1, total: page.total || 0 }
  } catch (cause) { availabilityError.value = cause.message || 'Availability records could not be loaded.' } finally { availabilityBusy.value = false }
}

async function loadClients() {
  if (!can('clients.view')) return
  try { clients.value = (await api('/api/sales/clients?per_page=100')).data?.data || [] } catch { clients.value = [] }
}

async function load() {
  const [summaryLoaded] = await Promise.all([loadSummary(), loadCalendar(), loadAvailability(), loadClients()])
  return summaryLoaded
}

watch(calendarView, () => { calendarPageInfo.value.current_page = 1; loadCalendar() })
watch([month, weekStart, calendarFilters], () => { calendarPageInfo.value.current_page = 1; loadCalendar() }, { deep: true })
watch(availabilityFilters, () => {
  availabilityPage.value = 1
  window.clearTimeout(availabilitySearchTimer)
  availabilitySearchTimer = window.setTimeout(loadAvailability, 250)
}, { deep: true })
async function refreshForAssistantAction(event) {
  if (!await load()) throw new Error('Sales dashboard refresh failed; leave the invalidation pending.')
  const inquiry = event.actions.find(action => action.entity_type === 'sales_inquiry')
  if (inquiry) notice.value = `Sales dashboard refreshed after ${inquiry.reference_number} was saved.`
}

const stopActionSync = listenForBusinessActions(props.userId, 'sales-dashboard', refreshForAssistantAction)
onBeforeUnmount(stopActionSync)
onMounted(async () => {
  const pendingSyncs = pendingBusinessActions(props.userId, 'sales-dashboard')
  const refreshed = await load()
  if (refreshed) pendingSyncs.forEach(event => acknowledgeBusinessAction(event.id, props.userId, 'sales-dashboard'))
})

const calendarDays = computed(() => {
  const [year, monthNumber] = month.value.split('-').map(Number)
  const first = mondayOf(new Date(year, monthNumber - 1, 1))
  return Array.from({ length: 42 }, (_, index) => {
    const date = new Date(first)
    date.setDate(first.getDate() + index)
    const key = localDate(date)
    return { key, day: date.getDate(), inMonth: date.getMonth() === monthNumber - 1, events: events.value.filter(event => event.date === key) }
  })
})
const weeklyDays = computed(() => {
  const start = new Date(`${weekStart.value}T00:00:00`)
  return Array.from({ length: 7 }, (_, index) => {
    const date = new Date(start)
    date.setDate(date.getDate() + index)
    const key = localDate(date)
    return { key, label: date.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' }), events: events.value.filter(event => event.date === key) }
  })
})
const capacitySummary = computed(() => {
  const units = summary.value?.available_capacity?.by_unit || {}
  return Object.entries(units).map(([unit, amount]) => `${Number(amount).toLocaleString()} ${unit}`).join(' · ')
})

async function openEvent(event) {
  selectedEvent.value = event
  selectedDetail.value = null
  dialog.value = 'event'
  detailBusy.value = true
  try { selectedDetail.value = (await api(`/api/sales/calendar/${event.detail_type}/${event.detail_id}`)).data } catch (cause) { selectedDetail.value = { error: cause.message } } finally { detailBusy.value = false }
}

function eventLabel(event) {
  return `${event.label}: ${event.title} · ${event.client} · ${event.route}`
}

function openCapacity(record = null) {
  editingAvailability.value = record
  capacityForm.value = record ? { ...emptyCapacity(), ...record, confirmed_capacity: record.confirmed_capacity, reserved_capacity: record.reserved_capacity } : emptyCapacity()
  error.value = ''
  dialog.value = 'capacity'
}

async function saveCapacity() {
  saving.value = true
  error.value = ''
  try {
    const editing = Boolean(editingAvailability.value)
    const url = editing ? `/api/sales/availability/${editingAvailability.value.id}` : '/api/sales/availability'
    await api(url, { method: editing ? 'PUT' : 'POST', body: JSON.stringify(capacityForm.value) })
    dialog.value = ''
    notice.value = editing ? 'Availability record updated.' : 'Availability record added.'
    await Promise.all([loadAvailability(), loadSummary()])
  } catch (cause) { error.value = cause.message || 'Availability record could not be saved.' } finally { saving.value = false }
}

async function deactivate(record) {
  if (!window.confirm(`Deactivate ${record.reference}? It will no longer appear as available capacity.`)) return
  availabilityError.value = ''
  try {
    await api(`/api/sales/availability/${record.id}/deactivate`, { method: 'POST', body: JSON.stringify({ lock_version: record.lock_version }) })
    notice.value = `${record.reference} deactivated.`
    await Promise.all([loadAvailability(), loadSummary()])
  } catch (cause) { availabilityError.value = cause.message || 'Availability record could not be deactivated.' }
}

function changeCalendarPage(page) {
  if (page < 1 || page > calendarPageInfo.value.last_page) return
  calendarPageInfo.value.current_page = page
  loadCalendar()
}
function changeAvailabilityPage(page) {
  if (page < 1 || page > availabilityMeta.value.last_page) return
  availabilityPage.value = page
  loadAvailability()
}
function displayDate(value, options = { dateStyle: 'medium' }) {
  if (!value) return 'Not scheduled'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, options).format(date)
}
function titleCase(value) { return String(value || 'unknown').replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()) }
</script>

<template>
  <section class="sales-dashboard-page">
    <div class="sales-dashboard-heading">
      <div><div class="ifc-kicker">INTERFREIGHT GROUP / SALES</div><h1>{{ title }}</h1><p>{{ subtitle }}</p></div>
      <a class="sales-dashboard-action" href="/sales/inquiries">Create inquiry <span aria-hidden="true">＋</span></a>
    </div>
    <div v-if="notice" class="sales-dashboard-notice" role="status">{{ notice }}<button type="button" aria-label="Dismiss notice" @click="notice = ''">×</button></div>

    <template>
      <div v-if="can('sales.dashboard.view')" class="sales-dashboard-kpis" aria-label="Sales summary">
        <article><span>Total Inquiries</span><strong>{{ busy ? '—' : summary?.total_inquiries ?? '—' }}</strong><small>Your owned inquiry records</small></article>
        <article><span>Draft Inquiries</span><strong>{{ busy ? '—' : summary?.draft_inquiries ?? '—' }}</strong><small>Saved and awaiting submission</small></article>
        <article><span>Submitted Inquiries</span><strong>{{ busy ? '—' : summary?.submitted_inquiries ?? '—' }}</strong><small>Ready for forecasting</small></article>
        <article><span>Forecasted Inquiries</span><strong>{{ busy ? '—' : summary?.forecasted_inquiries ?? '—' }}</strong><small>Linked to active forecasts</small></article>
        <article><span>Forecast Shipments</span><strong>{{ busy ? '—' : summary?.forecast_shipments ?? '—' }}</strong><small>Forecast demand, not operational jobs</small></article>
        <article><span>Approved Quotations</span><strong>{{ busy ? '—' : summary?.approved_quotations ?? '—' }}</strong><small>Approved, issued or client accepted</small></article>
        <article><span>Upcoming Shipments</span><strong>{{ busy ? '—' : summary?.upcoming_shipments ?? '—' }}</strong><small>Scheduled in the next 90 days</small></article>
        <article><span>Available Capacity</span><strong v-if="summary?.available_capacity?.message">Not confirmed</strong><strong v-else>{{ capacitySummary || '—' }}</strong><small>{{ summary?.available_capacity?.message || 'Verified, active capacity minus reservations' }}</small></article>
      </div>
      <div v-if="error" class="sales-dashboard-error" role="alert">{{ error }}<button type="button" @click="loadSummary">Retry</button></div>

      <section v-if="can('sales.calendar.view')" class="ifc-panel sales-dashboard-calendar">
        <header class="sales-dashboard-panel-head"><div><h2>Shipment Calendar</h2><p>Forecast windows and confirmed operational schedules are marked separately.</p></div><div class="sales-calendar-tabs" role="group" aria-label="Calendar view"><button v-for="view in ['month', 'week', 'list']" :key="view" type="button" :aria-pressed="calendarView === view" :class="{ selected: calendarView === view }" @click="calendarView = view">{{ titleCase(view) }}</button></div></header>
        <div class="sales-dashboard-filters">
          <label v-if="calendarView === 'month' || calendarView === 'list'">{{ calendarView === 'month' ? 'Calendar month' : 'List start month' }}<input v-model="month" type="month" aria-label="Calendar month"></label>
          <label v-if="calendarView === 'week'">Week starting<input v-model="weekStart" type="date" aria-label="Week starting"></label>
          <label>Client<select v-model="calendarFilters.client_id"><option value="">All clients</option><option v-for="client in calendarClients" :key="client.id" :value="client.id">{{ client.company_name }}</option></select></label>
          <label>Route<input v-model.trim="calendarFilters.route" placeholder="Search origin or destination"></label>
          <label>Mode<select v-model="calendarFilters.mode"><option value="">All modes</option><option>Sea</option><option>Air</option><option>Road</option><option>Rail</option></select></label>
          <label>Status<select v-model="calendarFilters.status"><option value="">All statuses</option><option v-for="status in ['tentative','probable','confirmed','open','in_progress','completed','submitted','under_review']" :key="status" :value="status">{{ titleCase(status) }}</option></select></label>
        </div>
        <div class="sales-calendar-legend"><span><i class="forecast-dot"></i>Forecast window</span><span><i class="confirmed-dot"></i>Confirmed schedule / booking milestone</span><span>{{ calendarPageInfo.total }} event{{ calendarPageInfo.total === 1 ? '' : 's' }}</span></div>
        <div v-if="calendarError" class="sales-dashboard-error" role="alert">{{ calendarError }}<button type="button" @click="loadCalendar">Retry</button></div>
        <p v-else-if="calendarBusy && !events.length" class="sales-dashboard-loading" role="status">Loading calendar events…</p>
        <div v-else-if="!events.length" class="sales-dashboard-empty">No forecasts or confirmed schedules match these filters.</div>
        <div v-else-if="calendarView === 'month'" class="sales-calendar-month">
          <div v-for="day in ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']" :key="day" class="sales-calendar-weekday">{{ day }}</div>
          <div v-for="day in calendarDays" :key="day.key" :class="['sales-calendar-day', { 'outside-month': !day.inMonth, 'today': day.key === new Date().toISOString().slice(0, 10) }]">
            <span class="sales-calendar-day-number">{{ day.day }}</span>
            <button v-for="event in day.events.slice(0, 3)" :key="event.key" type="button" :class="['sales-calendar-event', `event-${event.kind}`]" :title="eventLabel(event)" @click="openEvent(event)"><b>{{ event.kind === 'forecast' ? 'Forecast' : 'Schedule' }}</b><span>{{ event.title }}</span></button>
            <small v-if="day.events.length > 3">+{{ day.events.length - 3 }} more</small>
          </div>
        </div>
        <div v-else-if="calendarView === 'week'" class="sales-calendar-week">
          <section v-for="day in weeklyDays" :key="day.key"><h3>{{ day.label }}</h3><button v-for="event in day.events" :key="event.key" type="button" :class="['sales-calendar-list-event', `event-${event.kind}`]" @click="openEvent(event)"><span>{{ event.kind === 'forecast' ? 'FORECAST' : 'CONFIRMED' }}</span><b>{{ event.title }}</b><small>{{ event.client }} · {{ event.route }} · {{ event.transport_mode }}</small></button><p v-if="!day.events.length" class="sales-calendar-day-empty">No calendar entries</p></section>
        </div>
        <div v-else class="ifc-table-wrap sales-calendar-list">
          <table><thead><tr><th>DATE</th><th>TYPE</th><th>CLIENT / JOB</th><th>ROUTE</th><th>MODE</th><th>STATUS</th><th></th></tr></thead><tbody>
            <tr v-for="event in events" :key="event.key"><td>{{ event.date_precision === 'month' ? new Date(`${event.date.slice(0,7)}-02`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' }) : displayDate(event.date) }}</td><td><span :class="['sales-dashboard-badge', `badge-${event.kind}`]">{{ event.kind === 'forecast' ? 'Forecast' : titleCase(event.kind) }}</span></td><td><b>{{ event.client }}</b><small>{{ event.title }}</small></td><td>{{ event.route }}</td><td>{{ event.transport_mode }}</td><td>{{ titleCase(event.status) }}</td><td><button type="button" class="sales-row-action" @click="openEvent(event)">View</button></td></tr>
          </tbody></table>
        </div>
        <footer v-if="calendarPageInfo.last_page > 1" class="sales-dashboard-pagination"><span>Page {{ calendarPageInfo.current_page }} of {{ calendarPageInfo.last_page }}</span><div><button type="button" :disabled="calendarPageInfo.current_page <= 1 || calendarBusy" @click="changeCalendarPage(calendarPageInfo.current_page - 1)">Previous</button><button type="button" :disabled="calendarPageInfo.current_page >= calendarPageInfo.last_page || calendarBusy" @click="changeCalendarPage(calendarPageInfo.current_page + 1)">Next</button></div></footer>
      </section>

      <section v-if="can('sales.availability.view')" class="ifc-panel sales-dashboard-availability">
        <header class="sales-dashboard-panel-head"><div><h2>Shipment Availability</h2><p>Only verified carrier capacity and approved internal allocations appear here.</p></div><button v-if="can('sales.availability.manage')" type="button" class="sales-dashboard-action" @click="openCapacity()">Add availability <span aria-hidden="true">＋</span></button></header>
        <div class="sales-dashboard-filters sales-availability-filters">
          <label>Search<input v-model.trim="availabilityFilters.search" type="search" placeholder="Route, source or reference"></label>
          <label>Route<input v-model.trim="availabilityFilters.route" placeholder="Origin or destination"></label>
          <label>Mode<select v-model="availabilityFilters.mode"><option value="">All modes</option><option>Sea</option><option>Air</option><option>Road</option><option>Rail</option></select></label>
          <label>Status<select v-model="availabilityFilters.status"><option value="">Current capacity</option><option value="available">Available</option><option value="fully_reserved">Fully reserved</option><option v-if="can('sales.availability.manage')" value="expired">Expired</option><option v-if="can('sales.availability.manage')" value="deactivated">Deactivated</option></select></label>
        </div>
        <div v-if="availabilityError" class="sales-dashboard-error" role="alert">{{ availabilityError }}<button type="button" @click="loadAvailability">Retry</button></div>
        <p v-else-if="availabilityBusy && !availabilityRows.length" class="sales-dashboard-loading" role="status">Loading verified availability…</p>
        <div v-else-if="!availabilityRows.length" class="sales-dashboard-empty"><b>Availability not yet confirmed</b><span>No verified capacity records match this view. Forecasts are not used to infer available slots.</span></div>
        <div v-else class="ifc-table-wrap sales-availability-table"><table><thead><tr><th>ROUTE / MODE</th><th>DEPARTURE</th><th>CAPACITY</th><th>CUTOFF</th><th>STATUS</th><th>SOURCE / VERIFIED</th><th v-if="can('sales.availability.manage')">ACTIONS</th></tr></thead><tbody>
          <tr v-for="record in availabilityRows" :key="record.id"><td><b>{{ record.origin }} → {{ record.destination }}</b><small>{{ record.transport_mode }} · {{ record.service_type }} · {{ record.reference }}</small></td><td>{{ displayDate(record.departure_date) }}</td><td><b>{{ record.available_capacity }} {{ record.capacity_unit }}</b><small>{{ record.reserved_capacity }} reserved of {{ record.confirmed_capacity }}</small></td><td>{{ displayDate(record.booking_cutoff, { dateStyle: 'medium', timeStyle: 'short' }) }}</td><td><span :class="['sales-dashboard-badge', `status-${record.availability_status}`]">{{ titleCase(record.availability_status) }}</span></td><td><b>{{ record.source_reference }}</b><small>Verified by {{ record.verifier?.name || 'authorized manager' }} · {{ displayDate(record.verified_at) }}</small></td><td v-if="can('sales.availability.manage')" class="sales-availability-actions"><button type="button" class="sales-row-action" @click="openCapacity(record)">Edit</button><button v-if="!record.deactivated_at" type="button" class="sales-row-action danger" @click="deactivate(record)">Deactivate</button></td></tr>
        </tbody></table></div>
        <footer v-if="availabilityMeta.last_page > 1" class="sales-dashboard-pagination"><span>Page {{ availabilityMeta.current_page }} of {{ availabilityMeta.last_page }} · {{ availabilityMeta.total }} records</span><div><button type="button" :disabled="availabilityPage <= 1 || availabilityBusy" @click="changeAvailabilityPage(availabilityPage - 1)">Previous</button><button type="button" :disabled="availabilityPage >= availabilityMeta.last_page || availabilityBusy" @click="changeAvailabilityPage(availabilityPage + 1)">Next</button></div></footer>
      </section>
    </template>

    <div v-if="dialog" class="sales-dashboard-overlay" @click.self="dialog = ''" @keydown.esc="dialog = ''">
      <section v-if="dialog === 'event'" class="sales-dashboard-modal" role="dialog" aria-modal="true" aria-labelledby="sales-event-title"><header><div><span class="sales-dashboard-badge" :class="`badge-${selectedEvent?.kind}`">{{ selectedEvent?.label }}</span><h2 id="sales-event-title">{{ selectedEvent?.title }}</h2></div><button type="button" aria-label="Close details" @click="dialog = ''">×</button></header><p>{{ selectedEvent?.client }} · {{ selectedEvent?.route }}</p><p v-if="detailBusy" role="status">Loading record details…</p><div v-else-if="selectedDetail?.error" class="sales-dashboard-error" role="alert">{{ selectedDetail.error }}</div><dl v-else-if="selectedDetail?.record"><template v-for="(value, key) in selectedDetail.record" :key="key"><dt v-if="['reference','job_number','status','master_status','transport_mode','mode','service_type','cargo_description','cargo','planned_departure','requested_ship_date','forecast_month','origin','destination'].includes(key)">{{ titleCase(key) }}</dt><dd v-if="['reference','job_number','status','master_status','transport_mode','mode','service_type','cargo_description','cargo','planned_departure','requested_ship_date','forecast_month','origin','destination'].includes(key)">{{ value || 'Not recorded' }}</dd></template></dl><footer><button type="button" class="sales-dashboard-secondary" @click="dialog = ''">Close</button><a v-if="selectedDetail?.type === 'shipment'" :href="`/shipments/${selectedEvent?.detail_id}`" class="sales-dashboard-action">Open shipment</a><a v-else-if="selectedDetail?.type === 'inquiry'" href="/sales/inquiries" class="sales-dashboard-action">Open inquiries</a></footer></section>
      <form v-else class="sales-dashboard-modal sales-capacity-modal" role="dialog" aria-modal="true" aria-labelledby="capacity-modal-title" @submit.prevent="saveCapacity"><header><div><h2 id="capacity-modal-title">{{ editingAvailability ? 'Edit availability' : 'Add availability' }}</h2><p>Record an externally verified carrier slot or approved internal allocation.</p></div><button type="button" aria-label="Close form" @click="dialog = ''">×</button></header>
        <div v-if="error" class="sales-dashboard-error" role="alert">{{ error }}</div><div class="sales-capacity-form-grid"><label>Origin<input v-model.trim="capacityForm.origin" required maxlength="180"></label><label>Destination<input v-model.trim="capacityForm.destination" required maxlength="180"></label><label>Transport mode<select v-model="capacityForm.transport_mode"><option>Sea</option><option>Air</option><option>Road</option><option>Rail</option></select></label><label>Service type<select v-model="capacityForm.service_type"><option>LCL</option><option>FCL</option></select></label><label>Departure date<input v-model="capacityForm.departure_date" type="date" required></label><label>Confirmed capacity<input v-model="capacityForm.confirmed_capacity" type="number" min="0.001" step="0.001" required></label><label>Reserved capacity<input v-model="capacityForm.reserved_capacity" type="number" min="0" step="0.001" required></label><label>Capacity unit<select v-model="capacityForm.capacity_unit"><option>CBM</option><option>TEU</option><option>kg</option><option value="truck_slots">Truck slots</option><option value="pallets">Pallets</option></select></label><label>Booking cutoff<input v-model="capacityForm.booking_cutoff" type="datetime-local"></label><label>Source type<select v-model="capacityForm.source_type"><option value="carrier_verified">Carrier verified</option><option value="internal_allocation">Internal allocation</option></select></label><label>Source / approval reference<input v-model.trim="capacityForm.source_reference" required maxlength="180"></label><label>Valid from<input v-model="capacityForm.valid_from" type="date" required></label><label>Valid until<input v-model="capacityForm.valid_until" type="date"></label></div>
        <p class="sales-capacity-verification">Saving records the current authorized user as verifier and creates an immutable audit-history entry. Reserved capacity cannot exceed confirmed capacity.</p><footer><button type="button" class="sales-dashboard-secondary" @click="dialog = ''">Cancel</button><button type="submit" class="sales-dashboard-action" :disabled="saving">{{ saving ? 'Saving…' : 'Save availability' }}</button></footer></form>
    </div>
  </section>
</template>
