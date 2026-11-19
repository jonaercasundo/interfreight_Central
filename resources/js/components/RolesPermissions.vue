<script setup>
import { computed, ref } from 'vue'

const props = defineProps({ data: { type: Object, required: true } })
const roles = ref(props.data.roles.map((role) => ({ ...role, permissions: [...role.permissions] })))
const savedPermissions = ref(new Map(props.data.roles.map((role) => [role.id, role.permissions.map((permission) => permission.name)])))
const selectedRoleId = ref((roles.value.find((role) => role.name === 'Executive') || roles.value.find((role) => role.name !== 'Administrator') || roles.value[0])?.id ?? null)
const roleSearch = ref('')
const permissionSearch = ref('')
const saving = ref(false)
const error = ref('')
const notice = ref('')
const adminRoleName = 'Administrator'
const identity = JSON.parse(document.querySelector('#app')?.dataset.user || '{}')
const canManage = computed(() => identity.roles?.includes(adminRoleName) && identity.permissions?.includes('permissions.manage'))
const restrictedNames = new Set(['users.view', 'users.create', 'users.update', 'users.deactivate', 'users.assign-role', 'roles.manage', 'permissions.manage', 'audit.export', 'shipments.manage'])
const selectedRole = computed(() => roles.value.find((role) => role.id === selectedRoleId.value))
const isProtected = computed(() => selectedRole.value?.name === adminRoleName)
const selectedNames = computed(() => selectedRole.value?.permissions.map((permission) => permission.name) || [])
const dirty = computed(() => {
  const original = savedPermissions.value.get(selectedRoleId.value) || []
  return [...selectedNames.value].sort().join('|') !== [...original].sort().join('|')
})
const grantedCount = computed(() => selectedNames.value.length)
const filteredRoles = computed(() => roles.value.filter((role) => role.name.toLowerCase().includes(roleSearch.value.trim().toLowerCase())))
const roleDescriptions = {
  Administrator: 'System-wide access and security controls',
  Executive: 'Organization overview and cross-department visibility',
  Sales: 'Client acquisition and commercial workspace',
  'Sales Manager': 'Sales team oversight and inquiry approvals',
  Operations: 'Shipment coordination and operations workspace',
  Transport: 'Pickup, delivery and carrier workspace',
  Forwarding: 'Air and sea forwarding workspace',
  Accounting: 'Financial records and accounting workspace',
  Billing: 'Client billing and receivables workspace',
  Finance: 'Cash flow, budgets and financial oversight',
}
const labelMap = {
  'dashboard.view': 'View dashboard',
  'executive.overview.view': 'View executive overview',
  'users.view': 'View user accounts',
  'users.create': 'Create user accounts',
  'users.update': 'Edit user accounts',
  'users.deactivate': 'Activate or deactivate accounts',
  'users.assign-role': 'Assign user roles',
  'roles.view': 'View roles and permissions',
  'roles.manage': 'Manage roles',
  'permissions.view': 'View permissions',
  'permissions.manage': 'Manage role permissions',
  'audit.view': 'View audit logs',
  'audit.export': 'Export audit logs',
  'integrations.status.view': 'View integration health',
  'profile.view': 'View own profile',
  'profile.update': 'Update own profile',
  'assistant.use': 'Use IFC Assistant',
  'admin.ai-config.view': 'View AI configuration',
  'admin.ai-config.manage': 'Manage AI configuration',
  'admin.ai-config.test': 'Test AI provider connection',
  'admin.ai-usage.view': 'View AI usage and cost monitoring',
  'admin.ai-pricing.manage': 'Manage verified AI model pricing',
  'admin.ai-fx.manage': 'Manage verified USD to PHP rates',
  'admin.ai-budget.manage': 'Manage monthly AI budgets',
  'admin.ai-usage.export': 'Export AI usage reports',
  'admin.ai-usage.view': 'View AI usage and cost monitoring',
  'admin.ai-pricing.manage': 'Manage verified AI model pricing',
  'admin.ai-fx.manage': 'Manage verified USD to PHP rates',
  'admin.ai-budget.manage': 'Manage monthly AI budgets',
  'admin.ai-usage.export': 'Export AI usage reports',
  'shipments.view': 'View shipment records',
  'shipments.manage': 'Create and manage shipments',
  'sales.dashboard.view': 'View Sales dashboard',
  'sales.calendar.view': 'View shipment calendar',
  'sales.availability.view': 'View confirmed shipment availability',
  'sales.availability.manage': 'Manage verified shipment availability',
  'sales.inquiries.approve': 'Approve submitted client inquiries',
}
const domainGroups = [
  ['Dashboard & Profile', (name) => name.startsWith('dashboard.') || name.startsWith('executive.') || name.startsWith('profile.')],
  ['Department Access', (name) => name.startsWith('department.')],
  ['Users & Administration', (name) => name.startsWith('users.') || name.startsWith('admin.ai-config.') || name.startsWith('admin.ai-')],
  ['Roles & Permissions', (name) => name.startsWith('roles.') || name.startsWith('permissions.')],
  ['Audit & Security', (name) => name.startsWith('audit.')],
  ['Integrations', (name) => name.startsWith('integrations.') || name.startsWith('integration.')],
  ['Shipments & Operations', (name) => name.startsWith('shipments.') || name.startsWith('shipment.')],
  ['Other permissions', (name) => !['dashboard.', 'executive.', 'profile.', 'department.', 'users.', 'roles.', 'permissions.', 'audit.', 'integrations.', 'integration.', 'shipments.', 'shipment.'].some((prefix) => name.startsWith(prefix))],
]
const groups = computed(() => {
  const query = permissionSearch.value.trim().toLowerCase()
  return domainGroups.map(([title, matches]) => ({
    title,
    permissions: props.data.permissions.filter((permission) => matches(permission.name) && (!query || `${friendly(permission.name)} ${permission.name}`.toLowerCase().includes(query))),
  })).filter((group) => group.permissions.length)
})
const initials = (name) => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase()

