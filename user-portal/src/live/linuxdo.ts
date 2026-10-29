import { ref } from "vue";
import { api, setAuthToken } from "./api";

const verifierKey = "xboard-portal-linuxdo-verifier";
export const linuxDoPending = ref<{
    ticket: string;
    verifier: string;
    username: string;
} | null>(null);

export function clearLinuxDo() {
    linuxDoPending.value = null;
    try {
        sessionStorage.removeItem(verifierKey);
    } catch {
        // The pending identity is kept only in memory after the redirect.
    }
}

export async function startLinuxDo() {
    const bytes = crypto.getRandomValues(new Uint8Array(32));
    const verifier = Array.from(bytes, (v) =>
        v.toString(16).padStart(2, "0"),
    ).join("");
    try {
        sessionStorage.setItem(verifierKey, verifier);
    } catch {
        throw new Error("浏览器未允许会话存储，请开启后再使用 Linux DO 登录。");
    }
    try {
        const { url } = await api.linuxDoStart(verifier);
        const target = new URL(url);
        if (
            target.origin !== "https://connect.linux.do" ||
            target.pathname !== "/oauth2/authorize"
        )
            throw new Error("Linux DO 授权地址异常，请联系管理员。");
        location.assign(target.href);
    } catch (error) {
        clearLinuxDo();
        throw error;
    }
}

export async function finishLinuxDo(): Promise<boolean> {
    if (!location.hash.startsWith("#linuxdo?")) return false;
    const params = new URLSearchParams(location.hash.slice("#linuxdo?".length));
    // Remove the one-time ticket before loading account data or other resources.
    history.replaceState(
        null,
        "",
        `${location.pathname}${location.search}#overview`,
    );
    let verifier: string | null = null;
    try {
        verifier = sessionStorage.getItem(verifierKey);
    } catch {
        throw new Error(
            "浏览器未允许会话存储，请开启后重新使用 Linux DO 登录。",
        );
    }
    clearLinuxDo();
    if (params.has("error"))
        throw new Error(params.get("error") || "Linux DO 授权未完成。");
    const ticket = params.get("ticket");
    if (
        !ticket ||
        !/^[A-Za-z0-9]{64}$/.test(ticket) ||
        !verifier ||
        !/^[a-f0-9]{64}$/.test(verifier)
    )
        throw new Error(
            "授权请求已过期或不属于当前标签页，请重新使用 Linux DO 登录。",
        );
    const result = await api.linuxDoExchange(ticket, verifier);
    if ("requires_account" in result && result.requires_account) {
        linuxDoPending.value = { ticket, verifier, username: result.username };
    } else if ("auth_data" in result && result.auth_data) {
        setAuthToken(result.auth_data);
    } else {
        throw new Error("Linux DO 登录响应异常，请重新授权。");
    }
    return true;
}
