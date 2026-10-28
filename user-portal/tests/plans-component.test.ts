import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, unlink } from "node:fs/promises";
import { compileScript, parse } from "@vue/compiler-sfc";
import { JSDOM } from "jsdom";
import type { Plan, Subscription } from "../src/live/types";

const dom = new JSDOM('<div id="app"></div>', {
    url: "https://panel.example.test/",
});
for (const name of [
    "window",
    "document",
    "location",
    "Element",
    "HTMLElement",
    "SVGElement",
    "Node",
] as const) {
    Object.defineProperty(globalThis, name, {
        configurable: true,
        value: name === "window" ? dom.window : dom.window[name],
    });
}

test("the real plans page shows both category counts and sends each category's correct period to checkout", async () => {
    const { createApp, nextTick } = await import("vue");
    const { portal, resetPortal } = await import("../src/live/store");
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => new Response(JSON.stringify({ data: [] }));
    const monthly = {
        id: 1,
        name: "月付套餐",
        transfer_enable: 60,
        device_limit: 5,
        speed_limit: 20,
        month_price: 590,
        year_price: 5900,
        sell: true,
        renew: true,
        capacity_limit: null,
        content:
            "<p>流量 {{transfer}} GB / {{speed}} Mbps / {{devices}} 台</p>",
    } as Plan;
    const onetime = {
        ...monthly,
        id: 2,
        name: "长期流量套餐",
        month_price: null,
        year_price: null,
        onetime_price: 1990,
        capacity_limit: 0,
    } as Plan;
    portal.plans.data = [monthly, onetime];
    portal.subscription.data = {
        plan_id: 1,
        plan: { ...monthly, prices: { monthly: "5.9" } },
    } as Subscription;
    portal.site.data = { currency_symbol: "¥" };
    const source = await readFile(
        new URL("../src/live/components/PlansPage.vue", import.meta.url),
        "utf8",
    );
    const compiled = compileScript(parse(source).descriptor, {
        id: "plans-regression",
        inlineTemplate: true,
    });
    const file = new URL(`.plans-page-${process.pid}.ts`, import.meta.url);
    await writeFile(
        file,
        'import { h as _testH } from "vue";\n' +
            compiled.content
                .replace(/from "\.\.\/(.*?)"/g, 'from "../src/live/$1"')
                .replace(
                    'import OrderDialog from "./OrderDialog.vue";',
                    'const OrderDialog = { props: ["plan", "period"], emits: ["close", "changed"], render() { return _testH("div", { "data-order-period": this.period, "data-order-plan": String(this.plan?.id) }); } };',
                )
                .replace(
                    'import ResourceState from "./ResourceState.vue";',
                    "const ResourceState = { render() { return null; } };",
                ),
    );
    let app: { unmount(): void } | undefined;
    try {
        const Component = (await import(file.href)).default;
        app = createApp(Component);
        (app as ReturnType<typeof createApp>).mount("#app");
        await nextTick();
        const cards = () => [...document.querySelectorAll(".plan-card")];
        const categories = [
            ...document.querySelectorAll<HTMLButtonElement>(
                ".plan-categories button",
            ),
        ];
        assert.match(categories[0]!.textContent!, /订阅套餐\s*1/);
        assert.match(categories[1]!.textContent!, /长期流量\s*1/);
        assert.equal(categories[0]!.getAttribute("aria-pressed"), "true");
        assert.equal(cards().length, 1);
        assert.match(cards()[0]!.textContent!, /月付套餐/);
        assert.match(cards()[0]!.textContent!, /¥5\.90/);
        assert.match(cards()[0]!.textContent!, /流量 60 GB \/ 20 Mbps \/ 5 台/);
        assert.doesNotMatch(document.body.textContent!, /\{\{/);
        assert.equal(document.querySelector('[aria-label="计费周期"]'), null);
        const cardPeriod = cards()[0]!.querySelector("select")!;
        cardPeriod.value = "year_price";
        cardPeriod.dispatchEvent(
            new dom.window.Event("change", { bubbles: true }),
        );
        await nextTick();
        assert.match(cards()[0]!.textContent!, /¥59\.00/);
        categories[1]!.click();
        await nextTick();
        assert.equal(cards().length, 1);
        assert.match(cards()[0]!.textContent!, /长期流量套餐/);
        assert.match(cards()[0]!.textContent!, /¥19\.90.*一次性付费/s);
        assert.equal(cards()[0]!.querySelector("select"), null);
        assert.equal(
            cards()[0]!.querySelector<HTMLButtonElement>("button")!.disabled,
            false,
        );
        categories[0]!.click();
        await nextTick();
        assert.match(cards()[0]!.textContent!, /¥59\.00/);
        // A catalog with only one-time products should open that category.
        portal.subscription.data = null;
        portal.plans.data = [onetime];
        await nextTick();
        assert.equal(categories[1]!.getAttribute("aria-pressed"), "true");
        assert.match(cards()[0]!.textContent!, /长期流量套餐/);
        cards()[0]!.querySelector<HTMLButtonElement>("button")!.click();
        await nextTick();
        const checkout = document.querySelector("[data-order-period]")!;
        assert.equal(checkout.getAttribute("data-order-plan"), "2");
        assert.equal(
            checkout.getAttribute("data-order-period"),
            "onetime_price",
        );
    } finally {
        app?.unmount();
        resetPortal();
        globalThis.fetch = originalFetch;
        await unlink(file);
    }
});
