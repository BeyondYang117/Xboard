<script setup lang="ts">
import { LoaderCircle, RefreshCw, CircleAlert } from "@lucide/vue";
defineProps<{ loading?: boolean; error?: string }>();
defineEmits<{ retry: [] }>();
</script>
<template>
    <div v-if="loading" class="resource-state" role="status">
        <LoaderCircle :size="22" class="spin" /><span>正在加载，请稍候…</span>
    </div>
    <div v-else-if="error" class="resource-state resource-error" role="alert">
        <CircleAlert :size="22" />
        <p>{{ error }}</p>
        <button class="button button-secondary" @click="$emit('retry')">
            <RefreshCw :size="15" />重新加载
        </button>
    </div>
</template>
