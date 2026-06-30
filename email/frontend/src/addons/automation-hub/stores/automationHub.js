import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import automationHubApi from '../services/automationHubApi'

export const useAutomationHubStore = defineStore('automationHub', () => {
  // ── Dashboard state ───────────────────────────────────────────────────
  const workflows = ref([])
  const workflowsLoading = ref(false)

  // ── Editor state ──────────────────────────────────────────────────────
  const currentWorkflow = ref(null)
  const nodes = ref(new Map())
  const edges = ref([])
  const isDirty = ref(false)

  // ── Canvas state ──────────────────────────────────────────────────────
  const zoom = ref(1)
  const panX = ref(0)
  const panY = ref(0)
  const isPanning = ref(false)
  const isDragging = ref(false)
  const showFlowAnimation = ref(localStorage.getItem('ah_flow_anim') !== 'false')

  // ── Selection ─────────────────────────────────────────────────────────
  const selectedNodeUids = ref(new Set())
  const selectedEdgeId = ref(null)

  // ── Connection drawing ────────────────────────────────────────────────
  const isConnecting = ref(false)
  const connectingFrom = ref(null) // { nodeUid, portId }
  const tempEdgeEnd = ref(null)    // { x, y } in canvas coords

  // ── Execution state ───────────────────────────────────────────────────
  const executionState = ref(new Map()) // nodeUid -> { status, input, output }
  const activeExecutionId = ref(null)

  // ── Computed ──────────────────────────────────────────────────────────
  const nodesArray = computed(() => Array.from(nodes.value.values()))
  const selectedNode = computed(() => {
    if (selectedNodeUids.value.size !== 1) return null
    const uid = [...selectedNodeUids.value][0]
    return nodes.value.get(uid) || null
  })

  // ── Workflow CRUD ─────────────────────────────────────────────────────
  async function fetchWorkflows() {
    workflowsLoading.value = true
    try {
      const res = await automationHubApi.listWorkflows()
      workflows.value = res.data?.data?.workflows || []
    } catch (e) {
      console.error('[AutomationHub] Failed to fetch workflows:', e)
    } finally {
      workflowsLoading.value = false
    }
  }

  async function loadWorkflow(id) {
    try {
      const res = await automationHubApi.getWorkflow(id)
      const data = res.data?.data
      if (!data) throw new Error('No workflow data')

      currentWorkflow.value = data.workflow
      nodes.value = new Map()
      edges.value = []

      if (data.nodes) {
        for (const n of data.nodes) {
          nodes.value.set(n.node_uid, {
            uid: n.node_uid,
            type: n.node_type,
            category: n.node_category,
            label: n.label,
            config: typeof n.config === 'string' ? JSON.parse(n.config) : (n.config || {}),
            x: parseFloat(n.position_x) || 0,
            y: parseFloat(n.position_y) || 0,
            meta: typeof n.meta === 'string' ? JSON.parse(n.meta) : (n.meta || {}),
          })
        }
      }

      if (data.edges) {
        edges.value = data.edges.map(e => ({
          id: e.id,
          sourceUid: e.source_node_uid,
          targetUid: e.target_node_uid,
          sourcePort: e.source_port || 'output',
          targetPort: e.target_port || 'input',
          style: e.edge_style || 'solid',
        }))
      }

      // Restore canvas viewport
      if (data.workflow.canvas_data) {
        const cd = typeof data.workflow.canvas_data === 'string'
          ? JSON.parse(data.workflow.canvas_data)
          : data.workflow.canvas_data
        zoom.value = cd.zoom || 1
        panX.value = cd.panX || 0
        panY.value = cd.panY || 0
      }

      isDirty.value = false
      selectedNodeUids.value = new Set()
      selectedEdgeId.value = null
    } catch (e) {
      console.error('[AutomationHub] Failed to load workflow:', e)
      throw e
    }
  }

  async function saveWorkflow() {
    if (!currentWorkflow.value) return

    const nodesArr = Array.from(nodes.value.values()).map(n => ({
      node_uid: n.uid,
      node_type: n.type,
      node_category: n.category,
      label: n.label,
      config: n.config,
      position_x: n.x,
      position_y: n.y,
      meta: n.meta,
    }))

    const edgesArr = edges.value.map(e => ({
      source_node_uid: e.sourceUid,
      target_node_uid: e.targetUid,
      source_port: e.sourcePort,
      target_port: e.targetPort,
      edge_style: e.style,
    }))

    const payload = {
      name: currentWorkflow.value.name,
      description: currentWorkflow.value.description,
      category: currentWorkflow.value.category || 'custom',
      canvas_data: { zoom: zoom.value, panX: panX.value, panY: panY.value },
      nodes: nodesArr,
      edges: edgesArr,
    }

    await automationHubApi.updateWorkflow(currentWorkflow.value.id, payload)
    isDirty.value = false
  }

  async function createWorkflow(name = 'New Workflow') {
    const res = await automationHubApi.createWorkflow({ name, description: '', category: 'custom' })
    const wf = res.data?.data?.workflow
    if (wf) {
      workflows.value.unshift(wf)
    }
    return wf
  }

  async function createWorkflowFromTemplate(template) {
    const res = await automationHubApi.createWorkflow({
      name: template.name,
      description: template.description || '',
      category: template.category?.toLowerCase().replace(/\s+/g, '_') || 'custom',
    })
    const wf = res.data?.data?.workflow
    if (!wf) return null

    const uidMap = {}
    const tplNodes = template.nodes.map((n, i) => {
      const uid = crypto.randomUUID()
      uidMap[i] = uid
      return {
        node_uid: uid,
        node_type: n.type,
        node_category: n.type.split('.')[0],
        label: null,
        config: n.config || {},
        position_x: n.x || 100 + i * 320,
        position_y: n.y || 200,
        meta: {},
      }
    })

    const tplEdges = (template.edges || []).map(e => ({
      source_node_uid: uidMap[e.from],
      target_node_uid: uidMap[e.to],
      source_port: e.fromPort || 'output',
      target_port: e.toPort || 'input',
      edge_style: 'solid',
    }))

    await automationHubApi.updateWorkflow(wf.id, {
      name: wf.name,
      description: wf.description,
      category: wf.category,
      canvas_data: { zoom: 1, panX: 0, panY: 0 },
      nodes: tplNodes,
      edges: tplEdges,
    })

    workflows.value.unshift(wf)
    return wf
  }

  async function deleteWorkflow(id) {
    await automationHubApi.deleteWorkflow(id)
    workflows.value = workflows.value.filter(w => w.id !== id)
    if (currentWorkflow.value?.id === id) {
      currentWorkflow.value = null
      nodes.value = new Map()
      edges.value = []
    }
  }

  // ── Node operations ───────────────────────────────────────────────────
  function addNode(type, category, x, y, label = null) {
    const uid = crypto.randomUUID()
    nodes.value.set(uid, {
      uid,
      type,
      category,
      label,
      config: {},
      x,
      y,
      meta: {},
    })
    isDirty.value = true
    return uid
  }

  function updateNodePosition(uid, x, y) {
    const node = nodes.value.get(uid)
    if (node) {
      node.x = x
      node.y = y
      isDirty.value = true
    }
  }

  function updateNodeConfig(uid, config) {
    const node = nodes.value.get(uid)
    if (node) {
      node.config = { ...node.config, ...config }
      isDirty.value = true
    }
  }

  function updateNodeLabel(uid, label) {
    const node = nodes.value.get(uid)
    if (node) {
      node.label = label
      isDirty.value = true
    }
  }

  function removeNode(uid) {
    nodes.value.delete(uid)
    edges.value = edges.value.filter(e => e.sourceUid !== uid && e.targetUid !== uid)
    selectedNodeUids.value.delete(uid)
    isDirty.value = true
  }

  // ── Edge operations ───────────────────────────────────────────────────
  function addEdge(sourceUid, sourcePort, targetUid, targetPort, style = 'solid') {
    const exists = edges.value.some(
      e => e.sourceUid === sourceUid && e.sourcePort === sourcePort &&
           e.targetUid === targetUid && e.targetPort === targetPort
    )
    if (exists) return null

    const edge = {
      id: crypto.randomUUID(),
      sourceUid,
      targetUid,
      sourcePort,
      targetPort,
      style,
    }
    edges.value.push(edge)
    isDirty.value = true
    return edge
  }

  function removeEdge(edgeId) {
    edges.value = edges.value.filter(e => e.id !== edgeId)
    if (selectedEdgeId.value === edgeId) selectedEdgeId.value = null
    isDirty.value = true
  }

  // ── Selection ─────────────────────────────────────────────────────────
  function selectNode(uid, additive = false) {
    if (!additive) {
      selectedNodeUids.value = new Set([uid])
      selectedEdgeId.value = null
    } else {
      selectedNodeUids.value.add(uid)
    }
  }

  function deselectAll() {
    selectedNodeUids.value = new Set()
    selectedEdgeId.value = null
  }

  function selectEdge(edgeId) {
    selectedEdgeId.value = edgeId
    selectedNodeUids.value = new Set()
  }

  // ── Connection drawing ────────────────────────────────────────────────
  function startConnecting(nodeUid, portId) {
    isConnecting.value = true
    connectingFrom.value = { nodeUid, portId }
    tempEdgeEnd.value = null
  }

  function finishConnecting(targetUid, targetPort) {
    if (!connectingFrom.value || connectingFrom.value.nodeUid === targetUid) {
      cancelConnecting()
      return
    }
    addEdge(connectingFrom.value.nodeUid, connectingFrom.value.portId, targetUid, targetPort)
    cancelConnecting()
  }

  function cancelConnecting() {
    isConnecting.value = false
    connectingFrom.value = null
    tempEdgeEnd.value = null
  }

  // ── Delete selected ───────────────────────────────────────────────────
  function deleteSelected() {
    if (selectedEdgeId.value) {
      removeEdge(selectedEdgeId.value)
    }
    if (selectedNodeUids.value.size > 0) {
      for (const uid of selectedNodeUids.value) {
        removeNode(uid)
      }
    }
  }

  // ── Flow animation toggle ───────────────────────────────────────────
  function toggleFlowAnimation() {
    showFlowAnimation.value = !showFlowAnimation.value
    localStorage.setItem('ah_flow_anim', showFlowAnimation.value ? 'true' : 'false')
  }

  // ── Reset ─────────────────────────────────────────────────────────────
  function resetEditor() {
    currentWorkflow.value = null
    nodes.value = new Map()
    edges.value = []
    zoom.value = 1
    panX.value = 0
    panY.value = 0
    selectedNodeUids.value = new Set()
    selectedEdgeId.value = null
    isDirty.value = false
    executionState.value = new Map()
    activeExecutionId.value = null
  }

  return {
    // Dashboard
    workflows, workflowsLoading, fetchWorkflows,
    // Editor
    currentWorkflow, nodes, edges, isDirty,
    nodesArray, selectedNode,
    loadWorkflow, saveWorkflow, createWorkflow, createWorkflowFromTemplate, deleteWorkflow,
    // Canvas
    zoom, panX, panY, isPanning, isDragging, showFlowAnimation, toggleFlowAnimation,
    // Selection
    selectedNodeUids, selectedEdgeId,
    selectNode, deselectAll, selectEdge, deleteSelected,
    // Nodes
    addNode, updateNodePosition, updateNodeConfig, updateNodeLabel, removeNode,
    // Edges
    addEdge, removeEdge,
    // Connection drawing
    isConnecting, connectingFrom, tempEdgeEnd,
    startConnecting, finishConnecting, cancelConnecting,
    // Execution
    executionState, activeExecutionId,
    // Reset
    resetEditor,
  }
})
