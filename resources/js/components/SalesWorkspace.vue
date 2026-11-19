<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import LocationSelector from './LocationSelector.vue'
import { acknowledgeBusinessAction, listenForBusinessActions, pendingBusinessActions, publishBusinessActions } from '../businessActionSync.js'

const props = defineProps({ page: { type: String, required: true }, permissions: { type: Array, default: () => [] }, userId: { type: [Number, String], default: '' } })
const allowed = new Set(props.permissions)
const can = (permission) => allowed.has(permission)
const titleMap = {
  sales: ['Sales Overview', 'Manage client demand before operational shipment execution.'],
  'sales-clients': ['Client Management', 'Maintain client profiles and authorized sales ownership.'],
  'sales-inquiries': ['Client Inquiries', 'Capture route, service and cargo requirements before forecasting.'],
  'sales-calendar': ['Shipment Forecast Calendar', 'Plan expected shipment demand month by month.'],
  'sales-monthly': ['Monthly Demand Forecast', 'Review forecasted demand by month and confidence.'],
  'sales-history': ['Forecast Revision History', 'Review changes to forecast records with actor and revision detail.'],
  'accounting-forecasts': ['Accounting Forecast Review', 'Read-only view of probable and confirmed demand forecasts.'],
}
const [title, subtitle] = titleMap[props.page] || titleMap.sales
const busy = ref(false)
const error = ref('')
const sessionExpired = ref(false)
const notice = ref('')
const rows = ref([])
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const inquiryPage = ref(1)
const inquiryPagination = ref({ current_page: 1, last_page: 1, total: 0 })
const clients = ref([])
const selectedClient = ref(null)
const inquiries = ref([])
const summary = ref({ clients: 0, open_inquiries: 0, expected_shipments: 0, forecast_months: 0 })
const month = ref(new Date().toISOString().slice(0, 7))
const dialog = ref('')
const editing = ref(null)
const form = ref(blankForm())
const clientAssistantSessionId = ref(null)
const clientAiFields = ref(new Set())
const clientManuallyEditedFields = ref(new Set())
const filters = ref({ search: '', status: '' })
const clientType = ref('')
const clientPage = ref(1)
let clientSearchTimer

function blankForm() {
  return { company_name: '', contact_name: '', email: '', phone: '', billing_address: '', client_type: 'LCL', client_id: '', service_type: 'LCL', transport_mode: 'Sea', origin: '', destination: '', origin_country_code: '', origin_country_name: '', origin_location_id: '', origin_location_type: '', origin_location_code_system: '', origin_location_name: '', origin_custom_details: '', destination_country_code: '', destination_country_name: '', destination_location_id: '', destination_location_type: '', destination_location_code_system: '', destination_location_name: '', destination_custom_details: '', cargo_description: '', estimated_weight_kg: '', estimated_volume_cbm: '', estimated_container_count: '', container_type: '', commodity_category: '', special_requirements: '', requested_ship_date: '', inquiry_id: '', forecast_month: new Date().toISOString().slice(0, 7), expected_shipment_count: 1, confidence: 'tentative' }
}

function locationBinding(prefix) {
  return computed({
    get: () => ({
      country_code: form.value[`${prefix}_country_code`],
      country_name: form.value[`${prefix}_country_name`],
      location_id: form.value[`${prefix}_location_id`],
      location_type: form.value[`${prefix}_location_type`],
      code_system: form.value[`${prefix}_location_code_system`],
      location_name: form.value[`${prefix}_location_name`],
      custom_details: form.value[`${prefix}_custom_details`],
    }),
    set: (location) => {
      form.value[`${prefix}_country_code`] = location.country_code || ''
      form.value[`${prefix}_country_name`] = location.country_name || ''
      form.value[`${prefix}_location_id`] = location.location_id || ''
      form.value[`${prefix}_location_type`] = location.location_type || ''
      form.value[`${prefix}_location_code_system`] = location.code_system || ''
      form.value[`${prefix}_location_name`] = location.location_name || ''
      form.value[`${prefix}_custom_details`] = location.custom_details || ''
      const name = location.location_name || location.custom_details
      form.value[prefix] = name ? `${name}${location.country_name ? `, ${location.country_name}` : ''}` : ''
    },
  })
}
const originLocation = locationBinding('origin')
const destinationLocation = locationBinding('destination')

function changeInquiryServiceType(event) {
  form.value.service_type = event.target.value
  if (event.target.value !== 'FCL') {
    form.value.estimated_container_count = ''
    form.value.container_type = ''
  }
}

const apiPath = computed(() => {
  if (props.page === 'sales') return '/api/sales/summary'
  if (props.page === 'sales-clients') return '/api/sales/clients'
  if (props.page === 'sales-inquiries') return '/api/sales/inquiries'
  if (props.page === 'sales-calendar' || props.page === 'sales-monthly') return '/api/sales/forecasts'
  if (props.page === 'sales-history') return '/api/sales/forecasts/history'
  if (props.page === 'accounting-forecasts') return '/api/accounting/forecasts'
  return '/api/sales/inquiries'
})

