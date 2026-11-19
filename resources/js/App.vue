<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import UserManagement from './components/UserManagement.vue'
import RolesPermissions from './components/RolesPermissions.vue'
import AuditLogs from './components/AuditLogs.vue'
import IntegrationHealth from './components/IntegrationHealth.vue'
import ProfileSettings from './components/ProfileSettings.vue'
import SalesWorkspace from './components/SalesWorkspace.vue'
import SalesDashboard from './components/SalesDashboard.vue'
import CommercialWorkspace from './components/CommercialWorkspace.vue'
import BudgetWorkspace from './components/BudgetWorkspace.vue'
import ShipmentWorkspace from './components/ShipmentWorkspace.vue'
import ExpenseWorkspace from './components/ExpenseWorkspace.vue'
import AdditionalChargeWorkspace from './components/AdditionalChargeWorkspace.vue'
import BillingWorkspace from './components/BillingWorkspace.vue'
import CollectionWorkspace from './components/CollectionWorkspace.vue'
import ExecutiveDashboard from './components/ExecutiveDashboard.vue'
import ShipmentClosing from './components/ShipmentClosing.vue'
import AiConfiguration from './components/AiConfiguration.vue'
import AiUsage from './components/AiUsage.vue'
import { buildSidebarNavigation } from './sidebarNavigation.js'
import { renderSafeAssistantMarkdown } from './assistantMarkdown.js'
import { publishBusinessActions } from './businessActionSync.js'

const root = document.querySelector('#app')
const identity = JSON.parse(root?.dataset.user || '{}')
const page = root?.dataset.page || ''
const appTimezone = root?.dataset.timezone || 'UTC'
const baseCurrency = root?.dataset.baseCurrency || 'PHP'
const userManagement = root?.dataset.userManagement ? JSON.parse(root.dataset.userManagement) : null
const roleManagement = root?.dataset.roleManagement ? JSON.parse(root.dataset.roleManagement) : null
const auditManagement = root?.dataset.auditManagement ? JSON.parse(root.dataset.auditManagement) : null
const integrationHealth = root?.dataset.integrationHealth ? JSON.parse(root.dataset.integrationHealth) : null
const profileManagement = root?.dataset.profileManagement ? JSON.parse(root.dataset.profileManagement) : null
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || ''
const permissionSet = new Set(identity.permissions || [])
const can = (name) => permissionSet.has(name)
const departments = [
  ['Sales', 'department.sales.view', '/sales'], ['Operations', 'department.operations.view', '/operations'],
  ['Transport', 'department.transport.view', '/transport'], ['Forwarding', 'department.forwarding.view', '/forwarding'],
  ['Accounting', 'department.accounting.view', '/accounting'], ['Billing', 'department.billing.view', '/billing'],
  ['Finance', 'department.finance.view', '/finance'],
]
const navigation = computed(() => buildSidebarNavigation(identity.roles || [], identity.permissions || []))
const currentPath = window.location.pathname
const allNavigationItems = () => [...navigation.value.administration, ...navigation.value.workspace, ...navigation.value.executive, ...navigation.value.sales, ...navigation.value.accounting, ...navigation.value.finance, ...navigation.value.billing, ...navigation.value.expenseManagement, ...navigation.value.execution.flatMap(group => group.links), ...navigation.value.account]
const authorizedAssistantLinks = computed(() => allNavigationItems().map(item => ({ href: item.href, label: item.label })))
const selected = allNavigationItems().find(item => item.href === currentPath)?.label || (currentPath.startsWith('/shipments/') ? 'Shipment Workspace' : (currentPath === '/dashboard' ? 'Dashboard' : 'Workspace'))
const isDashboard = currentPath === '/dashboard' || currentPath === '/'
const isAdministrator = (identity.roles || []).includes('Administrator')
const isAdministratorDashboard = isDashboard && isAdministrator
const isAdmin = currentPath.startsWith('/admin/')
const isProfile = page === 'profile'
const isExecutive = page === 'executive'
const isBudgetPage = ['accounting-dashboard', 'accounting-overview', 'accounting-shipment-demand', 'accounting-funding-requirements', 'accounting-budget-plans', 'accounting-budget-allocations', 'accounting-cash-forecast', 'accounting-commitments-actuals', 'accounting-budget-variance', 'accounting-budget-history'].includes(page)
const isSalesPage = page.startsWith('sales-') || page === 'sales' || page === 'accounting-forecasts' || (page.startsWith('accounting-') && !isBudgetPage) || page.startsWith('finance-')
const isCommercialPage = page.includes('cost-estimates') || page.includes('quotations') || page.includes('cost-review') || page.includes('cash-requirements') || page.includes('margins') || page.includes('approval-queue')
const isShipmentPage = page.startsWith('shipment-') || page.startsWith('operations-') || page.startsWith('transport-') || page.startsWith('forwarding-')
const isExpensePage = page.startsWith('expense-') || page.startsWith('cash-advance-') || page.startsWith('liquidation-') || page.startsWith('finance-expense') || page === 'my-liquidations' || page === 'cash-advances'
const isAdditionalChargePage = page === 'additional-charges'
const isBillingPage = page === 'billing'
const isCollectionsPage = page === 'collections'
const isDepartment = isShipmentPage
const mobileNavOpen = ref(false)
const desktopCollapsed = ref(localStorage.getItem('ifcSidebarCollapsed') === 'true')
const adminOpen = ref(isAdmin)
const salesOpen = ref(currentPath.startsWith('/sales'))
const executiveOpen = ref(currentPath.startsWith('/executive'))
const executionOpen = ref(Object.fromEntries(navigation.value.execution.map(group => [group.key, currentPath.startsWith(`/${group.key}`) || (currentPath === '/finance/shipment-closing' && group.links.some(item => item.href === currentPath))])))
const accountingOpen = ref(currentPath.startsWith('/accounting') || currentPath === '/finance/shipment-closing' && navigation.value.accounting.some(item => item.href === currentPath))
const financeOpen = ref(currentPath.startsWith('/finance') && currentPath !== '/finance')
const billingOpen = ref(currentPath.startsWith('/billing') || currentPath === '/finance/shipment-closing' && navigation.value.billing.some(item => item.href === currentPath))
const expenseOpen = ref(currentPath.startsWith('/expenses') || currentPath.includes('liquidation') || currentPath.includes('cash-advance'))
watch(desktopCollapsed, (collapsed) => localStorage.setItem('ifcSidebarCollapsed', String(collapsed)))
const assistantOpen = ref(sessionStorage.getItem('ifcAssistantOpen') === 'true')
watch(assistantOpen, open => {
  if (open) sessionStorage.setItem('ifcAssistantOpen', 'true')
  else sessionStorage.removeItem('ifcAssistantOpen')
})
const assistantMenu = ref('')
const tutorialBusy = ref(false)
const tutorialError = ref('')
const tutorialCatalog = ref({ version: 1, tutorials: [], inquiries: [], suggested_questions: [] })
const activeTutorialKey = ref('')
const tutorialStepIndex = ref(0)
const selectedInquiryId = ref('')
const busy = ref(true)
const error = ref('')
const dashboard = ref({ metrics: {}, integration: 'checking', recent_events: [], recent_users: [], system_health: null, checked_at: null })
const shipments = ref([])
const message = ref('')
const chatMessages = ref([])
const assistantShouldAutoScroll = ref(true)
watch(chatMessages, async () => {
  await nextTick()
  if (assistantHistoryRef.value && assistantShouldAutoScroll.value) assistantHistoryRef.value.scrollTop = assistantHistoryRef.value.scrollHeight
}, { deep: true, flush: 'post' })
const pendingAssistantFiles = ref([])
const chatBusy = ref(false)
const conversationList = ref([])
const conversationError = ref('')
const activeConversationId = ref(sessionStorage.getItem(`ifcAssistantConversation:${identity.id || 'user'}`) || '')
const assistantHistoryRef = ref(null)
const assistantMessageInput = ref(null)
function updateAssistantScrollIntent(event) {
  const element = event?.target || assistantHistoryRef.value
  if (element) assistantShouldAutoScroll.value = element.scrollHeight - element.scrollTop - element.clientHeight < 100
}
function resizeAssistantComposer() {
  const input = assistantMessageInput.value
  if (!input) return
  input.style.height = 'auto'
  input.style.height = `${Math.min(input.scrollHeight, 144)}px`
  input.style.overflowY = input.scrollHeight > 144 ? 'auto' : 'hidden'
}
watch(message, async () => { await nextTick(); resizeAssistantComposer() })
const assistantDocuments = ref([])
const assistantUploadProgress = ref(0)
const assistantUploadError = ref('')
const assistantUploading = ref(false)
const assistantFormSession = ref(null)
const assistantFormSessions = ref([])
const assistantClientFormSession = ref(null)
const assistantClientFormError = ref('')
const assistantClientFormBusy = ref(false)
const assistantSavedActionStorageKey = `ifc:lastAssistantAction:${identity.id || 'user'}`
const assistantSavedAction = ref(null)
try { assistantSavedAction.value = JSON.parse(sessionStorage.getItem(assistantSavedActionStorageKey) || 'null') } catch { sessionStorage.removeItem(assistantSavedActionStorageKey) }
const validInquiryId = value => /^\d+$/.test(String(value ?? '')) && Number.isSafeInteger(Number(value)) && Number(value) > 0
if (assistantSavedAction.value && !validInquiryId(assistantSavedAction.value.entity_id)) {
  assistantSavedAction.value = null
  sessionStorage.removeItem(assistantSavedActionStorageKey)
}
const assistantWorkflowInquiry = ref(null)
const assistantWorkflowBusy = ref(false)
const assistantWorkflowError = ref('')
const assistantForecast = ref(null)
const assistantForecastBusy = ref(false)
const assistantForecastError = ref('')
const forecastText = (english, taglish, filipino) => assistantForecast.value?.language === 'filipino' ? filipino : assistantForecast.value?.language === 'taglish' ? taglish : english

async function startAssistantForecast() {
  if (!can('forecasts.create') || !can('inquiries.view') || !validInquiryId(assistantSavedAction.value?.entity_id)) return
  assistantForecastBusy.value = true
  assistantForecastError.value = ''
  try {
    const response = await fetch(`/api/sales/inquiries/${assistantSavedAction.value.entity_id}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    const payload = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(payload.message || `Inquiry lookup failed (${response.status}).`)
    const inquiry = payload.data
    if (!['submitted', 'under_review', 'forecasted'].includes(inquiry.status)) throw new Error('Submit this inquiry before creating a forecast.')
    const recentUserText = chatMessages.value.filter(item => item.role === 'user').slice(-5).map(item => item.content || '').join(' ')
    const language = /\b(filipino|tagalog)\b/i.test(recentUserText) ? 'filipino' : /\b(paano|gawa|gusto|natin|tulong|sige|ang|para|ako|po)\b/i.test(recentUserText) ? 'taglish' : 'english'
    assistantForecast.value = { inquiry, month: inquiry.requested_ship_date?.slice(0, 7) || '', count: '', confidence: '', step: inquiry.requested_ship_date ? 'count' : 'month', language }
    const details = `${inquiry.reference}: ${inquiry.client?.company_name}, ${inquiry.origin} → ${inquiry.destination}, ${inquiry.service_type} / ${inquiry.transport_mode}`
    chatMessages.value.push({ role: 'assistant', content: forecastText(`Sure! I'll prepare the shipment forecast using ${details}. ${inquiry.requested_ship_date ? 'The requested date is saved. How many shipments do you expect?' : 'Which month do you expect the shipment?'}`, `Sige! Ihahanda ko ang forecast gamit ang ${details}. ${inquiry.requested_ship_date ? 'Naka-save na ang date. Ilang shipment ang inaasahan mo?' : 'Anong buwan mo inaasahan ang shipment?'}`, `Sige. Ihahanda ko ang pagtataya gamit ang ${details}. ${inquiry.requested_ship_date ? 'Nakatala na ang petsa. Ilang kargamento ang inaasahan mo?' : 'Sa anong buwan inaasahan ang kargamento?'}`) })
  } catch (error) { assistantForecastError.value = error.message || 'The inquiry could not be loaded.' }
  finally { assistantForecastBusy.value = false }
}

function advanceAssistantForecast() {
  const draft = assistantForecast.value
  if (!draft) return
  assistantForecastError.value = ''
  if (draft.step === 'month') {
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(draft.month)) { assistantForecastError.value = 'Choose a forecast month.'; return }
    draft.step = 'count'
  } else if (draft.step === 'count') {
    if (!Number.isInteger(Number(draft.count)) || Number(draft.count) < 1 || Number(draft.count) > 10000) { assistantForecastError.value = 'Enter 1 to 10,000 expected shipments.'; return }
    draft.step = 'confidence'
  } else if (draft.step === 'confidence') {
    if (!['tentative', 'probable', 'confirmed'].includes(draft.confidence)) { assistantForecastError.value = 'Choose a confidence level.'; return }
    draft.step = 'preview'
  }
}

