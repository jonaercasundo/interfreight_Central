<script setup>
import { computed, onMounted, ref } from 'vue'

const props = defineProps({ identity: { type: Object, required: true } })
const rows = ref([])
const selectedId = ref(null)
const detail = ref(null)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const search = ref('')
const notes = ref('')
const comment = ref('')
const reason = ref('')
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const permissions = computed(() => new Set(props.identity.permissions || []))
const can = (name) => permissions.value.has(name)
const selected = computed(() => detail.value?.shipment || null)
const summary = computed(() => ({
  shipments: rows.value.length,
  operations: rows.value.filter(row => row.milestones.operations.status === 'completed').length,
  billing: rows.value.filter(row => row.milestones.billing.status === 'completed').length,
  closed: rows.value.filter(row => row.closing.status === 'financially_closed').length,
}))
const dimensions = [
  ['operations', 'Operational completion'],
  ['billing', 'Billing completion'],
  ['collections', 'Collection completion'],
  ['expense_reconciliation', 'Expense reconciliation'],
]

async function request(url, options = {}) {
  const response = await fetch(url, {
    ...options,
    headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } : {}), ...options.headers },
  })
  const body = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(body.message || `Request failed (${response.status}).`)
  return body.data
}

async function load(id = selectedId.value, page = 1) {
  loading.value = true
  error.value = ''
  try {
    const data = await request(`/api/financial-closing/shipments?per_page=50&page=${page}` + (search.value ? '&search=' + encodeURIComponent(search.value) : ''))
    rows.value = data.data || []
    pagination.value = { current_page: data.current_page || 1, last_page: data.last_page || 1, total: data.total || 0 }
    selectedId.value = id && rows.value.some(row => row.shipment.id === id) ? id : rows.value[0]?.shipment.id || null
    detail.value = selectedId.value ? await request(`/api/financial-closing/shipments/${selectedId.value}`) : null
  } catch (exception) { error.value = exception.message } finally { loading.value = false }
}

function goPage(page) { load(null, page) }

async function select(row) {
  selectedId.value = row.shipment.id
  error.value = ''
  try { detail.value = await request(`/api/financial-closing/shipments/${selectedId.value}`) } catch (exception) { error.value = exception.message }
}

async function action(path, payload) {
  if (!selectedId.value) return
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    await request(`/api/financial-closing/shipments/${selectedId.value}/${path}`, { method: 'POST', body: JSON.stringify(payload) })
    notice.value = 'Update saved.'
    notes.value = ''
    comment.value = ''
    reason.value = ''
    await load(selectedId.value)
  } catch (exception) { error.value = exception.message } finally { saving.value = false }
}

async function verify(dimension, current) {
  await action(`milestones/${dimension}/verify`, { notes: notes.value || null, lock_version: current.lock_version || 1 })
}

function formatMoney(value, currency) {
  if (value === null || value === undefined) return 'Not determinable'
  const [whole, fraction = '00'] = String(value).split('.')
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `${currency || ''} ${grouped}.${fraction.padEnd(2, '0').slice(0, 2)}`.trim()
}

onMounted(() => load(null))
</script>

