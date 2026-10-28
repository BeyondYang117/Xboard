import { afterEach, test } from "node:test";
import assert from "node:assert/strict";
import { api, authToken, request, setAuthToken } from "../src/live/api";
const originalFetch = globalThis.fetch;
const storage = new Map<string, string>();
Object.defineProperty(globalThis, "sessionStorage", {
    configurable: true,
    value: {
        getItem: (key: string) => storage.get(key) ?? null,
        setItem: (key: string, value: string) => storage.set(key, value),
        removeItem: (key: string) => storage.delete(key),
    },
});
afterEach(() => {
    globalThis.fetch = originalFetch;
    setAuthToken("");
});

test("authenticated requests use same-origin bearer and preserve zero response values", async () => {
    setAuthToken("Bearer test-only-token");
    globalThis.fetch = async (input, init) => {
        assert.equal(input, "/api/v1/user/order/check?trade_no=DEMO");
        assert.equal(init?.credentials, "same-origin");
        assert.equal(
            (init?.headers as Record<string, string>).Authorization,
            "Bearer test-only-token",
        );
        return new Response(JSON.stringify({ status: "success", data: 0 }));
    };
    assert.equal(await api.orderStatus("DEMO"), 0);
});
test("login requests never send an existing bearer token", async () => {
    setAuthToken("Bearer old-test-token");
    globalThis.fetch = async (_input, init) => {
        assert.equal(
            (init?.headers as Record<string, string>).Authorization,
            undefined,
        );
        assert.equal(init?.method, "POST");
        return new Response(
            JSON.stringify({ data: { auth_data: "Bearer new-test-token" } }),
        );
    };
    assert.equal(
        (
            await api.login({
                email: "test@example.test",
                password: "test-only-password",
            })
        ).auth_data,
        "Bearer new-test-token",
    );
});
test("authorization failure clears the session but validation failure keeps it", async () => {
    setAuthToken("Bearer test-token");
    globalThis.fetch = async () =>
        new Response(JSON.stringify({ message: "请重新登录" }), {
            status: 403,
        });
    await assert.rejects(api.user(), /请重新登录/);
    assert.equal(authToken.value, "");
    setAuthToken("Bearer test-token");
    globalThis.fetch = async () =>
        new Response(JSON.stringify({ errors: { period: ["周期不可用"] } }), {
            status: 422,
        });
    await assert.rejects(api.user(), /周期不可用/);
    assert.equal(authToken.value, "Bearer test-token");
});
test("payment type envelopes remain intact and mutations are not retried automatically", async () => {
    let calls = 0;
    globalThis.fetch = async () => {
        calls++;
        return new Response(JSON.stringify({ type: 0, data: "qr-payload" }));
    };
    assert.deepEqual(await api.checkout("DEMO", 1), {
        type: 0,
        data: "qr-payload",
    });
    assert.equal(calls, 1);
    globalThis.fetch = async () => {
        calls++;
        throw new TypeError("offline");
    };
    await assert.rejects(
        request("/user/order/save", { body: {} }),
        /暂时无法连接/,
    );
    assert.equal(calls, 2);
});

test("logout clears local auth after revocation, a missing route, or a network failure", async () => {
    for (const scenario of ["success", "missing", "offline", "expired"]) {
        setAuthToken("Bearer logout-test");
        let calls = 0;
        globalThis.fetch = async (input, init) => {
            calls++;
            assert.equal(input, "/api/v1/user/logout");
            assert.equal(init?.method, "POST");
            assert.equal(
                (init?.headers as Record<string, string>).Authorization,
                "Bearer logout-test",
            );
            if (scenario === "offline") throw new TypeError("offline");
            if (scenario === "missing" || scenario === "expired")
                return new Response(
                    JSON.stringify({ message: "unavailable" }),
                    {
                        status: scenario === "missing" ? 404 : 401,
                    },
                );
            return new Response(JSON.stringify({ data: true }));
        };
        assert.equal(
            await api.logout(),
            ["success", "expired"].includes(scenario) ? "revoked" : "local",
        );
        assert.equal(authToken.value, "");
        assert.equal(sessionStorage.getItem("xboard-portal-auth"), null);
        assert.equal(calls, 1);
    }
});

test("a delayed logout does not clear a newly established login", async () => {
    setAuthToken("Bearer previous-session");
    let finish!: (value: Response) => void;
    globalThis.fetch = () =>
        new Promise<Response>((resolve) => {
            finish = resolve;
        });
    const pending = api.logout();
    assert.equal(authToken.value, "");
    assert.equal(sessionStorage.getItem("xboard-portal-auth"), null);
    setAuthToken("Bearer new-session");
    finish(new Response(JSON.stringify({ data: true })));
    assert.equal(await pending, "revoked");
    assert.equal(authToken.value, "Bearer new-session");
});
