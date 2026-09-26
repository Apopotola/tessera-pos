"use client";

import { Alert, Badge, Button, Group, Modal, Stack, Text } from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { useCallback, useState } from "react";
import { ApiError, authApi } from "@/api";
import { AuthenticatorQr, RecoveryCodes, TwoStepCodeForm } from "@/components/auth/TwoStepParts";
import QueryState from "@/components/shared/QueryState";
import { useApiQuery } from "@/hooks/useApiQuery";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { userUpdated } from "@/store/slices/authSlice";
import type { MfaSetup } from "@/types/auth";

type Stage = { kind: "status" } | { kind: "setup"; setup: MfaSetup } | { kind: "codes"; codes: string[] } | { kind: "disable" };

/** Your two-step login: turn it on (scan, confirm, save recovery codes), or off when your role allows. */
export default function TwoStepModal({ onClose }: { onClose: () => void }) {
  const dispatch = useAppDispatch();
  const user = useAppSelector((state) => state.auth.user);
  const fetchStatus = useCallback(() => authApi.mfaStatus(), []);
  const { data: status, loading, error: loadError, reload } = useApiQuery(fetchStatus);
  const [stage, setStage] = useState<Stage>({ kind: "status" });
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const run = async (action: () => Promise<void>) => {
    setPending(true);
    setError(null);
    try {
      await action();
    } catch (e) {
      setError(e instanceof ApiError ? (e.formErrors.code ?? e.message) : "Something went wrong. Try again.");
    } finally {
      setPending(false);
    }
  };

  const setEnabled = (enabled: boolean) => user && dispatch(userUpdated({ ...user, mfaEnabled: enabled }));

  return (
    <Modal opened onClose={onClose} title="Two-step login" centered size="md">
      <QueryState loading={loading} error={loadError} isEmpty={!status} onRetry={reload}>
        {status && stage.kind === "status" && (
          <Stack>
            <Group justify="space-between">
              <Text fw={600}>Status</Text>
              <Badge color={status.enabled ? "green" : "gray"} variant="light">
                {status.enabled ? "On" : "Off"}
              </Badge>
            </Group>
            <Text size="sm" c="dimmed">
              After your password you type a code from an authenticator app on your phone, so a stolen password alone cannot open your account.
              {status.required && " Your role requires it."}
            </Text>
            {status.enabled && (
              <Text size="sm">
                Recovery codes left: <b>{status.recoveryCodesLeft}</b>
              </Text>
            )}
            <Group justify="flex-end">
              {!status.enabled && (
                <Button loading={pending} onClick={() => void run(async () => setStage({ kind: "setup", setup: await authApi.mfaStart() }))}>
                  Set up two-step login
                </Button>
              )}
              {status.enabled && !status.required && (
                <Button variant="light" color="red" onClick={() => setStage({ kind: "disable" })}>
                  Turn off
                </Button>
              )}
              {status.enabled && status.required && (
                <Text size="xs" c="dimmed">
                  New phone? Ask an administrator to reset it, or sign in with a recovery code.
                </Text>
              )}
            </Group>
          </Stack>
        )}

        {stage.kind === "setup" && (
          <Stack>
            <AuthenticatorQr setup={stage.setup} />
            <TwoStepCodeForm
              submitLabel="Turn on"
              pending={pending}
              error={error}
              onSubmit={(code) =>
                void run(async () => {
                  const { recoveryCodes } = await authApi.mfaEnable(code);
                  setEnabled(true);
                  setStage({ kind: "codes", codes: recoveryCodes });
                })
              }
            />
          </Stack>
        )}

        {stage.kind === "codes" && (
          <Stack>
            <RecoveryCodes codes={stage.codes} />
            <Button onClick={onClose}>I have saved them</Button>
          </Stack>
        )}

        {stage.kind === "disable" && (
          <Stack>
            <Alert color="yellow" variant="light">
              Enter a code from your app to turn two-step login off.
            </Alert>
            <TwoStepCodeForm
              submitLabel="Turn off"
              allowRecovery
              pending={pending}
              error={error}
              onSubmit={(code) =>
                void run(async () => {
                  await authApi.mfaDisable(code);
                  setEnabled(false);
                  notifications.show({ color: "green", message: "Two-step login is off." });
                  onClose();
                })
              }
            />
          </Stack>
        )}
      </QueryState>
    </Modal>
  );
}
