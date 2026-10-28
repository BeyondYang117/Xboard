<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import { X } from "@lucide/vue";
defineProps<{ title: string }>();
const emit = defineEmits<{ close: [] }>();
const element = ref<HTMLDialogElement>();
let previousOverflow = "";
onMounted(async () => {
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    await nextTick();
    element.value?.showModal();
});
onBeforeUnmount(() => {
    element.value?.close();
    document.body.style.overflow = previousOverflow;
});
</script>
<template>
    <dialog
        ref="element"
        class="dialog"
        aria-labelledby="portal-dialog-title"
        @cancel.prevent="emit('close')"
        @click="$event.target === element && emit('close')"
    >
        <div class="dialog-header">
            <h2 id="portal-dialog-title">{{ title }}</h2>
            <button
                class="icon-button"
                aria-label="关闭弹窗"
                @click="emit('close')"
            >
                <X :size="20" />
            </button>
        </div>
        <div class="dialog-body"><slot /></div>
    </dialog>
</template>
