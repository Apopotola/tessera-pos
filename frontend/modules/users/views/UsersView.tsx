"use client";

import { ActionIcon, Button, Menu, Table, Text } from "@mantine/core";
import { modals } from "@mantine/modals";
import { IconDots, IconEdit, IconKey, IconPlus, IconShieldOff } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useMemo, useState } from "react";
import { organisationApi, usersApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import StatusBadge from "@/components/shared/StatusBadge";
import WorkspacePage from "@/components/shared/WorkspacePage";
import QueryState from "@/components/shared/QueryState";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import PinModal from "@/modules/users/components/PinModal";
import UserFormModal from "@/modules/users/components/UserFormModal";
import type { ManagedUser } from "@/types/users";

type Dialog = { kind: "create" } | { kind: "edit"; user: ManagedUser } | { kind: "pin"; user: ManagedUser };

export default function UsersView({ title, section }: WorkspaceViewProps) {
  const fetchUsers = useCallback(() => usersApi.list(), []);
  const fetchBranches = useCallback(() => organisationApi.branches(), []);
  const users = useApiQuery(fetchUsers);
  const branches = useApiQuery(fetchBranches);
  const [dialog, setDialog] = useState<Dialog | null>(null);

  const branchName = useMemo(() => new Map((branches.data ?? []).map((b) => [b.id, b.code])), [branches.data]);

  const done = () => {
    setDialog(null);
    users.reload();
  };

  // Lost phone: two-step login is set up again at the person's next sign-in.
  const resetMfa = useApiMutation((user: ManagedUser) => usersApi.resetMfa(user.id), {
    successMessage: (user) => `Two-step login reset for ${user.name}.`,
    onSuccess: users.reload,
  });
  const confirmResetMfa = (user: ManagedUser) =>
    modals.openConfirmModal({
      title: `Reset two-step login for ${user.name}?`,
      children: <Text size="sm">Use this when they lost their phone. They set up two-step login again, with their new phone, the next time they sign in.</Text>,
      labels: { confirm: "Reset", cancel: "Cancel" },
      confirmProps: { color: "red" },
      onConfirm: () => void resetMfa.mutate(user),
    });

  return (
    <WorkspacePage section={section}
      title={title}
      description="Staff accounts, their role, the branches they work in and their till PIN."
      actions={
        <Button leftSection={<IconPlus size={16} />} onClick={() => setDialog({ kind: "create" })}>
          Add user
        </Button>
      }
    >

      <DataCard>
        <QueryState loading={users.loading} error={users.error} isEmpty={!users.data?.items.length} onRetry={users.reload}>
          <Table verticalSpacing="sm" highlightOnHover>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Name</Table.Th>
                <Table.Th>Role</Table.Th>
                <Table.Th>Branches</Table.Th>
                <Table.Th>Till PIN</Table.Th>
                <Table.Th>Two-step login</Table.Th>
                <Table.Th>Last sign-in</Table.Th>
                <Table.Th>Status</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {users.data?.items.map((user) => (
                <Table.Tr key={user.id} opacity={user.isActive ? 1 : 0.55}>
                  <Table.Td>
                    <Text size="sm" fw={600}>
                      {user.name}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {[user.email, user.phone].filter(Boolean).join(" · ")}
                    </Text>
                  </Table.Td>
                  <Table.Td>{user.role ?? "—"}</Table.Td>
                  <Table.Td>
                    <Text size="sm">{user.branchIds.map((id) => branchName.get(id) ?? `#${id}`).join(", ") || "—"}</Text>
                  </Table.Td>
                  <Table.Td>
                    <StatusBadge active={user.hasPin} activeLabel="Set" inactiveLabel="Not set" />
                  </Table.Td>
                  <Table.Td>
                    <StatusBadge active={user.mfaEnabled} activeLabel="On" inactiveLabel="Off" />
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">{user.lastLoginAt ? dayjs(user.lastLoginAt).format("DD MMM YYYY HH:mm") : "Never"}</Text>
                  </Table.Td>
                  <Table.Td>
                    <StatusBadge active={user.isActive} inactiveLabel="Disabled" />
                  </Table.Td>
                  <Table.Td>
                    <Menu position="bottom-end" withinPortal>
                      <Menu.Target>
                        <ActionIcon variant="subtle" color="gray" aria-label={`Actions for ${user.name}`}>
                          <IconDots size={16} />
                        </ActionIcon>
                      </Menu.Target>
                      <Menu.Dropdown>
                        <Menu.Item leftSection={<IconEdit size={14} />} onClick={() => setDialog({ kind: "edit", user })}>
                          Edit
                        </Menu.Item>
                        <Menu.Item leftSection={<IconKey size={14} />} onClick={() => setDialog({ kind: "pin", user })}>
                          {user.hasPin ? "Change till PIN" : "Set till PIN"}
                        </Menu.Item>
                        {user.mfaEnabled && (
                          <Menu.Item leftSection={<IconShieldOff size={14} />} color="red" onClick={() => confirmResetMfa(user)}>
                            Reset two-step login
                          </Menu.Item>
                        )}
                      </Menu.Dropdown>
                    </Menu>
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      {dialog?.kind === "create" && (
        <UserFormModal roles={users.data?.roles ?? []} branches={branches.data ?? []} onClose={() => setDialog(null)} onSaved={done} />
      )}
      {dialog?.kind === "edit" && (
        <UserFormModal user={dialog.user} roles={users.data?.roles ?? []} branches={branches.data ?? []} onClose={() => setDialog(null)} onSaved={done} />
      )}
      {dialog?.kind === "pin" && <PinModal user={dialog.user} onClose={() => setDialog(null)} onSaved={done} />}
    </WorkspacePage>
  );
}
