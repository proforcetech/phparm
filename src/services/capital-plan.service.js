import api from './api'

/**
 * Capital planning — asset replacement aging, scoring models, and plans.
 * Phase 9.x of docs/expansion-plan.md.
 */
export default {
  // Aging rollups
  agingPortfolio(params = {}) {
    return api.get('/capital-plan/aging/portfolio', { params }).then((res) => res.data)
  },
  agingForCompany(companyId, params = {}) {
    return api.get(`/capital-plan/aging/company/${companyId}`, { params }).then((res) => res.data)
  },
  agingForDivision(divisionId, params = {}) {
    return api
      .get(`/capital-plan/aging/division/${divisionId}`, { params })
      .then((res) => res.data)
  },

  // Scoring models
  listScoringModels(params = {}) {
    return api.get('/capital-plan/scoring-models', { params }).then((res) => res.data)
  },
  getScoringModel(id) {
    return api.get(`/capital-plan/scoring-models/${id}`).then((res) => res.data)
  },
  createScoringModel(payload) {
    return api.post('/capital-plan/scoring-models', payload).then((res) => res.data)
  },
  updateScoringModel(id, payload) {
    return api.put(`/capital-plan/scoring-models/${id}`, payload).then((res) => res.data)
  },
  setDefaultScoringModel(id) {
    return api.post(`/capital-plan/scoring-models/${id}/default`).then((res) => res.data)
  },
  deleteScoringModel(id) {
    return api.delete(`/capital-plan/scoring-models/${id}`).then((res) => res.data)
  },

  // Plans + scenarios
  listPlans(params = {}) {
    return api.get('/capital-plan/plans', { params }).then((res) => res.data)
  },
  getPlan(id) {
    return api.get(`/capital-plan/plans/${id}`).then((res) => res.data)
  },
  createPlan(payload) {
    return api.post('/capital-plan/plans', payload).then((res) => res.data)
  },
  updatePlan(id, payload) {
    return api.put(`/capital-plan/plans/${id}`, payload).then((res) => res.data)
  },
  deletePlan(id) {
    return api.delete(`/capital-plan/plans/${id}`).then((res) => res.data)
  },
  createScenario(planId, payload) {
    return api.post(`/capital-plan/plans/${planId}/scenarios`, payload).then((res) => res.data)
  },
  updateScenario(scenarioId, payload) {
    return api.put(`/capital-plan/scenarios/${scenarioId}`, payload).then((res) => res.data)
  },
  deleteScenario(scenarioId) {
    return api.delete(`/capital-plan/scenarios/${scenarioId}`).then((res) => res.data)
  },

  // UIG-11 — per-scenario overrides (a.k.a. "line items" in the React UI).
  // Backend keys overrides by (scenario_id, site_asset_id) and treats POST
  // as an upsert, so editing a row is "POST again with the same site_asset_id
  // and the new field values" — the frontend just re-sends the full row.
  listOverrides(scenarioId) {
    return api.get(`/capital-plan/scenarios/${scenarioId}/overrides`).then((res) => res.data)
  },
  setOverride(scenarioId, payload) {
    return api.post(`/capital-plan/scenarios/${scenarioId}/overrides`, payload).then((res) => res.data)
  },
  removeOverride(overrideId) {
    return api.delete(`/capital-plan/overrides/${overrideId}`).then((res) => res.data)
  },
}
