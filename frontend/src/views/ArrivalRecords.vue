<template>
  <div class="arrival-records">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>到账记录</span>
          <div class="header-actions">
            <el-button @click="handleRefresh">
              <el-icon><Refresh /></el-icon>刷新
            </el-button>
          </div>
        </div>
      </template>

      <el-form :inline="true" :model="searchForm" class="search-form">
        <el-form-item label="状态">
          <el-select v-model="searchForm.status" placeholder="全部" clearable style="width: 140px">
            <el-option label="处理中" value="processing" />
            <el-option label="已到账" value="success" />
            <el-option label="失败" value="failed" />
          </el-select>
        </el-form-item>
        <el-form-item label="交易单号">
          <el-input v-model="searchForm.transaction_no" placeholder="请输入交易单号" clearable style="width: 200px" />
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
        :data="store.records"
        stripe
        border
        v-loading="store.loading.records"
        style="width: 100%"
      >
        <el-table-column prop="id" label="记录ID" width="90" />
        <el-table-column prop="application_id" label="申请ID" width="100" />
        <el-table-column prop="transaction_no" label="交易单号" min-width="200" show-overflow-tooltip />
        <el-table-column prop="amount" label="到账金额" width="120" align="right">
          <template #default="{ row }">{{ formatAmount(row.amount) }}</template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)">{{ statusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="arrived_at" label="到账时间" width="170">
          <template #default="{ row }">{{ row.arrived_at || '-' }}</template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="170" />
        <el-table-column label="操作" width="160" fixed="right" align="center">
          <template #default="{ row }">
            <el-button type="primary" link @click="handleViewDetail(row)">详情追踪</el-button>
            <el-button
              v-if="row.status === 'processing'"
              type="success"
              link
              @click="handleMarkSuccess(row)"
            >
              标记到账
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog
      v-model="detailVisible"
      title="到账记录详情追踪"
      width="650px"
      destroy-on-close
    >
      <el-descriptions :column="2" border v-if="currentDetail">
        <el-descriptions-item label="记录ID">{{ currentDetail.id }}</el-descriptions-item>
        <el-descriptions-item label="申请ID">{{ currentDetail.application_id }}</el-descriptions-item>
        <el-descriptions-item label="交易单号" :span="2">{{ currentDetail.transaction_no }}</el-descriptions-item>
        <el-descriptions-item label="到账金额">
          <span style="color: #67c23a; font-weight: 600">{{ formatAmount(currentDetail.amount) }}</span>
        </el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="statusTagType(currentDetail.status)">{{ statusText(currentDetail.status) }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="到账时间">{{ currentDetail.arrived_at || '-' }}</el-descriptions-item>
        <el-descriptions-item label="创建时间">{{ currentDetail.created_at }}</el-descriptions-item>
        <el-descriptions-item label="失败原因" :span="2" v-if="currentDetail.status === 'failed'">
          <span style="color: #f56c6c">{{ currentDetail.fail_reason || '-' }}</span>
        </el-descriptions-item>
      </el-descriptions>

      <el-divider>处理时间线</el-divider>
      <el-timeline v-if="currentDetail">
        <el-timeline-item
          :timestamp="currentDetail.created_at"
          placement="top"
          type="primary"
          icon="Plus"
        >
          创建到账记录
        </el-timeline-item>
        <el-timeline-item
          v-if="currentDetail.arrived_at"
          :timestamp="currentDetail.arrived_at"
          placement="top"
          :type="currentDetail.status === 'success' ? 'success' : 'danger'"
          :icon="currentDetail.status === 'success' ? 'CircleCheck' : 'CircleClose'"
        >
          {{ currentDetail.status === 'success' ? '款项已成功到账' : (currentDetail.fail_reason || '到账失败') }}
        </el-timeline-item>
        <el-timeline-item
          v-if="currentDetail.status === 'processing'"
          placement="top"
          type="warning"
          icon="Loading"
        >
          处理中，等待银行确认...
        </el-timeline-item>
      </el-timeline>

      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
        <el-button
          v-if="currentDetail && currentDetail.status === 'processing'"
          type="success"
          @click="handleMarkSuccess(currentDetail)"
        >
          标记已到账
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh, Search, RefreshLeft } from '@element-plus/icons-vue'
import { useWithdrawalStore } from '../stores/withdrawal'
import dayjs from 'dayjs'

const store = useWithdrawalStore()
const detailVisible = ref(false)
const currentDetail = ref(null)

const searchForm = reactive({
  status: '',
  transaction_no: ''
})

const formatAmount = (val) => {
  if (val === null || val === undefined) return '0.00'
  return Number(val).toFixed(2)
}

const statusText = (status) => {
  const map = {
    processing: '处理中',
    success: '已到账',
    failed: '失败'
  }
  return map[status] || status
}

const statusTagType = (status) => {
  const map = {
    processing: 'warning',
    success: 'success',
    failed: 'danger'
  }
  return map[status] || 'info'
}

const handleRefresh = () => {
  store.fetchRecords({ ...searchForm })
}

const handleSearch = () => {
  store.fetchRecords({ ...searchForm })
}

const handleReset = () => {
  searchForm.status = ''
  searchForm.transaction_no = ''
  store.fetchRecords()
}

const handleViewDetail = async (row) => {
  currentDetail.value = { ...row }
  try {
    const detail = await store.fetchRecordDetail(row.id)
    if (detail) {
      currentDetail.value = { ...row, ...detail }
    }
  } catch (e) {
    // 保留列表行数据，保证明细与列表一致
  }
  detailVisible.value = true
}

const handleMarkSuccess = (row) => {
  ElMessageBox.confirm('确定标记该记录为已到账吗？', '确认操作', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning'
  }).then(async () => {
    try {
      await store.updateRecord(row.id, {
        status: 'success',
        arrived_at: dayjs().format('YYYY-MM-DD HH:mm:ss')
      })
      ElMessage.success('标记成功')
      detailVisible.value = false
      handleRefresh()
    } catch (e) {
      if (e?.message) {
        ElMessage.error(e.message || '标记失败')
      }
    }
  }).catch(() => {})
}

onMounted(() => {
  store.fetchRecords()
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
</style>
