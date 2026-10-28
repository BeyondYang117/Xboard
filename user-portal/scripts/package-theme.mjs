import { readFile, readdir, mkdir, rm, cp, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";
import { zipSync } from "fflate";

const root = fileURLToPath(new URL("../", import.meta.url));
const config = JSON.parse(
    await readFile(path.join(root, "theme/config.json"), "utf8"),
);
const manifest = JSON.parse(
    await readFile(path.join(root, "dist/.vite/manifest.json"), "utf8"),
);
const entry = Object.values(manifest).find((asset) => asset.isEntry);
const css = entry?.css?.[0] ?? manifest["style.css"]?.file;
if (
    !entry ||
    !/^assets\/portal-[\w-]+\.js$/.test(entry.file) ||
    !css ||
    !/^assets\/portal-[\w-]+\.css$/.test(css)
)
    throw new Error("Missing content-hashed portal entry or stylesheet");
if (!/^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(config.name))
    throw new Error("Invalid theme name");
const output = path.join(root, "release", config.name);
await rm(output, { recursive: true, force: true });
await mkdir(output, { recursive: true });
await cp(path.join(root, "theme"), output, { recursive: true });
await cp(path.join(root, "dist/assets"), path.join(output, "assets"), {
    recursive: true,
});
const bladePath = path.join(output, "dashboard.blade.php");
const blade = await readFile(bladePath, "utf8");
if (!blade.includes("assets/portal.js") || !blade.includes("assets/portal.css"))
    throw new Error("Missing Blade asset placeholders");
await writeFile(
    bladePath,
    blade
        .replaceAll("assets/portal.js", entry.file)
        .replaceAll("assets/portal.css", css),
);
for (const required of ["dashboard.blade.php", "config.json", entry.file, css])
    await readFile(path.join(output, required));
const files = {};
async function collect(directory, prefix = "") {
    for (const entry of await readdir(directory, { withFileTypes: true })) {
        const relative = `${prefix}${entry.name}`;
        if (entry.isDirectory())
            await collect(path.join(directory, entry.name), `${relative}/`);
        else
            files[relative] = [
                new Uint8Array(
                    await readFile(path.join(directory, entry.name)),
                ),
                { mtime: new Date("2020-01-01T00:00:00Z") },
            ];
    }
}
await collect(output);
const archive = zipSync(files, { level: 9 });
if (archive.length > 10 * 1024 * 1024)
    throw new Error("Theme exceeds the 10 MB upload limit");
const zipPath = path.join(
    root,
    "release",
    `${config.name}-${config.version}.zip`,
);
await writeFile(zipPath, archive);
console.log(
    `Theme package: ${zipPath} (${(archive.length / 1024).toFixed(0)} KB)`,
);
