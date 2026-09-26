"use client";

import {
  ActionIcon,
  Badge,
  Button,
  ColorInput,
  ColorSwatch,
  FileButton,
  Group,
  MultiSelect,
  NumberInput,
  PasswordInput,
  Select,
  SimpleGrid,
  Stack,
  Switch,
  Text,
  TextInput,
  Textarea,
} from "@mantine/core";
import { IconArrowDown, IconArrowUp, IconCheck, IconUpload, IconX } from "@tabler/icons-react";
import { useState } from "react";
import { settingsApi } from "@/api";
import VariantPicker from "@/modules/inventory/components/VariantPicker";
import type { SettingField, SettingValue, SettingsSchema } from "@/types/settings";
import { contrastRatio } from "@/utils/palette";

export interface EditorProps {
  field: SettingField;
  draft: SettingValue;
  onChange: (value: SettingValue) => void;
  disabled: boolean;
  references: SettingsSchema["references"];
  error?: string;
}

const asList = (v: SettingValue): string[] => (Array.isArray(v) ? v.map(String) : []);
const asMap = <T,>(v: SettingValue): Record<string, T> => (v && typeof v === "object" && !Array.isArray(v) ? (v as Record<string, T>) : {});

/** One editor per setting type (the registry decides which). */
export default function SettingEditor({ field, draft, onChange, disabled, references, error }: EditorProps) {
  const options = field.options.map((o) => ({ value: o.value, label: o.label }));

  switch (field.type) {
    case "text":
      return <TextInput value={(draft as string | null) ?? ""} onChange={(e) => onChange(e.currentTarget.value)} disabled={disabled} maxLength={field.max ?? undefined} error={error} />;

    case "secret":
      return (
        <PasswordInput
          value={(draft as string | null) ?? ""}
          onChange={(e) => onChange(e.currentTarget.value)}
          placeholder={field.value ? `Saved (${String(field.value)}). Type to replace.` : "Not set"}
          autoComplete="new-password"
          disabled={disabled}
          error={error}
        />
      );

    case "lines":
      return (
        <Textarea
          value={(draft as string | null) ?? ""}
          onChange={(e) => onChange(e.currentTarget.value)}
          autosize
          minRows={2}
          maxRows={6}
          disabled={disabled}
          styles={{ input: { fontFamily: "monospace" } }}
          description={`Up to ${field.max ?? 3} lines of 48 characters.`}
          error={error}
        />
      );

    case "boolean":
      return <Switch checked={draft === true} onChange={(e) => onChange(e.currentTarget.checked)} disabled={disabled} label={draft === true ? "On" : "Off"} error={error} />;

    case "select":
      return <Select data={options} value={draft === null ? null : String(draft)} onChange={(v) => onChange(v)} allowDeselect={false} disabled={disabled} error={error} />;

    case "multiselect":
      return <MultiSelect data={options} value={asList(draft)} onChange={onChange} disabled={disabled} clearable error={error} />;

    case "order":
      return <OrderEditor options={options} value={asList(draft)} onChange={onChange} disabled={disabled} error={error} />;

    case "number":
      return (
        <NumberInput
          value={typeof draft === "number" ? draft : ""}
          onChange={(v) => onChange(typeof v === "number" ? v : null)}
          min={field.min ?? undefined}
          max={field.max ?? undefined}
          allowDecimal={false}
          disabled={disabled}
          maw={220}
          error={error}
        />
      );

    case "money":
      return (
        <NumberInput
          value={typeof draft === "number" ? draft / 100 : ""}
          onChange={(v) => onChange(typeof v === "number" ? Math.round(v * 100) : null)}
          min={0}
          decimalScale={2}
          thousandSeparator=","
          prefix="KES "
          disabled={disabled}
          maw={260}
          error={error}
        />
      );

    case "color":
      return <ColourEditor field={field} value={(draft as string | null) ?? ""} onChange={onChange} disabled={disabled} error={error} />;

    case "image":
      return <ImageEditor field={field} value={(draft as string | null) ?? null} onChange={onChange} disabled={disabled} error={error} />;

    case "time":
      return <TextInput type="time" value={(draft as string | null) ?? ""} onChange={(e) => onChange(e.currentTarget.value || null)} disabled={disabled} maw={160} error={error} />;

    case "categories":
      return (
        <MultiSelect
          data={references.categories.map((c) => ({ value: String(c.id), label: c.name }))}
          value={asList(draft)}
          onChange={(ids) => onChange(ids.map(Number))}
          placeholder={asList(draft).length ? undefined : "All categories"}
          searchable
          clearable
          disabled={disabled}
          error={error}
        />
      );

    case "items":
      return <ItemsEditor field={field} value={asList(draft).map(Number)} onChange={onChange} disabled={disabled} references={references} error={error} />;

    case "role_percent": {
      const map = asMap<number>(draft);
      return (
        <SimpleGrid cols={{ base: 1, sm: 2 }} spacing="xs">
          {references.roles.map((role) => (
            <NumberInput
              key={role}
              label={role}
              value={map[role] ?? 0}
              onChange={(v) => onChange({ ...map, [role]: typeof v === "number" ? v : 0 })}
              min={0}
              max={100}
              suffix="%"
              allowDecimal={false}
              disabled={disabled}
            />
          ))}
          {error && (
            <Text size="xs" c="red">
              {error}
            </Text>
          )}
        </SimpleGrid>
      );
    }

    case "role_tiles": {
      const map = asMap<string[]>(draft);
      const tiles = Object.entries(references.dashboardTiles).map(([value, label]) => ({ value, label }));
      return (
        <Stack gap="xs">
          {references.roles.map((role) => (
            <MultiSelect
              key={role}
              label={role}
              data={tiles}
              value={map[role] ?? []}
              onChange={(v) => onChange({ ...map, [role]: v })}
              placeholder="Standard tiles for this role"
              clearable
              disabled={disabled}
            />
          ))}
          {error && (
            <Text size="xs" c="red">
              {error}
            </Text>
          )}
        </Stack>
      );
    }
  }
}

