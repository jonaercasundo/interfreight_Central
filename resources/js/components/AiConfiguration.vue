<script setup>
import { computed, onMounted, ref } from 'vue'

const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
const settings = ref({ provider: 'openai', model: 'gpt-5.4-mini', timeout_seconds: 20, max_output_tokens: 500, database_ready: true, enabled: false, tutorials_enabled: true, credential_configured: false, credential_source: 'missing', last_test_status: 'not_tested' })
const usage = ref({ requests: 0, successful: 0, failed: 0, input_tokens: 0, output_tokens: 0, recent: [] })
const apiKey = ref('')
const replaceConfirmed = ref(false)
const removeConfirmation = ref('')
const loading = ref(true)
const refreshing = ref(false)
const saving = ref(false)
const testing = ref(false)
const removing = ref(false)
const error = ref('')
const notice = ref('')
const testResult = ref(null)
const errors = ref({})
const connected = computed(() => settings.value.last_test_status === 'connected')
function date(value) { return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Not recorded' }
function label(value) { return value ? String(value).replaceAll('_', ' ') : 'Not recorded' }

async function request(url, method = 'GET', body = null) {
  const response = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, ...(body ? { body: JSON.stringify(body) } : {}) })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    const exception = new Error(payload.message || payload.detail || 'The request could not be completed.')
    exception.validation = payload.errors || {}
    throw exception
  }
  return payload
}

function apply(payload) {
  if (payload.settings) settings.value = payload.settings
  if (payload.usage) usage.value = payload.usage
}

async function load() {
  const initialLoad = loading.value
  if (initialLoad) loading.value = true
  else refreshing.value = true
  error.value = ''
  try {
    apply(await request('/api/admin/ai-configuration'))
    testResult.value = null
  } catch (exception) { error.value = exception.message } finally {
    loading.value = false
    refreshing.value = false
  }
}

async function save() {
  saving.value = true; error.value = ''; notice.value = ''; errors.value = {}
  try {
    const payload = { enabled: settings.value.enabled, tutorials_enabled: settings.value.tutorials_enabled, model: settings.value.model, timeout_seconds: settings.value.timeout_seconds, max_output_tokens: settings.value.max_output_tokens }
    if (apiKey.value.trim()) { payload.api_key = apiKey.value; payload.confirm_credential_change = replaceConfirmed.value }
    apply(await request('/api/admin/ai-configuration', 'PUT', payload))
    apiKey.value = ''; replaceConfirmed.value = false; notice.value = 'AI settings saved.'
  } catch (exception) { error.value = exception.message; errors.value = exception.validation || {} } finally { saving.value = false }
}

async function testConnection() {
  testing.value = true; error.value = ''; testResult.value = null
  try { const payload = await request('/api/admin/ai-configuration/test', 'POST', {}); apply(payload); testResult.value = payload.result }
  catch (exception) { error.value = exception.message; errors.value = exception.validation || {} }
  finally { testing.value = false }
}

async function removeCredential() {
  if (removeConfirmation.value !== 'REMOVE') return
  removing.value = true; error.value = ''; notice.value = ''
  try { apply(await request('/api/admin/ai-configuration/credential', 'DELETE', { confirmation: removeConfirmation.value })); removeConfirmation.value = ''; notice.value = 'Stored credential reference removed.' }
  catch (exception) { error.value = exception.message }
  finally { removing.value = false }
}

onMounted(load)
</script>

