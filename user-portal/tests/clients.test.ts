import { test } from "node:test";
import assert from "node:assert/strict";
import { clientImportUrl, resolveClient } from "../src/live/clients";

test("backend client labels and links override theme defaults together", () => {
    const site = {
        windows_version: "v2rayN",
        windows_download_url: "https://downloads.example.test/v2rayN.zip",
        macos_version: "v2rayN for macOS",
        macos_download_url: "https://downloads.example.test/v2rayN-mac.zip",
        android_version: "v2rayNG",
        android_download_url: "https://downloads.example.test/v2rayNG.apk",
    };
    for (const [device, name, url] of [
        ["Windows", site.windows_version, site.windows_download_url],
        ["macOS", site.macos_version, site.macos_download_url],
        ["Android", site.android_version, site.android_download_url],
    ] as const) {
        const client = resolveClient(device, site, {
            [device]: "https://old-theme.example.test/old.zip",
        });
        assert.equal(client.name, name);
        assert.equal(client.url, url);
        assert.equal(
            clientImportUrl(
                device,
                client.name,
                "https://panel.example.test/sub?token=fixture",
                "站点",
            ),
            null,
        );
    }
});

test("partial and invalid custom configuration never mixes unrelated applications", () => {
    const nameOnly = resolveClient("Windows", { windows_version: "v2rayN" });
    assert.equal(nameOnly.url, null);
    const linkOnly = resolveClient("Android", {
        android_download_url: "https://downloads.example.test/app.apk",
    });
    assert.equal(linkOnly.name, "Android 客户端");
    for (const address of [
        "javascript:alert(1)",
        "not a url",
        "file:///tmp/app.zip",
    ]) {
        assert.equal(
            resolveClient("Windows", { windows_download_url: address }).url,
            null,
        );
    }
    const overridden = resolveClient("macOS", null, {
        macOS: "https://downloads.example.test/client.dmg",
    });
    assert.equal(overridden.name, "macOS 客户端");
    assert.equal(
        clientImportUrl(
            "macOS",
            overridden.name,
            "https://panel.example.test/sub",
            "站点",
        ),
        null,
    );
});

test("unconfigured clients keep their matching default import schemes", () => {
    const sub = "https://panel.example.test/sub?token=fixture&flag=clash";
    for (const [device, scheme] of [
        ["Windows", "clash-verge://"],
        ["macOS", "clash-verge://"],
        ["Android", "flclash://"],
        ["iOS", "shadowrocket://"],
    ] as const) {
        const client = resolveClient(device, {});
        const url = clientImportUrl(device, client.name, sub, "星旅");
        assert.ok(url?.startsWith(scheme));
        if (device !== "iOS")
            assert.equal(new URL(url!).searchParams.get("url"), sub);
    }
    assert.equal(
        clientImportUrl(
            "Windows",
            "Clash Verge Rev",
            "javascript:alert(1)",
            "站点",
        ),
        null,
    );
});
