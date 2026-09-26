"use client";

import { Badge, Button, Group, Pagination, SegmentedControl, Stack, Table, Text } from "@mantine/core";
import { IconPlus } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { catalogueApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import NoteModal from "@/components/shared/NoteModal";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import PromotionFormModal from "@/modules/catalogue/components/PromotionFormModal";
import { promotionOffer } from "@/modules/catalogue/promotions";
import { useAppSelector } from "@/store/hooks";
import type { Promotion } from "@/types/catalogue";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

type Tab = "active" | "pending" | "finished";
const WEEKDAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
const STATUS = { pending: ["Waiting for approval", "yellow"], active: ["Approved", "green"], rejected: ["Rejected", "red"], ended: ["Ended early", "gray"], finished: ["Finished", "gray"] } as const;

function when(p: Promotion): string {
  const dates = `${dayjs(p.startsOn).format("D MMM")} – ${dayjs(p.endsOn).format("D MMM YYYY")}`;
  const days = p.weekdays?.length ? ` · ${p.weekdays.map((d) => WEEKDAYS[d - 1]).join(", ")}` : "";
  const hours = p.timeFrom && p.timeTo ? ` · ${p.timeFrom}–${p.timeTo}` : "";
  return dates + days + hours;
}

/** Promotions: set up by managers, approved by the owner, applied at the till automatically. */
export default function PromotionsView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const me = useAppSelector((state) => state.auth.user);
  const [tab, setTab] = useState<Tab>("active");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [dialog, setDialog] = useState<{ kind: "reject" | "end"; promotion: Promotion } | null>(null);
  const fetchPromotions = useCallback(() => catalogueApi.promotions(tab, page), [tab, page]);
  const { data, loading, error, reload } = useApiQuery(fetchPromotions);
  const approve = useApiMutation((p: Promotion) => catalogueApi.approvePromotion(p.id), { successMessage: "Promotion approved.", onSuccess: reload });
  const canApprove = can(PERMISSIONS.PROMOTIONS_APPROVE);

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Price rules like “buy 6 wine, 10% off” or a happy hour. The owner approves each one; the till applies the best promotion to each line automatically."
      actions={
        can(PERMISSIONS.PROMOTIONS_REQUEST) && (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setCreating(true)}>
            New promotion
          </Button>
        )
      }
    >
      <SegmentedControl
        value={tab}
        onChange={(v) => {
          setTab(v as Tab);
          setPage(1);
        }}
        data={[
          { value: "active", label: "Approved" },
          { value: "pending", label: "Waiting for approval" },
          { value: "finished", label: "Finished" },
        ]}
        w="fit-content"
      />
      <DataCard>
        <QueryState loading={loading && !data} error={error} isEmpty={!data?.items.length} emptyMessage="No promotions here." onRetry={reload}>
          <Table verticalSpacing="sm" fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Promotion</Table.Th>
                <Table.Th>Applies to</Table.Th>
                <Table.Th>When</Table.Th>
                <Table.Th>Status</Table.Th>
                <Table.Th ta="right">Given so far</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((p) => {
                const targets = [...p.categories, ...p.brands, ...p.variants];
                const [label, color] = STATUS[p.status];
                return (
                  <Table.Tr key={p.id}>
                    <Table.Td>
                      <Text size="sm" fw={600}>
                        {p.name}
                      </Text>
                      <Text size="xs" c="dimmed">
                        {promotionOffer(p)}
                      </Text>
                    </Table.Td>
                    <Table.Td maw={260}>
                      <Text size="sm" lineClamp={2}>
                        {targets.length ? targets.join(", ") : "Everything"}
                      </Text>
                      {p.branchIds?.length ? (
                        <Text size="xs" c="dimmed">
                          {p.branchIds.length} branch{p.branchIds.length === 1 ? "" : "es"}
                        </Text>
                      ) : null}
                    </Table.Td>
                    <Table.Td>{when(p)}</Table.Td>
                    <Table.Td>
                      <Badge color={color} variant="light">
                        {label}
                      </Badge>
                      <Text size="xs" c="dimmed" mt={2}>
                        By {p.requestedBy ?? "—"}
                        {p.reviewedBy && p.reviewedBy !== p.requestedBy ? ` · approved by ${p.reviewedBy}` : ""}
                      </Text>
                      {p.reviewNote && p.status === "rejected" && (
                        <Text size="xs" c="red">
                          {p.reviewNote}
                        </Text>
                      )}
                    </Table.Td>
                    <Table.Td ta="right">
                      {formatKes(p.discountGivenCents)}
                      <Text size="xs" c="dimmed">
                        {p.linesCount} line{p.linesCount === 1 ? "" : "s"}
                      </Text>
                    </Table.Td>
                    <Table.Td ta="right">
                      {p.status === "pending" && canApprove && p.requestedById !== me?.id && (
                        <Group gap="xs" justify="flex-end" wrap="nowrap">
                          <Button size="xs" loading={approve.pending} onClick={() => void approve.mutate(p)}>
                            Approve
                          </Button>
                          <Button size="xs" variant="default" color="red" onClick={() => setDialog({ kind: "reject", promotion: p })}>
                            Reject
                          </Button>
                        </Group>
                      )}
                      {p.status === "active" && canApprove && (
                        <Button size="xs" variant="subtle" color="red" onClick={() => setDialog({ kind: "end", promotion: p })}>
                          End now
                        </Button>
                      )}
                    </Table.Td>
                  </Table.Tr>
                );
              })}
            </Table.Tbody>
          </Table>
        </QueryState>
        {data && data.meta.lastPage > 1 && (
          <Group justify="flex-end" p="sm">
            <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />
          </Group>
        )}
      </DataCard>
      <Stack gap={4}>
        <Text size="xs" c="dimmed">
          One promotion per sale line: the till takes the biggest. Lines at a changed price or at the wholesale price get none. A cashier&apos;s discount can come on top, within their limit.
        </Text>
      </Stack>

      {creating && (
        <PromotionFormModal
          onClose={() => setCreating(false)}
          onSaved={(saved) => {
            setCreating(false);
            setTab(saved.status === "pending" ? "pending" : "active");
            reload();
          }}
        />
      )}
      {dialog && (
        <NoteModal
          title={dialog.kind === "reject" ? `Reject “${dialog.promotion.name}”?` : `End “${dialog.promotion.name}” now?`}
          description={dialog.kind === "end" ? "It stops at the till straight away. Sales already made keep their discount." : undefined}
          label="Reason"
          confirmLabel={dialog.kind === "reject" ? "Reject" : "End promotion"}
          danger
          successMessage={dialog.kind === "reject" ? "Promotion rejected." : "Promotion ended."}
          onSubmit={(note) => (dialog.kind === "reject" ? catalogueApi.rejectPromotion(dialog.promotion.id, note) : catalogueApi.endPromotion(dialog.promotion.id, note))}
          onClose={() => setDialog(null)}
          onDone={() => {
            setDialog(null);
            reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}
