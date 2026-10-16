"use client";

import { AppShell } from "@mantine/core";
import { useDisclosure } from "@mantine/hooks";
import { brand } from "@/app/theme";
import Sidebar from "@/components/layout/Sidebar";
import WorkspaceBar from "@/components/layout/WorkspaceBar";
import WorkspaceTabRenderer from "@/components/workspace/WorkspaceTabRenderer";
import WorkspaceUrlSync from "@/components/workspace/WorkspaceUrlSync";
import sidebarClasses from "@/components/layout/Sidebar.module.css";

/** Authenticated shell: navy sidebar with the shop name, a slim light bar (tabs, alerts, account) and the page. */
export default function ContentLayout() {
  const [navOpened, { toggle, close }] = useDisclosure();

  return (
    <AppShell navbar={{ width: 240, breakpoint: "sm", collapsed: { mobile: !navOpened } }} padding={0} styles={{ main: { background: brand.surface } }}>
      <AppShell.Navbar className={sidebarClasses.navbar}>
        <Sidebar onNavigate={close} />
      </AppShell.Navbar>

      <AppShell.Main>
        <WorkspaceUrlSync />
        <WorkspaceBar navOpened={navOpened} onToggleNav={toggle} />
        <WorkspaceTabRenderer />
      </AppShell.Main>
    </AppShell>
  );
}
