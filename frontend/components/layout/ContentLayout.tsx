"use client";

import { AppShell } from "@mantine/core";
import { useDisclosure } from "@mantine/hooks";
import { brand } from "@/app/theme";
import Header from "@/components/layout/Header";
import Sidebar from "@/components/layout/Sidebar";
import WorkspaceTabRenderer from "@/components/workspace/WorkspaceTabRenderer";
import WorkspaceTabs from "@/components/workspace/WorkspaceTabs";
import WorkspaceUrlSync from "@/components/workspace/WorkspaceUrlSync";

/** Authenticated shell: header, permission-filtered sidebar, and the tab workspace. */
export default function ContentLayout() {
  const [navOpened, { toggle, close }] = useDisclosure();

  return (
    <AppShell
      header={{ height: 60 }}
      navbar={{ width: 250, breakpoint: "sm", collapsed: { mobile: !navOpened } }}
      padding={0}
      styles={{ main: { background: brand.cream }, header: { borderBottom: 0 } }}
    >
      <AppShell.Header>
        <Header navOpened={navOpened} onToggleNav={toggle} />
      </AppShell.Header>

      <AppShell.Navbar p="sm">
        <Sidebar onNavigate={close} />
      </AppShell.Navbar>

      <AppShell.Main>
        <WorkspaceUrlSync />
        <WorkspaceTabs />
        <WorkspaceTabRenderer />
      </AppShell.Main>
    </AppShell>
  );
}
