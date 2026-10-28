<script setup lang="ts">
import { onMounted, ref } from "vue";
import {
    ArrowLeft,
    ArrowRight,
    Plus,
    RefreshCw,
    Ticket as TicketIcon,
} from "@lucide/vue";
import { api } from "../api";
import { notify, portal } from "../store";
import { formatDate } from "../format";
import type { Ticket } from "../types";
import PortalDialog from "./PortalDialog.vue";
import ResourceState from "./ResourceState.vue";
defineEmits<{ close: [] }>();
const mode = ref<"list" | "new" | "detail">("list");
const subject = ref(""),
    message = ref(""),
    level = ref(1),
    reply = ref(""),
    error = ref("");
const selected = ref<Ticket | null>(null),
    loading = ref(false),
    busy = ref(false),
    confirmingClose = ref(false);
async function openTicket(ticket: Ticket) {
    mode.value = "detail";
    loading.value = true;
    error.value = "";
    selected.value = ticket;
    try {
        selected.value = await api.ticket(ticket.id);
    } catch (cause) {
        error.value = (cause as Error).message;
    } finally {
        loading.value = false;
    }
}
async function createTicket() {
    if (busy.value || !subject.value.trim() || !message.value.trim()) return;
    busy.value = true;
    error.value = "";
    try {
        await api.createTicket(
            subject.value.trim(),
            message.value.trim(),
            level.value,
        );
        subject.value = "";
        message.value = "";
        mode.value = "list";
        await portal.tickets.load();
        notify("工单已提交，可以在这里查看回复。");
    } catch (cause) {
        error.value = (cause as Error).message;
    } finally {
        busy.value = false;
    }
}
async function sendReply() {
    if (busy.value || !reply.value.trim() || !selected.value) return;
    busy.value = true;
    error.value = "";
    try {
        await api.replyTicket(selected.value.id, reply.value.trim());
        reply.value = "";
        await openTicket(selected.value);
        void portal.tickets.load();
        notify("回复已发送。");
    } catch (cause) {
        error.value = (cause as Error).message;
    } finally {
        busy.value = false;
    }
}
async function closeTicket() {
    if (busy.value || !selected.value) return;
    busy.value = true;
    error.value = "";
    try {
        await api.closeTicket(selected.value.id);
        selected.value.status = 1;
        confirmingClose.value = false;
        void portal.tickets.load();
        notify("工单已关闭。");
    } catch (cause) {
        error.value = (cause as Error).message;
    } finally {
        busy.value = false;
    }
}
onMounted(() => void portal.tickets.load());
</script>
<template>
    <PortalDialog
        :title="
            mode === 'list'
                ? '帮助与工单'
                : mode === 'new'
                  ? '提交新工单'
                  : selected?.subject || '工单详情'
        "
        @close="$emit('close')"
        ><template v-if="mode === 'list'"
            ><div class="section-heading ticket-toolbar">
                <span class="muted caption">历史记录与支持回复</span
                ><button
                    class="button button-brand"
                    @click="
                        mode = 'new';
                        error = '';
                    "
                >
                    <Plus :size="15" />新建工单
                </button>
            </div>
            <ResourceState
                :loading="portal.tickets.loading"
                :error="portal.tickets.error"
                @retry="portal.tickets.load"
            />
            <div
                v-if="portal.tickets.data?.length === 0"
                class="empty-state compact"
            >
                <TicketIcon :size="32" />
                <p>还没有工单，遇到问题可以联系我们。</p>
            </div>
            <button
                v-for="ticket in portal.tickets.data"
                :key="ticket.id"
                class="ticket-list-item"
                @click="openTicket(ticket)"
            >
                <span
                    ><strong>{{ ticket.subject }}</strong
                    ><small>{{
                        formatDate(ticket.updated_at, true)
                    }}</small></span
                ><span class="pill">{{
                    ticket.status
                        ? "已关闭"
                        : ticket.reply_status
                          ? "等待你的回复"
                          : "处理中"
                }}</span>
            </button></template
        ><template v-else
            ><button
                class="text-button back-button"
                @click="
                    mode = 'list';
                    error = '';
                    confirmingClose = false;
                "
            >
                <ArrowLeft :size="14" />返回工单列表
            </button>
            <form v-if="mode === 'new'" @submit.prevent="createTicket">
                <label class="form-label" for="support-subject">问题标题</label
                ><input
                    id="support-subject"
                    v-model="subject"
                    class="form-input"
                    required
                    maxlength="100"
                    placeholder="例如：macOS 导入后无法连接"
                /><label class="form-label" for="support-level">优先级</label
                ><select id="support-level" v-model="level" class="form-input">
                    <option :value="0">低 · 使用咨询</option>
                    <option :value="1">中 · 功能异常</option>
                    <option :value="2">高 · 服务不可用</option></select
                ><label class="form-label" for="support-message">详细描述</label
                ><textarea
                    id="support-message"
                    v-model="message"
                    class="form-input"
                    rows="5"
                    maxlength="10000"
                    required
                    placeholder="请提供设备、客户端版本与错误信息。请勿提交密码或完整订阅链接。"
                ></textarea>
                <p v-if="error" class="field-error" role="alert">{{ error }}</p>
                <button
                    class="button button-brand full-width"
                    :disabled="busy || !subject.trim() || !message.trim()"
                    type="submit"
                >
                    {{ busy ? "正在提交…" : "提交工单"
                    }}<ArrowRight :size="16" />
                </button>
            </form>
            <template v-else
                ><ResourceState
                    :loading="loading"
                    :error="error"
                    @retry="selected && openTicket(selected)"
                /><template v-if="selected && !loading"
                    ><div class="ticket-status">
                        <span class="pill">{{
                            selected.status ? "已关闭" : "处理中"
                        }}</span
                        ><button
                            class="text-button"
                            :disabled="busy"
                            @click="openTicket(selected)"
                        >
                            <RefreshCw :size="13" />刷新回复
                        </button>
                    </div>
                    <div class="ticket-messages">
                        <article
                            v-for="item in selected.message"
                            :key="item.id"
                            :class="['ticket-message', { own: item.is_me }]"
                        >
                            <div>
                                <strong>{{
                                    item.is_me ? "我" : "支持团队"
                                }}</strong
                                ><small>{{
                                    formatDate(item.created_at, true)
                                }}</small>
                            </div>
                            <p>{{ item.message }}</p>
                        </article>
                        <p
                            v-if="!selected.message?.length"
                            class="muted caption"
                        >
                            暂无消息。
                        </p>
                    </div>
                    <form v-if="!selected.status" @submit.prevent="sendReply">
                        <label class="form-label" for="support-reply"
                            >回复工单</label
                        ><textarea
                            id="support-reply"
                            v-model="reply"
                            class="form-input"
                            rows="3"
                            maxlength="10000"
                            required
                            placeholder="补充问题信息…"
                        ></textarea>
                        <div class="dialog-footer">
                            <button
                                type="button"
                                class="text-button"
                                :disabled="busy"
                                @click="confirmingClose = !confirmingClose"
                            >
                                关闭此工单</button
                            ><button
                                class="button button-brand"
                                :disabled="busy || !reply.trim()"
                                type="submit"
                            >
                                {{ busy ? "处理中…" : "发送回复"
                                }}<ArrowRight :size="15" />
                            </button>
                        </div>
                        <div v-if="confirmingClose" class="inline-confirm">
                            <p>确认关闭工单？关闭后将无法继续回复。</p>
                            <button
                                type="button"
                                class="button button-secondary"
                                :disabled="busy"
                                @click="closeTicket"
                            >
                                确认关闭
                            </button>
                        </div>
                    </form></template
                ></template
            ></template
        ></PortalDialog
    >
</template>