<template>
  <main class="ifc-ai-settings">
    <div class="ifc-ai-heading"><div><p class="ifc-ai-eyebrow">ADMINISTRATION / SYSTEM SETTINGS</p><h1>AI Configuration</h1><p>Manage the IFC Assistant connection and tutorial access.</p></div><span class="ifc-ai-state" :class="connected ? 'is-connected' : ''">{{ connected ? 'Connection verified' : 'Connection not verified' }}</span></div>
    <div v-if="error" class="ifc-ai-alert is-error" role="alert">{{ error }}</div>
    <div v-if="notice" class="ifc-ai-alert is-success" role="status">{{ notice }}</div>
    <div v-if="loading" class="ifc-ai-loading" role="status">Loading AI settings…</div>
    <div v-else-if="!settings.database_ready" class="ifc-ai-alert is-error" role="alert"><b>Setup required:</b> the AI configuration tables are missing from this database. Back up the database, review pending migrations, then apply the approved migrations. Settings can be edited after the schema is installed.</div>
    <template v-else>
      <section class="ifc-ai-grid">
        <article class="ifc-ai-card ifc-ai-config-card">
          <div class="ifc-ai-card-title"><span class="ifc-ai-icon">AI</span><div><h2>Assistant settings</h2><p>OpenAI is called only through the signed IFC service.</p></div></div>
          <label class="ifc-ai-toggle"><span><b>Enable AI Assistant</b><small>Allow authorized users to ask workflow questions.</small></span><input v-model="settings.enabled" type="checkbox" aria-label="Enable AI Assistant" :disabled="!settings.database_ready"></label>
          <label class="ifc-ai-toggle"><span><b>Enable Sales tutorials</b><small>Show the permission-filtered Sales guide in the assistant.</small></span><input v-model="settings.tutorials_enabled" type="checkbox" aria-label="Enable Sales tutorials" :disabled="!settings.database_ready"></label>
          <label class="ifc-ai-field"><span>Provider</span><input value="OpenAI" readonly aria-readonly="true"></label>
          <label class="ifc-ai-field"><span>Model</span><select v-model="settings.model" :disabled="!settings.database_ready"><option value="gpt-5.4-mini">GPT-5.4 mini (recommended fallback)</option><option value="gpt-5.4">GPT-5.4</option><option value="gpt-5.4-nano">GPT-5.4 nano</option><option value="gpt-6.1-sol">GPT-6.1 Sol</option><option value="gpt-6-astra">GPT-6 Astra</option><option value="gpt-6-luna">GPT-6 Luna</option></select><small>Model access depends on the configured OpenAI project. Test the connection after changing models.</small><small v-if="errors.model" class="ifc-ai-field-error">{{ errors.model[0] }}</small></label>
          <div class="ifc-ai-field"><span>Request limits</span><div class="ifc-ai-limit-row"><label>Timeout (seconds)<input v-model.number="settings.timeout_seconds" type="number" min="5" max="60" :disabled="!settings.database_ready"></label><label>Maximum output tokens<input v-model.number="settings.max_output_tokens" type="number" min="64" max="1000" :disabled="!settings.database_ready"></label></div><small>Limits are bounded to protect service availability and response size.</small><small v-if="errors.timeout_seconds" class="ifc-ai-field-error">{{ errors.timeout_seconds[0] }}</small><small v-if="errors.max_output_tokens" class="ifc-ai-field-error">{{ errors.max_output_tokens[0] }}</small></div>
          <div class="ifc-ai-field"><span>API key</span><div class="ifc-ai-key-row"><input v-model="apiKey" type="password" autocomplete="new-password" placeholder="Enter a new key to configure or rotate" maxlength="500" aria-label="New OpenAI API key" :disabled="!settings.database_ready"><span v-if="settings.credential_configured" class="ifc-ai-mask">{{ settings.masked_credential }} · {{ settings.credential_source }}</span></div><small>The saved key is encrypted on the server and cannot be viewed here.</small><small v-if="!settings.credential_configured" class="ifc-ai-field-error">Setup required: enter an API key before enabling the assistant.</small><label v-if="apiKey" class="ifc-ai-confirm"><input v-model="replaceConfirmed" type="checkbox"> I confirm replacing the configured credential.</label><small v-if="errors.api_key" class="ifc-ai-field-error">{{ errors.api_key[0] }}</small><small v-if="errors.confirm_credential_change" class="ifc-ai-field-error">{{ errors.confirm_credential_change[0] }}</small></div>
          <div class="ifc-ai-actions"><button class="ifc-ai-primary" :disabled="saving || !settings.database_ready" @click="save">{{ saving ? 'Saving…' : 'Save settings' }}</button><button class="ifc-ai-secondary" :disabled="testing || !settings.database_ready || !settings.enabled || !settings.credential_configured" @click="testConnection">{{ testing ? 'Testing…' : 'Test connection' }}</button></div>
          <div v-if="testResult" class="ifc-ai-test-result" :class="testResult.status === 'connected' ? 'is-success' : 'is-error'" role="status"><b>{{ testResult.status === 'connected' ? 'Connection successful' : 'Connection test failed' }}</b><span>{{ testResult.message || testResult.category || `Model ${testResult.model} responded to the minimal test.` }}</span><small v-if="testResult.category">Diagnostic: {{ label(testResult.category) }}</small></div>
        </article>
        <article class="ifc-ai-card ifc-ai-connection-card"><div class="ifc-ai-section-heading"><h2>Connection status</h2><button class="ifc-ai-link" type="button" :disabled="refreshing" @click="load">{{ refreshing ? 'Refreshing…' : 'Refresh status' }}</button></div><p>Last tested: <b>{{ date(settings.last_tested_at) }}</b></p><p>Last successful test: <b>{{ date(settings.last_successful_test_at) }}</b></p><p>Status: <b>{{ label(settings.last_test_status) }}</b></p><hr><h3>Remove administrator-managed key</h3><p class="ifc-ai-muted">This removes the encrypted key. An environment-managed key, if configured, remains available.</p><div class="ifc-ai-remove-row"><input v-model="removeConfirmation" placeholder="Type REMOVE" aria-label="Type REMOVE to confirm key removal" :disabled="!settings.database_ready"><button class="ifc-ai-danger" :disabled="removing || !settings.database_ready || removeConfirmation !== 'REMOVE' || settings.credential_source !== 'administrator'" @click="removeCredential">{{ removing ? 'Removing…' : 'Remove key' }}</button></div></article>
      </section>
      <section class="ifc-ai-card ifc-ai-usage"><div class="ifc-ai-section-heading"><div><h2>Usage monitoring</h2><p>Recorded requests from the last {{ usage.period_days }} days. Prompt and response text are not stored.</p></div><button class="ifc-ai-link" @click="load">Refresh</button></div><div class="ifc-ai-metrics"><div><small>Requests</small><b>{{ usage.requests }}</b></div><div><small>Successful</small><b>{{ usage.successful }}</b></div><div><small>Failed</small><b>{{ usage.failed }}</b></div><div><small>Tokens in / out</small><b>{{ usage.input_tokens }} / {{ usage.output_tokens }}</b></div></div><div class="ifc-ai-table-wrap"><table><thead><tr><th>Time</th><th>Model</th><th>Status</th><th>Diagnostic</th><th>Latency</th></tr></thead><tbody><tr v-for="(event, index) in usage.recent" :key="`${event.created_at}-${index}`"><td>{{ date(event.created_at) }}</td><td>{{ event.model || '—' }}</td><td>{{ label(event.status) }}</td><td>{{ label(event.error_category) }}</td><td>{{ event.latency_ms == null ? '—' : `${event.latency_ms} ms` }}</td></tr><tr v-if="!usage.recent?.length"><td colspan="5" class="ifc-ai-empty">No AI usage recorded in this period.</td></tr></tbody></table></div></section>
      <p class="ifc-ai-note">Connection testing sends a minimal request to OpenAI only after an administrator selects Test connection. API billing and usage limits are managed in your OpenAI account.</p>
    </template>
  </main>
