import ProtectedRoute from "@/components/auth/ProtectedRoute";
import ContentLayout from "@/components/layout/ContentLayout";

/**
 * All authenticated pages render through the tab workspace in ContentLayout;
 * route pages under this group only exist so deep links resolve.
 */
export default function ContentGroupLayout() {
  return (
    <ProtectedRoute>
      <ContentLayout />
    </ProtectedRoute>
  );
}
