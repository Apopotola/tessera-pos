"use client";

import { Alert, Button, Group, Loader, Modal, Stack, Table, Text } from "@mantine/core";
import { IconCloudOff, IconCloudUpload, IconAlertTriangle } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useState } from "react";
import type { OfflineTill } from "@/modules/till/offline/useOfflineTill";
import { formatKes } from "@/utils/money";

/** Connection status on the selling screen, and what is waiting to be sent. */
export default function OfflineBanner({ offline }: { offline: OfflineTill }) {
  const [reviewing, setReviewing] = useState(false);
  const failedSales = offline.failed.filter((f) => f.kind === "sale");

  return (
    <>
      {!offline.online && (
        <Alert color="yellow" variant="filled" radius="md" icon={<IconCloudOff size={20} />} title="Offline — still selling">
          Cash and card only. Returns, M-PESA, manager approvals and ending the shift wait for the connection.
          {offline.pendingSales > 0 && ` ${offline.pendingSales} sale${offline.pendingSales === 1 ? "" : "s"} waiting to send.`}
          {!offline.snapshot && " This till has no saved catalogue yet — connect once to download it."}
        </Alert>
      )}

      {offline.online && offline.syncing && (
        <Alert color="tessera" radius="md" icon={<Loader size={16} />}>
          Connection back — sending offline sales…
        </Alert>
      )}

      {offline.online && !offline.syncing && offline.pendingSales > 0 && (
        <Alert color="tessera" radius="md" icon={<IconCloudUpload size={20} />}>
          <Group justify="space-between">
            <span>
              {offline.pendingSales} offline sale{offline.pendingSales === 1 ? "" : "s"} still to send.
            </span>
            <Button size="xs" variant="white" onClick={() => void offline.sync()}>
              Send now
            </Button>
          </Group>
        </Alert>
      )}

      {failedSales.length > 0 && (
        <Alert color="red" radius="md" icon={<IconAlertTriangle size={20} />}>
          <Group justify="space-between">
            <span>
              {failedSales.length} offline sale{failedSales.length === 1 ? " was" : "s were"} not accepted. Keep the paper receipts and call a manager.
            </span>
            <Button size="xs" variant="white" color="red" onClick={() => setReviewing(true)}>
              Review
            </Button>
          </Group>
        </Alert>
      )}

      {offline.othersWaiting > 0 && (
        <Text size="xs" c="gray.5">
          {offline.othersWaiting} offline sale{offline.othersWaiting === 1 ? "" : "s"} by another cashier will be sent when they sign in on this till.
        </Text>
      )}

      {reviewing && (
        <Modal opened onClose={() => setReviewing(false)} title="Offline sales not accepted" size="lg" centered>
          <Stack>
            <Text size="sm" c="dimmed">
              The customer already paid for these. A manager should check each one (for example a price that changed, or stock rules) and record it in the back office if needed.
            </Text>
            <Table verticalSpacing="xs">
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Receipt</Table.Th>
                  <Table.Th>When</Table.Th>
                  <Table.Th ta="right">Total</Table.Th>
                  <Table.Th>Why</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {failedSales.map((f) => (
                  <Table.Tr key={f.clientId}>
                    <Table.Td ff="monospace">{f.kind === "sale" ? f.localNumber : ""}</Table.Td>
                    <Table.Td>{dayjs(f.queuedAt).format("D MMM, h:mm a")}</Table.Td>
                    <Table.Td ta="right">{f.kind === "sale" ? formatKes(f.totalCents) : ""}</Table.Td>
                    <Table.Td>
                      <Text size="sm" c="red.7">
                        {f.error}
                      </Text>
                    </Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
            <Group justify="flex-end">
              <Button
                onClick={() => {
                  offline.retryFailed();
                  setReviewing(false);
                }}
              >
                Try sending again
              </Button>
            </Group>
          </Stack>
        </Modal>
      )}
    </>
  );
}
