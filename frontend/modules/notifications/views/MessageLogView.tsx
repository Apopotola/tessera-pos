"use client";

import { Alert, Badge, Group, Pagination, Table, Text } from "@mantine/core";
import { IconInfoCircle } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { notificationsApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";

const CHANNEL = { sms: "SMS", whatsapp: "WhatsApp", email: "Email" } as const;
const STATUS_COLOR = { pending: "gray", sent: "green", failed: "red" } as const;

/** Every SMS, WhatsApp message and email the alerts produced, and whether it went out. */
export default function MessageLogView({ title, section }: WorkspaceViewProps) {
  const [page, setPage] = useState(1);
  const fetchMessages = useCallback(() => notificationsApi.messages(page), [page]);
  const { data, loading, error, reload } = useApiQuery(fetchMessages);
  const demo = data?.items.some((m) => m.driver === "log");

  return (
    <WorkspacePage section={section} title={title} description="Alerts sent to owners outside the app (Settings → Notifications → Channels). Messages go out within a minute.">
      {demo && (
        <Alert color="yellow" variant="light" icon={<IconInfoCircle size={18} />}>
          SMS and WhatsApp are in demo mode: messages marked &quot;demo&quot; were written to the server log, not delivered. They go out for real once an SMS / WhatsApp provider and a registered sender ID are set up.
        </Alert>
      )}
      <DataCard>
        <QueryState loading={loading && !data} error={error} isEmpty={!data?.items.length} emptyMessage="No messages yet." onRetry={reload}>
          <Table verticalSpacing="sm" fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>When</Table.Th>
                <Table.Th>Channel</Table.Th>
                <Table.Th>To</Table.Th>
                <Table.Th>Message</Table.Th>
                <Table.Th>Status</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((m) => (
                <Table.Tr key={m.id}>
                  <Table.Td style={{ whiteSpace: "nowrap" }}>{m.createdAt ? dayjs(m.createdAt).format("DD MMM, HH:mm") : "—"}</Table.Td>
                  <Table.Td>{CHANNEL[m.channel]}</Table.Td>
                  <Table.Td ff="monospace">{m.recipient}</Table.Td>
                  <Table.Td maw={460}>
                    {m.subject && (
                      <Text size="sm" fw={600}>
                        {m.subject}
                      </Text>
                    )}
                    <Text size="sm" lineClamp={3} style={{ whiteSpace: "pre-line" }}>
                      {m.body}
                    </Text>
                    {m.lastError && (
                      <Text size="xs" c="red">
                        {m.lastError}
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td>
                    <Group gap={4}>
                      <Badge color={STATUS_COLOR[m.status]} variant="light">
                        {m.status}
                      </Badge>
                      {m.driver === "log" && (
                        <Badge color="yellow" variant="outline">
                          demo
                        </Badge>
                      )}
                    </Group>
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
        {data && data.meta.lastPage > 1 && (
          <Group justify="flex-end" p="sm">
            <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />
          </Group>
        )}
      </DataCard>
    </WorkspacePage>
  );
}
