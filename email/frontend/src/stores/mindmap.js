import { defineStore } from "pinia";
import { ref, computed } from "vue";
import api from "@/services/api";

// Mind Map Modes
export const MINDMAP_MODES = {
  CLIENT_EMAILS: {
    id: 'client-emails',
    label: 'Client Emails',
    icon: 'person',
    rootType: 'client',
    layout: 'radial',
    maxDepth: 3,
    showLinked: ['calendar', 'board', 'task'],
  },
  CONVERSATION_THREAD: {
    id: 'conversation-thread',
    label: 'Conversation Thread',
    icon: 'forum',
    rootType: 'email',
    layout: 'tree-horizontal',
    maxDepth: null,
    showLinked: ['calendar'],
  },
  TOPIC_CLUSTER: {
    id: 'topic-cluster',
    label: 'Topic Clusters',
    icon: 'hub',
    rootType: 'topic',
    layout: 'force',
    maxDepth: 2,
    showLinked: [],
  },
  RELATIONSHIP_GRAPH: {
    id: 'relationship-graph',
    label: 'Relationships',
    icon: 'account_tree',
    rootType: 'selected',
    layout: 'force',
    maxDepth: null,
    showLinked: ['calendar', 'board', 'task', 'client'],
  }
};

// Node type styling configuration
export const NODE_STYLES = {
  client: {
    icon: 'person',
    bgClass: 'bg-primary-100 dark:bg-primary-900/40',
    borderClass: 'border-primary-400 dark:border-primary-600',
    textClass: 'text-primary-700 dark:text-primary-300',
    size: 120,
  },
  conversation: {
    icon: 'forum',
    bgClass: 'bg-blue-50 dark:bg-blue-900/30',
    borderClass: 'border-blue-300 dark:border-blue-600',
    textClass: 'text-blue-700 dark:text-blue-300',
    size: 100,
  },
  email: {
    icon: 'mail',
    bgClass: 'bg-surface-100 dark:bg-surface-700',
    borderClass: 'border-surface-300 dark:border-surface-600',
    textClass: 'text-surface-700 dark:text-surface-300',
    size: 80,
  },
  calendar: {
    icon: 'event',
    bgClass: 'bg-green-50 dark:bg-green-900/30',
    borderClass: 'border-green-400 dark:border-green-600',
    textClass: 'text-green-700 dark:text-green-300',
    size: 70,
  },
  'calendar-group': {
    icon: 'calendar_month',
    bgClass: 'bg-green-50 dark:bg-green-900/30',
    borderClass: 'border-green-400 dark:border-green-600',
    textClass: 'text-green-700 dark:text-green-300',
    size: 80,
  },
  board: {
    icon: 'dashboard',
    bgClass: 'bg-purple-50 dark:bg-purple-900/30',
    borderClass: 'border-purple-400 dark:border-purple-600',
    textClass: 'text-purple-700 dark:text-purple-300',
    size: 70,
  },
  task: {
    icon: 'task_alt',
    bgClass: 'bg-amber-50 dark:bg-amber-900/30',
    borderClass: 'border-amber-400 dark:border-amber-600',
    textClass: 'text-amber-700 dark:text-amber-300',
    size: 60,
  },
  topic: {
    icon: 'label',
    bgClass: 'bg-rose-50 dark:bg-rose-900/30',
    borderClass: 'border-rose-400 dark:border-rose-600',
    textClass: 'text-rose-700 dark:text-rose-300',
    size: 100,
  },
  drive: {
    icon: 'folder',
    bgClass: 'bg-cyan-50 dark:bg-cyan-900/30',
    borderClass: 'border-cyan-400 dark:border-cyan-600',
    textClass: 'text-cyan-700 dark:text-cyan-300',
    size: 70,
  },
  milestone: {
    icon: 'flag',
    bgClass: 'bg-rose-50 dark:bg-rose-900/30',
    borderClass: 'border-rose-400 dark:border-rose-600',
    textClass: 'text-rose-700 dark:text-rose-300',
    size: 70,
  },
  list: {
    icon: 'view_list',
    bgClass: 'bg-indigo-50 dark:bg-indigo-900/30',
    borderClass: 'border-indigo-400 dark:border-indigo-600',
    textClass: 'text-indigo-700 dark:text-indigo-300',
    size: 70,
  },
};

