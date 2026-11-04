<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { LogOut, Moon, ShieldCheck, Sun } from "@lucide/vue";
import { api } from "../api";
import {
    displayName,
    initials,
    notify,
    portal,
    currencySymbol,
} from "../store";
import { money } from "../format";
const props = defineProps<{ theme: string }>();
defineEmits<{ theme: [value: string]; logout: [] }>();
const user = computed(() => portal.user.data!);
const saving = ref(false),
    error = ref("");
const telegramBusy = ref(false),
    telegramError = ref(""),
    bindingUrl = ref(""),
    bindingExpired = ref(false),
    confirmUnbind = ref(false);
let expiryTimer: ReturnType<typeof setTimeout> | undefined;
let active = true;
const telegramAvailable = computed(() =>
    Boolean(portal.site.data?.is_telegram),
);
function clearBindingLink() {
    clearTimeout(expiryTimer);
    bindingUrl.value = "";
    bindingExpired.value = false;
}
async function telegramAction(action: "create" | "refresh" | "unbind") {
    if (telegramBusy.value) return;
    const email = user.value.email;
    telegramBusy.value = true;
    telegramError.value = "";
    const current = () => active && portal.user.data?.email === email;
    try {
        if (action === "create") {
            clearBindingLink();
            const link = await api.telegramBindingLink();
            if (!current()) return;
            const url = new URL(link.url);
            if (
                url.protocol !== "https:" ||
                url.hostname !== "t.me" ||
                url.username ||
                url.password
            )
                throw new Error("绑定链接格式异常，请重试。");
            bindingUrl.value = url.href;
            const remaining = link.expires_at * 1000 - Date.now();
            bindingExpired.value = remaining <= 0;
            if (remaining > 0)
                expiryTimer = setTimeout(() => {
                    bindingExpired.value = true;
                }, remaining);
        } else if (action === "refresh") {
            const info = await api.user();
            if (!current()) return;
            portal.user.data = info;
            if (info.telegram_id) clearBindingLink();
        } else {
            await api.telegramUnbind();
            if (!current()) return;
            portal.user.data = { ...user.value, telegram_id: null };
            clearBindingLink();
            confirmUnbind.value = false;
            notify("Telegram 绑定已解除，未使用的绑定链接已失效。");
        }
    } catch (cause) {
        if (current()) telegramError.value = (cause as Error).message;
    } finally {
        if (active) telegramBusy.value = false;
    }
}
function refreshTelegramOnFocus() {
    if (bindingUrl.value) void telegramAction("refresh");
}
onMounted(() => window.addEventListener("focus", refreshTelegramOnFocus));
onBeforeUnmount(() => {
    active = false;
    clearTimeout(expiryTimer);
    window.removeEventListener("focus", refreshTelegramOnFocus);
});
async function toggle(key: "remind_expire" | "remind_traffic") {
    if (saving.value) return;
    saving.value = true;
    error.value = "";
    const expire =
        key === "remind_expire"
            ? Number(!user.value.remind_expire)
            : user.value.remind_expire;
    const traffic =
        key === "remind_traffic"
            ? Number(!user.value.remind_traffic)
            : user.value.remind_traffic;
    try {
        await api.updateReminders(expire, traffic);
        user.value.remind_expire = expire;
        user.value.remind_traffic = traffic;
        notify("提醒设置已保存。");
    } catch (cause) {
        error.value = (cause as Error).message;
    } finally {
        saving.value = false;
    }
}
</script>
<template>
    <div class="page-heading">
        <div>
            <div class="eyebrow">MAKE YOURSELF AT HOME</div>
            <h1>你的账户，你的偏好。</h1>
            <p>把常用设置，调整成舒服的样子。</p>
        </div>
    </div>
    <section class="panel padded account-panel">
        <div class="account-profile">
            <span class="avatar avatar-large">{{ initials }}</span>
            <div>
                <h2>{{ displayName }}</h2>
                <p>{{ user.email }}</p>
            </div>
        </div>
        <div class="settings-row linuxdo-account-row">
            <div class="linuxdo-account-details">
                <h3>Linux DO</h3>
                <template v-if="user.linuxdo_id">
                    <p class="linuxdo-account-name">
                        {{
                            user.linuxdo_username
                                ? `@${user.linuxdo_username}`
                                : "用户名暂未同步"
                        }}
                    </p>
                    <p>用户 ID：{{ user.linuxdo_id }}</p>
                </template>
                <p v-else-if="user.linuxdo_id === null">
                    尚未绑定。可退出后使用 Linux DO
                    登录，验证本站邮箱和密码完成绑定。
                </p>
                <p v-else>更新服务端后可查看绑定状态。</p>
            </div>
            <span v-if="user.linuxdo_id" class="pill">已绑定</span>
            <span v-else class="muted-tag">{{
                user.linuxdo_id === null ? "未绑定" : "暂不可用"
            }}</span>
        </div>
        <div class="settings-row telegram-account-row">
            <div class="telegram-account-details">
                <h3>Telegram</h3>
                <p v-if="user.telegram_id">
                    已绑定 · 用户 ID：{{ user.telegram_id }}
                </p>
                <p v-else>
                    绑定后可查询账户、接收提醒，并在 Telegram 中联系客服。
                </p>
                <p v-if="!telegramAvailable">站点暂未开启 Telegram Bot。</p>
                <p v-if="bindingUrl && !user.telegram_id">
                    {{
                        bindingExpired
                            ? "绑定链接已过期，请重新生成。"
                            : "链接 5 分钟内有效。打开 Telegram 后点击确认绑定，请勿转发。"
                    }}
                </p>
                <p v-if="confirmUnbind">
                    确认解除绑定？解绑后将停止接收 Telegram 提醒。
                </p>
                <p v-if="telegramError" class="field-error" role="alert">
                    {{ telegramError }}
                </p>
            </div>
            <div class="telegram-actions">
                <template v-if="confirmUnbind">
                    <button
                        class="button button-secondary"
                        :disabled="telegramBusy"
                        @click="telegramAction('unbind')"
                    >
                        确认解绑
                    </button>
                    <button
                        class="button button-ghost"
                        :disabled="telegramBusy"
                        @click="confirmUnbind = false"
                    >
                        取消
                    </button>
                </template>
                <template v-else-if="user.telegram_id">
                    <button
                        class="button button-secondary"
                        :disabled="telegramBusy"
                        @click="confirmUnbind = true"
                    >
                        解除绑定
                    </button>
                </template>
                <template v-else-if="telegramAvailable">
                    <button
                        class="button button-secondary"
                        :disabled="telegramBusy"
                        @click="telegramAction('create')"
                    >
                        {{
                            telegramBusy
                                ? "处理中…"
                                : bindingUrl
                                  ? "重新生成链接"
                                  : "绑定 Telegram"
                        }}
                    </button>
                    <template v-if="bindingUrl">
                        <a
                            v-if="!bindingExpired"
                            class="button button-primary"
                            :href="bindingUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            referrerpolicy="no-referrer"
                            >打开 Telegram</a
                        >
                        <button
                            class="button button-ghost"
                            :disabled="telegramBusy"
                            @click="telegramAction('refresh')"
                        >
                            刷新绑定状态
                        </button>
                        <button
                            class="button button-ghost"
                            :disabled="telegramBusy"
                            @click="telegramAction('unbind')"
                        >
                            撤销链接
                        </button>
                    </template>
                </template>
            </div>
        </div>
        <div class="settings-row">
            <div>
                <h3>账户余额</h3>
                <p>结算时由系统计算可抵扣金额。</p>
            </div>
            <strong>{{ money(user.balance, currencySymbol) }}</strong>
        </div>
        <div class="settings-row">
            <div>
                <h3>外观</h3>
                <p>选择适合你的明暗主题。</p>
            </div>
            <div class="tabs">
                <button
                    :class="{ selected: props.theme === 'light' }"
                    @click="$emit('theme', 'light')"
                >
                    <Sun :size="15" />浅色</button
                ><button
                    :class="{ selected: props.theme === 'dark' }"
                    @click="$emit('theme', 'dark')"
                >
                    <Moon :size="15" />深色
                </button>
            </div>
        </div>
        <div
            v-for="item in [
                {
                    key: 'remind_expire' as const,
                    title: '订阅到期提醒',
                    description:
                        '在订阅临近到期时接收邮件提醒；绑定 Telegram 后也可接收 Bot 提醒。',
                },
                {
                    key: 'remind_traffic' as const,
                    title: '剩余流量提醒',
                    description:
                        '在流量不足时接收邮件提醒；绑定 Telegram 后也可接收 Bot 提醒。',
                },
            ]"
            :key="item.key"
            class="settings-row"
        >
            <div>
                <h3>{{ item.title }}</h3>
                <p>{{ item.description }}</p>
            </div>
            <button
                class="switch"
                role="switch"
                :aria-checked="Boolean(user[item.key])"
                :aria-label="item.title"
                :disabled="saving"
                @click="toggle(item.key)"
            >
                <span></span>
            </button>
        </div>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <div class="account-note">
            <ShieldCheck :size="18" />
            <p>
                请妥善保管密码与订阅链接。退出登录会清除本设备的会话凭证，已导入的订阅仍需在客户端管理。
            </p>
        </div>
        <button
            class="button button-secondary logout-button"
            @click="$emit('logout')"
        >
            <LogOut :size="16" />退出登录
        </button>
    </section>
</template>
