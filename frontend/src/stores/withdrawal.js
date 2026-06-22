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
      const res = await get(`/applications/${id}`)
      const detail = res.data || res
      if (detail) {
        const idx = this.applications.findIndex((a) => a.id === Number(id))
        if (idx !== -1) {
          this.applications[idx] = { ...this.applications[idx], ...detail }
        }
        const reviewIdx = this.reviews.findIndex((a) => a.id === Number(id))
        if (reviewIdx !== -1) {
          this.reviews[reviewIdx] = { ...this.reviews[reviewIdx], ...detail }
        }
      }
      return detail
    },

    async fetchRecordDetail(id) {
      const res = await get(`/records/${id}`)
      const detail = res.data || res
      if (detail) {
        const idx = this.records.findIndex((r) => r.id === Number(id))
        if (idx !== -1) {
          this.records[idx] = { ...this.records[idx], ...detail }
        }
      }
      return detail
    },

    async checkWithdrawalLimit(userId, amount, ruleId) {
      const res = await get('/check-limit', { user_id: userId, amount, rule_id: ruleId })
      return res.data || res
    },

    async createRule(data) {
      return await post('/rules', data)
    },

    async updateRule(id, data) {
      const res = await put(`/rules/${id}`, data)
      const idx = this.rules.findIndex((r) => r.id === Number(id))
      if (idx !== -1) {
        const merged = { ...this.rules[idx], ...data }
        if (typeof merged.status === 'boolean') {
          merged.status = merged.status ? 'active' : 'inactive'
        }
        this.rules[idx] = merged
      }
      return res
    },

    async deleteRule(id) {
      const res = await del(`/rules/${id}`)
      this.rules = this.rules.filter((r) => r.id !== Number(id))
      return res
    },

    async createApplication(data) {
      return await post('/applications', data)
    },

    async approveApplication(id, data) {
      const res = await put(`/applications/${id}/approve`, data)
      if (res && res.code === 0) {
        await this.fetchApplicationDetail(id)
      }
      return res
    },

    async rejectApplication(id, data) {
      const res = await put(`/applications/${id}/reject`, data)
      if (res && res.code === 0) {
        await this.fetchApplicationDetail(id)
      }
      return res
    },

    async cancelApplication(id) {
      const res = await put(`/applications/${id}/cancel`)
      if (res && res.code === 0) {
        await this.fetchApplicationDetail(id)
      }
      return res
    },

    async updateRecord(id, data) {
      const res = await put(`/records/${id}`, data)
      if (res && res.code === 0) {
        const idx = this.records.findIndex((r) => r.id === Number(id))
        if (idx !== -1) {
          const merged = { ...this.records[idx], ...data }
          if (data.status === 'success' && !merged.arrived_at) {
            merged.arrived_at = new Date().toISOString().replace('T', ' ').substring(0, 19)
          }
          this.records[idx] = merged
        }
      }
      return res
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
