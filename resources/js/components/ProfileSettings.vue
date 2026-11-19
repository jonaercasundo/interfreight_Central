<script setup>
import { computed, reactive, ref } from 'vue'

const props = defineProps({ data: { type: Object, required: true } })
const emit = defineEmits(['profile-updated'])
const user = ref({ ...props.data.user })
const profileForm = reactive({ name: user.value.name, email: user.value.email })
const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' })
const profileErrors = ref({})
const passwordErrors = ref({})
const profileBusy = ref(false)
const passwordBusy = ref(false)
const profileNotice = ref(props.data.flash_status || '')
const passwordNotice = ref('')
const profileError = ref('')
const passwordError = ref('')
const visibility = reactive({ current_password: false, password: false, password_confirmation: false })
const profileChanged = computed(() => profileForm.name.trim() !== user.value.name || profileForm.email.trim() !== user.value.email)
const initials = computed(() => user.value.name.split(/\s+/).filter(Boolean).map(part => part[0]).slice(0, 2).join('').toUpperCase() || 'U')

function formatDate(value) {
  if (!value) return null
  return new Date(value).toLocaleDateString('en-SG', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Manila' })
}

function clearFormErrors(errors) {
  return Object.fromEntries(Object.entries(errors || {}).map(([key, messages]) => [key, Array.isArray(messages) ? messages[0] : String(messages)]))
}

async function submitProfile() {
  if (profileBusy.value || !profileChanged.value) return
  profileBusy.value = true
  profileErrors.value = {}
  profileError.value = ''
  profileNotice.value = ''
  try {
    const response = await fetch('/profile', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
      credentials: 'same-origin',
      body: JSON.stringify({ name: profileForm.name.trim(), email: profileForm.email.trim() }),
    })
    const payload = await response.json()
    if (response.status === 422) {
      profileErrors.value = clearFormErrors(payload.errors)
      return
    }
    if (response.status === 403) throw new Error('Your account cannot update profile information.')
    if (!response.ok) throw new Error('Your profile could not be saved. Please try again.')
    user.value = { ...user.value, ...payload.user }
    emit('profile-updated', payload.user)
    profileForm.name = user.value.name
    profileForm.email = user.value.email
    profileNotice.value = payload.status || 'Profile updated.'
  } catch (exception) {
    profileError.value = exception instanceof TypeError ? 'IFC could not be reached. Check your connection and try again.' : exception.message
  } finally {
    profileBusy.value = false
  }
}

async function submitPassword() {
  if (passwordBusy.value) return
  passwordBusy.value = true
  passwordErrors.value = {}
  passwordError.value = ''
  passwordNotice.value = ''
  try {
    const response = await fetch('/profile/password', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
      credentials: 'same-origin',
      body: JSON.stringify({ ...passwordForm }),
    })
    const payload = await response.json()
    if (response.status === 422) {
      passwordErrors.value = clearFormErrors(payload.errors)
      return
    }
    if (response.status === 403) throw new Error('Your account cannot update its password.')
    if (!response.ok) throw new Error('Your password could not be updated. Please try again.')
    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
    passwordNotice.value = payload.status || 'Password updated.'
  } catch (exception) {
    passwordError.value = exception instanceof TypeError ? 'IFC could not be reached. Check your connection and try again.' : exception.message
  } finally {
    passwordBusy.value = false
  }
}
</script>

