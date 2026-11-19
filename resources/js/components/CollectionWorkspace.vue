<script setup>
import { computed, onMounted, ref } from 'vue'

const props = defineProps({ permissions: { type: Array, default: () => [] }, roles: { type: Array, default: () => [] }, userId: Number })
const allowed = new Set(props.permissions)
const can = (permission) => allowed.has(permission)
const money = (value, currency = 'PHP') => new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(Number(value || 0))
const date = (value) => value ? new Date(value).toLocaleDateString() : 'Not set'
const today = new Date().toISOString().slice(0, 10)
const printStatement = () => window.print()
const validViews = ['receivables', 'receipts', 'reconciliation', 'ledger', 'aging', 'followups']
const requestedView = new URLSearchParams(window.location.search).get('view')
const tab = ref(validViews.includes(requestedView) ? requestedView : 'receivables')
const dashboard = ref({ totals: [], aging: [], counts: {}, pending_receipts: [], recent_receipts: [] })
const invoices = ref([])
const receipts = ref([])
const bankTransactions = ref([])
const bankDialog = ref(false)
const bankMatchSelections = ref({})
const bankForm = ref({ statement_key: '', bank_account_reference: '', transaction_date: today, value_date: '', transaction_reference: '', credit_amount: '', currency: 'PHP', counterparty: '', description: '' })
const followUps = ref([])
const ledger = ref(null)
const ledgerClient = ref('')
const ledgerFrom = ref('')
const ledgerTo = ref(today)
const asOf = ref(today)
const followUpDialog = ref(false)
const followUpForm = ref({ invoice_id: '', assigned_to: '', follow_up_date: today, contact_method: 'phone', notes: '', promised_payment_date: '', promised_amount: '', next_action: '', status: 'scheduled' })
const invoiceOptions = ref([])
const invoicePage = ref(1)
const receiptPage = ref(1)
const invoicePagination = ref({ current_page: 1, last_page: 1, total: 0 })
const receiptPagination = ref({ current_page: 1, last_page: 1, total: 0 })
const search = ref('')
const status = ref('')
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const dialog = ref(false)
const form = ref({ reference: '', client_id: '', allocations: [{ invoice_id: '', amount: '' }], amount: '', received_at: today, payment_method: 'bank_transfer', evidence_reference: '', provider_name: '', bank_transaction_reference: '', deposit_reference: '', clearing_date: '', payment_notes: '' })
const selectedInvoice = computed(() => invoiceOptions.value.find((row) => String(row.id) === String(form.value.allocations[0]?.invoice_id)))
const clients = computed(() => [...new Map(invoiceOptions.value.map((row) => [row.client_id, { id: row.client_id, name: row.company_name }])).values()])
const clientInvoices = computed(() => invoiceOptions.value.filter((row) => String(row.client_id) === String(form.value.client_id) && Number(row.outstanding) > Number(row.pending_amount)))
const allocationOptions = (index) => clientInvoices.value.filter((row) => !form.value.allocations.some((allocation, other) => other !== index && String(allocation.invoice_id) === String(row.id)) && (!selectedInvoice.value || row.currency === selectedInvoice.value.currency))
const available = (row) => Math.max(0, Number(row.outstanding) - Number(row.pending_amount))

async function api(url, options = {}) {
  const response = await fetch(url, { credentials: 'same-origin', ...options, headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } : {}), ...(options.headers || {}) } })
  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    if (response.status === 401) throw new Error('Your IFC sign-in session has expired. Sign in again to continue.')
    if (response.status === 403) throw new Error('Your account does not have permission to perform this action.')
    throw new Error(body.errors ? Object.values(body.errors).flat().join(' ') : body.message || 'Collection request failed. Please retry.')
  }
  return body
}

async function load() {
  loading.value = true; error.value = ''
  try {
    const [summary, invoiceList, receiptList, followUpList, agingList, ledgerData, bankList] = await Promise.all([
      api('/api/collections/dashboard'),
      api(`/api/collections/invoices?per_page=20&page=${invoicePage.value}&search=${encodeURIComponent(tab.value === 'receivables' ? search.value : '')}`),
      can('collections.history.view') ? api(`/api/collections/receipts?per_page=20&page=${receiptPage.value}&search=${encodeURIComponent(tab.value === 'receipts' ? search.value : '')}&status=${encodeURIComponent(tab.value === 'receipts' ? status.value : '')}`) : Promise.resolve({ data: { data: [] } }),
      can('collections.followups.view') && tab.value === 'followups' ? api('/api/collections/follow-ups') : Promise.resolve({ data: { data: { data: [] } } }),
      tab.value === 'aging' ? api(`/api/collections/invoices?per_page=100&as_of=${encodeURIComponent(asOf.value)}`) : Promise.resolve(null),
      tab.value === 'ledger' && ledgerClient.value ? api(`/api/collections/ledger?client_id=${encodeURIComponent(ledgerClient.value)}&from=${encodeURIComponent(ledgerFrom.value)}&to=${encodeURIComponent(ledgerTo.value)}`) : Promise.resolve(null),
      can('collections.reconcile') && tab.value === 'reconciliation' ? api('/api/collections/bank-transactions') : Promise.resolve(null),
    ])
    dashboard.value = summary.data
    invoices.value = invoiceList.data.data || []
    if (invoiceOptions.value.length === 0) invoiceOptions.value = (await api('/api/collections/invoices?per_page=100')).data.data || []
    invoicePagination.value = { current_page: invoiceList.data.current_page || 1, last_page: invoiceList.data.last_page || 1, total: invoiceList.data.total || 0 }
    receipts.value = receiptList.data.data || []
    receiptPagination.value = { current_page: receiptList.data.current_page || 1, last_page: receiptList.data.last_page || 1, total: receiptList.data.total || 0 }
    followUps.value = followUpList.data.data?.data || []
    if (agingList) invoices.value = agingList.data.data.data || []
    if (ledgerData) ledger.value = ledgerData.data
    if (bankList) bankTransactions.value = bankList.data.data?.data || []
  } catch (exception) { error.value = exception.message } finally { loading.value = false }
}

