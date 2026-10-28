import { periods, priceFor } from "./format";
import type { Period, Plan, SiteConfig } from "./types";

export type PlanCategory = "recurring" | "onetime";
export function purchasePeriods(plan: Plan, category?: PlanCategory) {
    return periods.filter(
        (item) =>
            item.key !== "reset_price" &&
            priceFor(plan, item.key) != null &&
            (!category ||
                (category === "onetime"
                    ? item.key === "onetime_price"
                    : item.key !== "onetime_price")),
    );
}
export function mergePlans(plans: Plan[], current?: Plan): Plan[] {
    return current && !plans.some((plan) => plan.id === current.id)
        ? [current, ...plans]
        : plans;
}
export function plansForCategory(
    plans: Plan[],
    category: PlanCategory,
): Plan[] {
    return plans.filter((plan) => purchasePeriods(plan, category).length > 0);
}
export function planPeriod(
    plan: Plan,
    category: PlanCategory,
    selected?: Period,
): Period {
    const available = purchasePeriods(plan, category);
    if (selected && available.some((item) => item.key === selected))
        return selected;
    return (
        available[0]?.key ??
        (category === "onetime" ? "onetime_price" : "month_price")
    );
}
export function planResetRule(plan: Plan, site?: SiteConfig | null): string {
    const method = plan.reset_traffic_method ?? site?.reset_traffic_method;
    const rules: Record<number, string> = {
        0: "每月1号重置流量",
        1: "按月重置流量",
        2: "流量不重置",
        3: "每年1月1日重置流量",
        4: "按年重置流量",
    };
    return method == null
        ? "流量重置以站点规则为准"
        : (rules[Number(method)] ?? "流量重置以站点规则为准");
}
// Older /getSubscribe responses may still carry an unrendered description.
// Replace only the documented variables; sanitize the resulting rich text later.
export function planDescription(plan: Plan, site?: SiteConfig | null): string {
    const reset = plan.reset_traffic_method ?? site?.reset_traffic_method;
    const resetLabels: Record<number, string> = {
        0: "每月1号",
        1: "按月",
        2: "不重置",
        3: "每年1月1日",
        4: "按年",
    };
    const values: Record<string, string> = {
        transfer: String(plan.transfer_enable),
        speed: plan.speed_limit == null ? "不限制" : String(plan.speed_limit),
        devices:
            plan.device_limit == null ? "不限制" : String(plan.device_limit),
        reset_method:
            reset == null
                ? "按站点规则"
                : (resetLabels[Number(reset)] ?? "按站点规则"),
    };
    return (plan.content || "").replace(
        /\{\{\s*(transfer|speed|devices|reset_method)\s*\}\}/g,
        (_, key: string) => values[key]!,
    );
}
