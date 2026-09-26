"use client";

import { Alert, Button, Group, Modal, PasswordInput, Select, Stack, Text } from "@mantine/core";
import { IconShieldCheck } from "@tabler/icons-react";
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, salesApi } from "@/api";
import type { Approval, ApprovalAction } from "@/types/sales";

const ACTION_LABEL: Record<ApprovalAction, string> = {
  discount: "a discount above your limit",
  override: "a price change",
  void: "removing a high-value item",
  refund: "a customer refund",
  cash_drop: "moving cash to the safe (witness)",
  below_zero: "selling more than the shelf holds",
};

interface Request {
  action: ApprovalAction;
  detail: string | null;
  resolve: (approval: Approval | null) => void;
}

/**
 * Manager approval at the till: pick a manager, they key in their PIN, and the API
 * returns a single-use token for exactly this action. Usage:
 *   const { requestApproval, approvalModal } = useApprovalPrompt();
 *   const approval = await requestApproval("refund", "Refund KES 4,800");
 */
export function useApprovalPrompt() {
  const [request, setRequest] = useState<Request | null>(null);

  const requestApproval = useCallback(
    (action: ApprovalAction, detail: string | null = null) => new Promise<Approval | null>((resolve) => setRequest({ action, detail, resolve })),
    [],
  );

  const finish = (approval: Approval | null) => {
    request?.resolve(approval);
    setRequest(null);
  };

  const approvalModal = request ? <ApprovalModal key={request.action} action={request.action} detail={request.detail} onDone={finish} /> : null;

  return { requestApproval, approvalModal };
}

function ApprovalModal({ action, detail, onDone }: { action: ApprovalAction; detail: string | null; onDone: (approval: Approval | null) => void }) {
  const [approvers, setApprovers] = useState<{ id: number; name: string }[] | null>(null);
  const [approverId, setApproverId] = useState<string | null>(null);
  const [pin, setPin] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const pinRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    let active = true;
    salesApi
      .approvers(action)
      .then((list) => {
        if (!active) return;
        setApprovers(list);
        if (list.length === 1) setApproverId(String(list[0].id));
      })
      .catch((e: unknown) => active && setError(e instanceof Error ? e.message : "Could not load managers."));
    return () => {
      active = false;
    };
  }, [action]);

  const submit = async () => {
    if (!approverId || pin.length < 4) return;
    setPending(true);
    setError(null);
    try {
      onDone(await salesApi.approve(Number(approverId), pin, action));
    } catch (e) {
      setPin("");
      setError(e instanceof ApiError ? (e.formErrors.pin ?? e.message) : "Approval failed.");
      pinRef.current?.focus();
    } finally {
      setPending(false);
    }
  };

  return (
    <Modal opened onClose={() => onDone(null)} title="Manager approval" centered>
      <Stack>
        <Group gap="xs" wrap="nowrap" align="flex-start">
          <IconShieldCheck size={20} color="var(--mantine-color-tessera-6)" />
          <Text size="sm">
            A manager must approve {ACTION_LABEL[action]}.{detail ? ` ${detail}` : ""}
          </Text>
        </Group>
        {approvers && approvers.length === 0 && (
          <Alert color="yellow">No manager with a till PIN can approve this at this branch. Ask an admin to set a manager PIN.</Alert>
        )}
        <Select
          label="Manager"
          placeholder="Choose manager"
          data={(approvers ?? []).map((a) => ({ value: String(a.id), label: a.name }))}
          value={approverId}
          onChange={(value) => {
            setApproverId(value);
            pinRef.current?.focus();
          }}
          disabled={!approvers}
        />
        <PasswordInput
          ref={pinRef}
          label="Manager PIN"
          inputMode="numeric"
          autoComplete="off"
          maxLength={6}
          value={pin}
          onChange={(e) => setPin(e.currentTarget.value.replace(/\D/g, ""))}
          onKeyDown={(e) => e.key === "Enter" && void submit()}
          data-autofocus
        />
        {error && <Alert color="red">{error}</Alert>}
        <Group justify="flex-end">
          <Button variant="default" onClick={() => onDone(null)} disabled={pending}>
            Cancel
          </Button>
          <Button onClick={() => void submit()} loading={pending} disabled={!approverId || pin.length < 4}>
            Approve
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