</template>

<style scoped>
.ifc-ai-settings{width:100%;min-width:0;max-width:1440px;margin:0 auto;padding:38px 42px 52px;color:#122f4d}.ifc-ai-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:26px}.ifc-ai-heading h1{margin:5px 0;font-size:32px;letter-spacing:-.04em}.ifc-ai-heading p{margin:0;color:#71839a}.ifc-ai-eyebrow{font-size:11px!important;font-weight:800;letter-spacing:.12em;color:#62809f!important}.ifc-ai-state{border:1px solid #d8e2ed;background:#f7f9fc;color:#60748b;border-radius:20px;padding:8px 12px;font-size:12px;font-weight:700}.ifc-ai-state.is-connected{background:#ecf8f2;color:#18845b;border-color:#cbe9d9}.ifc-ai-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));gap:18px;min-width:0}.ifc-ai-card{min-width:0;border:1px solid #dce5ee;border-radius:14px;background:#fff;box-shadow:0 8px 28px #18385808;padding:24px}.ifc-ai-card h2{margin:0 0 6px;font-size:18px}.ifc-ai-card p{margin:0 0 18px;color:#71839a;font-size:13px}.ifc-ai-card-title{display:flex;gap:13px;align-items:center;margin-bottom:20px}.ifc-ai-card-title p{margin:0}.ifc-ai-icon{height:42px;width:42px;border-radius:12px;background:#e8f5fb;color:#14769d;display:grid;place-items:center;font-weight:800}.ifc-ai-toggle{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px 0;border-top:1px solid #edf1f5}.ifc-ai-toggle b,.ifc-ai-field>span{display:block;font-size:13px;font-weight:700}.ifc-ai-toggle small,.ifc-ai-field small{display:block;color:#7b8da2;font-size:12px;margin-top:4px}.ifc-ai-toggle input,.ifc-ai-confirm input{accent-color:#1685a8}.ifc-ai-field{display:block;margin-top:16px}.ifc-ai-field>input,.ifc-ai-field select,.ifc-ai-key-row input,.ifc-ai-remove-row input{box-sizing:border-box;width:100%;height:42px;margin-top:7px;border:1px solid #cfdbe7;border-radius:8px;padding:0 12px;color:#183653;background:white}.ifc-ai-limit-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.ifc-ai-limit-row label{display:grid;gap:6px;color:#36516d;font-size:12px;font-weight:700}.ifc-ai-limit-row input{min-width:0;box-sizing:border-box;width:100%;height:42px;border:1px solid #d5dfeb;border-radius:7px;padding:0 11px;color:#122f4d}.ifc-ai-key-row{display:flex;align-items:center;gap:12px;min-width:0}.ifc-ai-key-row input{min-width:0;flex:1}.ifc-ai-mask{color:#456982;white-space:nowrap;font-size:12px}.ifc-ai-confirm{display:flex;align-items:center;gap:7px;margin-top:9px;font-size:12px;color:#516981}.ifc-ai-field-error{color:#b42318!important}.ifc-ai-actions{display:flex;gap:10px;margin-top:22px}.ifc-ai-actions button,.ifc-ai-danger{height:40px;padding:0 16px;border-radius:8px;font-weight:700;cursor:pointer}.ifc-ai-primary{border:1px solid #12466d;background:#12466d;color:#fff}.ifc-ai-secondary{border:1px solid #ccd9e6;background:#fff;color:#234666}.ifc-ai-actions button:disabled,.ifc-ai-danger:disabled{opacity:.48;cursor:not-allowed}.ifc-ai-connection-card{align-self:start}.ifc-ai-connection-card p{display:flex;justify-content:space-between;gap:10px;margin:16px 0;color:#667c92}.ifc-ai-connection-card p b{color:#244666;text-align:right}.ifc-ai-connection-card hr{border:0;border-top:1px solid #e8edf2;margin:22px 0}.ifc-ai-connection-card h3{font-size:14px;margin:0 0 8px}.ifc-ai-muted{display:block!important}.ifc-ai-remove-row{display:flex;gap:9px}.ifc-ai-remove-row input{margin:0;min-width:0}.ifc-ai-danger{white-space:nowrap;border:1px solid #efc9c5;background:#fff6f5;color:#a72e24}.ifc-ai-test-result{display:flex;flex-direction:column;gap:4px;margin-top:14px;padding:12px;border-radius:8px;font-size:13px}.ifc-ai-test-result.is-success{background:#effaf4;color:#127247}.ifc-ai-test-result.is-error,.ifc-ai-alert.is-error{background:#fff2f0;color:#a72e24}.ifc-ai-usage{margin-top:18px}.ifc-ai-section-heading{display:flex;justify-content:space-between;gap:20px;align-items:start}.ifc-ai-section-heading p{margin-bottom:0}.ifc-ai-link{border:0;background:none;color:#12739a;cursor:pointer;font-weight:700}.ifc-ai-link:disabled{opacity:.6;cursor:wait}.ifc-ai-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:18px 0}.ifc-ai-metrics div{border-radius:9px;background:#f5f8fb;padding:14px}.ifc-ai-metrics small{display:block;color:#73879c;font-size:11px}.ifc-ai-metrics b{display:block;margin-top:6px;font-size:20px}.ifc-ai-table-wrap{overflow-x:auto}.ifc-ai-table-wrap table{width:100%;border-collapse:collapse;text-align:left;font-size:12px}.ifc-ai-table-wrap th{color:#71849a;font-size:10px;text-transform:uppercase;letter-spacing:.06em}.ifc-ai-table-wrap th,.ifc-ai-table-wrap td{padding:11px 9px;border-bottom:1px solid #eef1f5}.ifc-ai-empty{text-align:center;color:#71849a;padding:26px!important}.ifc-ai-note{margin:15px 2px;color:#71849a;font-size:12px}.ifc-ai-alert{padding:12px 14px;margin-bottom:14px;border-radius:8px;font-size:13px}.ifc-ai-alert.is-success{background:#effaf4;color:#127247}.ifc-ai-loading{padding:32px;border:1px solid #dce5ee;border-radius:12px;background:#fff;color:#71839a}@media(max-width:850px){.ifc-ai-settings{padding:28px 22px}.ifc-ai-grid{grid-template-columns:1fr}}@media(max-width:520px){.ifc-ai-limit-row{grid-template-columns:1fr}.ifc-ai-settings{padding:22px 14px}.ifc-ai-heading{align-items:flex-start;flex-direction:column}.ifc-ai-heading h1{font-size:27px}.ifc-ai-card{padding:17px}.ifc-ai-key-row,.ifc-ai-remove-row{align-items:stretch;flex-direction:column}.ifc-ai-mask{white-space:normal}.ifc-ai-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.ifc-ai-actions{flex-wrap:wrap}}
</style>
