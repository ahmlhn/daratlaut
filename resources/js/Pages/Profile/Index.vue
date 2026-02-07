<script setup>
import { ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const page = usePage();
const user = page.props.auth.user;

const form = useForm({
    name: user.name || '',
    email: user.email || '',
    phone: user.phone || '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/profile', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <Head title="Profile" />

    <AdminLayout title="Profile Saya">
        <div class="max-w-2xl mx-auto">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-white/10 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-900/50">
                    <h2 class="text-lg font-bold text-slate-800 dark:text-white">Edit Profil Saya</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Update informasi akun Anda</p>
                </div>

                <form @submit.prevent="submit" class="p-6 space-y-6">
                    <!-- Success Message -->
                    <div v-if="page.props.flash?.success" class="p-4 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-300">
                        {{ page.props.flash.success }}
                    </div>

                    <!-- Name & Username -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase mb-1">Nama Lengkap</label>
                            <input 
                                v-model="form.name" 
                                type="text" 
                                required
                                class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 text-slate-800 dark:text-white focus:border-blue-500 focus:outline-none transition"
                            >
                            <div v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase mb-1">Username</label>
                            <input 
                                :value="user.username" 
                                type="text" 
                                disabled
                                class="w-full bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2 text-slate-500 dark:text-slate-400 font-mono cursor-not-allowed"
                            >
                            <div class="mt-1 text-[10px] text-slate-400">Username tidak dapat diubah</div>
                        </div>
                    </div>

                    <!-- Email -->
                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase mb-1">Email</label>
                        <input 
                            v-model="form.email" 
                            type="email"
                            required
                            class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 text-slate-800 dark:text-white focus:border-blue-500 focus:outline-none transition"
                        >
                        <div v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</div>
                    </div>

                    <!-- Phone -->
                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase mb-1">No. WhatsApp</label>
                        <input 
                            v-model="form.phone" 
                            type="tel"
                            placeholder="08xxx atau 62xxx"
                            class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 text-slate-800 dark:text-white focus:border-blue-500 focus:outline-none transition"
                        >
                        <div v-if="form.errors.phone" class="mt-1 text-xs text-red-600">{{ form.errors.phone }}</div>
                    </div>

                    <!-- Divider -->
                    <div class="border-t border-slate-200 dark:border-white/10 pt-6">
                        <h3 class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-4">Ganti Password</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Kosongkan jika tidak ingin mengubah password</p>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase mb-1">Password Baru</label>
                                <input 
                                    v-model="form.password" 
                                    type="password"
                                    placeholder="Minimal 6 karakter"
                                    class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 text-slate-800 dark:text-white focus:border-blue-500 focus:outline-none transition"
                                >
                                <div v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase mb-1">Konfirmasi Password</label>
                                <input 
                                    v-model="form.password_confirmation" 
                                    type="password"
                                    placeholder="Ketik ulang password baru"
                                    class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 text-slate-800 dark:text-white focus:border-blue-500 focus:outline-none transition"
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="pt-4 flex gap-3 border-t border-slate-100 dark:border-white/10">
                        <button 
                            type="submit" 
                            :disabled="form.processing"
                            class="flex-1 py-3 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-xl shadow-lg shadow-blue-500/30 transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span v-if="form.processing">Menyimpan...</span>
                            <span v-else>Simpan Perubahan</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Role & Permissions Info -->
            <div class="mt-6 bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-white/10 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-900/50">
                    <h3 class="text-sm font-bold text-slate-700 dark:text-slate-200">Informasi Akun</h3>
                </div>
                <div class="p-6 space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Role</span>
                        <span class="font-bold text-slate-800 dark:text-white uppercase">{{ user.role || 'User' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Tenant ID</span>
                        <span class="font-mono text-slate-800 dark:text-white">#{{ user.tenant_id }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Terdaftar Sejak</span>
                        <span class="text-slate-800 dark:text-white">{{ new Date(user.created_at).toLocaleDateString('id-ID') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