async function openReceipt() {
  form.value = { reference: '', client_id: '', allocations: [{ invoice_id: '', amount: '' }], amount: '', received_at: today, payment_method: 'bank_transfer', evidence_reference: '', provider_name: '', bank_transaction_reference: '', deposit_reference: '', clearing_date: '', payment_notes: '' }
  error.value = ''; dialog.value = true
  try { invoiceOptions.value = (await api('/api/collections/invoices?per_page=100')).data.data || [] } catch (exception) { error.value = exception.message }
}

function chooseInvoice(index = 0) {
  const invoice = invoiceOptions.value.find((row) => String(row.id) === String(form.value.allocations[index]?.invoice_id))
  if (invoice) {
    if (index === 0) form.value.client_id = String(invoice.client_id)
    form.value.allocations[index].amount = available(invoice).toFixed(2)
    if (!form.value.amount) form.value.amount = available(invoice).toFixed(2)
  }
}
function addAllocation() { form.value.allocations.push({ invoice_id: '', amount: '' }) }
function removeAllocation(index) { if (form.value.allocations.length > 1) form.value.allocations.splice(index, 1) }

async function recordReceipt() {
  if (!selectedInvoice.value || form.value.allocations.some((row) => !row.invoice_id || Number(row.amount) <= 0)) return
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const result = await api('/api/collections/receipts', { method: 'POST', body: JSON.stringify({ ...form.value, client_id: selectedInvoice.value.client_id, currency: selectedInvoice.value.currency, allocations: form.value.allocations, idempotency_key: crypto.randomUUID() }) })
    notice.value = result.message; dialog.value = false; tab.value = 'receipts'; await load()
  } catch (exception) { error.value = exception.message } finally { saving.value = false }
}