async function request(url, options = {}) {
  const response = await fetch(url, { credentials: 'same-origin', ...options, headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } : {}), ...(options.headers || {}) } })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    if (response.status === 422) error.value = Object.values(payload.errors || {}).flat().join(' ')
    else if (response.status === 401) { sessionExpired.value = true; error.value = 'Your IFC sign-in session has expired.' }
    else if (response.status === 419) { sessionExpired.value = true; error.value = 'Your session or security token expired.' }
    else if (response.status === 403) { sessionExpired.value = false; error.value = 'You are signed in, but your role does not have permission to perform this action.' }
    else error.value = payload.message || `Request failed (${response.status}).`
    throw new Error(error.value)
  }
  sessionExpired.value = false
  return payload
}

async function load() {
  busy.value = true
  error.value = ''
  if (props.page === 'sales-clients') rows.value = []
  try {
    const query = new URLSearchParams()
    if (filters.value.search) query.set('search', filters.value.search)
    if (filters.value.status) query.set('status', filters.value.status)
    if (props.page === 'sales-clients') {
      query.set('page', String(clientPage.value))
      if (clientType.value) query.set('client_type', clientType.value)
    }
    if (props.page === 'sales-inquiries') {
      query.set('page', String(inquiryPage.value))
      query.set('per_page', '15')
    }
    if (props.page === 'sales-calendar' || props.page === 'sales-monthly' || props.page === 'accounting-forecasts') query.set('month', month.value)
    const payload = await request(`${apiPath.value}?${query}`)
    rows.value = payload.data || []
    if (props.page === 'sales-inquiries') {
      inquiries.value = payload.data || []
      inquiryPagination.value = { current_page: payload.current_page || 1, last_page: payload.last_page || 1, total: payload.total || 0 }
    }
    if (props.page === 'sales-clients') pagination.value = payload.meta || {
      current_page: payload.current_page || 1,
      last_page: payload.last_page || 1,
      total: payload.total || 0,
    }
    if (props.page === 'sales') summary.value = payload.data || summary.value
    if (props.page === 'sales-inquiries' || props.page === 'sales-calendar' || props.page === 'sales-monthly' || props.page === 'sales') {
      const inquiryQuery = new URLSearchParams()
      if (filters.value.search) inquiryQuery.set('search', filters.value.search)
      if (filters.value.status) inquiryQuery.set('status', filters.value.status)
      if (props.page === 'sales') { inquiryQuery.set('page', String(inquiryPage.value)); inquiryQuery.set('per_page', '15') }
      const [clientResponse, inquiryResponse] = await Promise.all([
        can('clients.view') ? request('/api/sales/clients') : Promise.resolve({ data: [] }),
        can('inquiries.view') && props.page !== 'sales-inquiries' ? request(`/api/sales/inquiries?${inquiryQuery}`) : Promise.resolve({ data: props.page === 'sales-inquiries' ? inquiries.value : [] }),
      ])
      clients.value = clientResponse.data || []
      if (props.page !== 'sales-inquiries') inquiries.value = inquiryResponse.data || []
      if (props.page === 'sales') rows.value = inquiryResponse.data || []
    }
    return true
  } catch { /* error is displayed above */ return false } finally { busy.value = false }
}

function queueClientSearch() {
  clientPage.value = 1
  window.clearTimeout(clientSearchTimer)
  clientSearchTimer = window.setTimeout(load, 250)
}

function changeClientPage(nextPage) {
  if (nextPage < 1 || nextPage > pagination.value.last_page) return
  clientPage.value = nextPage
  load()
}

function queueInquirySearch() {
  inquiryPage.value = 1
  window.clearTimeout(clientSearchTimer)
  clientSearchTimer = window.setTimeout(load, 250)
}

function changeInquiryPage(nextPage) {
  if (nextPage < 1 || nextPage > inquiryPagination.value.last_page) return
  inquiryPage.value = nextPage
  load()
}

async function refreshForAssistantAction(event) {
  if (!await load()) throw new Error('Sales data refresh failed; leave the invalidation pending.')
  const inquiry = event.actions.find(action => action.entity_type === 'sales_inquiry')
  if (inquiry && props.page === 'sales-inquiries') {
    const isVisible = inquiries.value.some(row => Number(row.id) === Number(inquiry.entity_id))
    notice.value = isVisible
      ? `Sales Inquiry ${inquiry.reference_number} is visible in the refreshed list.`
      : `Sales Inquiry ${inquiry.reference_number} was saved, but may be outside this page or hidden by the current filters.`
  }
  const client = event.actions.find(action => action.entity_type === 'client')
  if (client && props.page === 'sales-clients') notice.value = `Client records refreshed after ${client.reference_number || 'the client record'} was created.`
}