async function confirmAssistantForecast() {
  const draft = assistantForecast.value
  if (!draft || draft.step !== 'preview' || assistantForecastBusy.value) return
  assistantForecastBusy.value = true
  assistantForecastError.value = ''
  try {
    const response = await fetch('/api/sales/forecasts', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ inquiry_id: draft.inquiry.id, forecast_month: `${draft.month}-01`, expected_shipment_count: Number(draft.count), confidence: draft.confidence }) })
    const payload = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(Object.values(payload.errors || {}).flat().join(' ') || payload.message || `Forecast creation failed (${response.status}).`)
    publishBusinessActions([{ action: 'shipment_forecast.created', operation_status: 'success', entity_type: 'shipment_forecast', entity_id: payload.data.id, reference_number: `Forecast #${payload.data.id}` }], identity.id)
    assistantSavedAction.value = { ...assistantSavedAction.value, status: 'forecasted' }
    sessionStorage.setItem(assistantSavedActionStorageKey, JSON.stringify(assistantSavedAction.value))
    chatMessages.value.push({ role: 'assistant', content: `Shipment forecast #${payload.data.id} was saved for ${draft.inquiry.reference}. The inquiry is now forecasted. The Forecast Calendar and Sales Dashboard have been refreshed.` })
    assistantForecast.value = null
  } catch (error) { assistantForecastError.value = error.message || 'The forecast could not be saved.' }
  finally { assistantForecastBusy.value = false }
}
const assistantFormAnswer = ref('')
const assistantFormBusy = ref(false)
const assistantFormError = ref('')
const assistantFormEditField = ref('')
const assistantFormEditValue = ref('')
const assistantFormEditCountry = ref('')
const assistantFormEditLocations = ref([])
const assistantInquiryReviewFields = [['client_id', 'Client'], ['service_type', 'Service type'], ['transport_mode', 'Transport mode'], ['origin', 'Origin'], ['destination', 'Destination'], ['cargo_description', 'Cargo description']]
const assistantFormChoices = computed(() => {
  const field = assistantFormSession.value?.current_field
  if (['origin', 'destination', 'origin_location_id', 'destination_location_id'].includes(field)) return assistantFormSession.value?.suggested_choices || []
  return ({ client_kind: ['Existing client', 'New client'], service_type: ['LCL', 'FCL'], client_type: ['LCL', 'FCL', 'Mixed'], transport_mode: ['Sea', 'Air', 'Road', 'Rail'] }[field] || [])
})
const assistantSessionKey = sessionStorage.getItem('ifcAssistantDocumentSession') || crypto.randomUUID()
sessionStorage.setItem('ifcAssistantDocumentSession', assistantSessionKey)
const toast = ref('')
const dashboardDateTime = computed(() => formatDateTime(dashboard.value.checked_at || new Date().toISOString()))
const activeTutorial = computed(() => tutorialCatalog.value.tutorials?.find(item => item.key === activeTutorialKey.value) || null)
const activeTutorialStep = computed(() => activeTutorial.value?.steps?.[tutorialStepIndex.value] || '')
const tutorialProgress = computed(() => activeTutorial.value?.steps?.length ? Math.round(((tutorialStepIndex.value + 1) / activeTutorial.value.steps.length) * 100) : 0)
const systemHealthRows = computed(() => {
  const health = dashboard.value.system_health || {}
  return [
    { label: 'Laravel Application', ...(health.application || {}) },
    { label: 'Database', ...(health.database || {}) },
    ...(can('integrations.status.view') ? [
      { label: 'Python FastAPI', ...(health.service || {}) },
      { label: 'Protected Authentication', ...(health.authentication || {}) },
    ] : []),
  ]
})
const adminQuickActions = computed(() => [
  ...(can('users.create') && can('users.view') ? [{ label: 'Add New User', detail: 'Create an account', href: '/admin/users', icon: 'user' }] : []),
  ...(can('roles.view') ? [{ label: 'Manage Roles & Permissions', detail: 'Review system access', href: '/admin/roles', icon: 'roles' }] : []),
  ...(can('audit.view') ? [{ label: 'View Audit Logs', detail: 'Review security activity', href: '/admin/audit', icon: 'audit' }] : []),
  ...(can('integrations.status.view') ? [{ label: 'Integration Health', detail: 'Check connected services', href: '/admin/integrations', icon: 'health' }] : []),
  ...(can('admin.ai-usage.view') ? [{ label: 'AI Usage & Costs', detail: 'Monitor tokens, PHP estimates and budgets', href: '/admin/ai-usage', icon: 'health' }] : []),
])

function formatDateTime(value) {
  if (!value) return 'Not recorded'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Not recorded' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: appTimezone }).format(date)
}

function syncIdentity(profile) {
  identity.name = profile.name
  identity.email = profile.email
}

async function loadDashboard() {
  busy.value = true; error.value = ''
  try {
    const response = await fetch('/api/dashboard', { headers: { Accept: 'application/json' } })
    if (!response.ok) throw new Error(response.status === 403 ? 'Your role cannot view the dashboard.' : 'Dashboard data is unavailable.')
    dashboard.value = await response.json()
    if (can('shipments.view') && !isAdministratorDashboard) {
      const result = await fetch('/api/shipments-workspace?per_page=8', { headers: { Accept: 'application/json' } })
      if (result.ok) shipments.value = (await result.json()).data || []
    }
  } catch (e) { error.value = e.message } finally { busy.value = false }
}

async function askAssistant() {
  const prompt = message.value.trim()
  if (!prompt && !pendingAssistantFiles.value.length) return
  if (!activeConversationId.value && !await startNewConversation()) return
  conversationError.value = ''
  let uploadedFiles = []
  if (pendingAssistantFiles.value.length) {
    try {
      uploadedFiles = await uploadAssistantDocuments(pendingAssistantFiles.value)
      pendingAssistantFiles.value = []
    } catch (error) {
      chatMessages.value.push({ role: 'assistant', content: error.message || 'The attachment could not be uploaded.', live_ai: false })
      return
    }
  }
  message.value = ''
  if (assistantForecast.value && prompt) {
    chatMessages.value.push({ role: 'user', content: prompt, attachments: uploadedFiles })
    if (uploadedFiles.length) chatMessages.value.push({ role: 'assistant', content: 'I cannot read attachments for a forecast. Please enter the requested value directly.' })
    const draft = assistantForecast.value
    if (draft.step === 'month') draft.month = prompt
    else if (draft.step === 'count') draft.count = prompt
    else if (draft.step === 'confidence') draft.confidence = prompt.toLowerCase()
    else { chatMessages.value.push({ role: 'assistant', content: 'Please review the preview and confirm or change a field.' }); return }
    advanceAssistantForecast()
    if (assistantForecastError.value) chatMessages.value.push({ role: 'assistant', content: assistantForecastError.value })
    else if (draft.step === 'count') chatMessages.value.push({ role: 'assistant', content: forecastText('How many shipments do you expect?', 'Ilang shipment ang inaasahan mo?', 'Ilang kargamento ang inaasahan mo?') })
    else if (draft.step === 'confidence') chatMessages.value.push({ role: 'assistant', content: forecastText('How confident is this forecast: tentative, probable, or confirmed?', 'Gaano ka kasigurado: tentative, probable, o confirmed?', 'Gaano katiyak: tentative, probable, o confirmed?') })
    else chatMessages.value.push({ role: 'assistant', content: forecastText('Please review the forecast preview below. I will save it only after you confirm.', 'Paki-review ang forecast preview. Ise-save ko lang kapag kinumpirma mo.', 'Pakisuri ang pagtataya sa ibaba. Itatala ko lamang ito kapag pinagtibay mo.') })
    return
  }
  if (assistantClientFormSession.value?.status === 'collecting' && prompt) {
    chatMessages.value.push({ role: 'user', content: prompt, attachments: uploadedFiles })
    if (uploadedFiles.length) chatMessages.value.push({ role: 'assistant', content: 'The file was uploaded securely. Document extraction is not available for client forms yet; I will not use it to fill or save client details.', live_ai: false })
    await answerAssistantClientForm(prompt)
    return
  }
  if (assistantFormSession.value?.status === 'collecting' && prompt) {
    chatMessages.value.push({ role: 'user', content: prompt, attachments: uploadedFiles })
    if (uploadedFiles.length) chatMessages.value.push({ role: 'assistant', content: 'Your file is attached securely. IFC document reading is not available yet, so I can’t extract its contents.', live_ai: false })
    assistantFormAnswer.value = prompt
    await answerAssistantInquiry()
    if (assistantFormSession.value?.status === 'collecting') chatMessages.value.push({ role: 'assistant', content: assistantFormSession.value.question })
    else if (assistantFormSession.value?.status === 'review') chatMessages.value.push({ role: 'assistant', content: 'We have the required details. Please review them below; I won’t save the draft until you confirm.' })
    return
  }
  chatMessages.value.push({ role: 'user', content: prompt || 'I attached a document.', attachments: uploadedFiles })
  if (uploadedFiles.length) chatMessages.value.push({ role: 'assistant', content: 'Your file is attached securely. IFC document reading is not available yet, so I can’t extract its contents. I can still help with your question or the next workflow step.', live_ai: false })
  if (!prompt) return
  chatBusy.value = true
  try {
    const body = { message: prompt, conversation_id: activeConversationId.value }
    if (currentPath.startsWith('/sales') || currentPath === '/admin/roles') {
      body.context_module = 'sales'
      body.context_page = currentPath
      if (selectedInquiryId.value) body.inquiry_id = Number(selectedInquiryId.value)
    }
    const response = await fetch('/api/assistant', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify(body) })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.message || payload.detail || `Assistant request failed (${response.status}).`)
    if (payload.workflow_session?.form_key === 'client_profile') {
      assistantClientFormSession.value = payload.workflow_session
      assistantClientFormError.value = ''
      publishAssistantClientForm(currentPath === '/sales/clients')
    }
    let responseText = payload.answer || 'No answer was returned.'
    if (payload.live_ai === false || payload.degraded === true) responseText = `Live AI is temporarily unavailable. Here’s verified IFC guidance instead.\n\n${responseText}`
    chatMessages.value.push({ role: 'assistant', content: responseText, live_ai: payload.live_ai !== false && payload.degraded !== true })
    if (payload.tutorial_key) activeTutorialKey.value = payload.tutorial_key
  } catch (error) {
    chatMessages.value.push({ role: 'assistant', content: `${error.message || 'Live AI assistance is temporarily unavailable.'} Open Tutorials for verified static guidance.`, live_ai: false })
  } finally { chatBusy.value = false }
}

async function openAssistant() {
  assistantOpen.value = true
  assistantMenu.value = ''
  loadAssistantDocuments()
  await loadAssistantFormSessions()
  await loadAssistantConversations()
  if (activeConversationId.value) await resumeAssistantConversation(activeConversationId.value, false)
  else await startNewConversation()
  if (tutorialCatalog.value.tutorials?.length) return
  tutorialBusy.value = true
  tutorialError.value = ''
  try {
    const query = new URLSearchParams({ path: currentPath })
    const response = await fetch(`/api/assistant/tutorials?${query}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.message || `Tutorials unavailable (${response.status}).`)
    tutorialCatalog.value = payload
    if (payload.current_tutorial) activeTutorialKey.value = payload.current_tutorial
    const saved = JSON.parse(localStorage.getItem(`ifcTutorialProgress:${identity.id || 'user'}`) || '{}')
    if (saved.key && payload.tutorials.some(item => item.key === saved.key)) {
      activeTutorialKey.value = saved.key
      tutorialStepIndex.value = Math.min(saved.step || 0, payload.tutorials.find(item => item.key === saved.key).steps.length - 1)
    }
  } catch (error) { tutorialError.value = error.message || 'Verified tutorial content could not be loaded.' }
  finally { tutorialBusy.value = false }
}

async function loadAssistantConversations() {
  try {
    const response = await fetch('/api/assistant/conversations', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    if (response.ok) conversationList.value = (await response.json()).data || []
  } catch { conversationError.value = 'Conversation history is temporarily unavailable.' }
}

async function startNewConversation() {
  try {
    const response = await fetch('/api/assistant/conversations', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken } })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.message || 'Could not start a new conversation.')
    activeConversationId.value = payload.data.conversation_id
    assistantShouldAutoScroll.value = true
    sessionStorage.setItem(`ifcAssistantConversation:${identity.id || 'user'}`, activeConversationId.value)
    chatMessages.value = []
    assistantFormSession.value = null
    assistantClientFormSession.value = null
    assistantSavedAction.value = null
    assistantWorkflowInquiry.value = null
    sessionStorage.removeItem(assistantSavedActionStorageKey)
    assistantMenu.value = ''
    conversationError.value = ''
    await loadAssistantConversations()
    return true
  } catch (error) { conversationError.value = error.message || 'Could not start a new conversation.'; return false }
}

async function resumeAssistantConversation(id, toggle = true) {
  try {
    const response = await fetch(`/api/assistant/conversations/${encodeURIComponent(id)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.message || 'That conversation is unavailable.')
    activeConversationId.value = payload.data.conversation_id
    assistantShouldAutoScroll.value = true
    sessionStorage.setItem(`ifcAssistantConversation:${identity.id || 'user'}`, activeConversationId.value)
    chatMessages.value = payload.data.messages || []
    assistantFormSession.value = assistantFormSessions.value.find(item => item.conversation_uuid === id) || null
    await loadAssistantClientFormSession()
    if (toggle) assistantMenu.value = ''
  } catch (error) {
    conversationError.value = error.message || 'That conversation is unavailable.'
    if (activeConversationId.value === id) {
      activeConversationId.value = ''
      sessionStorage.removeItem(`ifcAssistantConversation:${identity.id || 'user'}`)
    }
  }
}

async function deleteAssistantConversation(id) {
  if (!window.confirm('Delete this conversation? This cannot be undone.')) return
  const response = await fetch(`/api/assistant/conversations/${encodeURIComponent(id)}`, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken } })
  if (!response.ok) { conversationError.value = 'The conversation could not be deleted.'; return }
  conversationList.value = conversationList.value.filter(item => item.conversation_uuid !== id)
  if (activeConversationId.value === id) await startNewConversation()
}

