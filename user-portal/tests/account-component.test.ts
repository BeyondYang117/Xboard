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
    } finally {
        app?.unmount();
        resetPortal();
        await unlink(file);
        dom.window.close();
    }
});
