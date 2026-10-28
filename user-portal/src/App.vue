<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from "vue";
import {
    ArrowDownToLine,
    ArrowRight,
    ArrowUpRight,
    Bell,
    BookOpen,
    Check,
    CheckCheck,
    ChevronDown,
    ChevronRight,
    CircleHelp,
    Clock3,
    Copy,
    CreditCard,
    ExternalLink,
    Globe2,
    LayoutDashboard,
    LifeBuoy,
    Monitor,
    Moon,
    Plus,
    Search,
    Settings2,
    ShieldCheck,
    Smartphone,
    Sparkles,
    Sun,
    Ticket,
    Wallet,
    X,
    Zap,
} from "@lucide/vue";
import {
    articles,
    clients,
    plans,
    subscription,
    usage,
    type Device,
    type Page,
    type Scenario,
} from "./data";

const navigation = [
    { id: "overview", label: "概览", icon: LayoutDashboard },
    { id: "subscription", label: "我的订阅", icon: Globe2 },
    { id: "plans", label: "套餐与账单", icon: Wallet },
    { id: "help", label: "帮助中心", icon: LifeBuoy },
] as const;
const titles: Record<Page, string> = {
    overview: "概览",
    subscription: "我的订阅",
    plans: "套餐与账单",
    guide: "开始使用",
    help: "帮助中心",
    account: "账户设置",
};
function readSaved(key: string, fallback: string) {
    try {
        return localStorage.getItem(key) ?? fallback;
    } catch {
        return fallback;
    }
}
function save(key: string, value: string) {
    try {
        localStorage.setItem(key, value);
    } catch {
        /* Storage can be unavailable in private browsing. */
    }
}
function pageFromHash(): Page {
    const value = location.hash.slice(1);
    return Object.hasOwn(titles, value) ? (value as Page) : "overview";
}
const page = ref<Page>(pageFromHash());
const scenario = ref<Scenario>("active");
const theme = ref(readSaved("xboard-preview-theme", "light"));
const skin = ref(readSaved("xboard-preview-skin", "anime"));
watch(
    skin,
    (value) => {
        document.documentElement.dataset.skin = value;
        save("xboard-preview-skin", value);
    },
    { immediate: true },
);
const devices: Device[] = ["Windows", "macOS", "iOS", "Android"];
const savedDevice = readSaved("xboard-preview-device", "macOS") as Device;
const device = ref<Device>(
    devices.includes(savedDevice) ? savedDevice : "macOS",
);
const client = computed(() => clients[device.value]);
const yearly = ref(false);
const tab = ref<"plans" | "bills">("plans");
const helpQuery = ref("");
const category = ref("全部文章");
const categories = ["全部文章", "开始使用", "订阅与流量", "连接排查"];
const filteredArticles = computed(() =>
    articles.filter(
        (a) =>
            (category.value === "全部文章" || a.category === category.value) &&
            `${a.title}${a.description}${a.body}`
                .toLowerCase()
                .includes(helpQuery.value.trim().toLowerCase()),
    ),
);
const isEmpty = computed(() => scenario.value === "empty");
const isExpiring = computed(() => scenario.value === "expiring");
const used = computed(() =>
    isEmpty.value ? 0 : isExpiring.value ? 188.6 : subscription.used,
);
const remaining = computed(() => +(subscription.total - used.value).toFixed(1));
const usagePercent = computed(() => (used.value / subscription.total) * 100);
const modal = ref<
    "checkout" | "article" | "ticket" | "notifications" | "import" | null
>(null);
const dialog = ref<HTMLDialogElement | null>(null);
const checkoutStage = ref<"confirm" | "complete">("confirm");
const selectedPlan = ref(plans[1]!);
const selectedArticle = ref(articles[0]!);
const ticketSubject = ref("");
const ticketBody = ref("");
const ticketDraft = ref<{ subject: string; body: string } | null>(null);
const reminders = ref(readSaved("xboard-preview-reminders", "on") === "on");
const toast = ref("");
let toastTimer: ReturnType<typeof setTimeout> | undefined;
const checkoutTotal = computed(
    () => selectedPlan.value.price * (yearly.value ? 10 : 1),
);
const modalTitle = computed(
    () =>
        ({
            checkout:
                checkoutStage.value === "complete"
                    ? "准备开始连接"
                    : "确认套餐",
            article: selectedArticle.value.title,
            ticket: "联系支持",
            notifications: "通知中心",
            import: "导入订阅",
        })[modal.value ?? "import"],
);
function notify(message: string) {
    clearTimeout(toastTimer);
    toast.value = message;
    toastTimer = setTimeout(() => {
        toast.value = "";
    }, 4500);
}
function focusMain() {
    document.getElementById("main-content")?.focus();
}
function syncRoute() {
    page.value = pageFromHash();
    window.scrollTo({ top: 0, behavior: "instant" });
}
function go(target: Page) {
    if (location.hash !== `#${target}`) location.hash = target;
    else syncRoute();
}
function openArticle(article: (typeof articles)[number]) {
    selectedArticle.value = article;
    modal.value = "article";
}
function checkout(plan: (typeof plans)[number]) {
    selectedPlan.value = plan;
    checkoutStage.value = "confirm";
    modal.value = "checkout";
}
function closeModal() {
    modal.value = null;
}
function saveTicket() {
    ticketDraft.value = {
        subject: ticketSubject.value.trim(),
        body: ticketBody.value.trim(),
    };
    closeModal();
    notify("工单草稿已保存在本次预览中，尚未发送。");
}
watch(
    theme,
    (value) => {
        document.documentElement.dataset.theme = value;
        save("xboard-preview-theme", value);
    },
    { immediate: true },
);
watch(device, (value) => save("xboard-preview-device", value));
watch(reminders, (value) =>
    save("xboard-preview-reminders", value ? "on" : "off"),
);
watch(page, (value) => {
    document.title = `${titles[value]} · Xboard 设计预览`;
});
watch(modal, async (value) => {
    await nextTick();
    if (value && dialog.value && !dialog.value.open) dialog.value.showModal();
    else if (!value && dialog.value?.open) dialog.value.close();
    document.body.style.overflow = value ? "hidden" : "";
});
onMounted(() => window.addEventListener("hashchange", syncRoute));
onBeforeUnmount(() => {
    window.removeEventListener("hashchange", syncRoute);
    clearTimeout(toastTimer);
    document.body.style.overflow = "";
});
</script>

