import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, unlink } from "node:fs/promises";
import { compileScript, parse } from "@vue/compiler-sfc";
import { JSDOM } from "jsdom";

const dom = new JSDOM('<div id="app"></div>', {
    url: "https://panel.example.test/",
});
for (const name of [
    "window",
    "document",
    "Document",
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

test("the real login page offers Linux DO only when enabled, binds existing accounts and preserves registration fields", async () => {
    const { createApp, nextTick } = await import("vue");
    const { authToken, setAuthToken } = await import("../src/live/api");
    const { linuxDoPending, clearLinuxDo } =
        await import("../src/live/linuxdo");
    const source = await readFile(
        new URL("../src/live/components/AuthPage.vue", import.meta.url),
        "utf8",
    );
    const compiled = compileScript(parse(source).descriptor, {
        id: "linuxdo-auth",
        inlineTemplate: true,
    });
    const file = new URL(`.linuxdo-auth-${process.pid}.ts`, import.meta.url);
    await writeFile(
        file,
        compiled.content
            .replace(/from "\.\.\/(.*?)"/g, 'from "../src/live/$1"')
            .replace(
                'import { portalBuildVersion, portalConfig } from "../src/live/config";',
                'const portalBuildVersion = "test"; const portalConfig = { title: "Test", description: "Test", allowRegistration: true, coverImage: "" };',
            )
            .replace(
                'import CaptchaWidget from "./CaptchaWidget.vue";',
                "const CaptchaWidget = { render() { return null; } };",
            )
            .replace(
                'import ResourceState from "./ResourceState.vue";',
                "const ResourceState = { render() { return null; } };",
            ),
    );
    let app: ReturnType<typeof createApp> | undefined;
    const originalFetch = globalThis.fetch;
    let enabled = true;
    let completeBody: Record<string, unknown> = {};
    let completionError = false;
    globalThis.fetch = async (input, init) => {
        if (input === "/api/v1/guest/comm/config")
            return new Response(
                JSON.stringify({
                    data: {
                        is_linuxdo: enabled,
                        is_register: true,
                        is_email_verify: 1,
                        is_invite_force: 1,
                        is_captcha: 0,
                        tos_url: "https://panel.example.test/terms",
                    },
                }),
            );
        assert.equal(input, "/api/v1/passport/auth/linuxdo/complete");
        completeBody = JSON.parse(init!.body as string);
        return completionError
            ? new Response(JSON.stringify({ message: "密码错误" }), {
                  status: 400,
              })
            : new Response(
                  JSON.stringify({ data: { auth_data: "Bearer bound-user" } }),
              );
    };
    const settle = async () => {
        await new Promise((resolve) => setTimeout(resolve, 0));
        await nextTick();
    };
    try {
        const Component = (await import(file.href)).default;
        app = createApp(Component, { theme: "light" });
        app.mount("#app");
        await settle();
        assert.match(
            document.querySelector(".linuxdo-login")!.textContent!,
            /使用 Linux DO 登录/,
        );
        linuxDoPending.value = {
            ticket: "T".repeat(64),
            verifier: "a".repeat(64),
            username: "<script>traveler</script>",
        };
        await nextTick();
        assert.equal(document.querySelector(".linuxdo-login"), null);
        assert.match(
            document.querySelector(".linuxdo-pending")!.textContent!,
            /<script>traveler<\/script>/,
        );
        assert.equal(document.querySelector(".linuxdo-pending script"), null);
        assert.match(
            document.querySelector(".auth-submit")!.textContent!,
            /绑定已有账户并登录/,
        );
        const input = async (selector: string, value: string) => {
            const field = document.querySelector<HTMLInputElement>(selector)!;
            field.value = value;
            field.dispatchEvent(
                new dom.window.Event("input", { bubbles: true }),
            );
            await nextTick();
        };
        await input("#auth-email", "existing@example.test");
        await input("#auth-password", "test-password");
        completionError = true;
        document.querySelector("form")!.dispatchEvent(
            new dom.window.Event("submit", {
                bubbles: true,
                cancelable: true,
            }),
        );
        await settle();
        assert.match(
            document.querySelector(".field-error")!.textContent!,
            /密码错误/,
        );
        assert.equal(authToken.value, "");
        assert.ok(linuxDoPending.value);
        completionError = false;
        document.querySelector("form")!.dispatchEvent(
            new dom.window.Event("submit", {
                bubbles: true,
                cancelable: true,
            }),
        );
        await settle();
        assert.deepEqual(completeBody, {
            email: "existing@example.test",
            password: "test-password",
            action: "login",
            ticket: "T".repeat(64),
            verifier: "a".repeat(64),
        });
        assert.equal(authToken.value, "Bearer bound-user");
        assert.equal(linuxDoPending.value, null);
        setAuthToken("");
        linuxDoPending.value = {
            ticket: "T".repeat(64),
            verifier: "a".repeat(64),
            username: "traveler",
        };
        const register = [
            ...document.querySelectorAll<HTMLButtonElement>(
                ".auth-links button",
            ),
        ].find((b) => b.textContent!.includes("创建新账户"))!;
        register.click();
        await nextTick();
        assert.ok(document.querySelector("#auth-code"));
        assert.equal(
            document.querySelector<HTMLInputElement>("#invite-code")!.required,
            true,
        );
        assert.equal(
            document.querySelector<HTMLInputElement>(".tos-row input")!
                .required,
            true,
        );
        assert.match(
            document.querySelector(".auth-submit")!.textContent!,
            /创建并绑定账户/,
        );
        document
            .querySelector<HTMLButtonElement>(".linuxdo-pending button")!
            .click();
        await nextTick();
        assert.equal(linuxDoPending.value, null);
        assert.ok(document.querySelector(".linuxdo-login"));
        app.unmount();
        app = undefined;
        enabled = false;
        app = createApp(Component, { theme: "dark" });
        app.mount("#app");
        await settle();
        assert.equal(document.querySelector(".linuxdo-login"), null);
    } finally {
        app?.unmount();
        globalThis.fetch = originalFetch;
        clearLinuxDo();
        setAuthToken("");
        await unlink(file);
    }
});