/** Chosen options in order: move up or down, remove, add from the rest. */
function OrderEditor({
  options,
  value,
  onChange,
  disabled,
  error,
}: {
  options: { value: string; label: string }[];
  value: string[];
  onChange: (v: string[]) => void;
  disabled: boolean;
  error?: string;
}) {
  const label = (v: string) => options.find((o) => o.value === v)?.label ?? v;
  const move = (i: number, by: number) => {
    const next = [...value];
    [next[i], next[i + by]] = [next[i + by], next[i]];
    onChange(next);
  };
  const rest = options.filter((o) => !value.includes(o.value));

  return (
    <Stack gap={6}>
      {value.map((v, i) => (
        <Group key={v} gap="xs" wrap="nowrap">
          <Badge variant="light" w={28} px={0}>
            {i + 1}
          </Badge>
          <Text size="sm" style={{ flex: 1 }}>
            {label(v)}
          </Text>
          <ActionIcon variant="subtle" aria-label="Move up" disabled={disabled || i === 0} onClick={() => move(i, -1)}>
            <IconArrowUp size={16} />
          </ActionIcon>
          <ActionIcon variant="subtle" aria-label="Move down" disabled={disabled || i === value.length - 1} onClick={() => move(i, 1)}>
            <IconArrowDown size={16} />
          </ActionIcon>
          <ActionIcon variant="subtle" color="red" aria-label="Remove" disabled={disabled || value.length === 1} onClick={() => onChange(value.filter((x) => x !== v))}>
            <IconX size={16} />
          </ActionIcon>
        </Group>
      ))}
      {rest.length > 0 && (
        <Select placeholder="Add…" data={rest} value={null} onChange={(v) => v && onChange([...value, v])} disabled={disabled} maw={260} size="xs" />
      )}
      {error && (
        <Text size="xs" c="red">
          {error}
        </Text>
      )}
    </Stack>
  );
}

/** Tested brand colours as swatches, or a custom colour checked for readable white text. */
function ColourEditor({ field, value, onChange, disabled, error }: { field: SettingField; value: string; onChange: (v: string) => void; disabled: boolean; error?: string }) {
  const valid = /^#[0-9A-Fa-f]{6}$/.test(value);
  const tested = field.options.some((o) => o.value.toUpperCase() === value.toUpperCase());
  const hardToRead = valid && !tested && contrastRatio("#FFFFFF", value) < 4.5;

  return (
    <Stack gap="xs">
      <Group gap="xs">
        {field.options.map((o) => (
          <ColorSwatch
            key={o.value}
            component="button"
            type="button"
            color={o.value}
            title={o.label}
            aria-label={o.label}
            onClick={() => !disabled && onChange(o.value)}
            style={{ cursor: disabled ? "not-allowed" : "pointer", color: "#fff" }}
          >
            {o.value.toUpperCase() === value.toUpperCase() && <IconCheck size={14} />}
          </ColorSwatch>
        ))}
      </Group>
      <ColorInput
        value={value}
        onChange={onChange}
        format="hex"
        disabled={disabled}
        maw={220}
        description={tested ? "Tested brand colour." : "Custom colour"}
        error={error ?? (hardToRead ? "White text on this colour would be hard to read. Pick a darker shade." : undefined)}
      />
    </Stack>
  );
}

