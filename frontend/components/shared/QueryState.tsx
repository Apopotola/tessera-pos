"use client";

import { Alert, Button, Center, Loader, Stack, Text } from "@mantine/core";
import { IconAlertCircle, IconInbox, IconLock } from "@tabler/icons-react";
import type { ReactNode } from "react";
import type { ApiError } from "@/api";

interface QueryStateProps {
  loading: boolean;
  error: ApiError | null;
  isEmpty: boolean;
  emptyMessage?: string;
  onRetry?: () => void;
  children: ReactNode;
}

/**
 * Consistent loading / error / forbidden / empty handling for data screens.
 * Renders children only when data is ready and non-empty.
 */
export default function QueryState({ loading, error, isEmpty, emptyMessage = "Nothing to show yet.", onRetry, children }: QueryStateProps) {
  if (loading) {
    return (
      <Center py="xl">
        <Loader />
      </Center>
    );
  }

  if (error?.status === 403) {
    return (
      <Alert color="yellow" variant="light" icon={<IconLock size={18} />} title="Access denied">
        You do not have permission to view this information.
      </Alert>
    );
  }

  if (error) {
    return (
      <Alert color="red" variant="light" icon={<IconAlertCircle size={18} />} title="Could not load data">
        <Stack gap="xs" align="flex-start">
          <Text size="sm">{error.message}</Text>
          {onRetry && (
            <Button size="xs" variant="light" color="red" onClick={onRetry}>
              Try again
            </Button>
          )}
        </Stack>
      </Alert>
    );
  }

  if (isEmpty) {
    return (
      <Stack align="center" gap="xs" py="xl" c="dimmed">
        <IconInbox size={32} stroke={1.4} />
        <Text size="sm">{emptyMessage}</Text>
      </Stack>
    );
  }

  return <>{children}</>;
}
