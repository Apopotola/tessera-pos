"use client";

import { Box } from "@mantine/core";
import DynamicPage from "@/components/workspace/DynamicPage";
import { useAppSelector } from "@/store/hooks";

/**
 * Renders ALL open tabs and only displays the active one, so switching tabs
 * keeps each screen's state (form input, scroll, filters) alive.
 */
export default function WorkspaceTabRenderer() {
  const { tabs, activeTabId } = useAppSelector((state) => state.tabs);

  return (
    <>
      {tabs.map((tab) => (
        <Box key={tab.id} style={{ display: tab.id === activeTabId ? "block" : "none" }}>
          <DynamicPage tab={tab} />
        </Box>
      ))}
    </>
  );
}
