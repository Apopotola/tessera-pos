"use client";

import { ActionIcon, Button, Table, Text, Tooltip } from "@mantine/core";
import { IconEdit, IconPlus } from "@tabler/icons-react";
import { useState } from "react";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import StatusBadge from "@/components/shared/StatusBadge";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { usePermissions } from "@/hooks/usePermissions";
import SupplierFormModal from "@/modules/purchasing/components/SupplierFormModal";
import { useSuppliers } from "@/modules/purchasing/hooks/useSuppliers";
import { PERMISSIONS } from "@/types/permissions";
import type { Supplier } from "@/types/purchasing";

export default function SuppliersView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const suppliers = useSuppliers();
  const [dialog, setDialog] = useState<{ supplier?: Supplier } | null>(null);
  const canManage = can(PERMISSIONS.PURCHASING_MANAGE);

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Distributors you buy from. Their KRA PIN and payment terms are used on purchase orders and invoices."
      actions={
        canManage && (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setDialog({})}>
            Add supplier
          </Button>
        )
      }
    >
      <DataCard>
        <QueryState loading={suppliers.loading} error={suppliers.error} isEmpty={!suppliers.data?.length} emptyMessage="No suppliers yet." onRetry={suppliers.reload}>
          <Table verticalSpacing="sm" highlightOnHover>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Supplier</Table.Th>
                <Table.Th>KRA PIN</Table.Th>
                <Table.Th>Contact</Table.Th>
                <Table.Th ta="right">Terms</Table.Th>
                <Table.Th>Status</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {suppliers.data?.map((s) => (
                <Table.Tr key={s.id}>
                  <Table.Td fw={600}>{s.name}</Table.Td>
                  <Table.Td>
                    <Text size="sm" ff="monospace">
                      {s.kraPin ?? "—"}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">{s.contactPerson ?? "—"}</Text>
                    <Text size="xs" c="dimmed">
                      {[s.phone, s.email].filter(Boolean).join(" · ")}
                    </Text>
                  </Table.Td>
                  <Table.Td ta="right">{s.paymentTermsDays} days</Table.Td>
                  <Table.Td>
                    <StatusBadge active={s.isActive} />
                  </Table.Td>
                  <Table.Td ta="right">
                    {canManage && (
                      <Tooltip label={`Edit ${s.name}`}>
                        <ActionIcon variant="subtle" color="gray" aria-label={`Edit ${s.name}`} onClick={() => setDialog({ supplier: s })}>
                          <IconEdit size={16} />
                        </ActionIcon>
                      </Tooltip>
                    )}
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      {dialog && (
        <SupplierFormModal
          supplier={dialog.supplier}
          onClose={() => setDialog(null)}
          onSaved={() => {
            setDialog(null);
            suppliers.reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}
