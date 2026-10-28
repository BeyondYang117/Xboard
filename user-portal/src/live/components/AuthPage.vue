<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { ArrowRight, Eye, EyeOff, ShieldCheck, Sun, Moon } from "@lucide/vue";
import { api, setAuthToken } from "../api";
import { httpUrl } from "../format";
import { portalBuildVersion, portalConfig } from "../config";
import type { GuestConfig } from "../types";
import CaptchaWidget from "./CaptchaWidget.vue";
import ResourceState from "./ResourceState.vue";
const props = defineProps<{ theme: string }>();
defineEmits<{ toggleTheme: [] }>();
const mode = ref<"login" | "register" | "forget">("login");
const email = ref(""),
    password = ref(""),
    emailCode = ref(""),
    inviteCode = ref(""),
    accepted = ref(false);
const visible = ref(false),
    busy = ref(false),
    sending = ref(false),
    error = ref(""),
    success = ref("");
const config = ref<GuestConfig | null>(null),
    configError = ref(""),
    configLoading = ref(true);
const captcha = ref<InstanceType<typeof CaptchaWidget>>();
const countdown = ref(0);
let timer: ReturnType<typeof setInterval>;
const needsCode = computed(
    () =>
        mode.value === "forget" ||
        (mode.value === "register" && config.value?.is_email_verify),
);
const tosUrl = computed(() => httpUrl(config.value?.tos_url));
const cover = computed(() => httpUrl(portalConfig.coverImage));
async function loadConfig() {
    configLoading.value = true;
    configError.value = "";
    try {
        config.value = await api.guestConfig();
    } catch (cause) {
        configError.value = (cause as Error).message;
    } finally {
        configLoading.value = false;
    }
}
async function sendCode() {
    if (sending.value || countdown.value) return;
    if (!email.value || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
        error.value = "请先填写正确的邮箱地址。";
        return;
    }
    sending.value = true;
    error.value = "";
    success.value = "";
    try {
        await api.verifyEmail(
            email.value.trim(),
            config.value?.is_captcha ? await captcha.value!.getPayload() : {},
        );
        countdown.value = 60;
        clearInterval(timer);
        timer = setInterval(() => {
            if (--countdown.value <= 0) clearInterval(timer);
        }, 1000);
        success.value = "验证码已发送，请查看邮箱。";
    } catch (cause) {
        error.value = (cause as Error).message;
    } finally {
        captcha.value?.reset();
        sending.value = false;
    }
}
async function submit() {
    if (busy.value) return;
    busy.value = true;
    error.value = "";
    success.value = "";
    try {
        if (mode.value === "forget") {
            await api.forget({
                email: email.value.trim(),
                password: password.value,
                email_code: emailCode.value,
            });
            password.value = "";
            mode.value = "login";
            success.value = "密码已更新，请使用新密码登录。";
            return;
        }
        if (mode.value === "register" && tosUrl.value && !accepted.value)
            throw new Error("请阅读并同意服务条款。");
        const data =
            mode.value === "login"
                ? await api.login({
                      email: email.value.trim(),
                      password: password.value,
                  })
                : await api.register({
                      email: email.value.trim(),
                      password: password.value,
                      email_code: emailCode.value,
                      invite_code: inviteCode.value,
                      ...(config.value?.is_captcha
                          ? await captcha.value!.getPayload()
                          : {}),
                  });
        if (!data.auth_data)
            throw new Error("登录响应缺少凭证，请联系管理员。");
        password.value = "";
        setAuthToken(data.auth_data);
    } catch (cause) {
        error.value = (cause as Error).message;
    } finally {
        captcha.value?.reset();
        busy.value = false;
    }
}
onMounted(loadConfig);
onBeforeUnmount(() => clearInterval(timer));
</script>
<template>
    <div class="auth-page">
        <div class="auth-art">
            <img
                v-if="cover"
                :src="cover"
                alt=""
                class="custom-cover"
                referrerpolicy="no-referrer"
            /><a class="brand" href="#overview"
                ><span class="brand-symbol"
                    ><span></span><span></span><span></span></span
                ><span
                    >{{ portalConfig.title
                    }}<span class="brand-dot">.</span></span
                ></a
            >
            <div class="auth-story">
                <div class="eyebrow">A NEW CHAPTER OF CONNECTION</div>
                <h1>世界很大，<br />从这里出发。</h1>
                <p>{{ portalConfig.description }}</p>
                <span><ShieldCheck :size="16" />一个账户，连接你的日常。</span>
            </div>
            <div class="auth-art-orbit"></div>
        </div>
        <div class="auth-form-wrap">
            <button
                class="icon-button auth-theme"
                :aria-label="theme === 'dark' ? '切换浅色外观' : '切换深色外观'"
                @click="$emit('toggleTheme')"
            >
                <component :is="theme === 'dark' ? Sun : Moon" :size="20" />
            </button>
            <div class="auth-form">
                <div class="eyebrow">
                    WELCOME TO {{ portalConfig.title.toUpperCase() }}
                </div>
                <h1>
                    {{
                        mode === "login"
                            ? "欢迎回来。"
                            : mode === "register"
                              ? "开启你的连接之旅。"
                              : "重新找回你的账户。"
                    }}
                </h1>
                <p class="auth-subtitle">
                    {{
                        mode === "login"
                            ? "登录后，继续你的探索。"
                            : mode === "register"
                              ? "创建账户，让每次连接更从容。"
                              : "使用邮箱验证码设置新密码。"
                    }}
                </p>
                <ResourceState
                    :loading="configLoading"
                    :error="configError"
                    @retry="loadConfig"
                />
                <form v-if="config" @submit.prevent="submit">
                    <label for="auth-email" class="form-label">邮箱地址</label
                    ><input
                        id="auth-email"
                        v-model="email"
                        type="email"
                        class="form-input"
                        autocomplete="username"
                        required
                        maxlength="255"
                        placeholder="you@example.com"
                        :disabled="busy"
                    /><template v-if="needsCode"
                        ><label class="form-label" for="auth-code"
                            >邮箱验证码</label
                        >
                        <div class="verification-row">
                            <input
                                id="auth-code"
                                v-model="emailCode"
                                class="form-input"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                required
                                placeholder="6 位验证码"
                            /><button
                                type="button"
                                class="button button-secondary"
                                :disabled="sending || busy || countdown > 0"
                                @click="sendCode"
                            >
                                {{
                                    countdown
                                        ? `${countdown}s 后重试`
                                        : sending
                                          ? "发送中…"
                                          : "发送验证码"
                                }}
                            </button>
                        </div></template
                    ><label for="auth-password" class="form-label">{{
                        mode === "forget" ? "新密码" : "密码"
                    }}</label>
                    <div class="password-field">
                        <input
                            id="auth-password"
                            v-model="password"
                            class="form-input"
                            :type="visible ? 'text' : 'password'"
                            :autocomplete="
                                mode === 'login'
                                    ? 'current-password'
                                    : 'new-password'
                            "
                            minlength="8"
                            required
                            placeholder="至少 8 个字符"
                            :disabled="busy"
                        /><button
                            class="icon-button"
                            type="button"
                            :aria-label="visible ? '隐藏密码' : '显示密码'"
                            @click="visible = !visible"
                        >
                            <component
                                :is="visible ? EyeOff : Eye"
                                :size="17"
                            />
                        </button>
                    </div>
                    <template v-if="mode === 'register'"
                        ><label class="form-label" for="invite-code"
                            >邀请码{{
                                config.is_invite_force ? "" : "（选填）"
                            }}</label
                        ><input
                            id="invite-code"
                            v-model="inviteCode"
                            class="form-input"
                            :required="Boolean(config.is_invite_force)"
                            maxlength="64"
                        /><label v-if="tosUrl" class="tos-row"
                            ><input
                                v-model="accepted"
                                type="checkbox"
                                required
                            />我已阅读并同意<a
                                :href="tosUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                >服务条款</a
                            ></label
                        ></template
                    ><CaptchaWidget
                        v-if="config.is_captcha && mode !== 'login'"
                        :key="mode"
                        ref="captcha"
                        :config="config"
                    />
                    <p v-if="error" class="field-error" role="alert">
                        {{ error }}
                    </p>
                    <p v-if="success" class="field-success" role="status">
                        {{ success }}
                    </p>
                    <button
                        class="button button-brand full-width auth-submit"
                        :disabled="busy || sending"
                        type="submit"
                    >
                        {{
                            busy
                                ? "正在处理…"
                                : mode === "login"
                                  ? "登录账户"
                                  : mode === "register"
                                    ? "创建账户"
                                    : "更新密码"
                        }}<ArrowRight :size="17" />
                    </button>
                    <div class="auth-links">
                        <button
                            v-if="mode !== 'login'"
                            type="button"
                            class="text-button"
                            @click="
                                mode = 'login';
                                error = '';
                                success = '';
                            "
                        >
                            返回登录</button
                        ><button
                            v-if="
                                mode === 'login' &&
                                portalConfig.allowRegistration
                            "
                            type="button"
                            class="text-button"
                            @click="
                                mode = 'register';
                                error = '';
                                success = '';
                            "
                        >
                            创建新账户</button
                        ><button
                            v-if="mode === 'login'"
                            type="button"
                            class="text-button"
                            @click="
                                mode = 'forget';
                                error = '';
                                success = '';
                            "
                        >
                            忘记密码？
                        </button>
                    </div>
                </form>
                <p class="auth-privacy">
                    请在自己的设备上登录，并妥善保管订阅链接。
                    <br />主题 v{{ portalBuildVersion }}
                </p>
            </div>
        </div>
    </div>
</template>
