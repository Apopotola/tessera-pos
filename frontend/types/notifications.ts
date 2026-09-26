/** Mirrors Modules\Notifications\Http\Controllers\NotificationController. */
import type { Paginated } from "@/types/api";

export type AlertType = "low_stock" | "large_refund" | "cash_variance" | "etims_failure" | "daily_summary";

export interface InboxItem {
  /** The recipient row (mark read with this id). */
  id: number;
  type: AlertType;
  title: string;
  body: string;
  /** The screen that acts on the alert. */
  link: { title: string; path: string; view: string } | null;
  createdAt: string;
  readAt: string | null;
}

export interface Inbox {
  unread: number;
  items: InboxItem[];
}

export interface OutboundMessageRow {
  id: number;
  channel: "sms" | "whatsapp" | "email";
  recipient: string;
  subject: string | null;
  body: string;
  status: "pending" | "sent" | "failed";
  /** "log" = demo: written to the server log, not delivered. */
  driver: string | null;
  attempts: number;
  lastError: string | null;
  createdAt: string | null;
  sentAt: string | null;
}

export type OutboundMessages = Paginated<OutboundMessageRow>;