export const useMindMapStore = defineStore("mindmap", () => {
  // State
  const isOpen = ref(false);
  const loading = ref(false);
  const mode = ref(MINDMAP_MODES.CLIENT_EMAILS);
  
  // Data
  const rootNode = ref(null);
  const nodes = ref([]);
  const connections = ref([]);
  const flatNodes = ref([]); // Flat list with positions calculated
  
  // Context (what we're visualizing)
  const context = ref({
    type: null, // 'client', 'conversation', 'folder'
    id: null,
    data: null,
  });
  
  // View state
  const zoom = ref(1);
  const pan = ref({ x: 0, y: 0 });
  const selectedNode = ref(null);
  const hoveredNode = ref(null);
  const expandedNodes = ref(new Set());
  
  // Computed
  const visibleNodes = computed(() => {
    if (!flatNodes.value?.length) return [];
    
    return flatNodes.value.filter(node => {
      // Root is always visible
      if (!node.parentId) return true;
      
      // Check if all ancestors are expanded
      let current = node;
      while (current.parentId) {
        if (!expandedNodes.value || !expandedNodes.value.has(current.parentId)) {
          return false;
        }
        current = flatNodes.value.find(n => n.id === current.parentId);
        if (!current) break;
      }
      return true;
    });
  });
  
  const visibleConnections = computed(() => {
    if (!connections.value?.length || !visibleNodes.value?.length) return [];
    
    const visibleIds = new Set(visibleNodes.value.map(n => n.id));
    return connections.value.filter(conn => 
      visibleIds.has(conn.from) && visibleIds.has(conn.to)
    );
  });

  // Actions
  function openMindMap(contextType, contextId, contextData = null, extraContext = {}) {
    context.value = {
      type: contextType,
      id: contextId,
      data: contextData,
      // Store original conversation data for mode switching back
      _originalConversationData: contextType === 'conversation' ? contextData : null,
      // Extra context for different modes
      senderEmail: extraContext.senderEmail || null,
      senderName: extraContext.senderName || null,
      subject: extraContext.subject || null,
      folder: extraContext.folder || null,
      conversationId: extraContext.conversationId || null,
    };
    
    // Auto-select mode based on context
    if (contextType === 'client') {
      mode.value = MINDMAP_MODES.CLIENT_EMAILS;
    } else if (contextType === 'conversation') {
      mode.value = MINDMAP_MODES.CONVERSATION_THREAD;
    } else if (contextType === 'topic') {
      mode.value = MINDMAP_MODES.TOPIC_CLUSTER;
    }
    
    isOpen.value = true;
    loadData();
  }
  
  function closeMindMap() {
    isOpen.value = false;
    resetView();
  }
  
  function setMode(newMode) {
    mode.value = newMode;
    // If switching to conversation mode and we have original data, restore it
    if (newMode.id === 'conversation-thread' && context.value._originalConversationData) {
      context.value.data = context.value._originalConversationData;
    } else {
      // Clear context data to force API fetch for different modes
      context.value.data = null;
    }
    loadData();
  }
  
  async function loadData() {
    if (!context.value.type || !context.value.id) return;
    
    loading.value = true;
    
    // Reset expanded nodes for new data
    expandedNodes.value = new Set();
    
    try {
      let response;
      const currentMode = mode.value;
      
      // Check which mode we're in and load appropriate data
      if (currentMode.id === 'conversation-thread' && context.value.data) {
        // Use provided conversation data directly
        rootNode.value = context.value.data;
        processNodes(context.value.data);
        return;
      }
      
      // All modes now use the comprehensive client endpoint
      const email = context.value.senderEmail || context.value.id;
      if (!email) {
        console.warn('No email available for mind map');
        return;
      }
      
      // The comprehensive endpoint returns all data - conversations, boards, drive, calendar
      response = await api.get(`/clients/by-email/${encodeURIComponent(email)}/mindmap`);
      
      if (response?.data?.success) {
        rootNode.value = response.data.data.root;
        processNodes(response.data.data.root);
      } else if (response?.data?.message) {
        console.warn('Mind map load warning:', response.data.message);
      }
    } catch (e) {
      console.error('Failed to load mind map data:', e);
      // If API fails, try to use context data as fallback
      if (context.value.data) {
        rootNode.value = context.value.data;
        processNodes(context.value.data);
      }
    } finally {
      loading.value = false;
    }
  }
  
  function processNodes(root) {
    if (!root) return;
    
    const flat = [];
    const conns = [];
    
    function traverse(node, parentId = null, level = 0) {
      const processedNode = {
        ...node,
        parentId,
        level,
        x: 0,
        y: 0,
      };
      
      flat.push(processedNode);
      
      // Only auto-expand root level (conversations stay collapsed for stacking)
      if (level < 1) {
        expandedNodes.value.add(node.id);
      }
      
      if (parentId) {
        conns.push({
          id: `${parentId}-${node.id}`,
          from: parentId,
          to: node.id,
          type: 'parent-child',
        });
      }
      
      // Process children
      if (node.children?.length) {
        node.children.forEach(child => {
          traverse(child, node.id, level + 1);
        });
      }
      
      // Process linked items (boards, calendar, drive) with their children
      if (node.linkedTo?.length) {
        node.linkedTo.forEach(linked => {
          conns.push({
            id: `${node.id}-${linked.id}-link`,
            from: node.id,
            to: linked.id,
            type: 'link',
          });
          // Recursively process linked node and its children
          if (!flat.find(n => n.id === linked.id)) {
            traverseLinked(linked, node.id, level + 1);
          }
        });
      }
    }
    
    // Traverse linked items (boards with milestones, calendar groups, etc.)
    function traverseLinked(node, parentId, level) {
      flat.push({
        ...node,
        parentId: parentId,
        level,
        x: 0,
        y: 0,
        isLinked: true,
      });
      
      // Process children of linked items (milestones, tasks, events)
      if (node.children?.length) {
        node.children.forEach(child => {
          conns.push({
            id: `${node.id}-${child.id}`,
            from: node.id,
            to: child.id,
            type: 'child',
          });
          traverseLinked(child, node.id, level + 1);
        });
      }
    }
    
    traverse(root);
    
    flatNodes.value = flat;
    connections.value = conns;
  }
  
  function toggleNodeExpanded(nodeId) {
    if (expandedNodes.value.has(nodeId)) {
      expandedNodes.value.delete(nodeId);
    } else {
      expandedNodes.value.add(nodeId);
    }
    // Trigger reactivity
    expandedNodes.value = new Set(expandedNodes.value);
  }
  
  function selectNode(node) {
    selectedNode.value = node;
  }
  
  function hoverNode(node) {
    hoveredNode.value = node;
  }
  
  function setZoom(newZoom) {
    zoom.value = Math.max(0.25, Math.min(2, newZoom));
  }
  
  function setPan(newPan) {
    pan.value = newPan;
  }
  
  function resetView() {
    zoom.value = 1;
    pan.value = { x: 0, y: 0 };
    selectedNode.value = null;
    hoveredNode.value = null;
  }
  
  function fitToView(containerWidth, containerHeight) {
    if (!visibleNodes.value.length) return;
    
    // Calculate bounds
    let minX = Infinity, maxX = -Infinity;
    let minY = Infinity, maxY = -Infinity;
    
    visibleNodes.value.forEach(node => {
      const style = NODE_STYLES[node.type] || NODE_STYLES.email;
      const halfSize = style.size / 2;
      
      minX = Math.min(minX, node.x - halfSize);
      maxX = Math.max(maxX, node.x + halfSize);
      minY = Math.min(minY, node.y - halfSize);
      maxY = Math.max(maxY, node.y + halfSize);
    });
    
    const contentWidth = maxX - minX + 100;
    const contentHeight = maxY - minY + 100;
    
    const scaleX = containerWidth / contentWidth;
    const scaleY = containerHeight / contentHeight;
    const newZoom = Math.min(scaleX, scaleY, 1.5);
    
    zoom.value = newZoom;
    pan.value = {
      x: (containerWidth / 2) - ((minX + maxX) / 2) * newZoom,
      y: (containerHeight / 2) - ((minY + maxY) / 2) * newZoom,
    };
  }
  
  function expandAll() {
    flatNodes.value.forEach(node => {
      if (node.children?.length || flatNodes.value.some(n => n.parentId === node.id)) {
        expandedNodes.value.add(node.id);
      }
    });
    expandedNodes.value = new Set(expandedNodes.value);
  }
  
  function collapseAll() {
    expandedNodes.value.clear();
    // Keep root expanded
    if (rootNode.value) {
      expandedNodes.value.add(rootNode.value.id);
    }
    expandedNodes.value = new Set(expandedNodes.value);
  }

  function $reset() {
    isOpen.value = false;
    loading.value = false;
    mode.value = MINDMAP_MODES.CLIENT_EMAILS;
    rootNode.value = null;
    nodes.value = [];
    connections.value = [];
    flatNodes.value = [];
    context.value = { type: null, id: null, data: null };
    zoom.value = 1;
    pan.value = { x: 0, y: 0 };
    selectedNode.value = null;
    hoveredNode.value = null;
    expandedNodes.value = new Set();
  }

  return {
    // State
    isOpen,
    loading,
    mode,
    rootNode,
    nodes,
    connections,
    flatNodes,
    context,
    zoom,
    pan,
    selectedNode,
    hoveredNode,
    expandedNodes,
    
    // Computed
    visibleNodes,
    visibleConnections,
    
    // Actions
    openMindMap,
    closeMindMap,
    setMode,
    loadData,
    processNodes,
    toggleNodeExpanded,
    selectNode,
    hoverNode,
    setZoom,
    setPan,
    resetView,
    fitToView,
    expandAll,
    collapseAll,
    $reset,
  };
});

