import { test } from "node:test";
import assert from "node:assert/strict";
import { JSDOM } from "jsdom";
import type { Plan } from "../src/live/types";
const dom = new JSDOM("", { url: "https://panel.example.test/" });
Object.defineProperty(globalThis, "window", {
    value: dom.window,
    configurable: true,
});
Object.defineProperty(globalThis, "document", {
    value: dom.window.document,
    configurable: true,
});
Object.defineProperty(globalThis, "location", {
    value: dom.window.location,
    configurable: true,
});
const {
    mergePlans,
    planDescription,
    planPeriod,
    plansForCategory,
    planResetRule,
    purchasePeriods,
} = await import("../src/live/plans");
const { priceFor, safeRichText } = await import("../src/live/format");
const monthly = { id: 1, month_price: 590, transfer_enable: 60 } as Plan;
const onetime = {
    id: 2,
    prices: { onetime: "19.90" },
    transfer_enable: 200,
} as Plan;

test("plan categories separate billing types and keep checkout periods inside the selected category", () => {
    const plans = [monthly, onetime];
    assert.deepEqual(
        plansForCategory(plans, "recurring").map((p) => p.id),
        [1],
    );
    assert.deepEqual(
        plansForCategory(plans, "onetime").map((p) => p.id),
        [2],
    );
    assert.equal(planPeriod(monthly, "recurring"), "month_price");
    assert.equal(planPeriod(onetime, "onetime"), "onetime_price");
    assert.equal(priceFor(onetime, planPeriod(onetime, "onetime")), 1990);
    const multi = {
        id: 3,
        month_price: 0,
        year_price: 5900,
        onetime_price: 9900,
        reset_price: 100,
    } as Plan;
    assert.deepEqual(
        purchasePeriods(multi, "recurring").map((p) => p.key),
        ["month_price", "year_price"],
    );
    assert.deepEqual(
        purchasePeriods(multi, "onetime").map((p) => p.key),
        ["onetime_price"],
    );
    assert.equal(planPeriod(multi, "recurring", "year_price"), "year_price");
    assert.equal(planPeriod(multi, "onetime", "year_price"), "onetime_price");
    assert.equal(
        planPeriod(multi, "recurring", "onetime_price"),
        "month_price",
    );
    assert.equal(plansForCategory([multi], "recurring").length, 1);
    assert.equal(plansForCategory([multi], "onetime").length, 1);
    const resetOnly = [{ reset_price: 100 } as Plan];
    assert.deepEqual(plansForCategory(resetOnly, "recurring"), []);
    assert.deepEqual(plansForCategory(resetOnly, "onetime"), []);
});

test("reset labels reflect configured rules without promising one-time plans never reset", () => {
    assert.equal(
        planResetRule({ ...onetime, reset_traffic_method: 2 }),
        "流量不重置",
    );
    assert.equal(
        planResetRule(onetime, { reset_traffic_method: 0 }),
        "每月1号重置流量",
    );
    assert.equal(planResetRule(onetime), "流量重置以站点规则为准");
});

test("merging a hidden current plan retains both catalog plans without duplicates", () => {
    const current = { id: 3, prices: { monthly: "9" } } as Plan;
    assert.deepEqual(
        mergePlans([monthly, onetime], current).map((p) => p.id),
        [3, 1, 2],
    );
    assert.deepEqual(
        mergePlans([monthly, onetime], {
            ...monthly,
            prices: { monthly: "99" },
        }).map((p) => p.id),
        [1, 2],
    );
});

test("raw plan descriptions replace values and reset rules before rich text sanitization", () => {
    const plan = {
        ...monthly,
        speed_limit: 20,
        device_limit: 5,
        reset_traffic_method: null,
        content:
            '<p>{{transfer}} GB / {{ speed }} Mbps / {{devices}} 台 / {{reset_method}}</p><img src="x" onerror="alert(1)">',
    } as Plan;
    const rendered = safeRichText(
        planDescription(plan, { reset_traffic_method: 0 }),
    );
    assert.match(rendered, /60 GB \/ 20 Mbps \/ 5 台 \/ 每月1号/);
    assert.doesNotMatch(rendered, /\{\{|onerror/);
    assert.match(
        planDescription(
            { ...plan, reset_traffic_method: 2 },
            { reset_traffic_method: 0 },
        ),
        /不重置/,
    );
    assert.match(planDescription(plan), /按站点规则/);
    assert.match(
        planDescription({ ...plan, device_limit: null, speed_limit: 0 }),
        /0 Mbps \/ 不限制 台/,
    );
    assert.equal(
        planDescription({ ...plan, content: "<p>已由后端渲染</p>" }),
        "<p>已由后端渲染</p>",
    );
});
