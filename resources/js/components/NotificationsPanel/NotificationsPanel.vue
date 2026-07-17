<script setup lang="ts">
import _ from 'lodash';
import Button from '@/components/Base/Button';
import { Slideover } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import activities from '@/fakers/activities';
import users from '@/fakers/users';

interface MainProps {
    notificationsPanel: boolean;
    setNotificationsPanel: (val: boolean) => void;
}

const props = withDefaults(defineProps<MainProps>(), {
    notificationsPanel: false,
    setNotificationsPanel: () => {},
});
</script>

<template>
    <div>
        <Slideover
            :open="props.notificationsPanel"
            @close="
                () => {
                    props.setNotificationsPanel(false);
                }
            "
        >
            <Slideover.Panel
                class="w-72 rounded-[0.75rem_0_0_0.75rem/1.1rem_0_0_1.1rem]"
            >
                <a
                    href=""
                    class="absolute inset-y-0 right-auto left-0 my-auto -ml-[60px] flex h-8 w-8 items-center justify-center rounded-full border border-white/90 bg-white/5 text-white/90 transition-all hover:scale-105 hover:rotate-180 hover:bg-white/10 focus:outline-none sm:-ml-[105px] sm:h-14 sm:w-14"
                    @click="
                        (e) => {
                            e.preventDefault();
                            props.setNotificationsPanel(false);
                        }
                    "
                >
                    <Lucide class="h-8 w-8 stroke-[1]" icon="X" />
                </a>
                <Slideover.Title class="px-6 py-5">
                    <h2 class="mr-auto text-base font-medium">Notifications</h2>
                    <Button variant="outline-secondary" class="hidden sm:flex">
                        <Lucide icon="ShieldCheck" class="mr-2 h-4 w-4" /> Mark
                        all as read
                    </Button>
                </Slideover.Title>
                <Slideover.Description class="p-0">
                    <div class="flex flex-col gap-0.5 p-3">
                        <template
                            v-for="(
                                faker, fakerKey
                            ) in activities.fakeActivities()"
                            :key="fakerKey"
                        >
                            <a
                                href=""
                                class="flex items-center rounded-xl px-3 py-2.5 hover:bg-slate-100/80"
                            >
                                <div>
                                    <div
                                        class="image-fit h-11 w-11 overflow-hidden rounded-full border-2 border-slate-200/70"
                                    >
                                        <img
                                            alt="Tailwise - Admin Dashboard Template"
                                            :src="users.fakeUsers()[0].photo"
                                        />
                                    </div>
                                </div>
                                <div class="sm:ml-5">
                                    <div class="font-medium">
                                        {{ faker.activity }}
                                    </div>
                                    <div class="mt-0.5 text-slate-500">
                                        {{ faker.activityDetails }}
                                    </div>
                                    <template v-if="faker.images">
                                        <div
                                            class="my-3.5 w-40 rounded-[0.6rem] border bg-slate-50/80 p-1 sm:w-56"
                                        >
                                            <div
                                                class="grid grid-cols-3 overflow-hidden rounded-[0.6rem]"
                                            >
                                                <div
                                                    class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16"
                                                >
                                                    <img
                                                        alt="Tailwise - Admin Dashboard Template"
                                                        :src="faker.images[0]"
                                                    />
                                                </div>
                                                <div
                                                    class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16"
                                                >
                                                    <img
                                                        alt="Tailwise - Admin Dashboard Template"
                                                        :src="faker.images[1]"
                                                    />
                                                </div>
                                                <div
                                                    class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16"
                                                >
                                                    <img
                                                        alt="Tailwise - Admin Dashboard Template"
                                                        :src="faker.images[2]"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                    <div class="mt-1.5 text-xs text-slate-500">
                                        {{ faker.date }}
                                    </div>
                                </div>
                                <template v-if="_.random(0, 1) == 1">
                                    <div
                                        class="ml-auto h-2 w-2 flex-none rounded-full border border-primary/40 bg-primary/40"
                                    ></div>
                                </template>
                            </a>
                        </template>
                    </div>
                </Slideover.Description>
            </Slideover.Panel>
        </Slideover>
    </div>
</template>
