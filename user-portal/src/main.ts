import { createApp } from "vue";
import { isDemo } from "./live/config";
import "./style.css";
import "./live/live.css";
import "./live/skins.css";

async function mount() {
    const { default: App } = isDemo
        ? await import("./App.vue")
        : await import("./live/LiveApp.vue");
    createApp(App).mount("#app");
}
void mount();
