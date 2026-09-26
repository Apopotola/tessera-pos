"use client";

import { Alert, Anchor, Button, CopyButton, Group, Paper, PinInput, SimpleGrid, Stack, Text, TextInput } from "@mantine/core";
import { IconCheck, IconCopy, IconShieldLock } from "@tabler/icons-react";
import { QRCodeSVG } from "qrcode.react";
import { useState } from "react";
import type { MfaSetup } from "@/types/auth";

/** Step 1 of setting up two-step login: scan the QR code (or type the key) in an authenticator app. */
export function AuthenticatorQr({ setup }: { setup: MfaSetup }) {
  const key = setup.secret.match(/.{1,4}/g)?.join(" ") ?? setup.secret;

  return (
    <Stack gap="sm">
      <Text size="sm">
        Open an authenticator app on your phone (Google Authenticator, Microsoft Authenticator or Authy), add an account and scan this code.
      </Text>
      <Group justify="center">
        <Paper withBorder p="sm" radius="md" bg="white">
          <QRCodeSVG value={setup.uri} size={168} marginSize={0} />
        </Paper>
      </Group>
      <Text size="xs" c="dimmed" ta="center">
        Can&apos;t scan? Type this key instead:
      </Text>
      <Text ff="monospace" fw={600} fz="sm" ta="center" style={{ letterSpacing: "0.04em" }}>
        {key}
      </Text>
    </Stack>
  );
}

/**
 * The 6-digit code from the app, or a recovery code (when `allowRecovery`).
 * Calls onSubmit once all digits are in; shows the server's error.
 */
export function TwoStepCodeForm({
  onSubmit,
  pending,
  error,
  allowRecovery = false,
  submitLabel = "Continue",
}: {
  onSubmit: (code: string) => void;
  pending: boolean;
  error: string | null;
  allowRecovery?: boolean;
  submitLabel?: string;
}) {
  const [code, setCode] = useState("");
  const [recovery, setRecovery] = useState(false);
  // A refused code is cleared so the next one can be typed straight away.
  const [seenError, setSeenError] = useState(error);
  if (error !== seenError) {
    setSeenError(error);
    if (error) setCode("");
  }
  const ready = recovery ? code.replace(/[^A-Za-z0-9]/g, "").length === 10 : code.length === 6;
  const submit = () => ready && !pending && onSubmit(code);

  return (
    <Stack gap="sm">
      {recovery ? (
        <TextInput
          label="Recovery code"
          placeholder="ABCDE-12345"
          value={code}
          onChange={(e) => setCode(e.currentTarget.value.toUpperCase())}
          onKeyDown={(e) => e.key === "Enter" && submit()}
          styles={{ input: { fontFamily: "var(--font-mono), monospace", letterSpacing: "0.08em" } }}
          data-autofocus
        />
      ) : (
        <Stack gap={6} align="center">
          <Text size="sm" fw={500}>
            6-digit code from your app
          </Text>
          <PinInput
            length={6}
            type="number"
            oneTimeCode
            size="lg"
            value={code}
            onChange={setCode}
            onComplete={(value) => !pending && onSubmit(value)}
            error={Boolean(error)}
            autoFocus
            aria-label="Two-step code"
          />
        </Stack>
      )}
      {error && (
        <Text size="sm" c="red" ta="center" role="alert">
          {error}
        </Text>
      )}
      <Button fullWidth size="md" onClick={submit} loading={pending} disabled={!ready}>
        {submitLabel}
      </Button>
      {allowRecovery && (
        <Anchor
          component="button"
          type="button"
          size="sm"
          ta="center"
          onClick={() => {
            setRecovery(!recovery);
            setCode("");
          }}
        >
          {recovery ? "Use the code from my app" : "Lost your phone? Use a recovery code"}
        </Anchor>
      )}
    </Stack>
  );
}

/** Recovery codes, shown once: each signs in once if the phone is lost. */
export function RecoveryCodes({ codes }: { codes: string[] }) {
  const formatted = codes.map((c) => `${c.slice(0, 5)}-${c.slice(5)}`);

  return (
    <Stack gap="sm">
      <Alert color="amber" variant="light" icon={<IconShieldLock size={18} />} title="Save your recovery codes">
        If you lose your phone, each code signs you in once. Keep them somewhere safe, away from this computer. They are not shown again.
      </Alert>
      <SimpleGrid cols={2} spacing={6}>
        {formatted.map((code) => (
          <Text key={code} ff="monospace" fw={600} ta="center" py={4} bg="var(--mantine-color-gray-0)" style={{ borderRadius: 6, letterSpacing: "0.06em" }}>
            {code}
          </Text>
        ))}
      </SimpleGrid>
      <CopyButton value={formatted.join("\n")}>
        {({ copied, copy }) => (
          <Button variant="default" leftSection={copied ? <IconCheck size={16} /> : <IconCopy size={16} />} onClick={copy}>
            {copied ? "Copied" : "Copy codes"}
          </Button>
        )}
      </CopyButton>
    </Stack>
  );
}