function friendly(name) {
  if (labelMap[name]) return labelMap[name]
  return name.split('.').map((part) => part.replace(/[-_]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())).join(' · ')
}

function selectRole(role) {
  if (role.id === selectedRoleId.value) return
  if (dirty.value && !window.confirm('Discard unsaved permission changes and switch roles?')) return
  selectedRoleId.value = role.id
  error.value = ''
  notice.value = ''
  permissionSearch.value = ''
}

function setPermission(name, checked) {
  if (isProtected.value || !selectedRole.value || (restrictedNames.has(name) && selectedRole.value.name !== adminRoleName)) return
  const current = new Set(selectedNames.value)
  checked ? current.add(name) : current.delete(name)
  selectedRole.value.permissions = props.data.permissions.filter((permission) => current.has(permission.name))
}

function setGroup(group, checked) {
  group.permissions.filter((permission) => selectedRole.value?.name === adminRoleName || !restrictedNames.has(permission.name)).forEach((permission) => setPermission(permission.name, checked))
}

function isLocked(permission) {
  return isProtected.value || !canManage.value || (restrictedNames.has(permission.name) && selectedRole.value?.name !== adminRoleName)
}

function groupState(group) {
  const count = group.permissions.filter((permission) => selectedNames.value.includes(permission.name)).length
  return { count, all: count === group.permissions.length, partial: count > 0 && count < group.permissions.length }
}

function groupContainsRestricted(group) {
  return selectedRole.value?.name !== adminRoleName && group.permissions.some((permission) => restrictedNames.has(permission.name))
}

function groupHasGrantedRestricted(group) {
  return selectedRole.value?.name !== adminRoleName && group.permissions.some((permission) => restrictedNames.has(permission.name) && selectedNames.value.includes(permission.name))
}

function discard() {
  if (!selectedRole.value) return
  const original = new Set(savedPermissions.value.get(selectedRoleId.value) || [])
  selectedRole.value.permissions = props.data.permissions.filter((permission) => original.has(permission.name))
  error.value = ''
  notice.value = ''
}