<template>
  <div class="closing-page">
    <header class="closing-heading">
      <div><div class="closing-eyebrow">FINANCE / SHIPMENT CONTROLS</div><h1>Shipment Closing</h1><p>Review independent shipment completion, reconciliation evidence and closing blockers.</p></div>
      <a class="closing-link" href="/shipments">Shipment Register</a>
    </header>

    <div v-if="error" class="closing-alert error" role="alert">{{ error }}</div>
    <div v-if="notice" class="closing-alert success" role="status">{{ notice }}</div>

    <section class="closing-kpis" aria-label="Shipment closing summary">
      <article><span>MASTER SHIPMENTS</span><b>{{ summary.shipments }}</b><small>Current page</small></article>
      <article><span>OPERATIONS VERIFIED</span><b>{{ summary.operations }}</b><small>Independent milestone</small></article>
      <article><span>BILLING VERIFIED</span><b>{{ summary.billing }}</b><small>Separate from collection</small></article>
      <article><span>FINANCIALLY CLOSED</span><b>{{ summary.closed }}</b><small>Immutable snapshot recorded</small></article>
    </section>

    <div class="closing-layout">
      <section class="closing-panel closing-register">
        <div class="closing-panel-head"><div><h2>Completion register</h2><p>One row per master shipment</p></div><form @submit.prevent="load(null)"><input v-model="search" aria-label="Search shipments" placeholder="Search job or client"><button type="submit">Search</button></form></div>
        <div class="closing-table-scroll"><table><thead><tr><th>Job / Client</th><th>Operations</th><th>Billing</th><th>Collections</th><th>Closing</th></tr></thead><tbody>
          <tr v-for="row in rows" :key="row.shipment.id" :class="{ selected: selectedId === row.shipment.id }" @click="select(row)"><td><b>{{ row.shipment.job_number }}</b><small>{{ row.shipment.client || 'Client not linked' }}</small></td><td><span :class="['closing-badge', row.milestones.operations.status]">{{ row.milestones.operations.status.replaceAll('_', ' ') }}</span></td><td><span :class="['closing-badge', row.milestones.billing.status]">{{ row.milestones.billing.status.replaceAll('_', ' ') }}</span></td><td><span :class="['closing-badge', row.milestones.collections.status]">{{ row.milestones.collections.status.replaceAll('_', ' ') }}</span></td><td><span :class="['closing-badge', row.closing.status]">{{ row.closing.status.replaceAll('_', ' ') }}</span></td></tr>
          <tr v-if="loading"><td colspan="5" class="closing-empty">Loading shipment records…</td></tr><tr v-else-if="!rows.length"><td colspan="5" class="closing-empty">No master shipments match this search.</td></tr>
        </tbody></table></div><div class="closing-pagination"><span>{{ pagination.total }} master shipments · page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div><button :disabled="loading || pagination.current_page <= 1" @click="goPage(pagination.current_page - 1)">Previous</button><button :disabled="loading || pagination.current_page >= pagination.last_page" @click="goPage(pagination.current_page + 1)">Next</button></div></div>
      </section>

      <section v-if="detail" class="closing-panel closing-detail">
        <div class="closing-panel-head"><div><div class="closing-eyebrow">{{ selected.job_number }}</div><h2>{{ selected.client || 'Shipment details' }}</h2><p>{{ selected.service || 'Service not recorded' }} · {{ selected.mode || 'Mode not recorded' }} · {{ selected.currency || 'Currency not recorded' }}</p></div><a :href="`/shipments/${selected.id}`" class="closing-link">Open job</a></div>

        <div class="closing-dimensions"><article v-for="[key, label] in dimensions" :key="key" class="closing-dimension"><div><h3>{{ label }}</h3><span :class="['closing-badge', detail.milestones[key].status]">{{ detail.milestones[key].status.replaceAll('_', ' ') }}</span></div><ul v-if="detail.milestones[key].blockers.length"><li v-for="blocker in detail.milestones[key].blockers" :key="blocker">{{ blocker }}</li></ul><p v-else class="no-blockers">No source-record blockers found.</p><button v-if="can(key === 'operations' ? 'shipments.completion.operations.verify' : key === 'billing' ? 'shipments.completion.billing.verify' : key === 'collections' ? 'shipments.completion.collections.verify' : 'shipments.reconciliation.manage') && detail.milestones[key].status !== 'completed' && !detail.milestones[key].blockers.length" :disabled="saving" @click="verify(key, detail.milestones[key])">Verify completion</button></article></div>

        <section class="closing-financial"><div class="closing-section-heading"><div><h3>Profitability and reconciliation</h3><span class="closing-badge not_yet_determinable">{{ detail.financial.status.replaceAll('_', ' ') }}</span></div></div><div class="closing-values"><div><small>Estimated authorized value</small><b>{{ formatMoney(detail.financial.estimated_authorized_value, detail.financial.currency) }}</b></div><div><small>Estimated approved cost</small><b>{{ formatMoney(detail.financial.estimated_approved_cost, detail.financial.currency) }}</b></div><div><small>Estimated gross profit / margin</small><b>{{ formatMoney(detail.financial.estimated_gross_profit, detail.financial.currency) }} <template v-if="detail.financial.estimated_gross_margin_percent !== null">· {{ detail.financial.estimated_gross_margin_percent }}%</template></b></div><div><small>Recognized revenue</small><b>{{ formatMoney(detail.financial.recognized_revenue, detail.financial.currency) }}</b></div><div><small>Issued invoices</small><b>{{ formatMoney(detail.financial.issued_invoice_amount, detail.financial.currency) }}</b></div><div><small>Collected cash</small><b>{{ formatMoney(detail.financial.collected_amount, detail.financial.currency) }}</b></div><div><small>Outstanding receivable</small><b>{{ formatMoney(detail.financial.outstanding_receivables, detail.financial.currency) }}</b></div><div><small>Verified actual cost</small><b>{{ formatMoney(detail.financial.final_cost, detail.financial.currency) }}</b></div><div><small>Actual gross profit / margin</small><b>Not determinable</b></div></div><ul class="closing-blockers"><li v-for="blocker in detail.financial.blockers" :key="blocker">{{ blocker }}</li></ul></section>

        <section class="closing-workflow"><div class="closing-section-heading"><div><h3>Financial close workflow</h3><span :class="['closing-badge', detail.closing.status]">{{ detail.closing.status.replaceAll('_', ' ') }}</span></div></div><ul v-if="detail.closing.blockers.length" class="closing-blockers"><li v-for="blocker in detail.closing.blockers" :key="blocker">{{ blocker }}</li></ul>
          <label v-if="can('financial-closing.prepare')">Submission reason<textarea v-model="reason" rows="2" placeholder="Document why this shipment is ready for financial closing"></textarea></label><button v-if="can('financial-closing.prepare') && detail.closing.status !== 'pending_approval' && detail.closing.status !== 'financially_closed'" :disabled="saving || !reason" @click="action('prepare', { reason })">Submit for approval</button>
          <div v-if="can('financial-closing.approve') && detail.closing.status === 'pending_approval'" class="closing-review"><label>Review comments<textarea v-model="comment" rows="2" placeholder="Required review note"></textarea></label><div><button :disabled="saving || !comment" @click="action('review', { decision: 'approve', comments: comment, lock_version: detail.closing.lock_version })">Approve</button><button class="secondary" :disabled="saving || !comment" @click="action('review', { decision: 'request_correction', comments: comment, lock_version: detail.closing.lock_version })">Request correction</button><button class="danger" :disabled="saving || !comment" @click="action('review', { decision: 'reject', comments: comment, lock_version: detail.closing.lock_version })">Reject</button></div></div>
          <button v-if="can('financial-closing.close') && detail.closing.status === 'approved'" :disabled="saving || detail.closing.blockers.length" @click="action('close', { lock_version: detail.closing.lock_version })">Create immutable snapshot and close</button>
          <label v-if="can('financial-closing.reopen') && detail.closing.status === 'financially_closed'">Reopen reason<textarea v-model="reason" rows="2" placeholder="Describe the material correction required"></textarea></label><button v-if="can('financial-closing.reopen') && detail.closing.status === 'financially_closed'" class="secondary" :disabled="saving || !reason" @click="action('reopen', { reason })">Request controlled reopen</button>
          <a v-if="can('financial-closing.history.view')" :href="`/api/financial-closing/shipments/${selected.id}/history`" target="_blank" rel="noreferrer" class="closing-history-link">View closing history and snapshots</a>
        </section>
      </section>
      <section v-else class="closing-panel closing-empty">Select a shipment to review completion and closing requirements.</section>
    </div>
  </div>
