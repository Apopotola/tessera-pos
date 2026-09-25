/** Props every workspace view receives from DynamicPage. */
export interface WorkspaceViewProps {
  viewType: string;
  title: string;
  tabId: string;
  /** Extra props passed through openTab({ props }). */
  props?: Record<string, unknown>;
}
