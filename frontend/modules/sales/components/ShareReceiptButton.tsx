"use client";

import { Button, CopyButton, Group, Modal, Stack, Text, TextInput } from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconBrandWhatsapp, IconCheck, IconCopy, IconShare } from "@tabler/icons-react";
import { useState } from "react";
import { ApiError } from "@/api";

interface ShareReceiptButtonProps {
  saleNumber: string;
  businessName: string;
  /** Makes a new link on the server and returns its token (the till or the back office). */
  createToken: () => Promise<string>;
  size?: "sm" | "md" | "lg";
}

/**
 * "Share receipt": a new private link to the receipt (/r/{token}) for WhatsApp or anywhere else.
 * The link opens the receipt without signing in; every open is logged.
 */
export default function ShareReceiptButton({ saleNumber, businessName, createToken, size = "md" }: ShareReceiptButtonProps) {
  const [busy, setBusy] = useState(false);
  const [link, setLink] = useState<string | null>(null);

  const share = async () => {
    if (busy) return;
    setBusy(true);
    try {
      setLink(`${window.location.origin}/r/${await createToken()}`);
    } catch (e) {
      notifications.show({ color: "red", message: e instanceof ApiError ? e.message : "Could not make a receipt link." });
    } finally {
      setBusy(false);
    }
  };

  const message = link ? `Your receipt ${saleNumber} from ${businessName}: ${link}` : "";

  return (
    <>
      <Button size={size} variant="default" leftSection={<IconShare size={18} />} loading={busy} onClick={() => void share()}>
        Share receipt
      </Button>
      {link && (
        <Modal opened onClose={() => setLink(null)} title={`Share receipt ${saleNumber}`} centered>
          <Stack>
            <Text size="sm" c="dimmed">
              Anyone with this link can see this receipt as printed, nothing more. Send it only to the customer.
            </Text>
            <TextInput value={link} readOnly onFocus={(e) => e.currentTarget.select()} />
            <Group grow>
              <CopyButton value={link}>
                {({ copied, copy }) => (
                  <Button variant="default" leftSection={copied ? <IconCheck size={18} /> : <IconCopy size={18} />} onClick={copy}>
                    {copied ? "Copied" : "Copy link"}
                  </Button>
                )}
              </CopyButton>
              <Button component="a" href={`https://wa.me/?text=${encodeURIComponent(message)}`} target="_blank" rel="noopener noreferrer" color="green" leftSection={<IconBrandWhatsapp size={18} />}>
                WhatsApp
              </Button>
            </Group>
          </Stack>
        </Modal>
      )}
    </>
  );
}
