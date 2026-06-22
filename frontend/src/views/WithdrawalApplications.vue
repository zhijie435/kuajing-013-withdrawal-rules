<template>
  <div class="withdrawal-applications">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>提现申请</span>
          <div class="header-actions">
            <el-button type="primary" @click="handleAdd">
              <el-icon><Plus /></el-icon>新建申请
            </el-button>
            <el-button @click="handleRefresh">
              <el-icon><Refresh /></el-icon>刷新
            </el-button>
          </div>
        </div>
      </template>

      <el-form :inline="true" :model="searchForm" class="search-form">
        <el-form-item label="状态">
          <el-select v-model="searchForm.status" placeholder="全部" clearable style="width: 140px">
            <el-option label="待审核" value="pending" />
            <el-option label="审核中" value="reviewing" />
            <el-option label="已通过" value="approved" />
            <el-option label="已拒绝" value="rejected" />
            <el-option label="已取消" value="cancelled" />
            <el-option label="已完成" value="completed" />
            <el-option label="到账失败" value="failed" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">
            <el-icon><Search /></el-icon>查询
          </el-button>
          <el-button @click="handleReset">
            <el-icon><RefreshLeft /></el-icon>重置
          </el-button>
        </el-form-item>
      </el-form>

      <el-table
        :data="store.applications"
        stripe
        border
        v-loading="store.loading.applications"
        style="width: 100%"
      >
        <el-table-column prop="id" label="申请ID" width="90" />
        <el-table-column prop="user_id" label="用户ID" width="90" />
        <el-table-column prop="amount" label="申请金额" width="120" align="right">
          <template #default="{ row }">{{ formatAmount(row.amount) }}</template>
        </el-table-column>
        <el-table-column prop="fee" label="手续费" width="100" align="right">
          <template #default="{ row }">{{ formatAmount(row.fee) }}</template>
        </el-table-column>
        <el-table-column prop="actual_amount" label="实际到账" width="120" align="right">
          <template #default="{ row }">{{ formatAmount(row.actual_amount) }}</template>
        </el-table-column>
        <el-table-column label="银行信息" min-width="200">
          <template #default="{ row }">
            <div>{{ row.bank_name }}</div>
            <div style="color: #909399; font-size: 12px">{{ row.bank_account }} / {{ row.account_name }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)">{{ statusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="申请时间" width="170" />
        <el-table-column label="操作" width="200" fixed="right" align="center">
          <template #default="{ row }">
            <el-button type="primary" link @click="handleViewDetail(row)">详情</el-button>
            <el-button
              v-if="row.status === 'pending' || row.status === 'reviewing'"
              type="warning"
              link
              @click="handleCancel(row)"
            >
              取消
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      :title="isDetail ? '申请详情' : '新建申请'"
      width="600px"
      destroy-on-close
    >
      <template v-if="isDetail">
        <el-descriptions :column="2" border v-if="currentDetail">
          <el-descriptions-item label="申请ID">{{ currentDetail.id }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag :type="statusTagType(currentDetail.status)">{{ statusText(currentDetail.status) }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="用户ID">{{ currentDetail.user_id }}</el-descriptions-item>
          <el-descriptions-item label="申请金额">{{ formatAmount(currentDetail.amount) }}</el-descriptions-item>
          <el-descriptions-item label="手续费">{{ formatAmount(currentDetail.fee) }}</el-descriptions-item>
          <el-descriptions-item label="实际到账">{{ formatAmount(currentDetail.actual_amount) }}</el-descriptions-item>
          <el-descriptions-item label="银行名称">{{ currentDetail.bank_name }}</el-descriptions-item>
          <el-descriptions-item label="银行账号">{{ currentDetail.bank_account }}</el-descriptions-item>
          <el-descriptions-item label="开户名">{{ currentDetail.account_name }}</el-descriptions-item>
          <el-descriptions-item label="规则ID">{{ currentDetail.rule_id }}</el-descriptions-item>
          <el-descriptions-item label="审核备注" :span="2">{{ currentDetail.review_remark || '-' }}</el-descriptions-item>
          <el-descriptions-item label="审核人">{{ currentDetail.reviewer_id || '-' }}</el-descriptions-item>
          <el-descriptions-item label="审核时间">{{ currentDetail.reviewed_at || '-' }}</el-descriptions-item>
          <el-descriptions-item label="申请时间">{{ currentDetail.created_at }}</el-descriptions-item>
        </el-descriptions>
      </template>
      <template v-else>
        <el-alert
          v-if="amountTip"
          :title="amountTip"
          type="warning"
          show-icon
          style="margin-bottom: 16px"
        />
        <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
          <el-form-item label="用户ID" prop="user_id">
            <el-input-number v-model="form.user_id" :min="1" style="width: 100%" />
          </el-form-item>
          <el-form-item label="提现规则" prop="rule_id">
            <el-select v-model="form.rule_id" placeholder="请选择提现规则" style="width: 100%" @change="onRuleChange">
              <el-option
                v-for="rule in store.activeRules"
                :key="rule.id"
                :label="`${rule.rule_name} (${rule.min_amount}-${rule.max_amount})`"
                :value="rule.id"
              />
            </el-select>
            <div v-if="selectedRule" class="rule-tip">
              限额: {{ selectedRule.min_amount }} - {{ selectedRule.max_amount }}，
              日限: {{ selectedRule.daily_limit || '不限' }}，
              费率: {{ (selectedRule.fee_rate * 100).toFixed(2) }}%
            </div>
          </el-form-item>
          <el-form-item label="提现金额" prop="amount">
            <el-input-number
              v-model="form.amount"
              :min="0"
              :precision="2"
              :step="100"
              style="width: 100%"
              @change="onAmountChange"
            />
          </el-form-item>
          <el-form-item label="手续费">
            <span style="color: #409eff; font-weight: 600">{{ formatAmount(feeInfo.fee) }}</span>
          </el-form-item>
          <el-form-item label="实际到账">
            <span style="color: #67c23a; font-weight: 600">{{ formatAmount(feeInfo.actualAmount) }}</span>
          </el-form-item>
          <el-form-item label="银行名称" prop="bank_name">
            <el-input v-model="form.bank_name" placeholder="请输入银行名称" />
          </el-form-item>
          <el-form-item label="银行账号" prop="bank_account">
            <el-input v-model="form.bank_account" placeholder="请输入银行账号" />
          </el-form-item>
          <el-form-item label="开户名" prop="account_name">
            <el-input v-model="form.account_name" placeholder="请输入开户名" />
          </el-form-item>
          <el-alert
            v-if="submitError"
            :title="submitError"
            type="error"
            show-icon
            style="margin-top: 12px"
          />
        </el-form>
      </template>
      <template #footer>
        <el-button @click="dialogVisible = false">关闭</el-button>
        <el-button v-if="!isDetail && submitError" type="warning" @click="handleSubmit">重试</el-button>
        <el-button v-if="!isDetail" type="primary" :loading="submitting" @click="handleSubmit">提交申请</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Refresh, Search, RefreshLeft } from '@element-plus/icons-vue'
import { useWithdrawalStore } from '../stores/withdrawal'

const store = useWithdrawalStore()
const dialogVisible = ref(false)
const isDetail = ref(false)
const formRef = ref(null)
const currentDetail = ref(null)
const amountTip = ref('')
const submitting = ref(false)
const submitError = ref('')

const searchForm = reactive({
  status: ''
})

const defaultForm = {
  user_id: 1,
  rule_id: null,
  amount: 0,
  bank_name: '',
  bank_account: '',
  account_name: ''
}

const form = reactive({ ...defaultForm })

const rules = {
  user_id: [{ required: true, message: '请输入用户ID', trigger: 'blur' }],
  rule_id: [{ required: true, message: '请选择提现规则', trigger: 'change' }],
  amount: [{ required: true, message: '请输入提现金额', trigger: 'blur' }],
  bank_name: [{ required: true, message: '请输入银行名称', trigger: 'blur' }],
  bank_account: [{ required: true, message: '请输入银行账号', trigger: 'blur' }],
  account_name: [{ required: true, message: '请输入开户名', trigger: 'blur' }]
}

const selectedRule = computed(() => {
  return store.rules.find((r) => r.id === form.rule_id)
})

const feeInfo = computed(() => {
  return store.calculateFee(form.amount, selectedRule.value)
})

const formatAmount = (val) => {
  if (val === null || val === undefined) return '0.00'
  return Number(val).toFixed(2)
}

const statusText = (status) => {
  const map = {
    pending: '待审核',
    reviewing: '审核中',
    approved: '已通过',
    rejected: '已拒绝',
    cancelled: '已取消',
    completed: '已完成',
    failed: '到账失败'
  }
  return map[status] || status
}

const statusTagType = (status) => {
  const map = {
    pending: 'warning',
    reviewing: 'primary',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'info',
    completed: 'success',
    failed: 'danger'
  }
  return map[status] || 'info'
}

const handleRefresh = () => {
  store.fetchApplications({ ...searchForm })
}

const handleSearch = () => {
  store.fetchApplications({ ...searchForm })
}

const handleReset = () => {
  searchForm.status = ''
  store.fetchApplications()
}

const handleAdd = () => {
  isDetail.value = false
  amountTip.value = ''
  submitError.value = ''
  Object.assign(form, defaultForm)
  dialogVisible.value = true
}

const handleViewDetail = async (row) => {
  isDetail.value = true
  currentDetail.value = { ...row }
  try {
    const detail = await store.fetchApplicationDetail(row.id)
    if (detail) {
      currentDetail.value = { ...row, ...detail }
    }
  } catch (e) {
    // 保留列表行数据，保证明细与列表一致
  }
  dialogVisible.value = true
}

const handleCancel = (row) => {
  ElMessageBox.confirm('确定要取消该提现申请吗？', '取消确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning'
  }).then(async () => {
    try {
      await store.cancelApplication(row.id)
      ElMessage.success('取消成功')
      handleRefresh()
    } catch (e) {
      ElMessageBox.alert('取消申请失败，操作已回滚，请稍后重试。', '操作失败', {
        confirmButtonText: '我知道了',
        type: 'error'
      })
    }
  }).catch(() => {})
}

