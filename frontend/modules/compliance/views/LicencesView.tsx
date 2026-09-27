"use client";

import { Alert, Badge, Button, Group, Modal, Select, SimpleGrid, Stack, Switch, Table, Text, TextInput, Textarea } from "@mantine/core";
import { isNotEmpty, useForm } from "@mantine/form";
import { modals } from "@mantine/modals";
import { IconInfoCircle, IconPlus } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { complianceApi, organisationApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import type { Licence, LicenceRegister } from "@/types/compliance";
import { PERMISSIONS } from "@/types/permissions";

function expiryBadge(l: Licence) {
  if (!l.isCurrent) return <Badge color="gray" variant="light">Renewed</Badge>;
  if (l.daysLeft < 0) return <Badge color="red">Expired {Math.abs(l.daysLeft)} days ago</Badge>;
  if (l.daysLeft <= 30) return <Badge color="red" variant="light">{l.daysLeft} days left</Badge>;
  if (l.daysLeft <= 60) return <Badge color="yellow" variant="light">{l.daysLeft} days left</Badge>;
  return <Badge color="green" variant="light">Valid</Badge>;
}

/** Licence & permit register: a reminder 60 and 30 days before expiry; renewals are new entries. */
export default function LicencesView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const fetchLicences = useCallback(() => complianceApi.licences(), []);
  const { data, loading, error, reload } = useApiQuery(fetchLicences);
  const [editing, setEditing] = useState<{ licence: Licence | null; renew: boolean } | null>(null);
  const remove = useApiMutation((l: Licence) => complianceApi.removeLicence(l.id), { successMessage: "Entry removed.", onSuccess: reload });
  const canManage = can(PERMISSIONS.COMPLIANCE_MANAGE);

  const confirmRemove = (l: Licence) =>
    modals.openConfirmModal({
      title: `Remove ${l.name}?`,
      children: <Text size="sm">Only for an entry made by mistake. A renewal is added as a new entry instead. The removal is logged.</Text>,
      labels: { confirm: "Remove", cancel: "Cancel" },
      confirmProps: { color: "red" },
      onConfirm: () => void remove.mutate(l),
    });

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Liquor licence, single business permit and other certificates per branch. You are reminded 60 and 30 days before each expires."
      actions={
        canManage && (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setEditing({ licence: null, renew: false })}>
            Add licence or permit
          </Button>
        )
      }
    >
      <Alert color="gray" variant="light" icon={<IconInfoCircle size={18} />}>
        A reminder only: keeping licences valid stays with the business. Which licences apply, and whether receipts must show the licence number, depends on your county (to be confirmed with the county licensing office).
      </Alert>
      <DataCard>
        <QueryState loading={loading && !data} error={error} isEmpty={!data?.items.length} emptyMessage="No licences recorded yet." onRetry={reload}>
          <Table verticalSpacing="sm" fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Licence</Table.Th>
                <Table.Th>Branch</Table.Th>
                <Table.Th>Number</Table.Th>
                <Table.Th>Expires</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((l) => (
                <Table.Tr key={l.id} opacity={l.isCurrent ? 1 : 0.55}>
                  <Table.Td>
                    <Text size="sm" fw={600}>
                      {l.name}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {l.typeLabel}
                      {l.issuer ? ` · ${l.issuer}` : ""}
                      {l.printOnReceipt ? " · printed on receipts" : ""}
                    </Text>
                  </Table.Td>
                  <Table.Td>{l.branch.name}</Table.Td>
                  <Table.Td ff="monospace">{l.number}</Table.Td>
                  <Table.Td>
                    <Stack gap={2}>
                      <Text size="sm">{dayjs(l.expiresOn).format("DD MMM YYYY")}</Text>
                      {expiryBadge(l)}
                    </Stack>
                  </Table.Td>
                  <Table.Td ta="right">
                    {canManage && (
                      <Group gap="xs" justify="flex-end" wrap="nowrap">
                        {l.isCurrent && (
                          <Button size="xs" variant="light" onClick={() => setEditing({ licence: l, renew: true })}>
                            Renew
                          </Button>
                        )}
                        <Button size="xs" variant="subtle" onClick={() => setEditing({ licence: l, renew: false })}>
                          Edit
                        </Button>
                        <Button size="xs" variant="subtle" color="red" onClick={() => confirmRemove(l)}>
                          Remove
                        </Button>
                      </Group>
                    )}
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      {editing && data && (
        <LicenceFormModal
          register={data}
          licence={editing.licence}
          renew={editing.renew}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}

interface FormValues {
  branchId: string | null;
  type: string | null;
  name: string;
  number: string;
  issuer: string;
  issuedOn: string;
  expiresOn: string;
  printOnReceipt: boolean;
  notes: string;
}

/** Add, correct, or renew (a new entry pre-filled from the old one). */
function LicenceFormModal({ register, licence, renew, onClose, onSaved }: { register: LicenceRegister; licence: Licence | null; renew: boolean; onClose: () => void; onSaved: () => void }) {
  const fetchBranches = useCallback(() => organisationApi.branches(), []);
  const branches = useApiQuery(fetchBranches).data ?? [];
  const form = useForm<FormValues>({
    initialValues: {
      branchId: licence ? String(licence.branch.id) : null,
      type: licence?.type ?? "liquor_licence",
      name: licence?.name ?? "",
      number: renew ? "" : (licence?.number ?? ""),
      issuer: licence?.issuer ?? "",
      issuedOn: renew ? dayjs().format("YYYY-MM-DD") : (licence?.issuedOn ?? ""),
      expiresOn: renew ? dayjs(licence?.expiresOn).add(1, "year").format("YYYY-MM-DD") : (licence?.expiresOn ?? ""),
      printOnReceipt: licence?.printOnReceipt ?? false,
      notes: renew ? "" : (licence?.notes ?? ""),
    },
    validate: {
      branchId: isNotEmpty("Choose the branch"),
      type: isNotEmpty("Choose the type"),
      name: isNotEmpty("Name as on the certificate"),
      number: isNotEmpty("Licence or permit number"),
      expiresOn: isNotEmpty("Expiry date"),
    },
  });
  const { mutate, pending } = useApiMutation(
    (v: FormValues) => {
      const payload = {
        branchId: Number(v.branchId),
        type: v.type ?? "other",
        name: v.name.trim(),
        number: v.number.trim(),
        issuer: v.issuer.trim() || null,
        issuedOn: v.issuedOn || null,
        expiresOn: v.expiresOn,
        printOnReceipt: v.printOnReceipt,
        notes: v.notes.trim() || null,
      };
      return licence && !renew ? complianceApi.updateLicence(licence.id, payload) : complianceApi.addLicence(payload);
    },
    { successMessage: renew ? "Renewal saved." : "Licence saved.", onValidationError: form.setErrors, onSuccess: onSaved },
  );

  return (
    <Modal opened onClose={onClose} title={renew ? `Renew ${licence?.name}` : licence ? "Correct licence" : "Add licence or permit"} centered>
      <form onSubmit={form.onSubmit((v) => void mutate(v))} noValidate>
        <Stack>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <Select label="Branch" data={branches.map((b) => ({ value: String(b.id), label: b.name }))} disabled={renew} {...form.getInputProps("branchId")} />
            <Select label="Type" data={register.types} allowDeselect={false} disabled={renew} {...form.getInputProps("type")} />
          </SimpleGrid>
          <TextInput label="Name" placeholder="e.g. Nairobi County liquor licence" disabled={renew} {...form.getInputProps("name")} />
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <TextInput label={renew ? "New number" : "Number"} data-autofocus {...form.getInputProps("number")} />
            <TextInput label="Issued by (optional)" {...form.getInputProps("issuer")} />
          </SimpleGrid>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <TextInput label="Issued on (optional)" type="date" {...form.getInputProps("issuedOn")} />
            <TextInput label="Expires on" type="date" {...form.getInputProps("expiresOn")} />
          </SimpleGrid>
          <Switch label="Print this number on receipts" {...form.getInputProps("printOnReceipt", { type: "checkbox" })} />
          <Textarea label="Notes (optional)" autosize minRows={2} {...form.getInputProps("notes")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Save
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
