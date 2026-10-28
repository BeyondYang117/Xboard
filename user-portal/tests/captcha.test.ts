import { test } from "node:test";
import assert from "node:assert/strict";
import { JSDOM } from "jsdom";
import { loadCaptcha, type CaptchaAPI } from "../src/live/captcha";

const dom = new JSDOM("", { url: "https://panel.example.test/" });
Object.defineProperty(globalThis, "window", {
    value: dom.window,
    configurable: true,
});
Object.defineProperty(globalThis, "document", {
    value: dom.window.document,
    configurable: true,
});
const globals = dom.window as unknown as Record<string, unknown>;
function callback(script: HTMLScriptElement) {
    const name = new URL(script.src).searchParams.get("onload")!;
    (globals[name] as () => void)();
}

test("Turnstile retries a failed load, shares the async callback and never calls ready", async () => {
    const failed = loadCaptcha("turnstile", "test-site-key");
    const firstScript = document.querySelector("script")!;
    firstScript.dispatchEvent(new dom.window.Event("error"));
    await assert.rejects(failed, /暂时无法加载/);
    assert.equal(document.querySelectorAll("script").length, 0);
    const loaded = loadCaptcha("turnstile", "test-site-key");
    assert.equal(loadCaptcha("turnstile", "another-key"), loaded);
    const script = document.querySelector("script")!;
    assert.equal(script.async, true);
    assert.equal(new URL(script.src).searchParams.get("render"), "explicit");
    let readyCalls = 0;
    const api: CaptchaAPI = {
        render: () => "widget-test",
        reset() {},
        ready() {
            readyCalls++;
            throw new Error(
                "Remove async/defer before using turnstile.ready()",
            );
        },
    };
    globals.turnstile = api;
    script.dispatchEvent(new dom.window.Event("load"));
    callback(script);
    assert.equal(await loaded, api);
    assert.equal(readyCalls, 0);
    assert.equal(loadCaptcha("turnstile", "test-site-key"), loaded);
    assert.equal(document.querySelectorAll("script").length, 1);
});

test("reCAPTCHA v2 and v3 wait for ready before exposing their API", async () => {
    for (const type of ["recaptcha", "recaptcha-v3"]) {
        delete globals.grecaptcha;
        let ready!: () => void;
        const loaded = loadCaptcha(type, "google-test-key");
        const scripts = document.querySelectorAll("script");
        const script = scripts[scripts.length - 1]!;
        assert.equal(
            new URL(script.src).searchParams.get("render"),
            type === "recaptcha-v3" ? "google-test-key" : "explicit",
        );
        const api: CaptchaAPI = {
            render: () => 1,
            reset() {},
            ready(fn) {
                ready = fn;
            },
        };
        globals.grecaptcha = api;
        callback(script);
        let resolved = false;
        void loaded.then(() => {
            resolved = true;
        });
        await Promise.resolve();
        assert.equal(resolved, false);
        ready();
        assert.equal(await loaded, api);
    }
});
