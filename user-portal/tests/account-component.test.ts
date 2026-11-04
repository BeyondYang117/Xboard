import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, unlink } from "node:fs/promises";
import { compileScript, parse } from "@vue/compiler-sfc";
import { JSDOM } from "jsdom";
import type { UserInfo } from "../src/live/types";

const dom = new JSDOM('<div id="app"></div>', {
    url: "https://panel.example.test/#account",
});
for (const name of [
    "window",
    "document",
    "location",
    "sessionStorage",
    "Element",
    "HTMLElement",
    "SVGElement",
    "Node",
] as const) {
    Object.defineProperty(globalThis, name, {
        configurable: true,
        value: dom.window[name],
    });
}

test("the account page shows bound identities safely and distinguishes unbound accounts from old servers", async () => {
    const { createApp, nextTick } = await import("vue");
    const { portal, resetPortal } = await import("../src/live/store");
    const { api } = await import("../src/live/api");
    const originalLink = api.telegramBindingLink;
    const originalUnbind = api.telegramUnbind;
    const originalUser = api.user;
    const source = await readFile(
        new URL("../src/live/components/AccountPage.vue", import.meta.url),
        "utf8",
    );
    const compiled = compileScript(parse(source).descriptor, {
        id: "account-binding",
        inlineTemplate: true,
    });
    const file = new URL(`.account-page-${process.pid}.ts`, import.meta.url);
    await writeFile(
        file,
        compiled.content.replace(
            /from "\.\.\/(.*?)"/g,
            'from "../src/live/$1"',
        ),
    );
    const user: UserInfo = {
        email: "traveler@example.com",
        balance: 0,
        banned: false,
        remind_expire: 1,
        remind_traffic: 1,
        created_at: 0,
        linuxdo_id: "9007199254740993",
        linuxdo_username: "<script>traveler</script>",
    };
    portal.user.data = user;
    let app: ReturnType<typeof createApp> | undefined;
    const row = () => document.querySelector(".linuxdo-account-row")!;
    try {
        const Component = (await import(file.href)).default;
        app = createApp(Component, { theme: "light" });
        app.mount("#app");
        await nextTick();
        assert.match(row().textContent!, /已绑定/);
        assert.match(row().textContent!, /@<script>traveler<\/script>/);
        assert.match(row().textContent!, /用户 ID：9007199254740993/);
        assert.equal(row().querySelector("script"), null);

        portal.user.data = { ...user, linuxdo_username: null };
        await nextTick();
        assert.match(row().textContent!, /用户名暂未同步/);
        assert.match(row().textContent!, /已绑定/);

        portal.user.data = {
            ...user,
            linuxdo_id: null,
            linuxdo_username: null,
        };
        await nextTick();
        assert.match(row().textContent!, /未绑定/);
        assert.match(row().textContent!, /验证本站邮箱和密码完成绑定/);
        assert.doesNotMatch(row().textContent!, /9007199254740993|traveler/);

        portal.user.data = {
            ...user,
            linuxdo_id: undefined,
            linuxdo_username: undefined,
        };
        await nextTick();
        assert.match(row().textContent!, /更新服务端后可查看绑定状态/);
        assert.match(row().textContent!, /暂不可用/);
        assert.doesNotMatch(row().textContent!, /未绑定/);

        // Exercise the full website flow without leaving the page or exposing
        // a subscription token in Telegram URLs.
        portal.user.data = { ...user, telegram_id: null };
        portal.site.data = { is_telegram: 1 };
        const tgRow = () => document.querySelector(".telegram-account-row")!;
        const click = async (label: string) => {
            const button = Array.from(tgRow().querySelectorAll("button")).find(
                (item) => item.textContent?.trim() === label,
            );
            assert.ok(button, `Missing button: ${label}`);
            button.click();
            await new Promise((resolve) => setImmediate(resolve));
            await nextTick();
        };
        let unbinds = 0;
        api.telegramBindingLink = async () => ({
            url: "https://t.me/test_bot?start=bind_" + "a".repeat(40),
            expires_at: Math.floor(Date.now() / 1000) + 300,
        });
        api.telegramUnbind = async () => {
            unbinds++;
            return true;
        };
        api.user = async () => ({ ...user, telegram_id: 123456789 });
        await nextTick();
        await click("绑定 Telegram");
        const link = tgRow().querySelector("a")!;
        assert.match(link.href, /^https:\/\/t.me\/test_bot\?start=bind_/);
        assert.equal(link.rel, "noopener noreferrer");
        assert.match(tgRow().textContent!, /5 分钟内有效/);
        window.dispatchEvent(new dom.window.Event("focus"));
        await new Promise((resolve) => setImmediate(resolve));
        await nextTick();
        assert.match(tgRow().textContent!, /已绑定 · 用户 ID：123456789/);
        assert.equal(tgRow().querySelector("a"), null);
        await click("解除绑定");
        assert.equal(unbinds, 0);
        await click("取消");
        assert.equal(unbinds, 0);
        await click("解除绑定");
        await click("确认解绑");
        assert.equal(unbinds, 1);
        assert.equal(portal.user.data?.telegram_id, null);

        api.telegramBindingLink = async () => {
            throw new Error("站点暂未开启 Telegram Bot");
        };
        await click("绑定 Telegram");
        assert.match(
            tgRow().querySelector('[role="alert"]')!.textContent!,
            /暂未开启/,
        );
        api.telegramBindingLink = async () => ({
            url: "javascript:alert(1)",
            expires_at: 1,
        });
        await click("绑定 Telegram");
        assert.equal(tgRow().querySelector("a"), null);
        assert.match(tgRow().textContent!, /绑定链接格式异常/);
        api.telegramBindingLink = async () => ({
            url: "https://t.me/test_bot?start=bind_" + "b".repeat(40),
            expires_at: Math.floor(Date.now() / 1000) - 1,
        });
        await click("绑定 Telegram");
        assert.match(tgRow().textContent!, /绑定链接已过期/);
        assert.equal(tgRow().querySelector("a"), null);
        await click("撤销链接");
        assert.equal(unbinds, 2);
        assert.doesNotMatch(tgRow().textContent!, /绑定链接已过期/);
    } finally {
        api.telegramBindingLink = originalLink;
        api.telegramUnbind = originalUnbind;
        api.user = originalUser;
        app?.unmount();
        resetPortal();
        await unlink(file);
        dom.window.close();
    }
});
