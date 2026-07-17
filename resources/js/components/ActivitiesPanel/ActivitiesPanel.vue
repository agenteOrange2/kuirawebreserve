<script setup lang="ts">
import _ from 'lodash';
import FileIcon from '@/components/Base/FileIcon';
import { Slideover } from '@/components/Base/Headless';
import { Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import activities from '@/fakers/activities';

interface MainProps {
    activitiesPanel: boolean;
    setActivitiesPanel: (val: boolean) => void;
}

const props = withDefaults(defineProps<MainProps>(), {
    activitiesPanel: false,
    setActivitiesPanel: () => {},
});
</script>

<template>
    <div>
        <Slideover
            :open="props.activitiesPanel"
            @close="
                () => {
                    props.setActivitiesPanel(false);
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
                            props.setActivitiesPanel(false);
                        }
                    "
                >
                    <Lucide class="h-8 w-8 stroke-[1]" icon="X" />
                </a>
                <Slideover.Title class="px-6 py-5">
                    <h2 class="mr-auto text-base font-medium">
                        Latest Activities
                    </h2>
                </Slideover.Title>
                <Slideover.Description class="p-0">
                    <div class="flex flex-col gap-3.5 px-5 py-3">
                        <div
                            class="relative overflow-hidden before:absolute before:inset-y-0 before:left-0 before:ml-[14px] before:w-px before:bg-slate-200/60 before:content-[''] before:dark:bg-darkmode-400"
                        >
                            <template
                                v-for="(faker, fakerKey) in _.take(
                                    activities.fakeActivities(),
                                    5,
                                )"
                                :key="fakerKey"
                            >
                                <div
                                    :class="[
                                        'relative mb-3 last:mb-0',
                                        'first:before:absolute first:before:h-1/2 first:before:w-5 first:before:bg-white first:before:content-[\'\']',
                                        'last:after:absolute last:after:bottom-0 last:after:h-1/2 last:after:w-5 last:after:bg-white last:after:content-[\'\']',
                                    ]"
                                >
                                    <div
                                        :class="[
                                            'ml-8 px-4 py-3',
                                            'before:absolute before:inset-y-0 before:left-0 before:z-10 before:my-auto before:ml-1 before:h-5 before:w-5 before:rounded-full before:bg-slate-200 before:content-[\'\'] before:dark:bg-darkmode-300',
                                            'after:absolute after:inset-y-0 after:left-0 after:z-10 after:my-auto after:ml-[11px] after:h-1.5 after:w-1.5 after:rounded-full after:bg-slate-500 after:content-[\'\'] after:dark:bg-darkmode-200',
                                        ]"
                                    >
                                        <a
                                            href=""
                                            class="font-medium text-primary"
                                        >
                                            {{ faker.activity }}
                                        </a>
                                        <div
                                            class="mt-1.5 flex flex-col gap-y-1.5 text-[0.8rem] leading-relaxed text-slate-500 sm:flex-row sm:items-center"
                                        >
                                            {{ faker.activityDetails }}
                                            <span
                                                :class="[
                                                    'group mr-auto flex items-center rounded-md border px-1.5 py-px text-xs font-medium sm:mr-0 sm:ml-2',
                                                    '[&.primary]:border-primary/10 [&.primary]:bg-primary/10 [&.primary]:text-primary',
                                                    '[&.success]:border-success/10 [&.success]:bg-success/10 [&.success]:text-success',
                                                    '[&.warning]:border-warning/10 [&.warning]:bg-warning/10 [&.warning]:text-warning',
                                                    '[&.info]:border-info/10 [&.info]:bg-info/10 [&.info]:text-info',
                                                    [
                                                        'primary',
                                                        'success',
                                                        'warning',
                                                        'info',
                                                    ][_.random(0, 3)],
                                                ]"
                                            >
                                                <span
                                                    class="mr-1.5 h-1.5 w-1.5 rounded-full group-[.info]:bg-info/80 group-[.primary]:bg-primary/80 group-[.success]:bg-success/80 group-[.warning]:bg-warning/80"
                                                ></span>
                                                <span class="-mt-px">{{
                                                    faker.statusBadge
                                                }}</span>
                                            </span>
                                        </div>
                                        <template v-if="faker.uploadedFiles">
                                            <div
                                                class="my-3.5 grid grid-cols-1 gap-4"
                                            >
                                                <template
                                                    v-for="(
                                                        file, fileKey
                                                    ) in faker.uploadedFiles"
                                                    :key="fileKey"
                                                >
                                                    <div
                                                        class="flex items-center rounded-[0.6rem] border border-slate-200/80 bg-slate-50/70 py-4 pr-2.5 pl-5"
                                                    >
                                                        <FileIcon
                                                            class="hidden w-10 sm:block"
                                                            variant="directory"
                                                        />
                                                        <div
                                                            class="mr-auto sm:ml-3.5"
                                                        >
                                                            <div
                                                                class="max-w-[8rem] truncate font-medium text-primary"
                                                            >
                                                                {{
                                                                    file.filename
                                                                }}
                                                            </div>
                                                            <div
                                                                class="mt-1 text-xs text-slate-500"
                                                            >
                                                                {{ file.size }}
                                                            </div>
                                                        </div>
                                                        <Menu>
                                                            <Menu.Button
                                                                class="h-5 w-5 text-slate-500"
                                                            >
                                                                <Lucide
                                                                    icon="MoreVertical"
                                                                    class="h-4 w-4"
                                                                />
                                                            </Menu.Button>
                                                            <Menu.Items
                                                                class="w-40"
                                                            >
                                                                <Menu.Item>
                                                                    <Lucide
                                                                        icon="Copy"
                                                                        class="mr-2 h-4 w-4"
                                                                    />
                                                                    Copy Link
                                                                </Menu.Item>
                                                                <Menu.Item>
                                                                    <Lucide
                                                                        icon="Trash"
                                                                        class="mr-2 h-4 w-4"
                                                                    />
                                                                    Delete
                                                                </Menu.Item>
                                                            </Menu.Items>
                                                        </Menu>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        <template v-if="faker.images">
                                            <div
                                                class="my-3.5 w-40 rounded-[0.6rem] border bg-slate-50/80 p-1 sm:w-[80%]"
                                            >
                                                <div
                                                    class="grid grid-cols-3 overflow-hidden rounded-[0.6rem]"
                                                >
                                                    <div
                                                        class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-20"
                                                    >
                                                        <img
                                                            alt="Tailwise - Admin Dashboard Template"
                                                            :src="
                                                                faker.images[0]
                                                            "
                                                        />
                                                    </div>
                                                    <div
                                                        class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-20"
                                                    >
                                                        <img
                                                            alt="Tailwise - Admin Dashboard Template"
                                                            :src="
                                                                faker.images[1]
                                                            "
                                                        />
                                                    </div>
                                                    <div
                                                        class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-20"
                                                    >
                                                        <img
                                                            alt="Tailwise - Admin Dashboard Template"
                                                            :src="
                                                                faker.images[2]
                                                            "
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                        <div
                                            class="mt-1.5 text-xs text-slate-500"
                                        >
                                            {{ faker.date }}
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </Slideover.Description>
            </Slideover.Panel>
        </Slideover>
    </div>
</template>
