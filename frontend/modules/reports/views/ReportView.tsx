"use client";

import { Alert, Button, Group, Select, SegmentedControl, Stack, Table, Text } from "@mantine/core";
import { DateInput } from "@mantine/dates";
import { notifications } from "@mantine/notifications";
import { IconDownload, IconPrinter } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useMemo, useState } from "react";
import { ApiError, reportsApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { useCatalogueReference } from "@/modules/catalogue/hooks/useCatalogueReference";
import type { ReportCell, ReportColumn, ReportQuery } from "@/types/reports";
import { formatKes } from "@/utils/money";

const today = () => dayjs().format("YYYY-MM-DD");

/** One report: filters, table with totals, notes, CSV export and print (Save as PDF). */
export default function ReportView({ title, section, props }: WorkspaceViewProps) {
  const reportKey = String(props?.reportKey ?? "");
  const [query, setQuery] = useState<ReportQuery>({ from: dayjs().startOf("month").format("YYYY-MM-DD"), to: today(), asAt: today() });
  const [exporting, setExporting] = useState(false);

  const fetchReport = useCallback(() => reportsApi.run(reportKey, query), [reportKey, query]);
  const report = useApiQuery(fetchReport);
  const fetchCatalogue = useCallback(() => reportsApi.catalogue(), []);
  const catalogue = useApiQuery(fetchCatalogue);
  const { brandOptions, categoryOptions } = useCatalogueReference();

  const definition = report.data?.report;
  const filters = useMemo(() => definition?.filters ?? [], [definition]);
  const set = (patch: ReportQuery) => setQuery((q) => ({ ...q, ...patch }));

  const exportCsv = async () => {
    setExporting(true);
    try {
      await reportsApi.exportCsv(reportKey, query);
    } catch (e) {
      notifications.show({ color: "red", message: e instanceof ApiError ? e.message : "Export failed." });
    } finally {
      setExporting(false);
    }
  };

  const period = useMemo(() => {
    if (!report.data) return "";
    const f = report.data.filters;
    if (filters.includes("asAt")) return `As at ${dayjs(f.asAt).format("D MMM YYYY")}`;
    if (filters.includes("dateRange")) return `${dayjs(f.from).format("D MMM YYYY")} – ${dayjs(f.to).format("D MMM YYYY")}`;
    return `As at ${dayjs().format("D MMM YYYY, h:mm a")}`;
  }, [report.data, filters]);

  return (
    <WorkspacePage
      section={section}
      title={definition?.title ?? title}
      description={definition?.description}
      actions={
        <>
          {catalogue.data?.canExport && (
            <Button leftSection={<IconDownload size={16} />} loading={exporting} disabled={!report.data?.rows.length} onClick={() => void exportCsv()}>
              Export CSV
            </Button>
          )}
          <Button variant="default" leftSection={<IconPrinter size={16} />} disabled={!report.data} onClick={() => window.print()}>
            Print / PDF
          </Button>
        </>
      }
    >
      <Group align="flex-end" gap="sm">
        {Object.keys(definition?.groupings ?? {}).length > 0 && (
          <SegmentedControl
            value={query.groupBy ?? Object.keys(definition?.groupings ?? {})[0]}
            onChange={(groupBy) => set({ groupBy })}
            data={Object.entries(definition?.groupings ?? {}).map(([value, label]) => ({ value, label }))}
          />
        )}
        {filters.includes("dateRange") && (
          <>
            <DateInput label="From" valueFormat="DD MMM YYYY" maxDate={new Date()} value={query.from ?? null} onChange={(v) => v && set({ from: v })} w={150} />
            <DateInput label="To" valueFormat="DD MMM YYYY" maxDate={new Date()} value={query.to ?? null} onChange={(v) => v && set({ to: v })} w={150} />
          </>
        )}
        {filters.includes("asAt") && (
          <DateInput label="As at close of" valueFormat="DD MMM YYYY" maxDate={new Date()} value={query.asAt ?? null} onChange={(v) => v && set({ asAt: v })} w={170} />
        )}
        {filters.includes("branch") && (catalogue.data?.branches.length ?? 0) > 1 && (
          <Select
            label="Branch"
            placeholder="All branches"
            clearable
            data={(catalogue.data?.branches ?? []).map((b) => ({ value: String(b.id), label: b.name }))}
            value={query.branchId ? String(query.branchId) : null}
            onChange={(v) => set({ branchId: v ? Number(v) : undefined })}
            w={180}
          />
        )}
        {filters.includes("category") && (
          <Select label="Category" placeholder="All" clearable searchable data={categoryOptions} value={query.categoryId ? String(query.categoryId) : null} onChange={(v) => set({ categoryId: v ? Number(v) : undefined })} w={190} />
        )}
        {filters.includes("brand") && (
          <Select label="Brand" placeholder="All" clearable searchable data={brandOptions} value={query.brandId ? String(query.brandId) : null} onChange={(v) => set({ brandId: v ? Number(v) : undefined })} w={170} />
        )}
        {filters.includes("user") && (
          <Select
            label="Staff"
            placeholder="Everyone"
            clearable
            searchable
            data={(catalogue.data?.staff ?? []).map((u) => ({ value: String(u.id), label: u.name }))}
            value={query.userId ? String(query.userId) : null}
            onChange={(v) => set({ userId: v ? Number(v) : undefined })}
            w={180}
          />
        )}
        {filters.includes("status") && Object.keys(definition?.statuses ?? {}).length > 0 && (
          <Select
            label="Show"
            allowDeselect={false}
            data={Object.entries(definition?.statuses ?? {}).map(([value, label]) => ({ value, label }))}
            value={query.status ?? ""}
            onChange={(v) => set({ status: v || undefined })}
            w={170}
          />
        )}
      </Group>

      <div className="tessera-printable">
        <DataCard>
          <Stack gap={2} px="md" pt="md">
            <Text fw={700}>{definition?.title ?? title}</Text>
            <Text size="sm" c="dimmed">
              {period}
            </Text>
          </Stack>
          <QueryState loading={report.loading} error={report.error} isEmpty={!report.data?.rows.length} emptyMessage="Nothing to report for these filters." onRetry={report.reload}>
            <Table.ScrollContainer minWidth={640}>
              <Table verticalSpacing="xs" striped highlightOnHover>
                <Table.Thead>
                  <Table.Tr>
                    {report.data?.columns.map((c) => (
                      <Table.Th key={c.key} ta={alignOf(c)}>
                        {c.label}
                      </Table.Th>
                    ))}
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {report.data?.rows.map((row, i) => (
                    <Table.Tr key={i}>
                      {report.data?.columns.map((c) => (
                        <Table.Td key={c.key} ta={alignOf(c)} c={isNegative(row[c.key], c) ? "red.7" : undefined}>
                          {formatCell(row[c.key], c)}
                        </Table.Td>
                      ))}
                    </Table.Tr>
                  ))}
                </Table.Tbody>
                {report.data?.totals && (
                  <Table.Tfoot>
                    <Table.Tr>
                      {report.data.columns.map((c, i) => (
                        <Table.Th key={c.key} ta={alignOf(c)}>
                          {i === 0 ? "Total" : c.total ? formatCell(report.data?.totals?.[c.key] ?? null, c) : ""}
                        </Table.Th>
                      ))}
                    </Table.Tr>
                  </Table.Tfoot>
                )}
              </Table>
            </Table.ScrollContainer>
          </QueryState>
          {report.data?.notes.map((note) => (
            <Text key={note} size="xs" c="dimmed" px="md" pb="sm">
              {note}
            </Text>
          ))}
        </DataCard>
      </div>

      {report.data?.truncated && (
        <Alert color="yellow">Showing the first 2,000 of {report.data.rowCount.toLocaleString()} rows. Export to CSV for all of them.</Alert>
      )}
    </WorkspacePage>
  );
}

function alignOf(column: ReportColumn): "left" | "right" {
  return column.type === "money" || column.type === "number" || column.type === "percent" ? "right" : "left";
}

function isNegative(value: ReportCell, column: ReportColumn): boolean {
  return typeof value === "number" && value < 0 && (column.type === "money" || column.type === "number");
}

function formatCell(value: ReportCell, column: ReportColumn): string {
  if (value === null || value === undefined || value === "") return "—";
  switch (column.type) {
    case "money":
      return formatKes(Number(value));
    case "number":
      return Number(value).toLocaleString("en-KE");
    case "percent":
      return `${Number(value).toFixed(1)}%`;
    case "date":
      return dayjs(String(value)).format("D MMM YYYY");
    case "datetime":
      return dayjs(String(value)).format("D MMM YYYY, h:mm a");
    default:
      return String(value);
  }
}
