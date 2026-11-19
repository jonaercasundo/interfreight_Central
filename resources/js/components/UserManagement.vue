<script setup>
import { computed, reactive, ref } from 'vue'

const props = defineProps({ data: { type: Object, required: true } })
const query = new URLSearchParams(window.location.search)
const filters = reactive({ search: props.data.filters.search || '', status: props.data.filters.status || '', department: props.data.filters.department || '', role: props.data.filters.role || '' })
const modal = ref('')
const selected = ref(null)
const busy = ref(false)
const toast = ref(sessionStorage.getItem('ifcUserToast') || '')
const errors = ref({})
const form = reactive({ name: '', email: '', roles: [], is_active: true, setup_method: 'temporary_password', password: '', password_confirmation: '' })
const tempPassword = reactive({ password: '', password_confirmation: '' })
const tempGenerated = ref(false)
const tempVisible = ref(false)
const canResetPasswords = computed(() => props.data.permissions.password_reset)
const canCreate = computed(() => props.data.permissions.create)
const canEdit = computed(() => props.data.permissions.update)
const canAssignRoles = computed(() => props.data.permissions.assign_roles)
const canToggleActive = computed(() => props.data.permissions.deactivate)
const page = props.data.users
const visiblePages = computed(() => [...new Set([1, page.current_page - 1, page.current_page, page.current_page + 1, page.last_page].filter((number) => number >= 1 && number <= page.last_page))].sort((a, b) => a - b))
const initials = (name) => (name || '?').split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase()

if (toast.value) {
  sessionStorage.removeItem('ifcUserToast')
  window.setTimeout(() => { toast.value = '' }, 4500)
}

function showCreate() {
  selected.value = null
  Object.assign(form, { name: '', email: '', roles: [], is_active: true, setup_method: canResetPasswords.value ? 'temporary_password' : 'setup_link', password: '', password_confirmation: '' })
  Object.assign(tempPassword, { password: '', password_confirmation: '' })
  tempVisible.value = false
  errors.value = {}
  modal.value = 'create'
}

function showEdit(user) {
  selected.value = user
  Object.assign(form, { name: user.name, email: user.email, roles: [...user.roles], is_active: user.is_active })
  form.password = ''
  form.password_confirmation = ''
  errors.value = {}
  modal.value = 'edit'
}

function showView(user) {
  selected.value = user
  modal.value = 'view'
}

function closeModal() {
  modal.value = ''
  form.password = ''
  form.password_confirmation = ''
  Object.assign(tempPassword, { password: '', password_confirmation: '' })
  tempGenerated.value = false
  tempVisible.value = false
}

function canResetPassword(user) {
  return canResetPasswords.value && user.id !== props.data.current_user_id && (!user.is_privileged || props.data.permissions.privileged_password_reset)
}

function showTemporaryPassword(user) {
  selected.value = user
  Object.assign(tempPassword, { password: '', password_confirmation: '' })
  tempGenerated.value = false
  tempVisible.value = false
  errors.value = {}
  modal.value = 'temporary-password'
}

function generatePassword() {
  const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*()-_=+']
  const random = (max) => {
    const limit = Math.floor(256 / max) * max
    const byte = new Uint8Array(1)
    do { crypto.getRandomValues(byte) } while (byte[0] >= limit)
    return byte[0] % max
  }
  const chars = sets.map((set) => set[random(set.length)])
  const all = sets.join('')
  while (chars.length < 24) chars.push(all[random(all.length)])
  for (let i = chars.length - 1; i > 0; i--) { const j = random(i + 1); [chars[i], chars[j]] = [chars[j], chars[i]] }
  tempPassword.password = chars.join('')
  tempPassword.password_confirmation = tempPassword.password
  tempGenerated.value = true
}

async function copyGeneratedPassword() {
  if (!tempGenerated.value) return
  try { await navigator.clipboard.writeText(tempPassword.password); toast.value = 'Temporary password copied. Share it securely.' }
  catch { errors.value = { form: ['Clipboard access is unavailable. You can select and copy the locally generated password.'] } }
}

function runSearch() {
  const params = new URLSearchParams()
  for (const key of ['search', 'status', 'department', 'role']) if (filters[key]) params.set(key, filters[key])
  window.location.assign(`/admin/users${params.size ? `?${params.toString()}` : ''}`)
}

function goToPage(number) {
  if (number < 1 || number > page.last_page) return
  const params = new URLSearchParams(query)
  params.set('page', String(number))
  window.location.assign(`/admin/users?${params.toString()}`)
}

