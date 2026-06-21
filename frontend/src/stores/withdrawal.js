import { defineStore } from 'pinia'
import { get, post, put, del } from '../utils/request'

export const useWithdrawalStore = defineStore('withdrawal', {
  state: () => ({
    rules: [],
    applications: [],
    reviews: [],
    records: []
  }),

  getters: {
    pendingCount: (state) => state.applications.filter((a) => a.status === 'pending').length,
    reviewingCount: (state) => state.applications.filter((a) => a.status === 'reviewing').length,
    activeRules: (state) => state.rules.filter((r) => r.status === 'active')
  },

  actions: {
    async fetchRules() {
      const res = await get('/withdrawal/rules/')
      this.rules = res.data || res
    },

    async fetchApplications(params) {
      const res = await get('/withdrawal/applications/', params)
      this.applications = res.data || res
    },

    async fetchReviews(params) {
      const res = await get('/withdrawal/applications/', params)
      this.reviews = res.data || res
    },

    async fetchRecords(params) {
      const res = await get('/withdrawal/records/', params)
      this.records = res.data || res
    },

    async createRule(data) {
      return await post('/withdrawal/rules/', data)
    },

    async updateRule(id, data) {
      return await put(`/withdrawal/rules/${id}`, data)
    },

    async deleteRule(id) {
      return await del(`/withdrawal/rules/${id}`)
    },

    async createApplication(data) {
      return await post('/withdrawal/applications/', data)
    },

    async cancelApplication(id) {
      return await put(`/withdrawal/applications/${id}/cancel`)
    },

    async approveApplication(id, data) {
      return await put(`/withdrawal/applications/${id}/approve`, data)
    },

    async rejectApplication(id, data) {
      return await put(`/withdrawal/applications/${id}/reject`, data)
    },

    async updateRecord(id, data) {
      return await put(`/withdrawal/records/${id}`, data)
    }
  }
})
