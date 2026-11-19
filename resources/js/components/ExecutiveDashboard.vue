<script setup>
import { computed, onMounted, ref } from 'vue'

defineProps({ canGenerateForecast: { type: Boolean, default: false } })
const month = ref(new Date().toISOString().slice(0, 7))
const data = ref(null)
const loading = ref(false)
const error = ref('')
const forecast = ref(null)
const forecastLoading = ref(false)
const forecastError = ref('')
const series = computed(() => data.value?.series || [])
const maxVolume = computed(() => Math.max(1, ...series.value.flatMap(row => [row.forecast, row.actual])))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await fetch(`/api/executive/dashboard?month=${encodeURIComponent(month.value)}`, { headers: { Accept: 'application/json' } })
    if (!response.ok) throw new Error(response.status === 403 ? 'Your account is not authorized to view enterprise executive reporting.' : response.status === 422 ? 'Choose a valid reporting month.' : 'Executive reporting data could not be loaded.')
    data.value = await response.json()
  } catch (cause) {
    error.value = cause.message || 'Executive reporting data could not be loaded.'
  } finally {
    loading.value = false
  }
}

async function generateForecast() {
  forecastLoading.value = true
  forecastError.value = ''
  forecast.value = null
  try {
    const response = await fetch('/api/executive/forecast', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: JSON.stringify({ horizon_months: 3 }) })
    const result = await response.json()
    if (!response.ok) throw new Error(result.detail || (response.status === 403 ? 'Forecast generation is not authorized for this account.' : 'The forecasting service is unavailable.'))
    forecast.value = result
  } catch (cause) {
    forecastError.value = cause.message || 'The forecast could not be generated.'
  } finally {
    forecastLoading.value = false
  }
}

function number(value) { return new Intl.NumberFormat().format(value ?? 0) }
function amountRows(rows) { return rows?.length ? rows.map(row => `${row.currency} ${Number(row.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`).join(' · ') : 'No verified source values' }
function shortMonth(value) { return new Intl.DateTimeFormat(undefined, { month: 'short', year: '2-digit', timeZone: 'UTC' }).format(new Date(`${value}-01T00:00:00Z`)) }

onMounted(load)
</script>

