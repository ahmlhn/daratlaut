<script setup>
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import RoleManagementPanel from '@/Pages/Settings/Partials/RoleManagementPanel.vue'
</script>

<template>
  <Head title="Kelola Role" />
  <AdminLayout>
    <RoleManagementPanel />
  </AdminLayout>
</template>
