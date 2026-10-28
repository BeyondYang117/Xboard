<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import {
    ArrowRight,
    ArrowUpRight,
    BookOpen,
    LifeBuoy,
    Search,
    Ticket,
} from "@lucide/vue";
import { portal } from "../store";
import { formatDate, plainText, safeRichText } from "../format";
import type { Article } from "../types";
import { api } from "../api";
import ResourceState from "./ResourceState.vue";
import PortalDialog from "./PortalDialog.vue";
defineEmits<{ support: [] }>();
const query = ref(""),
    category = ref("全部文章");
const categories = computed(() => [
    "全部文章",
    ...Object.keys(portal.articles.data ?? {}),
]);
const articles = computed(() =>
    Object.values(portal.articles.data ?? {}).flat(),
);
const filtered = computed(() =>
    articles.value.filter(
        (article) =>
            (category.value === "全部文章" ||
                article.category === category.value) &&
            `${article.title}${plainText(article.body)}`
                .toLowerCase()
                .includes(query.value.trim().toLowerCase()),
    ),
);
const selected = ref<Article | null>(null),
    articleError = ref(""),
    articleLoading = ref(false);
let sequence = 0;
async function openArticle(article: Article) {
    const current = ++sequence;
    selected.value = article;
    articleError.value = "";
    articleLoading.value = true;
    try {
        const result = await api.article(article.id);
        if (current === sequence) selected.value = result;
    } catch (cause) {
        if (current === sequence) articleError.value = (cause as Error).message;
    } finally {
        if (current === sequence) articleLoading.value = false;
    }
}
function closeArticle() {
    sequence++;
    selected.value = null;
}
onMounted(() => {
    if (!portal.articles.data) void portal.articles.load();
});
</script>
<template>
    <div class="page-heading">
        <div>
            <div class="eyebrow">A LITTLE HELP GOES A LONG WAY</div>
            <h1>让问题，到这里为止。</h1>
            <p>从第一次使用到日常排查，答案就在这里。</p>
        </div>
        <button class="button button-secondary" @click="$emit('support')">
            <Ticket :size="16" />联系支持
        </button>
    </div>
    <section class="help-search">
        <Search :size="22" /><input
            v-model="query"
            type="search"
            aria-label="搜索帮助文章"
            placeholder="搜索问题，例如：导入订阅、流量重置…"
        /><kbd>搜索</kbd>
    </section>
    <ResourceState
        :loading="portal.articles.loading"
        :error="portal.articles.error"
        @retry="portal.articles.load"
    /><template v-if="portal.articles.data && !portal.articles.error"
        ><div class="category-tabs" aria-label="文章分类">
            <button
                v-for="item in categories"
                :key="item"
                :class="{ active: category === item }"
                @click="category = item"
            >
                {{ item }}
            </button>
        </div>
        <div class="article-grid">
            <button
                v-for="article in filtered"
                :key="article.id"
                class="article-card panel"
                @click="openArticle(article)"
            >
                <span class="article-category"
                    ><BookOpen :size="15" />{{ article.category }}</span
                >
                <h2>{{ article.title }}</h2>
                <p class="article-excerpt">
                    {{ plainText(article.body).slice(0, 100) }}
                </p>
                <span class="read-more"
                    >阅读指南 <ArrowUpRight :size="15"
                /></span>
            </button>
        </div>
        <div v-if="!filtered.length" class="panel empty-state">
            <Search :size="35" />
            <h2>
                {{
                    articles.length
                        ? "暂时没有找到相关内容"
                        : "使用指南正在准备中"
                }}
            </h2>
            <p>
                {{
                    articles.length
                        ? "试试更短的关键词，或联系我们。"
                        : "你可以先查看设备配置引导，或联系支持。"
                }}
            </p>
            <button
                v-if="query || category !== '全部文章'"
                class="button button-secondary"
                @click="
                    query = '';
                    category = '全部文章';
                "
            >
                清除筛选</button
            ><a v-else href="#guide" class="button button-secondary"
                >查看设备配置</a
            >
        </div></template
    >
    <section class="support-banner">
        <span class="support-icon"><LifeBuoy :size="28" /></span>
        <div>
            <h2>需要一点额外帮助？</h2>
            <p>描述你的设备和遇到的问题，我们一起解决。</p>
        </div>
        <button class="button button-secondary" @click="$emit('support')">
            查看工单 / 联系支持 <ArrowRight :size="16" />
        </button>
    </section>
    <PortalDialog v-if="selected" :title="selected.title" @close="closeArticle"
        ><ResourceState
            :loading="articleLoading"
            :error="articleError"
            @retry="openArticle(selected!)" /><template
            v-if="!articleLoading && !articleError"
            ><span class="article-category"
                >{{ selected.category
                }}<span v-if="selected.updated_at">
                    · {{ formatDate(selected.updated_at) }}</span
                ></span
            >
            <div class="rich-text" v-html="safeRichText(selected.body)"></div>
            <div class="dialog-footer">
                <span>还需要帮助？</span
                ><button
                    class="text-button"
                    @click="
                        closeArticle();
                        $emit('support');
                    "
                >
                    联系支持 <ArrowRight :size="15" />
                </button></div></template
    ></PortalDialog>
</template>
