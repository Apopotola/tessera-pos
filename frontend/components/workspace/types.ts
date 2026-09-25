/** Props every workspace view receives from DynamicPage. */
export interface WorkspaceViewProps {
  viewType: string;
  title: string;
  tabId: string;
  /** Top-level menu section this screen belongs to (e.g. "Catalogue"); null for top-level pages. */
  section: string | null;
  /** Extra props passed through openTab({ props }). */
  props?: Record<string, unknown>;
}
