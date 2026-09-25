"use client";

import { Alert, Badge, Button, Group, Pagination, SimpleGrid, Stack, Table, Text } from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconDownload, IconEdit, IconUserOff } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { ApiError, customersApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import NoteModal from "@/components/shared/NoteModal";
import QueryState from "@/components/shared/QueryState";
import StatTile from "@/components/shared/StatTile";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import CustomerFormModal from "@/modules/customers/components/CustomerFormModal";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

/** One customer: details, purchase history, and data-protection actions for Owner/Admin. */
export default function CustomerDetailView({ title, section, props }: WorkspaceViewProps) {
  const customerId = Number(props?.customerId);
  const { can } = usePermissions();
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState(false);
  const [anonymising, setAnonymising] = useState(false);

  const fetchCustomer = useCallback(() => customersApi.get(customerId), [customerId]);
  const customer = useApiQuery(fetchCustomer);
  const fetchSales = useCallback(() => (can(PERMISSIONS.SALES_VIEW) ? customersApi.sales(customerId, page) : Promise.resolve(null)), [customerId, page, can]);
  const sales = useApiQuery(fetchSales);
  const c = customer.data;

  const exportData = async () => {
    try {
      await customersApi.exportData(customerId);
      notifications.show({ color: "green", message: "Customer data downloaded. The export was logged." });
    } catch (e) {
      notifications.show({ color: "red", message: e instanceof ApiError ? e.message : "Export failed." });
    }
  };

  return (
    <WorkspacePage
      section={section}
      title={c?.name ?? title}
      description={c ? [c.isWholesale ? "Wholesale customer" : "Retail business customer", c.kraPin ? `KRA PIN ${c.kraPin}` : "No KRA PIN"].join(" · ") : undefined}
      actions={
        c &&
        !c.anonymisedAt && (
          <>
            {can(PERMISSIONS.CUSTOMERS_MANAGE) && (
              <Button leftSection={<IconEdit size={16} />} onClick={() => setEditing(true)}>
                Edit
              </Button>
            )}
            {can(PERMISSIONS.CUSTOMERS_PRIVACY) && (
              <>
                <Button variant="default" leftSection={<IconDownload size={16} />} onClick={() => void exportData()}>
                  Export their data
                </Button>
                <Button variant="default" color="red" leftSection={<IconUserOff size={16} />} onClick={() => setAnonymising(true)}>
                  Anonymise
                </Button>
              </>
            )}
          </>
        )
      }
    >
      <QueryState loading={customer.loading} error={customer.error} isEmpty={false} onRetry={customer.reload}>
        {c && (
          <Stack gap="lg">
            {c.anonymisedAt && (
              <Alert color="gray">
                Personal data was removed on {dayjs(c.anonymisedAt).format("D MMM YYYY")}. Sales records are kept for tax purposes.
              </Alert>
            )}
            <SimpleGrid cols={{ base: 2, md: 4 }}>
              <StatTile label="Purchases" value={formatKes(c.salesTotalCents ?? 0)} hint={`${c.salesCount ?? 0} sales`} />
              <StatTile label="Last purchase" value={c.lastPurchaseAt ? dayjs(c.lastPurchaseAt).format("D MMM YYYY") : "—"} />
              <StatTile label="Price" value={c.isWholesale ? "Wholesale" : "Retail"} />
              <StatTile label="Status" value={c.anonymisedAt ? "Anonymised" : c.isActive ? "Active" : "Inactive"} />
            </SimpleGrid>

            {can(PERMISSIONS.CUSTOMERS_MANAGE) && !c.anonymisedAt && (
              <DataCard>
                <SimpleGrid cols={{ base: 1, sm: 4 }} p="md">
                  <Detail label="Contact person" value={c.contactName} />
                  <Detail label="Phone" value={c.phone} />
                  <Detail label="Email" value={c.email} />
                  <Detail label="Notes" value={c.notes} />
                </SimpleGrid>
                <Text size="xs" c="dimmed" px="md" pb="sm">
                  Contact details are visible to managers only. Opening this record is logged.
                </Text>
              </DataCard>
            )}

            {sales.data && (
              <DataCard>
                <Text fw={700} p="md" pb={0}>
                  Purchase history
                </Text>
                <QueryState loading={sales.loading} error={sales.error} isEmpty={!sales.data.items.length} emptyMessage="No purchases yet." onRetry={sales.reload}>
                  <Table verticalSpacing="sm">
                    <Table.Thead>
                      <Table.Tr>
                        <Table.Th>Receipt</Table.Th>
                        <Table.Th>Branch</Table.Th>
                        <Table.Th>Items</Table.Th>
                        <Table.Th ta="right">Total</Table.Th>
                        <Table.Th>Status</Table.Th>
                      </Table.Tr>
                    </Table.Thead>
                    <Table.Tbody>
                      {sales.data.items.map((s) => (
                        <Table.Tr key={s.id}>
                          <Table.Td>
                            <Text size="sm" ff="monospace" fw={600}>
                              {s.number}
                            </Text>
                            <Text size="xs" c="dimmed">
                              {dayjs(s.completedAt).format("DD MMM YYYY, h:mm a")}
                            </Text>
                          </Table.Td>
                          <Table.Td>{s.branch.name}</Table.Td>
                          <Table.Td>
                            <Text size="sm" lineClamp={2}>
                              {s.lines.map((l) => `${l.quantity} × ${l.variant.displayName}`).join(", ")}
                            </Text>
                          </Table.Td>
                          <Table.Td ta="right">
                            {formatKes(s.totalCents)}
                            {s.returnedCents > 0 && (
                              <Text size="xs" c="red.7">
                                −{formatKes(s.returnedCents)} returned
                              </Text>
                            )}
                          </Table.Td>
                          <Table.Td>
                            <Badge variant="light" color={s.status === "completed" ? "green" : "yellow"}>
                              {s.status === "completed" ? "Completed" : s.status === "returned" ? "Returned" : "Part returned"}
                            </Badge>
                          </Table.Td>
                        </Table.Tr>
                      ))}
                    </Table.Tbody>
                  </Table>
                </QueryState>
                {sales.data.meta.lastPage > 1 && (
                  <Group justify="flex-end" p="sm">
                    <Pagination value={page} onChange={setPage} total={sales.data.meta.lastPage} size="sm" />
                  </Group>
                )}
              </DataCard>
            )}
          </Stack>
        )}
      </QueryState>

      {editing && c && (
        <CustomerFormModal
          customer={c}
          onClose={() => setEditing(false)}
          onSaved={() => {
            setEditing(false);
            customer.reload();
          }}
        />
      )}
      {anonymising && c && (
        <NoteModal
          title={`Anonymise ${c.name}`}
          description="Removes the name, KRA PIN, contact person, phone, email and notes from this record. Their sales stay (including the PIN printed on past tax invoices). This cannot be undone."
          label="Reason (e.g. the customer asked to be forgotten)"
          confirmLabel="Anonymise"
          danger
          successMessage="Personal data removed."
          onSubmit={(reason) => customersApi.anonymise(c.id, reason)}
          onClose={() => setAnonymising(false)}
          onDone={() => {
            setAnonymising(false);
            customer.reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}

function Detail({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <Text size="xs" c="dimmed">
        {label}
      </Text>
      <Text size="sm">{value || "—"}</Text>
    </div>
  );
}
