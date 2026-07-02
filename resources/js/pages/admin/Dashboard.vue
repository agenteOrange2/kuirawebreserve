<script setup lang="ts">
import RazeLayout from '@/layouts/RazeLayout.vue';
import Lucide from '@/components/Base/Lucide';

defineProps<{
    canLogin: boolean;
    canRegister: boolean;
    laravelVersion: string;
    phpVersion: string;
}>();
</script>

<template>
    <RazeLayout title="Admin Dashboard">
        <div class="grid grid-cols-12 gap-6">
            <div class="col-span-12 2xl:col-span-9">
                <div class="grid grid-cols-12 gap-6">
                    <!-- Stats -->
                    <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                        <div class="box">
                            <div class="p-5">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="p-3 bg-primary/10 rounded-full">
                                            <Lucide icon="Users" class="w-6 h-6 text-primary" />
                                        </div>
                                        <div>
                                            <div class="text-slate-500 text-xs">Total Users</div>
                                            <div class="text-2xl font-medium">1,234</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                        <div class="box">
                            <div class="p-5">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="p-3 bg-success/10 rounded-full">
                                            <Lucide icon="ShoppingCart" class="w-6 h-6 text-success" />
                                        </div>
                                        <div>
                                            <div class="text-slate-500 text-xs">Orders</div>
                                            <div class="text-2xl font-medium">567</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                        <div class="box">
                            <div class="p-5">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="p-3 bg-warning/10 rounded-full">
                                            <Lucide icon="DollarSign" class="w-6 h-6 text-warning" />
                                        </div>
                                        <div>
                                            <div class="text-slate-500 text-xs">Revenue</div>
                                            <div class="text-2xl font-medium">$12,345</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                        <div class="box">
                            <div class="p-5">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="p-3 bg-danger/10 rounded-full">
                                            <Lucide icon="AlertTriangle" class="w-6 h-6 text-danger" />
                                        </div>
                                        <div>
                                            <div class="text-slate-500 text-xs">Pending</div>
                                            <div class="text-2xl font-medium">89</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Recent Activity -->
                    <div class="col-span-12 xl:col-span-8">
                        <div class="box">
                            <div class="border-b border-slate-200 px-5 py-4">
                                <div class="text-lg font-medium">Recent Activity</div>
                            </div>
                            <div class="p-5">
                                <div class="flex items-center gap-4 py-3 border-b border-slate-100">
                                    <div class="w-10 h-10 rounded-full bg-success/20 flex items-center justify-center">
                                        <Lucide icon="Check" class="w-5 h-5 text-success" />
                                    </div>
                                    <div class="flex-1">
                                        <div class="font-medium">New order #1234</div>
                                        <div class="text-xs text-slate-500">2 minutes ago</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-4 py-3 border-b border-slate-100">
                                    <div class="w-10 h-10 rounded-full bg-primary/20 flex items-center justify-center">
                                        <Lucide icon="UserPlus" class="w-5 h-5 text-primary" />
                                    </div>
                                    <div class="flex-1">
                                        <div class="font-medium">New user registered</div>
                                        <div class="text-xs text-slate-500">15 minutes ago</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-4 py-3">
                                    <div class="w-10 h-10 rounded-full bg-warning/20 flex items-center justify-center">
                                        <Lucide icon="AlertCircle" class="w-5 h-5 text-warning" />
                                    </div>
                                    <div class="flex-1">
                                        <div class="font-medium">Server alert triggered</div>
                                        <div class="text-xs text-slate-500">1 hour ago</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Quick Stats -->
                    <div class="col-span-12 xl:col-span-4">
                        <div class="box">
                            <div class="border-b border-slate-200 px-5 py-4">
                                <div class="text-lg font-medium">Quick Stats</div>
                            </div>
                            <div class="p-5 space-y-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">Active Sessions</span>
                                    <span class="font-medium">234</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">Bounce Rate</span>
                                    <span class="font-medium text-success">32%</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">Page Views</span>
                                    <span class="font-medium">5,678</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">Conversion</span>
                                    <span class="font-medium text-primary">4.2%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>