async function showAssistantInquiry(event) {
  if (props.page !== 'sales-inquiries' || !event?.detail?.reference_number) return
  filters.value.search = event.detail.reference_number
  filters.value.status = ''
  inquiryPage.value = 1
  await load()
  notice.value = `Showing Sales Inquiry ${event.detail.reference_number}.`
}

async function viewClient(client) {
  busy.value = true
  error.value = ''
  try {
    selectedClient.value = (await request(`/api/sales/clients/${client.id}`)).data
    editing.value = client
    dialog.value = 'client-view'
  } catch { /* error is shown above */ } finally { busy.value = false }
}

function openDialog(kind, row = null) {
  editing.value = row
  form.value = { ...blankForm(), ...(row || {}) }
  clientAssistantSessionId.value = null
  clientAiFields.value = new Set()
  clientManuallyEditedFields.value = new Set()
  if (kind === 'inquiry' && row) form.value.client_id = row.client_id || row.client?.id || ''
  if (kind === 'forecast' && row) { form.value.inquiry_id = row.inquiry_id; form.value.forecast_month = row.forecast_month?.slice(0, 7) || form.value.forecast_month }
  dialog.value = kind
  error.value = ''
}

function applyAssistantClientDraft(event) {
  if (props.page !== 'sales-clients' || !event?.detail) return
  const { session_id: sessionId, values = {}, source_fields: sourceFields = [], open = false } = event.detail
  if (open && dialog.value !== 'client') openDialog('client')
  if (dialog.value !== 'client') return
  clientAssistantSessionId.value = sessionId
  const allowed = ['company_name', 'contact_name', 'email', 'phone', 'billing_address', 'client_type']
  for (const field of allowed) {
    if (!sourceFields.includes(field) || values[field] === undefined) continue
    if (!clientManuallyEditedFields.value.has(field) || clientAiFields.value.has(field)) {
      form.value[field] = values[field]
      clientAiFields.value.add(field)
    }
  }
}

function markClientFieldManual(field) {
  const fields = new Set(clientAiFields.value)
  fields.delete(field)
  clientAiFields.value = fields
  clientManuallyEditedFields.value = new Set([...clientManuallyEditedFields.value, field])
}

function assistantClientFormSaved(event) {
  if (props.page !== 'sales-clients' || !event?.detail) return
  clientAssistantSessionId.value = null
  dialog.value = ''
  notice.value = `Client ${event.detail.reference || event.detail.company_name} was saved from the IFC Assistant form.`
}

async function save() {
  const kind = dialog.value
  if (kind === 'client' && clientAssistantSessionId.value) {
    window.dispatchEvent(new CustomEvent('ifc:assistant-client-form-save', { detail: { session_id: clientAssistantSessionId.value, values: Object.fromEntries(['company_name', 'contact_name', 'email', 'phone', 'billing_address', 'client_type'].map(key => [key, form.value[key] || ''])) } }))
    return
  }
  let url = kind === 'client' ? '/api/sales/clients' : kind === 'inquiry' ? '/api/sales/inquiries' : '/api/sales/forecasts'
  let method = 'POST'
  let body = { ...form.value }
  if (kind === 'forecast') body.forecast_month = `${body.forecast_month}-01`
  if (kind === 'client' && editing.value) { url += `/${editing.value.id}`; method = 'PUT' }
  if (kind === 'inquiry' && editing.value) { url += `/${editing.value.id}`; method = 'PUT' }
  if (kind === 'forecast' && editing.value) { url += `/${editing.value.id}`; method = 'PUT'; delete body.inquiry_id }
  if (kind === 'client') body = Object.fromEntries(Object.entries(body).filter(([key]) => ['company_name', 'contact_name', 'email', 'phone', 'billing_address', 'client_type'].includes(key)))
  if (kind === 'inquiry') {
    for (const [prefix, label] of [['origin', 'Origin'], ['destination', 'Destination']]) {
      const location = form.value[`${prefix}_location_id`]
      const custom = form.value[`${prefix}_custom_details`]?.trim()
      const country = form.value[`${prefix}_country_code`]
      if (!/^[A-Z]{2}$/.test(country || '') || (!location && !custom)) {
        error.value = `${label}: choose a country and a listed location, or specify a location.`
        return
      }
      if (form.value[`${prefix}_country_code`] === '__OTHER__' && !form.value[`${prefix}_country_name`]) {
        error.value = `${label}: enter the country name.`
        return
      }
    }
    body = Object.fromEntries(Object.entries(body).filter(([key]) => ['client_id', 'service_type', 'transport_mode', 'origin', 'destination', 'origin_country_code', 'origin_country_name', 'origin_location_id', 'origin_location_type', 'origin_location_code_system', 'origin_location_name', 'origin_custom_details', 'destination_country_code', 'destination_country_name', 'destination_location_id', 'destination_location_type', 'destination_location_code_system', 'destination_location_name', 'destination_custom_details', 'cargo_description', 'estimated_weight_kg', 'estimated_volume_cbm', 'estimated_container_count', 'container_type', 'commodity_category', 'special_requirements', 'requested_ship_date'].includes(key)))
  }
  if (kind === 'forecast') body = Object.fromEntries(Object.entries(body).filter(([key]) => ['inquiry_id', 'forecast_month', 'expected_shipment_count', 'confidence'].includes(key)))
  try {
    const payload = await request(url, { method, body: JSON.stringify(body) })
    notice.value = payload.message || 'Changes saved.'
    if (kind === 'forecast' && !editing.value && payload.data?.id) publishBusinessActions([{ action: 'shipment_forecast.created', operation_status: 'success', entity_type: 'shipment_forecast', entity_id: payload.data.id, reference_number: `Forecast #${payload.data.id}` }], props.userId)
    dialog.value = ''
    await load()
  } catch { /* validation feedback displayed above */ }
}

