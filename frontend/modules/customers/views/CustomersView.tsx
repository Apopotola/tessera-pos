"use client";

import { Badge, Button, Group, Pagination, SegmentedControl, Table, Text, TextInput } from "@mantine/core";
import { useDebouncedValue } from "@mantine/hooks";
import { IconPlus, IconSearch } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { customersApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import StatusBadge from "@/components/shared/StatusBadge";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import CustomerFormModal from "@/modules/customers/components/CustomerFormModal";
import { customerTab } from "@/modules/customers/routes";
import { useAppDispatch } from "@/store/hooks";
import { openTab } from "@/store/slices/tabsSlice";
import type { CustomerType } from "@/types/customers";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

/** Registered wholesale and B2B customers. Walk-in sales need no customer. */
export default function CustomersView({ title, section, tabId }: WorkspaceViewProps) {
  const dispatch = useAppDispatch();
  const { can } = usePermissions();
  const [search, setSearch] = useState("");
  const [debounced] = useDebouncedValue(search.trim(), 300);
  const [type, setType] = useState<CustomerType>("all");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);

  const fetchPage = useCallback(() => customersApi.list({ search: debounced || undefined, type, page }), [debounced, type, page]);
  const { data, loading, error, reload } = useApiQuery(fetchPage);

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Bars, restaurants and businesses that buy at wholesale prices or need their KRA PIN on the tax invoice. Walk-in sales need no customer."
      actions={
        can(PERMISSIONS.CUSTOMERS_MANAGE) && (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setCreating(true)}>
            Register customer
          </Button>
        )
      }
    >
      <Group align="flex-end">
        <TextInput label="Search" placeholder="Name or KRA PIN" leftSection={<IconSearch size={16} />} value={search} onChange={(e) => (setSearch(e.currentTarget.value), setPage(1))} w={260} />
        <SegmentedControl
          value={type}
          onChange={(v) => (setType(v as CustomerType), setPage(1))}
          data={[
            { value: "all", label: "All" },
            { value: "wholesale", label: "Wholesale" },
            { value: "business", label: "Retail business" },
          ]}
        />
      </Group>

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="No customers registered yet." onRetry={reload}>
          <Table verticalSpacing="sm" highlightOnHover>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Customer</Table.Th>
                <Table.Th>KRA PIN</Table.Th>
                <Table.Th>Price</Table.Th>
                <Table.Th ta="right">Purchases</Table.Th>
                <Table.Th>Last purchase</Table.Th>
                <Table.Th>Status</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((c) => (
                <Table.Tr key={c.id} style={{ cursor: "pointer" }} onClick={() => dispatch(openTab(customerTab(c, tabId)))}>
                  <Table.Td>
                    <Text fw={600} size="sm">
                      {c.name}
                    </Text>
                    {c.contactName && (
                      <Text size="xs" c="dimmed">
                        {c.contactName}
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td ff="monospace">{c.kraPin ?? "—"}</Table.Td>
                  <Table.Td>
                    <Badge variant="light" color={c.isWholesale ? "tessera" : "gray"}>
                      {c.isWholesale ? "Wholesale" : "Retail"}
                    </Badge>
                  </Table.Td>
                  <Table.Td ta="right">
                    <Text size="sm">{formatKes(c.salesTotalCents ?? 0)}</Text>
                    <Text size="xs" c="dimmed">
                      {c.salesCount ?? 0} {c.salesCount === 1 ? "sale" : "sales"}
                    </Text>
                  </Table.Td>
                  <Table.Td>{c.lastPurchaseAt ? dayjs(c.lastPurchaseAt).format("DD MMM YYYY") : "—"}</Table.Td>
                  <Table.Td>{c.anonymisedAt ? <Badge color="gray">Anonymised</Badge> : <StatusBadge active={c.isActive} />}</Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      {data && data.meta.lastPage > 1 && (
        <Group justify="flex-end">
          <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />
        </Group>
      )}

      {creating && (
        <CustomerFormModal
          onClose={() => setCreating(false)}
          onSaved={(customer) => {
            setCreating(false);
            reload();
            dispatch(openTab(customerTab(customer, tabId)));
          }}
        />
      )}
    </WorkspacePage>
  );
}
