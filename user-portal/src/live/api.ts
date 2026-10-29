import { ref } from "vue";
import type {
    Article,
    AuthData,
    CaptchaPayload,
    GuestConfig,
    LinuxDoAccountRequired,
    Notice,
    Order,
    PaymentMethod,
    PaymentResult,
    Period,
    Plan,
    SiteConfig,
    Subscription,
    Ticket,
    TrafficRecord,
    UserInfo,
} from "./types";

const tokenKey = "xboard-portal-auth";
function readToken() {
    try {
        return sessionStorage.getItem(tokenKey) ?? "";
    } catch {
        return "";
    }
}
export const authToken = ref(readToken());
export function setAuthToken(value: string) {
    authToken.value = value;
    try {
        if (value) sessionStorage.setItem(tokenKey, value);
        else sessionStorage.removeItem(tokenKey);
    } catch {
        /* In-memory session still works. */
    }
}
export class ApiError extends Error {
    constructor(
        message: string,
        public status = 0,
    ) {
        super(message);
    }
}
export async function request<T>(
    path: string,
    options: {
        body?: unknown;
        public?: boolean;
        signal?: AbortSignal;
        raw?: boolean;
    } = {},
): Promise<T> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20000);
    const relayAbort = () => controller.abort();
    options.signal?.addEventListener("abort", relayAbort, { once: true });
    if (options.signal?.aborted) controller.abort();
    const sentToken = options.public ? "" : authToken.value;
    try {
        const response = await fetch(`/api/v1${path}`, {
            method: options.body === undefined ? "GET" : "POST",
            credentials: "same-origin",
            cache: "no-store",
            signal: controller.signal,
            headers: {
                Accept: "application/json",
                "Content-Language": "zh-CN",
                ...(options.body !== undefined
                    ? { "Content-Type": "application/json" }
                    : {}),
                ...(sentToken ? { Authorization: sentToken } : {}),
            },
            body:
                options.body === undefined
                    ? undefined
                    : JSON.stringify(options.body),
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.status === "fail") {
            if (
                (response.status === 401 || response.status === 403) &&
                sentToken &&
                authToken.value === sentToken
            )
                setAuthToken("");
            const errors = payload?.errors as
                Record<string, string[]> | undefined;
            throw new ApiError(
                errors
                    ? Object.values(errors).flat().join("；")
                    : payload?.message || `请求失败（${response.status}）`,
                response.status,
            );
        }
        if (!payload || typeof payload !== "object")
            throw new ApiError("服务返回格式异常，请稍后重试。");
        return (options.raw ? payload : payload.data) as T;
    } catch (error) {
        if (error instanceof ApiError) throw error;
        if (error instanceof Error && error.name === "AbortError")
            throw new ApiError("请求超时或已取消，请重试。");
        throw new ApiError("暂时无法连接服务，请检查网络后重试。");
    } finally {
        clearTimeout(timer);
        options.signal?.removeEventListener("abort", relayAbort);
    }
}
const query = (values: Record<string, string | number>) =>
    new URLSearchParams(
        Object.entries(values).map(([k, v]) => [k, String(v)]),
    ).toString();
export const api = {
    linuxDoStart: (verifier: string) =>
        request<{ url: string }>("/passport/auth/linuxdo/start", {
            body: { verifier },
            public: true,
        }),
    linuxDoExchange: (ticket: string, verifier: string) =>
        request<AuthData | LinuxDoAccountRequired>(
            "/passport/auth/linuxdo/exchange",
            {
                body: { ticket, verifier },
                public: true,
            },
        ),
    linuxDoComplete: (body: Record<string, unknown>) =>
        request<AuthData>("/passport/auth/linuxdo/complete", {
            body,
            public: true,
        }),
    guestConfig: () =>
        request<GuestConfig>("/guest/comm/config", { public: true }),
    login: (body: { email: string; password: string }) =>
        request<AuthData>("/passport/auth/login", { body, public: true }),
    register: (body: Record<string, unknown>) =>
        request<AuthData>("/passport/auth/register", { body, public: true }),
    forget: (body: Record<string, unknown>) =>
        request<boolean>("/passport/auth/forget", { body, public: true }),
    verifyEmail: (email: string, captcha: CaptchaPayload) =>
        request<boolean>("/passport/comm/sendEmailVerify", {
            body: { email, ...captcha },
            public: true,
        }),
    tokenLogin: (verify: string) =>
        request<AuthData>(`/passport/auth/token2Login?${query({ verify })}`, {
            public: true,
        }),
    logout: async (): Promise<"revoked" | "local"> => {
        const token = authToken.value;
        // request() captures the current bearer before its first await. Start
        // revocation, then leave the account immediately even on a slow network.
        const revocation = request<boolean>("/user/logout", { body: {} });
        if (authToken.value === token) setAuthToken("");
        try {
            await revocation;
            return "revoked";
        } catch (cause) {
            // An expired session is already unusable on the server. Older
            // backends and network failures must not trap users in the UI.
            if (cause instanceof ApiError && cause.status === 401)
                return "revoked";
            return "local";
        }
    },
    user: () => request<UserInfo>("/user/info"),
    subscription: () => request<Subscription>("/user/getSubscribe"),
    plans: () => request<Plan[]>("/user/plan/fetch"),
    traffic: () => request<TrafficRecord[]>("/user/stat/getTrafficLog"),
    siteConfig: () => request<SiteConfig>("/user/comm/config"),
    articles: () =>
        request<Record<string, Article[]>>(
            "/user/knowledge/fetch?language=zh-CN",
        ),
    article: (id: number) =>
        request<Article>(`/user/knowledge/fetch?${query({ id })}`),
    notices: (current = 1) =>
        request<{ data: Notice[]; total: number }>(
            `/user/notice/fetch?${query({ current })}`,
            { raw: true },
        ),
    tickets: () => request<Ticket[]>("/user/ticket/fetch"),
    ticket: (id: number) =>
        request<Ticket>(`/user/ticket/fetch?${query({ id })}`),
    createTicket: (subject: string, message: string, level: number) =>
        request<boolean>("/user/ticket/save", {
            body: { subject, message, level },
        }),
    replyTicket: (id: number, message: string) =>
        request<boolean>("/user/ticket/reply", { body: { id, message } }),
    closeTicket: (id: number) =>
        request<boolean>("/user/ticket/close", { body: { id } }),
    updateReminders: (remind_expire: number, remind_traffic: number) =>
        request<boolean>("/user/update", {
            body: { remind_expire, remind_traffic },
        }),
    orders: () => request<Order[]>("/user/order/fetch"),
    order: (trade_no: string) =>
        request<Order>(`/user/order/detail?${query({ trade_no })}`),
    createOrder: (plan_id: number, period: Period, coupon_code: string) =>
        request<string>("/user/order/save", {
            body: { plan_id, period, coupon_code: coupon_code || undefined },
        }),
    paymentMethods: () =>
        request<PaymentMethod[]>("/user/order/getPaymentMethod"),
    checkout: (trade_no: string, method?: number) =>
        request<PaymentResult>("/user/order/checkout", {
            body: { trade_no, method },
            raw: true,
        }),
    orderStatus: (trade_no: string) =>
        request<number>(`/user/order/check?${query({ trade_no })}`),
    cancelOrder: (trade_no: string) =>
        request<boolean>("/user/order/cancel", { body: { trade_no } }),
};
