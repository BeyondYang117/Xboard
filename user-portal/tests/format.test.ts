import { test } from "node:test";
import assert from "node:assert/strict";
import { JSDOM } from "jsdom";
import type { Plan, Subscription } from "../src/live/types";
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
    aggregateTraffic,
    formatBytes,
    priceFor,
    safeRichText,
    subscriptionState,
} = await import("../src/live/format");

test("legacy cent prices, zero prices and raw yuan prices stay distinct", () => {
    assert.equal(priceFor({ month_price: 2900 } as Plan, "month_price"), 2900);
    assert.equal(priceFor({ month_price: 0 } as Plan, "month_price"), 0);
    assert.equal(priceFor({ month_price: null } as Plan, "month_price"), null);
    assert.equal(
        priceFor({ prices: { yearly: "290.50" } } as Plan, "year_price"),
        29050,
    );
    assert.equal(priceFor({} as Plan, "year_price"), null);
});
test("permanent, expired, empty and exhausted subscriptions have different states", () => {
    const now = new Date("2026-10-28T00:00:00Z").getTime();
    const subscription = {
        plan_id: 1,
        transfer_enable: 1000,
        u: 100,
        d: 200,
        expired_at: null,
    } as Subscription;
    assert.equal(subscriptionState(subscription, false, now), "active");
    assert.equal(
        subscriptionState({ ...subscription, expired_at: 0 }, false, now),
        "expired",
    );
    assert.equal(
        subscriptionState(
            { ...subscription, transfer_enable: 300 },
            false,
            now,
        ),
        "exhausted",
    );
    assert.equal(
        subscriptionState(
            { ...subscription, plan_id: null, transfer_enable: 0 },
            false,
            now,
        ),
        "empty",
    );
    assert.equal(
        subscriptionState(
            { ...subscription, expired_at: now / 1000 + 86400 },
            false,
            now,
        ),
        "expiring",
    );
    assert.equal(subscriptionState(subscription, true, now), "banned");
});
test("daily billed traffic is summed once without multiplying rate again", () => {
    const day = new Date(2026, 9, 2).getTime() / 1000;
    const values = aggregateTraffic(
        [
            { record_at: day, u: 200, d: 300, server_rate: 2 },
            { record_at: day, u: 50, d: 50, server_rate: 1 },
        ],
        new Date(2026, 9, 5),
    );
    assert.equal(values.length, 5);
    assert.equal(values[1]?.bytes, 600);
    assert.equal(formatBytes(1024 ** 3), "1 GB");
});
test("rich articles preserve useful markup while removing executable content", () => {
    const result = safeRichText(
        '<script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">bad</a><a href="https://example.com">good</a><iframe src="https://example.com"></iframe>',
    );
    assert.doesNotMatch(result, /<script|onerror=|javascript:|<iframe/);
    assert.match(result, /rel="noopener noreferrer"/);
    assert.match(result, /target="_blank"/);
    assert.match(result, /referrerpolicy="no-referrer"/);
});
