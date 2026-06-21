import { createRouter, createWebHistory } from 'vue-router'
import MainLayout from '../layout/MainLayout.vue'

const routes = [
  {
    path: '/',
    component: MainLayout,
    redirect: '/rules',
    children: [
      {
        path: 'rules',
        name: 'WithdrawalRules',
        component: () => import('../views/WithdrawalRules.vue'),
        meta: { title: '提现规则管理' }
      },
      {
        path: 'applications',
        name: 'WithdrawalApplications',
        component: () => import('../views/WithdrawalApplications.vue'),
        meta: { title: '提现申请' }
      },
      {
        path: 'reviews',
        name: 'ReviewManagement',
        component: () => import('../views/ReviewManagement.vue'),
        meta: { title: '审核管理' }
      },
      {
        path: 'records',
        name: 'ArrivalRecords',
        component: () => import('../views/ArrivalRecords.vue'),
        meta: { title: '到账记录' }
      }
    ]
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

export default router
