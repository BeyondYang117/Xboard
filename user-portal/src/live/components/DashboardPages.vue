<script setup lang="ts">
import { computed, onMounted } from "vue";
import {
    ArrowDownToLine,
    ArrowRight,
    ArrowUpRight,
    Bell,
    BookOpen,
    Clock3,
    Copy,
    Globe2,
    LifeBuoy,
    Monitor,
    ShieldCheck,
    Sparkles,
    Zap,
} from "@lucide/vue";
import { accountState, displayName, portal } from "../store";
import { aggregateTraffic, formatBytes, formatDate } from "../format";
import { httpUrl } from "../format";
import { portalConfig } from "../config";
import ResourceState from "./ResourceState.vue";
defineProps<{ page: "overview" | "subscription" }>();
defineEmits<{ copy: []; support: [] }>();
const sub = computed(() => portal.subscription.data!);
const cover = computed(() => httpUrl(portalConfig.coverImage));
const used = computed(() => Number(sub.value.u) + Number(sub.value.d));
const total = computed(() => Number(sub.value.transfer_enable));
const remaining = computed(() => Math.max(0, total.value - used.value));
const percent = computed(() =>
    total.value > 0 ? Math.min(100, (used.value / total.value) * 100) : 0,
);
const empty = computed(() => accountState.value === "empty");
const stateLabel = computed(
    () =>
        ({
            loading: "加载中",
            banned: "账户已停用",
            empty: "尚未订阅",
            expired: "订阅已到期",
            exhausted: "流量已用尽",
            expiring: "即将到期",
            active: "订阅有效",
        })[accountState.value],
);
const warning = computed(() =>
    ["expired", "exhausted", "expiring", "banned"].includes(accountState.value),
);
const resetLabel = computed(() =>
    sub.value.next_reset_at
        ? formatDate(sub.value.next_reset_at)
        : sub.value.reset_day != null
          ? `约 ${sub.value.reset_day} 天后`
          : "以套餐规则为准",
);
const traffic = computed(() => aggregateTraffic(portal.traffic.data ?? []));
const maxTraffic = computed(() =>
    Math.max(...traffic.value.map((day) => day.bytes), 1),
);
const monthlyUsage = computed(() =>
    traffic.value.reduce((sum, day) => sum + day.bytes, 0),
);
const month = new Date().getMonth() + 1;
onMounted(() => {
    if (!portal.traffic.data) void portal.traffic.load();
});
</script>
<template>
    <div class="page-heading">
        <div>
            <div class="eyebrow">
                {{
                    page === "overview"
                        ? "YOUR EVERYDAY CONNECTION"
                        : "YOUR SUBSCRIPTION"
                }}
            </div>
            <h1>
                {{ page === "overview" ? `你好，${displayName}` : "我的订阅"
                }}<span v-if="page === 'overview'" class="greeting-dot">✳</span>
            </h1>
            <p>
                {{
                    page === "overview"
                        ? "世界很大，连接可以很简单。"
                        : "额度、有效期与配置方式，清清楚楚。"
                }}
            </p>
        </div>
        <a class="button button-secondary" :href="empty ? '#plans' : '#guide'"
            ><Monitor :size="16" />{{ empty ? "选择套餐" : "连接新设备"
            }}<ArrowUpRight :size="15"
        /></a>
    </div>
    <div v-if="warning" class="alert alert-warning">
        <Clock3 :size="18" /><span
            >{{ stateLabel }}。{{
                accountState === "expiring"
                    ? "提前续费，让连接更从容。"
                    : accountState === "banned"
                      ? "如有疑问，请联系支持。"
                      : "查看订阅详情与可用套餐，恢复你的服务。"
            }}</span
        ><a href="#plans">查看套餐 <ArrowRight :size="15" /></a>
    </div>
    <section v-if="page === 'overview'" class="subscription-hero live-hero">
        <img
            v-if="cover"
            :src="cover"
            alt=""
            class="custom-cover"
            referrerpolicy="no-referrer"
        />
        <div class="hero-content">
            <div class="hero-kicker">
                <span class="status-dot"></span
                >{{ empty ? "开启你的第一段旅程" : "你的专属连接空间" }}
            </div>
            <h2>{{ empty ? "下一站，畅快连接。" : "准备好了，就出发。" }}</h2>
            <p>
                {{
                    empty
                        ? "选择适合你的套餐，让日常工作与探索更加从容。"
                        : "订阅、设备与使用指南，都在触手可及的地方。"
                }}
            </p>
            <div class="hero-buttons">
                <a
                    class="button button-brand"
                    :href="empty ? '#plans' : '#guide'"
                    ><ArrowDownToLine :size="17" />{{
                        empty ? "探索套餐" : "导入订阅"
                    }}<ArrowRight :size="16" /></a
                ><a
                    class="hero-secondary"
                    :href="empty ? '#help' : '#subscription'"
                    >{{ empty ? "了解如何使用" : "管理我的订阅"
                    }}<ArrowUpRight :size="15"
                /></a>
            </div>
            <div class="hero-footnote">
                <ShieldCheck :size="14" />你的订阅链接仅供自己使用，请妥善保管
            </div>
        </div>
        <div class="hero-scene" aria-hidden="true">
            <div class="scene-orbit"></div>
            <Globe2 :size="170" :stroke-width="0.45" /><span
                >EXPLORE YOUR WORLD</span
            >
        </div>
    </section>
    <section
        v-if="page === 'overview'"
        class="metrics-grid"
        aria-label="订阅概览"
    >
        <article class="metric-card">
            <div class="metric-top">
                <span>本期剩余流量</span
                ><span class="metric-icon"><Zap :size="17" /></span>
            </div>
            <div class="metric-value live-metric">
                {{ empty ? "—" : formatBytes(remaining) }}
            </div>
            <div class="usage-track">
                <span
                    :style="{ width: `${100 - percent}%` }"
                    :class="{ warning }"
                ></span>
            </div>
            <div class="metric-foot">
                {{
                    empty
                        ? "订阅后查看流量额度"
                        : `已用 ${formatBytes(used)} / 共 ${formatBytes(total)}`
                }}
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-top">
                <span>当前订阅</span
                ><span class="metric-icon"><Globe2 :size="17" /></span>
            </div>
            <div class="metric-value metric-plan live-plan-name">
                {{ empty ? "尚未订阅" : sub.plan?.name || "专属订阅" }}
            </div>
            <div class="metric-foot metric-foot-spaced">
                <span
                    ><span :class="['status-dot', { amber: warning }]"></span
                    >{{ stateLabel }}</span
                ><a href="#subscription"
                    >查看详情 <ArrowUpRight :size="13"
                /></a>
            </div>
        </article>
        <article class="metric-card">
            <div class="metric-top">
                <span>订阅到期日</span
                ><span class="metric-icon"><Clock3 :size="17" /></span>
            </div>
            <div class="metric-value metric-date">
                {{ empty ? "—" : formatDate(sub.expired_at) }}
            </div>
            <div class="metric-foot metric-foot-spaced">
                <span>{{
                    empty ? "有效期以购买套餐为准" : `流量重置：${resetLabel}`
                }}</span
                ><a href="#plans">续费 <ArrowUpRight :size="13" /></a>
            </div>
        </article>
    </section>
    <template v-if="page === 'overview'"
        ><div class="dashboard-columns">
            <section class="panel usage-panel">
                <div class="section-heading">
                    <div>
                        <h2>用量一目了然</h2>
                        <p>了解你的使用节奏，选择更适合的套餐。</p>
                    </div>
                    <span class="period-label">{{ month }} 月</span>
                </div>
                <ResourceState
                    :loading="portal.traffic.loading"
                    :error="portal.traffic.error"
                    @retry="portal.traffic.load"
                /><template v-if="portal.traffic.data && !portal.traffic.error"
                    ><div class="chart-summary">
                        <strong>{{ formatBytes(monthlyUsage) }}</strong
                        ><span>本月累计统计</span>
                    </div>
                    <div
                        v-if="monthlyUsage > 0"
                        class="bar-chart live-chart"
                        role="img"
                        :aria-label="`${month} 月每日计费流量，合计 ${formatBytes(monthlyUsage)}`"
                    >
                        <div class="bars">
                            <div
                                v-for="day in traffic"
                                :key="day.day"
                                class="bar"
                                :style="{
                                    height: `${Math.max(1, (day.bytes / maxTraffic) * 90)}%`,
                                }"
                                :title="`${month} 月 ${day.day} 日：${formatBytes(day.bytes)}`"
                            ></div>
                        </div>
                    </div>
                    <div v-else class="chart-empty">
                        <Globe2 :size="27" />
                        <p>本月暂无用量记录。</p>
                    </div>
                    <div class="chart-axis">
                        <span>{{ month }} 月 1 日</span
                        ><span>{{ month }} 月 {{ traffic.length }} 日</span>
                    </div>
                    <div class="chart-note">
                        <span class="status-dot"></span
                        >统计可能延迟更新，流量按线路倍率计费。<a
                            href="#subscription"
                            >查看详情 <ArrowUpRight :size="13"
                        /></a></div
                ></template>
            </section>
            <section class="panel quick-panel">
                <div class="section-heading">
                    <div>
                        <h2>连接，从这里开始</h2>
                        <p>常用操作，少找一步。</p>
                    </div>
                </div>
                <a class="quick-link" href="#guide"
                    ><span class="quick-icon green"
                        ><ArrowDownToLine :size="20" /></span
                    ><span
                        ><strong>下载与导入</strong
                        ><small>选择设备，完成客户端配置</small></span
                    ><ArrowUpRight :size="17" /></a
                ><a class="quick-link" href="#help"
                    ><span class="quick-icon blue"><BookOpen :size="20" /></span
                    ><span
                        ><strong>使用指南</strong
                        ><small>从第一次连接到日常使用</small></span
                    ><ArrowUpRight :size="17" /></a
                ><button class="quick-link" @click="$emit('support')">
                    <span class="quick-icon beige"><LifeBuoy :size="20" /></span
                    ><span
                        ><strong>遇到问题？</strong
                        ><small>查找解答，或联系我们</small></span
                    ><ArrowUpRight :size="17" />
                </button>
                <div class="help-note">
                    <ShieldCheck :size="16" />支持人员不会索取你的账号密码。
                </div>
            </section>
        </div>
        <section class="notice-strip">
            <span class="notice-icon"><Bell :size="17" /></span>
            <div>
                <strong>把重要提醒，留给重要的事</strong>
                <p>按需开启到期与流量提醒，让每次连接更从容。</p>
            </div>
            <a href="#account">管理提醒 <ArrowRight :size="15" /></a></section
    ></template>
    <div v-else-if="empty" class="panel empty-state">
        <Globe2 :size="42" />
        <h2>你的旅程，还未开始</h2>
        <p>选择适合自己的套餐，随后在引导中完成配置。</p>
        <a class="button button-brand" href="#plans"
            >探索套餐 <ArrowRight :size="16"
        /></a>
    </div>
    <template v-else
        ><section class="subscription-detail panel">
            <div>
                <span class="pill">{{ stateLabel }}</span>
                <h2>{{ sub.plan?.name || "专属订阅" }}</h2>
                <p>为日常工作与生活，留出更多连接的空间。</p>
            </div>
            <a class="button button-secondary" href="#plans"
                >续费 / 查看套餐 <ArrowUpRight :size="16"
            /></a>
            <div class="detail-stats">
                <div>
                    <small>剩余流量</small
                    ><strong>{{ formatBytes(remaining) }}</strong>
                </div>
                <div>
                    <small>本期流量额度</small
                    ><strong>{{ formatBytes(total) }}</strong>
                </div>
                <div>
                    <small>同时使用设备</small
                    ><strong
                        >{{ sub.device_limit ?? "不限"
                        }}<span v-if="sub.device_limit != null">
                            台</span
                        ></strong
                    >
                </div>
                <div>
                    <small>到期日期</small
                    ><strong class="date-stat">{{
                        formatDate(sub.expired_at)
                    }}</strong>
                </div>
            </div>
            <div class="usage-track large">
                <span
                    :style="{ width: `${percent}%` }"
                    :class="{ warning }"
                ></span>
            </div>
            <div class="usage-detail">
                <span>本期已用 {{ formatBytes(used) }}</span
                ><span>下次流量重置：{{ resetLabel }}</span>
            </div>
        </section>
        <div class="two-columns">
            <section class="panel padded">
                <div class="section-heading">
                    <h2>订阅配置</h2>
                    <ShieldCheck :size="18" class="brand-text" />
                </div>
                <p class="body-copy">
                    订阅链接是访问凭证。请仅导入自己的设备，避免公开分享。
                </p>
                <div class="masked-input">
                    <span>••••••••••••••••••••••••</span
                    ><span class="muted-tag">已隐藏</span>
                </div>
                <div class="inline-actions">
                    <a href="#guide" class="button button-brand"
                        ><ArrowDownToLine :size="16" />按设备导入</a
                    ><button
                        class="button button-secondary"
                        @click="$emit('copy')"
                    >
                        <Copy :size="16" />复制订阅地址
                    </button>
                </div>
            </section>
            <section class="panel padded">
                <h2>关于你的流量周期</h2>
                <p class="body-copy">
                    流量重置后，本期已用流量归零。订阅有效期和流量重置周期分别计算。
                </p>
                <div class="info-row">
                    <span>流量重置</span><strong>{{ resetLabel }}</strong>
                </div>
                <div class="info-row">
                    <span>速度限制</span
                    ><strong>{{
                        sub.speed_limit == null
                            ? "不限速"
                            : `${sub.speed_limit} Mbps`
                    }}</strong>
                </div>
                <a href="#help" class="text-button"
                    >查看使用说明 <ArrowRight :size="15"
                /></a>
            </section></div
    ></template>
</template>
