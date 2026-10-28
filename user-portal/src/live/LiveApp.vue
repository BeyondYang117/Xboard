<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import {
    ArrowUpRight,
    Bell,
    BookOpen,
    ChevronRight,
    Globe2,
    LayoutDashboard,
    LifeBuoy,
    Moon,
    RefreshCw,
    Settings2,
    Sparkles,
    Sun,
    Wallet,
} from "@lucide/vue";
import { api, authToken, setAuthToken } from "./api";
import { portalConfig, readPreference, savePreference } from "./config";
import {
    accountState,
    bootstrapPortal,
    displayName,
    initials,
    notification,
    notify,
    portal,
    resetPortal,
} from "./store";
import { formatDate, httpUrl, safeRichText } from "./format";
import type { Page } from "../data";
import AuthPage from "./components/AuthPage.vue";
import DashboardPages from "./components/DashboardPages.vue";
import GuidePage from "./components/GuidePage.vue";
import PlansPage from "./components/PlansPage.vue";
import HelpPage from "./components/HelpPage.vue";
import AccountPage from "./components/AccountPage.vue";
import TicketsDialog from "./components/TicketsDialog.vue";
import PortalDialog from "./components/PortalDialog.vue";
import ResourceState from "./components/ResourceState.vue";

const titles: Record<Page, string> = {
    overview: "概览",
    subscription: "我的订阅",
    plans: "套餐与账单",
    guide: "开始使用",
    help: "帮助中心",
    account: "账户设置",
};
const navigation = [
    { id: "overview", label: "概览", icon: LayoutDashboard },
    { id: "subscription", label: "我的订阅", icon: Globe2 },
    { id: "plans", label: "套餐与账单", icon: Wallet },
    { id: "help", label: "帮助中心", icon: LifeBuoy },
] as const;
const page = ref<Page>("overview"),
    initialTrade = ref(""),
    booting = ref(false),
    verifying = ref(false),
    verifyError = ref("");
const support = ref(false),
    notices = ref(false),
    moreNotices = ref(false),
    noticeError = ref(""),
    noticePage = ref(1),
    expandedNotice = ref<number | null>(null);
