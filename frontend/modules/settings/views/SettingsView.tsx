"use client";

import { Alert, Badge, Box, Button, Checkbox, Grid, Group, List, Modal, NavLink, Select, Stack, Table, Text } from "@mantine/core";
import { IconBuildingStore, IconInfoCircle, IconLock } from "@tabler/icons-react";
import { useCallback, useMemo, useState } from "react";
import { settingsApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import SettingRow, { describeValue } from "@/modules/settings/components/SettingRow";
import { useAppDispatch } from "@/store/hooks";
import { loadAppSettings, loadBranding } from "@/store/slices/settingsSlice";
import type { PresetChange, SettingField, SettingScope, SettingsSchema } from "@/types/settings";

const LEVEL_TEXT = {
  T: "You are signed in as Tessera support: you can change everything, including KRA and payment verification settings.",
  O: "You can change business-wide settings and override them per branch or till.",
  B: "You can change the settings for your own branch and its tills. Business-wide settings are shown read-only.",
} as const;

/** Settings: one place for business, branding, receipts, till, stock, payments, staff, notifications and integrations. */
export default function SettingsView({ title, section }: WorkspaceViewProps) {
  const dispatch = useAppDispatch();
  const [target, setTarget] = useState<{ scope: SettingScope; scopeId: number } | null>(null);
  const [sectionKey, setSectionKey] = useState("business");

  // Branch managers start on their branch; everyone else on the whole business.
  const fetchSchema = useCallback(() => settingsApi.schema(target?.scope ?? "business", target?.scopeId ?? 0), [target]);
  const { data: schema, loading, error, reload } = useApiQuery(fetchSchema);
  if (!target && schema?.level === "B" && schema.branches.length > 0) setTarget({ scope: "branch", scopeId: schema.branches[0].id });

  const scope = schema?.scope ?? "business";
  const scopeId = schema?.scopeId ?? 0;

  const refreshApp = useCallback(() => {
    void dispatch(loadAppSettings());
    void dispatch(loadBranding());
  }, [dispatch]);

  const scopeOptions = useMemo(() => {
    if (!schema) return [];
    const branchName = new Map(schema.branches.map((b) => [b.id, b.name]));
    return [
      { group: "Business", items: [{ value: "business:0", label: "Whole business" }] },
      { group: "Branches", items: schema.branches.map((b) => ({ value: `branch:${b.id}`, label: b.name })) },
      { group: "Tills", items: schema.tills.map((t) => ({ value: `till:${t.id}`, label: `${branchName.get(t.branchId) ?? "Branch"} · ${t.name}` })) },
    ].filter((g) => g.items.length > 0);
  }, [schema]);

  const current = schema?.sections.find((s) => s.key === sectionKey) ?? schema?.sections[0];

  return (
    <WorkspacePage section={section} title={title} description="Set up how the system looks and works for your business. Every change is logged and can be undone.">
      <QueryState loading={loading && !schema} error={error} isEmpty={!schema} onRetry={reload}>
        {schema && current && (
          <Stack gap="lg">
            <Group justify="space-between" align="flex-end" wrap="wrap">
              <Select
                label="Settings for"
                description="Branch and till settings override the business ones; the most specific wins."
                leftSection={<IconBuildingStore size={16} />}
                data={scopeOptions}
                value={`${scope}:${scopeId}`}
                onChange={(v) => {
                  if (!v) return;
                  const [s, id] = v.split(":");
                  setTarget({ scope: s as SettingScope, scopeId: Number(id) });
                }}
                allowDeselect={false}
                w={{ base: "100%", sm: 340 }}
              />
              <Text size="sm" c="dimmed" maw={520}>
                {LEVEL_TEXT[schema.level]}
              </Text>
            </Group>

            <Grid gutter="lg">
              <Grid.Col span={{ base: 12, md: 3 }}>
                <DataCard>
                  <Box p={6}>
                    {schema.sections.map((s) => (
                      <NavLink
                        key={s.key}
                        label={s.title}
                        description={s.fields.length === 0 ? "Nothing to set here" : undefined}
                        active={s.key === current.key}
                        onClick={() => setSectionKey(s.key)}
                        variant="light"
                        style={{ borderRadius: 8 }}
                        rightSection={
                          s.fields.some((f) => f.source === scope && f.isSet) ? (
                            <Badge size="xs" variant="light">
                              {s.fields.filter((f) => f.source === scope && f.isSet).length}
                            </Badge>
                          ) : undefined
                        }
                      />
                    ))}
                  </Box>
                </DataCard>
              </Grid.Col>

              <Grid.Col span={{ base: 12, md: 9 }}>
                <Stack gap="lg">
                  {current.key === "business" && scope === "business" && <PresetCard schema={schema} onApplied={() => {
                        reload();
                        refreshApp();
                      }} />}

                  <DataCard title={current.title} description={current.description} padding="lg">
                    {current.fields.length === 0 ? (
                      <Text size="sm" c="dimmed">
                        These settings are set for the whole business. Choose &quot;Whole business&quot; above.
                      </Text>
                    ) : (
                      <FieldList fields={current.fields.filter((f) => f.key !== "business.preset")} scope={scope} scopeId={scopeId} references={schema.references} onChanged={refreshApp} />
                    )}
                  </DataCard>

                  {current.key === "business" && scope === "business" && <LockedCard schema={schema} />}
                </Stack>
              </Grid.Col>
            </Grid>
          </Stack>
        )}
      </QueryState>
    </WorkspacePage>
  );
}

/** Fields in registry order, with a heading whenever the group changes. */
function FieldList({
  fields,
  scope,
  scopeId,
  references,
  onChanged,
}: {
  fields: SettingField[];
  scope: SettingScope;
  scopeId: number;
  references: SettingsSchema["references"];
  onChanged: () => void;
}) {
  return (
    <Stack gap={0}>
      {fields.map((field, i) => (
        <Box key={`${scope}:${scopeId}:${field.key}`}>
          {field.group && field.group !== fields[i - 1]?.group && (
            <Text fw={700} size="xs" tt="uppercase" c="dimmed" mt={i === 0 ? 0 : "lg"} mb={4} style={{ letterSpacing: "0.08em" }}>
              {field.group}
            </Text>
          )}
          <SettingRow field={field} scope={scope} scopeId={scopeId} references={references} onChanged={onChanged} />
        </Box>
      ))}
    </Stack>
  );
}

/** Industry presets: preview what changes, keep the owner's own changes unless they agree. */
function PresetCard({ schema, onApplied }: { schema: SettingsSchema; onApplied: () => void }) {
  const [preset, setPreset] = useState<string | null>(null);
  const [preview, setPreview] = useState<PresetChange[] | null>(null);
  const [overwrite, setOverwrite] = useState(false);
  const fields = useMemo(() => new Map(schema.sections.flatMap((s) => s.fields).map((f) => [f.key, f])), [schema]);
  const currentTitle = schema.presets.find((p) => p.key === schema.currentPreset)?.title ?? schema.currentPreset;
  const chosen = schema.presets.find((p) => p.key === preset);

  const load = useApiMutation((key: string) => settingsApi.presetPreview(key), {
    onSuccess: (changes) => {
      setPreview(changes);
      setOverwrite(false);
    },
  });
  const apply = useApiMutation(() => settingsApi.applyPreset(preset ?? "", overwrite), {
    successMessage: (r) => `${chosen?.title ?? "Preset"} applied: ${r.written} setting(s) changed.`,
    onSuccess: () => {
      setPreview(null);
      onApplied();
    },
  });

  const yours = preview?.filter((c) => c.yourChange).length ?? 0;
  const describe = (c: PresetChange, v: PresetChange["from"]) => {
    const f = fields.get(c.key);
    return f ? describeValue(f, v) : String(v);
  };

  return (
    <DataCard title="Industry preset" description={`Starting values for a type of shop. Current: ${currentTitle}.`} padding="lg">
      {!schema.canEditBusiness ? (
        <Text size="sm" c="dimmed">
          Only the business owner can apply a preset.
        </Text>
      ) : (
        <Group align="flex-end" gap="sm" wrap="wrap">
          <Select
            label="Preset"
            placeholder="Choose a preset"
            data={schema.presets.map((p) => ({ value: p.key, label: p.title }))}
            value={preset}
            onChange={setPreset}
            w={{ base: "100%", sm: 280 }}
          />
          <Button variant="default" disabled={!preset} loading={load.pending} onClick={() => preset && void load.mutate(preset)}>
            Preview changes
          </Button>
        </Group>
      )}
      {chosen && (
        <Text size="sm" c="dimmed" mt="xs">
          {chosen.description}
        </Text>
      )}

      <Modal opened={preview !== null} onClose={() => setPreview(null)} title={`Apply ${chosen?.title ?? "preset"}?`} size="lg">
        {preview && (
          <Stack>
            {preview.length === 0 ? (
              <Text size="sm">Your settings already match this preset.</Text>
            ) : (
              <Table verticalSpacing="xs" fz="sm">
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>Setting</Table.Th>
                    <Table.Th>Now</Table.Th>
                    <Table.Th>After</Table.Th>
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {preview.map((c) => (
                    <Table.Tr key={c.key} style={c.yourChange && !overwrite ? { opacity: 0.55 } : undefined}>
                      <Table.Td>
                        {c.label}
                        {c.yourChange && (
                          <Badge size="xs" ml={6} color="amber" variant="light">
                            Your change
                          </Badge>
                        )}
                      </Table.Td>
                      <Table.Td c="dimmed">{describe(c, c.from)}</Table.Td>
                      <Table.Td>{describe(c, c.to)}</Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            )}
            {yours > 0 && (
              <Alert color="amber" variant="light" icon={<IconInfoCircle size={18} />}>
                <Stack gap="xs">
                  <Text size="sm">
                    {yours} of these you changed yourself. They are kept unless you tick below.
                  </Text>
                  <Checkbox label="Also replace my own changes" checked={overwrite} onChange={(e) => setOverwrite(e.currentTarget.checked)} />
                </Stack>
              </Alert>
            )}
            <Group justify="flex-end">
              <Button variant="default" onClick={() => setPreview(null)}>
                Cancel
              </Button>
              <Button onClick={() => void apply.mutate()} loading={apply.pending} disabled={preview.length === 0}>
                Apply preset
              </Button>
            </Group>
          </Stack>
        )}
      </Modal>
    </DataCard>
  );
}

/** Settings that lock after first use, and how to correct them. */
function LockedCard({ schema }: { schema: SettingsSchema }) {
  return (
    <DataCard title="Locked after first use" description="These keep your records consistent. Each has a safe way to correct it." padding="lg">
      <List spacing="sm" icon={<IconLock size={16} color="var(--mantine-color-gray-6)" />} center={false}>
        {schema.locked.map((item) => (
          <List.Item key={item.item}>
            <Text size="sm" fw={600}>
              {item.item}
            </Text>
            <Text size="sm" c="dimmed">
              {item.why}.
            </Text>
            <Text size="sm">
              <Text span fw={600} size="sm">
                To correct:
              </Text>{" "}
              {item.correction}.
            </Text>
          </List.Item>
        ))}
      </List>
    </DataCard>
  );
}
