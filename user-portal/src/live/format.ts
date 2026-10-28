import DOMPurify from "dompurify";
import { marked } from "marked";
import type { Period, Plan, Subscription, TrafficRecord } from "./types";
export const periods: { key: Period; label: string; months?: number }[] = [
    { key: "month_price", label: "月付", months: 1 },
    { key: "quarter_price", label: "季付", months: 3 },
    { key: "half_year_price", label: "半年付", months: 6 },
    { key: "year_price", label: "年付", months: 12 },
    { key: "two_year_price", label: "两年付", months: 24 },
    { key: "three_year_price", label: "三年付", months: 36 },
    { key: "onetime_price", label: "一次性" },
    { key: "reset_price", label: "流量重置包" },
];
const modernPeriods = {
    month_price: "monthly",
    quarter_price: "quarterly",
    half_year_price: "half_yearly",
    year_price: "yearly",
    two_year_price: "two_yearly",
    three_year_price: "three_yearly",
    onetime_price: "onetime",
    reset_price: "reset_traffic",
};
// User resources return prices in cents. Raw subscription.plan uses yuan-valued prices.
export function priceFor(plan: Plan, period: Period): number | null {
    const legacy = plan[period];
    if (legacy != null)
        return Number.isFinite(Number(legacy)) ? Number(legacy) : null;
    const modern = plan.prices?.[modernPeriods[period]];
    return modern != null && Number.isFinite(Number(modern))
        ? Math.round(Number(modern) * 100)
        : null;
}
export function money(cents: number | null | undefined, symbol = "¥") {
    return `${symbol}${(Number(cents ?? 0) / 100).toFixed(2)}`;
}
export function formatDate(
    timestamp: number | null | undefined,
    withTime = false,
) {
    if (timestamp == null) return "长期有效";
    if (!Number.isFinite(Number(timestamp)) || Number(timestamp) <= 0)
        return "—";
    return new Intl.DateTimeFormat("zh-CN", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        ...(withTime ? ({ hour: "2-digit", minute: "2-digit" } as const) : {}),
    }).format(new Date(timestamp * 1000));
}
export function formatBytes(bytes: number) {
    if (!Number.isFinite(bytes) || bytes <= 0) return "0 B";
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), 4);
    return `${(bytes / 1024 ** index).toLocaleString("zh-CN", { maximumFractionDigits: index ? 2 : 0 })} ${["B", "KB", "MB", "GB", "TB"][index]}`;
}
export function subscriptionState(
    sub: Subscription | null,
    banned = false,
    now = Date.now(),
) {
    if (!sub) return "loading";
    if (banned) return "banned";
    if (!sub.plan_id && sub.transfer_enable <= 0) return "empty";
    if (sub.expired_at != null && sub.expired_at * 1000 <= now)
        return "expired";
    if (sub.transfer_enable <= sub.u + sub.d) return "exhausted";
    if (sub.expired_at != null && sub.expired_at * 1000 - now <= 3 * 86400000)
        return "expiring";
    return "active";
}
export function aggregateTraffic(records: TrafficRecord[], now = new Date()) {
    const lastDay = now.getDate();
    const values = Array.from({ length: lastDay }, (_, i) => ({
        day: i + 1,
        bytes: 0,
    }));
    for (const record of records) {
        const date = new Date(record.record_at * 1000);
        if (
            date.getFullYear() === now.getFullYear() &&
            date.getMonth() === now.getMonth() &&
            date.getDate() <= lastDay
        )
            values[date.getDate() - 1]!.bytes +=
                Number(record.u) + Number(record.d);
    }
    // getTrafficLog already contains billed traffic; applying server_rate again would double-count.
    return values;
}
export function httpUrl(value: string | null | undefined): string | null {
    try {
        const url = new URL(value ?? "", location.origin);
        return ["http:", "https:"].includes(url.protocol) && value
            ? url.href
            : null;
    } catch {
        return null;
    }
}
export function safeRichText(value: string) {
    const html = DOMPurify.sanitize(
        marked.parse(value, { async: false }) as string,
        {
            USE_PROFILES: { html: true },
            FORBID_TAGS: ["style", "form", "input", "button", "iframe"],
            FORBID_ATTR: ["style", "srcset"],
        },
    );
    const wrapper = document.createElement("div");
    wrapper.innerHTML = html;
    wrapper.querySelectorAll("a").forEach((link) => {
        link.rel = "noopener noreferrer";
        link.target = "_blank";
        if (!httpUrl(link.getAttribute("href"))) link.removeAttribute("href");
    });
    wrapper.querySelectorAll("img").forEach((image) => {
        image.setAttribute("loading", "lazy");
        image.setAttribute("referrerpolicy", "no-referrer");
    });
    return wrapper.innerHTML;
}
export function plainText(value: string) {
    const element = document.createElement("div");
    element.innerHTML = DOMPurify.sanitize(
        marked.parse(value, { async: false }) as string,
    );
    return element.textContent ?? "";
}