<template>
  <div class="profile-page">
    <header class="profile-heading">
      <div>
        <div class="profile-kicker">ACCOUNT / PERSONAL SETTINGS</div>
        <h1>My Profile</h1>
        <p>Manage your personal information and account security.</p>
      </div>
    </header>

    <div class="profile-layout">
      <aside class="profile-overview">
        <div class="profile-overview-top"><span class="profile-avatar-large">{{ initials }}</span><span class="profile-account-state" :class="user.is_active ? 'is-active' : 'is-inactive'"><i></i>{{ user.is_active ? 'Active account' : 'Inactive account' }}</span></div>
        <h2>{{ user.name }}</h2>
        <p class="profile-overview-email">{{ user.email }}</p>
        <div class="profile-role-block"><span class="profile-label">ASSIGNED ROLE{{ user.roles.length === 1 ? '' : 'S' }}</span><div class="profile-role-list"><span v-for="role in user.roles" :key="role" class="profile-role-badge">{{ role }}</span><span v-if="!user.roles.length" class="profile-no-role">No role assigned</span></div></div>
        <div class="profile-overview-meta">
          <div><span class="profile-meta-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg></span><span><small>Member since</small><b>{{ formatDate(user.created_at) || 'Not recorded' }}</b></span></div>
          <div><span class="profile-meta-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="m4 7 8 6 8-6"/></svg></span><span><small>Email verification</small><b>{{ user.email_verified_at ? `Verified ${formatDate(user.email_verified_at)}` : 'Not verified' }}</b></span></div>
          <div><span class="profile-meta-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><span><small>Last successful login</small><b>{{ formatDate(user.last_login_at) || 'No login recorded' }}</b></span></div>
          <div><span class="profile-meta-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M12 14v3"/></svg></span><span><small>Password last changed</small><b>{{ formatDate(user.password_last_changed_at) || 'No change recorded' }}</b></span></div>
        </div>
        <p class="profile-role-note"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></svg>Role assignments are managed by an IFC administrator.</p>
      </aside>

      <div class="profile-settings-column">
        <section class="profile-card">
          <div class="profile-card-heading"><span class="profile-card-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/></svg></span><div><h2>Profile Information</h2><p>Update the name and work email associated with your account.</p></div></div>
          <div v-if="profileNotice" class="profile-message is-success" role="status">{{ profileNotice }}</div>
          <div v-if="profileError" class="profile-message is-error" role="alert">{{ profileError }}</div>
          <form class="profile-form" @submit.prevent="submitProfile" novalidate>
            <label class="profile-field"><span>Full Name</span><input v-model="profileForm.name" name="name" autocomplete="name" maxlength="120" :aria-invalid="!!profileErrors.name" :aria-describedby="profileErrors.name ? 'profile-name-error' : undefined"><small v-if="profileErrors.name" id="profile-name-error" class="profile-field-error">{{ profileErrors.name }}</small></label>
            <label class="profile-field"><span>Email Address</span><input v-model="profileForm.email" name="email" type="email" autocomplete="email" maxlength="254" :aria-invalid="!!profileErrors.email" :aria-describedby="profileErrors.email ? 'profile-email-error' : undefined"><small v-if="profileErrors.email" id="profile-email-error" class="profile-field-error">{{ profileErrors.email }}</small></label>
            <div class="profile-form-actions"><p v-if="profileError" class="profile-inline-error">Review the message above and try again.</p><span v-else class="profile-save-hint">Changes apply to your IFC account.</span><button class="profile-primary" type="submit" :disabled="profileBusy || !profileChanged">{{ profileBusy ? 'Saving…' : 'Save Changes' }}</button></div>
          </form>
        </section>

        <section class="profile-card">
          <div class="profile-card-heading"><span class="profile-card-icon security"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M12 14v3"/></svg></span><div><h2>Account Security</h2><p>Choose a strong password that you do not use elsewhere.</p></div></div>
          <div v-if="passwordNotice" class="profile-message is-success" role="status">{{ passwordNotice }}</div>
          <div v-if="passwordError" class="profile-message is-error" role="alert">{{ passwordError }}</div>
          <form class="profile-form" @submit.prevent="submitPassword" novalidate>
            <div v-for="field in [{ key: 'current_password', label: 'Current Password', autocomplete: 'current-password' }, { key: 'password', label: 'New Password', autocomplete: 'new-password' }, { key: 'password_confirmation', label: 'Confirm New Password', autocomplete: 'new-password' }]" :key="field.key" class="profile-field"><label :for="`profile-${field.key}`">{{ field.label }}</label><div class="profile-password-wrap"><input :id="`profile-${field.key}`" v-model="passwordForm[field.key]" :name="field.key" :type="visibility[field.key] ? 'text' : 'password'" :autocomplete="field.autocomplete" :minlength="field.key === 'current_password' ? undefined : 12" :aria-invalid="!!passwordErrors[field.key]" :aria-describedby="passwordErrors[field.key] ? `${field.key}-error` : field.key === 'password' ? 'password-requirements' : undefined"><button type="button" class="profile-password-toggle" :aria-label="`${visibility[field.key] ? 'Hide' : 'Show'} ${field.label.toLowerCase()}`" :aria-pressed="visibility[field.key]" @click="visibility[field.key] = !visibility[field.key]">{{ visibility[field.key] ? 'Hide' : 'Show' }}</button></div><small v-if="passwordErrors[field.key]" :id="`${field.key}-error`" class="profile-field-error">{{ passwordErrors[field.key] }}</small></div>
            <p id="password-requirements" class="profile-password-help">Use at least 12 characters. Your current password is required to confirm this change.</p>
            <div class="profile-form-actions"><span class="profile-save-hint">Updating your password signs out remembered sessions.</span><button class="profile-primary" type="submit" :disabled="passwordBusy || !passwordForm.current_password || !passwordForm.password || !passwordForm.password_confirmation">{{ passwordBusy ? 'Updating…' : 'Update Password' }}</button></div>
          </form>
        </section>
      </div>
    </div>
  </div>
</template>
