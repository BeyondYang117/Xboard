export type Period =
    | "month_price"
    | "quarter_price"
    | "half_year_price"
    | "year_price"
    | "two_year_price"
    | "three_year_price"
    | "onetime_price"
    | "reset_price";
export interface Plan {
    id: number;
    name: string;
    content: string;
    transfer_enable: number;
    device_limit: number | null;
    speed_limit: number | null;
    capacity_limit: number | string | null;
    sell: boolean;
    renew: boolean;
    tags?: string[] | null;
    prices?: Record<string, number | string | null>;
    month_price?: number | null;
    quarter_price?: number | null;
    half_year_price?: number | null;
    year_price?: number | null;
    two_year_price?: number | null;
    three_year_price?: number | null;
    onetime_price?: number | null;
    reset_price?: number | null;
    reset_traffic_method?: number | null;
}
export interface UserInfo {
    email: string;
    balance: number;
    banned: number | boolean;
    remind_expire: number;
    remind_traffic: number;
    created_at: number;
}
export interface Subscription {
    plan_id: number | null;
    plan?: Plan;
    expired_at: number | null;
    transfer_enable: number;
    u: number;
    d: number;
    device_limit: number | null;
    speed_limit: number | null;
    subscribe_url: string;
    next_reset_at: number | null;
    reset_day: number | null;
}
export interface TrafficRecord {
    u: number;
    d: number;
    record_at: number;
    server_rate?: number;
}
export interface Article {
    id: number;
    category: string;
    title: string;
    body: string;
    updated_at?: number;
}
export interface Notice {
    id: number;
    title: string;
    content: string;
    created_at: number;
}
export interface TicketMessage {
    id: number;
    is_me: boolean;
    message: string;
    created_at: number;
}
export interface Ticket {
    id: number;
    subject: string;
    level: number;
    status: number;
    reply_status: number;
    updated_at: number;
    message?: TicketMessage[] | null;
}
export interface Order {
    trade_no: string;
    plan: Plan | null;
    period: Period;
    status: number;
    type: number;
    total_amount: number;
    balance_amount?: number | null;
    surplus_amount?: number | null;
    surplus_credit?: number | null;
    discount_amount?: number | null;
    handling_amount?: number | null;
    created_at: number;
}
export interface PaymentMethod {
    id: number;
    name: string;
    payment: string;
    handling_fee_fixed: number | null;
    handling_fee_percent: number | null;
}
export interface PaymentResult {
    type: number;
    data: string | boolean;
}
export interface GuestConfig {
    tos_url?: string | null;
    is_email_verify: number;
    is_invite_force: number;
    email_whitelist_suffix?: string[] | number;
    is_captcha: number;
    captcha_type?: "turnstile" | "recaptcha" | "recaptcha-v3";
    turnstile_site_key?: string | null;
    recaptcha_site_key?: string | null;
    recaptcha_v3_site_key?: string | null;
}
export interface AuthData {
    auth_data: string;
}
export interface SiteConfig {
    reset_traffic_method?: number | null;
    currency?: string;
    currency_symbol?: string;
    windows_version?: string | null;
    windows_download_url?: string | null;
    macos_version?: string | null;
    macos_download_url?: string | null;
    android_version?: string | null;
    android_download_url?: string | null;
}
export type CaptchaPayload = Partial<
    Record<"turnstile_token" | "recaptcha_data" | "recaptcha_v3_token", string>
>;
