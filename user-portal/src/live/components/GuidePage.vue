<script setup lang="ts">
import { computed, ref, watch } from "vue";
import {
    ArrowDownToLine,
    ArrowRight,
    Check,
    CheckCheck,
    CircleHelp,
    Copy,
    ExternalLink,
    Monitor,
    ShieldCheck,
    Smartphone,
    Zap,
} from "@lucide/vue";
import type { Device } from "../../data";
import { accountState, notify, portal } from "../store";
import { portalConfig, readPreference, savePreference } from "../config";
import { httpUrl } from "../format";
import { clientImportUrl, resolveClient } from "../clients";
const emit = defineEmits<{ copy: [] }>();
const devices: Device[] = ["Windows", "macOS", "iOS", "Android"];
const saved = readPreference("device", "macOS") as Device;
const device = ref<Device>(devices.includes(saved) ? saved : "macOS");
watch(device, (value) => savePreference("device", value));
const client = computed(() =>
    resolveClient(device.value, portal.site.data, portalConfig.clients),
);
const importUrl = computed(() =>
    clientImportUrl(
        device.value,
        client.value.name,
        portal.subscription.data?.subscribe_url || "",
        portalConfig.title,
    ),
);
const canImport = computed(
    () =>
        !["empty", "banned", "expired", "exhausted", "loading"].includes(
            accountState.value,
        ) && Boolean(httpUrl(portal.subscription.data?.subscribe_url)),
);
function importSubscription() {
    if (!canImport.value || !importUrl.value) return;
    window.location.assign(importUrl.value);
    notify("已请求客户端导入，请在客户端确认，并启用连接。");
}
</script>
<template>
    <div class="page-heading">
        <div>
            <div class="eyebrow">LET’S GET YOU CONNECTED</div>
            <h1>下一步，连接你的世界。</h1>
            <p>选择设备，跟着步骤完成配置。</p>
        </div>
        <a href="#help" class="button button-secondary"
            ><CircleHelp :size="16" />需要帮助？</a
        >
    </div>
    <div v-if="!canImport" class="alert alert-warning">
        <CircleHelp :size="18" /><span
            >当前订阅暂不可用。你可以先安装客户端，再查看套餐与订阅状态。</span
        ><a href="#plans">查看套餐 <ArrowRight :size="15" /></a>
    </div>
    <section class="panel guide-panel">
        <div class="step-heading">
            <span class="step-number">01</span>
            <div>
                <h2>你想在哪台设备上使用？</h2>
                <p>我们会记住你的选择，方便下次配置。</p>
            </div>
        </div>
        <div class="device-grid">
            <button
                v-for="item in devices"
                :key="item"
                :class="['device-option', { selected: device === item }]"
                :aria-pressed="device === item"
                @click="device = item"
            >
                <component
                    :is="
                        item === 'iOS' || item === 'Android'
                            ? Smartphone
                            : Monitor
                    "
                    :size="28"
                /><strong>{{ item }}</strong
                ><Check
                    v-if="device === item"
                    :size="15"
                    class="device-check"
                />
            </button>
        </div>
        <div class="guide-divider"></div>
        <div class="step-heading">
            <span class="step-number">02</span>
            <div>
                <h2>安装兼容客户端</h2>
                <p>当前推荐：{{ client.name }}。</p>
            </div>
        </div>
        <div class="client-box">
            <span class="client-symbol"><Zap :size="24" /></span>
            <div>
                <strong>{{ client.name }}</strong>
                <p>{{ client.note }}</p>
            </div>
            <a
                v-if="client.url"
                :href="client.url"
                target="_blank"
                rel="noopener noreferrer"
                class="button button-secondary"
                >前往下载 <ExternalLink :size="15"
            /></a>
        </div>
        <p v-if="!client.url" class="body-copy">
            下载地址暂不可用，请联系支持获取客户端。
        </p>
        <div class="guide-divider"></div>
        <div class="step-heading">
            <span class="step-number">03</span>
            <div>
                <h2>导入订阅，开启连接</h2>
                <p>
                    {{
                        importUrl
                            ? "安装完成后，将订阅导入客户端，再选择线路并启用连接。"
                            : `安装完成后，复制订阅地址，在 ${client.name} 中添加订阅，再更新订阅并启用连接。`
                    }}
                </p>
            </div>
        </div>
        <div class="import-step">
            <div class="inline-actions">
                <button
                    v-if="importUrl"
                    class="button button-brand"
                    :disabled="!canImport"
                    @click="importSubscription"
                >
                    <ArrowDownToLine :size="16" />一键导入
                    {{ client.name }}</button
                ><button
                    class="button button-secondary"
                    :disabled="!canImport"
                    @click="emit('copy')"
                >
                    <Copy :size="16" />复制订阅地址
                </button>
            </div>
            <p>
                <ShieldCheck :size="14" />{{
                    importUrl
                        ? "链接仅供自己使用。若未唤起客户端，可复制地址后手动导入。"
                        : "链接仅供自己使用。复制后请在客户端中手动添加订阅。"
                }}
            </p>
        </div>
    </section>
    <div class="guide-bottom">
        <CheckCheck :size="21" />
        <div>
            <strong>导入完成后，还差一步</strong>
            <p>
                请在客户端启用连接并检查结果。遇到问题，可以查看<a href="#help"
                    >连接排查指南</a
                >。
            </p>
        </div>
    </div>
</template>
