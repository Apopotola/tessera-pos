"use client";

import { Button, Group, Modal, Pagination, SegmentedControl, Select, SimpleGrid, Stack, Table, Text, TextInput, Textarea } from "@mantine/core";
import { useForm } from "@mantine/form";
import { IconPlus } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { purchasingApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import NoteModal from "@/components/shared/NoteModal";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import LineItemsEditor, { newLine, type LineRow } from "@/modules/inventory/components/LineItemsEditor";
import { DOCUMENT_STATUS_COLOR, STATUS_LABEL } from "@/modules/inventory/constants";
import { useLocations } from "@/modules/inventory/hooks/useLocations";
import { useSuppliers } from "@/modules/purchasing/hooks/useSuppliers";
import { useAppSelector } from "@/store/hooks";
import type { DocumentStatus } from "@/types/inventory";
import { PERMISSIONS } from "@/types/permissions";
import type { SupplierReturn } from "@/types/purchasing";

type Dialog = { kind: "create" } | { kind: "reject" | "credit"; ret: SupplierReturn };

export default function SupplierReturnsView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const userId = useAppSelector((state) => state.auth.user?.id);
  const [status, setStatus] = useState<DocumentStatus>("pending");
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState<Dialog | null>(null);

  const fetchReturns = useCallback(() => purchasingApi.returns(status, page), [status, page]);
  const { data, loading, error, reload } = useApiQuery(fetchReturns);
  const approve = useApiMutation((r: SupplierReturn) => purchasingApi.approveReturn(r.id), { successMessage: (r) => `${r.number} approved — stock updated.`, onSuccess: reload });

  const done = () => {
    setDialog(null);
    reload();
  };

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Stock going back to a supplier (corked, damaged, wrong item). It leaves stock once someone else approves; record the credit note when it arrives."
      actions={
        (can(PERMISSIONS.PURCHASING_MANAGE) || can(PERMISSIONS.INVENTORY_ADJUST)) && (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setDialog({ kind: "create" })}>
            New return
          </Button>
        )
      }
    >
      <SegmentedControl
        w="fit-content"
        value={status}
        onChange={(v) => {
          setStatus(v as DocumentStatus);
          setPage(1);
        }}
        data={[
          { value: "pending", label: "Waiting approval" },
          { value: "approved", label: "Approved" },
          { value: "rejected", label: "Rejected" },
        ]}
      />

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="Nothing here." onRetry={reload}>
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Number</Table.Th>
                <Table.Th>Supplier</Table.Th>
                <Table.Th>Items</Table.Th>
                <Table.Th>Reason</Table.Th>
                <Table.Th>Credit note</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((r) => {
                const own = r.requestedBy?.id === userId;
                return (
                  <Table.Tr key={r.id}>
                    <Table.Td>
                      <Text size="sm" ff="monospace">
                        {r.number}
                      </Text>
                      <Text size="xs" c="dimmed">
                        {r.requestedBy?.name} · {r.createdAt ? dayjs(r.createdAt).format("DD MMM") : ""}
                      </Text>
                    </Table.Td>
                    <Table.Td fw={600}>{r.supplier.name}</Table.Td>
                    <Table.Td>
                      {r.lines.map((l, i) => (
                        <Text key={i} size="sm">
                          {l.quantity} × {l.variant.displayName}
                        </Text>
                      ))}
                    </Table.Td>
                    <Table.Td maw={220}>
                      <Text size="sm" lineClamp={2}>
                        {r.reason}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      {r.creditNoteRef ? (
                        <Text size="sm" ff="monospace">
                          {r.creditNoteRef}
                        </Text>
                      ) : r.status === "approved" && can(PERMISSIONS.PURCHASING_MANAGE) ? (
                        <Button size="xs" variant="light" onClick={() => setDialog({ kind: "credit", ret: r })}>
                          Record
                        </Button>
                      ) : (
                        "—"
                      )}
                    </Table.Td>
                    <Table.Td>
                      {r.status === "pending" && can(PERMISSIONS.PURCHASING_APPROVE) && !own ? (
                        <Group gap="xs" wrap="nowrap">
                          <Button size="xs" loading={approve.pending} onClick={() => void approve.mutate(r)}>
                            Approve
                          </Button>
                          <Button size="xs" variant="light" color="red" onClick={() => setDialog({ kind: "reject", ret: r })}>
                            Reject
                          </Button>
                        </Group>
                      ) : (
                        <DocStatusBadge label={own && r.status === "pending" ? "Awaiting approver" : STATUS_LABEL[r.status]} color={DOCUMENT_STATUS_COLOR[r.status]} />
                      )}
                    </Table.Td>
                  </Table.Tr>
                );
              })}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      {data && data.meta.lastPage > 1 && (
        <Group justify="flex-end">
          <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />
        </Group>
      )}

      {dialog?.kind === "create" && <ReturnFormModal onClose={() => setDialog(null)} onSaved={done} />}
      {dialog?.kind === "reject" && (
        <NoteModal
          title={`Reject ${dialog.ret.number}`}
          label="Reason"
          confirmLabel="Reject"
          danger
          successMessage={`${dialog.ret.number} rejected.`}
          onSubmit={(note) => purchasingApi.rejectReturn(dialog.ret.id, note)}
          onClose={() => setDialog(null)}
          onDone={done}
        />
      )}
      {dialog?.kind === "credit" && <CreditNoteModal ret={dialog.ret} onClose={() => setDialog(null)} onDone={done} />}
    </WorkspacePage>
  );
}

function ReturnFormModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const suppliers = useSuppliers();
  const locations = useLocations();
  const form = useForm<{ supplierId: string | null; locationId: string | null; reason: string; lines: LineRow[] }>({
    initialValues: { supplierId: null, locationId: null, reason: "", lines: [newLine()] },
    validate: {
      supplierId: (v) => (v ? null : "Choose the supplier"),
      locationId: (v) => (v ? null : "Where is the stock?"),
      reason: (v) => (v.trim() ? null : "Why is it going back?"),
    },
  });

  const { mutate, pending } = useApiMutation(
    (v: typeof form.values) =>
      purchasingApi.createReturn({
        supplierId: Number(v.supplierId),
        locationId: Number(v.locationId),
        reason: v.reason.trim(),
        lines: v.lines.flatMap((l) => (l.variant ? [{ variantId: l.variant.id, quantity: Number(l.quantity) }] : [])),
      }),
    { successMessage: (r) => `${r.number} sent for approval.`, onValidationError: (e) => form.setErrors(e), onSuccess: onSaved },
  );

  return (
    <Modal opened onClose={onClose} title="Return to supplier" size="xl">
      <form onSubmit={form.onSubmit((v) => void mutate(v))} noValidate>
        <Stack>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <Select label="Supplier" data={suppliers.options} searchable required {...form.getInputProps("supplierId")} />
            <Select label="Take from" data={locations.options} required {...form.getInputProps("locationId")} />
          </SimpleGrid>
          <LineItemsEditor lines={form.values.lines} onChange={(lines) => form.setFieldValue("lines", lines)} errors={form.errors as Record<string, string>} />
          <Textarea label="Reason" required autosize placeholder="e.g. Corked bottles, wrong vintage" {...form.getInputProps("reason")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Send for approval
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}

function CreditNoteModal({ ret, onClose, onDone }: { ret: SupplierReturn; onClose: () => void; onDone: () => void }) {
  const [reference, setReference] = useState("");
  const { mutate, pending } = useApiMutation(() => purchasingApi.recordCreditNote(ret.id, reference.trim()), { successMessage: "Credit note recorded.", onSuccess: onDone });

  return (
    <Modal opened onClose={onClose} title={`Credit note for ${ret.number}`} centered>
      <Stack>
        <TextInput label="Supplier credit note number" data-autofocus value={reference} onChange={(e) => setReference(e.currentTarget.value)} />
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button disabled={!reference.trim()} loading={pending} onClick={() => void mutate()}>
            Save
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