<template>
    <a class="skip-link" href="#main-content" @click.prevent="focusMain"
        >跳到主要内容</a
    >
    <aside class="sidebar" aria-label="主导航">
        <a class="brand" href="#overview" aria-label="Xboard 首页"
            ><span class="brand-symbol"
                ><span></span><span></span><span></span></span
            ><span>Xboard<span class="brand-dot">.</span></span></a
        >
        <div class="workspace">
            <span class="workspace-icon"><Globe2 :size="18" /></span
            ><span>个人空间<small>订阅与设备</small></span
            ><span class="workspace-tag">个人版</span>
        </div>
        <div class="nav-caption">工作空间</div>
        <nav>
            <a
                v-for="item in navigation"
                :key="item.id"
                :href="`#${item.id}`"
                :class="[
                    'nav-item',
                    {
                        active:
                            page === item.id ||
                            (page === 'guide' && item.id === 'subscription'),
                    },
                ]"
                :aria-current="page === item.id ? 'page' : undefined"
                ><component :is="item.icon" :size="19" /><span>{{
                    item.label
                }}</span
                ><span v-if="item.id === 'help'" class="nav-count">5</span></a
            >
        </nav>
        <div class="sidebar-guide">
            <span class="tiny-label"><Sparkles :size="13" /> 从这里开始</span>
            <h3>第一次使用？</h3>
            <p>跟着引导，轻松完成配置。</p>
            <a href="#guide">查看使用指南 <ArrowUpRight :size="15" /></a>
            <div class="guide-orbit"></div>
        </div>
        <div class="sidebar-bottom">
            <button
                class="nav-item"
                @click="theme = theme === 'dark' ? 'light' : 'dark'"
            >
                <component
                    :is="theme === 'dark' ? Sun : Moon"
                    :size="18"
                /><span>{{
                    theme === "dark" ? "切换浅色外观" : "切换深色外观"
                }}</span></button
            ><a class="profile" href="#account"
                ><span class="avatar">A</span
                ><span>Alex<small>个人账户</small></span
                ><Settings2 :size="17"
            /></a>
        </div>
    </aside>

    <div class="app-body">
        <header class="topbar">
            <div class="breadcrumb">
                <span>工作空间</span><ChevronRight :size="14" /><strong>{{
                    titles[page]
                }}</strong>
            </div>
            <div class="topbar-actions">
                <label class="skin-picker"
                    >主题风格<select v-model="skin" aria-label="切换主题风格">
                        <option value="anime">星旅 · 日系</option>
                        <option value="gothic">夜航 · 哥特</option>
                        <option value="quiet">简洁原版</option>
                    </select></label
                >
                <label class="preview-selector"
                    ><span class="preview-dot"></span
                    ><span class="preview-label">设计预览</span
                    ><select v-model="scenario" aria-label="切换示例用户状态">
                        <option value="active">正常使用</option>
                        <option value="new">首次使用</option>
                        <option value="expiring">即将到期</option>
                        <option value="empty">尚未订阅</option></select
                    ><ChevronDown :size="12" /></label
                ><span class="header-divider"></span
                ><button
                    class="icon-button"
                    aria-label="查看通知"
                    @click="modal = 'notifications'"
                >
                    <Bell :size="19" /><span
                        class="notification-dot"
                    ></span></button
                ><a
                    class="avatar avatar-small"
                    href="#account"
                    aria-label="账户设置"
                    >A</a
                >
            </div>
        </header>

        <main id="main-content" class="main-content" tabindex="-1">
            <template v-if="page === 'overview'">
                <div class="page-heading">
                    <div>
                        <div class="eyebrow">YOUR EVERYDAY CONNECTION</div>
                        <h1>你好，Alex <span class="greeting-dot">✳</span></h1>
                        <p>世界很大，连接可以很简单。</p>
                    </div>
                    <a
                        class="button button-secondary heading-action"
                        href="#guide"
                        ><Monitor :size="16" /> 连接新设备
                        <ArrowUpRight :size="15"
                    /></a>
                </div>

                <div v-if="isExpiring" class="alert alert-warning">
                    <Clock3 :size="18" /><span
                        >示例订阅将于 3 天后到期，剩余流量不足
                        10%。续费前请确认套餐与计费周期。</span
                    ><button @click="go('plans')">
                        查看续费 <ArrowRight :size="15" />
                    </button>
                </div>
                <div v-if="scenario === 'new'" class="alert">
                    <Sparkles :size="18" /><span
                        >订阅已就绪。接下来，选择你的设备并完成客户端配置。</span
                    ><button @click="go('guide')">
                        开始配置 <ArrowRight :size="15" />
                    </button>
                </div>

                <section class="subscription-hero" aria-labelledby="hero-title">
                    <div class="hero-content">
                        <div class="hero-kicker">
                            <span class="status-dot"></span
                            >{{
                                isEmpty
                                    ? "开启你的第一段旅程"
                                    : "你的专属连接空间"
                            }}
                        </div>
                        <h2 id="hero-title">
                            {{
                                isEmpty
                                    ? "下一站，畅快连接。"
                                    : "准备好了，就出发。"
                            }}
                        </h2>
                        <p>
                            {{
                                isEmpty
                                    ? "选择适合你的套餐，让日常工作与探索更加从容。"
                                    : "订阅、设备与使用指南，都在触手可及的地方。"
                            }}
                        </p>
                        <div class="hero-buttons">
                            <button
                                class="button button-brand"
                                @click="go(isEmpty ? 'plans' : 'guide')"
                            >
                                <component
                                    :is="isEmpty ? Sparkles : ArrowDownToLine"
                                    :size="17"
                                />{{ isEmpty ? "探索套餐" : "导入订阅"
                                }}<ArrowRight :size="16" /></button
                            ><a
                                class="hero-secondary"
                                :href="isEmpty ? '#help' : '#subscription'"
                                >{{ isEmpty ? "了解如何使用" : "管理我的订阅"
                                }}<ArrowUpRight :size="15"
                            /></a>
                        </div>
                        <div class="hero-footnote">
                            <ShieldCheck :size="14" />
                            你的订阅链接仅供自己使用，请妥善保管
                        </div>
                    </div>
                    <div class="hero-art" aria-hidden="true">
                        <div class="orbit orbit-one"></div>
                        <div class="orbit orbit-two"></div>
                        <div class="globe">
                            <svg viewBox="0 0 240 240">
                                <defs>
                                    <radialGradient
                                        id="globe-shade"
                                        cx="32%"
                                        cy="28%"
                                        r="75%"
                                    >
                                        <stop offset="0" stop-color="#ddf0e8" />
                                        <stop offset="1" stop-color="#8bc7b5" />
                                    </radialGradient>
                                    <clipPath id="globe-clip">
                                        <circle cx="120" cy="120" r="105" />
                                    </clipPath>
                                </defs>
                                <circle
                                    cx="120"
                                    cy="120"
                                    r="105"
                                    fill="url(#globe-shade)"
                                />
                                <g
                                    clip-path="url(#globe-clip)"
                                    fill="none"
                                    stroke="#fff"
                                    stroke-opacity=".55"
                                    stroke-width=".8"
                                >
                                    <ellipse
                                        cx="120"
                                        cy="120"
                                        rx="40"
                                        ry="105"
                                    />
                                    <ellipse
                                        cx="120"
                                        cy="120"
                                        rx="79"
                                        ry="105"
                                    />
                                    <path
                                        d="M15 120h210M24 78h192M24 162h192M47 42h146M47 198h146M120 15v210"
                                    />
                                </g>
                                <path
                                    d="M43 92l25-25 26-3 11 16-13 18 9 19-14 12-6 38-13-8-8-34-16-11zM127 45l24 4 6 15 28 2 12 20-12 12-18-4-5 18-25 7-8-20-15-7 5-21zM140 128l26-9 17 19-13 24-19 21-9-23z"
                                    fill="#5b9e8a"
                                    opacity=".56"
                                />
                                <circle
                                    cx="120"
                                    cy="120"
                                    r="105"
                                    fill="none"
                                    stroke="#fff"
                                    stroke-opacity=".5"
                                />
                            </svg>
                        </div>
                        <div class="art-label label-top">
                            <span class="status-dot"></span> Stay connected
                        </div>
                        <div class="art-label label-bottom">
                            <ShieldCheck :size="15" /> 为每一段连接，用心
                        </div>
                        <span class="art-spark spark-one">+</span
                        ><span class="art-spark spark-two">+</span>
                    </div>
                </section>

                <section class="metrics-grid" aria-label="订阅概览">
                    <article class="metric-card">
                        <div class="metric-top">
                            <span>本期剩余流量</span
                            ><span class="metric-icon"><Zap :size="17" /></span>
                        </div>
                        <div class="metric-value">
                            {{ isEmpty ? "—" : remaining }}
                            <small v-if="!isEmpty">GB</small>
                        </div>
                        <div class="usage-track">
                            <span
                                :style="{
                                    width: isEmpty
                                        ? '0%'
                                        : `${100 - usagePercent}%`,
                                }"
                                :class="{ warning: isExpiring }"
                            ></span>
                        </div>
                        <div class="metric-foot">
                            {{
                                isEmpty
                                    ? "订阅后查看流量额度"
                                    : `已用 ${used} GB / 共 ${subscription.total} GB`
                            }}
                        </div>
                    </article>
                    <article class="metric-card">
                        <div class="metric-top">
                            <span>当前订阅</span
                            ><span class="metric-icon"
                                ><Globe2 :size="17"
                            /></span>
                        </div>
                        <div class="metric-value metric-plan">
                            {{ isEmpty ? "尚未订阅" : "Explorer"
                            }}<span v-if="!isEmpty" class="pill">探索者</span>
                        </div>
                        <div class="metric-foot metric-foot-spaced">
                            <span
                                ><span
                                    :class="[
                                        'status-dot',
                                        { amber: isEmpty || isExpiring },
                                    ]"
                                ></span
                                >{{
                                    isEmpty
                                        ? "等待开启"
                                        : isExpiring
                                          ? "即将到期"
                                          : "订阅有效"
                                }}</span
                            ><a :href="isEmpty ? '#plans' : '#subscription'"
                                >{{ isEmpty ? "选择套餐" : "查看权益"
                                }}<ChevronRight :size="13"
                            /></a>
                        </div>
                    </article>
                    <article class="metric-card">
                        <div class="metric-top">
                            <span>订阅到期日</span
                            ><span class="metric-icon"
                                ><Clock3 :size="17"
                            /></span>
                        </div>
                        <div class="metric-value metric-date">
                            {{
                                isEmpty
                                    ? "—"
                                    : isExpiring
                                      ? "2026.10.31"
                                      : "2026.11.28"
                            }}
                        </div>
                        <div class="metric-foot metric-foot-spaced">
                            <span>{{
                                isEmpty
                                    ? "有效期以购买套餐为准"
                                    : "流量重置于 11 月 1 日"
                            }}</span
                            ><a v-if="!isEmpty" href="#plans"
                                >续费<ChevronRight :size="13"
                            /></a>
                        </div>
                    </article>
                </section>

                <div class="dashboard-columns">
                    <section class="panel usage-panel">
                        <div class="section-heading">
                            <div>
                                <h2>
                                    用量一目了然
                                    <span class="muted-tag">示例数据</span>
                                </h2>
                                <p>了解你的使用节奏，选择更适合的套餐。</p>
                            </div>
                            <span class="period-label">2026 年 10 月</span>
                        </div>
                        <div class="chart-summary">
                            <strong
                                >{{ isEmpty ? "—" : used }}
                                <span v-if="!isEmpty">GB</span></strong
                            ><span>本月累计使用</span>
                        </div>
                        <div
                            v-if="!isEmpty"
                            class="bar-chart"
                            role="img"
                            aria-label="10 月每日流量示意图，柱高仅用于设计展示"
                        >
                            <div class="chart-grid">
                                <span>8 GB</span><span>4 GB</span><span>0</span>
                            </div>
                            <div class="bars">
                                <div
                                    v-for="(height, i) in usage"
                                    :key="i"
                                    :class="['bar', { highlighted: i === 25 }]"
                                    :style="{ height: `${height}%` }"
                                    :title="`10 月 ${i + 1} 日 · 示例用量`"
                                ></div>
                            </div>
                        </div>
                        <div v-else class="chart-empty">
                            <Globe2 :size="30" />
                            <p>开始订阅后，在这里查看用量变化。</p>
                        </div>
                        <div class="chart-axis">
                            <span>10 月 1 日</span><span>10 月 14 日</span
                            ><span>10 月 28 日</span>
                        </div>
                        <div class="chart-note">
                            <span class="status-dot"></span
                            >流量重置与订阅到期是两个独立日期。<a
                                href="#subscription"
                                >查看详情 <ArrowUpRight :size="13"
                            /></a>
                        </div>
                    </section>
                    <section class="panel quick-panel">
                        <div class="section-heading">
                            <div>
                                <h2>连接，从这里开始</h2>
                                <p>常用操作，少找一步。</p>
                            </div>
                            <ArrowUpRight :size="18" class="muted" />
                        </div>
                        <a class="quick-link" href="#guide"
                            ><span class="quick-icon green"
                                ><ArrowDownToLine :size="20" /></span
                            ><span
                                ><strong>下载与导入</strong
                                ><small>选择设备，完成客户端配置</small></span
                            ><ChevronRight :size="17" /></a
                        ><a class="quick-link" href="#help"
                            ><span class="quick-icon blue"
                                ><BookOpen :size="20" /></span
                            ><span
                                ><strong>使用指南</strong
                                ><small>从第一次连接到日常使用</small></span
                            ><ChevronRight :size="17" /></a
                        ><button class="quick-link" @click="modal = 'ticket'">
                            <span class="quick-icon beige"
                                ><LifeBuoy :size="20" /></span
                            ><span
                                ><strong>遇到问题？</strong
                                ><small>查找解答，或联系我们</small></span
                            ><ChevronRight :size="17" />
                        </button>
                        <div class="help-note">
                            <ShieldCheck :size="16" /><span
                                >支持人员不会索取你的账号密码。</span
                            >
                        </div>
                    </section>
                </div>
                <section class="notice-strip">
                    <span class="notice-icon"><Bell :size="17" /></span>
                    <div>
                        <strong>把重要提醒，留给重要的事</strong>
                        <p>按需开启到期与流量提醒，让每次连接更从容。</p>
                    </div>
                    <a href="#account">管理提醒 <ArrowRight :size="15" /></a>
                </section>
            </template>

            <template v-else-if="page === 'subscription'">
                <div class="page-heading">
                    <div>
                        <div class="eyebrow">YOUR SUBSCRIPTION</div>
                        <h1>我的订阅</h1>
                        <p>额度、有效期与配置方式，清清楚楚。</p>
                    </div>
                    <a
                        class="button button-brand"
                        :href="isEmpty ? '#plans' : '#guide'"
                        >{{ isEmpty ? "选择套餐" : "连接新设备"
                        }}<ArrowRight :size="16"
                    /></a>
                </div>
                <div v-if="isEmpty" class="panel empty-state">
                    <Globe2 :size="42" />
                    <h2>你的旅程，还未开始</h2>
                    <p>选择适合自己的套餐，随后在引导中完成配置。</p>
                    <a href="#plans" class="button button-brand"
                        >探索套餐 <ArrowRight :size="16"
                    /></a>
                </div>
                <template v-else
                    ><div class="subscription-detail panel">
                        <div>
                            <span class="pill">{{
                                isExpiring ? "即将到期" : "订阅有效"
                            }}</span>
                            <h2>Explorer <span>探索者</span></h2>
                            <p>为日常工作与生活，留出更多连接的空间。</p>
                        </div>
                        <a class="button button-secondary" href="#plans"
                            >续费 / 查看套餐 <ArrowUpRight :size="16"
                        /></a>
                        <div class="detail-stats">
                            <div>
                                <small>剩余流量</small
                                ><strong
                                    >{{ remaining }} <span>GB</span></strong
                                >
                            </div>
                            <div>
                                <small>流量额度</small
                                ><strong>200 <span>GB / 月</span></strong>
                            </div>
                            <div>
                                <small>同时使用设备</small
                                ><strong>5 <span>台</span></strong>
                            </div>
                            <div>
                                <small>到期日期</small
                                ><strong class="date-stat">{{
                                    isExpiring
                                        ? "2026-10-31"
                                        : subscription.expiresAt
                                }}</strong>
                            </div>
                        </div>
                        <div class="usage-track large">
                            <span
                                :style="{ width: `${usagePercent}%` }"
                                :class="{ warning: isExpiring }"
                            ></span>
                        </div>
                        <div class="usage-detail">
                            <span>本期已用 {{ used }} GB</span
                            ><span
                                >下次流量重置：{{ subscription.resetsAt }}</span
                            >
                        </div>
                    </div>
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
                                <span>••••••••••••••••••••••••••••</span
                                ><span class="muted-tag">预览中隐藏</span>
                            </div>
                            <div class="inline-actions">
                                <button
                                    class="button button-brand"
                                    @click="go('guide')"
                                >
                                    <ArrowDownToLine
                                        :size="16"
                                    />按设备导入</button
                                ><button
                                    class="button button-secondary"
                                    @click="modal = 'import'"
                                >
                                    <Copy :size="16" />复制订阅地址
                                </button>
                            </div>
                        </section>
                        <section class="panel padded">
                            <h2>关于你的流量周期</h2>
                            <p class="body-copy">
                                流量重置后，本期已用量归零。订阅到期后，需续费才能继续使用服务。
                            </p>
                            <div class="info-row">
                                <span>流量重置日</span
                                ><strong>2026 年 11 月 1 日</strong>
                            </div>
                            <div class="info-row">
                                <span>用量来源</span
                                ><strong>设计示例，非实时统计</strong>
                            </div>
                            <button
                                class="text-button"
                                @click="openArticle(articles[1]!)"
                            >
                                了解重置与到期的区别 <ArrowRight :size="15" />
                            </button>
                        </section></div
                ></template>
            </template>

            <template v-else-if="page === 'plans'">
                <div class="page-heading">
                    <div>
                        <div class="eyebrow">MADE FOR YOUR EVERYDAY</div>
                        <h1>刚刚好的连接，自由选择。</h1>
                        <p>从轻量浏览到高频使用，找到适合你的节奏。</p>
                    </div>
                </div>
                <div class="plans-toolbar">
                    <div class="tabs" aria-label="套餐与账单">
                        <button
                            :class="{ selected: tab === 'plans' }"
                            @click="tab = 'plans'"
                        >
                            订阅套餐</button
                        ><button
                            :class="{ selected: tab === 'bills' }"
                            @click="tab = 'bills'"
                        >
                            我的账单
                        </button>
                    </div>
                    <div
                        v-if="tab === 'plans'"
                        class="billing-toggle"
                        aria-label="计费周期"
                    >
                        <button
                            :class="{ selected: !yearly }"
                            @click="yearly = false"
                        >
                            月付</button
                        ><button
                            :class="{ selected: yearly }"
                            @click="yearly = true"
                        >
                            年付 <span>省 2 个月</span>
                        </button>
                    </div>
                </div>
                <template v-if="tab === 'plans'"
                    ><div class="plan-grid">
                        <article
                            v-for="plan in plans"
                            :key="plan.id"
                            :class="[
                                'plan-card',
                                { recommended: plan.id === 'explorer' },
                            ]"
                        >
                            <div
                                v-if="plan.id === 'explorer'"
                                class="recommend-label"
                            >
                                <Sparkles :size="13" />日常使用推荐
                            </div>
                            <div class="plan-name">{{ plan.name }}</div>
                            <h2>{{ plan.title }}</h2>
                            <p>{{ plan.description }}</p>
                            <div class="plan-price">
                                <span>¥</span
                                ><strong>{{
                                    yearly ? plan.price * 10 : plan.price
                                }}</strong
                                ><small>/ {{ yearly ? "年" : "月" }}</small>
                            </div>
                            <div class="price-note">
                                {{
                                    yearly
                                        ? `折合 ¥${((plan.price * 10) / 12).toFixed(2)} / 月，按年支付`
                                        : "按月支付，流量每周期重置"
                                }}
                            </div>
                            <button
                                :class="[
                                    'button',
                                    plan.id === 'explorer'
                                        ? 'button-brand'
                                        : 'button-secondary',
                                ]"
                                @click="checkout(plan)"
                            >
                                选择 {{ plan.name }} <ArrowRight :size="16" />
                            </button>
                            <div class="plan-divider"></div>
                            <ul class="feature-list">
                                <li
                                    v-for="feature in plan.features"
                                    :key="feature"
                                >
                                    <Check :size="16" />{{ feature }}
                                </li>
                            </ul>
                        </article>
                    </div>
                    <div class="plan-disclaimer">
                        <ShieldCheck :size="17" /><span
                            >套餐与价格均为设计示例。实际流量倍率、可用线路与续费规则需以正式服务说明为准。</span
                        >
                    </div>
                    <section class="panel padded plan-faq">
                        <h2>选择之前，你可能想了解</h2>
                        <details>
                            <summary>
                                年付套餐的流量一次性发放吗？<Plus :size="17" />
                            </summary>
                            <p>
                                本设计示例按周期重置流量，年付表示一次支付一年的服务费用。上线时需根据实际套餐规则明确说明。
                            </p>
                        </details>
                        <details>
                            <summary>
                                现在已有套餐，可以直接切换吗？<Plus
                                    :size="17"
                                />
                            </summary>
                            <p>
                                套餐切换可能涉及剩余价值折抵、流量和有效期变化。正式购买前，应在确认页展示实际计算结果，此原型不执行套餐变更。
                            </p>
                        </details>
                    </section></template
                >
                <section v-else class="panel bills-panel">
                    <div class="section-heading">
                        <h2>账单记录</h2>
                        <span class="muted-tag">示例账单</span>
                    </div>
                    <div v-if="isEmpty" class="empty-state">
                        <CreditCard :size="36" />
                        <h3>还没有账单</h3>
                        <p>购买订阅后，账单会显示在这里。</p>
                        <button
                            class="button button-brand"
                            @click="tab = 'plans'"
                        >
                            查看套餐
                        </button>
                    </div>
                    <template v-else
                        ><div class="bill-row bill-header">
                            <span>套餐 / 订单</span><span>日期</span
                            ><span>金额</span><span>状态</span>
                        </div>
                        <div class="bill-row">
                            <span
                                ><strong>Explorer 月付</strong
                                ><small>DEMO-20261028 · 设计示例</small></span
                            ><span>2026-10-28</span><strong>¥29.00</strong
                            ><span class="pill">已支付 · 示例</span>
                        </div>
                        <div class="bill-note">
                            此处仅展示账单布局，没有实际付款或交易记录。
                        </div></template
                    >
                </section>
            </template>

            <template v-else-if="page === 'guide'">
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
                <div v-if="isEmpty" class="alert alert-warning">
                    <CircleHelp :size="18" /><span
                        >你可以先了解配置流程。获取真实订阅链接需要先购买套餐。</span
                    ><button @click="go('plans')">
                        查看套餐 <ArrowRight :size="15" />
                    </button>
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
                            :class="[
                                'device-option',
                                { selected: device === item },
                            ]"
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
                            <p>
                                当前推荐：{{ client.name }}。请从官方来源获取。
                            </p>
                        </div>
                    </div>
                    <div class="client-box">
                        <span class="client-symbol"><Zap :size="24" /></span>
                        <div>
                            <strong>{{ client.name }}</strong>
                            <p>{{ client.note }}</p>
                        </div>
                        <a
                            :href="client.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="button button-secondary"
                            >前往下载 <ExternalLink :size="15"
                        /></a>
                    </div>
                    <div class="guide-divider"></div>
                    <div class="step-heading">
                        <span class="step-number">03</span>
                        <div>
                            <h2>导入订阅，开启连接</h2>
                            <p>
                                安装完成后，将订阅导入客户端，再选择线路并启用连接。
                            </p>
                        </div>
                    </div>
                    <div class="import-step">
                        <div class="inline-actions">
                            <button
                                class="button button-brand"
                                :disabled="isEmpty"
                                @click="modal = 'import'"
                            >
                                <ArrowDownToLine :size="16" />一键导入
                                {{ client.name }}</button
                            ><button
                                class="button button-secondary"
                                :disabled="isEmpty"
                                @click="modal = 'import'"
                            >
                                <Copy :size="16" />复制订阅地址
                            </button>
                        </div>
                        <p>
                            <ShieldCheck
                                :size="14"
                            />设计预览不提供真实订阅链接，也不会启动客户端。
                        </p>
                    </div>
                </section>
                <div class="guide-bottom">
                    <CheckCheck :size="21" />
                    <div>
                        <strong>导入完成后，还差一步</strong>
                        <p>
                            请在客户端启用连接并检查结果。遇到问题，可以查看<a
                                href="#help"
                                @click="category = '连接排查'"
                                >连接排查指南 <ArrowUpRight :size="12" /></a
                            >。
                        </p>
                    </div>
                </div>
            </template>

            <template v-else-if="page === 'help'">
                <div class="page-heading">
                    <div>
                        <div class="eyebrow">A LITTLE HELP GOES A LONG WAY</div>
                        <h1>让问题，到这里为止。</h1>
                        <p>从第一次使用到日常排查，答案就在这里。</p>
                    </div>
                    <button
                        class="button button-secondary"
                        @click="modal = 'ticket'"
                    >
                        <Ticket :size="16" />联系支持
                    </button>
                </div>
                <section class="help-search">
                    <Search :size="22" /><input
                        v-model="helpQuery"
                        type="search"
                        aria-label="搜索帮助文章"
                        placeholder="搜索问题，例如：导入订阅、流量重置…"
                    /><kbd>搜索</kbd>
                </section>
                <div class="category-tabs" aria-label="文章分类">
                    <button
                        v-for="item in categories"
                        :key="item"
                        :class="{ active: category === item }"
                        @click="category = item"
                    >
                        {{ item }}
                    </button>
                </div>
                <div class="article-grid">
                    <button
                        v-for="article in filteredArticles"
                        :key="article.id"
                        class="article-card panel"
                        @click="openArticle(article)"
                    >
                        <span class="article-category"
                            ><BookOpen :size="15" />{{ article.category }}</span
                        >
                        <h2>{{ article.title }}</h2>
                        <p>{{ article.description }}</p>
                        <span class="read-more"
                            >阅读指南 <ArrowUpRight :size="15"
                        /></span>
                    </button>
                </div>
                <div v-if="!filteredArticles.length" class="panel empty-state">
                    <Search :size="35" />
                    <h2>暂时没有找到相关内容</h2>
                    <p>试试更短的关键词，或联系我们。</p>
                    <button
                        class="button button-secondary"
                        @click="
                            helpQuery = '';
                            category = '全部文章';
                        "
                    >
                        清除筛选
                    </button>
                </div>
                <section class="support-banner">
                    <span class="support-icon"><LifeBuoy :size="28" /></span>
                    <div>
                        <h2>需要一点额外帮助？</h2>
                        <p>描述你的设备和遇到的问题，我们一起解决。</p>
                    </div>
                    <button
                        class="button button-secondary"
                        @click="modal = 'ticket'"
                    >
                        {{ ticketDraft ? "查看工单草稿" : "联系支持"
                        }}<ArrowRight :size="16" />
                    </button>
                </section>
                <div v-if="ticketDraft" class="draft-note">
                    <Ticket :size="16" /><span
                        >本次预览中的工单草稿：{{
                            ticketDraft.subject
                        }}（尚未发送）</span
                    >
                </div>
            </template>

            <template v-else-if="page === 'account'">
                <div class="page-heading">
                    <div>
                        <div class="eyebrow">MAKE YOURSELF AT HOME</div>
                        <h1>你的账户，你的偏好。</h1>
                        <p>把常用设置，调整成舒服的样子。</p>
                    </div>
                </div>
                <section class="panel padded account-panel">
                    <div class="account-profile">
                        <span class="avatar avatar-large">A</span>
                        <div>
                            <h2>Alex</h2>
                            <p>alex@example.com · 示例账户</p>
                        </div>
                        <span class="muted-tag">设计预览</span>
                    </div>
                    <div class="settings-row">
                        <div>
                            <h3>外观</h3>
                            <p>选择适合你的明暗主题。</p>
                        </div>
                        <div class="tabs">
                            <button
                                :class="{ selected: theme === 'light' }"
                                @click="theme = 'light'"
                            >
                                <Sun :size="15" />浅色</button
                            ><button
                                :class="{ selected: theme === 'dark' }"
                                @click="theme = 'dark'"
                            >
                                <Moon :size="15" />深色
                            </button>
                        </div>
                    </div>
                    <div class="settings-row">
                        <div>
                            <h3>到期与流量提醒</h3>
                            <p>当前仅在本浏览器保存偏好，不会发送通知。</p>
                        </div>
                        <button
                            class="switch"
                            role="switch"
                            :aria-checked="reminders"
                            aria-label="到期与流量提醒"
                            @click="reminders = !reminders"
                        >
                            <span></span>
                        </button>
                    </div>
                    <div class="settings-row">
                        <div>
                            <h3>常用设备</h3>
                            <p>为你优先展示对应客户端与配置说明。</p>
                        </div>
                        <select
                            v-model="device"
                            class="form-select"
                            aria-label="常用设备"
                        >
                            <option v-for="item in devices" :key="item">
                                {{ item }}
                            </option>
                        </select>
                    </div>
                    <div class="account-note">
                        <ShieldCheck :size="18" />
                        <p>
                            本预览不处理密码、登录凭证或真实订阅信息。账户安全与登录功能将在业务接入阶段实现。
                        </p>
                    </div>
                </section>
            </template>

            <footer class="footer">
                <span
                    >© 2026 Xboard<span class="footer-dot">·</span
                    >连接更多可能</span
                ><span class="footer-status"
                    ><span class="status-dot"></span>交互设计预览 ·
                    全部业务数据为示例</span
                ><a href="#help">帮助与支持 <ArrowUpRight :size="12" /></a>
            </footer>
        </main>
    </div>

    <nav class="mobile-nav" aria-label="移动端导航">
        <a
            v-for="item in navigation"
            :key="item.id"
            :href="`#${item.id}`"
            :class="{
                active:
                    page === item.id ||
                    (page === 'guide' && item.id === 'subscription'),
            }"
            :aria-current="page === item.id ? 'page' : undefined"
            ><component :is="item.icon" :size="20" /><span>{{
                item.id === "plans"
                    ? "套餐"
                    : item.id === "subscription"
                      ? "订阅"
                      : item.id === "help"
                        ? "帮助"
                        : item.label
            }}</span></a
        ><a
            href="#account"
            :class="{ active: page === 'account' }"
            :aria-current="page === 'account' ? 'page' : undefined"
            ><Settings2 :size="20" /><span>我的</span></a
        >
    </nav>

    <dialog
        ref="dialog"
        class="dialog"
        aria-labelledby="dialog-title"
        @cancel.prevent="closeModal"
        @close="modal = null"
        @click="$event.target === dialog && closeModal()"
    >
        <div class="dialog-header">
            <h2 id="dialog-title">{{ modalTitle }}</h2>
            <button
                class="icon-button"
                aria-label="关闭弹窗"
                @click="closeModal"
            >
                <X :size="20" />
            </button>
        </div>
        <div class="dialog-body">
            <template v-if="modal === 'checkout' && checkoutStage === 'confirm'"
                ><span class="pill">套餐选择预览</span>
                <h3 class="checkout-name">
                    {{ selectedPlan.name }} · {{ selectedPlan.title }}
                </h3>
                <div class="checkout-summary">
                    <div>
                        <span>计费周期</span
                        ><strong>{{
                            yearly ? "年付 · 12 个月" : "月付 · 1 个月"
                        }}</strong>
                    </div>
                    <div>
                        <span>每周期流量</span
                        ><strong>{{ selectedPlan.traffic }} GB / 月</strong>
                    </div>
                    <div>
                        <span>同时使用设备</span
                        ><strong>{{ selectedPlan.devices }} 台</strong>
                    </div>
                    <div class="checkout-total">
                        <span>{{ yearly ? "年度总价" : "本期总价" }}</span
                        ><strong>¥{{ checkoutTotal }}<small>.00</small></strong>
                    </div>
                </div>
                <p class="dialog-note">
                    此为交互预览，不会创建订单、变更订阅或扣款。正式结算需接入实际套餐、折抵规则与支付方式。
                </p>
                <button
                    class="button button-brand full-width"
                    @click="checkoutStage = 'complete'"
                >
                    预览购买完成页 <Check :size="16" /></button
            ></template>
            <template
                v-else-if="modal === 'checkout' && checkoutStage === 'complete'"
                ><div class="purchase-complete">
                    <span class="success-icon"><CheckCheck :size="30" /></span
                    ><span class="pill">购买完成页 · 示例</span>
                    <h3>订阅已就绪，开始连接吧。</h3>
                    <p>接下来，选择设备、安装客户端，再导入你的订阅。</p>
                </div>
                <div class="checkout-summary">
                    <div>
                        <span>已选示例套餐</span
                        ><strong
                            >{{ selectedPlan.name }} ·
                            {{ yearly ? "年付" : "月付" }}</strong
                        >
                    </div>
                    <div>
                        <span>配置步骤</span
                        ><strong>选择设备 → 安装 → 导入</strong>
                    </div>
                </div>
                <p class="dialog-note">
                    这是购买完成后的页面示例，没有创建订单或实际付款。下一步将进入首次使用示例。
                </p>
                <button
                    class="button button-brand full-width"
                    @click="
                        scenario = 'new';
                        closeModal();
                        go('guide');
                    "
                >
                    继续配置设备 <ArrowRight :size="16" /></button
            ></template>
            <template v-else-if="modal === 'article'"
                ><span class="article-category"
                    ><BookOpen :size="15" />{{ selectedArticle.category }}</span
                >
                <p class="article-body">{{ selectedArticle.body }}</p>
                <div class="dialog-footer">
                    <span>还需要帮助？</span
                    ><button class="text-button" @click="modal = 'ticket'">
                        联系支持 <ArrowRight :size="15" />
                    </button></div
            ></template>
            <form v-else-if="modal === 'ticket'" @submit.prevent="saveTicket">
                <p class="dialog-note">
                    描述问题前，可先查看帮助中心。此表单仅保存本次页面会话的草稿，不会发送工单。
                </p>
                <label class="form-label" for="ticket-subject">问题标题</label
                ><input
                    id="ticket-subject"
                    v-model="ticketSubject"
                    class="form-input"
                    placeholder="例如：macOS 导入后无法连接"
                    maxlength="80"
                    required
                    pattern=".*\S.*"
                /><label class="form-label" for="ticket-body">详细描述</label
                ><textarea
                    id="ticket-body"
                    v-model="ticketBody"
                    class="form-input"
                    rows="5"
                    placeholder="请填写设备系统、客户端版本、发生时间和错误提示。请勿填写密码或完整订阅链接。"
                    maxlength="2000"
                    required
                ></textarea
                ><button
                    class="button button-brand full-width"
                    :disabled="!ticketSubject.trim() || !ticketBody.trim()"
                    type="submit"
                >
                    保存工单草稿 <ArrowRight :size="16" />
                </button>
            </form>
            <template v-else-if="modal === 'notifications'"
                ><div class="notification-item">
                    <span class="quick-icon green"
                        ><Sparkles :size="20"
                    /></span>
                    <div>
                        <h3>欢迎来到新的用户空间</h3>
                        <p>
                            这里是交互设计原型，你可以体验套餐选择、设备引导和帮助搜索。
                        </p>
                        <small>设计示例</small>
                    </div>
                </div>
                <div v-if="isExpiring" class="notification-item">
                    <span class="quick-icon beige"><Clock3 :size="20" /></span>
                    <div>
                        <h3>订阅即将到期 · 示例</h3>
                        <p>你的示例订阅将于 2026 年 10 月 31 日到期。</p>
                        <button
                            class="text-button"
                            @click="
                                closeModal();
                                go('plans');
                            "
                        >
                            查看套餐 <ArrowRight :size="14" />
                        </button>
                    </div>
                </div>
                <div class="dialog-note">
                    正式通知将在后续接入账户状态与消息接口。
                </div></template
            >
            <template v-else-if="modal === 'import'"
                ><div class="import-placeholder">
                    <ShieldCheck :size="38" />
                    <h3>这里将安全地导入你的订阅</h3>
                    <p>
                        当前为设计预览，没有真实订阅链接。接入 Xboard
                        后，此处将根据所选客户端提供导入或复制操作。
                    </p>
                </div>
                <button
                    class="button button-brand full-width"
                    @click="
                        closeModal();
                        go('guide');
                    "
                >
                    返回设备配置指南 <ArrowRight :size="16" /></button
            ></template>
        </div>
    </dialog>
    <div
        class="toast-region"
        role="status"
        aria-live="polite"
        aria-atomic="true"
    >
        <div v-if="toast" class="toast"><Check :size="17" />{{ toast }}</div>
    </div>
</template>