const savedTheme = readPreference("appearance", portalConfig.appearance);
const theme = ref(
    savedTheme === "system"
        ? matchMedia("(prefers-color-scheme: dark)").matches
            ? "dark"
            : "light"
        : savedTheme === "dark"
          ? "dark"
          : "light",
);
const logo = computed(() => httpUrl(portalConfig.logo));
const essentialError = computed(
    () => portal.user.error || portal.subscription.error || portal.site.error,
);
const ready = computed(() =>
    Boolean(portal.user.data && portal.subscription.data && portal.site.data),
);
const supportUrl = computed(() => httpUrl(portalConfig.supportUrl));
const revoking = ref(false);
function route() {
    const value = location.hash.replace(/^#\/?/, "");
    const order = value.match(/^order\/([^/?#]+)/);
    if (order) {
        page.value = "plans";
        try {
            initialTrade.value = decodeURIComponent(order[1]!);
        } catch {
            initialTrade.value = "";
        }
    } else {
        const name = value.split("?")[0] || "overview";
        const aliases: Record<string, Page> = {
            dashboard: "overview",
            knowledge: "help",
            subscribe: "subscription",
            profile: "account",
            ticket: "help",
        };
        page.value = Object.hasOwn(titles, name)
            ? (name as Page)
            : aliases[name] || "overview";
        initialTrade.value = "";
    }
    window.scrollTo({ top: 0, behavior: "instant" });
}
function focusMain() {
    document.getElementById("main-content")?.focus();
}
async function bootstrap() {
    const token = authToken.value;
    booting.value = true;
    await bootstrapPortal();
    if (authToken.value === token) booting.value = false;
}
async function copySubscription() {
    const value = httpUrl(portal.subscription.data?.subscribe_url);
    if (!value) {
        notify("当前没有可复制的订阅地址，请先检查订阅状态。");
        return;
    }
    try {
        await navigator.clipboard.writeText(value);
        notify("订阅地址已复制，请仅在自己的设备上使用。");
    } catch {
        notify("浏览器未允许复制。请使用 HTTPS 或在客户端手动导入。");
    }
}
async function logout() {
    if (revoking.value) return;
    revoking.value = true;
    try {
        const result = await api.logout();
        if (authToken.value) return;
        notify(
            result === "revoked"
                ? "已退出登录。"
                : "已退出此设备。服务器会话未能撤销，请联系管理员检查服务。",
        );
    } finally {
        revoking.value = false;
    }
}
async function openNotices() {
    notices.value = true;
    noticePage.value = 1;
    noticeError.value = "";
    await portal.notices.load();
}
async function loadMoreNotices() {
    if (moreNotices.value) return;
    moreNotices.value = true;
    noticeError.value = "";
    try {
        const result = await api.notices(noticePage.value + 1);
        if (portal.notices.data) {
            portal.notices.data = {
                total: result.total,
                data: [...portal.notices.data.data, ...result.data],
            };
            noticePage.value++;
        }
    } catch (cause) {
        noticeError.value = (cause as Error).message;
    } finally {
        moreNotices.value = false;
    }
}
watch(
    theme,
    (value) => {
        document.documentElement.dataset.theme = value;
        savePreference("appearance", value);
    },
    { immediate: true },
);
watch(
    page,
    (value) => {
        document.title = `${titles[value]} · ${portalConfig.title}`;
    },
    { immediate: true },
);
watch(
    authToken,
    (value) => {
        resetPortal();
        support.value = false;
        notices.value = false;
        if (value) void bootstrap();
        else booting.value = false;
    },
    { immediate: true },
);
onMounted(async () => {
    route();
    window.addEventListener("hashchange", route);
    const verify = new URLSearchParams(location.hash.split("?")[1] || "").get(
        "verify",
    );
    if (verify) {
        verifying.value = true;
        history.replaceState(
            null,
            "",
            `${location.pathname}${location.search}#overview`,
        );
        try {
            const result = await api.tokenLogin(verify);
            if (!result.auth_data) throw new Error("登录链接返回异常。");
            setAuthToken(result.auth_data);
        } catch (cause) {
            verifyError.value = (cause as Error).message;
        } finally {
            verifying.value = false;
        }
    }
});
onBeforeUnmount(() => window.removeEventListener("hashchange", route));
document.documentElement.dataset.skin = portalConfig.visualStyle;
document.documentElement.dataset.accent = portalConfig.accent;
</script>
<template>
    <div v-if="verifying" class="full-screen-state">
        <ResourceState loading />
    </div>
    <template v-else-if="!authToken"
        ><div v-if="verifyError" class="auth-link-error" role="alert">
            登录链接未能验证：{{ verifyError }}。请重新登录。
        </div>
        <AuthPage
            :theme="theme"
            @toggle-theme="theme = theme === 'dark' ? 'light' : 'dark'"
    /></template>
    <template v-else
        ><a class="skip-link" href="#main-content" @click.prevent="focusMain"
            >跳到主要内容</a
        >
        <aside class="sidebar" aria-label="主导航">
            <a
                href="#overview"
                class="brand"
                :aria-label="`${portalConfig.title} 首页`"
                ><img v-if="logo" :src="logo" alt="" class="brand-logo" /><span
                    v-else
                    class="brand-symbol"
                    ><span></span><span></span><span></span></span
                ><span
                    >{{ portalConfig.title
                    }}<span class="brand-dot">.</span></span
                ></a
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
                                (page === 'guide' &&
                                    item.id === 'subscription'),
                        },
                    ]"
                    :aria-current="page === item.id ? 'page' : undefined"
                    ><component :is="item.icon" :size="19" /><span>{{
                        item.label
                    }}</span></a
                >
            </nav>
            <div class="sidebar-guide">
                <span class="tiny-label"
                    ><Sparkles :size="13" />从这里开始</span
                >
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
                    ><span class="avatar">{{ initials }}</span
                    ><span class="profile-name"
                        >{{ displayName }}<small>个人账户</small></span
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
                    <a class="header-guide-link" href="#guide"
                        ><BookOpen :size="14" /><span>使用指南</span></a
                    ><span class="header-divider"></span
                    ><button
                        class="icon-button"
                        aria-label="查看公告"
                        @click="openNotices"
                    >
                        <Bell :size="19" /></button
                    ><a
                        class="avatar avatar-small"
                        href="#account"
                        aria-label="账户设置"
                        >{{ initials }}</a
                    >
                </div>
            </header>
            <main id="main-content" class="main-content" tabindex="-1">
                <ResourceState
                    v-if="booting || essentialError"
                    :loading="booting"
                    :error="essentialError"
                    @retry="bootstrap"
                /><template v-if="ready && !booting && !essentialError"
                    ><DashboardPages
                        v-if="page === 'overview' || page === 'subscription'"
                        :key="page"
                        :page="page"
                        @copy="copySubscription"
                        @support="support = true" /><GuidePage
                        v-else-if="page === 'guide'"
                        @copy="copySubscription" /><PlansPage
                        v-else-if="page === 'plans'"
                        :initial-trade="initialTrade" /><HelpPage
                        v-else-if="page === 'help'"
                        @support="support = true" /><AccountPage
                        v-else-if="page === 'account'"
                        :theme="theme"
                        @theme="theme = $event"
                        @logout="logout"
                /></template>
                <footer class="footer">
                    <span
                        >© {{ new Date().getFullYear() }} {{ portalConfig.title
                        }}<span class="footer-dot">·</span
                        >{{ portalConfig.description }}</span
                    ><span
                        v-if="portal.subscription.updatedAt"
                        class="footer-status"
                        >账户数据更新于
                        {{
                            new Date(
                                portal.subscription.updatedAt,
                            ).toLocaleTimeString("zh-CN", {
                                hour: "2-digit",
                                minute: "2-digit",
                            })
                        }}</span
                    ><a
                        v-if="supportUrl"
                        :href="supportUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        >联系支持 <ArrowUpRight :size="12" /></a
                    ><a v-else href="#help"
                        >帮助与支持 <ArrowUpRight :size="12"
                    /></a>
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
            ><a href="#account" :class="{ active: page === 'account' }"
                ><Settings2 :size="20" /><span>我的</span></a
            >
        </nav>
        <TicketsDialog v-if="support" @close="support = false" /><PortalDialog
            v-if="notices"
            title="公告与消息"
            @close="notices = false"
            ><ResourceState
                :loading="portal.notices.loading"
                :error="portal.notices.error"
                @retry="openNotices"
            />
            <div
                v-if="portal.notices.data?.data.length === 0"
                class="empty-state compact"
            >
                <Bell :size="30" />
                <p>暂时没有新的公告。</p>
            </div>
            <article
                v-for="notice in portal.notices.data?.data"
                :key="notice.id"
                class="notice-article"
            >
                <button
                    @click="
                        expandedNotice =
                            expandedNotice === notice.id ? null : notice.id
                    "
                >
                    <strong>{{ notice.title }}</strong
                    ><small>{{ formatDate(notice.created_at) }}</small
                    ><ChevronRight :size="16" />
                </button>
                <div
                    v-if="expandedNotice === notice.id"
                    class="rich-text"
                    v-html="safeRichText(notice.content)"
                ></div>
            </article>
            <p v-if="noticeError" class="field-error" role="alert">
                {{ noticeError }}
            </p>
            <button
                v-if="
                    portal.notices.data &&
                    portal.notices.data.data.length < portal.notices.data.total
                "
                class="button button-secondary full-width"
                :disabled="moreNotices"
                @click="loadMoreNotices"
            >
                <RefreshCw :size="14" />{{
                    moreNotices ? "加载中…" : "加载更多公告"
                }}
            </button></PortalDialog
        ></template
    >
    <div
        class="toast-region"
        role="status"
        aria-live="polite"
        aria-atomic="true"
    >
        <div v-if="notification" class="toast">{{ notification }}</div>
    </div>
</template>
