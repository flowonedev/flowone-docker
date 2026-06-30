import { ref } from 'vue'

export function useSubtaskLinkedCards(hubStore, boardsStore, colleaguesStore) {
  const linkedCardMap = ref({})
  const linkedCardStatusMap = ref({})

  function getAssigneeRows(subtask) {
    return (hubStore.cardAssignees?.[subtask.id] || []).filter(a => (a.role || 'assignee') === 'assignee')
  }

  function getRawCompletionMeta(subtask, assigneeRows = getAssigneeRows(subtask)) {
    if (assigneeRows.length > 0) {
      const done = assigneeRows.filter(a => a.status === 'done').length
      return { done, total: assigneeRows.length, isAssigneeDriven: true, isComplete: done === assigneeRows.length }
    }
    return { done: subtask.completed ? 1 : 0, total: 0, isAssigneeDriven: false, isComplete: !!subtask.completed }
  }

  function getLinkedCard(subtask) {
    return linkedCardMap.value?.[subtask.id] || null
  }

  function hasLinkedCard(subtask) {
    return !!getLinkedCard(subtask)
  }

  function getLinkedCardStatus(subtask) {
    return linkedCardStatusMap.value?.[subtask.id] || null
  }

  function getDisplayAssigneeRows(subtask) {
    return getLinkedCardStatus(subtask)?.assignees || getAssigneeRows(subtask)
  }

  function getCompletionMeta(subtask) {
    return getLinkedCardStatus(subtask)?.completionMeta || getRawCompletionMeta(subtask)
  }

  function getDisplayName(email) {
    if (!email) return ''
    const colleague = (colleaguesStore.colleagues || []).find(m => m.email === email)
    return colleague?.display_name || email.split('@')[0]
  }

  function getAssigneeSummary(subtask) {
    const assignees = getDisplayAssigneeRows(subtask)
    if (!assignees.length) return { done: '', pending: '' }

    const done = assignees.filter(a => a.status === 'done').map(a => getDisplayName(a.user_email)).join(', ')
    const pending = assignees.filter(a => a.status !== 'done').map(a => getDisplayName(a.user_email)).join(', ')
    return { done, pending }
  }

  function getLinkedProgressLabel(subtask) {
    const status = getLinkedCardStatus(subtask)
    if (!status) return ''

    const meta = status.completionMeta
    if (meta.total > 0) return `${meta.done}/${meta.total} subtasks done`
    if (meta.isAssigneeDriven) return `${meta.done}/${meta.total} assignees done`
    return meta.isComplete ? 'Linked card complete' : 'Linked card in progress'
  }

  function getSubtaskProgressPercent(subtask) {
    const meta = getCompletionMeta(subtask)
    if (meta.total > 0) return Math.round((meta.done / meta.total) * 100)
    return meta.isComplete ? 100 : 0
  }

  async function loadSubtaskAssignees(list) {
    if (!list.length) return
    // ONE HTTP call instead of N parallel ones; server runs a single
    // IN-clause query and returns a card_id => assignees[] map which
    // the store unpacks into hubStore.cardAssignees.
    const ids = list.map(s => s.id).filter(Boolean)
    if (!ids.length) return
    await hubStore.fetchCardAssigneesBatch(ids)
  }

  async function syncLinkedSubtaskCompletion(subtask, completionMeta) {
    const desiredCompleted = !!completionMeta?.isComplete
    if (!!subtask.completed === desiredCompleted) return
    try {
      await boardsStore.updateCard(subtask.id, { completed: desiredCompleted })
      subtask.completed = desiredCompleted
    } catch (err) {
      console.error('Failed to sync linked subtask completion:', err)
    }
  }

  async function buildLinkedCardStatus(link) {
    const linkedCardId = Number(link?.linked_card_id)
    if (!linkedCardId) return null

    const [linkedCard, linkedSubtasks, linkedAssignees] = await Promise.all([
      boardsStore.getCard(linkedCardId),
      hubStore.fetchSubtasks(linkedCardId),
      hubStore.fetchCardAssignees(linkedCardId),
    ])

    if (linkedSubtasks.length) await loadSubtaskAssignees(linkedSubtasks)

    const assigneeRows = (linkedAssignees || []).filter(a => (a.role || 'assignee') === 'assignee')

    if (linkedSubtasks.length > 0) {
      const done = linkedSubtasks.filter(child => getRawCompletionMeta(child).isComplete).length
      return {
        assignees: assigneeRows,
        completionMeta: { done, total: linkedSubtasks.length, isAssigneeDriven: false, isComplete: done === linkedSubtasks.length },
      }
    }

    if (assigneeRows.length > 0) {
      const done = assigneeRows.filter(a => a.status === 'done').length
      return {
        assignees: assigneeRows,
        completionMeta: { done, total: assigneeRows.length, isAssigneeDriven: true, isComplete: done === assigneeRows.length },
      }
    }

    return {
      assignees: [],
      completionMeta: { done: linkedCard?.completed ? 1 : 0, total: 0, isAssigneeDriven: false, isComplete: !!linkedCard?.completed },
    }
  }

  async function refreshLinkedCardStatus(subtaskId, link, subtasks) {
    const numericSubtaskId = Number(subtaskId)

    if (!link?.linked_card_id) {
      const nextStatusMap = { ...linkedCardStatusMap.value }
      delete nextStatusMap[numericSubtaskId]
      linkedCardStatusMap.value = nextStatusMap
      return null
    }

    const status = await buildLinkedCardStatus(link)
    linkedCardStatusMap.value = { ...linkedCardStatusMap.value, [numericSubtaskId]: status }

    const subtask = subtasks.find(item => item.id === numericSubtaskId)
    if (subtask && status?.completionMeta) {
      await syncLinkedSubtaskCompletion(subtask, status.completionMeta)
    }
    return status
  }

  async function loadLinkedCardStatuses(subtasks) {
    const entries = Object.entries(linkedCardMap.value || {})
    if (!entries.length) {
      linkedCardStatusMap.value = {}
      return
    }
    await Promise.allSettled(entries.map(([id, link]) => refreshLinkedCardStatus(id, link, subtasks)))
  }

  async function loadLinks(cardId) {
    linkedCardMap.value = await hubStore.fetchSubtaskCardLinks(cardId)
  }

  function resetStatus() {
    linkedCardStatusMap.value = {}
  }

  return {
    linkedCardMap, linkedCardStatusMap,
    getAssigneeRows, getRawCompletionMeta,
    getLinkedCard, hasLinkedCard, getLinkedCardStatus,
    getDisplayAssigneeRows, getCompletionMeta,
    getDisplayName, getAssigneeSummary,
    getLinkedProgressLabel, getSubtaskProgressPercent,
    loadSubtaskAssignees, syncLinkedSubtaskCompletion,
    refreshLinkedCardStatus, loadLinkedCardStatuses,
    loadLinks, resetStatus,
  }
}
