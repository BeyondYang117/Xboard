import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile, unlink } from "node:fs/promises";
import { compileScript, parse } from "@vue/compiler-sfc";
import { JSDOM } from "jsdom";
import { loadCaptcha, type CaptchaAPI } from "../src/live/captcha";

const dom = new JSDOM('<div id="app"></div>', {
    url: "https://panel.example.test/",
});
for (const name of [
    "window",
    "document",
    "Element",
    "HTMLElement",
    "SVGElement",
    "Node",
] as const) {
    Object.defineProperty(globalThis, name, {
        configurable: true,
        value:
            name === "window"
                ? dom.window
                : name === "document"
                  ? dom.window.document
                  : dom.window[name],
    });
}

test("the real CAPTCHA component renders Turnstile, submits its token and removes the widget", async () => {
    const source = await readFile(
        new URL("../src/live/components/CaptchaWidget.vue", import.meta.url),
        "utf8",
    );
    const { descriptor } = parse(source);
    const compiled = compileScript(descriptor, {
        id: "captcha-regression",
        inlineTemplate: true,
    });
    const file = new URL(`.captcha-widget-${process.pid}.ts`, import.meta.url);
    // Compile the actual SFC for this jsdom test; keep its real loader import.
    await writeFile(
        file,
        compiled.content.replace(
            'from "../captcha"',
            'from "../src/live/captcha"',
        ),
    );
    let app: { unmount(): void } | undefined;
    try {
        const { createApp, nextTick } = await import("vue");
        const Component = (await import(file.href)).default;
        let success!: (token: string) => void;
        let expire!: () => void;
        let resets = 0;
        let removed = 0;
        let readyCalls = 0;
        const api: CaptchaAPI = {
            render(element, options) {
                assert.equal(options.sitekey, "test-site-key");
                element.textContent = "Turnstile test widget";
                success = options.callback as (token: string) => void;
                expire = options["expired-callback"] as () => void;
                return "widget-regression";
            },
            ready() {
                readyCalls++;
                throw new Error(
                    "Remove async/defer from the Turnstile api.js script tag before using turnstile.ready()",
                );
            },
            reset(id) {
                assert.equal(id, "widget-regression");
                resets++;
            },
            remove(id) {
                assert.equal(id, "widget-regression");
                removed++;
            },
        };
        const globals = dom.window as unknown as { turnstile: CaptchaAPI };
        globals.turnstile = api;
        await loadCaptcha("turnstile", "test-site-key");
        app = createApp(Component, {
            config: {
                is_captcha: true,
                captcha_type: "turnstile",
                turnstile_site_key: "test-site-key",
            },
        });
        const instance = (app as ReturnType<typeof createApp>).mount(
            "#app",
        ) as unknown as {
            getPayload(): Promise<Record<string, string>>;
            reset(): void;
        };
        await nextTick();
        assert.match(document.body.textContent!, /Turnstile test widget/);
        assert.doesNotMatch(document.body.textContent!, /Remove async\/defer/);
        assert.equal(readyCalls, 0);
        await assert.rejects(instance.getPayload(), /请先完成人机验证/);
        success("test-token");
        assert.deepEqual(await instance.getPayload(), {
            turnstile_token: "test-token",
        });
        expire();
        await assert.rejects(instance.getPayload(), /请先完成人机验证/);
        instance.reset();
        assert.equal(resets, 1);
        app.unmount();
        app = undefined;
        assert.equal(removed, 1);
    } finally {
        app?.unmount();
        await unlink(file);
    }
});
