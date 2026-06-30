import { ref, computed, watch } from 'vue';
import { NODE_STYLES } from '@/stores/mindmap';

/**
 * Mind Map Layout Composable
 * Provides different layout algorithms for positioning nodes
 */
export function useMindMapLayout(nodes, expandedNodes, options = {}) {
  const layoutType = ref(options.layout || 'radial');
  const centerX = ref(options.centerX || 0);
  const centerY = ref(options.centerY || 0);
  
  // Layout configuration
  const config = ref({
    radial: {
      levelSpacing: 180,      // Distance between levels
      minAngleSpan: 0.3,      // Minimum angle span for a branch (radians)
      startAngle: -Math.PI / 2, // Start from top
    },
    tree: {
      levelSpacing: 150,      // Vertical spacing between levels
      siblingSpacing: 40,     // Horizontal spacing between siblings
      subtreeSpacing: 60,     // Extra spacing between subtrees
    },
    treeHorizontal: {
      levelSpacing: 200,      // Horizontal spacing between levels
      siblingSpacing: 80,     // Vertical spacing between siblings
    },
    force: {
      linkDistance: 150,
      chargeStrength: -300,
      iterations: 100,
    }
  });

  /**
   * Calculate positions for all nodes based on layout type
   */
  function calculateLayout(nodeList, layout = layoutType.value) {
    if (!nodeList?.length) return [];
    
    const positioned = JSON.parse(JSON.stringify(nodeList));
    
    switch (layout) {
      case 'radial':
        calculateRadialLayout(positioned);
        break;
      case 'tree':
        calculateTreeLayout(positioned, false);
        break;
      case 'tree-horizontal':
        calculateTreeLayout(positioned, true);
        break;
      case 'force':
        calculateForceLayout(positioned);
        break;
      default:
        calculateRadialLayout(positioned);
    }
    
    return positioned;
  }

  /**
   * Radial Layout - Root at center, children radiate outward
   */
  function calculateRadialLayout(nodes) {
    const root = nodes.find(n => !n.parentId && !n.isLinked);
    if (!root) return;
    
    // Position root at center
    root.x = centerX.value;
    root.y = centerY.value;
    
    // Build tree structure for layout
    const nodeMap = new Map(nodes.map(n => [n.id, n]));
    const childrenMap = new Map();
    
    nodes.forEach(n => {
      if (n.parentId && !n.isLinked) {
        const children = childrenMap.get(n.parentId) || [];
        children.push(n);
        childrenMap.set(n.parentId, children);
      }
    });
    
    // Count visible descendants for angle distribution
    function countDescendants(nodeId) {
      const children = childrenMap.get(nodeId) || [];
      const visibleChildren = children.filter(c => 
        expandedNodes.value.has(nodeId) || c.level <= 1
      );
      
      if (visibleChildren.length === 0) return 1;
      
      return visibleChildren.reduce((sum, child) => {
        return sum + countDescendants(child.id);
      }, 0);
    }
    
    // Position children recursively
    function positionChildren(parentId, startAngle, endAngle, level) {
      const children = childrenMap.get(parentId) || [];
      if (!children.length) return;
      
      const parent = nodeMap.get(parentId);
      if (!parent) return;
      
      // Check if parent is expanded (or if we're at first level)
      if (level > 1 && !expandedNodes.value.has(parentId)) return;
      
      const totalDescendants = children.reduce((sum, c) => sum + countDescendants(c.id), 0);
      const angleSpan = endAngle - startAngle;
      const radius = config.value.radial.levelSpacing * level;
      
      let currentAngle = startAngle;
      
      children.forEach(child => {
        const childDescendants = countDescendants(child.id);
        const childAngleSpan = (childDescendants / totalDescendants) * angleSpan;
        const childAngle = currentAngle + childAngleSpan / 2;
        
        child.x = parent.x + Math.cos(childAngle) * radius;
        child.y = parent.y + Math.sin(childAngle) * radius;
        
        // Position this child's children
        positionChildren(
          child.id,
          currentAngle,
          currentAngle + childAngleSpan,
          level + 1
        );
        
        currentAngle += childAngleSpan;
      });
    }
    
    // Start positioning from root
    positionChildren(root.id, 0, Math.PI * 2, 1);
    
    // Position linked nodes around their source
    nodes.filter(n => n.isLinked).forEach((linked, i) => {
      const source = nodes.find(n => 
        n.linkedTo?.some(l => l.id === linked.id)
      );
      if (source) {
        const angle = (Math.PI * 2 / 8) * i + Math.PI / 4;
        const distance = 100;
        linked.x = source.x + Math.cos(angle) * distance;
        linked.y = source.y + Math.sin(angle) * distance;
      }
    });
  }

  /**
   * Tree Layout - Traditional hierarchical tree
   */
  function calculateTreeLayout(nodes, horizontal = false) {
    const root = nodes.find(n => !n.parentId && !n.isLinked);
    if (!root) return;
    
    const nodeMap = new Map(nodes.map(n => [n.id, n]));
    const childrenMap = new Map();
    
    nodes.forEach(n => {
      if (n.parentId && !n.isLinked) {
        const children = childrenMap.get(n.parentId) || [];
        children.push(n);
        childrenMap.set(n.parentId, children);
      }
    });
    
    const cfg = horizontal ? config.value.treeHorizontal : config.value.tree;
    
    // Calculate subtree widths
    function getSubtreeSize(nodeId) {
      const children = childrenMap.get(nodeId) || [];
      if (!expandedNodes.value.has(nodeId) || !children.length) {
        const node = nodeMap.get(nodeId);
        const style = NODE_STYLES[node?.type] || NODE_STYLES.email;
        return style.size;
      }
      
      const childSizes = children.map(c => getSubtreeSize(c.id));
      return childSizes.reduce((sum, size) => sum + size + cfg.siblingSpacing, -cfg.siblingSpacing);
    }
    
    // Position nodes
    function positionNode(nodeId, x, y, level) {
      const node = nodeMap.get(nodeId);
      if (!node) return;
      
      if (horizontal) {
        node.x = x;
        node.y = y;
      } else {
        node.x = x;
        node.y = y;
      }
      
      const children = childrenMap.get(nodeId) || [];
      if (!expandedNodes.value.has(nodeId) || !children.length) return;
      
      const totalWidth = getSubtreeSize(nodeId);
      let currentPos = horizontal ? y - totalWidth / 2 : x - totalWidth / 2;
      
      children.forEach(child => {
        const childSize = getSubtreeSize(child.id);
        const childPos = currentPos + childSize / 2;
        
        if (horizontal) {
          positionNode(child.id, x + cfg.levelSpacing, childPos, level + 1);
        } else {
          positionNode(child.id, childPos, y + cfg.levelSpacing, level + 1);
        }
        
        currentPos += childSize + cfg.siblingSpacing;
      });
    }
    
    // Start from root
    positionNode(root.id, centerX.value, centerY.value, 0);
    
    // Position linked nodes
    nodes.filter(n => n.isLinked).forEach((linked, i) => {
      const source = nodes.find(n => 
        n.linkedTo?.some(l => l.id === linked.id)
      );
      if (source) {
        linked.x = source.x + 120;
        linked.y = source.y + (i * 60);
      }
    });
  }

  /**
   * Force-Directed Layout - Physics simulation
   */
  function calculateForceLayout(nodes) {
    const cfg = config.value.force;
    
    // Initialize positions if not set
    nodes.forEach((node, i) => {
      if (!node.x && !node.y) {
        const angle = (Math.PI * 2 / nodes.length) * i;
        const radius = 200;
        node.x = centerX.value + Math.cos(angle) * radius;
        node.y = centerY.value + Math.sin(angle) * radius;
      }
      node.vx = 0;
      node.vy = 0;
    });
    
    // Build link map
    const links = [];
    nodes.forEach(node => {
      if (node.parentId) {
        const parent = nodes.find(n => n.id === node.parentId);
        if (parent) {
          links.push({ source: parent, target: node });
        }
      }
    });
    
    // Run simulation
    for (let i = 0; i < cfg.iterations; i++) {
      const alpha = 1 - i / cfg.iterations;
      
      // Apply repulsion between all nodes
      for (let j = 0; j < nodes.length; j++) {
        for (let k = j + 1; k < nodes.length; k++) {
          const dx = nodes[k].x - nodes[j].x;
          const dy = nodes[k].y - nodes[j].y;
          const dist = Math.sqrt(dx * dx + dy * dy) || 1;
          const force = (cfg.chargeStrength * alpha) / (dist * dist);
          
          const fx = (dx / dist) * force;
          const fy = (dy / dist) * force;
          
          nodes[j].vx -= fx;
          nodes[j].vy -= fy;
          nodes[k].vx += fx;
          nodes[k].vy += fy;
        }
      }
      
      // Apply link forces
      links.forEach(link => {
        const dx = link.target.x - link.source.x;
        const dy = link.target.y - link.source.y;
        const dist = Math.sqrt(dx * dx + dy * dy) || 1;
        const force = (dist - cfg.linkDistance) * 0.1 * alpha;
        
        const fx = (dx / dist) * force;
        const fy = (dy / dist) * force;
        
        link.source.vx += fx;
        link.source.vy += fy;
        link.target.vx -= fx;
        link.target.vy -= fy;
      });
      
      // Apply center force
      nodes.forEach(node => {
        node.vx += (centerX.value - node.x) * 0.01 * alpha;
        node.vy += (centerY.value - node.y) * 0.01 * alpha;
      });
      
      // Update positions
      nodes.forEach(node => {
        node.x += node.vx;
        node.y += node.vy;
        node.vx *= 0.9; // Damping
        node.vy *= 0.9;
      });
    }
    
    // Clean up velocity properties
    nodes.forEach(node => {
      delete node.vx;
      delete node.vy;
    });
  }

  /**
   * Calculate bezier curve path between two nodes
   */
  function calculateConnectionPath(fromNode, toNode, layout = layoutType.value) {
    const fromStyle = NODE_STYLES[fromNode.type] || NODE_STYLES.email;
    const toStyle = NODE_STYLES[toNode.type] || NODE_STYLES.email;
    
    const x1 = fromNode.x;
    const y1 = fromNode.y;
    const x2 = toNode.x;
    const y2 = toNode.y;
    
    // Calculate control points based on layout
    let path;
    
    if (layout === 'tree-horizontal') {
      // Horizontal S-curve
      const midX = (x1 + x2) / 2;
      path = `M ${x1} ${y1} C ${midX} ${y1}, ${midX} ${y2}, ${x2} ${y2}`;
    } else if (layout === 'tree') {
      // Vertical S-curve
      const midY = (y1 + y2) / 2;
      path = `M ${x1} ${y1} C ${x1} ${midY}, ${x2} ${midY}, ${x2} ${y2}`;
    } else {
      // Radial - curved line from parent to child
      const dx = x2 - x1;
      const dy = y2 - y1;
      const dist = Math.sqrt(dx * dx + dy * dy);
      
      // Control point perpendicular to line
      const midX = (x1 + x2) / 2;
      const midY = (y1 + y2) / 2;
      const cpOffset = dist * 0.2;
      
      // Simple curved line
      path = `M ${x1} ${y1} Q ${midX} ${midY - cpOffset * 0.5}, ${x2} ${y2}`;
    }
    
    return path;
  }

  /**
   * Calculate all connection paths
   */
  function calculateConnections(connections, nodeMap, layout = layoutType.value) {
    return connections.map(conn => {
      const fromNode = nodeMap.get(conn.from);
      const toNode = nodeMap.get(conn.to);
      
      if (!fromNode || !toNode) return null;
      
      return {
        ...conn,
        path: calculateConnectionPath(fromNode, toNode, layout),
        isLink: conn.type === 'link',
      };
    }).filter(Boolean);
  }

  return {
    layoutType,
    centerX,
    centerY,
    config,
    calculateLayout,
    calculateConnectionPath,
    calculateConnections,
    calculateRadialLayout,
    calculateTreeLayout,
    calculateForceLayout,
  };
}

