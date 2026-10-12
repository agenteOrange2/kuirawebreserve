<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Lucide from '@/components/Base/Lucide';
import BookingModal from './BookingModal.vue';

export interface GalleryPhoto {
    id: number;
    url: string;
    thumb_url: string;
}

/**
 * Foto grande + miniaturas, y al tocar la grande un visor a pantalla con
 * flechas (y teclado). Antes las fotos solo se veían en una franja de 288px
 * sin forma de agrandarlas. Sin fotos, zona visual con el icono: que el
 * paso no arranque en texto pelón.
 */
const props = withDefaults(
    defineProps<{
        photos: GalleryPhoto[];
        alt: string;
        height?: 'md' | 'lg';
        placeholderIcon?: 'BedDouble' | 'Compass' | 'ShoppingBag';
    }>(),
    { height: 'lg', placeholderIcon: 'BedDouble' },
);

const index = ref(0);
const viewer = ref(false);

watch(
    () => props.photos,
    () => (index.value = 0),
);

const current = computed(
    () => props.photos[Math.min(index.value, props.photos.length - 1)],
);

function go(step: number) {
    const n = props.photos.length;
    if (!n) return;
    index.value = (index.value + step + n) % n;
}

function onKey(e: KeyboardEvent) {
    if (!viewer.value) return;
    if (e.key === 'ArrowRight') go(1);
    if (e.key === 'ArrowLeft') go(-1);
}

watch(viewer, (open) => {
    if (open) window.addEventListener('keydown', onKey);
    else window.removeEventListener('keydown', onKey);
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const arrow =
    'absolute top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 text-white backdrop-blur-sm transition hover:bg-black/65';
</script>

<template>
    <div>
        <div
            class="relative w-full overflow-hidden rounded-xl bg-slate-100"
            :class="
                !photos.length
                    ? 'h-28 sm:h-36'
                    : height === 'lg'
                      ? 'h-56 sm:h-80'
                      : 'h-44 sm:h-56'
            "
        >
            <template v-if="current">
                <button
                    type="button"
                    class="block h-full w-full"
                    :title="`Ver fotos de ${alt}`"
                    @click="viewer = true"
                >
                    <img
                        :src="current.url"
                        :alt="alt"
                        class="h-full w-full object-cover"
                    />
                </button>
                <template v-if="photos.length > 1">
                    <button
                        type="button"
                        :class="arrow"
                        class="left-2"
                        aria-label="Foto anterior"
                        @click="go(-1)"
                    >
                        <Lucide icon="ChevronLeft" class="h-5 w-5" />
                    </button>
                    <button
                        type="button"
                        :class="arrow"
                        class="right-2"
                        aria-label="Foto siguiente"
                        @click="go(1)"
                    >
                        <Lucide icon="ChevronRight" class="h-5 w-5" />
                    </button>
                </template>
                <button
                    type="button"
                    class="absolute right-2 bottom-2 inline-flex items-center gap-1.5 rounded-full bg-black/55 px-3 py-1.5 text-xs font-medium text-white backdrop-blur-sm"
                    @click="viewer = true"
                >
                    <Lucide icon="Expand" class="h-3.5 w-3.5" />
                    {{
                        photos.length > 1
                            ? `${index + 1} de ${photos.length}`
                            : 'Ampliar'
                    }}
                </button>
            </template>
            <div v-else class="flex h-full w-full items-center justify-center">
                <Lucide
                    :icon="placeholderIcon"
                    class="h-12 w-12 text-slate-300"
                />
            </div>
        </div>

        <div
            v-if="photos.length > 1"
            class="mt-2 flex gap-2 overflow-x-auto pb-1"
        >
            <button
                v-for="(photo, i) in photos"
                :key="photo.id"
                type="button"
                class="h-14 w-20 shrink-0 overflow-hidden rounded-lg border-2 transition"
                :class="
                    i === index
                        ? 'border-primary'
                        : 'border-transparent opacity-70 hover:opacity-100'
                "
                :aria-label="`Foto ${i + 1}`"
                @click="index = i"
            >
                <img
                    :src="photo.thumb_url"
                    alt=""
                    class="h-full w-full object-cover"
                    loading="lazy"
                />
            </button>
        </div>

        <BookingModal
            :open="viewer"
            :title="alt"
            :subtitle="
                photos.length > 1
                    ? `Foto ${index + 1} de ${photos.length}`
                    : undefined
            "
            icon="Images"
            width="xl"
            flush
            @close="viewer = false"
        >
            <div v-if="current" class="relative bg-black">
                <img
                    :src="current.url"
                    :alt="alt"
                    class="mx-auto max-h-[70dvh] w-full object-contain"
                />
                <template v-if="photos.length > 1">
                    <button
                        type="button"
                        :class="arrow"
                        class="left-3"
                        aria-label="Foto anterior"
                        @click="go(-1)"
                    >
                        <Lucide icon="ChevronLeft" class="h-5 w-5" />
                    </button>
                    <button
                        type="button"
                        :class="arrow"
                        class="right-3"
                        aria-label="Foto siguiente"
                        @click="go(1)"
                    >
                        <Lucide icon="ChevronRight" class="h-5 w-5" />
                    </button>
                </template>
            </div>
            <div
                v-if="photos.length > 1"
                class="flex gap-2 overflow-x-auto px-4 py-3"
            >
                <button
                    v-for="(photo, i) in photos"
                    :key="photo.id"
                    type="button"
                    class="h-12 w-16 shrink-0 overflow-hidden rounded-md border-2 transition"
                    :class="
                        i === index
                            ? 'border-primary'
                            : 'border-transparent opacity-60 hover:opacity-100'
                    "
                    @click="index = i"
                >
                    <img
                        :src="photo.thumb_url"
                        alt=""
                        class="h-full w-full object-cover"
                        loading="lazy"
                    />
                </button>
            </div>
        </BookingModal>
    </div>
</template>
