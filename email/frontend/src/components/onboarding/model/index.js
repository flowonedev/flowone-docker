import { beginnerModel } from './beginner'
import { intermediateModel } from './intermediate'
import { advancedModel } from './advanced'

export { beginnerModel, intermediateModel, advancedModel }

const models = {
  beginner: beginnerModel,
  intermediate: intermediateModel,
  advanced: advancedModel,
}

export function getModel(tierId) {
  return models[tierId] || null
}

export function getStepKey(model, step) {
  if (!model || step < 1 || step > model.totalSteps) return null
  return model.stepKeys[step - 1]
}

export function validateModel(model) {
  const errors = []
  const nodeIds = new Set(model.nodes.map(n => n.id))

  for (const node of model.nodes) {
    if (!model.positions[node.id]) {
      errors.push(`Node "${node.id}": missing position`)
    }
    if (node.step < 1 || node.step > model.totalSteps) {
      errors.push(`Node "${node.id}": step ${node.step} out of range [1, ${model.totalSteps}]`)
    }
  }

  for (const edge of model.edges) {
    if (!nodeIds.has(edge.from)) errors.push(`Edge: unknown source node "${edge.from}"`)
    if (!nodeIds.has(edge.to)) errors.push(`Edge: unknown target node "${edge.to}"`)
    if (!model.branches[edge.branch]) errors.push(`Edge ${edge.from}->${edge.to}: unknown branch "${edge.branch}"`)
  }

  const connectedNodes = new Set()
  for (const edge of model.edges) {
    connectedNodes.add(edge.from)
    connectedNodes.add(edge.to)
  }
  for (const node of model.nodes) {
    if (!connectedNodes.has(node.id)) errors.push(`Node "${node.id}": orphan (no edges)`)
  }

  if (model.stepKeys.length !== model.totalSteps) {
    errors.push(`stepKeys length (${model.stepKeys.length}) !== totalSteps (${model.totalSteps})`)
  }

  return errors
}
