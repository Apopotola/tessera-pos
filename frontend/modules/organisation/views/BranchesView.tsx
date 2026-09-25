"use client";

import { Badge, Paper, Stack, Table } from "@mantine/core";
import { useCallback } from "react";
import { organisationApi } from "@/api";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import PageHeader from "@/components/shared/PageHeader";
import QueryState from "@/components/shared/QueryState";
import { useApiQuery } from "@/hooks/useApiQuery";

export default function BranchesView({ title }: WorkspaceViewProps) {
  const fetchBranches = useCallback(() => organisationApi.branches(), []);
  const { data: branches, loading, error, reload } = useApiQuery(fetchBranches);

  return (
    <Stack p="md" gap="md">
      <PageHeader title={title} description="Outlets and warehouses in this business." />
      <Paper withBorder>
        <QueryState loading={loading} error={error} isEmpty={!branches?.length} emptyMessage="No branches yet." onRetry={reload}>
          <Table striped highlightOnHover verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Code</Table.Th>
                <Table.Th>Name</Table.Th>
                <Table.Th>Type</Table.Th>
                <Table.Th>Phone</Table.Th>
                <Table.Th>Status</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {branches?.map((branch) => (
                <Table.Tr key={branch.id}>
                  <Table.Td fw={600}>{branch.code}</Table.Td>
                  <Table.Td>{branch.name}</Table.Td>
                  <Table.Td>{branch.isWarehouse ? "Warehouse" : "Outlet"}</Table.Td>
                  <Table.Td>{branch.phone ?? "—"}</Table.Td>
                  <Table.Td>
                    <Badge color={branch.isActive ? "green" : "gray"} variant="light">
                      {branch.isActive ? "Active" : "Inactive"}
                    </Badge>
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </Paper>
    </Stack>
  );
}