async function submitInquiry(row) {
  try {
    const payload = await request(`/api/sales/inquiries/${row.id}/submit`, { method: 'POST', body: '{}' })
    notice.value = 'Inquiry submitted. You can create its shipment forecast now.'
    publishBusinessActions([{ action: 'sales_inquiry.updated', operation_status: 'success', entity_type: 'sales_inquiry', entity_id: row.id, reference_number: payload.data?.reference || row.reference }], props.userId)
    await load()
    window.dispatchEvent(new CustomEvent('ifc:inquiry-submitted', { detail: { id: row.id } }))
  } catch { /* error shown */ }
}

async function withdrawForecast(row) {
  if (!window.confirm('Withdraw this forecast? It will remain in history.')) return
  try { await request(`/api/sales/forecasts/${row.id}/withdraw`, { method: 'POST', body: '{}' }); notice.value = 'Forecast withdrawn.'; await load() } catch { /* error shown */ }
}

const count = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.expected_shipment_count) || 0), 0))
const monthRows = computed(() => rows.value.filter(row => String(row.forecast_month || row.month || '').startsWith(month.value)))
const displayedRows = computed(() => props.page === 'sales-calendar' || props.page === 'sales-monthly' ? monthRows.value : rows.value)
const groupedMonths = computed(() => Object.groupBy ? Object.groupBy(rows.value, row => String(row.forecast_month || '').slice(0, 7)) : rows.value.reduce((all, row) => { const key = String(row.forecast_month || '').slice(0, 7); (all[key] ||= []).push(row); return all }, {}))

const syncModule = ['sales-inquiries', 'sales-clients', 'sales', 'sales-calendar', 'sales-monthly', 'sales-history', 'accounting-forecasts'].includes(props.page) ? props.page : null
const stopActionSync = syncModule ? listenForBusinessActions(props.userId, syncModule, refreshForAssistantAction) : () => {}
onBeforeUnmount(stopActionSync)
window.addEventListener('ifc:view-inquiry', showAssistantInquiry)
onBeforeUnmount(() => window.removeEventListener('ifc:view-inquiry', showAssistantInquiry))
window.addEventListener('ifc:assistant-client-form', applyAssistantClientDraft)
window.addEventListener('ifc:assistant-client-form-saved', assistantClientFormSaved)
onBeforeUnmount(() => window.removeEventListener('ifc:assistant-client-form', applyAssistantClientDraft))
onBeforeUnmount(() => window.removeEventListener('ifc:assistant-client-form-saved', assistantClientFormSaved))
onMounted(async () => {
  const pendingSyncs = syncModule ? pendingBusinessActions(props.userId, syncModule) : []
  const query = new URLSearchParams(window.location.search)
  const highlight = props.page === 'sales-inquiries' ? query.get('highlight') : null
  const validId = value => /^\d+$/.test(value || '') && Number.isSafeInteger(Number(value)) && Number(value) > 0
  const editId = props.page === 'sales-inquiries' && can('inquiries.update') && validId(query.get('edit')) ? query.get('edit') : null
  const inquiryClientId = props.page === 'sales-inquiries' && can('inquiries.create') && validId(query.get('client_id')) ? query.get('client_id') : null
  const clientId = props.page === 'sales-clients' && can('clients.view') && validId(query.get('client_id')) ? query.get('client_id') : null
  const forecastInquiryId = ['sales-calendar', 'sales-monthly'].includes(props.page) && validId(query.get('inquiry_id')) ? query.get('inquiry_id') : null
  if (highlight) {
    filters.value.search = highlight
    inquiryPage.value = 1
  }
  const refreshed = await load()
  if (refreshed && syncModule) pendingSyncs.forEach(event => acknowledgeBusinessAction(event.id, props.userId, syncModule))
  if (highlight) notice.value = `Showing Sales Inquiry ${highlight}.`
  if (refreshed && editId) {
    const inquiry = inquiries.value.find(row => String(row.id) === String(editId))
    if (inquiry?.status === 'draft') openDialog('inquiry', inquiry)
    else notice.value = 'This inquiry is no longer an editable draft.'
  }
  if (refreshed && forecastInquiryId && can('forecasts.create') && can('inquiries.view')) {
    try {
      const { data: inquiry } = await request(`/api/sales/inquiries/${encodeURIComponent(forecastInquiryId)}`)
      if (['submitted', 'under_review', 'forecasted'].includes(inquiry.status)) {
        if (!inquiries.value.some(row => row.id === inquiry.id)) inquiries.value = [inquiry, ...inquiries.value]
        openDialog('forecast', { inquiry_id: inquiry.id, forecast_month: inquiry.requested_ship_date?.slice(0, 7) })
      }
      else notice.value = 'This inquiry is not currently eligible for shipment forecasting.'
    } catch { /* the authorized inquiry lookup reports its error */ }
  }
  if (refreshed && inquiryClientId) {
    const client = clients.value.find(row => String(row.id) === String(inquiryClientId))
    if (client) openDialog('inquiry', { client_id: client.id })
    else notice.value = 'The client could not be selected for a new inquiry.'
  }
  if (refreshed && clientId) {
    const client = rows.value.find(row => String(row.id) === String(clientId))
    if (client) await viewClient(client)
    else notice.value = 'The saved client is outside the current authorized list.'
  }
})
</script>