async function loadLedger() { if (!ledgerClient.value) return; error.value = ''; try { ledger.value = (await api(`/api/collections/ledger?client_id=${encodeURIComponent(ledgerClient.value)}&from=${encodeURIComponent(ledgerFrom.value)}&to=${encodeURIComponent(ledgerTo.value)}`)).data } catch (exception) { error.value = exception.message } }
async function saveFollowUp() {
  saving.value = true; error.value = ''; notice.value = ''
  try { await api('/api/collections/follow-ups', { method: 'POST', body: JSON.stringify(followUpForm.value) }); notice.value = 'Collection follow-up saved.'; followUpDialog.value = false; await load() } catch (exception) { error.value = exception.message } finally { saving.value = false }
}
async function updateFollowUpStatus(item, nextStatus) {
  try { await api(`/api/collections/follow-ups/${item.id}`, { method: 'PATCH', body: JSON.stringify({ status: nextStatus }) }); notice.value = 'Follow-up status updated.'; await load() } catch (exception) { error.value = exception.message; await load() }
}
function openFollowUp() { followUpForm.value = { invoice_id: '', assigned_to: String(props.userId || ''), follow_up_date: today, contact_method: 'phone', notes: '', promised_payment_date: '', promised_amount: '', next_action: '', status: 'scheduled' }; followUpDialog.value = true }
function selectTab(view) { tab.value = view; history.replaceState({}, '', `/collections${view === 'receivables' ? '' : `?view=${view}`}`); load() }
async function enterBankTransaction() {
  saving.value = true; error.value = ''; notice.value = ''
  try { await api('/api/collections/bank-transactions', { method: 'POST', body: JSON.stringify({ ...bankForm.value, value_date: bankForm.value.value_date || null }) }); notice.value = 'Statement transaction recorded; it is not reconciled yet.'; bankDialog.value = false; await load() } catch (exception) { error.value = exception.message } finally { saving.value = false }
}
async function matchBankTransaction(row) {
  const receiptId = bankMatchSelections.value[row.id]
  if (!receiptId) return
  const reason = window.prompt('Explain why this statement line matches the receipt. Exact currency and amount are checked by the server.')
  if (!reason?.trim()) return
  saving.value = true; error.value = ''; notice.value = ''
  try { const result = await api(`/api/collections/bank-transactions/${row.id}/match`, { method: 'POST', body: JSON.stringify({ receipt_id: receiptId, reason }) }); notice.value = result.message; await load() } catch (exception) { error.value = exception.message } finally { saving.value = false }
}
function openBankDialog() { bankForm.value = { statement_key: '', bank_account_reference: '', transaction_date: today, value_date: '', transaction_reference: '', credit_amount: '', currency: 'PHP', counterparty: '', description: '' }; bankDialog.value = true }
function applyCredit(receipt) {
  const eligible = invoiceOptions.value.filter((row) => Number(row.client_id) === Number(receipt.client_id) && row.currency === receipt.currency && Number(row.outstanding) > Number(row.pending_amount))
  if (!eligible.length) { error.value = 'No eligible issued invoices are available for this customer credit.'; return }
  const selected = window.prompt(`Enter the target issued invoice ID. Available: ${eligible.map((row) => `${row.reference} (#${row.id})`).join(', ')}`)
  const invoice = eligible.find((row) => String(row.id) === String(selected))
  if (!invoice) return
  const amount = window.prompt(`Unapplied ${receipt.currency} ${receipt.unapplied_credit}. Amount to allocate to ${invoice.reference}:`)
  if (!amount || Number(amount) <= 0) return
  saving.value = true; error.value = ''; notice.value = ''
  api(`/api/collections/receipts/${receipt.id}/allocate-credit`, { method: 'POST', body: JSON.stringify({ allocations: [{ invoice_id: invoice.id, amount }] }) }).then((result) => { notice.value = result.message; load() }).catch((exception) => { error.value = exception.message }).finally(() => { saving.value = false })
}

async function reconcile(receipt, decision) {
  const noun = decision === 'reconcile' ? 'confirm the receipt against supporting evidence' : 'reject this receipt and release its held allocation'
  if (!window.confirm(`Are you sure you want to ${noun}?`)) return
  const note = window.prompt('Add a reconciliation note (required)')
  if (!note?.trim()) return
  saving.value = true; error.value = ''; notice.value = ''
  try { const result = await api(`/api/collections/receipts/${receipt.id}/reconcile`, { method: 'POST', body: JSON.stringify({ decision, note }) }); notice.value = result.message; await load() } catch (exception) { error.value = exception.message } finally { saving.value = false }
}
async function reverseReceipt(receipt) {
  if (!window.confirm(`Reverse verified receipt ${receipt.reference}? This changes IFC accounting status only; it will not issue a refund.`)) return
  const reason = window.prompt('Enter the reason for this financial reversal (required)')
  if (!reason?.trim()) return
  saving.value = true; error.value = ''; notice.value = ''
  try { const result = await api(`/api/collections/receipts/${receipt.id}/reverse`, { method: 'POST', body: JSON.stringify({ reason }) }); notice.value = result.message; await load() } catch (exception) { error.value = exception.message } finally { saving.value = false }
}

async function applyFilters() { invoicePage.value = 1; receiptPage.value = 1; await load() }
async function changePage(view, offset) {
  const pagination = view === 'receivables' ? invoicePagination.value : receiptPagination.value
  const next = pagination.current_page + offset
  if (next < 1 || next > pagination.last_page) return
  if (view === 'receivables') invoicePage.value = next
  else receiptPage.value = next
  await load()
}
function agingLabel(bucket) { return ({ not_due: 'Not yet due', '1_30': '1–30 days', '31_60': '31–60 days', '61_90': '61–90 days', over_90: 'Over 90 days', no_due_date: 'No due date' })[bucket] || bucket }
function statusLabel(value) { return (value || '').replaceAll('_', ' ') }

onMounted(load)
</script>

<template>
  <div class="collection-workspace">
    <header class="collection-heading">
      <div><span class="collection-kicker">INTERFREIGHT GROUP / ACCOUNTING</span><h1>Collection monitoring</h1><p>Track issued invoice balances, receipts, due dates and reconciliation status.</p></div>
      <button v-if="can('collections.record')" class="collection-primary" @click="openReceipt">Record payment</button>
    </header>
    <nav class="collection-tabs" aria-label="Collection views">
      <button :class="{ active: tab === 'receivables' }" @click="selectTab('receivables')">Accounts receivable</button>
      <button v-if="can('collections.history.view')" :class="{ active: tab === 'receipts' }" @click="selectTab('receipts')">Payment history</button>
      <button v-if="can('collections.reconcile')" :class="{ active: tab === 'reconciliation' }" @click="selectTab('reconciliation')">Payment reconciliation</button>
      <button :class="{ active: tab === 'ledger' }" @click="selectTab('ledger')">Customer ledger</button>
      <button :class="{ active: tab === 'aging' }" @click="selectTab('aging')">Aging report</button>
      <button v-if="can('collections.followups.view')" :class="{ active: tab === 'followups' }" @click="selectTab('followups')">Follow-ups</button>
    </nav>
    <div v-if="notice" class="collection-success" role="status">{{ notice }}</div>
    <div v-if="error && !dialog" class="collection-error" role="alert">{{ error }}<a v-if="error.startsWith('Your IFC sign-in session has expired')" href="/login"> Sign in again</a></div>

    <template v-if="tab === 'receivables'">
      <section class="collection-totals" aria-label="Receivables by currency">
        <article v-for="item in dashboard.totals" :key="item.currency"><small>{{ item.currency }} · Outstanding</small><b>{{ money(item.outstanding, item.currency) }}</b><span>{{ item.count }} issued invoices · {{ money(item.collected, item.currency) }} reconciled</span></article>
        <article v-for="item in dashboard.kpis?.verified_this_month || []" :key="`month-${item.currency}`"><small>{{ item.currency }} · Verified this month</small><b>{{ money(item.amount, item.currency) }}</b><span>Reconciled receipts · Updated {{ date(dashboard.kpis.as_of) }}</span></article>
        <article v-for="item in dashboard.kpis?.unapplied_credits || []" :key="`credit-${item.currency}`"><small>{{ item.currency }} · Unapplied customer credit</small><b>{{ money(item.amount, item.currency) }}</b><span>Verified and available for allocation</span></article>
        <article><small>Pending reconciliation</small><b>{{ dashboard.counts.pending_reconciliation ?? 0 }}</b><span>Receipts awaiting Finance review</span></article>
        <article><small>Overdue invoices</small><b>{{ dashboard.counts.overdue ?? 0 }}</b><span>Based on recorded due dates</span></article>
        <article><small>Partially paid</small><b>{{ dashboard.kpis?.partially_paid ?? 0 }}</b><span>Issued invoices with open balance</span></article>
        <article><small>Fully settled</small><b>{{ dashboard.kpis?.fully_settled ?? 0 }}</b><span>Issued invoices with no balance</span></article>
      </section>
      <section class="collection-panel collection-aging">
        <header><div><h2>Receivables aging</h2><p>Open balance is aged from the invoice due date. Currency totals stay separate.</p></div></header>
        <div v-if="dashboard.aging.length" class="collection-aging-grid"><article v-for="item in dashboard.aging" :key="item.currency"><b>{{ item.currency }}</b><span v-for="key in ['not_due','1_30','31_60','61_90','over_90','no_due_date']" :key="key"><small>{{ agingLabel(key) }}</small><strong>{{ money(item[key], item.currency) }}</strong></span></article></div>
        <div v-else class="collection-empty">{{ loading ? 'Loading receivables…' : 'No issued invoices are available for collection monitoring.' }}</div>
      </section>
      <section class="collection-panel">
        <header><div><h2>Open invoices</h2><p>Only issued invoices are included. Pending receipts are held separately until reconciled.</p></div><span>{{ invoicePagination.total }} invoices</span></header>
        <div class="collection-toolbar"><input v-model="search" placeholder="Search reference, client or shipment" aria-label="Search invoices"><button class="collection-secondary" @click="applyFilters">Search</button></div>
        <div class="collection-table-wrap"><table><thead><tr><th>Invoice</th><th>Client / Shipment</th><th>Due date</th><th>Amount</th><th>Reconciled</th><th>Pending</th><th>Outstanding</th><th>Collection status</th><th>Aging</th></tr></thead><tbody>
          <tr v-for="row in invoices" :key="row.id"><td><b>{{ row.reference }}</b><small v-if="row.official_number">Official {{ row.official_number }}</small></td><td>{{ row.company_name }}<small>{{ row.job_number || 'Shipment not linked' }}</small></td><td>{{ date(row.due_date) }}</td><td>{{ money(row.total, row.currency) }}</td><td>{{ money(row.collected, row.currency) }}</td><td>{{ money(row.pending_amount, row.currency) }}</td><td><b>{{ money(row.outstanding, row.currency) }}</b></td><td><span class="collection-badge" :class="row.collection_status">{{ statusLabel(row.collection_status) }}</span></td><td><span :class="{ 'aging-late': row.aging_days > 0 }">{{ row.aging_bucket === 'no_due_date' ? 'No due date' : row.aging_days ? `${row.aging_days} days overdue` : 'Current' }}</span></td></tr>
          <tr v-if="!loading && !invoices.length"><td colspan="9" class="collection-empty">No issued invoices are available yet. Draft and approved invoices are excluded from receivables.</td></tr><tr v-if="loading"><td colspan="9" class="collection-empty">Loading issued invoices…</td></tr>
        </tbody></table></div>
        <footer class="collection-pagination"><span>{{ invoicePagination.total }} invoices · page {{ invoicePagination.current_page }} of {{ invoicePagination.last_page }}</span><div><button class="collection-secondary" :disabled="invoicePagination.current_page <= 1 || loading" @click="changePage('receivables', -1)">Previous</button><button class="collection-secondary" :disabled="invoicePagination.current_page >= invoicePagination.last_page || loading" @click="changePage('receivables', 1)">Next</button></div></footer>
      </section>
      <section v-if="can('collections.reconcile') && dashboard.pending_receipts?.length" class="collection-panel">
        <header><div><h2>Receipts to reconcile</h2><p>Finance review confirms evidence before amounts reduce receivables.</p></div><span>{{ dashboard.pending_receipts.length }} shown</span></header>
        <div class="collection-review-list"><article v-for="receipt in dashboard.pending_receipts" :key="receipt.id"><span><b>{{ receipt.reference }} · {{ receipt.company_name }}</b><small>{{ receipt.evidence_reference }} · Recorded {{ date(receipt.received_at) }}</small></span><strong>{{ money(receipt.amount, receipt.currency) }}</strong><button class="collection-secondary" :disabled="saving || Number(receipt.recorded_by) === Number(userId)" @click="reconcile(receipt, 'reconcile')">Reconcile</button><button class="collection-danger" :disabled="saving || Number(receipt.recorded_by) === Number(userId)" @click="reconcile(receipt, 'reject')">Reject</button></article></div>
      </section>
    </template>

    <section v-else-if="tab === 'receipts'" class="collection-panel">
      <header><div><h2>Payment history</h2><p>Recorded receipt details, invoice allocations and independent reconciliation decisions.</p></div></header>
      <div class="collection-toolbar"><input v-model="search" placeholder="Search reference, evidence or client" aria-label="Search receipts"><select v-model="status" aria-label="Filter receipt status"><option value="">All receipt statuses</option><option value="pending_reconciliation">Pending reconciliation</option><option value="reconciled">Reconciled</option><option value="rejected">Rejected</option><option value="reversed">Reversed</option></select><button class="collection-secondary" @click="applyFilters">Apply filters</button></div>
      <div class="collection-table-wrap"><table><thead><tr><th>Receipt</th><th>Client</th><th>Received</th><th>Method / evidence</th><th>Invoice allocation</th><th>Amount</th><th>Recorded by</th><th>Reconciled by</th><th>Status / action</th></tr></thead><tbody>
        <tr v-for="receipt in receipts" :key="receipt.id"><td><b>{{ receipt.reference }}</b><small>{{ receipt.bank_transaction_reference || receipt.deposit_reference || '' }}</small></td><td>{{ receipt.company_name }}</td><td>{{ date(receipt.received_at) }}</td><td>{{ statusLabel(receipt.payment_method) }}<small>{{ receipt.provider_name || receipt.evidence_reference }}</small></td><td><span v-for="allocation in receipt.allocations || []" :key="allocation.reference">{{ allocation.reference }} · {{ money(allocation.amount, allocation.currency) }}</span></td><td>{{ money(receipt.amount, receipt.currency) }}<small v-if="Number(receipt.unapplied_credit) > 0">Unapplied: {{ money(receipt.unapplied_credit, receipt.currency) }}</small></td><td>{{ receipt.recorded_by_name }}</td><td>{{ receipt.reconciled_by_name || 'Pending' }}<small>{{ receipt.reconciled_at ? date(receipt.reconciled_at) : '' }}</small></td><td><span class="collection-badge" :class="receipt.status">{{ statusLabel(receipt.status) }}</span><div v-if="receipt.status === 'pending_reconciliation' && can('collections.reconcile')" class="collection-row-actions"><button :disabled="saving || Number(receipt.recorded_by) === Number(userId)" @click="reconcile(receipt, 'reconcile')">Reconcile</button><button :disabled="saving || Number(receipt.recorded_by) === Number(userId)" @click="reconcile(receipt, 'reject')">Reject</button></div><div v-if="Number(receipt.unapplied_credit) > 0 && can('collections.record')" class="collection-row-actions"><button :disabled="saving" @click="applyCredit(receipt)">Allocate credit</button></div><div v-if="receipt.status === 'reconciled' && can('collections.reverse')" class="collection-row-actions"><button class="collection-danger" :disabled="saving" @click="reverseReceipt(receipt)">Reverse</button></div><small v-if="receipt.reconciliation_note">{{ receipt.reconciliation_note }}</small></td></tr>
        <tr v-if="!loading && !receipts.length"><td colspan="9" class="collection-empty">No payment receipts match this view.</td></tr><tr v-if="loading"><td colspan="9" class="collection-empty">Loading payment history…</td></tr>
      </tbody></table></div>
      <footer class="collection-pagination"><span>{{ receiptPagination.total }} receipts · page {{ receiptPagination.current_page }} of {{ receiptPagination.last_page }}</span><div><button class="collection-secondary" :disabled="receiptPagination.current_page <= 1 || loading" @click="changePage('receipts', -1)">Previous</button><button class="collection-secondary" :disabled="receiptPagination.current_page >= receiptPagination.last_page || loading" @click="changePage('receipts', 1)">Next</button></div></footer>
    </section>

    <section v-if="tab === 'aging'" class="collection-panel">
      <header><div><h2>Receivables aging</h2><p>Verified collections are counted by reconciliation date. Currency totals remain separate.</p></div><label class="as-of-control">As of<input v-model="asOf" type="date" :max="today" @change="load"></label></header>
      <div class="collection-table-wrap"><table><thead><tr><th>Client / Invoice</th><th>Due</th><th>Original</th><th>Verified collections</th><th>Outstanding</th><th>Overdue days</th><th>Bucket</th></tr></thead><tbody><tr v-for="row in invoices" :key="row.id"><td><b>{{ row.company_name }}</b><small>{{ row.official_number || row.reference }}</small></td><td>{{ date(row.due_date) }}</td><td>{{ money(row.total, row.currency) }}</td><td>{{ money(row.collected, row.currency) }}</td><td>{{ money(row.outstanding, row.currency) }}</td><td>{{ row.aging_days }}</td><td>{{ agingLabel(row.aging_bucket) }}</td></tr><tr v-if="!invoices.length"><td colspan="7" class="collection-empty">No receivables were issued by this date.</td></tr></tbody></table></div>
    </section>
    <section v-else-if="tab === 'reconciliation'" class="collection-panel">
      <header><div><h2>Statement reconciliation</h2><p>Enter statement credits manually. No bank connection is made; matching does not verify funds by itself.</p></div><button class="collection-primary" @click="openBankDialog">Enter statement credit</button></header>
      <div class="collection-table-wrap"><table><thead><tr><th>Statement line</th><th>Transaction date</th><th>Counterparty / details</th><th>Amount</th><th>Status</th><th>Pending receipt match</th></tr></thead><tbody><tr v-for="row in bankTransactions" :key="row.id"><td><b>{{ row.transaction_reference }}</b><small>{{ row.bank_account_reference }} · {{ row.statement_key }}</small></td><td>{{ date(row.transaction_date) }}</td><td>{{ row.counterparty || 'Not supplied' }}<small>{{ row.description }}</small></td><td>{{ money(row.credit_amount, row.currency) }}</td><td><span class="collection-badge" :class="row.status">{{ statusLabel(row.status) }}</span><small v-if="row.payment_reference">{{ row.payment_reference }}</small></td><td><div v-if="row.status === 'unmatched'" class="bank-match-control"><select v-model="bankMatchSelections[row.id]" aria-label="Choose pending receipt"><option value="">Choose receipt</option><option v-for="receipt in dashboard.pending_receipts" :key="receipt.id" :value="receipt.id">{{ receipt.reference }} · {{ money(receipt.amount, receipt.currency) }}</option></select><button class="collection-secondary" :disabled="saving || !bankMatchSelections[row.id]" @click="matchBankTransaction(row)">Match</button></div><span v-else>{{ row.payment_reference || '—' }}</span></td></tr><tr v-if="!bankTransactions.length"><td colspan="6" class="collection-empty">No statement credits have been entered.</td></tr></tbody></table></div>
    </section>
    <section v-else-if="tab === 'ledger'" class="collection-panel">
      <header><div><h2>Customer ledger</h2><p>Invoice charges, verified allocations and unapplied credits from source records.</p></div><button class="collection-secondary" :disabled="!ledger" @click="printStatement">Print statement</button></header>
      <div class="collection-toolbar"><select v-model="ledgerClient" aria-label="Select customer"><option value="">Select customer</option><option v-for="client in clients" :key="client.id" :value="String(client.id)">{{ client.name }}</option></select><label>From<input v-model="ledgerFrom" type="date" :max="ledgerTo"></label><label>To<input v-model="ledgerTo" type="date" :min="ledgerFrom" :max="today"></label><button class="collection-secondary" @click="loadLedger">Open ledger</button></div>
      <div v-if="ledger" class="collection-ledger-meta"><b>{{ ledger.client.company_name }}</b><span>Through {{ ledger.to }}</span></div>
      <div v-if="ledger" class="collection-table-wrap"><table><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th>Currency</th><th>Debit</th><th>Credit</th></tr></thead><tbody><tr v-for="(row, index) in ledger.transactions" :key="`${row.type}-${row.reference}-${index}`"><td>{{ date(row.date) }}</td><td>{{ statusLabel(row.type) }}</td><td>{{ row.reference }}</td><td>{{ row.description }}</td><td>{{ row.currency }}</td><td>{{ money(row.debit, row.currency) }}</td><td>{{ money(row.credit, row.currency) }}</td></tr><tr v-if="!ledger.transactions.length"><td colspan="7" class="collection-empty">No issued invoices or verified allocations for this customer.</td></tr></tbody></table></div>
      <div v-if="ledger" class="collection-ledger-meta"><b>Opening balance</b><span v-for="(amount, currency) in ledger.opening" :key="`o-${currency}`">{{ currency }} {{ amount }}</span><b>Closing balance</b><span v-for="(amount, currency) in ledger.closing" :key="`c-${currency}`">{{ currency }} {{ amount }}</span></div>
    </section>
    <section v-else-if="tab === 'followups'" class="collection-panel">
      <header><div><h2>Collection follow-ups</h2><p>Internal reminders and promises to pay. A promise is not a verified payment.</p></div><button v-if="can('collections.followups.manage')" class="collection-primary" @click="openFollowUp">Schedule follow-up</button></header>
      <div class="collection-table-wrap"><table><thead><tr><th>Due</th><th>Client / Invoice</th><th>Assigned to</th><th>Contact / notes</th><th>Promise</th><th>Status</th><th>Next action</th></tr></thead><tbody><tr v-for="item in followUps" :key="item.id"><td>{{ date(item.follow_up_date) }}</td><td>{{ item.company_name }}<small>{{ item.invoice_reference }}</small></td><td>{{ item.assigned_name }}</td><td>{{ statusLabel(item.contact_method) }}<small>{{ item.notes }}</small></td><td>{{ item.promised_payment_date ? `${date(item.promised_payment_date)} · ${money(item.promised_amount, item.currency)}` : '—' }}</td><td><select v-if="can('collections.followups.manage')" :value="item.status" aria-label="Update follow-up status" @change="updateFollowUpStatus(item, $event.target.value)"><option value="scheduled">Scheduled</option><option value="contacted">Contacted</option><option value="promise_to_pay">Promise to pay</option><option value="awaiting_response">Awaiting response</option><option value="escalated">Escalated</option><option value="resolved">Resolved</option></select><span v-else class="collection-badge">{{ statusLabel(item.status) }}</span></td><td>{{ item.next_action || '—' }}</td></tr><tr v-if="!followUps.length"><td colspan="7" class="collection-empty">No follow-ups are scheduled.</td></tr></tbody></table></div>
    </section>

    <div v-if="followUpDialog" class="collection-overlay" @click.self="followUpDialog = false"><form class="collection-dialog" @submit.prevent="saveFollowUp"><header><div><span class="collection-kicker">COLLECTIONS</span><h2>Schedule follow-up</h2><p>Record an internal collection activity.</p></div><button type="button" class="collection-close" aria-label="Close" @click="followUpDialog = false">×</button></header><div class="collection-form-grid"><label class="wide">Issued invoice<select v-model="followUpForm.invoice_id" required><option value="">Select invoice</option><option v-for="invoice in invoiceOptions" :key="invoice.id" :value="invoice.id">{{ invoice.company_name }} · {{ invoice.reference }}</option></select></label><label>Follow-up date<input v-model="followUpForm.follow_up_date" type="date" required></label><label>Contact method<select v-model="followUpForm.contact_method"><option value="phone">Phone</option><option value="email">Email</option><option value="meeting">Meeting</option><option value="other">Other</option></select></label><label>Status<select v-model="followUpForm.status"><option value="scheduled">Scheduled</option><option value="contacted">Contacted</option><option value="promise_to_pay">Promise to pay</option><option value="awaiting_response">Awaiting response</option><option value="escalated">Escalated</option><option value="resolved">Resolved</option></select></label><label>Promised date<input v-model="followUpForm.promised_payment_date" type="date"></label><label>Promised amount<input v-model="followUpForm.promised_amount" type="number" min="0.01" step="0.01"></label><label class="wide">Notes<textarea v-model.trim="followUpForm.notes" required minlength="3" maxlength="5000" rows="3"></textarea></label><label class="wide">Next action<input v-model.trim="followUpForm.next_action" maxlength="2000"></label></div><footer><button type="button" class="collection-secondary" @click="followUpDialog = false">Cancel</button><button class="collection-primary" :disabled="saving">Save follow-up</button></footer></form></div>

    <div v-if="bankDialog" class="collection-overlay" @click.self="bankDialog = false"><form class="collection-dialog" @submit.prevent="enterBankTransaction"><header><div><span class="collection-kicker">MANUAL STATEMENT ENTRY</span><h2>Enter bank statement credit</h2><p>This records statement data supplied by Finance. It does not connect to a bank.</p></div><button type="button" class="collection-close" aria-label="Close" @click="bankDialog = false">×</button></header><div class="collection-form-grid"><label>Statement row key<input v-model.trim="bankForm.statement_key" maxlength="180" required></label><label>Bank account reference<input v-model.trim="bankForm.bank_account_reference" maxlength="120" required></label><label>Transaction reference<input v-model.trim="bankForm.transaction_reference" maxlength="180" required></label><label>Transaction date<input v-model="bankForm.transaction_date" type="date" :max="today" required></label><label>Value date<input v-model="bankForm.value_date" type="date" :min="bankForm.transaction_date" :max="today"></label><label>Credit amount<input v-model="bankForm.credit_amount" type="number" min="0.01" step="0.01" required></label><label>Currency<input v-model.trim="bankForm.currency" maxlength="3" pattern="[A-Z]{3}" required></label><label>Counterparty<input v-model.trim="bankForm.counterparty" maxlength="180"></label><label class="wide">Description<textarea v-model.trim="bankForm.description" maxlength="5000" rows="2"></textarea></label></div><footer><button type="button" class="collection-secondary" @click="bankDialog = false">Cancel</button><button class="collection-primary" :disabled="saving">Save statement line</button></footer></form></div>

    <div v-if="dialog" class="collection-overlay" @click.self="dialog = false"><form class="collection-dialog" @submit.prevent="recordReceipt"><header><div><span class="collection-kicker">RECEIPT ENTRY</span><h2>Record customer payment</h2><p>The payment will remain pending until Finance reconciles it.</p></div><button type="button" class="collection-close" aria-label="Close" @click="dialog = false">×</button></header>
      <div v-if="error" class="collection-error" role="alert">{{ error }}<a v-if="error.startsWith('Your IFC sign-in session has expired')" href="/login"> Sign in again</a></div>
      <div class="collection-form-grid"><label>Receipt reference<input v-model.trim="form.reference" maxlength="100" required placeholder="Bank or receipt reference"></label><label>Client<select v-model="form.client_id" required @change="form.allocations = [{ invoice_id: '', amount: '' }]; form.amount = ''"><option value="" disabled>Select client</option><option v-for="client in clients" :key="client.id" :value="String(client.id)">{{ client.name }}</option></select></label><label>Amount received<input v-model="form.amount" type="number" min="0.01" step="0.01" required></label><label>Received on<input v-model="form.received_at" type="date" :max="today" required></label><div class="wide allocation-lines"><div v-for="(allocation, index) in form.allocations" :key="index" class="allocation-line"><label>Issued invoice<select v-model="allocation.invoice_id" required @change="chooseInvoice(index)"><option value="">Select invoice</option><option v-for="invoice in allocationOptions(index)" :key="invoice.id" :value="String(invoice.id)">{{ invoice.reference }} · {{ invoice.currency }} {{ money(available(invoice), invoice.currency) }} available</option></select></label><label>Allocation amount<input v-model="allocation.amount" type="number" min="0.01" step="0.01" :max="allocation.invoice_id ? available(invoiceOptions.find((row) => String(row.id) === String(allocation.invoice_id))) : undefined" required></label><button v-if="form.allocations.length > 1" type="button" class="collection-danger" @click="removeAllocation(index)">Remove</button></div><button type="button" class="collection-secondary" @click="addAllocation">Add invoice allocation</button></div><label>Payment method<select v-model="form.payment_method" required><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="check">Check</option><option value="online_payment">Online payment</option><option value="card">Card</option><option value="other">Other</option></select></label><label>Bank / payment provider<input v-model.trim="form.provider_name" maxlength="120"></label><label>Bank transaction reference<input v-model.trim="form.bank_transaction_reference" maxlength="180"></label><label>Deposit reference<input v-model.trim="form.deposit_reference" maxlength="180"></label><label>Clearing date<input v-model="form.clearing_date" type="date" :min="form.received_at"></label><label>Evidence reference<input v-model.trim="form.evidence_reference" maxlength="180" required placeholder="Deposit slip, check number, or receipt ID"></label><label class="wide">Payment notes<textarea v-model.trim="form.payment_notes" rows="2" maxlength="5000"></textarea></label></div>
      <p v-if="selectedInvoice" class="collection-allocation-note">Allocate up to {{ money(form.amount, selectedInvoice.currency) }} received. Any verified remainder stays as unapplied customer credit.</p>
      <footer><button type="button" class="collection-secondary" @click="dialog = false">Cancel</button><button class="collection-primary" :disabled="saving || !selectedInvoice">{{ saving ? 'Saving…' : 'Record receipt' }}</button></footer>
    </form></div>
  </div>
</template>

<style scoped>
.collection-workspace{display:grid;gap:16px;min-width:0;color:#29495f}.collection-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:16px}.collection-kicker{color:#1681a3;font-size:10px;font-weight:800;letter-spacing:.12em}.collection-heading h1{margin:5px 0;color:#173b59;font:700 28px Manrope,Inter,system-ui,sans-serif;letter-spacing:-.035em}.collection-heading p,.collection-panel header p{margin:4px 0 0;color:#718397;font-size:13px}.collection-primary,.collection-secondary,.collection-danger{min-height:40px;border-radius:8px;padding:0 15px;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer}.collection-primary{border:1px solid #123f65;background:#123f65;color:white}.collection-primary:hover{background:#0d3150}.collection-secondary{border:1px solid #c9d8e5;background:white;color:#173b59}.collection-danger{border:1px solid #f0c3c3;background:#fff7f7;color:#a33b43}.collection-secondary:disabled,.collection-danger:disabled{opacity:.45;cursor:not-allowed}.collection-tabs{display:flex;gap:7px;border-bottom:1px solid #dce5ec}.collection-tabs button{border:0;border-bottom:2px solid transparent;background:transparent;padding:10px 13px;color:#61798e;font-size:13px;font-weight:700;cursor:pointer}.collection-tabs button.active{border-bottom-color:#24a5c1;color:#123f65}.collection-success,.collection-error{border:1px solid #bee6d0;border-radius:8px;background:#effaf3;padding:11px 14px;color:#236b42;font-size:13px}.collection-error{border-color:#f0c3c3;background:#fff5f5;color:#9b3438}.collection-error a{margin-left:6px;color:inherit;font-weight:700}.collection-totals{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.collection-totals article,.collection-panel{min-width:0;border:1px solid #dce5ec;border-radius:10px;background:#fff;box-shadow:0 4px 15px #173b5908}.collection-totals article{display:grid;gap:7px;padding:16px}.collection-totals small,.collection-totals span{color:#77899a;font-size:11px}.collection-totals b{color:#153c5b;font-size:22px;letter-spacing:-.03em}.collection-panel{overflow:hidden}.collection-panel>header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid #e7edf2}.collection-panel h2{margin:0;color:#173b59;font-size:15px}.collection-panel>header>span{color:#73879a;font-size:12px}.collection-aging-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px;padding:13px}.collection-aging-grid article{display:grid;gap:8px;border:1px solid #e6edf2;border-radius:8px;padding:12px}.collection-aging-grid article>b{color:#173b59}.collection-aging-grid article span{display:flex;justify-content:space-between;gap:8px}.collection-aging-grid small{color:#73879a}.collection-aging-grid strong{color:#29495f;font-size:12px}.collection-toolbar{display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid #e7edf2}.collection-toolbar input,.collection-toolbar select,.collection-form-grid input,.collection-form-grid select{height:39px;border:1px solid #cfdae4;border-radius:7px;background:#fff;padding:0 11px;color:#29495f;font-family:inherit;font-size:13px;min-width:0}.collection-toolbar input{flex:1}.collection-table-wrap{width:100%;overflow-x:auto}.collection-table-wrap table{width:100%;border-collapse:collapse;min-width:930px;text-align:left}.collection-table-wrap th{background:#f6f8fa;color:#657b8f;font-size:10px;font-weight:800;letter-spacing:.06em;white-space:nowrap}.collection-table-wrap th,.collection-table-wrap td{padding:11px 13px;border-bottom:1px solid #e7edf2;vertical-align:top;font-size:12px}.collection-table-wrap td{color:#29495f}.collection-table-wrap td small{display:block;margin-top:4px;color:#8291a0;font-size:10px}.collection-badge{display:inline-flex;align-items:center;border-radius:999px;background:#edf2f6;padding:5px 9px;color:#51697e;font-size:10px;font-weight:800;text-transform:capitalize;white-space:nowrap}.collection-badge.settled,.collection-badge.reconciled{background:#e8f7ef;color:#267245}.collection-badge.partially_collected{background:#e8f4fb;color:#286b94}.collection-badge.pending_reconciliation{background:#fff3dd;color:#8a6413}.collection-badge.rejected{background:#fff0f0;color:#9b3438}.aging-late{color:#a33b43;font-weight:700}.collection-empty{padding:24px!important;text-align:center!important;color:#7a8c9c!important}.collection-review-list{padding:6px 15px}.collection-review-list article{display:grid;grid-template-columns:minmax(150px,1fr) auto auto auto;align-items:center;gap:9px;padding:11px 0;border-bottom:1px solid #edf1f4}.collection-review-list b,.collection-review-list small{display:block}.collection-review-list b{font-size:12px}.collection-review-list small{margin-top:4px;color:#8291a0;font-size:10px}.collection-review-list strong{font-size:12px}.collection-row-actions{display:flex;gap:7px;margin-top:7px}.collection-row-actions button{border:0;background:none;padding:0;color:#147898;font-size:10px;font-weight:700;cursor:pointer}.collection-overlay{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;background:#102b4166;padding:18px}.collection-dialog{width:min(680px,100%);max-height:min(90vh,800px);overflow:auto;border:1px solid #dce5ec;border-radius:13px;background:#fff;padding:21px;box-shadow:0 24px 80px #102b4133}.collection-dialog>header{display:flex;justify-content:space-between;gap:12px;margin-bottom:17px}.collection-dialog h2{margin:5px 0;color:#173b59;font-size:20px}.collection-dialog header p{margin:3px 0;color:#718397;font-size:12px}.collection-close{border:0;background:transparent;color:#677c90;font-size:25px;cursor:pointer}.collection-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.collection-form-grid label{display:grid;gap:6px;color:#38576f;font-size:11px;font-weight:700}.collection-form-grid .wide{grid-column:1/-1}.collection-allocation-note{margin:14px 0 0;border-radius:7px;background:#f0f8fb;padding:10px;color:#30657b;font-size:11px}.collection-dialog footer{display:flex;justify-content:flex-end;gap:8px;margin-top:18px;padding-top:15px;border-top:1px solid #e7edf2}.collection-dialog button:focus-visible,.collection-tabs button:focus-visible,.collection-toolbar input:focus-visible,.collection-toolbar select:focus-visible,.collection-form-grid input:focus-visible,.collection-form-grid select:focus-visible{outline:3px solid #22a8c2;outline-offset:2px}.collection-pagination{display:flex;align-items:center;justify-content:space-between;gap:10px;border-top:1px solid #e7edf2;padding:10px 14px;color:#73879a;font-size:11px}.collection-pagination div{display:flex;gap:7px}.collection-pagination .collection-secondary{min-height:34px;padding:0 11px}.as-of-control{display:flex;align-items:center;gap:8px;color:#60788d;font-size:11px;font-weight:700}.as-of-control input,.bank-match-control select{height:36px;border:1px solid #cfdae4;border-radius:7px;background:#fff;padding:0 10px;color:#29495f;font:inherit}.bank-match-control{display:flex;align-items:center;gap:6px;min-width:200px}.collection-ledger-meta{display:flex;align-items:center;flex-wrap:wrap;gap:12px;padding:12px 16px;color:#536d82;font-size:12px}.collection-ledger-meta b{color:#163b59}.allocation-lines{display:grid;gap:8px}.allocation-line{display:grid;grid-template-columns:minmax(0,1fr) minmax(130px,.5fr) auto;align-items:end;gap:8px}.collection-dialog textarea{border:1px solid #cfdae4;border-radius:7px;padding:9px 11px;font:inherit;color:#29495f;resize:vertical}.collection-dialog textarea:focus-visible,.bank-match-control select:focus-visible{outline:3px solid #22a8c2;outline-offset:2px}@media print{.collection-heading,.collection-tabs,.collection-toolbar,.collection-primary,.collection-secondary,.collection-pagination{display:none!important}.collection-panel{box-shadow:none;border:0}.collection-table-wrap{overflow:visible}.collection-table-wrap table{min-width:0}}@media(max-width:760px){.collection-heading{align-items:flex-start;flex-direction:column}.collection-heading h1{font-size:24px}.collection-toolbar{flex-wrap:wrap}.collection-toolbar input{flex-basis:100%}.collection-toolbar select{flex:1}.collection-review-list article{grid-template-columns:minmax(120px,1fr) auto auto}.collection-review-list article strong{grid-column:2}.collection-form-grid{grid-template-columns:1fr}.collection-form-grid .wide{grid-column:auto}.allocation-line{grid-template-columns:1fr}.allocation-line .collection-danger{width:max-content}}@media(max-width:420px){.collection-totals{grid-template-columns:1fr 1fr}.collection-review-list article{grid-template-columns:1fr 1fr}.collection-review-list article>span{grid-column:1/-1}.collection-review-list article strong{grid-column:auto}.collection-review-list article button{width:100%;padding:0 8px}}
</style>
