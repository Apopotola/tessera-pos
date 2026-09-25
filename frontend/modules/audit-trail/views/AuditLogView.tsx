"use client";

import { Code, Group, Pagination, Paper, Stack, Table, Text, TextInput } from "@mantine/core";
import { useDebouncedValue } from "@mantine/hooks";
import { IconSearch } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { auditTrailApi } from "@/api";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import PageHeader from "@/components/shared/PageHeader";
import QueryState from "@/components/shared/QueryState";
import { useApiQuery } from "@/hooks/useApiQuery";

const PER_PAGE = 25;

export default function AuditLogView({ title }: WorkspaceViewProps) {
  const [page, setPage] = useState(1);
  const [action, setAction] = useState("");
  const [debouncedAction] = useDebouncedValue(action.trim(), 400);

  const fetchLogs = useCallback(
    () => auditTrailApi.logs({ page, per_page: PER_PAGE, action: debouncedAction || undefined }),
    [page, debouncedAction],
  );
  const { data, loading, error, reload } = useApiQuery(fetchLogs);

  return (
    <Stack p="md" gap="md">
      <PageHeader title={title} description="Read-only record of sign-ins and sensitive actions. Entries cannot be edited or deleted." />

      <TextInput
        placeholder="Filter by exact action, e.g. auth.login.failed"
        leftSection={<IconSearch size={16} />}
        value={action}
        onChange={(event) => {
          setAction(event.currentTarget.value);
          setPage(1);
        }}
        maw={420}
      />

      <Paper withBorder>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="No audit entries match." onRetry={reload}>
          <Table striped verticalSpacing="xs">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>When</Table.Th>
                <Table.Th>Action</Table.Th>
                <Table.Th>User</Table.Th>
                <Table.Th>Entity</Table.Th>
                <Table.Th>Reason</Table.Th>
                <Table.Th>IP</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((entry) => (
                <Table.Tr key={entry.id}>
                  <Table.Td>
                    <Text size="sm">{dayjs(entry.occurredAt).format("DD MMM YYYY HH:mm:ss")}</Text>
                  </Table.Td>
                  <Table.Td>
                    <Code>{entry.action}</Code>
                  </Table.Td>
                  <Table.Td>{entry.user?.name ?? "—"}</Table.Td>
                  <Table.Td>
                    <Text size="sm" c="dimmed">
                      {entry.entityType ? `${entry.entityType.split("\\").pop()} #${entry.entityId}` : "—"}
                    </Text>
                  </Table.Td>
                  <Table.Td>{entry.reason ?? "—"}</Table.Td>
                  <Table.Td>{entry.ipAddress ?? "—"}</Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </Paper>

      {data && data.meta.lastPage > 1 && (
        <Group justify="flex-end">
          <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />
        </Group>
      )}
    </Stack>
  );
}
