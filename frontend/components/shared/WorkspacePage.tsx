import { Box, Stack } from "@mantine/core";
import type { ReactNode } from "react";
import PageHeader from "@/components/shared/PageHeader";

interface WorkspacePageProps {
  /** Omit for pages that render their own hero (e.g. the dashboard). */
  title?: string;
  description?: string;
  /** Section label above the title — pass the `section` prop every view receives. */
  section?: string | null;
  actions?: ReactNode;
  children: ReactNode;
}

/**
 * The frame for every back-office screen: same padding, width, header and spacing.
 * Every view registered in ViewRegistry must render inside it.
 */
export default function WorkspacePage({ title, description, section, actions, children }: WorkspacePageProps) {
  return (
    <Box px={{ base: "md", md: "xl" }} py={{ base: "md", md: "lg" }} maw={1440}>
      <Stack gap="lg">
        {title && <PageHeader eyebrow={section ?? undefined} title={title} description={description} actions={actions} />}
        {children}
      </Stack>
    </Box>
  );
}
