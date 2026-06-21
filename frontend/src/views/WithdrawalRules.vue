<template>
  <div class="withdrawal-rules">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>提现规则列表</span>
          <el-button type="primary" @click="handleAdd">
            <el-icon><Plus /></el-icon>新增规则
          </el-button>
        </div>
      </template>
      <el-table :data="store.rules" stripe border style="width: 100%">
        <el-table-column prop="rule_name" label="规则名称" min-width="140" />
        <el-table-column prop="min_amount" label="最小金额" width="120" align="right">
          <template #default="{ row }">{{ formatAmount(row.min_amount) }}</template>
        </el-table-column>
        <el-table-column prop="max_amount" label="最大金额" width="120" align="right">
          <template #default="{ row }">{{ formatAmount(row.max_amount) }}</template>
        </el-table-column>
        <el-table-column prop="daily_limit" label="每日限额" width="120" align="right">
          <template #default="{ row }">{{ formatAmount(row.daily_limit) }}</template>
        </el-table-column>
        <el-table-column prop="fee_rate" label="手续费率" width="100" align="center">
          <template #default="{ row }">{{ (row.fee_rate * 100).toFixed(2) }}%</template>
        </el-table-column>
        <el-table-column prop="fee_min" label="最低手续费" width="120" align="right">
          <template #default="{ row }">{{ formatAmount(row.fee_min) }}</template>
        </el-table-column>
        <el-table-column prop="fee_max" label="最高手续费" width="120" align="right">
          <template #default="{ row }">{{ formatAmount(row.fee_max) }}</template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-switch
              :model-value="row.status === 'active'"
              active-text="启用"
              inactive-text="禁用"
              @change="(val) => handleStatusChange(row, val)"
            />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="160" fixed="right" align="center">
          <template #default="{ row }">
            <el-button type="primary" link @click="handleEdit(row)">编辑</el-button>
            <el-button type="danger" link @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑规则' : '新增规则'"
      width="600px"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
        <el-form-item label="规则名称" prop="rule_name">
          <el-input v-model="form.rule_name" placeholder="请输入规则名称" />
        </el-form-item>
        <el-form-item label="最小金额" prop="min_amount">
          <el-input-number v-model="form.min_amount" :min="0" :precision="2" style="width: 100%" />
        </el-form-item>
        <el-form-item label="最大金额" prop="max_amount">
          <el-input-number v-model="form.max_amount" :min="0" :precision="2" style="width: 100%" />
        </el-form-item>
        <el-form-item label="每日限额" prop="daily_limit">
          <el-input-number v-model="form.daily_limit" :min="0" :precision="2" style="width: 100%" />
        </el-form-item>
        <el-form-item label="手续费率" prop="fee_rate">
          <el-input-number v-model="form.fee_rate" :min="0" :max="1" :step="0.001" :precision="4" style="width: 100%" />
        </el-form-item>
        <el-form-item label="最低手续费" prop="fee_min">
          <el-input-number v-model="form.fee_min" :min="0" :precision="2" style="width: 100%" />
        </el-form-item>
        <el-form-item label="最高手续费" prop="fee_max">
          <el-input-number v-model="form.fee_max" :min="0" :precision="2" style="width: 100%" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio value="active">启用</el-radio>
            <el-radio value="inactive">禁用</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { useWithdrawalStore } from '../stores/withdrawal'

const store = useWithdrawalStore()
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref(null)
const editingId = ref(null)

const defaultForm = {
  rule_name: '',
  min_amount: 0,
  max_amount: 0,
  daily_limit: 0,
  fee_rate: 0,
  fee_min: 0,
  fee_max: 0,
  status: 'active'
}

const form = reactive({ ...defaultForm })

const rules = {
  rule_name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
  min_amount: [{ required: true, message: '请输入最小金额', trigger: 'blur' }],
  max_amount: [{ required: true, message: '请输入最大金额', trigger: 'blur' }],
  fee_rate: [{ required: true, message: '请输入手续费率', trigger: 'blur' }]
}

const formatAmount = (val) => {
  if (val === null || val === undefined) return '0.00'
  return Number(val).toFixed(2)
}

const handleAdd = () => {
  isEdit.value = false
  editingId.value = null
  Object.assign(form, defaultForm)
  dialogVisible.value = true
}

const handleEdit = (row) => {
  isEdit.value = true
  editingId.value = row.id
  Object.assign(form, {
    rule_name: row.rule_name,
    min_amount: row.min_amount,
    max_amount: row.max_amount,
    daily_limit: row.daily_limit,
    fee_rate: row.fee_rate,
    fee_min: row.fee_min,
    fee_max: row.fee_max,
    status: row.status
  })
  dialogVisible.value = true
}

const handleSubmit = async () => {
  await formRef.value.validate()
  try {
    if (isEdit.value) {
      await store.updateRule(editingId.value, { ...form })
      ElMessage.success('更新成功')
    } else {
      await store.createRule({ ...form })
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false
    store.fetchRules()
  } catch (e) {
    // error handled by interceptor
  }
}

const handleDelete = (row) => {
  ElMessageBox.confirm(`确定删除规则"${row.rule_name}"吗？`, '删除确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning'
  }).then(async () => {
    try {
      await store.deleteRule(row.id)
      ElMessage.success('删除成功')
      store.fetchRules()
    } catch (e) {
      // error handled by interceptor
    }
  }).catch(() => {})
}

const handleStatusChange = async (row, val) => {
  try {
    const newStatus = val ? 'active' : 'inactive'
    await store.updateRule(row.id, { ...row, status: newStatus })
    ElMessage.success('状态更新成功')
    store.fetchRules()
  } catch (e) {
    // error handled by interceptor
  }
}

onMounted(() => {
  store.fetchRules()
})
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
</style>
