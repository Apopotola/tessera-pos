import { api } from "@/api/client";
import { NOTIFICATIONS_URLS as U } from "@/api/urls";
import type { Inbox, OutboundMessages } from "@/types/notifications";

export const notificationsApi = {
  /** My alerts. Polled by the bell; the API does not count it as activity for the idle sign-out. */
  inbox: () => api.get<Inbox>(U.inbox),
  read: (id: number) => api.post<null>(U.read(id)),
  readAll: () => api.post<null>(U.readAll),
  /** SMS / WhatsApp / email sent or waiting (owners). */
  messages: (page = 1) => api.get<OutboundMessages>(U.messages, { page }),
};