async function renameAssistantConversation(conversation) {
  const title = window.prompt('Rename conversation', conversation.title || 'IFC conversation')
  if (title === null || !title.trim()) return
  const response = await fetch(`/api/assistant/conversations/${encodeURIComponent(conversation.conversation_uuid)}`, {
    method: 'PATCH', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
    body: JSON.stringify({ title: title.trim() }),
  })
  if (!response.ok) { conversationError.value = 'The conversation name could not be saved.'; return }
  conversation.title = title.trim()
}

async function loadAssistantFormSessions() {
  if (!can('inquiries.create')) return
  try {
    const response = await fetch('/api/assistant/form-sessions', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    if (response.ok) {
      assistantFormSessions.value = (await response.json()).data || []
      assistantFormSession.value = assistantFormSessions.value.find(item => item.conversation_uuid === activeConversationId.value) || assistantFormSession.value
    }
  } catch { /* Resume list remains unavailable until the connection returns. */ }
}

async function loadAssistantClientFormSession() {
  if (!can('clients.create')) return
  try {
    const query = activeConversationId.value ? `?conversation_uuid=${encodeURIComponent(activeConversationId.value)}` : ''
    const response = await fetch(`/api/assistant/client-form-sessions/active${query}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    if (!response.ok) return
    const payload = await response.json()
    if (payload.data) {
      assistantClientFormSession.value = payload.data
      if (['collecting', 'review'].includes(payload.data.status) && currentPath === '/sales/clients') publishAssistantClientForm(true)
    }
  } catch { /* The form can be resumed when the assistant service is available. */ }
}

function publishAssistantClientForm(open = false) {
  const session = assistantClientFormSession.value
  if (!session) return
  window.dispatchEvent(new CustomEvent('ifc:assistant-client-form', { detail: {
    session_id: session.id, values: session.values || {}, source_fields: session.source_fields || Object.keys(session.values || {}), open,
  } }))
}

async function answerAssistantClientForm(answer) {
  const session = assistantClientFormSession.value
  if (!session || !answer.trim()) return
  assistantClientFormBusy.value = true
  assistantClientFormError.value = ''
  try {
    const response = await fetch(`/api/assistant/client-form-sessions/${session.id}/answer`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify({ answer, conversation_uuid: activeConversationId.value || null }),
    })
    const payload = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(payload.errors?.answer?.[0] || payload.message || 'That answer could not be added to the client form.')
    assistantClientFormSession.value = payload.data
    publishAssistantClientForm(currentPath === '/sales/clients')
    chatMessages.value.push({ role: 'assistant', content: payload.data.status === 'review'
      ? "I've completed the client form. Please review the details below. I won't save it until you confirm."
      : payload.data.question })
  } catch (error) { assistantClientFormError.value = error.message || 'That answer could not be added to the form.' }
  finally { assistantClientFormBusy.value = false }
}

async function saveAssistantClientForm(values = null, sessionId = null) {
  const session = assistantClientFormSession.value
  if (!session || session.status !== 'review' || assistantClientFormBusy.value || (sessionId && Number(sessionId) !== Number(session.id))) return
  assistantClientFormBusy.value = true
  assistantClientFormError.value = ''
  try {
    const response = await fetch(`/api/assistant/client-form-sessions/${session.id}/save`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify({ confirmed: true, conversation_uuid: activeConversationId.value || null, ...(values ? { values } : {}) }),
    })
    const payload = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(payload.message || Object.values(payload.errors || {}).flat().join(' ') || `Client save failed (${response.status}).`)
    assistantClientFormSession.value = payload.data
    assistantClientFormSession.value.next_steps = payload.next_steps
    publishBusinessActions(payload.action_results || [], identity.id)
    publishAssistantClientForm(false)
    window.dispatchEvent(new CustomEvent('ifc:assistant-client-form-saved', { detail: payload.client }))
  } catch (error) { assistantClientFormError.value = error.message || 'The client could not be saved.' }
  finally { assistantClientFormBusy.value = false }
}

window.addEventListener('ifc:assistant-client-form-save', event => {
  saveAssistantClientForm(event.detail?.values || null, event.detail?.session_id || null)
})

function openAssistantClientForm() {
  const session = assistantClientFormSession.value
  if (!session) return
  if (currentPath !== '/sales/clients') {
    window.location.assign(`/sales/clients?assistant_session=${encodeURIComponent(session.id)}`)
    return
  }
  publishAssistantClientForm(true)
}

function handleAssistantClientNextStep(key) {
  const client = assistantClientFormSession.value?.client
  if (!client) return
  if (key === 'start_sales_inquiry') window.location.assign(`/sales/inquiries?client_id=${encodeURIComponent(client.id)}`)
}

async function startAssistantInquiry() {
  assistantFormBusy.value = true
  assistantFormError.value = ''
  try {
    const response = await fetch('/api/assistant/form-sessions', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ session_uuid: crypto.randomUUID(), conversation_uuid: activeConversationId.value || undefined }) })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.message || 'Could not start a Sales Inquiry session.')
    assistantFormSession.value = payload.data
    assistantFormAnswer.value = ''
    assistantFormSessions.value = assistantFormSessions.value.filter(item => item.id !== payload.data.id)
  } catch (error) { assistantFormError.value = error.message || 'Could not start a Sales Inquiry session.' }
  finally { assistantFormBusy.value = false }
}

async function launchAssistantInquiry() {
  await startAssistantInquiry()
  assistantMenu.value = ''
  if (assistantFormSession.value) chatMessages.value.push({ role: 'assistant', content: `Sure, let’s prepare a Sales Inquiry. ${assistantFormSession.value.question}` })
}

async function resumeAssistantInquiry(session) {
  assistantFormSession.value = session
  assistantFormAnswer.value = ''
  assistantFormError.value = ''
  assistantMenu.value = ''
  chatMessages.value.push({ role: 'assistant', content: `Let’s continue your Sales Inquiry. ${session.question || 'Please review the details below.'}` })
}

async function answerAssistantInquiry() {
  if (!assistantFormSession.value || !assistantFormAnswer.value.trim()) return
  assistantFormBusy.value = true
  assistantFormError.value = ''
  try {
    const response = await fetch(`/api/assistant/form-sessions/${assistantFormSession.value.id}/answer`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ answer: assistantFormAnswer.value }) })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.errors?.answer?.[0] || payload.message || 'That answer could not be used.')
    assistantFormSession.value = payload.data
    assistantFormAnswer.value = ''
  } catch (error) { assistantFormError.value = error.message || 'That answer could not be used.' }
  finally { assistantFormBusy.value = false }
}

async function chooseAssistantFormAnswer(answer) {
  message.value = answer
  await askAssistant()
}

async function cancelAssistantInquiry() {
  if (!assistantFormSession.value || !window.confirm('Cancel this Sales Inquiry workflow? Its collected answers will be kept in history but cannot be saved.')) return
  const response = await fetch(`/api/assistant/form-sessions/${assistantFormSession.value.id}/cancel`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken } })
  if (!response.ok) { assistantFormError.value = 'The Sales Inquiry workflow could not be cancelled.'; return }
  assistantFormSession.value = null
  chatMessages.value.push({ role: 'assistant', content: 'I cancelled that Sales Inquiry workflow. The answers were not saved as a client or inquiry.' })
}

function assistantRetryMessage(response, action) {
  if (response.status !== 429) return null
  const seconds = Number(response.headers.get('Retry-After') || 0)
  return seconds > 0
    ? `Please wait ${seconds} seconds before retrying. Your inquiry is still here; ${action}.`
    : `Please wait a moment before retrying. Your inquiry is still here; ${action}.`
}

async function saveAssistantInquiryDraft() {
  if (!assistantFormSession.value || assistantFormSession.value.status !== 'review') return
  if (!window.confirm('Save this Sales Inquiry as a draft? It will not be submitted or approved.')) return
  assistantFormBusy.value = true
  assistantFormError.value = ''
  try {
    const response = await fetch(`/api/assistant/form-sessions/${assistantFormSession.value.id}/draft`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ confirmed: true }) })
    const payload = await response.json()
    if (!response.ok) {
      if (payload.data) {
        assistantFormSession.value = payload.data
        assistantFormError.value = ''
        return
      }
      throw new Error(assistantRetryMessage(response, 'it has not been saved yet') || payload.message || Object.values(payload.errors || {}).flat().join(' ') || 'Draft save failed.')
    }
    assistantFormSession.value = payload.data.session
    assistantFormSession.value.draft = payload.data.draft
    const actionResults = payload.action_results || []
    publishBusinessActions(actionResults, identity.id)
    assistantSavedAction.value = actionResults.find(action => action.action === 'sales_inquiry.created' && validInquiryId(action.entity_id)) || null
    if (assistantSavedAction.value) {
      sessionStorage.setItem(assistantSavedActionStorageKey, JSON.stringify(assistantSavedAction.value))
    }
    await loadAssistantFormSessions()
  } catch (error) { assistantFormError.value = error.message || 'Draft save failed.' }
  finally { assistantFormBusy.value = false }
}

function viewAssistantSavedInquiry() {
  if (!assistantSavedAction.value) return
  const target = `/sales/inquiries?highlight=${encodeURIComponent(assistantSavedAction.value.reference_number)}`
  if (currentPath === '/sales/inquiries') {
    window.dispatchEvent(new CustomEvent('ifc:view-inquiry', { detail: assistantSavedAction.value }))
    return
  }
  window.location.assign(target)
}

async function refreshAssistantInquiryGuidance() {
  if (!validInquiryId(assistantSavedAction.value?.entity_id)) {
    assistantWorkflowError.value = 'The saved inquiry link is unavailable. Open Client Inquiries to select the record.'
    return null
  }
  assistantWorkflowBusy.value = true
  assistantWorkflowError.value = ''
  try {
    const response = await fetch(`/api/assistant/workflows/inquiries/${assistantSavedAction.value.entity_id}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    const payload = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(payload.message || `Inquiry details could not be loaded (${response.status}).`)
    assistantWorkflowInquiry.value = payload.data
    assistantSavedAction.value = { ...assistantSavedAction.value, status: payload.data.status, next_steps: payload.next_steps }
    sessionStorage.setItem(assistantSavedActionStorageKey, JSON.stringify(assistantSavedAction.value))
    return payload
  } catch (error) {
    assistantWorkflowError.value = error.message || 'Inquiry details could not be loaded.'
    return null
  } finally { assistantWorkflowBusy.value = false }
}

async function reviewAndSubmitAssistantInquiry() {
  const payload = await refreshAssistantInquiryGuidance()
  if (!payload) return
  if (payload.data.status === 'draft' && payload.next_steps.actions.some(action => action.key === 'review_submit')) {
    chatMessages.value.push({ role: 'assistant', content: `I loaded the current saved details for ${payload.data.reference}. Please review them below. Submission makes the inquiry available for forecasting; no inquiry approval is required.` })
  } else {
    chatMessages.value.push({ role: 'assistant', content: payload.next_steps.message })
  }
}

async function checkAssistantInquiryStatus() {
  const payload = await refreshAssistantInquiryGuidance()
  if (payload) chatMessages.value.push({ role: 'assistant', content: payload.next_steps.message })
}

async function confirmAssistantInquirySubmission() {
  const current = assistantWorkflowInquiry.value
  if (!current || current.status !== 'draft' || !assistantSavedAction.value?.next_steps?.actions?.some(action => action.key === 'review_submit')) return
  assistantWorkflowBusy.value = true
  assistantWorkflowError.value = ''
  try {
    const response = await fetch(`/api/assistant/workflows/inquiries/${current.id}/submit`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify({ confirmed: true, conversation_uuid: activeConversationId.value || null }),
    })
    const payload = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(payload.message || Object.values(payload.errors || {}).flat().join(' ') || `Submission failed (${response.status}).`)
    publishBusinessActions(payload.action_results || [], identity.id)
    const result = (payload.action_results || []).find(action => action.action === 'sales_inquiry.updated')
    if (result && validInquiryId(result.entity_id)) {
      assistantSavedAction.value = { ...result, status: payload.data.status, next_steps: payload.next_steps }
      sessionStorage.setItem(assistantSavedActionStorageKey, JSON.stringify(assistantSavedAction.value))
    }
    assistantWorkflowInquiry.value = payload.data
    chatMessages.value.push({ role: 'assistant', content: payload.next_steps?.message || `${payload.data.reference} has been submitted. Would you like to create a shipment forecast?` })
  } catch (error) { assistantWorkflowError.value = error.message || 'The inquiry could not be submitted.' }
  finally { assistantWorkflowBusy.value = false }
}

function handleAssistantNextStep(key) {
  if (key === 'review_submit') return reviewAndSubmitAssistantInquiry()
  if (key === 'edit_details') {
    const record = assistantSavedAction.value
    if (validInquiryId(record?.entity_id)) window.location.assign(`/sales/inquiries?highlight=${encodeURIComponent(record.reference_number)}&edit=${encodeURIComponent(record.entity_id)}`)
    return
  }
  if (key === 'create_another') return createAnotherAssistantInquiry()
  if (key === 'view_inquiry') return viewAssistantSavedInquiry()
  if (key === 'check_status') return checkAssistantInquiryStatus()
  if (key === 'prepare_cost_estimate' && validInquiryId(assistantSavedAction.value?.entity_id)) window.location.assign(`/sales/cost-estimates?inquiry_id=${encodeURIComponent(assistantSavedAction.value.entity_id)}`)
  if (key === 'create_forecast') return startAssistantForecast()
}

async function offerForecastAfterInquirySubmission(event) {
  if (!can('assistant.use') || !validInquiryId(event?.detail?.id)) return
  await openAssistant()
  const response = await fetch(`/api/assistant/workflows/inquiries/${event.detail.id}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
  if (!response.ok) return
  const payload = await response.json()
  assistantSavedAction.value = { action: 'sales_inquiry.updated', entity_type: 'sales_inquiry', entity_id: payload.data.id, reference_number: payload.data.reference, status: payload.data.status, next_steps: payload.next_steps }
  sessionStorage.setItem(assistantSavedActionStorageKey, JSON.stringify(assistantSavedAction.value))
  chatMessages.value.push({ role: 'assistant', content: payload.next_steps.message })
}
window.addEventListener('ifc:inquiry-submitted', offerForecastAfterInquirySubmission)
onBeforeUnmount(() => window.removeEventListener('ifc:inquiry-submitted', offerForecastAfterInquirySubmission))

async function createAnotherAssistantInquiry() {
  if (!await startNewConversation()) return
  await startAssistantInquiry()
  if (assistantFormSession.value) chatMessages.value.push({ role: 'assistant', content: `Sure, let’s prepare another Sales Inquiry. ${assistantFormSession.value.question}` })
}

async function editAssistantInquiryField(field) {
  assistantFormEditField.value = field
  assistantFormEditValue.value = field === 'client_id' ? assistantFormSession.value.values.client_name : String(assistantFormSession.value.values[field] || '')
  assistantFormEditLocations.value = []
  if (['origin', 'destination'].includes(field)) {
    assistantFormEditCountry.value = assistantFormSession.value.values[`${field}_country_code`] || ''
    await loadAssistantEditLocations()
  }
}

async function loadAssistantEditLocations() {
  if (!assistantFormEditCountry.value || !assistantFormSession.value) return
  const mode = assistantFormSession.value.values.transport_mode || 'Sea'
  const response = await fetch(`/api/sales/location-options?mode=${encodeURIComponent(mode)}&country=${encodeURIComponent(assistantFormEditCountry.value)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
  if (!response.ok) { assistantFormError.value = 'Location options are unavailable.'; return }
  const payload = await response.json()
  assistantFormEditLocations.value = payload.data || []
  const field = assistantFormEditField.value
  const selected = assistantFormSession.value.values[`${field}_location_name`]
  if (selected && assistantFormEditLocations.value.some(location => location.name === selected)) assistantFormEditValue.value = selected
}

async function saveAssistantInquiryField() {
  const session = assistantFormSession.value
  const field = assistantFormEditField.value
  if (!session || !field || !assistantFormEditValue.value.trim()) return
  assistantFormBusy.value = true
  assistantFormError.value = ''
  try {
    let value = assistantFormEditValue.value.trim()
    if (['origin', 'destination'].includes(field)) {
      const country = session.countries?.find(item => item.code === assistantFormEditCountry.value)
      if (!country) throw new Error('Choose a country for this location.')
      value = `${value}, ${country.name}`
    }
    const response = await fetch(`/api/assistant/form-sessions/${session.id}/fields/${field}`, { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ value }) })
    const payload = await response.json()
    if (!response.ok) {
      if (payload.data) {
        assistantFormSession.value = payload.data
        assistantFormError.value = ''
        return
      }
      throw new Error(assistantRetryMessage(response, 'your review is unchanged') || payload.errors?.value?.[0] || payload.message || 'That change could not be saved.')
    }
    assistantFormSession.value = payload.data
    assistantFormEditField.value = ''
  } catch (error) { assistantFormError.value = error.message || 'That change could not be saved.' }
  finally { assistantFormBusy.value = false }
}

async function loadAssistantDocuments() {
  try {
    const response = await fetch(`/api/assistant/documents?session_key=${encodeURIComponent(assistantSessionKey)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    if (response.ok) assistantDocuments.value = (await response.json()).data || []
  } catch { /* Document list remains unavailable until the connection returns. */ }
}

function queueAssistantDocuments(event) {
  const files = [...(event.target.files || [])]
  event.target.value = ''
  if (!files.length) return
  assistantUploadError.value = ''
  pendingAssistantFiles.value = [...pendingAssistantFiles.value, ...files]
  assistantMenu.value = ''
  assistantMessageInput.value?.focus()
}

function removePendingAssistantFile(index) {
  pendingAssistantFiles.value.splice(index, 1)
}

function uploadAssistantDocuments(files) {
  assistantUploading.value = true
  assistantUploadProgress.value = 0
  const data = new FormData()
  data.append('session_key', assistantSessionKey)
  files.forEach(file => data.append('documents[]', file))
  const request = new XMLHttpRequest()
  return new Promise((resolve, reject) => {
    request.open('POST', '/api/assistant/documents')
    request.withCredentials = true
    request.setRequestHeader('Accept', 'application/json')
    request.setRequestHeader('X-CSRF-TOKEN', csrfToken)
    request.upload.onprogress = event => { if (event.lengthComputable) assistantUploadProgress.value = Math.round(event.loaded / event.total * 100) }
    request.onload = () => {
      assistantUploading.value = false
      try {
        const payload = JSON.parse(request.responseText || '{}')
        if (request.status >= 200 && request.status < 300) {
          assistantDocuments.value = [...payload.data, ...assistantDocuments.value]
          resolve(payload.data)
        } else reject(new Error(payload.message || Object.values(payload.errors || {}).flat().join(' ') || 'Upload failed.'))
      } catch (error) { reject(error instanceof Error ? error : new Error('Upload failed.')) }
    }
    request.onerror = () => { assistantUploading.value = false; reject(new Error('Upload connection failed.')) }
    request.send(data)
  })
}

async function removeAssistantDocument(document) {
  const response = await fetch(`/api/assistant/documents/${document.id}`, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken } })
  if (response.ok) assistantDocuments.value = assistantDocuments.value.filter(item => item.id !== document.id)
  else assistantUploadError.value = 'The document could not be removed.'
}

function startTutorial(key) {
  const tutorial = tutorialCatalog.value.tutorials.find(item => item.key === key)
  if (!tutorial) return
  activeTutorialKey.value = key
  tutorialStepIndex.value = 0
  assistantMenu.value = ''
  chatMessages.value.push({ role: 'assistant', content: `Here’s the verified IFC guide for **${tutorial.module_name}**.\n\n${tutorial.steps[0]}\n\n${tutorial.approval_dependencies?.[0] || ''}`.trim() })
  localStorage.setItem(`ifcTutorialProgress:${identity.id || 'user'}`, JSON.stringify({ key, step: 0 }))
}

function moveTutorialStep(offset) {
  if (!activeTutorial.value) return
  tutorialStepIndex.value = Math.max(0, Math.min(activeTutorial.value.steps.length - 1, tutorialStepIndex.value + offset))
  chatMessages.value.push({ role: 'assistant', content: `**${activeTutorial.value.module_name} · Step ${tutorialStepIndex.value + 1} of ${activeTutorial.value.steps.length}**\n\n${activeTutorialStep}` })
  localStorage.setItem(`ifcTutorialProgress:${identity.id || 'user'}`, JSON.stringify({ key: activeTutorialKey.value, step: tutorialStepIndex.value }))
}

function restartTutorial() { tutorialStepIndex.value = 0; localStorage.setItem(`ifcTutorialProgress:${identity.id || 'user'}`, JSON.stringify({ key: activeTutorialKey.value, step: 0 })) }
function exitTutorial() { activeTutorialKey.value = ''; chatMessages.value.push({ role: 'assistant', content: 'Tutorial closed. What would you like help with next?' }) }
function toggleAssistantMenu(menu) { assistantMenu.value = assistantMenu.value === menu ? '' : menu }

if (assistantOpen.value) onMounted(openAssistant)
if (!['users', 'roles', 'audit', 'integrations', 'ai-configuration', 'ai-usage', 'profile', 'executive', 'sales', 'sales-dashboard', 'sales-clients', 'sales-inquiries', 'sales-calendar', 'sales-monthly', 'sales-history', 'accounting-forecasts', 'sales-cost-estimates', 'sales-quotations', 'sales-quotation-history', 'accounting-cost-review', 'accounting-cash-requirements', 'finance-cost-review', 'finance-margins', 'finance-approval-queue', 'billing', 'collections'].includes(page) && !isBudgetPage && !isShipmentPage) onMounted(loadDashboard)
</script>

<template>
  <div :class="['ifc-shell', { 'ifc-shell-collapsed': desktopCollapsed }]">
    <div v-if="mobileNavOpen" class="ifc-mobile-scrim" @click="mobileNavOpen = false"></div>
    <aside id="ifc-sidebar-navigation" class="ifc-sidebar" :class="{ 'ifc-sidebar-open': mobileNavOpen }" @keydown.esc="mobileNavOpen = false">
      <a class="ifc-brand" href="/dashboard" aria-label="InterFreight Central dashboard"><span class="ifc-mark">IF</span><span><b>INTERFREIGHT</b><small>CENTRAL</small></span></a>
      <!-- Single IFC brand header above the scrollable navigation. -->
      <nav aria-label="Main navigation">
        <div class="ifc-nav-label">WORKSPACE</div>
        <a v-for="item in navigation.workspace" :key="item.href" :href="item.href" :class="['ifc-nav-link', { active: currentPath === item.href }]" :aria-current="currentPath === item.href ? 'page' : undefined" :title="desktopCollapsed ? item.label : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><template v-if="item.icon === 'grid'"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></template><template v-else-if="item.icon === 'chart'"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-5 5"/></template><template v-else><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 17l9 5 9-5"/></template></svg><span class="ifc-nav-text">{{ item.label }}</span></a>
        <div v-if="navigation.executive.length" class="ifc-admin-group"><button class="ifc-nav-link ifc-nav-group" :class="{ active: currentPath.startsWith('/executive') }" aria-controls="ifc-executive-submenu" :aria-expanded="executiveOpen" @click="executiveOpen = !executiveOpen"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-5 5"/></svg><span class="ifc-nav-text">Executive Intelligence</span><svg class="ifc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path :d="executiveOpen ? 'm6 15 6-6 6 6' : 'm9 18 6-6-6-6'"/></svg></button><Transition name="ifc-submenu"><div v-if="executiveOpen" id="ifc-executive-submenu" class="ifc-nav-submenu"><a v-for="item in navigation.executive" :key="item.href" :href="item.href" :class="['ifc-nav-link','ifc-nav-sublink',{ active: currentPath === item.href }]" :aria-current="currentPath === item.href ? 'page' : undefined" :title="desktopCollapsed ? item.label : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg><span class="ifc-nav-text">{{ item.label }}</span></a></div></Transition></div>
        <div v-for="group in navigation.execution" :key="group.key" class="ifc-admin-group">
          <button class="ifc-nav-link ifc-nav-group" :class="{ active: currentPath.startsWith('/' + group.key) }" :aria-controls="`ifc-${group.key}-submenu`" :aria-expanded="Boolean(executionOpen[group.key])" @click="executionOpen[group.key] = !executionOpen[group.key]"><svg viewBox="0 0 24 24" aria-hidden="true"><template v-if="group.icon === 'truck'"><path d="M3 6h11v11H3zM14 10h4l3 4v3h-7z"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/></template><template v-else-if="group.icon === 'ship'"><path d="M3 14h18l-3 5H6zM5 14V5h14v9M9 5V2h6v3M3 21c2 0 2-1 4-1s2 1 4 1 2-1 4-1 2 1 4 1"/></template><template v-else><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 17l9 5 9-5"/></template></svg><span class="ifc-nav-text">{{ group.title }}</span><svg class="ifc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path :d="executionOpen[group.key] ? 'm6 15 6-6 6 6' : 'm9 18 6-6-6-6'"/></svg></button>
          <Transition name="ifc-submenu"><div v-if="executionOpen[group.key]" :id="`ifc-${group.key}-submenu`" class="ifc-nav-submenu"><a v-for="item in group.links" :key="item.href" :href="item.href" :class="['ifc-nav-link', 'ifc-nav-sublink', { active: currentPath === item.href }]" :aria-current="currentPath === item.href ? 'page' : undefined" :title="desktopCollapsed ? item.label : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg><span class="ifc-nav-text">{{ item.label }}</span></a></div></Transition>
        </div>
        <div v-if="navigation.expenseManagement.length" class="ifc-admin-group">
          <button class="ifc-nav-link ifc-nav-group" :class="{ active: currentPath === '/expenses' || currentPath === '/cash-advances' || currentPath === '/my-liquidations' }" aria-controls="ifc-expense-submenu" :aria-expanded="expenseOpen" @click="expenseOpen = !expenseOpen" :title="desktopCollapsed ? 'Expense Management' : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h4m-4 4h6"/></svg><span class="ifc-nav-text">Expense Management</span><svg class="ifc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path :d="expenseOpen ? 'm6 15 6-6 6 6' : 'm9 18 6-6-6-6'"/></svg></button>
          <Transition name="ifc-submenu"><div v-if="expenseOpen" id="ifc-expense-submenu" class="ifc-nav-submenu"><a v-for="item in navigation.expenseManagement" :key="item.href" :href="item.href" :class="['ifc-nav-link', 'ifc-nav-sublink', { active: currentPath === item.href }]" :aria-current="currentPath === item.href ? 'page' : undefined" :title="desktopCollapsed ? item.label : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg><span class="ifc-nav-text">{{ item.label }}</span></a></div></Transition>
        </div>
        <div v-if="navigation.sales.length" class="ifc-admin-group">
          <button class="ifc-nav-link ifc-nav-group" :class="{ active: currentPath.startsWith('/sales') }" aria-controls="ifc-sales-submenu" :aria-expanded="salesOpen" @click="salesOpen = !salesOpen" :title="desktopCollapsed ? 'Sales' : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-5 5"/></svg><span class="ifc-nav-text">Sales</span><svg class="ifc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path :d="salesOpen ? 'm6 15 6-6 6 6' : 'm9 18 6-6-6-6'"/></svg></button>
          <Transition name="ifc-submenu"><div v-if="salesOpen" id="ifc-sales-submenu" class="ifc-nav-submenu"><a v-for="item in navigation.sales" :key="item.href" :href="item.href" :class="['ifc-nav-link', 'ifc-nav-sublink', { active: currentPath === item.href }]" :aria-current="currentPath === item.href ? 'page' : undefined" :title="desktopCollapsed ? item.label : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><template v-if="item.icon === 'users'"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 8h4m-2-2v4"/></template><template v-else-if="item.icon === 'chart'"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-5 5"/></template><template v-else><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></template></svg><span class="ifc-nav-text">{{ item.label }}</span></a></div></Transition>
        </div>
        <div v-if="navigation.accounting.length" class="ifc-admin-group">
          <button class="ifc-nav-link ifc-nav-group" :class="{ active: currentPath.startsWith('/accounting') }" aria-controls="ifc-accounting-submenu" :aria-expanded="accountingOpen" @click="accountingOpen = !accountingOpen" :title="desktopCollapsed ? 'Accounting' : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h2m4 0h2m-8 4h2m4 0h2"/></svg><span class="ifc-nav-text">Accounting</span><svg class="ifc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path :d="accountingOpen ? 'm6 15 6-6 6 6' : 'm9 18 6-6-6-6'"/></svg></button>
          <Transition name="ifc-submenu"><div v-if="accountingOpen" id="ifc-accounting-submenu" class="ifc-nav-submenu"><a v-for="item in navigation.accounting" :key="item.href" :href="item.href" :class="['ifc-nav-link', 'ifc-nav-sublink', { active: currentPath === item.href }]" :aria-current="currentPath === item.href ? 'page' : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-5 5"/></svg><span class="ifc-nav-text">{{ item.label }}</span></a></div></Transition>
        </div>
        <div v-if="navigation.finance.length" class="ifc-admin-group">
          <button class="ifc-nav-link ifc-nav-group" :class="{ active: currentPath.startsWith('/finance/') }" aria-controls="ifc-finance-submenu" :aria-expanded="financeOpen" @click="financeOpen = !financeOpen" :title="desktopCollapsed ? 'Finance' : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h.01M7 13h.01M11 9h2m-2 4h2m4-4h.01m-.01 4h.01"/></svg><span class="ifc-nav-text">Finance</span><svg class="ifc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path :d="financeOpen ? 'm6 15 6-6 6 6' : 'm9 18 6-6-6-6'"/></svg></button>
          <Transition name="ifc-submenu"><div v-if="financeOpen" id="ifc-finance-submenu" class="ifc-nav-submenu"><a v-for="item in navigation.finance" :key="item.href" :href="item.href" :class="['ifc-nav-link', 'ifc-nav-sublink', { active: currentPath === item.href } ]" :aria-current="currentPath === item.href ? 'page' : undefined" :title="desktopCollapsed ? item.label : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-5 5"/></svg><span class="ifc-nav-text">{{ item.label }}</span></a></div></Transition>
        </div>
        <div v-if="navigation.billing.length" class="ifc-admin-group">
          <button class="ifc-nav-link ifc-nav-group" :class="{ active: currentPath.startsWith('/billing') }" aria-controls="ifc-billing-submenu" :aria-expanded="billingOpen" @click="billingOpen = !billingOpen" :title="desktopCollapsed ? 'Billing' : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h6M7 17h3"/></svg><span class="ifc-nav-text">Billing</span><svg class="ifc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path :d="billingOpen ? 'm6 15 6-6 6 6' : 'm9 18 6-6-6-6'"/></svg></button>
          <Transition name="ifc-submenu"><div v-if="billingOpen" id="ifc-billing-submenu" class="ifc-nav-submenu"><a v-for="item in navigation.billing" :key="item.href" :href="item.href" :class="['ifc-nav-link', 'ifc-nav-sublink', { active: currentPath === item.href } ]" :aria-current="currentPath === item.href ? 'page' : undefined" :title="desktopCollapsed ? item.label : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg><span class="ifc-nav-text">{{ item.label }}</span></a></div></Transition>
        </div>
        <div v-if="navigation.administration.length" class="ifc-admin-group">
          <button class="ifc-nav-link ifc-nav-group" :class="{ active: isAdmin }" aria-controls="ifc-admin-submenu" :aria-expanded="adminOpen" @click="adminOpen = !adminOpen" :title="desktopCollapsed ? 'Administration' : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/><path d="M3.5 9h17M7 3.8l2 5.2m8-5.2-2 5.2"/></svg><span class="ifc-nav-text">Administration</span><svg class="ifc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path :d="adminOpen ? 'm6 15 6-6 6 6' : 'm9 18 6-6-6-6'"/></svg></button>
          <Transition name="ifc-submenu"><div v-if="adminOpen" id="ifc-admin-submenu" class="ifc-nav-submenu"><a v-for="item in navigation.administration" :key="item.href" :href="item.href" :class="['ifc-nav-link', 'ifc-nav-sublink', { active: currentPath === item.href }]" :aria-current="currentPath === item.href ? 'page' : undefined" :title="desktopCollapsed ? item.label : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><template v-if="item.icon === 'users'"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></template><template v-else-if="item.icon === 'roles'"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></template><template v-else-if="item.icon === 'audit'"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></template><template v-else><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/><path d="M3.5 9h17"/></template></svg><span class="ifc-nav-text">{{ item.label }}</span></a></div></Transition>
        </div>
      </nav>
      <div class="ifc-sidebar-foot">
        <div class="ifc-account-nav">
          <div class="ifc-nav-label">ACCOUNT</div>
          <a v-for="item in navigation.account" :key="item.href" :href="item.href" :class="['ifc-nav-link', { active: currentPath === item.href }]" :aria-current="currentPath === item.href ? 'page' : undefined" :title="desktopCollapsed ? item.label : undefined"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/></svg><span class="ifc-nav-text">{{ item.label }}</span></a>
          <form method="post" action="/logout"><input type="hidden" name="_token" :value="csrfToken"><button type="submit" class="ifc-nav-link ifc-signout" title="Sign Out"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg><span class="ifc-nav-text">Sign Out</span></button></form>
        </div>
        <div class="ifc-sidebar-status"><span><i class="ifc-secure-dot"></i><span class="ifc-nav-text">Secure workspace</span></span><small>{{ isExecutive ? 'Executive Intelligence' : 'Phase 1 foundation' }}</small><button class="ifc-collapse-toggle" :aria-label="desktopCollapsed ? 'Expand sidebar' : 'Collapse sidebar'" :title="desktopCollapsed ? 'Expand sidebar' : 'Collapse sidebar'" @click="desktopCollapsed = !desktopCollapsed"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="desktopCollapsed ? 'm9 18 6-6-6-6' : 'm15 18-6-6 6-6'"/></svg></button></div>
      </div>
    </aside>

    <main class="ifc-main">
      <header class="ifc-topbar"><button class="ifc-menu" aria-label="Open navigation" aria-controls="ifc-sidebar-navigation" :aria-expanded="mobileNavOpen" @click="mobileNavOpen = !mobileNavOpen"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><nav class="ifc-breadcrumb" aria-label="Breadcrumb"><template v-if="isAdministratorDashboard"><a href="/dashboard">IFC</a><span>/</span><b>Administration</b></template><template v-else-if="page === 'ai-configuration'"><span>Administration</span><span>/</span><span>System Settings</span><span>/</span><b>AI Configuration</b></template><template v-else-if="isAdmin"><span>Administration</span><span>/</span><b>{{ selected }}</b></template><template v-else-if="isProfile"><span>Account</span><span>/</span><b>{{ selected }}</b></template><template v-else><a href="/dashboard">IFC</a><span>/</span><b>{{ selected }}</b></template></nav><button v-if="can('assistant.use')" class="ifc-assistant-trigger" @click="openAssistant">AI Assistant</button><details class="ifc-profile-menu"><summary><span class="ifc-avatar">{{ (identity.name || 'U').split(' ').map(s => s[0]).join('').slice(0,2).toUpperCase() }}</span><span class="ifc-user-name"><b>{{ identity.name }}</b><small>{{ (identity.roles || []).join(' · ') }}</small></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg></summary><div class="ifc-profile-dropdown"><div class="ifc-profile-details"><b>{{ identity.name }}</b><small>{{ identity.email }}</small></div><a v-if="can('profile.view')" href="/profile">My profile</a><form method="post" action="/logout"><input type="hidden" name="_token" :value="csrfToken"><button type="submit">Sign out</button></form></div></details></header>
      <section class="ifc-content">
        <div v-if="isAdministratorDashboard" class="ifc-title-row ifc-admin-dashboard-heading"><div><div class="ifc-kicker">IFC / ADMINISTRATION</div><h1>Administrator Dashboard</h1><p>Manage user access, monitor security, and maintain system health.</p></div><div class="ifc-admin-dashboard-tools"><span><b>{{ identity.name }}</b><small>{{ dashboardDateTime }} · {{ appTimezone }}</small></span><button class="ifc-secondary" :disabled="busy" @click="loadDashboard"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M5.6 9a7 7 0 0 1 11.9-2L20 12M4 12l2.5 5a7 7 0 0 0 11.9-2"/></svg>{{ busy ? 'Refreshing' : 'Refresh' }}</button></div></div>
        <div v-else-if="!isAdmin && !isProfile && !isExecutive && !isSalesPage && !isBudgetPage && !isShipmentPage && !isExpensePage && !isAdditionalChargePage && !isBillingPage && !isCollectionsPage" class="ifc-title-row"><div><div class="ifc-kicker">INTERFREIGHT GROUP / SYSTEM FOUNDATION</div><h1>{{ isDashboard ? 'Good day, ' + identity.name : selected }}</h1><p>{{ isDashboard ? 'Identity, access and service health for your IFC workspace.' : isDepartment ? 'Your role-based InterFreight Central workspace.' : 'Your role-based InterFreight Central workspace.' }}</p></div></div>

        <div v-if="error && !isAdmin" class="ifc-alert" role="alert">{{ error }} <button @click="loadDashboard">Retry</button></div>
        <ShipmentClosing v-if="page === 'shipment-closing'" :identity="identity" />
        <UserManagement v-else-if="page === 'users' && userManagement" :data="userManagement" />
        <RolesPermissions v-else-if="page === 'roles' && roleManagement" :data="roleManagement" />
        <AuditLogs v-else-if="page === 'audit' && auditManagement" :data="auditManagement" />
        <IntegrationHealth v-else-if="page === 'integrations' && integrationHealth" :data="integrationHealth" />
        <AiConfiguration v-else-if="page === 'ai-configuration'" />
        <AiUsage v-else-if="page === 'ai-usage'" />
        <ProfileSettings v-else-if="page === 'profile' && profileManagement" :data="profileManagement" @profile-updated="syncIdentity" />
        <BudgetWorkspace v-else-if="isBudgetPage" :page="page" :permissions="identity.permissions || []" :roles="identity.roles || []" :base-currency="baseCurrency" />
        <ShipmentWorkspace v-else-if="isShipmentPage" :page="page" :permissions="identity.permissions || []" :roles="identity.roles || []" :user-id="identity.id" />
        <ExpenseWorkspace v-else-if="isExpensePage" :page="page" :permissions="identity.permissions || []" :roles="identity.roles || []" :user-id="identity.id" />
        <AdditionalChargeWorkspace v-else-if="isAdditionalChargePage" :permissions="identity.permissions || []" :roles="identity.roles || []" :user-id="identity.id" />
        <BillingWorkspace v-else-if="isBillingPage" :permissions="identity.permissions || []" :roles="identity.roles || []" :user-id="identity.id" />
        <CollectionWorkspace v-else-if="isCollectionsPage" :permissions="identity.permissions || []" :roles="identity.roles || []" :user-id="identity.id" />
        <SalesDashboard v-else-if="page === 'sales-dashboard'" :page="page" :permissions="identity.permissions || []" :user-id="identity.id" />
        <CommercialWorkspace v-else-if="isCommercialPage" :page="page" :permissions="identity.permissions || []" :roles="identity.roles || []" :base-currency="baseCurrency" :user-id="identity.id" />
        <SalesWorkspace v-else-if="isSalesPage" :page="page" :permissions="identity.permissions || []" :user-id="identity.id" />
        <ExecutiveDashboard v-else-if="isExecutive" :can-generate-forecast="can('ai.forecasts.generate')" />
        <div v-else-if="isAdministratorDashboard" class="ifc-admin-dashboard">
          <section class="ifc-admin-kpis" aria-label="System summary">
            <article class="ifc-admin-kpi"><span class="ifc-admin-kpi-icon users"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><span><small>Total Users</small><b>{{ dashboard.metrics.users ?? '—' }}</b></span></article>
            <article class="ifc-admin-kpi"><span class="ifc-admin-kpi-icon active"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/><circle cx="12" cy="12" r="9"/></svg></span><span><small>Active Users</small><b>{{ dashboard.metrics.active_users ?? '—' }}</b></span></article>
            <article class="ifc-admin-kpi"><span class="ifc-admin-kpi-icon inactive"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6m0-6-6 6"/></svg></span><span><small>Inactive Users</small><b>{{ dashboard.metrics.inactive_users ?? '—' }}</b></span></article>
            <article class="ifc-admin-kpi"><span class="ifc-admin-kpi-icon roles"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></svg></span><span><small>System Roles</small><b>{{ dashboard.metrics.roles ?? '—' }}</b></span></article>
          </section>

          <section v-if="can('admin.ai-usage.view')" class="ifc-panel ifc-ai-dashboard-summary"><div class="ifc-panel-head"><div><h2>AI usage this month</h2><p>Provider usage is tracked separately from estimated spend.</p></div><a href="/admin/ai-usage" class="ifc-admin-panel-link">Usage details <span aria-hidden="true">→</span></a></div><div class="ifc-ai-summary-grid"><article><small>Requests</small><b>{{ dashboard.ai_usage?.requests ?? '—' }}</b></article><article><small>Input tokens</small><b>{{ dashboard.ai_usage?.input_tokens ?? '—' }}</b></article><article><small>Output tokens</small><b>{{ dashboard.ai_usage?.output_tokens ?? '—' }}</b></article><article><small>Estimated cost (PHP)</small><b>{{ dashboard.ai_usage?.estimated_cost_php == null ? 'Unavailable' : '₱' + Number(dashboard.ai_usage.estimated_cost_php).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</b><small>{{ dashboard.ai_usage?.costed_requests ?? 0 }} requests priced</small></article></div></section>

          <div class="ifc-admin-dashboard-grid">
            <section v-if="can('users.view')" class="ifc-panel ifc-admin-table-panel"><div class="ifc-panel-head"><div><h2>Recent User Accounts</h2><p>Latest accounts in InterFreight Central</p></div><a v-if="can('users.view')" href="/admin/users" class="ifc-admin-panel-link">View all users <span aria-hidden="true">→</span></a></div><div class="ifc-admin-table-wrap"><table class="ifc-admin-users"><thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th>Created</th><th>Last Login</th><th></th></tr></thead><tbody><tr v-for="account in dashboard.recent_users" :key="account.id"><td><b>{{ account.name }}</b></td><td>{{ account.email }}</td><td><span v-if="account.roles.length" class="ifc-admin-role-tags"><i v-for="role in account.roles" :key="role">{{ role }}</i></span><span v-else class="ifc-admin-muted">No role</span></td><td><span :class="['ifc-admin-status', account.is_active ? 'is-active' : 'is-inactive']">{{ account.is_active ? 'Active' : 'Inactive' }}</span></td><td>{{ formatDateTime(account.created_at) }}</td><td>{{ formatDateTime(account.last_login_at) }}</td><td><a :href="'/admin/users?search=' + encodeURIComponent(account.email)" class="ifc-admin-manage-link">Manage</a></td></tr><tr v-if="!dashboard.recent_users.length"><td colspan="7" class="ifc-admin-empty">{{ busy ? 'Loading user accounts…' : 'No user accounts are available.' }}</td></tr></tbody></table></div></section>

            <section class="ifc-panel ifc-admin-health"><div class="ifc-panel-head"><div><h2>System Health</h2><p>Current application and service diagnostics</p></div><a v-if="can('integrations.status.view')" href="/admin/integrations" class="ifc-admin-panel-link">Diagnostics <span aria-hidden="true">→</span></a></div><div class="ifc-health-list"><article v-for="row in systemHealthRows" :key="row.label" class="ifc-health-row"><span class="ifc-health-dot" :class="'health-' + (row.status || 'checking')"></span><div><div class="ifc-health-row-title"><b>{{ row.label }}</b><span :class="'health-text-' + (row.status || 'checking')">{{ (row.status || (busy ? 'checking' : 'unknown')).replaceAll('_', ' ') }}</span></div><p>{{ row.summary || 'Waiting for the first health check.' }}</p></div></article></div><div class="ifc-health-foot"><span>Last checked {{ dashboard.system_health?.checked_at ? formatDateTime(dashboard.system_health.checked_at) : (busy ? 'Checking…' : 'Not available') }}</span><span v-if="dashboard.system_health?.last_successful_check">Last authenticated {{ formatDateTime(dashboard.system_health.last_successful_check) }}</span></div></section>

            <section v-if="can('audit.view')" class="ifc-panel ifc-admin-activity"><div class="ifc-panel-head"><div><h2>Recent Security Activity</h2><p>Latest recorded account and system events</p></div><a href="/admin/audit" class="ifc-admin-panel-link">View audit logs <span aria-hidden="true">→</span></a></div><div v-if="dashboard.recent_events.length" class="ifc-admin-event-list"><article v-for="event in dashboard.recent_events" :key="event.id" class="ifc-admin-event"><span class="ifc-admin-event-icon" :class="'event-' + event.category"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg></span><div><div class="ifc-admin-event-heading"><b>{{ event.event }}</b><span>{{ event.category_label }}</span></div><p>{{ event.actor?.name || 'System' }}<template v-if="event.target"> · {{ event.target.type }} #{{ event.target.id }}</template></p></div><time :datetime="event.created_at">{{ formatDateTime(event.created_at) }}</time></article></div><div v-else class="ifc-admin-empty">{{ busy ? 'Loading recent activity…' : 'No security events have been recorded.' }}</div></section>
          </div>

          <section v-if="adminQuickActions.length" class="ifc-panel ifc-admin-quick"><div class="ifc-panel-head"><div><h2>Quick Administration</h2><p>Shortcuts available to your account</p></div></div><div class="ifc-admin-actions"><a v-for="action in adminQuickActions" :key="action.href" :href="action.href" class="ifc-admin-action"><span class="ifc-admin-action-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><template v-if="action.icon === 'user'"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></template><template v-else-if="action.icon === 'roles'"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></template><template v-else-if="action.icon === 'audit'"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></template><template v-else><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></template></svg></span><span><b>{{ action.label }}</b><small>{{ action.detail }}</small></span><span class="ifc-admin-action-arrow" aria-hidden="true">→</span></a></div></section>
        </div>
        <div v-else-if="isDashboard" class="ifc-dashboard">
          <div class="ifc-stats">
            <article v-if="dashboard.metrics.users !== undefined" class="ifc-stat"><span>REGISTERED USERS</span><strong>{{ dashboard.metrics.users }}</strong><small>{{ dashboard.metrics.active_users }} active accounts</small></article>
            <article class="ifc-stat"><span>FASTAPI CONNECTION</span><strong :class="dashboard.integration === 'authenticated' ? 'good' : 'warn'">{{ dashboard.integration === 'authenticated' ? 'Connected' : busy ? 'Checking...' : 'Unavailable' }}</strong><small>Protected service diagnostic</small></article>
            <article class="ifc-stat"><span>YOUR ACCESS</span><strong>{{ (identity.roles || []).length }} role{{ (identity.roles || []).length === 1 ? '' : 's' }}</strong><small>{{ (identity.roles || []).join(', ') }}</small></article>
          </div>
          <section class="ifc-panel"><div class="ifc-panel-head"><div><h2>Department access</h2><p>Your role determines which workspaces appear here.</p></div></div><div class="ifc-dept-grid"><a v-for="[name, permission, href] in departments.filter(([, permission]) => can(permission))" :key="permission" :href="href" class="ifc-dept"><span class="ifc-dept-icon" aria-hidden="true">&rarr;</span><span><b>{{ name }}</b><small>Open workspace</small></span><span class="ifc-arrow" aria-hidden="true">&rarr;</span></a><p v-if="!departments.some(([, permission]) => can(permission))" class="ifc-empty">No department workspaces are assigned to your account.</p></div></section>
          <section v-if="can('audit.view')" class="ifc-panel"><div class="ifc-panel-head"><div><h2>Recent security activity</h2><p>Administrative and account events available to your role.</p></div><a href="/admin/audit" class="ifc-text-link">View audit log &rarr;</a></div><div v-if="dashboard.recent_events.length" class="ifc-events"><article v-for="event in dashboard.recent_events" :key="event.id"><span class="ifc-event-dot"></span><div><b>{{ event.event || event.action.replaceAll('.', ' ') }}</b><small>{{ event.actor?.name || 'System' }} &middot; {{ formatDateTime(event.created_at) }}</small></div></article></div><div v-else class="ifc-empty">No security events have been recorded yet.</div></section>
          <section v-if="can('shipments.view')" class="ifc-panel"><div class="ifc-panel-head"><div><h2>Shipment register</h2><p>Distinct master jobs and departmental assignments</p></div><a href="/shipments" class="ifc-text-link">Open shipment register →</a></div><div class="ifc-table-wrap"><table><thead><tr><th>JOB NUMBER</th><th>CLIENT</th><th>ROUTE</th><th>MASTER STATUS</th><th>ETA</th></tr></thead><tbody><tr v-for="shipment in shipments" :key="shipment.id"><td><a :href="`/shipments/${shipment.id}`">{{ shipment.job_number || shipment.reference }}</a></td><td>{{ shipment.customer_name }}</td><td>{{ shipment.origin }} &rarr; {{ shipment.destination }}</td><td>{{ shipment.master_status || shipment.status }}</td><td>{{ shipment.estimated_delivery || 'Not recorded' }}</td></tr><tr v-if="!shipments.length"><td colspan="5" class="ifc-empty">{{ busy ? 'Loading authorized shipment records...' : 'No shipment records are available in your department scope.' }}</td></tr></tbody></table></div></section>
          <section class="ifc-panel ifc-footnote"><b>Operational metrics will appear when the corresponding modules are implemented.</b><span>IFC Phase 1 &middot; Identity, access, audit and service health</span></section>
        </div>
        <section v-else class="ifc-panel ifc-placeholder"><div class="ifc-placeholder-mark">{{ selected.slice(0,1) }}</div><h2>{{ selected }} workspace</h2><p v-if="isDepartment">Your account has access to this department. Its shipment workflows will be introduced in the next implementation phase.</p><p v-else>Use the navigation links to open a section authorized for your account.</p><a href="/dashboard" class="ifc-primary-link">Return to dashboard</a></section>
        <footer class="ifc-footer"><span>&#169; {{ new Date().getFullYear() }} INTERFREIGHT GROUP</span><span>InterFreight Central</span></footer>
      </section>
    </main>

    <aside v-if="assistantOpen && can('assistant.use')" class="ifc-assistant" aria-label="IFC Assistant" @keydown.esc="assistantMenu ? assistantMenu = '' : assistantOpen = false">
      <header class="ifc-assistant-header">
        <span class="ifc-assistant-brand" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="7" width="14" height="11" rx="3"/><path d="M9 12h.01M15 12h.01M9 18v2m6-2v2M9 4v3m6-3v3"/></svg></span>
        <div class="ifc-assistant-heading"><h2>IFC Assistant</h2><p>Your AI workspace assistant</p></div>
        <div class="ifc-assistant-header-actions">
          <button type="button" class="ifc-assistant-icon-button" aria-label="Conversation history" :aria-expanded="assistantMenu === 'history'" @click="toggleAssistantMenu('history'); loadAssistantConversations()"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 2.7-6.4L3 8"/><path d="M3 3v5h5m4-1v5l3 2"/></svg></button>
          <button type="button" class="ifc-assistant-icon-button" aria-label="Tutorials" :aria-expanded="assistantMenu === 'tutorials'" @click="toggleAssistantMenu('tutorials')"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21z"/><path d="M4 5.5v13A2.5 2.5 0 0 1 6.5 16H20M8 7h8m-8 3h6"/></svg></button>
          <button type="button" class="ifc-assistant-icon-button" aria-label="New conversation" @click="startNewConversation"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/></svg></button>
          <button type="button" class="ifc-assistant-icon-button" aria-label="Close assistant" @click="assistantOpen = false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
        </div>
      </header>

      <section v-if="assistantMenu === 'history'" class="ifc-assistant-popover ifc-assistant-history" aria-label="Conversation history">
        <div class="ifc-assistant-popover-heading"><b>Recent conversations</b><button type="button" @click="startNewConversation">New conversation</button></div>
        <p v-if="conversationError" class="ifc-assistant-error" role="alert">{{ conversationError }}</p>
        <p v-else-if="!conversationList.length" class="ifc-assistant-muted">No saved conversations yet.</p>
        <article v-for="conversation in conversationList" :key="conversation.conversation_uuid" class="ifc-assistant-history-row">
          <button type="button" class="ifc-assistant-history-title" @click="resumeAssistantConversation(conversation.conversation_uuid)">{{ conversation.title || (conversation.current_path ? (authorizedAssistantLinks.find(item => item.href === conversation.current_path)?.label || 'IFC conversation') : 'IFC conversation') }}<small>{{ formatDateTime(conversation.last_activity_at) }}</small></button>
          <button type="button" aria-label="Rename conversation" @click="renameAssistantConversation(conversation)">Rename</button>
          <button type="button" aria-label="Delete conversation" @click="deleteAssistantConversation(conversation.conversation_uuid)">Delete</button>
        </article>
      </section>

      <section v-if="assistantMenu === 'tutorials'" class="ifc-assistant-popover ifc-assistant-tool-popover" aria-label="IFC tutorials">
        <div class="ifc-assistant-popover-heading"><b>Tutorials</b><span v-if="tutorialBusy">Loading…</span></div>
        <p v-if="tutorialError" class="ifc-assistant-error" role="alert">{{ tutorialError }}</p>
        <button v-for="tutorial in tutorialCatalog.tutorials" :key="tutorial.key" type="button" class="ifc-assistant-menu-item" @click="startTutorial(tutorial.key)"><b>{{ tutorial.module_name }}</b><small>{{ tutorial.purpose }}</small></button>
        <p v-if="!tutorialBusy && !tutorialCatalog.tutorials?.length && !tutorialError" class="ifc-assistant-muted">No tutorials are available for this account.</p>
        <div v-if="activeTutorial" class="ifc-assistant-tutorial-controls"><span>{{ activeTutorial.module_name }} · {{ tutorialProgress }}%</span><button type="button" :disabled="tutorialStepIndex >= activeTutorial.steps.length - 1" @click="moveTutorialStep(1)">Next step</button><button type="button" @click="exitTutorial">Close guide</button></div>
      </section>

      <section v-if="assistantMenu === 'tools'" class="ifc-assistant-popover ifc-assistant-tool-popover" aria-label="Workflow tools">
        <div class="ifc-assistant-popover-heading"><b>Workflow help</b></div>
        <button v-if="can('inquiries.create') && !assistantFormSession" type="button" class="ifc-assistant-menu-item" @click="launchAssistantInquiry"><b>Start a Sales Inquiry</b><small>Answer a few questions, then review before saving.</small></button>
        <button v-for="item in assistantFormSessions" :key="item.id" type="button" class="ifc-assistant-menu-item" @click="resumeAssistantInquiry(item); assistantMenu = ''"><b>Resume Sales Inquiry</b><small>{{ item.progress }}% complete</small></button>
        <label v-if="tutorialCatalog.inquiries?.length" class="ifc-assistant-record">Inquiry context<select v-model="selectedInquiryId"><option value="">No inquiry selected</option><option v-for="record in tutorialCatalog.inquiries" :key="record.id" :value="String(record.id)">{{ record.reference }} · {{ record.status.replaceAll('_', ' ') }}</option></select></label>
      </section>

      <main ref="assistantHistoryRef" class="ifc-assistant-history-scroll" aria-label="Conversation" aria-live="polite" @scroll.passive="updateAssistantScrollIntent">
        <div v-if="!chatMessages.length" class="ifc-assistant-welcome-copy">Hi! How can I help you today? I can help prepare a Sales Inquiry, attach documents for review, or guide you through IFC.</div>
        <template v-for="(item, index) in chatMessages" :key="`${item.role}-${index}`">
          <article :class="['ifc-chat-message', item.role === 'user' ? 'user' : 'assistant']">
            <div v-html="renderSafeAssistantMarkdown(item.content, authorizedAssistantLinks)"></div>
            <div v-if="item.attachments?.length" class="ifc-chat-attachments"><span v-for="file in item.attachments" :key="file.id" class="ifc-chat-attachment-chip"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v13H7zM14 3v5h5"/></svg><span>{{ file.filename }}<small>{{ file.scan_status }} · {{ file.extraction_status }}</small></span></span></div>
          </article>
        </template>
        <section v-if="assistantFormSession" class="ifc-assistant-active-form" :class="{ 'is-complete': assistantFormSession.status === 'saved' }" :aria-label="assistantFormSession.status === 'saved' ? 'Sales Inquiry saved' : 'Sales Inquiry progress'">
          <div class="ifc-assistant-active-form-heading"><b>{{ assistantFormSession.status === 'saved' ? 'Sales Inquiry saved' : 'Sales Inquiry' }}</b><span v-if="assistantFormSession.status !== 'saved'">{{ assistantFormSession.progress }}% complete</span><button type="button" aria-label="Close inquiry workflow" @click="assistantFormSession = null; loadAssistantFormSessions()">×</button></div>
          <progress v-if="['collecting', 'review'].includes(assistantFormSession.status)" max="100" :value="assistantFormSession.progress">{{ assistantFormSession.progress }}%</progress>
          <div v-if="assistantFormSession.status === 'collecting' && assistantFormChoices.length" class="ifc-assistant-choice-actions"><button v-for="choice in assistantFormChoices" :key="choice" type="button" :disabled="assistantFormBusy" @click="chooseAssistantFormAnswer(choice)">{{ choice }}</button></div>
          <template v-if="assistantFormSession.status === 'review'"><p v-if="assistantFormSession.values.client_kind === 'new'">New client: {{ assistantFormSession.values.client_name }} ({{ assistantFormSession.values.client_type }}) <button type="button" @click="editAssistantInquiryField('client_name')">Change name</button><button type="button" @click="editAssistantInquiryField('client_type')">Change client type</button></p><div class="ifc-assistant-review-fields"><div v-for="[field, label] in assistantInquiryReviewFields" :key="field"><span>{{ label }}</span><b>{{ field === 'client_id' ? assistantFormSession.values.client_name : assistantFormSession.values[field] }}</b><button v-if="field !== 'client_id' || assistantFormSession.values.client_kind !== 'new'" type="button" @click="editAssistantInquiryField(field)">Edit</button></div></div><form v-if="assistantFormEditField" class="ifc-assistant-inline-edit" @submit.prevent="saveAssistantInquiryField"><template v-if="['origin', 'destination'].includes(assistantFormEditField)"><select v-model="assistantFormEditCountry" aria-label="Location country" @change="assistantFormEditValue = ''; loadAssistantEditLocations()"><option value="">Choose country</option><option v-for="country in assistantFormSession.countries" :key="country.code" :value="country.code">{{ country.name }}</option></select><select v-model="assistantFormEditValue" aria-label="Choose a listed location"><option value="">Choose a listed location</option><option v-for="location in assistantFormEditLocations" :key="location.id" :value="location.name">{{ location.name }} ({{ location.code }})</option></select><input v-model="assistantFormEditValue" aria-label="Location name or Custom: location name" placeholder="Or enter Custom: location name" maxlength="180"></template><input v-else v-model="assistantFormEditValue" :aria-label="`Edit ${assistantFormEditField}`" maxlength="4000"><button type="submit" :disabled="assistantFormBusy">Save</button><button type="button" @click="assistantFormEditField = ''">Cancel</button></form><button type="button" class="ifc-assistant-confirm" :disabled="assistantFormBusy" @click="saveAssistantInquiryDraft">Confirm and save draft</button></template>
          <div v-else-if="assistantFormSession.status === 'saved'" class="ifc-assistant-saved-action" role="status">
            <div class="ifc-assistant-completion-heading"><span class="ifc-assistant-success-icon" aria-hidden="true">✓</span><div><b>Sales Inquiry Saved</b><a v-if="can('inquiries.view') && assistantSavedAction?.reference_number" :href="`/sales/inquiries?highlight=${encodeURIComponent(assistantSavedAction.reference_number)}`" @click.prevent="viewAssistantSavedInquiry">{{ assistantSavedAction.reference_number }}</a><span class="ifc-assistant-draft-status">{{ (assistantSavedAction?.status || 'draft').replaceAll('_', ' ') }}</span></div></div>
            <p>{{ assistantSavedAction?.next_steps?.message || `Sales Inquiry ${assistantSavedAction?.reference_number || assistantFormSession.draft?.reference || ''} has been saved as a draft.` }}</p>
            <p v-if="assistantSavedAction?.next_steps?.actions?.length" class="ifc-assistant-suggested-next">Suggested next action: <b>{{ assistantSavedAction.next_steps.actions[0].label }}</b></p>
            <div v-if="assistantSavedAction?.next_steps?.actions?.length" class="ifc-assistant-next-actions"><button v-for="action in assistantSavedAction.next_steps.actions" :key="action.key" type="button" :class="{ 'ifc-assistant-action-primary': action.key === 'review_submit' }" :disabled="assistantWorkflowBusy" :aria-busy="assistantWorkflowBusy && action.key === 'review_submit'" @click="handleAssistantNextStep(action.key)">{{ assistantWorkflowBusy && action.key === 'review_submit' ? 'Loading inquiry…' : action.label }}</button></div>
            <div v-else><button v-if="can('inquiries.view') && assistantSavedAction" type="button" @click="viewAssistantSavedInquiry">View Inquiry</button><button v-if="can('inquiries.create')" type="button" @click="createAnotherAssistantInquiry">Create Another Inquiry</button></div>
            <p v-if="assistantWorkflowError" class="ifc-assistant-workflow-error" role="alert">{{ assistantWorkflowError }}</p>
            <section v-if="assistantWorkflowInquiry" class="ifc-assistant-inquiry-review" aria-label="Saved inquiry review">
              <b>{{ assistantWorkflowInquiry.reference }} · {{ assistantWorkflowInquiry.status.replaceAll('_', ' ') }}</b>
              <dl><div><dt>Client</dt><dd>{{ assistantWorkflowInquiry.client?.company_name }}</dd></div><div><dt>Service</dt><dd>{{ assistantWorkflowInquiry.service_type }} · {{ assistantWorkflowInquiry.transport_mode }}</dd></div><div><dt>Origin</dt><dd>{{ assistantWorkflowInquiry.origin }}</dd></div><div><dt>Destination</dt><dd>{{ assistantWorkflowInquiry.destination }}</dd></div><div><dt>Cargo</dt><dd>{{ assistantWorkflowInquiry.cargo_description }}</dd></div></dl>
              <p>Submission records the inquiry and makes it available for forecasting. No inquiry approval is required.</p>
              <button v-if="assistantWorkflowInquiry.status === 'draft' && assistantSavedAction?.next_steps?.actions?.some(action => action.key === 'review_submit')" type="button" class="ifc-assistant-primary" :disabled="assistantWorkflowBusy" @click="confirmAssistantInquirySubmission">{{ assistantWorkflowBusy ? 'Submitting…' : 'Confirm and Submit Inquiry' }}</button>
              <button type="button" class="ifc-assistant-secondary" :disabled="assistantWorkflowBusy" @click="assistantWorkflowInquiry = null">Close review</button>
            </section>
          </div>
          <p v-if="assistantFormSession.status === 'collecting'" class="ifc-assistant-form-hint">{{ assistantFormSession.question }} Answer in the message box below.</p>
          <button v-if="['collecting', 'review'].includes(assistantFormSession.status)" type="button" class="ifc-assistant-cancel-workflow" @click="cancelAssistantInquiry">Cancel inquiry</button>
          <p v-if="assistantFormError" class="ifc-assistant-error" role="alert">{{ assistantFormError }}</p>
        </section>
        <section v-if="assistantSavedAction?.status === 'submitted' && (!assistantFormSession || Number(assistantFormSession.draft?.id) !== Number(assistantSavedAction.entity_id))" class="ifc-assistant-active-form is-complete" aria-label="Submitted inquiry next step">
          <div class="ifc-assistant-active-form-heading"><b>{{ assistantSavedAction.reference_number }} submitted</b></div>
          <p>{{ assistantSavedAction.next_steps?.message }}</p>
          <div class="ifc-assistant-next-actions"><button v-for="action in assistantSavedAction.next_steps?.actions || []" :key="action.key" type="button" :class="{ 'ifc-assistant-action-primary': action.key === 'create_forecast' }" @click="handleAssistantNextStep(action.key)">{{ action.label }}</button></div>
        </section>
          <section v-if="assistantForecast" class="ifc-assistant-inquiry-review" aria-label="Shipment forecast assistant">
            <b>Shipment forecast · {{ assistantForecast.inquiry.reference }}</b>
            <p>{{ assistantForecast.inquiry.client?.company_name }} · {{ assistantForecast.inquiry.origin }} → {{ assistantForecast.inquiry.destination }} · {{ assistantForecast.inquiry.service_type }} / {{ assistantForecast.inquiry.transport_mode }}</p>
            <p>Cargo: {{ assistantForecast.inquiry.cargo_description }} · Requested ship date: {{ assistantForecast.inquiry.requested_ship_date || 'Not provided' }}</p>
            <label v-if="assistantForecast.step === 'month'">{{ forecastText('Which month do you expect the shipment?', 'Anong buwan mo inaasahan ang shipment?', 'Sa anong buwan inaasahan ang kargamento?') }}<input v-model="assistantForecast.month" type="month" required></label>
            <label v-else-if="assistantForecast.step === 'count'">{{ forecastText('How many shipments do you expect?', 'Ilang shipment ang inaasahan mo?', 'Ilang kargamento ang inaasahan mo?') }}<input v-model="assistantForecast.count" type="number" min="1" max="10000" required></label>
            <label v-else-if="assistantForecast.step === 'confidence'">{{ forecastText('How confident is this forecast?', 'Gaano ka kasigurado sa forecast?', 'Gaano katiyak ang pagtatayang ito?') }}<select v-model="assistantForecast.confidence" required><option value="">Choose confidence</option><option value="tentative">Tentative</option><option value="probable">Probable</option><option value="confirmed">Confirmed</option></select></label>
            <dl v-else><div><dt>Forecast month</dt><dd>{{ assistantForecast.month }} <button type="button" @click="assistantForecast.step = 'month'">Change</button></dd></div><div><dt>Expected shipments</dt><dd>{{ assistantForecast.count }} <button type="button" @click="assistantForecast.step = 'count'">Change</button></dd></div><div><dt>Confidence</dt><dd>{{ assistantForecast.confidence }} <button type="button" @click="assistantForecast.step = 'confidence'">Change</button></dd></div></dl>
            <p v-if="assistantForecastError" role="alert">{{ assistantForecastError }}</p>
            <div class="ifc-assistant-next-actions"><button v-if="assistantForecast.step !== 'preview'" type="button" @click="advanceAssistantForecast">Continue</button><button v-else type="button" class="ifc-assistant-action-primary" :disabled="assistantForecastBusy" @click="confirmAssistantForecast">{{ assistantForecastBusy ? 'Saving…' : 'Confirm and save forecast' }}</button><button type="button" @click="assistantForecast = null">Cancel</button></div>
          </section>
          <p v-else-if="assistantForecastError" role="alert">{{ assistantForecastError }}</p>
        <section v-if="assistantClientFormSession" class="ifc-assistant-active-form ifc-assistant-client-form" :class="{ 'is-complete': assistantClientFormSession.status === 'saved' }" aria-label="Client Management form assistant">
          <div class="ifc-assistant-active-form-heading"><b>{{ assistantClientFormSession.status === 'saved' ? 'Client saved' : 'Client Management form' }}</b><span v-if="assistantClientFormSession.status === 'collecting'">{{ assistantClientFormSession.progress }}% complete</span><button type="button" aria-label="Close client form workflow" @click="assistantClientFormSession = null">×</button></div>
          <progress v-if="assistantClientFormSession.status === 'collecting'" max="100" :value="assistantClientFormSession.progress">{{ assistantClientFormSession.progress }}%</progress>
          <div v-if="assistantClientFormSession.status === 'collecting' && assistantClientFormSession.choices?.length" class="ifc-assistant-choice-actions"><button v-for="choice in assistantClientFormSession.choices" :key="choice" type="button" :disabled="assistantClientFormBusy" @click="message = choice; askAssistant()">{{ choice }}</button></div>
          <p v-if="assistantClientFormSession.status === 'collecting'" class="ifc-assistant-form-hint">{{ assistantClientFormSession.question }} Answer in the message box below. The Client Management form updates as you answer.</p>
          <template v-if="assistantClientFormSession.status === 'review'"><p class="ifc-assistant-review-intro">I’ve completed the client form. Review the details before saving.</p><dl class="ifc-assistant-client-preview"><div v-for="[field, label] in [['company_name', 'Company name'], ['client_type', 'Client type'], ['contact_name', 'Contact name'], ['email', 'Email'], ['phone', 'Phone'], ['billing_address', 'Billing address']]" :key="field"><dt>{{ label }}</dt><dd>{{ assistantClientFormSession.values[field] || 'Not provided' }}</dd></div></dl><div class="ifc-assistant-next-actions"><button type="button" :disabled="assistantClientFormBusy" @click="openAssistantClientForm">Open prefilled client form</button><button type="button" class="ifc-assistant-action-primary" :disabled="assistantClientFormBusy" :aria-busy="assistantClientFormBusy" @click="saveAssistantClientForm()">{{ assistantClientFormBusy ? 'Saving client…' : 'Confirm and save client' }}</button></div></template>
          <div v-if="assistantClientFormSession.status === 'saved'" class="ifc-assistant-client-saved" role="status"><span class="ifc-assistant-success-icon" aria-hidden="true">✓</span><div><b>Client saved</b><a v-if="can('clients.view')" :href="`/sales/clients?client_id=${encodeURIComponent(assistantClientFormSession.client?.id || '')}`">{{ assistantClientFormSession.client?.reference }} · {{ assistantClientFormSession.client?.company_name }}</a><span v-else>{{ assistantClientFormSession.client?.company_name }}</span><p>{{ assistantClientFormSession.next_steps?.message || `Done! ${assistantClientFormSession.client?.company_name} has been saved.` }}</p><div v-if="assistantClientFormSession.next_steps?.actions?.length" class="ifc-assistant-next-actions"><button v-for="action in assistantClientFormSession.next_steps.actions" :key="action.key" type="button" @click="handleAssistantClientNextStep(action.key)">{{ action.label }}</button></div></div></div>
          <p v-if="assistantClientFormError" class="ifc-assistant-workflow-error" role="alert">{{ assistantClientFormError }}</p>
        </section>
        <div v-if="chatBusy" class="ifc-chat-typing" role="status"><span></span><span></span><span></span> IFC Assistant is thinking…</div>
        <div v-if="assistantUploading" class="ifc-assistant-upload-status" role="status">Uploading attachments · {{ assistantUploadProgress }}%</div>
      </main>

      <footer class="ifc-assistant-composer-shell">
        <div v-if="pendingAssistantFiles.length" class="ifc-assistant-pending-files"><span v-for="(file, index) in pendingAssistantFiles" :key="`${file.name}-${file.size}`" class="ifc-assistant-pending-chip">{{ file.name }}<button type="button" :aria-label="`Remove ${file.name}`" @click="removePendingAssistantFile(index)">×</button></span></div>
        <div v-if="assistantUploadError" class="ifc-assistant-composer-error" role="alert">{{ assistantUploadError }}</div>
        <div class="ifc-assistant-composer">
          <textarea ref="assistantMessageInput" v-model="message" aria-label="Message IFC Assistant" :placeholder="assistantClientFormSession?.status === 'collecting' ? assistantClientFormSession.question : assistantFormSession?.status === 'collecting' ? assistantFormSession.question : 'Ask IFC anything…'" maxlength="1000" rows="1" :disabled="chatBusy || assistantUploading || assistantFormBusy || assistantClientFormBusy" @keydown.enter.exact.prevent="askAssistant"></textarea>
          <div class="ifc-assistant-composer-actions">
            <button type="button" class="ifc-assistant-composer-icon" aria-label="Workflow tools" :aria-expanded="assistantMenu === 'tools'" @click="toggleAssistantMenu('tools')"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>
            <button type="button" class="ifc-assistant-composer-icon" aria-label="Attach files" :aria-expanded="assistantMenu === 'attachments'" @click="toggleAssistantMenu('attachments')"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 12.5 6.8-6.8a3.2 3.2 0 0 1 4.5 4.5l-8.8 8.9a5 5 0 0 1-7.1-7.1l8.5-8.5"/></svg></button>
            <button type="button" class="ifc-assistant-send" aria-label="Send message" :disabled="(!message.trim() && !pendingAssistantFiles.length) || chatBusy || assistantUploading || assistantClientFormBusy" @click="askAssistant"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5m-7 7 7-7 7 7"/></svg></button>
          </div>
        </div>
        <section v-if="assistantMenu === 'attachments'" class="ifc-assistant-popover ifc-assistant-attachment-menu" aria-label="Attach a file">
          <label class="ifc-assistant-menu-item"><b>Upload document</b><small>PDF, office document, spreadsheet, or CSV</small><input type="file" multiple accept=".pdf,.docx,.xlsx,.csv" @change="queueAssistantDocuments"></label>
          <label class="ifc-assistant-menu-item"><b>Upload image</b><small>JPG or PNG</small><input type="file" multiple accept="image/jpeg,image/png" @change="queueAssistantDocuments"></label>
          <div v-if="assistantDocuments.length" class="ifc-assistant-existing-files"><b>Attached in this session</b><span v-for="document in assistantDocuments" :key="document.id"><span>{{ document.filename }} · {{ document.extraction_status }}</span><button type="button" :aria-label="`Remove ${document.filename}`" @click="removeAssistantDocument(document)">Remove</button></span></div>
        </section>
      </footer>
    </aside>
    <div v-if="toast" class="ifc-toast" role="status">{{ toast }}</div>
  </div>
</template>