async function saveUser() {
  busy.value = true
  errors.value = {}
  const creating = modal.value === 'create'
  const body = { name: form.name, email: form.email }
  if (canAssignRoles.value) body.roles = form.roles
  if (creating) body.roles = form.roles
  if (creating) {
    body.setup_method = form.setup_method
    if (form.setup_method === 'temporary_password') {
      body.password = form.password
      body.password_confirmation = form.password_confirmation
    }
  }
  if (!creating && canToggleActive.value) body.is_active = form.is_active ? 1 : 0
  try {
    const response = await fetch(creating ? '/admin/users' : `/admin/users/${selected.value.id}`, {
      method: creating ? 'POST' : 'PUT',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
      body: JSON.stringify(body),
    })
    const result = await response.json()
    if (!response.ok) {
      if (response.status === 422) errors.value = result.errors || {}
      else errors.value = { form: [result.message || 'The user could not be saved.'] }
      return
    }
    closeModal()
    sessionStorage.setItem('ifcUserToast', result.message)
    window.location.reload()
  } catch {
    errors.value = { form: ['Unable to reach the server. Please try again.'] }
  } finally {
    busy.value = false
  }
}

async function saveTemporaryPassword() {
  if (busy.value || !selected.value) return
  busy.value = true
  errors.value = {}
  try {
    const response = await fetch(`/admin/users/${selected.value.id}/temporary-password`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
      body: JSON.stringify({ ...tempPassword }),
    })
    const result = await response.json()
    if (!response.ok) {
      if (response.status === 422) errors.value = result.errors || {}
      else errors.value = { form: [result.message || 'The temporary password could not be set.'] }
      return
    }
    closeModal()
    toast.value = result.message
    window.setTimeout(() => { toast.value = '' }, 5000)
  } catch {
    errors.value = { form: ['Unable to reach the server. Please try again.'] }
  } finally { busy.value = false }
}

async function sendResetLink(user) {
  if (!window.confirm(`Send a password reset link to ${user.email}?`)) return
  try {
    const response = await fetch(`/admin/users/${user.id}/reset-link`, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } })
    const result = await response.json()
    if (!response.ok) throw new Error(result.message || 'The reset link could not be sent.')
    toast.value = result.message
  } catch (error) {
    toast.value = error.message
  }
  window.setTimeout(() => { toast.value = '' }, 4500)
}

