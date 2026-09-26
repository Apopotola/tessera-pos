"use client";

import { ActionIcon, Badge, Box, Button, Group, Menu, Modal, Stack, Table, Text, Tooltip } from "@mantine/core";
import { IconArrowBackUp, IconDots, IconHistory, IconLock, IconRestore } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { settingsApi } from "@/api";
import QueryState from "@/components/shared/QueryState";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import SettingEditor from "@/modules/settings/components/SettingEditor";
import type { SettingField, SettingLevel, SettingScope, SettingValue, SettingsSchema } from "@/types/settings";

interface SettingRowProps {
  field: SettingField;
  scope: SettingScope;
  scopeId: number;
  references: SettingsSchema["references"];
  /** Called after any change so the app picks up the new values (branding applies live). */
  onChanged: () => void;
}

const WHO_CAN: Record<SettingLevel, string> = { T: "Only Tessera support can change this.", O: "Only the business owner can change this.", B: "Only a branch manager can change this." };

function draftOf(field: SettingField): SettingValue {
  if (field.type === "image") return field.isSet ? field.storedPath : null;
  if (field.type === "secret") return "";
  const value = field.isSet ? field.value : field.effective;
  return field.type === "select" && value !== null ? String(value) : value;
}

/** Plain-language rendering of a value for history and preset previews. */
export function describeValue(field: Pick<SettingField, "type" | "options">, value: SettingValue): string {
  if (value === null || value === "") return "—";
  const option = (v: unknown) => field.options.find((o) => o.value === String(v))?.label ?? String(v);
  switch (field.type) {
    case "boolean":
      return value ? "On" : "Off";
    case "money":
      return `KES ${(Number(value) / 100).toLocaleString("en-KE", { minimumFractionDigits: 2 })}`;
    case "select":
      return option(value);
    case "multiselect":
    case "order":
      return Array.isArray(value) ? value.map(option).join(", ") || "None" : String(value);
    case "role_percent":
      return Object.entries(value as Record<string, number>)
        .map(([role, p]) => `${role} ${p}%`)
        .join(", ");
    case "role_tiles":
      return Object.keys(value as Record<string, string[]>).join(", ") || "Standard";
    case "categories":
    case "items":
      return Array.isArray(value) ? (value.length ? `${value.length} chosen` : field.type === "categories" ? "All" : "None") : String(value);
    case "lines":
      return String(value).replace(/\n/g, " / ");
    default:
      return typeof value === "object" ? JSON.stringify(value) : String(value);
  }
}