</template>

<style scoped>
.closing-page{display:grid;gap:18px;color:#153453}.closing-heading,.closing-panel-head,.closing-section-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.closing-heading h1{margin:4px 0;font-size:30px}.closing-heading p,.closing-panel-head p{margin:4px 0;color:#657992}.closing-eyebrow{font-size:11px;font-weight:700;letter-spacing:.12em;color:#537493}.closing-link{border:1px solid #cbd9e7;border-radius:8px;padding:10px 14px;color:#16466f;text-decoration:none;white-space:nowrap}.closing-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.closing-kpis article,.closing-panel{background:white;border:1px solid #d7e1eb;border-radius:10px}.closing-kpis article{padding:16px}.closing-kpis span,.closing-values small{display:block;color:#66809a;font-size:11px;font-weight:700;letter-spacing:.08em}.closing-kpis b{display:block;font-size:25px;margin:6px 0}.closing-kpis small{color:#72859a}.closing-layout{display:grid;grid-template-columns:minmax(340px,.85fr) minmax(520px,1.4fr);align-items:start;gap:14px}.closing-panel{overflow:hidden}.closing-panel-head{padding:16px;border-bottom:1px solid #e2e8ef}.closing-panel-head h2{margin:0;font-size:18px}.closing-panel-head form{display:flex;gap:6px}.closing-panel-head input,.closing-panel-head button,.closing-dimension button,.closing-workflow button{border:1px solid #cbd9e7;border-radius:7px;padding:8px 10px;background:#fff;color:#173e63}.closing-panel-head input{width:180px}.closing-panel-head button,.closing-dimension button,.closing-workflow>button{background:#17466d;color:white;border-color:#17466d}.closing-table-scroll{overflow:auto;max-height:680px}.closing-table-scroll table{border-collapse:collapse;width:100%;font-size:12px}.closing-table-scroll th,.closing-table-scroll td{text-align:left;padding:11px 9px;border-bottom:1px solid #e7edf3;white-space:nowrap}.closing-table-scroll th{background:#f5f8fb;color:#59738c;font-size:10px;letter-spacing:.08em;position:sticky;top:0}.closing-table-scroll tbody tr{cursor:pointer}.closing-table-scroll tbody tr:hover,.closing-table-scroll tbody tr.selected{background:#eef7fb}.closing-table-scroll td small{display:block;color:#70859a;margin-top:3px}.closing-detail{padding-bottom:16px}.closing-dimensions{padding:12px 16px;display:grid;gap:10px}.closing-dimension{padding:12px;border:1px solid #e0e8f0;border-radius:8px}.closing-dimension>div,.closing-section-heading>div{display:flex;align-items:center;justify-content:space-between;gap:10px}.closing-dimension h3,.closing-section-heading h3{font-size:14px;margin:0}.closing-dimension ul,.closing-blockers{padding-left:18px;color:#8b4c21;font-size:12px}.closing-dimension li,.closing-blockers li{margin:4px 0}.no-blockers{font-size:12px;color:#28734c;margin:10px 0 0}.closing-badge{display:inline-block;border-radius:999px;padding:4px 8px;text-transform:capitalize;font-size:10px;font-weight:700;background:#edf1f5;color:#52667a}.closing-badge.completed,.closing-badge.financially_closed,.closing-badge.approved{background:#e3f6ec;color:#197148}.closing-badge.blocked,.closing-badge.pending_approval{background:#fff1dd;color:#91530d}.closing-badge.rejected{background:#ffeded;color:#a23535}.closing-financial,.closing-workflow{border-top:1px solid #e2e8ef;padding:14px 16px}.closing-financial h3,.closing-workflow h3{margin:0}.closing-values{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:12px 0}.closing-values b{display:block;margin-top:3px;font-size:13px}.closing-workflow{display:grid;gap:12px}.closing-workflow label{display:grid;gap:6px;font-size:12px;font-weight:600}.closing-workflow textarea{width:100%;resize:vertical;border:1px solid #cfdae5;border-radius:7px;padding:9px;font:inherit}.closing-review{display:grid;gap:8px}.closing-review>div{display:flex;flex-wrap:wrap;gap:8px}.closing-workflow button.secondary{background:#f4f7fa;color:#25445f}.closing-workflow button.danger{background:#fff;color:#a23535;border-color:#e7b7b7}.closing-history-link{font-size:12px;color:#17699c}.closing-empty{padding:24px;color:#687e93;text-align:center}.closing-alert{border-radius:8px;padding:12px 14px}.closing-alert.error{background:#fff1ef;color:#a3372f;border:1px solid #f2c3bd}.closing-alert.success{background:#eaf8f0;color:#206b44;border:1px solid #b5e2c7}@media(max-width:1050px){.closing-layout{grid-template-columns:1fr}.closing-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.closing-heading{display:block}.closing-heading .closing-link{display:inline-block;margin-top:8px}.closing-kpis{grid-template-columns:1fr 1fr}.closing-panel-head{display:block}.closing-panel-head form{margin-top:12px}.closing-panel-head input{flex:1;width:auto}.closing-values{grid-template-columns:1fr 1fr}}
.closing-pagination{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;font-size:12px;color:#6b8196}.closing-pagination>div{display:flex;gap:8px}.closing-pagination button{border:1px solid #cbd9e7;background:white;border-radius:6px;padding:6px 10px;color:#1a456c}.closing-pagination button:disabled{opacity:.45;cursor:not-allowed}
</style>
