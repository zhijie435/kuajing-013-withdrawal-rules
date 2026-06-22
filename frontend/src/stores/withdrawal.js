import { defineStore } from 'pinia'
import { get, post, put, del } from '../utils/request'

export const useWithdrawalStore = defineStore('withdrawal', {
  state: () => ({
    rules: [],
    applications: [],
    reviews: [],
    records: [],
    loading: {
      rules: false,
      applications: false,
      reviews: false,
      records: false
    }
  }),

  getters: {
    pendingCount: (state) => state.applications.filter((a) => a.status === 'pending').length,
    reviewingCount: (state) => state.applications.filter((a) => a.status === 'reviewing').length,
    activeRules: (state) => state.rules.filter((r) => r.status === 'active'),
    getApplicationById: (state) => (id) => state.applications.find((a) => a.id === Number(id)),
    getRecordById: (state) => (id) => state.records.find((r) => r.id === Number(id))
  },

  actions: {
    async fetchRules() {
      this.loading.rules = true
      try {
        const res = await get('/rules')
        this.rules = res.data?.list || res.data || []
      } finally {
        this.loading.rules = false
      }
    },

    async fetchApplications(params) {
      this.loading.applications = true
      try {
        const res = await get('/applications', params)
        this.applications = res.data?.list || res.data || []
      } finally {
        this.loading.applications = false
      }
    },

    async fetchReviews(params) {
      this.loading.reviews = true
      try {
        const res = await get('/applications', params)
        this.reviews = res.data?.list || res.data || []
      } finally {
        this.loading.reviews = false
      }
    },

    async fetchRecords(params) {
      this.loading.records = true
      try {
        const res = await get('/records', params)
        this.records = res.data?.list || res.data || []
      } finally {
        this.loading.records = false
      }
    },

    async fetchApplicationDetail(id) {
      const cached = this.getApplicationById(id)
      if (cached) {
        try {
          const res = await get(`/applications/${id}`)
          const remote = res.data || res
          return { ...cached, ...remote }
        } catch (e) {
          return cached
        }
      }
      const res = await get(`/applications/${id}`)
      return res.data || res
    },

    async fetchRecordDetail(id) {
      const cached = this.getRecordById(id)
      if (cached) {
        try {
          const res = await get(`/records/${id}`)
          const remote = res.data || res
          return { ...cached, ...remote }
        } catch (e) {
          return cached
        }
      }
      const res = await get(`/records/${id}`)
      return res.data || res
    },

    async checkWithdrawalLimit(userId, amount, ruleId) {
      const res = await get('/check-limit', { user_id: userId, amount, rule_id: ruleId })
      return res.data || res
    },

    async createRule(data) {
      return await post('/rules', data)
    },

    async updateRule(id, data) {
      return await put(`/rules/${id}`, data)
    },

    async deleteRule(id) {
      return await del(`/rules/${id}`)
    },

    async createApplication(data) {
      return await post('/applications', data)
    },

    async cancelApplication(id) {
      return await put(`/applications/${id}/cancel`)
    },

    async approveApplication(id, data) {
      return await put(`/applications/${id}/approve`, data)
    },

    async rejectApplication(id, data) {
      return await put(`/applications/${id}/reject`, data)
    },

    async updateRecord(id, data) {
      return await put(`/records/${id}`, data)
    },

    calculateFee(amount, rule) {
      if (!rule) return { fee: 0, actualAmount: amount }
      let fee = Number(amount) * Number(rule.fee_rate || 0)
      if (rule.fee_min && fee < Number(rule.fee_min)) fee = Number(rule.fee_min)
      if (rule.fee_max && fee > Number(rule.fee_max)) fee = Number(rule.fee_max)
      fee = Number(fee.toFixed(2))
      return { fee, actualAmount: Number((Number(amount) - fee).toFixed(2)) }
    },

    validateWithdrawalAmount(amount, rule) {
      if (!rule) return { valid: false, message: '请选择提现规则' }
      const numAmount = Number(amount)
      if (!numAmount || numAmount <= 0) return { valid: false, message: '请输入有效金额' }
      if (rule.min_amount && numAmount < Number(rule.min_amount)) {
        return { valid: false, message: `提现金额不能低于最低限额 ${rule.min_amount}` }
      }
      if (rule.max_amount && numAmount > Number(rule.max_amount)) {
        return { valid: false, message: `提现金额不能超过最高限额 ${rule.max_amount}` }
      }
      return { valid: true, message: '金额有效' }
    }
  }
})
