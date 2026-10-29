import { afterEach, test } from "node:test";
import assert from "node:assert/strict";
import { JSDOM } from "jsdom";
import { api, authToken, setAuthToken } from "../src/live/api";
import {
    clearLinuxDo,
    finishLinuxDo,
    linuxDoPending,
    startLinuxDo,
} from "../src/live/linuxdo";

const dom = new JSDOM("", { url: "https://panel.example.test/" });
for (const name of [
    "window",
    "location",
    "history",
    "sessionStorage",
] as const) {
    Object.defineProperty(globalThis, name, {
        configurable: true,
        value: dom.window[name],
    });
}
const originalFetch = globalThis.fetch;
const ticket = "T".repeat(64);
const verifier = "a".repeat(64);
const key = "xboard-portal-linuxdo-verifier";
afterEach(() => {
    globalThis.fetch = originalFetch;
    clearLinuxDo();
    setAuthToken("");
    history.replaceState(null, "", "/");
});

test("OAuth callback exchanges a fragment ticket with this tab's verifier, then clears URL and verifier", async () => {
    history.replaceState(null, "", `/#linuxdo?ticket=${ticket}`);
    sessionStorage.setItem(key, verifier);
    globalThis.fetch = async (input, init) => {
        assert.equal(location.hash, "#overview");
        assert.equal(sessionStorage.getItem(key), null);
        assert.equal(input, "/api/v1/passport/auth/linuxdo/exchange");
        assert.equal(init?.method, "POST");
        assert.equal(
            (init?.headers as Record<string, string>).Authorization,
            undefined,
        );
        assert.deepEqual(JSON.parse(init!.body as string), {
            ticket,
            verifier,
        });
        return new Response(
            JSON.stringify({ data: { auth_data: "Bearer linuxdo-test" } }),
        );
    };
    assert.equal(await finishLinuxDo(), true);
    assert.equal(authToken.value, "Bearer linuxdo-test");
    assert.equal(linuxDoPending.value, null);
    assert.equal(
        sessionStorage.getItem("xboard-portal-auth"),
        "Bearer linuxdo-test",
    );
});

test("an unbound identity holds only its pending proof in memory and does not create a local session", async () => {
    history.replaceState(null, "", `/#linuxdo?ticket=${ticket}`);
    sessionStorage.setItem(key, verifier);
    globalThis.fetch = async () =>
        new Response(
            JSON.stringify({
                data: {
                    requires_account: true,
                    username: "traveler",
                    trust_level: 2,
                },
            }),
        );
    await finishLinuxDo();
    assert.deepEqual(linuxDoPending.value, {
        ticket,
        verifier,
        username: "traveler",
    });
    assert.equal(authToken.value, "");
    assert.equal(sessionStorage.getItem(key), null);
    clearLinuxDo();
    assert.equal(linuxDoPending.value, null);
});

test("callbacks from another tab, invalid tickets and cancellations never exchange credentials", async () => {
    let calls = 0;
    globalThis.fetch = async () => {
        calls++;
        throw new Error("unexpected");
    };
    for (const [hash, proof, message] of [
        [`#linuxdo?ticket=${ticket}`, "", /当前标签页/],
        ["#linuxdo?ticket=invalid", verifier, /当前标签页/],
        ["#linuxdo?error=用户取消授权", verifier, /用户取消授权/],
    ] as const) {
        history.replaceState(null, "", `/${hash}`);
        if (proof) sessionStorage.setItem(key, proof);
        await assert.rejects(finishLinuxDo(), message);
        assert.equal(location.hash, "#overview");
        assert.equal(sessionStorage.getItem(key), null);
        assert.equal(authToken.value, "");
    }
    assert.equal(calls, 0);
});

test("failed exchanges clear callback secrets and leave an expired-session error", async () => {
    history.replaceState(null, "", `/#linuxdo?ticket=${ticket}`);
    sessionStorage.setItem(key, verifier);
    globalThis.fetch = async () =>
        new Response(JSON.stringify({ message: "授权已过期" }), {
            status: 400,
        });
    await assert.rejects(finishLinuxDo(), /授权已过期/);
    assert.equal(location.hash, "#overview");
    assert.equal(sessionStorage.getItem(key), null);
    assert.equal(authToken.value, "");
    assert.equal(linuxDoPending.value, null);
});

test("starting authorization generates a fresh cryptographic proof and rejects unexpected redirect origins", async () => {
    let previous = "";
    globalThis.fetch = async (input, init) => {
        assert.equal(input, "/api/v1/passport/auth/linuxdo/start");
        const { verifier: proof } = JSON.parse(init!.body as string);
        assert.match(proof, /^[a-f0-9]{64}$/);
        assert.notEqual(previous, proof);
        assert.equal(sessionStorage.getItem(key), proof);
        previous = proof;
        return new Response(
            JSON.stringify({
                data: { url: "https://untrusted.example/oauth2/authorize" },
            }),
        );
    };
    await assert.rejects(startLinuxDo(), /授权地址异常/);
    await assert.rejects(startLinuxDo(), /授权地址异常/);
    assert.equal(sessionStorage.getItem(key), null);
});

test("completing a binding sends the pending identity and account credentials only to the same-origin API", async () => {
    setAuthToken("Bearer previous");
    const body = {
        ticket,
        verifier,
        action: "login",
        email: "existing@example.test",
        password: "test-password",
    };
    globalThis.fetch = async (input, init) => {
        assert.equal(input, "/api/v1/passport/auth/linuxdo/complete");
        assert.equal(init?.credentials, "same-origin");
        assert.equal(
            (init?.headers as Record<string, string>).Authorization,
            undefined,
        );
        assert.deepEqual(JSON.parse(init!.body as string), body);
        return new Response(
            JSON.stringify({ data: { auth_data: "Bearer bound" } }),
        );
    };
    assert.equal((await api.linuxDoComplete(body)).auth_data, "Bearer bound");
});