<template>
  <section class="sales-workspace">
    <div class="sales-heading"><div><div class="ifc-kicker">INTERFREIGHT GROUP / PHASE 2</div><h1>{{ title }}</h1><p>{{ subtitle }}</p></div><div class="sales-heading-actions"><a v-if="page.startsWith('sales')" href="/sales/clients" class="sales-secondary">Clients</a><button v-if="page === 'sales-clients' && can('clients.create')" class="ifc-primary" @click="openDialog('client')">+ Add client</button><button v-if="page === 'sales-inquiries' && can('inquiries.create')" class="ifc-primary" @click="openDialog('inquiry')">+ New inquiry</button><button v-if="(page === 'sales-calendar' || page === 'sales-monthly') && can('forecasts.create')" class="ifc-primary" :disabled="!inquiries.length" @click="openDialog('forecast')">+ Add forecast</button></div></div>
    <div v-if="error" class="sales-alert" role="alert"><span>{{ error }}</span><a v-if="sessionExpired" href="/login">Sign in again</a><button v-else @click="load">Retry</button></div><div v-if="notice" class="sales-notice" role="status">{{ notice }}<button aria-label="Dismiss" @click="notice = ''">×</button></div>
    <div v-if="page === 'sales' && !sessionExpired" class="sales-metrics"><article><span>OPEN INQUIRIES</span><b>{{ summary.open_inquiries }}</b><small>Sales records assigned to you</small></article><article><span>FORECASTED SHIPMENTS</span><b>{{ summary.expected_shipments }}</b><small>Across {{ summary.forecast_months }} active planning months</small></article><article><span>CLIENT PROFILES</span><b>{{ summary.clients }}</b><small>Owned client records</small></article><article><span>PLANNING STATUS</span><b>Forecast first</b><small>Demand is not an operational shipment</small></article></div>
    <div v-if="page === 'sales' && !sessionExpired" class="sales-quick-links"><a href="/sales/clients">Manage clients <span>→</span></a><a href="/sales/inquiries">Open inquiries <span>→</span></a><a href="/sales/forecasts/calendar">Open forecast calendar <span>→</span></a></div>
    <section v-if="page === 'sales-clients'" class="ifc-panel sales-panel"><div class="sales-toolbar sales-client-filters"><input v-model="filters.search" placeholder="Search name or reference" aria-label="Search clients" @input="queueClientSearch"><select v-model="clientType" aria-label="Filter by client type" @change="clientPage = 1; load()"><option value="">All client types</option><option value="LCL">LCL</option><option value="FCL">FCL</option><option value="Mixed">Mixed</option></select><span v-if="error">Client count unavailable</span><span v-else-if="busy && !pagination.total">Loading client list…</span><span v-else>{{ pagination.total }} client{{ pagination.total === 1 ? '' : 's' }} found</span></div><div class="ifc-table-wrap"><table><thead><tr><th>CLIENT</th><th>REFERENCE</th><th>CONTACT</th><th>TYPE</th><th>INQUIRIES</th><th>ACTIONS</th></tr></thead><tbody><tr v-for="client in rows" :key="client.id"><td><b>{{ client.company_name }}</b></td><td>{{ client.reference || 'Pending' }}</td><td>{{ client.contact_name || '—' }}<small>{{ client.email || '' }}</small></td><td><span class="sales-badge">{{ client.client_type }}</span></td><td>{{ client.inquiries_count ?? '—' }}</td><td class="sales-row-actions"><button @click="viewClient(client)">View</button><button v-if="can('clients.update')" @click="openDialog('client', client)">Edit</button></td></tr><tr v-if="busy && !rows.length"><td colspan="6" class="ifc-empty">Loading clients…</td></tr><tr v-else-if="error && !rows.length"><td colspan="6" class="ifc-empty">Client records could not be loaded. Review the message above.</td></tr><tr v-else-if="!rows.length"><td colspan="6" class="ifc-empty">No client records match the current filters.</td></tr></tbody></table></div><footer v-if="pagination.last_page > 1 && !error" class="sales-pagination"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div><button :disabled="pagination.current_page <= 1 || busy" @click="changeClientPage(pagination.current_page - 1)">Previous</button><button :disabled="pagination.current_page >= pagination.last_page || busy" @click="changeClientPage(pagination.current_page + 1)">Next</button></div></footer></section>
    <section v-if="(page === 'sales-inquiries' || page === 'sales') && !sessionExpired" class="ifc-panel sales-panel"><div class="sales-toolbar"><input v-model="filters.search" placeholder="Search client, route or inquiry" aria-label="Search inquiries" @input="queueInquirySearch"><select v-model="filters.status" aria-label="Filter by status" @change="inquiryPage = 1; load()"><option value="">All statuses</option><option>draft</option><option>submitted</option><option value="under_review">under review (historical)</option><option>forecasted</option><option>withdrawn</option><option>cancelled</option></select><span>{{ page === 'sales-inquiries' ? inquiryPagination.total : inquiries.length }} inquiries</span></div><div class="ifc-table-wrap"><table><thead><tr><th>INQUIRY</th><th>CLIENT</th><th>SERVICE</th><th>ROUTE</th><th>STATUS</th><th>ACTIONS</th></tr></thead><tbody><tr v-for="item in inquiries" :key="item.id"><td><b>{{ item.reference || 'Pending' }}</b><small>{{ item.cargo_description }}</small></td><td>{{ item.client?.company_name }}</td><td>{{ item.service_type }} · {{ item.transport_mode }}</td><td>{{ item.origin }} → {{ item.destination }}</td><td><span class="sales-badge" :class="`status-${item.status}`">{{ item.status.replaceAll('_', ' ') }}</span></td><td class="sales-row-actions"><button v-if="item.status === 'draft' && can('inquiries.update')" @click="openDialog('inquiry', item)">Edit</button><button v-if="item.status === 'draft' && can('inquiries.submit')" @click="submitInquiry(item)">Submit</button><button v-if="['submitted', 'under_review', 'forecasted'].includes(item.status) && can('forecasts.create')" @click="openDialog('forecast', { inquiry_id: item.id, forecast_month: item.requested_ship_date?.slice(0, 7) })">Add forecast</button></td></tr><tr v-if="!inquiries.length"><td colspan="6" class="ifc-empty">{{ busy ? 'Loading inquiries…' : 'No inquiries match your filters.' }}</td></tr></tbody></table></div><footer v-if="page === 'sales-inquiries' && inquiryPagination.last_page > 1" class="sales-pagination"><span>Page {{ inquiryPagination.current_page }} of {{ inquiryPagination.last_page }} · {{ inquiryPagination.total }} inquiries</span><div><button :disabled="inquiryPagination.current_page <= 1 || busy" @click="changeInquiryPage(inquiryPagination.current_page - 1)">Previous</button><button :disabled="inquiryPagination.current_page >= inquiryPagination.last_page || busy" @click="changeInquiryPage(inquiryPagination.current_page + 1)">Next</button></div></footer></section>
    <section v-if="page === 'sales-calendar' || page === 'sales-monthly'" class="ifc-panel sales-panel"><div class="sales-toolbar"><label>Forecast month <input v-model="month" type="month" @change="load"></label><span>{{ count }} expected shipment units in listed rows</span></div><div class="sales-forecast-grid"><article v-for="item in displayedRows" :key="item.id" class="sales-forecast-card"><div><b>{{ item.inquiry?.client?.company_name || `Inquiry #${item.inquiry_id}` }}</b><span class="sales-badge" :class="`status-${item.confidence}`">{{ item.confidence }}</span></div><p>{{ item.inquiry?.origin }} → {{ item.inquiry?.destination }}</p><strong>{{ item.expected_shipment_count }} expected</strong><small>{{ item.forecast_month }} · {{ item.inquiry?.transport_mode }} {{ item.inquiry?.service_type }}</small><div class="sales-row-actions"><button v-if="can('forecasts.update')" @click="openDialog('forecast', item)">Edit</button><button v-if="item.status === 'active' && can('forecasts.withdraw')" @click="withdrawForecast(item)">Withdraw</button></div></article><div v-if="!displayedRows.length" class="sales-empty">{{ busy ? 'Loading forecast…' : 'No forecast rows are recorded for this period.' }}</div></div></section>
    <section v-if="page === 'sales-history'" class="ifc-panel sales-panel"><div class="ifc-table-wrap"><table><thead><tr><th>CHANGED</th><th>FORECAST</th><th>REVISION</th><th>ACTION</th><th>ACTOR</th><th>DETAIL</th></tr></thead><tbody><tr v-for="revision in rows" :key="revision.id"><td>{{ new Date(revision.created_at).toLocaleString() }}</td><td>#{{ revision.forecast_id }}</td><td>{{ revision.revision }}</td><td>{{ revision.action }}</td><td>{{ revision.actor?.name || 'System' }}</td><td>{{ JSON.stringify(revision.after_values || {}) }}</td></tr><tr v-if="!rows.length"><td colspan="6" class="ifc-empty">{{ busy ? 'Loading history…' : 'No forecast changes have been recorded.' }}</td></tr></tbody></table></div></section>
    <section v-if="page === 'accounting-forecasts'" class="ifc-panel sales-panel"><div class="sales-toolbar"><label>Month <input v-model="month" type="month" @change="load"></label><span>Read-only · probable and confirmed demand only</span></div><div class="ifc-table-wrap"><table><thead><tr><th>PERIOD</th><th>INQUIRY</th><th>CLIENT</th><th>SERVICE</th><th>ROUTE</th><th>DEMAND</th><th>COST ESTIMATE</th></tr></thead><tbody><tr v-for="item in rows" :key="item.reference + item.month"><td>{{ item.month }}</td><td>{{ item.reference }}</td><td>{{ item.client }}</td><td>{{ item.service_type }} · {{ item.transport_mode }}</td><td>{{ item.origin }} → {{ item.destination }}</td><td>{{ item.expected_shipments }} · {{ item.confidence }}</td><td>Not yet estimated<br><small>No fee or cash advance amounts recorded</small></td></tr><tr v-if="!rows.length"><td colspan="7" class="ifc-empty">{{ busy ? 'Loading forecasts…' : 'No probable or confirmed forecasts for this month.' }}</td></tr></tbody></table></div></section>
    <section v-if="(page === 'sales-calendar' || page === 'sales-monthly') && Object.keys(groupedMonths).length" class="sales-month-summary"><article v-for="(entries, key) in groupedMonths" :key="key"><span>{{ key }}</span><b>{{ entries.reduce((sum, row) => sum + Number(row.expected_shipment_count || 0), 0) }}</b><small>expected shipments</small></article></section>
    <div v-if="dialog" class="ifc-overlay" @click.self="dialog = ''" @keydown.esc="dialog = ''"><form :class="['ifc-modal', 'sales-modal', { 'sales-inquiry-modal': dialog === 'inquiry' }]" role="dialog" aria-modal="true" aria-labelledby="sales-dialog-title" @submit.prevent="save"><div class="ifc-panel-head"><div><h2 id="sales-dialog-title">{{ dialog === 'client-view' ? 'Client details' : `${editing ? 'Edit' : 'Add'} ${dialog === 'client' ? 'client' : dialog === 'inquiry' ? 'inquiry' : 'forecast'}` }}</h2><p>{{ dialog === 'client-view' ? selectedClient?.reference || 'Client profile' : dialog === 'inquiry' ? 'Capture the route and cargo requirements for this client inquiry.' : 'Forecast records describe expected demand and do not create operational shipments.' }}</p></div><button type="button" class="ifc-close" aria-label="Close dialog" @click="dialog = ''">×</button></div>
      <div v-if="dialog === 'client-view' && selectedClient" class="sales-client-details"><div><span>Company</span><b>{{ selectedClient.company_name }}</b></div><div><span>Reference</span><b>{{ selectedClient.reference || 'Pending' }}</b></div><div><span>Contact</span><b>{{ selectedClient.contact_name || 'Not provided' }}</b></div><div><span>Email</span><b>{{ selectedClient.email || 'Not provided' }}</b></div><div><span>Phone</span><b>{{ selectedClient.phone || 'Not provided' }}</b></div><div><span>Client type</span><b>{{ selectedClient.client_type }}</b></div><div><span>Billing address</span><b>{{ selectedClient.billing_address || 'Not provided' }}</b></div><div><span>Inquiries</span><b>{{ selectedClient.inquiries_count }}</b></div><div class="sales-dialog-actions"><button type="button" class="sales-secondary" @click="dialog = ''">Close</button><button v-if="can('clients.update')" type="button" class="ifc-primary" @click="openDialog('client', selectedClient)">Edit client</button></div></div>
      <template v-else>
      <template v-if="dialog === 'client'"><label :class="{ 'assistant-filled-field': clientAiFields.has('company_name') }">Company name<input v-model="form.company_name" maxlength="180" required @input="markClientFieldManual('company_name')"><small v-if="clientAiFields.has('company_name')">AI filled · please review</small></label><div class="sales-form-row"><label :class="{ 'assistant-filled-field': clientAiFields.has('contact_name') }">Contact name<input v-model="form.contact_name" maxlength="120" @input="markClientFieldManual('contact_name')"><small v-if="clientAiFields.has('contact_name')">AI filled · please review</small></label><label :class="{ 'assistant-filled-field': clientAiFields.has('client_type') }">Client type<select v-model="form.client_type" @change="markClientFieldManual('client_type')"><option>LCL</option><option>FCL</option><option>Mixed</option></select><small v-if="clientAiFields.has('client_type')">AI filled · please review</small></label></div><div class="sales-form-row"><label :class="{ 'assistant-filled-field': clientAiFields.has('email') }">Email<input v-model="form.email" type="email" maxlength="254" @input="markClientFieldManual('email')"><small v-if="clientAiFields.has('email')">AI filled · please review</small></label><label :class="{ 'assistant-filled-field': clientAiFields.has('phone') }">Phone<input v-model="form.phone" maxlength="50" @input="markClientFieldManual('phone')"><small v-if="clientAiFields.has('phone')">AI filled · please review</small></label></div><label :class="{ 'assistant-filled-field': clientAiFields.has('billing_address') }">Billing address<textarea v-model="form.billing_address" maxlength="2000" @input="markClientFieldManual('billing_address')"></textarea><small v-if="clientAiFields.has('billing_address')">AI filled · please review</small></label></template>
      <template v-else-if="dialog === 'inquiry'">
        <label>Client<select v-model="form.client_id" required><option value="" disabled>Select client</option><option v-for="client in clients" :key="client.id" :value="client.id">{{ client.company_name }}</option></select></label>
        <div class="sales-form-row sales-inquiry-fields"><label>Service type<select :value="form.service_type" @change="changeInquiryServiceType"><option>LCL</option><option>FCL</option></select></label><label>Transport mode<select v-model="form.transport_mode"><option>Sea</option><option>Air</option><option>Road</option><option>Rail</option></select></label></div>
        <div class="sales-route-caption"><span>ROUTE LOCATIONS</span><small>Origin and destination may be in different countries.</small></div>
        <div class="sales-route-grid"><LocationSelector v-model="originLocation" label="Origin" :mode="form.transport_mode" :error="error.includes('Origin:') ? error : ''"/><span class="sales-route-connector" aria-hidden="true">→</span><LocationSelector v-model="destinationLocation" label="Destination" :mode="form.transport_mode" :error="error.includes('Destination:') ? error : ''"/></div>
        <label>Cargo description<input v-model="form.cargo_description" required maxlength="220" placeholder="Goods, packaging or handling summary"></label>
        <div class="sales-form-row sales-inquiry-fields"><label>Weight (kg)<input v-model="form.estimated_weight_kg" type="number" min="0" step="0.01"></label><label>Volume (CBM)<input v-model="form.estimated_volume_cbm" type="number" min="0" step="0.001"></label></div>
        <div v-if="form.service_type === 'FCL'" class="sales-form-row sales-inquiry-fields"><label>Container count<input v-model="form.estimated_container_count" type="number" min="1" max="65535"></label><label>Container type<select v-model="form.container_type"><option value="">Select container type</option><option>20GP</option><option>40GP</option><option>40HC</option><option>45HC</option><option>20RF</option><option>40RF</option><option>Other</option></select></label></div>
        <div class="sales-form-row sales-inquiry-fields"><label>Commodity category<input v-model="form.commodity_category" maxlength="100"></label><label>Requested ship date<input v-model="form.requested_ship_date" type="date"></label></div>
        <label>Special requirements<textarea v-model="form.special_requirements" maxlength="4000" placeholder="Handling, delivery or documentation requirements"></textarea></label>
      </template>
      <template v-else><label>Inquiry<select v-model="form.inquiry_id" required><option value="" disabled>Select submitted inquiry</option><option v-for="item in inquiries.filter(i => ['submitted', 'under_review', 'forecasted'].includes(i.status))" :key="item.id" :value="item.id">{{ item.reference }} · {{ item.client?.company_name }} ({{ item.origin }} → {{ item.destination }})</option></select></label><div class="sales-form-row"><label>Forecast month<input v-model="form.forecast_month" type="month" required></label><label>Expected shipments<input v-model="form.expected_shipment_count" type="number" min="1" max="10000" required></label></div><label>Confidence<select v-model="form.confidence"><option value="tentative">Tentative</option><option value="probable">Probable</option><option value="confirmed">Confirmed</option></select></label></template>
      <div class="sales-dialog-actions"><button type="button" class="sales-secondary" @click="dialog = ''">Cancel</button><button class="ifc-primary" :disabled="busy">Save</button></div></template></form></div>
  </section>
</template>
