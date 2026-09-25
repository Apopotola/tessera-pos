"use client";

import { Alert, Button, Checkbox, Group, Modal, NumberInput, Pagination, SegmentedControl, Select, SimpleGrid, Stack, Table, Text, TextInput, Textarea } from "@mantine/core";
import { DateInput } from "@mantine/dates";
import { IconAlertTriangle, IconCircleCheck, IconPlus } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useEffect, useState } from "react";
import { purchasingApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { useSuppliers } from "@/modules/purchasing/hooks/useSuppliers";
import { PERMISSIONS } from "@/types/permissions";
import type { GoodsReceipt, InvoiceMatch } from "@/types/purchasing";
import { formatKes, kesToCents } from "@/utils/money";

/** Supplier invoices checked against what was actually received (three-way match). */
export default function SupplierInvoicesView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const [match, setMatch] = useState<InvoiceMatch | "all">("all");
  const [page, setPage] = useState(1);
  const [recording, setRecording] = useState(false);

  const fetchInvoices = useCallback(() => purchasingApi.invoices(match === "all" ? undefined : match, page), [match, page]);
  const { data, loading, error, reload } = useApiQuery(fetchInvoices);

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Each invoice is compared with the goods received. A difference is flagged so it can be queried before paying."
      actions={
        can(PERMISSIONS.PURCHASING_MANAGE) && (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setRecording(true)}>
            Record invoice
          </Button>
        )
      }
    >
      <SegmentedControl
        w="fit-content"
        value={match}
        onChange={(v) => {
          setMatch(v as InvoiceMatch | "all");
          setPage(1);
        }}
        data={[
          { value: "all", label: "All" },
          { value: "variance", label: "Needs querying" },
          { value: "matched", label: "Matched" },
        ]}
      />

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="No invoices here." onRetry={reload}>
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Invoice</Table.Th>
                <Table.Th>Supplier</Table.Th>
                <Table.Th>Deliveries</Table.Th>
                <Table.Th ta="right">Invoiced</Table.Th>
                <Table.Th ta="right">Received value</Table.Th>
                <Table.Th ta="right">Difference</Table.Th>
                <Table.Th>Due</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((inv) => (
                <Table.Tr key={inv.id}>
                  <Table.Td>
                    <Text size="sm" ff="monospace">
                      {inv.invoiceNumber}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {dayjs(inv.invoiceDate).format("DD MMM YYYY")}
                    </Text>
                  </Table.Td>
                  <Table.Td fw={600}>{inv.supplier.name}</Table.Td>
                  <Table.Td>
                    <Text size="sm">{inv.receiptNumbers.join(", ")}</Text>
                  </Table.Td>
                  <Table.Td ta="right">{formatKes(inv.totalCents)}</Table.Td>
                  <Table.Td ta="right">{formatKes(inv.expectedTotalCents)}</Table.Td>
                  <Table.Td ta="right">
                    {inv.matchStatus === "matched" ? (
                      <DocStatusBadge label="Matched" color="green" />
                    ) : (
                      <Text fw={700} c="red.7">
                        {inv.varianceCents > 0 ? "+" : ""}
                        {formatKes(inv.varianceCents)}
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td>{dayjs(inv.dueDate).format("DD MMM YYYY")}</Table.Td>
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

      {recording && (
        <InvoiceFormModal
          onClose={() => setRecording(false)}
          onSaved={() => {
            setRecording(false);
            reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}

function InvoiceFormModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const suppliers = useSuppliers();
  const [supplierId, setSupplierId] = useState<string | null>(null);
  const [receipts, setReceipts] = useState<GoodsReceipt[]>([]);
  const [selected, setSelected] = useState<number[]>([]);
  const [invoiceNumber, setInvoiceNumber] = useState("");
  const [invoiceDate, setInvoiceDate] = useState<string | null>(dayjs().format("YYYY-MM-DD"));
  const [subtotal, setSubtotal] = useState<number | string>("");
  const [vat, setVat] = useState<number | string>("");
  const [note, setNote] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!supplierId) return;
    let active = true;
    purchasingApi
      .receipts(Number(supplierId))
      .then((list) => {
        if (!active) return;
        setReceipts(list);
        setSelected(list.map((g) => g.id));
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [supplierId]);

  const expected = receipts.filter((g) => selected.includes(g.id)).reduce((s, g) => s + g.valueCents, 0);
  const total = kesToCents(Number(subtotal) || 0) + kesToCents(Number(vat) || 0);
  const difference = total - expected;
  const ready = Boolean(supplierId && selected.length && invoiceNumber.trim() && invoiceDate && total > 0);

  const { mutate, pending } = useApiMutation(
    () =>
      purchasingApi.recordInvoice({
        supplierId: Number(supplierId),
        invoiceNumber: invoiceNumber.trim(),
        invoiceDate: invoiceDate ?? "",
        subtotalCents: kesToCents(Number(subtotal) || 0),
        vatCents: kesToCents(Number(vat) || 0),
        totalCents: total,
        grnIds: selected,
        note: note.trim() || null,
      }),
    { successMessage: (inv) => (inv.matchStatus === "matched" ? "Invoice matches the goods received." : "Invoice recorded — flagged for querying."), onValidationError: setErrors, onSuccess: onSaved },
  );

  return (
    <Modal opened onClose={onClose} title="Record supplier invoice" size="xl">
      <Stack>
        <SimpleGrid cols={{ base: 1, sm: 3 }}>
          <Select label="Supplier" data={suppliers.options} searchable value={supplierId} onChange={setSupplierId} error={errors.supplierId} />
          <TextInput label="Invoice number" value={invoiceNumber} onChange={(e) => setInvoiceNumber(e.currentTarget.value)} error={errors.invoiceNumber} />
          <DateInput label="Invoice date" maxDate={new Date()} valueFormat="DD MMM YYYY" value={invoiceDate} onChange={setInvoiceDate} error={errors.invoiceDate} />
        </SimpleGrid>

        {supplierId && (
          <Stack gap={4}>
            <Text size="sm" fw={600}>
              Deliveries on this invoice
            </Text>
            {receipts.length === 0 ? (
              <Text size="sm" c="dimmed">
                No uninvoiced deliveries from this supplier.
              </Text>
            ) : (
              receipts.map((g) => (
                <Checkbox
                  key={g.id}
                  checked={selected.includes(g.id)}
                  onChange={(e) => setSelected((s) => (e.currentTarget.checked ? [...s, g.id] : s.filter((id) => id !== g.id)))}
                  label={`${g.number} (${g.purchaseOrderNumber}) · ${g.receivedAt ? dayjs(g.receivedAt).format("DD MMM") : ""} · ${formatKes(g.valueCents)}`}
                />
              ))
            )}
            {errors.grnIds && (
              <Text c="red" size="sm">
                {errors.grnIds}
              </Text>
            )}
          </Stack>
        )}

        <SimpleGrid cols={{ base: 1, sm: 3 }}>
          <NumberInput label="Subtotal excl. VAT (KES)" min={0} decimalScale={2} thousandSeparator="," value={subtotal} onChange={setSubtotal} />
          <NumberInput label="VAT (KES)" min={0} decimalScale={2} thousandSeparator="," value={vat} onChange={setVat} />
          <TextInput label="Invoice total (KES)" value={formatKes(total)} readOnly error={errors.totalCents} />
        </SimpleGrid>

        {selected.length > 0 && total > 0 && (
          <Alert
            variant="light"
            color={Math.abs(difference) <= 100 ? "green" : "red"}
            icon={Math.abs(difference) <= 100 ? <IconCircleCheck size={18} /> : <IconAlertTriangle size={18} />}
          >
            Goods received are worth {formatKes(expected)} incl. VAT.{" "}
            {Math.abs(difference) <= 100 ? "The invoice matches." : `The invoice is ${difference > 0 ? "over" : "under"} by ${formatKes(Math.abs(difference))} — it will be flagged for querying.`}
          </Alert>
        )}

        <Textarea label="Note" autosize placeholder="Optional" value={note} onChange={(e) => setNote(e.currentTarget.value)} />
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button disabled={!ready} loading={pending} onClick={() => void mutate()}>
            Record invoice
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
