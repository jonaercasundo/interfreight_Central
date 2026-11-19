export function renderSafeAssistantMarkdown(value, authorizedLinks = []) {
  const escapeHtml = (text) => text.replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character])
  let source = escapeHtml(String(value || ''))
  const safeLinks = []

  source = source.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_, label, href) => {
    const authorized = authorizedLinks.find(item => item.href === href)
    if (!authorized) return label
    const token = `IFCLINKTOKEN${safeLinks.length}END`
    safeLinks.push(`<a href="${escapeHtml(authorized.href)}">${label}</a>`)
    return token
  })
  source = source.replace(/(?<![A-Za-z0-9])\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*(?![A-Za-z0-9_/-])/g, (path) => {
    const authorized = authorizedLinks.find(item => item.href === path)
    return authorized ? `<a href="${escapeHtml(authorized.href)}">${escapeHtml(authorized.label)}</a>` : 'the relevant IFC page'
  })
  source = source.replace(/\b[a-z][a-z0-9_-]*\.(?:create|view|update|delete|approve|issue|close|manage)\b/gi, 'the required access')
  source = source.replace(/IFCLINKTOKEN(\d+)END/g, (_, index) => safeLinks[Number(index)])
  source = source.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/`([^`]+)`/g, '<code>$1</code>')

  return source.split(/\n{2,}/).map(block => {
    const lines = block.split('\n')
    if (lines.every(line => /^\s*[-*]\s+/.test(line))) return `<ul>${lines.map(line => `<li>${line.replace(/^\s*[-*]\s+/, '')}</li>`).join('')}</ul>`
    if (lines.every(line => /^\s*\d+\.\s+/.test(line))) return `<ol>${lines.map(line => `<li>${line.replace(/^\s*\d+\.\s+/, '')}</li>`).join('')}</ol>`
    return `<p>${lines.join('<br>')}</p>`
  }).join('')
}