/** One setting: label, where its value comes from, the editor, and save / reset / undo / history. */
export default function SettingRow({ field: initial, scope, scopeId, references, onChanged }: SettingRowProps) {
  // Local copy, replaced by each save's response; a fresh schema (other scope, preset) resets it.
  const [state, setState] = useState({ source: initial, field: initial, draft: draftOf(initial) });
  const [error, setError] = useState<string | undefined>();
  const [historyOpen, setHistoryOpen] = useState(false);
  if (state.source !== initial) setState({ source: initial, field: initial, draft: draftOf(initial) });

  const { field, draft } = state;
  const dirty = JSON.stringify(draft) !== JSON.stringify(draftOf(field)) && !(field.type === "secret" && draft === "");
  const disabled = !field.editable || !field.available;

  const applied = (updated: SettingField) => {
    setState({ source: initial, field: updated, draft: draftOf(updated) });
    setError(undefined);
    onChanged();
  };
  const options = { onSuccess: applied, onValidationError: (errors: Record<string, string>) => setError(errors.value ?? Object.values(errors)[0]) };
  const save = useApiMutation(() => settingsApi.save(field.key, scope, scopeId, draft), { ...options, successMessage: `${field.label} saved.` });
  const reset = useApiMutation(() => settingsApi.reset(field.key, scope, scopeId), { ...options, successMessage: `${field.label}: back to the inherited value.` });
  const undo = useApiMutation(() => settingsApi.undo(field.key, scope, scopeId), { ...options, successMessage: `${field.label}: change undone.` });

  const sourceLabel = field.source === "default" ? "Default" : field.source === scope ? "Set here" : `From ${field.source}`;
  const lockedReason = field.lockedReason ?? (!field.editable ? WHO_CAN[field.level] : null);

  return (
    <Box py="md" style={{ borderTop: "1px solid var(--mantine-color-gray-2)" }}>
      <Group align="flex-start" gap="lg" wrap="wrap">
        <Stack gap={4} style={{ flex: "1 1 240px", minWidth: 220, maxWidth: 360 }}>
          <Group gap={6}>
            <Text fw={600} size="sm">
              {field.label}
            </Text>
            {!field.available && (
              <Badge size="xs" color="gray" variant="light">
                Coming later
              </Badge>
            )}
            {lockedReason && field.available && (
              <Tooltip label={lockedReason} multiline w={240}>
                <IconLock size={14} color="var(--mantine-color-gray-6)" aria-label={lockedReason} />
              </Tooltip>
            )}
          </Group>
          {field.help && (
            <Text size="xs" c="dimmed">
              {field.help}
            </Text>
          )}
          {!field.available && field.note && (
            <Text size="xs" c="dimmed" fs="italic">
              {field.note}
            </Text>
          )}
          {lockedReason && field.available && (
            <Text size="xs" c="dimmed">
              {lockedReason}
            </Text>
          )}
          <Group gap={6}>
            <Badge size="xs" variant={field.source === scope ? "filled" : "outline"} color={field.source === scope ? "tessera" : "gray"}>
              {sourceLabel}
            </Badge>
            {field.source !== scope && field.type !== "secret" && field.type !== "image" && (
              <Text size="xs" c="dimmed" lineClamp={1}>
                {describeValue(field, field.effective)}
              </Text>
            )}
          </Group>
        </Stack>

        <Box style={{ flex: "2 1 320px", minWidth: 0 }}>
          <SettingEditor field={field} draft={draft} onChange={(v) => setState((s) => ({ ...s, draft: v }))} disabled={disabled} references={references} error={error} />
        </Box>

        <Group gap="xs" wrap="nowrap" style={{ flex: "0 0 auto" }}>
          {!disabled && (
            <Button size="xs" onClick={() => void save.mutate()} loading={save.pending} disabled={!dirty}>
              Save
            </Button>
          )}
          {dirty && (
            <Button size="xs" variant="subtle" color="gray" onClick={() => setState((s) => ({ ...s, draft: draftOf(field) }))}>
              Cancel
            </Button>
          )}
          <Menu position="bottom-end" withinPortal>
            <Menu.Target>
              <ActionIcon variant="subtle" color="gray" aria-label={`More for ${field.label}`}>
                <IconDots size={16} />
              </ActionIcon>
            </Menu.Target>
            <Menu.Dropdown>
              <Menu.Item leftSection={<IconHistory size={14} />} onClick={() => setHistoryOpen(true)} disabled={!field.hasHistory}>
                History
              </Menu.Item>
              <Menu.Item leftSection={<IconArrowBackUp size={14} />} onClick={() => void undo.mutate()} disabled={disabled || !field.hasHistory}>
                Undo last change
              </Menu.Item>
              <Menu.Item leftSection={<IconRestore size={14} />} onClick={() => void reset.mutate()} disabled={disabled || !field.isSet || field.modelBacked}>
                {scope === "business" ? "Back to default" : `Use the ${scope === "till" ? "branch" : "business"} value`}
              </Menu.Item>
            </Menu.Dropdown>
          </Menu>
        </Group>
      </Group>

      <Modal opened={historyOpen} onClose={() => setHistoryOpen(false)} title={`History: ${field.label}`} size="lg">
        {historyOpen && <History field={field} scope={scope} scopeId={scopeId} />}
      </Modal>
    </Box>
  );
}

const SOURCE_LABEL = { edit: "Changed", reset: "Reset", undo: "Undone", preset: "Preset" } as const;

function History({ field, scope, scopeId }: { field: SettingField; scope: SettingScope; scopeId: number }) {
  const fetchHistory = useCallback(() => settingsApi.history(field.key, scope, scopeId), [field.key, scope, scopeId]);
  const { data, loading, error, reload } = useApiQuery(fetchHistory);

  return (
    <QueryState loading={loading} error={error} isEmpty={!data?.length} emptyMessage="No changes yet." onRetry={reload}>
      <Table verticalSpacing="xs" fz="sm">
        <Table.Thead>
          <Table.Tr>
            <Table.Th>When</Table.Th>
            <Table.Th>Who</Table.Th>
            <Table.Th>What</Table.Th>
            <Table.Th>From</Table.Th>
            <Table.Th>To</Table.Th>
          </Table.Tr>
        </Table.Thead>
        <Table.Tbody>
          {data?.map((change) => (
            <Table.Tr key={change.id}>
              <Table.Td style={{ whiteSpace: "nowrap" }}>{dayjs(change.at).format("DD MMM YYYY HH:mm")}</Table.Td>
              <Table.Td>{change.user ?? "System"}</Table.Td>
              <Table.Td>{SOURCE_LABEL[change.source]}</Table.Td>
              <Table.Td c="dimmed">{describeValue(field, change.oldValue)}</Table.Td>
              <Table.Td>{describeValue(field, change.newValue)}</Table.Td>
            </Table.Tr>
          ))}
        </Table.Tbody>
      </Table>
    </QueryState>
  );
}
