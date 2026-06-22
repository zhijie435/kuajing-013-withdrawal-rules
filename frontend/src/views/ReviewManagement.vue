<template>
  <div class="review-management">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>审核管理</span>
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
            <el-option label="待审核" value="pending" />
            <el-option label="审核中" value="reviewing" />
            <el-option label="已通过" value="approved" />
            <el-option label="已拒绝" value="rejected" />
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
        :data="store.reviews"
        stripe
        border
        v-loading="store.loading.reviews"
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
        <el-table-column label="操作" width="260" fixed="right" align="center">
          <template #default="{ row }">
            <el-button type="primary" link @click="handleViewDetail(row)">详情</el-button>
            <el-button
              v-if="row.status === 'pending' || row.status === 'reviewing'"
              type="success"
              link
              @click="handleApprove(row)"
            >
              通过
            </el-button>
            <el-button
              v-if="row.status === 'pending' || row.status === 'reviewing'"
              type="danger"
              link
              @click="handleReject(row)"
            >
              拒绝
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog
      v-model="detailVisible"
      title="申请详情"
      width="600px"
      destroy-on-close
    >
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
      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
        <el-button
          v-if="currentDetail && (currentDetail.status === 'pending' || currentDetail.status === 'reviewing')"
          type="success"
          @click="handleApprove(currentDetail)"
        >
          审核通过
        </el-button>
        <el-button
          v-if="currentDetail && (currentDetail.status === 'pending' || currentDetail.status === 'reviewing')"
          type="danger"
          @click="handleReject(currentDetail)"
        >
          审核拒绝
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="reviewVisible"
      :title="reviewAction === 'approve' ? '审核通过' : '审核拒绝'"
      width="500px"
      destroy-on-close
    >
      <el-form ref="reviewFormRef" :model="reviewForm" :rules="reviewRules" label-width="80px">
        <el-form-item label="审核备注" prop="review_remark">
          <el-input
            v-model="reviewForm.review_remark"
            type="textarea"
            :rows="4"
            :placeholder="reviewAction === 'approve' ? '请输入审核备注（选填）' : '请输入拒绝原因（必填）'"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="reviewVisible = false">取消</el-button>
        <el-button type="primary" @click="submitReview">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh, Search, RefreshLeft } from '@element-plus/icons-vue'
import { useWithdrawalStore } from '../stores/withdrawal'

const store = useWithdrawalStore()
const detailVisible = ref(false)
const reviewVisible = ref(false)
const reviewFormRef = ref(null)
const currentDetail = ref(null)
const reviewTargetId = ref(null)
const reviewAction = ref('approve')

const searchForm = reactive({
  status: ''
})

const reviewForm = reactive({
  review_remark: ''
})

const reviewRules = {
  review_remark: [
    {
      validator: (rule, value, callback) => {
        if (reviewAction.value === 'reject' && !value.trim()) {
          callback(new Error('请输入拒绝原因'))
        } else {
          callback()
        }
      },
      trigger: 'blur'
    }
  ]
}

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
    cancelled: '已取消'
  }
  return map[status] || status
}

const statusTagType = (status) => {
  const map = {
    pending: 'warning',
    reviewing: 'primary',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'info'
  }
  return map[status] || 'info'
}

const handleRefresh = () => {
  store.fetchReviews({ ...searchForm })
}

const handleSearch = () => {
  store.fetchReviews({ ...searchForm })
}

const handleReset = () => {
  searchForm.status = ''
  store.fetchReviews()
}

const handleViewDetail = async (row) => {
  currentDetail.value = { ...row }
  try {
    const detail = await store.fetchApplicationDetail(row.id)
    if (detail) {
      currentDetail.value = { ...row, ...detail }
    }
  } catch (e) {
    // 保留列表行数据，保证明细与列表一致
  }
  detailVisible.value = true
}

const handleApprove = (row) => {
  reviewAction.value = 'approve'
  reviewTargetId.value = row.id
  reviewForm.review_remark = ''
  reviewVisible.value = true
}

const handleReject = (row) => {
  reviewAction.value = 'reject'
  reviewTargetId.value = row.id
  reviewForm.review_remark = ''
  reviewVisible.value = true
}

const submitReview = async () => {
  await reviewFormRef.value.validate()
  try {
    if (reviewAction.value === 'approve') {
      await store.approveApplication(reviewTargetId.value, {
        review_remark: reviewForm.review_remark,
        reviewer_id: 1
      })
      ElMessage.success('审核通过成功')
    } else {
      await store.rejectApplication(reviewTargetId.value, {
        review_remark: reviewForm.review_remark,
        reviewer_id: 1
      })
      ElMessage.success('审核拒绝成功')
    }
    reviewVisible.value = false
    detailVisible.value = false
    handleRefresh()
  } catch (e) {}
}

onMounted(() => {
  store.fetchReviews()
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