/** Upload (PNG, JPG, SVG, WebP up to 1 MB), preview, remove. The draft is the stored path. */
function ImageEditor({ field, value, onChange, disabled, error }: { field: SettingField; value: string | null; onChange: (v: string | null) => void; disabled: boolean; error?: string }) {
  const [preview, setPreview] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  // Newly uploaded file, else what is saved here, else the inherited image.
  const shown = value === null ? null : (preview ?? (value === field.storedPath ? (field.value as string | null) : null) ?? (field.effective as string | null));

  const upload = async (file: File | null) => {
    if (!file) return;
    setUploading(true);
    setUploadError(null);
    try {
      const result = await settingsApi.upload(file);
      setPreview(result.url);
      onChange(result.path);
    } catch (e) {
      setUploadError(e instanceof Error ? e.message : "Upload failed.");
    } finally {
      setUploading(false);
    }
  };

  return (
    <Stack gap="xs">
      {shown ? (
        // eslint-disable-next-line @next/next/no-img-element -- uploaded branding image served by the API
        <img src={shown} alt={field.label} style={{ maxHeight: 72, maxWidth: 240, objectFit: "contain", background: "var(--mantine-color-gray-1)", borderRadius: 8, padding: 6 }} />
      ) : (
        <Text size="sm" c="dimmed">
          No image.
        </Text>
      )}
      <Group gap="xs">
        <FileButton onChange={upload} accept="image/png,image/jpeg,image/svg+xml,image/webp" disabled={disabled}>
          {(props) => (
            <Button {...props} size="xs" variant="default" leftSection={<IconUpload size={14} />} loading={uploading} disabled={disabled}>
              Upload
            </Button>
          )}
        </FileButton>
        {value && (
          <Button
            size="xs"
            variant="subtle"
            color="red"
            disabled={disabled}
            onClick={() => {
              setPreview(null);
              onChange(null);
            }}
          >
            Remove
          </Button>
        )}
      </Group>
      {(uploadError ?? error) && (
        <Text size="xs" c="red">
          {uploadError ?? error}
        </Text>
      )}
    </Stack>
  );
}

/** Favourite products: the chosen list plus a catalogue search to add more. */
function ItemsEditor({
  field,
  value,
  onChange,
  disabled,
  references,
  error,
}: {
  field: SettingField;
  value: number[];
  onChange: (v: number[]) => void;
  disabled: boolean;
  references: SettingsSchema["references"];
  error?: string;
}) {
  const [labels, setLabels] = useState<Record<number, string>>(() => Object.fromEntries(references.items.map((i) => [i.id, i.label])));
  const full = field.max !== null && value.length >= field.max;

  return (
    <Stack gap={6}>
      {value.length === 0 && (
        <Text size="sm" c="dimmed">
          None chosen.
        </Text>
      )}
      {value.map((id) => (
        <Group key={id} gap="xs" wrap="nowrap">
          <Text size="sm" style={{ flex: 1 }}>
            {labels[id] ?? `Item #${id}`}
          </Text>
          <ActionIcon variant="subtle" color="red" aria-label="Remove" disabled={disabled} onClick={() => onChange(value.filter((x) => x !== id))}>
            <IconX size={16} />
          </ActionIcon>
        </Group>
      ))}
      {!disabled && !full && (
        <VariantPicker
          value={null}
          placeholder="Search to add a product"
          onChange={(picked) => {
            if (!picked || value.includes(picked.id)) return;
            setLabels((l) => ({ ...l, [picked.id]: picked.label }));
            onChange([...value, picked.id]);
          }}
        />
      )}
      {error && (
        <Text size="xs" c="red">
          {error}
        </Text>
      )}
    </Stack>
  );
}
