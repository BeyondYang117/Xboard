<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import type { CaptchaPayload, GuestConfig } from "../types";
import { captchaGlobals, loadCaptcha } from "../captcha";
const props = defineProps<{ config: GuestConfig }>();
const container = ref<HTMLElement>();
const error = ref("");
const token = ref("");
let widget: string | number | undefined;
let disposed = false;
const globals = captchaGlobals();
let initialized = false;
const type = props.config.captcha_type || "recaptcha";
const key =
    type === "turnstile"
        ? props.config.turnstile_site_key
        : type === "recaptcha-v3"
          ? props.config.recaptcha_v3_site_key
          : props.config.recaptcha_site_key;
async function initialize() {
    try {
        if (!key) throw new Error("站点尚未配置验证码公钥，请联系管理员。");
        const api = await loadCaptcha(type, key);
        if (disposed) return;
        initialized = true;
        if (type === "recaptcha-v3" || !container.value) return;
        widget = api.render(container.value, {
            sitekey: key,
            size: "compact",
            callback: (value: string) => {
                token.value = value;
                error.value = "";
            },
            "expired-callback": () => {
                token.value = "";
            },
            "error-callback": () => {
                token.value = "";
                error.value = "验证失败，请刷新重试。";
            },
        });
    } catch (cause) {
        if (!disposed)
            error.value =
                cause instanceof Error ? cause.message : "验证码加载失败。";
    }
}
async function getPayload(): Promise<CaptchaPayload> {
    if (!props.config.is_captcha) return {};
    if (error.value) throw new Error(error.value);
    if (type === "recaptcha-v3") {
        if (!initialized || !key || !globals.grecaptcha?.execute)
            throw new Error("验证码仍在加载，请稍后重试。");
        const value = await globals.grecaptcha.execute(key, {
            action: "submit",
        });
        return { recaptcha_v3_token: value };
    }
    if (!token.value) throw new Error("请先完成人机验证。");
    return type === "turnstile"
        ? { turnstile_token: token.value }
        : { recaptcha_data: token.value };
}
function reset() {
    token.value = "";
    if (widget !== undefined)
        (type === "turnstile" ? globals.turnstile : globals.grecaptcha)?.reset(
            widget,
        );
}
defineExpose({ getPayload, reset });
onMounted(initialize);
onBeforeUnmount(() => {
    disposed = true;
    if (widget !== undefined && type === "turnstile")
        globals.turnstile?.remove?.(widget);
});
</script>
<template>
    <div class="captcha-container">
        <div ref="container"></div>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <p v-else-if="type === 'recaptcha-v3'" class="muted caption">
            此操作受 reCAPTCHA 保护。
        </p>
    </div>
</template>