async function save() {
  if (!dirty.value || saving.value || !selectedRole.value || isProtected.value || !canManage.value) return
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await fetch(`/admin/roles/${selectedRole.value.id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
      body: JSON.stringify({ permissions: selectedNames.value }),
    })
    const result = await response.json()
    if (!response.ok) {
      error.value = response.status === 422 ? Object.values(result.errors || {}).flat().join(' ') : (result.message || 'Permissions could not be saved.')
      if (!error.value) error.value = 'The request was rejected. Review access and try again.'
      return
    }
    const persisted = props.data.permissions.filter((permission) => result.permissions.includes(permission.name))
    selectedRole.value.permissions = persisted
    savedPermissions.value.set(selectedRoleId.value, persisted.map((permission) => permission.name))
    notice.value = result.message || 'Permissions saved.'
  } catch {
    error.value = 'Could not reach IFC. Your changes are still here; try saving again.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="rbac-page">
    <div class="rbac-heading">
      <div><span class="ifc-kicker">ACCESS CONTROL / ROLE DIRECTORY</span><h1>Roles &amp; Permissions</h1><p>Choose a role to review the access it grants across InterFreight Central.</p></div>
      <div class="rbac-registry"><b>{{ data.permissions.length }}</b><span>registered permissions</span></div>
    </div>

    <div class="rbac-layout">
      <aside class="rbac-role-panel" aria-label="Role directory">
        <div class="rbac-role-panel-head"><div><h2>IFC roles</h2><span>{{ filteredRoles.length }} of {{ roles.length }} roles</span></div><span class="rbac-role-total">{{ roles.length }}</span></div>
        <label class="rbac-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input v-model="roleSearch" type="search" placeholder="Find a role" aria-label="Search roles"></label>
        <div class="rbac-role-list" aria-label="Select a role">
          <button v-for="role in filteredRoles" :key="role.id" type="button" :aria-pressed="role.id === selectedRoleId" :class="['rbac-role-option', { 'is-selected': role.id === selectedRoleId }]" @click="selectRole(role)">
            <span class="rbac-role-avatar">{{ initials(role.name) }}</span><span class="rbac-role-copy"><b>{{ role.name }}</b><small>{{ roleDescriptions[role.name] || 'Organization role access' }}</small></span><span class="rbac-role-count">{{ role.permissions.length }}</span>
          </button>
          <div v-if="!filteredRoles.length" class="rbac-empty">No roles match “{{ roleSearch }}”.</div>
        </div>
        <div class="rbac-role-foot"><span class="rbac-secure-mark">✓</span><span><b>Permission changes are audited</b><small>Actor and before/after access are recorded.</small></span></div>
      </aside>

      <section v-if="selectedRole" class="rbac-editor" aria-labelledby="rbac-selected-title">
        <header class="rbac-editor-head">
          <div class="rbac-editor-title"><span class="rbac-role-avatar large">{{ initials(selectedRole.name) }}</span><div><span class="rbac-overline">SELECTED ROLE</span><h2 id="rbac-selected-title">{{ selectedRole.name }}</h2><p>{{ roleDescriptions[selectedRole.name] || 'Organization role access' }}</p></div></div>
          <span :class="['rbac-protection', { 'is-dirty': dirty }]"><span></span>{{ isProtected ? 'Protected system role' : dirty ? 'Unsaved changes' : 'Access configuration' }}</span>
        </header>
        <div class="rbac-summary" aria-live="polite"><div><b>{{ grantedCount }}</b><span>granted</span></div><i></i><div><b>{{ data.permissions.length }}</b><span>available</span></div><p v-if="isProtected">Administrator retains all registered permissions to protect system access.</p><p v-else>Changes apply to every user assigned this role.</p></div>
        <div class="rbac-toolbar"><label class="rbac-search permission-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input v-model="permissionSearch" type="search" placeholder="Search permissions" aria-label="Search permissions"></label><span>{{ groups.reduce((count, group) => count + group.permissions.length, 0) }} shown</span></div>
        <div v-if="!canManage" class="rbac-info" role="note">Your account can review role access but cannot change permissions.</div>
        <div v-else-if="isProtected" class="rbac-info" role="note">Administrator access is protected. Its full permission set cannot be removed.</div>
        <div v-if="error" class="rbac-alert" role="alert">{{ error }}</div>
        <div v-if="notice" class="rbac-success" role="status">{{ notice }}</div>
        <div class="rbac-groups">
          <section v-for="group in groups" :key="group.title" class="rbac-group">
            <header class="rbac-group-head"><div><h3>{{ group.title }}</h3><span>{{ groupState(group).count }} of {{ group.permissions.length }} granted</span></div><div class="rbac-group-actions"><button type="button" :disabled="isProtected || !canManage || groupState(group).all || groupContainsRestricted(group)" @click="setGroup(group, true)">Select all</button><button type="button" :disabled="isProtected || !canManage || groupState(group).count === 0 || groupHasGrantedRestricted(group)" @click="setGroup(group, false)">Clear</button></div></header>
            <div class="rbac-permission-list">
              <label v-for="permission in group.permissions" :key="permission.id" class="rbac-permission-row" :class="{ 'is-disabled': isLocked(permission) }">
                <input type="checkbox" :checked="selectedNames.includes(permission.name)" :disabled="isLocked(permission)" @change="setPermission(permission.name, $event.target.checked)">
                <span><b>{{ friendly(permission.name) }}</b><small>{{ permission.name }}</small></span>
                <span v-if="isLocked(permission) && !isProtected" class="rbac-state">Administrator only</span>
                <span v-else-if="groupState(group).partial && !selectedNames.includes(permission.name)" class="rbac-state">Not granted</span>
                <span v-else-if="selectedNames.includes(permission.name)" class="rbac-granted">Granted</span>
              </label>
            </div>
          </section>
          <div v-if="!groups.length" class="rbac-empty">No permissions match “{{ permissionSearch }}”. Try another term.</div>
        </div>
        <footer class="rbac-action-bar"><span class="rbac-action-status" role="status">{{ dirty ? 'Unsaved changes for ' + selectedRole.name : (saving ? 'Saving permissions…' : 'No unsaved changes') }}</span><div><button type="button" class="rbac-discard" :disabled="!dirty || saving" @click="discard">Discard changes</button><button type="button" class="rbac-save" :disabled="!dirty || saving || !canManage || isProtected" @click="save">{{ saving ? 'Saving…' : 'Save changes' }}</button></div></footer>
      </section>
      <div v-else class="rbac-editor rbac-empty">Select a role to review its permissions.</div>
    </div>
  </div>
</template>