const onRuleChange = () => {
  onAmountChange()
}

const onAmountChange = () => {
  if (!selectedRule.value) {
    amountTip.value = ''
    return
  }
  const result = store.validateWithdrawalAmount(form.amount, selectedRule.value)
  amountTip.value = result.valid ? '' : result.message
}

const handleSubmit = async () => {
  try {
    await formRef.value.validate()
  } catch (e) {
    return
  }
  if (!selectedRule.value) {
    ElMessage.warning('请选择提现规则')
    return
  }
  const validation = store.validateWithdrawalAmount(form.amount, selectedRule.value)
  if (!validation.valid) {
    ElMessage.warning(validation.message)
    return
  }
  submitting.value = true
  submitError.value = ''
  try {
    const { fee, actualAmount } = feeInfo.value
    const res = await store.createApplication({
      ...form,
      fee,
      actual_amount: actualAmount
    })
    if (res && res.code !== undefined && res.code !== 0) {
      submitError.value = `申请提交失败：${res.msg || '未知错误'}。余额扣减已回滚，请点击"重试"重新提交。`
      return
    }
    ElMessage.success('申请提交成功')
    dialogVisible.value = false
    handleRefresh()
  } catch (e) {
    const errorMsg = e?.message || '未知错误'
    submitError.value = `提交失败：${errorMsg}。余额扣减已回滚，请点击"重试"重新提交。`
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  if (store.rules.length === 0) {
    await store.fetchRules()
  }
  store.fetchApplications()
})
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.header-actions {
  display: flex;
  gap: 8px;
}

.search-form {
  margin-bottom: 16px;
}

.rule-tip {
  margin-top: 6px;
  font-size: 12px;
  color: #909399;
}
</style>