<template>
  <main class="executive-page">
    <header class="executive-heading">
      <div><div class="executive-eyebrow">INTERFREIGHT GROUP / EXECUTIVE INTELLIGENCE</div><h1>Executive Command Center</h1><p>One view of forecast demand, shipment progress and verified financial activity.</p></div>
      <div class="executive-period"><label for="executive-month">Reporting month</label><input id="executive-month" v-model="month" type="month" @change="load"><button type="button" :disabled="loading" @click="load">{{ loading ? 'Refreshing…' : 'Refresh' }}</button></div>
    </header>

    <div v-if="error" class="executive-message executive-error" role="alert">{{ error }} <button type="button" @click="load">Retry</button></div>
    <div v-else-if="loading && !data" class="executive-message" role="status">Loading verified IFC reporting data…</div>
    <template v-if="data">
      <div class="executive-scope"><span><b>{{ data.period.label }}</b><i>Live operational source records</i></span><span>All company totals use distinct master shipments. Financial values retain their source currency.</span></div>

      <section class="executive-kpis" aria-label="Shipment overview">
        <article><small>Forecasted shipments</small><b>{{ number(data.metrics.forecasted_shipments) }}</b><span>Active Sales forecast units</span></article>
        <article><small>Actual master shipments</small><b>{{ number(data.metrics.actual_master_shipments) }}</b><span>Created this month, canceled excluded</span></article>
        <article><small>Operationally completed</small><b>{{ number(data.metrics.operationally_completed) }}</b><span>Operations verification this month</span></article>
        <article><small>Financially closed</small><b>{{ number(data.metrics.financially_closed) }}</b><span>Closing records finalized this month</span></article>
        <article><small>Forecast accuracy</small><b>{{ data.metrics.forecast_accuracy_previous_month_percent === null ? '—' : `${data.metrics.forecast_accuracy_previous_month_percent}%` }}</b><span>{{ shortMonth(data.period.previous_month) }} · completed month comparison</span></article>
      </section>

      <section class="executive-financials" aria-label="Verified financial summary">
        <article><div class="executive-card-label"><span class="executive-mark budget">B</span><span><small>APPROVED BUDGET</small><b>Current month</b></span></div><p>{{ amountRows(data.metrics.approved_budget) }}</p><small class="executive-subline">Utilization: {{ data.metrics.budget_utilization.map(row => `${row.currency} ${row.utilization_percent === null ? 'n/a' : `${row.utilization_percent}%`}`).join(' · ') || 'No approved plan' }}</small></article>
        <article><div class="executive-card-label"><span class="executive-mark expenses">E</span><span><small>VERIFIED EXPENSES</small><b>Incurred this month</b></span></div><p>{{ amountRows(data.metrics.actual_verified_expenses) }}</p></article>
        <article><div class="executive-card-label"><span class="executive-mark budget">I</span><span><small>ISSUED INVOICES</small><b>Issued this month</b></span></div><p>{{ amountRows(data.metrics.issued_invoices) }}</p></article>
        <article><div class="executive-card-label"><span class="executive-mark receivable">C</span><span><small>VERIFIED COLLECTIONS</small><b>Reconciled this month</b></span></div><p>{{ amountRows(data.metrics.verified_collections) }}</p></article>
        <article><div class="executive-card-label"><span class="executive-mark receivable">R</span><span><small>ACCOUNTS RECEIVABLE</small><b>Issued invoices</b></span></div><p>{{ amountRows(data.metrics.accounts_receivable) }}</p><small class="executive-subline">Overdue: {{ amountRows(data.metrics.overdue_receivables) }}</small></article>
        <article><div class="executive-card-label"><span class="executive-mark exception">L</span><span><small>NONRECOVERABLE COSTS</small><b>Verified expense rows</b></span></div><p>{{ amountRows(data.metrics.company_nonrecoverable_expenses) }}</p></article>
        <article><div class="executive-card-label"><span class="executive-mark exception">!</span><span><small>OPEN EXCEPTIONS</small><b>Billing and expense</b></span></div><p>{{ number(data.metrics.open_financial_exceptions) }}</p></article>
      </section>

      <div class="executive-grid">
        <section class="executive-panel executive-trend">
          <header><div><h2>Forecast demand and master jobs</h2><p>Monthly Sales forecast units compared with distinct shipments by planned departure.</p></div><span class="executive-accuracy"><b>{{ data.metrics.forecast_accuracy_previous_month_percent === null ? '—' : `${data.metrics.forecast_accuracy_previous_month_percent}%` }}</b><small>Previous month forecast accuracy</small></span></header>
          <div class="executive-legend"><span><i class="forecast"></i>Sales forecast</span><span><i class="actual"></i>Master shipments</span></div>
          <div class="executive-bars" role="img" aria-label="Twelve month forecast and shipment comparison">
            <div v-for="row in series" :key="row.month" class="executive-bar-month"><div class="executive-bar-pair"><i class="forecast" :style="{ height: `${Math.max(3, row.forecast / maxVolume * 100)}%` }" :title="`Forecast ${row.forecast}`"></i><i class="actual" :style="{ height: `${Math.max(3, row.actual / maxVolume * 100)}%` }" :title="`Master shipments ${row.actual}`"></i></div><small>{{ shortMonth(row.month) }}</small></div>
          </div>
          <footer>Forecast accuracy compares the previous month’s active forecast units with distinct master shipments planned for that month. It is unavailable when no forecast exists.</footer>
          <div v-if="canGenerateForecast" class="executive-forecast-action"><button type="button" :disabled="forecastLoading" @click="generateForecast">{{ forecastLoading ? 'Evaluating history…' : 'Generate 3-month demand forecast' }}</button><span v-if="forecastError" role="alert">{{ forecastError }}</span></div>
          <div v-if="forecast" class="executive-forecast-result" aria-live="polite"><template v-if="forecast.status === 'ready'"><b>Deterministic shipment demand baseline</b><div class="executive-forecast-values"><span v-for="point in forecast.forecasts" :key="point.month">{{ shortMonth(point.month.slice(0, 7)) }} <strong>{{ number(point.point_estimate) }}</strong></span></div><p>Model {{ forecast.model_version }} · rolling holdout MAE {{ forecast.evaluation.mae }} · last-observation baseline MAE {{ forecast.evaluation.baseline_mae }}</p></template><p v-else>{{ forecast.limitation }}</p><small>{{ forecast.limitation }}</small></div>
        </section>

        <section class="executive-panel">
          <header><div><h2>Department work activity</h2><p>Assignment workflow counts; not company shipment totals.</p></div></header>
          <div v-if="data.department_activity.length" class="executive-departments"><article v-for="dept in data.department_activity" :key="dept.department"><b>{{ dept.department }}</b><span><strong>{{ number(dept.completed_assignments) }}</strong> completed assignments</span><span><strong>{{ number(dept.open_assignments) }}</strong> open assignments</span></article></div>
          <div v-else class="executive-empty">No department assignment activity was recorded in this month.</div>
        </section>
      </div>

      <section class="executive-integrity"><div><span class="executive-integrity-dot"></span><b>Financial integrity</b></div><p>Authorized commercial revenue, recognized revenue and gross profit are unavailable: the application has no approved enterprise revenue-recognition source. Invoice values and collections remain separately reported. {{ data.period.currency_scope }}</p><small>As of {{ new Date(data.freshness.as_of).toLocaleString() }} · {{ data.period.timezone }}</small></section>
      <details class="executive-definitions"><summary>Metric definitions and source scope</summary><dl><template v-for="(definition, key) in data.definitions" :key="key"><dt>{{ key.replaceAll('_', ' ') }}</dt><dd>{{ definition }}</dd></template></dl></details>
    </template>
  </main>
</template>