async function toggleStatus(user) {
  const next = !user.is_active
  const action = next ? 'activate' : 'deactivate'
  if (!window.confirm(`Are you sure you want to ${action} ${user.name}?`)) return
  try {
    const response = await fetch(`/admin/users/${user.id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
      body: JSON.stringify({ name: user.name, email: user.email, is_active: next ? 1 : 0 }),
    })
    const result = await response.json()
    if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || `Unable to ${action} account.`)
    window.location.reload()
  } catch (error) {
    toast.value = error.message
    window.setTimeout(() => { toast.value = '' }, 4500)
  }
}
</script>

<template>
  <div class="um-page">
    <div class="um-heading">
      <div><span class="um-eyebrow">PEOPLE & ACCESS</span><h2>User Management</h2><p>Manage organization accounts, assigned roles, and account access.</p></div>
      <button v-if="canCreate" class="ifc-primary um-add" @click="showCreate"><span aria-hidden="true">＋</span> Add User</button>
    </div>

    <section class="um-kpis" aria-label="User account summary">
      <article class="um-kpi"><span>Total users</span><strong>{{ data.metrics.total }}</strong><small>Organization accounts</small></article>
      <article class="um-kpi"><span>Active users</span><strong>{{ data.metrics.active }}</strong><small>Can sign in</small></article>
      <article class="um-kpi"><span>Inactive users</span><strong>{{ data.metrics.inactive }}</strong><small>Access disabled</small></article>
      <article class="um-kpi"><span>Assigned roles</span><strong>{{ data.metrics.assigned_roles }}</strong><small>Role assignments</small></article>
    </section>

    <section class="ifc-panel um-filter-panel" aria-label="Search and filter users">
      <form class="um-filters" @submit.prevent="runSearch">
        <label class="um-search-field"><span>Name or email</span><div class="um-input-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input v-model="filters.search" type="search" placeholder="Search users" autocomplete="off"></div></label>
        <label><span>Account status</span><select v-model="filters.status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        <label><span>Department</span><select v-model="filters.department"><option value="">All departments</option><option v-for="department in data.departments" :key="department" :value="department">{{ department }}</option></select></label>
        <label><span>Role</span><select v-model="filters.role"><option value="">All roles</option><option v-for="role in data.roles" :key="role" :value="role">{{ role }}</option></select></label>
        <div class="um-filter-actions"><button class="ifc-primary" type="submit">Apply filters</button><a href="/admin/users" class="um-reset">Reset filters</a></div>
      </form>
    </section>

    <section class="ifc-panel um-list-panel">
      <div class="um-list-head"><div><h3>Organization users</h3><p>{{ page.total }} account{{ page.total === 1 ? '' : 's' }} found</p></div><span class="um-page-indicator">Page {{ page.current_page }} of {{ page.last_page }}</span></div>
      <div class="um-table-wrap">
        <table class="um-table"><thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th class="um-actions-col">Actions</th></tr></thead>
          <tbody>
            <tr v-for="user in page.data" :key="user.id">
              <td><div class="um-person"><span class="um-avatar">{{ initials(user.name) }}</span><span><b>{{ user.name }}</b><small>User account</small></span></div></td>
              <td class="um-email">{{ user.email }}</td>
              <td><div class="um-role-tags"><span v-for="role in user.roles" :key="role" class="um-role-tag">{{ role }}</span><span v-if="!user.roles.length" class="um-muted">No role assigned</span></div></td>
              <td><span :class="['um-status', user.is_active ? 'is-active' : 'is-inactive']"><i></i>{{ user.is_active ? 'Active' : 'Inactive' }}</span></td>
              <td><div class="um-row-actions"><button class="um-action" @click="showView(user)">View</button><button v-if="canEdit" class="um-action" @click="showEdit(user)">Edit</button><button v-if="canEdit && canToggleActive" class="um-action" @click="toggleStatus(user)">{{ user.is_active ? 'Deactivate' : 'Activate' }}</button><button v-if="canEdit" class="um-action um-action-reset" @click="sendResetLink(user)">Send reset link</button><button v-if="canResetPassword(user)" class="um-action um-action-reset" @click="showTemporaryPassword(user)">Set temporary password</button></div></td>
            </tr>
            <tr v-if="!page.data.length"><td colspan="5"><div class="um-empty"><span class="um-empty-icon">⌕</span><b>{{ filters.search || filters.status || filters.department || filters.role ? 'No users match these filters' : 'No user accounts yet' }}</b><small>{{ filters.search || filters.status || filters.department || filters.role ? 'Try changing your search or resetting filters.' : 'Accounts you create will appear here.' }}</small><a v-if="filters.search || filters.status || filters.department || filters.role" href="/admin/users">Reset filters</a></div></td></tr>
          </tbody>
        </table>
      </div>
      <div v-if="page.last_page > 1" class="um-pagination"><span>Showing {{ page.from }}–{{ page.to }} of {{ page.total }}</span><div><button :disabled="page.current_page <= 1" aria-label="Previous page" @click="goToPage(page.current_page - 1)">←</button><template v-for="(number, index) in visiblePages" :key="number"><span v-if="index && number - visiblePages[index - 1] > 1" class="um-page-gap">…</span><button :aria-current="number === page.current_page ? 'page' : undefined" :class="{ current: number === page.current_page }" @click="goToPage(number)">{{ number }}</button></template><button :disabled="page.current_page >= page.last_page" aria-label="Next page" @click="goToPage(page.current_page + 1)">→</button></div></div>
    </section>

    <div v-if="modal" class="um-modal-backdrop" @click.self="closeModal" @keydown.esc="closeModal">
      <section class="um-dialog" role="dialog" aria-modal="true" :aria-labelledby="'um-dialog-title'">
        <header class="um-dialog-head"><div><span class="um-eyebrow">{{ modal === 'create' ? 'NEW ACCOUNT' : modal === 'edit' ? 'ACCOUNT SETTINGS' : modal === 'temporary-password' ? 'ACCOUNT SECURITY' : 'ACCOUNT DETAILS' }}</span><h3 id="um-dialog-title">{{ modal === 'create' ? 'Add user' : modal === 'edit' ? 'Edit user' : modal === 'temporary-password' ? 'Set temporary password' : 'User details' }}</h3><p>{{ modal === 'create' ? 'Create an account with a temporary password or secure setup link.' : selected?.email }}</p></div><button class="um-dialog-close" aria-label="Close dialog" @click="closeModal">×</button></header>
        <form v-if="modal !== 'view' && modal !== 'temporary-password'" class="um-form" @submit.prevent="saveUser">
          <p v-if="errors.form" class="um-form-error" role="alert">{{ errors.form[0] }}</p>
          <label><span>Full name</span><input v-model.trim="form.name" required maxlength="120" autocomplete="name" :aria-invalid="!!errors.name"><small v-if="errors.name" class="um-field-error">{{ errors.name[0] }}</small></label>
          <label><span>Work email</span><input v-model.trim="form.email" required type="email" maxlength="254" autocomplete="email" :aria-invalid="!!errors.email"><small v-if="errors.email" class="um-field-error">{{ errors.email[0] }}</small></label>
          <label v-if="modal === 'create'"><span>Account setup</span><select v-model="form.setup_method"><option v-if="canResetPasswords" value="temporary_password">Temporary password (change on first sign-in)</option><option value="setup_link">Email a secure setup link</option></select></label>
          <template v-if="modal === 'create' && form.setup_method === 'temporary_password'"><label><span>Temporary password</span><div class="um-password-control"><input v-model="form.password" :type="tempVisible ? 'text' : 'password'" autocomplete="new-password" minlength="12" required @input="tempGenerated = false"><button type="button" @click="tempVisible = !tempVisible">{{ tempVisible ? 'Hide' : 'Show' }}</button></div><small v-if="errors.password" class="um-field-error">{{ errors.password[0] }}</small></label><label><span>Confirm temporary password</span><input v-model="form.password_confirmation" :type="tempVisible ? 'text' : 'password'" autocomplete="new-password" minlength="12" required><small v-if="errors.password_confirmation" class="um-field-error">{{ errors.password_confirmation[0] }}</small></label><div class="um-password-tools"><div><button type="button" class="um-reset" @click="generatePassword(); form.password = tempPassword.password; form.password_confirmation = tempPassword.password; tempGenerated = true">Generate strong password</button><button v-if="tempGenerated" type="button" class="um-reset" @click="copyGeneratedPassword">Copy generated password</button></div><small>At least 12 characters, uppercase and lowercase letters, a number, and a symbol.</small></div></template>
          <fieldset v-if="canAssignRoles"><legend>Assigned roles</legend><p class="um-field-hint">Select the access this account needs.</p><div class="um-role-picker"><label v-for="role in data.roles" :key="role" class="um-role-option"><input v-model="form.roles" type="checkbox" :value="role"><span>{{ role }}</span></label></div><small v-if="errors.roles" class="um-field-error">{{ errors.roles[0] }}</small></fieldset>
          <p v-else-if="modal === 'create'" class="um-form-error" role="alert">Your account cannot assign roles, which is required to create users.</p>
          <label v-if="modal === 'edit' && canToggleActive" class="um-toggle"><input v-model="form.is_active" type="checkbox"><span><b>Account active</b><small>Active users can sign in to IFC.</small></span></label><small v-if="errors.is_active" class="um-field-error">{{ errors.is_active[0] }}</small>
          <footer class="um-dialog-actions"><button type="button" class="ifc-secondary" @click="closeModal">Cancel</button><button class="ifc-primary" :disabled="busy || (modal === 'create' && !canCreate)">{{ busy ? 'Saving…' : modal === 'create' ? 'Create user' : 'Save changes' }}</button></footer>
        </form>
        <form v-else-if="modal === 'temporary-password'" class="um-form" @submit.prevent="saveTemporaryPassword"><p class="um-form-error" role="status">The user will be signed out of existing sessions and must change this password at next sign-in.</p><p v-if="errors.form" class="um-form-error" role="alert">{{ errors.form[0] }}</p><label><span>Temporary password</span><div class="um-password-control"><input v-model="tempPassword.password" :type="tempVisible ? 'text' : 'password'" autocomplete="new-password" minlength="12" required @input="tempGenerated = false"><button type="button" @click="tempVisible = !tempVisible">{{ tempVisible ? 'Hide' : 'Show' }}</button></div><small v-if="errors.password" class="um-field-error">{{ errors.password[0] }}</small></label><label><span>Confirm temporary password</span><input v-model="tempPassword.password_confirmation" :type="tempVisible ? 'text' : 'password'" autocomplete="new-password" minlength="12" required><small v-if="errors.password_confirmation" class="um-field-error">{{ errors.password_confirmation[0] }}</small></label><div class="um-password-tools"><div><button type="button" class="um-reset" @click="generatePassword(); Object.assign(tempPassword, { password: tempPassword.password, password_confirmation: tempPassword.password }); tempGenerated = true">Generate strong password</button><button v-if="tempGenerated" type="button" class="um-reset" @click="copyGeneratedPassword">Copy generated password</button></div><small>At least 12 characters, uppercase and lowercase letters, a number, and a symbol. The password is shown only in this dialog before saving.</small></div><footer class="um-dialog-actions"><button type="button" class="ifc-secondary" @click="closeModal">Cancel</button><button class="ifc-primary" :disabled="busy">{{ busy ? 'Setting…' : 'Set temporary password' }}</button></footer></form>
        <div v-else class="um-view-details"><div><span>Name</span><b>{{ selected.name }}</b></div><div><span>Work email</span><b>{{ selected.email }}</b></div><div><span>Roles</span><b>{{ selected.roles.join(', ') || 'None assigned' }}</b></div><div><span>Status</span><b>{{ selected.is_active ? 'Active' : 'Inactive' }}</b></div><div class="um-dialog-actions"><button v-if="canEdit" class="ifc-secondary" @click="showEdit(selected)">Edit user</button><button class="ifc-primary" @click="modal = ''">Close</button></div></div>
      </section>
    </div>
    <div v-if="toast" class="ifc-toast" role="status">{{ toast }}</div>
  </div>
</template>
