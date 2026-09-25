"use client";

import { Alert, Button, Group, Modal, NumberInput, Stack, Table, Text, Textarea } from "@mantine/core";
import { IconInfoCircle } from "@tabler/icons-react";
import { useState } from "react";
import { inventoryApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import type { Transfer } from "@/types/inventory";

interface Props {
  transfer: Transfer;
  mode: "dispatch" | "receive";
  onClose: () => void;
  onDone: () => void;
}

/** Confirm quantities actually sent (dispatch) or arrived in good condition (receive). */
export default function TransferQuantitiesModal({ transfer, mode, onClose, onDone }: Props) {
  const dispatching = mode === "dispatch";
  const limit = (line: Transfer["lines"][number]) => (dispatching ? line.quantityRequested : (line.quantityDispatched ?? 0));
  const [quantities, setQuantities] = useState<Record<number, number | string>>(() => Object.fromEntries(transfer.lines.map((l) => [l.id, limit(l)])));
  const [note, setNote] = useState("");

  const shortfall = !dispatching && transfer.lines.some((l) => Number(quantities[l.id]) < limit(l));

  const { mutate, pending } = useApiMutation(
    () => {
      const q = Object.fromEntries(Object.entries(quantities).map(([id, v]) => [Number(id), Number(v) || 0]));
      return dispatching ? inventoryApi.dispatchTransfer(transfer.id, q) : inventoryApi.receiveTransfer(transfer.id, q, note.trim() || null);
    },
    { successMessage: dispatching ? `${transfer.number} dispatched.` : `${transfer.number} received.`, onSuccess: onDone },
  );

  return (
    <Modal opened onClose={onClose} title={`${dispatching ? "Dispatch" : "Receive"} ${transfer.number}`} size="lg">
      <Stack>
        <Text size="sm" c="dimmed">
          {dispatching
            ? `Count what is loaded for ${transfer.to.branchName}. Stock leaves ${transfer.from.location.name} and is shown as in transit.`
            : "Enter what arrived in good condition. Anything short stays in transit and is sent for approval as a breakage."}
        </Text>
        <Table>
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Item</Table.Th>
              <Table.Th ta="right">{dispatching ? "Requested" : "Sent"}</Table.Th>
              <Table.Th w={130}>{dispatching ? "Sending" : "Received"}</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {transfer.lines.map((line) => (
              <Table.Tr key={line.id}>
                <Table.Td>{line.variant.displayName}</Table.Td>
                <Table.Td ta="right">{limit(line)}</Table.Td>
                <Table.Td>
                  <NumberInput
                    min={0}
                    max={limit(line)}
                    value={quantities[line.id]}
                    onChange={(v) => setQuantities((q) => ({ ...q, [line.id]: v }))}
                    aria-label={`${dispatching ? "Sending" : "Received"} ${line.variant.displayName}`}
                  />
                </Table.Td>
              </Table.Tr>
            ))}
          </Table.Tbody>
        </Table>
        {shortfall && (
          <>
            <Alert color="yellow" variant="light" icon={<IconInfoCircle size={18} />}>
              Some items are short. The difference will be recorded as a breakage in transit for a manager to approve.
            </Alert>
            <Textarea label="What happened?" placeholder="e.g. One bottle broken in the van" value={note} onChange={(e) => setNote(e.currentTarget.value)} />
          </>
        )}
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button loading={pending} onClick={() => void mutate()}>
            {dispatching ? "Confirm dispatch" : "Confirm receipt"}
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
