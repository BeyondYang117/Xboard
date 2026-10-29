<script setup lang="ts">
import { computed, ref } from "vue";
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
                    description: '在订阅临近到期时接收邮件提醒。',
                },
                {
                    key: 'remind_traffic' as const,
                    title: '剩余流量提醒',
                    description: '在流量不足时接收邮件提醒。',
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
