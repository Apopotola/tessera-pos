"use client";

import { ActionIcon, Badge, Button, Group, Loader, Menu, Modal, ScrollArea, Stack, Text, TextInput, UnstyledButton } from "@mantine/core";
import { useIdle } from "@mantine/hooks";
import { modals } from "@mantine/modals";
import { notifications } from "@mantine/notifications";
import { IconBarcode, IconBuildingBank, IconDiscount, IconDots, IconUser, IconGlassFull, IconLock, IconLogout, IconMinus, IconPlayerPause, IconPlus, IconPrinter, IconReceiptRefund, IconSearch, IconTag, IconTrash, IconUsers } from "@tabler/icons-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ApiError, authApi, salesApi } from "@/api";
import { brand } from "@/app/theme";
import Receipt, { ReceiptPrint } from "@/modules/sales/components/Receipt";
import { type ApprovalRules, type CartLine, cartTotals, withPromotions, fromParked, lineKey, lineName, lineTotal, looksLikeBarcode, needsApproval, newClientId, newLine, repriceForCustomer, sameLine, toParked, toPayload } from "@/modules/till/cart";
import CustomerPicker from "@/modules/till/components/CustomerPicker";
import type { TillCustomer } from "@/types/customers";
import { useApprovalPrompt } from "@/modules/till/components/ApprovalModal";
import { CashDropModal, EndShiftModal, ShiftSummaryModal } from "@/modules/till/components/EndShift";
import LineEditModal from "@/modules/till/components/LineEditModal";
import LockScreen from "@/modules/till/components/LockScreen";
import { ParkModal, RecallModal } from "@/modules/till/components/ParkedSales";
import PriceCheckModal from "@/modules/till/components/PriceCheck";
import ReturnModal from "@/modules/till/components/ReturnModal";
import TenderModal from "@/modules/till/components/TenderModal";
import TillHeader from "@/modules/till/components/TillHeader";
import OfflineBanner from "@/modules/till/offline/OfflineBanner";
import { buildOfflineReceipt } from "@/modules/till/offline/offlineReceipt";
import { useOfflineTill } from "@/modules/till/offline/useOfflineTill";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { logout } from "@/store/slices/authSlice";
import type { ParkedSale, Sale, SaleUnit, ScanResult, TenderPayload, TillItem, PromotionRule } from "@/types/sales";
import type { Shift, TillContext } from "@/types/till";
import { formatKes } from "@/utils/money";
import { onTillLocked } from "@/utils/sessionEvents";
import classes from "../Till.module.css";

interface SellScreenProps {
  context: TillContext;
  shift: Shift;
  onEnded: () => void;
}

/**
 * The selling screen: scan or search on the left, the cart and Pay on the right.
 * The till shows prices for speed; the API re-prices every line from the approved price list.
 */
export default function SellScreen({ context, shift, onEnded }: SellScreenProps) {
  const dispatch = useAppDispatch();
  const user = useAppSelector((state) => state.auth.user);
  const canDiscount = user?.permissions.includes("sales.discount.within-limit") ?? false;
  const { policy } = context;
  const { voidApprovalThresholdCents, returnWindowDays } = policy;
  // Settings → Staff: the best discount limit of the cashier's roles, and what needs a manager.
  const discountLimitPercent = Math.max(0, ...(user?.roles ?? []).map((role) => policy.discountLimits[role] ?? 0));
  const rules: ApprovalRules = { limitPercent: discountLimitPercent, priceChangeNeedsApproval: policy.priceChangeNeedsApproval, blockBigDiscounts: policy.discountAboveLimit === "blocked" };
  const { requestApproval, approvalModal } = useApprovalPrompt();
  const offline = useOfflineTill(context.till.id, user?.id);
  const isOnline = offline.online;
  const { markOffline, search: searchLocal, scan: scanLocal } = offline;

  const [query, setQuery] = useState("");
  const [results, setResults] = useState<TillItem[]>([]);
  const [searching, setSearching] = useState(false);
  const [lines, setLines] = useState<CartLine[]>([]);
  // Walk-in by default; a registered customer brings wholesale prices and their KRA PIN.
  const [customer, setCustomer] = useState<TillCustomer | null>(null);
  const [pickingCustomer, setPickingCustomer] = useState(false);
  // One id per cart: retrying a sale after a dropped connection can never charge twice.
  const [clientId, setClientId] = useState(newClientId);
  const [editing, setEditing] = useState<CartLine | null>(null);
  const [paying, setPaying] = useState(false);
  const [payError, setPayError] = useState<string | null>(null);
  const [payPending, setPayPending] = useState(false);
  const [completed, setCompleted] = useState<Sale | null>(null);
  const [printing, setPrinting] = useState<{ sale: Sale; copy: boolean } | null>(null);
  const [returning, setReturning] = useState(false);
  const [ending, setEnding] = useState(false);
  const [closed, setClosed] = useState<Shift | null>(null);
  const [dropping, setDropping] = useState(false);
  const [parked, setParked] = useState<ParkedSale[]>([]);
  const [parking, setParking] = useState(false);
  const [recalling, setRecalling] = useState(false);
  const [checkingPrice, setCheckingPrice] = useState(false);
  const [favourites, setFavourites] = useState<TillItem[]>([]);
  const [locked, setLocked] = useState(false);
  const searchRef = useRef<HTMLInputElement>(null);

  // Screen lock (Settings → Staff → till locks after N idle minutes). The server refuses
  // everything but unlocking while locked; the cart stays on this device.
  const autoLockMs = policy.autoLockMinutes * 60_000;
  const idle = useIdle(autoLockMs > 0 ? autoLockMs : 86_400_000, { initialState: false });
  const lockScreen = useCallback(async (reason: "idle" | "manual") => {
    try {
      await authApi.lockTill(reason);
      setLocked(true);
      // Digits typed for the PIN must not land in the search box underneath.
      if (document.activeElement instanceof HTMLElement) document.activeElement.blur();
    } catch (e) {
      // Offline the till keeps selling unlocked (unlocking needs the server).
      if (e instanceof ApiError && e.status === 423) setLocked(true);
    }
  }, []);
  useEffect(() => {
    // Not while taking payment: the customer may be approving M-PESA on their phone.
    if (idle && autoLockMs > 0 && isOnline && !locked && !paying) void lockScreen("idle"); // eslint-disable-line react-hooks/set-state-in-effect -- the lock is a server call; state changes after it answers
  }, [idle, autoLockMs, isOnline, locked, paying, lockScreen]);
  useEffect(() => onTillLocked(() => setLocked(true)), []);

  // Promotions (approved by the owner): the day's rules, re-checked every minute for happy hours.
  const [promotionRules, setPromotionRules] = useState<PromotionRule[]>([]);
  const [clock, setClock] = useState(() => new Date());
  useEffect(() => {
    const timer = window.setInterval(() => setClock(new Date()), 60_000);
    return () => window.clearInterval(timer);
  }, []);
  const snapshotPromotions = offline.snapshot?.promotions;
  useEffect(() => {
    if (!isOnline) {
      setPromotionRules(snapshotPromotions ?? []); // eslint-disable-line react-hooks/set-state-in-effect -- offline: the saved copy
      return;
    }
    salesApi
      .promotions()
      .then(setPromotionRules)
      .catch(() => setPromotionRules(snapshotPromotions ?? []));
  }, [isOnline, snapshotPromotions]);
  const priced = useMemo(() => withPromotions(lines, promotionRules, clock, context.branch.id), [lines, promotionRules, clock, context.branch.id]);

  const totals = cartTotals(priced);
  const refocus = () => window.setTimeout(() => searchRef.current?.focus(), 0);

  // Live search as the cashier types (scanner input is handled on Enter instead).
  useEffect(() => {
    const term = query.trim();
    if (term.length < 2 || looksLikeBarcode(term)) {
      setResults([]); // eslint-disable-line react-hooks/set-state-in-effect
      return;
    }
    if (!isOnline) {
      setResults(searchLocal(term));
      return;
    }
    let active = true;
    setSearching(true);
    const timer = window.setTimeout(() => {
      salesApi
        .searchItems(term)
        .then((items) => active && setResults(items))
        .catch((e: unknown) => {
          if (!active) return;
          if (e instanceof ApiError && e.status === 0) {
            markOffline();
            setResults(searchLocal(term));
          } else setResults([]);
        })
        .finally(() => active && setSearching(false));
    }, 250);
    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [query, isOnline, searchLocal, markOffline]);

  // First screen (Settings → Sales screen): favourites while nothing is typed.
  const showFavourites = policy.layout !== "barcode" && policy.favouritesMode !== "none";
  const { snapshot } = offline;
  const loadFavourites = useCallback(() => {
    if (!showFavourites) return;
    const fromSnapshot = () => {
      const byId = new Map((snapshot?.items ?? []).map((i) => [i.variantId, i]));
      setFavourites((snapshot?.favouriteIds ?? []).flatMap((id) => byId.get(id) ?? []));
    };
    if (!isOnline) {
      fromSnapshot();
      return;
    }
    salesApi.favourites().then(setFavourites).catch(fromSnapshot);
  }, [showFavourites, isOnline, snapshot]);

  useEffect(() => {
    loadFavourites();
  }, [loadFavourites]);

  const loadParked = useCallback(() => {
    salesApi
      .parked()
      .then(setParked)
      .catch((e: unknown) => e instanceof ApiError && e.status === 0 && markOffline());
  }, [markOffline]);

  useEffect(() => {
    loadParked();
  }, [loadParked]);

  // The eTIMS invoice is sent just after the sale commits; refresh the receipt once to show KRA's details.
  const completedNumber = completed?.etimsStatus === "pending" && !completed.pendingSync ? completed.number : null;
  useEffect(() => {
    if (!completedNumber) return;
    const timer = window.setTimeout(() => {
      salesApi
        .findSale(completedNumber)
        .then((fresh) => setCompleted((current) => (current?.number === fresh.number ? fresh : current)))
        .catch(() => undefined);
    }, 1500);
    return () => window.clearTimeout(timer);
  }, [completedNumber]);

  // Print once the receipt portal is mounted, then unmount it.
  useEffect(() => {
    if (!printing) return;
    const timer = window.setTimeout(() => {
      window.print();
      setPrinting(null);
    }, 50);
    return () => window.clearTimeout(timer);
  }, [printing]);

  const addItem = useCallback((item: TillItem, quantity = 1, unit: SaleUnit = "bottle") => {
    if ((unit === "tot" ? item.totPriceCents : item.priceCents) == null) {
      notifications.show({ color: "yellow", message: `${item.displayName} has no ${unit === "tot" ? "tot" : "retail"} price yet. Ask a manager.` });
      return;
    }
    const key = lineKey({ variantId: item.variantId, unit });
    setLines((current) => {
      const existing = current.find((l) => lineKey(l) === key);
      if (existing) return current.map((l) => (l === existing ? { ...l, quantity: l.quantity + quantity } : l));
      return [...current, newLine(item, quantity, unit, customer?.isWholesale ?? false)];
    });
    setQuery("");
    setResults([]);
    refocus();
  }, [customer]);

  const onEnter = async () => {
    const term = query.trim();
    if (!term) return;
    if (looksLikeBarcode(term)) {
      const addScanned = (found: ScanResult) => {
        addItem(found.item, found.units);
        if (found.packName) notifications.show({ color: "tessera", message: `${found.packName}: ${found.units} bottles added` });
      };
      const offlineScan = () => {
        const found = scanLocal(term);
        if (found) addScanned(found);
        else {
          notifications.show({ color: "red", message: "No item has this barcode." });
          setQuery("");
        }
      };
      if (!isOnline) {
        offlineScan();
        return;
      }
      try {
        addScanned(await salesApi.scan(term));
      } catch (e) {
        if (e instanceof ApiError && e.status === 0) {
          markOffline();
          offlineScan();
          return;
        }
        notifications.show({ color: "red", message: e instanceof Error ? e.message : "Scan failed." });
        setQuery("");
      }
      return;
    }
    if (results.length === 1) addItem(results[0]);
  };

  const changeQty = (line: CartLine, delta: number) => {
    if (line.quantity + delta < 1) {
      void removeLine(line);
      return;
    }
    setLines((current) => current.map((l) => (sameLine(l, line) ? { ...l, quantity: l.quantity + delta } : l)));
  };

  /** Removing a line is logged; above the threshold a manager approves it. */
  const removeLine = async (line: CartLine): Promise<boolean> => {
    const value = lineTotal(line);
    const dropLine = () => {
      setLines((current) => current.filter((l) => !sameLine(l, line)));
      refocus();
    };
    const needsManager = voidApprovalThresholdCents !== null && value > voidApprovalThresholdCents;
    if (!isOnline) {
      if (needsManager) {
        notifications.show({ color: "yellow", message: "Removing this needs a manager's approval, which needs the connection." });
        return false;
      }
      offline.enqueueVoid({ variantId: line.variantId, quantity: line.quantity, valueCents: value });
      dropLine();
      return true;
    }
    let token: string | null = null;
    if (needsManager) {
      const approval = await requestApproval("void", `${lineName(line)}, ${formatKes(value)}.`);
      if (!approval) return false;
      token = approval.token;
    }
    try {
      await salesApi.logVoid(line.variantId, line.quantity, value, null, token);
    } catch (e) {
      if (e instanceof ApiError && e.status === 0 && !token) {
        markOffline();
        offline.enqueueVoid({ variantId: line.variantId, quantity: line.quantity, valueCents: value });
        dropLine();
        return true;
      }
      notifications.show({ color: "red", message: e instanceof Error ? e.message : "Could not remove the item." });
      return false;
    }
    dropLine();
    return true;
  };

  const clearCart = async () => {
    for (const line of lines) {
      if (!(await removeLine(line))) return;
    }
  };

  const saveLine = async (updated: CartLine) => {
    let line = updated;
    const needed = needsApproval(line, rules);
    if (needed === "blocked") {
      notifications.show({ color: "red", message: `Discounts above ${discountLimitPercent}% are not allowed.` });
      return;
    }
    if (needed && !line.approvalToken && !isOnline) {
      notifications.show({ color: "yellow", message: "Price changes and big discounts need a manager's approval, which needs the connection." });
      return;
    }
    if (needed && !line.approvalToken) {
      const approval = await requestApproval(needed, `${lineName(line)}: ${formatKes(lineTotal(line))}.`);
      if (!approval) return;
      line = { ...line, approvalToken: approval.token, approvedBy: approval.approver.name };
    }
    setLines((current) => current.map((l) => (sameLine(l, line) ? line : l)));
    setEditing(null);
    refocus();
  };

  const finishSale = (sale: Sale) => {
    setPaying(false);
    setCompleted(sale);
    // Settings → Receipts → Print behaviour.
    if (policy.receipt.printBehaviour === "always") setPrinting({ sale, copy: false });
    loadFavourites();
    setLines([]);
    setCustomer(null);
    setClientId(newClientId());
  };

  /** Records the sale on this device and prints a provisional receipt; it is sent when the connection is back. */
  const payOffline = async (tenders: TenderPayload[], customerPin: string | null) => {
    if (!user) return;
    if (tenders.some((t) => t.method === "mpesa")) {
      setPayError("The connection dropped. M-PESA needs the connection — take cash or card instead.");
      return;
    }
    const occurredAt = new Date().toISOString();
    const payload = { clientId, occurredAt, customerId: customer?.id ?? null, customerPin, lines: lines.map(toPayload), tenders };
    const localNumber = await offline.enqueueSale(payload, totals.total);
    finishSale(buildOfflineReceipt({ localNumber, occurredAt, context, user, lines: priced, tenders, customer, customerPin, snapshot: offline.snapshot }));
  };

  /** Manager approvals the server asked for, gathered one at a time before sending again. */
  const pay = async (tenders: TenderPayload[], customerPin: string | null, approvals: { stock?: string; credit?: string } = {}) => {
    setPayPending(true);
    setPayError(null);
    try {
      if (!isOnline) {
        await payOffline(tenders, customerPin);
        return;
      }
      finishSale(
        await salesApi.completeSale({
          clientId,
          customerId: customer?.id ?? null,
          customerPin,
          stockApprovalToken: approvals.stock ?? null,
          creditApprovalToken: approvals.credit ?? null,
          lines: lines.map(toPayload),
          tenders,
        }),
      );
    } catch (e) {
      // Settings: selling more than the shelf holds, or on account over the limit, needs a manager; then send it again.
      const needed = approvalNeeded(e, approvals);
      if (needed) {
        setPayPending(false);
        const approval = await requestApproval(needed.action, needed.message);
        if (approval) await pay(tenders, customerPin, { ...approvals, [needed.key]: approval.token });
        else setPayError(needed.message);
        return;
      }
      if (e instanceof ApiError && e.status === 0) {
        // Same clientId: if the server did get it, the later resend is recorded once.
        markOffline();
        await payOffline(tenders, customerPin);
        return;
      }
      setPayError(e instanceof ApiError ? (Object.values(e.formErrors)[0] ?? e.message) : "The sale did not go through. Try again.");
    } finally {
      setPayPending(false);
    }
  };

  /** Put the cart aside; nothing is charged or taken from stock until it is recalled and paid. */
  const park = async (label: string) => {
    const saved = await salesApi.park(label.trim() || null, lines.map(toParked), totals.total);
    setParking(false);
    setLines([]);
    setCustomer(null);
    setClientId(newClientId());
    notifications.show({ color: "tessera", message: `Parked as "${saved.label}".` });
    loadParked();
    refocus();
  };

  const recall = async (sale: ParkedSale) => {
    const recalled = await salesApi.recall(sale.id);
    const restored = recalled.lines.map((l) => fromParked(l, rules));
    setLines(restored.map((r) => r.line));
    setClientId(newClientId());
    setRecalling(false);
    if (restored.some((r) => r.reset)) {
      notifications.show({ color: "yellow", message: "Price changes and big discounts on this sale need a manager again." });
    }
    loadParked();
    refocus();
  };

  const chooseCustomer = (picked: TillCustomer | null) => {
    setCustomer(picked);
    setLines((current) => repriceForCustomer(current, picked?.isWholesale ?? false));
    setPickingCustomer(false);
    refocus();
  };

  /** Settings → Sales screen: confirm the customer's age before taking payment. */
  const startPayment = () => {
    const open = () => {
      setPayError(null);
      setPaying(true);
    };
    if (!policy.ageCheck) {
      open();
      return;
    }
    modals.openConfirmModal({
      title: "Age check",
      centered: true,
      children: <Text size="sm">Is the customer 18 or over? Ask for ID if you are not sure.</Text>,
      labels: { confirm: "Yes, 18 or over", cancel: "No — do not sell" },
      onConfirm: open,
      onCancel: refocus,
    });
  };

  /** Settings → Sales screen → Quick buttons ("customer" is the customer button above). */
  const quick = policy.quickButtons.filter((b) => b !== "customer" && b !== "open_drawer");
  const editLastLine = () => {
    const last = lines.at(-1);
    if (last) setEditing(last);
  };

  const renderItems = (items: TillItem[]) => (
    <div className={policy.layout === "list" ? classes.itemList : classes.itemGrid}>
      {items.map((item) => (
        <div key={item.variantId} className={classes.item} data-disabled={item.priceCents == null || undefined}>
          <UnstyledButton className={classes.itemMain} onClick={() => addItem(item)}>
            <Text c="white" fw={600} lineClamp={2} style={{ flex: policy.layout === "list" ? 1 : undefined }}>
              {item.displayName}
            </Text>
            <Text c="gray.5" size="xs" ff="monospace">
              {item.sku}
            </Text>
            <Group justify="space-between" mt={policy.layout === "list" ? 0 : "auto"} gap="sm" wrap="nowrap">
              <Text c="amber.4" fw={700}>
                {item.priceCents == null ? "No price" : formatKes(item.priceCents)}
              </Text>
              <Badge variant="light" color={item.onFloor > 0 ? "gray" : "red"} size="sm">
                {item.onFloor} on floor
              </Badge>
            </Group>
          </UnstyledButton>
          {item.totMl && item.totPriceCents != null && (
            <UnstyledButton className={classes.totButton} onClick={() => addItem(item, 1, "tot")}>
              <Group justify="space-between" wrap="nowrap" gap={6}>
                <Group gap={6} wrap="nowrap">
                  <IconGlassFull size={16} />
                  <Text size="sm" fw={600}>
                    Tot {item.totMl}ml
                  </Text>
                </Group>
                <Text size="sm" fw={700}>
                  {formatKes(item.totPriceCents)}
                </Text>
              </Group>
              <Text size="xs" c="gray.5">
                {item.openBottleMl == null ? "Opens a new bottle" : `${item.openBottleMl}ml left in open bottle`}
              </Text>
            </UnstyledButton>
          )}
        </div>
      ))}
    </div>
  );

  const lock = async () => {
    await dispatch(logout());
    onEnded();
  };

  return (
    <div className={classes.sell} data-touch={policy.touchMode}>
      <section className={classes.sellMain}>
        <TillHeader context={context} />
        <OfflineBanner offline={offline} />

        <TextInput
          ref={searchRef}
          size="xl"
          radius="md"
          autoFocus
          placeholder={policy.layout === "barcode" ? "Scan a barcode" : "Scan a barcode or type a name / SKU"}
          leftSection={looksLikeBarcode(query) ? <IconBarcode size={22} /> : <IconSearch size={22} />}
          rightSection={searching ? <Loader size="sm" color="tessera.4" /> : null}
          value={query}
          onChange={(e) => setQuery(e.currentTarget.value)}
          onKeyDown={(e) => e.key === "Enter" && void onEnter()}
          classNames={{ input: classes.searchInput }}
        />

        <ScrollArea className={classes.results} type="auto">
          {results.length > 0 ? (
            renderItems(results)
          ) : query.trim().length < 2 && showFavourites && favourites.length > 0 ? (
            <>
              <div className={classes.sectionLabel}>{policy.favouritesMode === "top" ? "Best sellers this week" : "Favourites"}</div>
              {renderItems(favourites)}
            </>
          ) : (
            <Stack align="center" justify="center" h={240} gap={6}>
              <IconBarcode size={48} color="#7c7d95" stroke={1.2} />
              <Text c="gray.5">{query.trim().length >= 2 && !searching ? "No matching items." : "Scan or search to add items."}</Text>
            </Stack>
          )}
        </ScrollArea>
      </section>

      <aside className={classes.cart}>
        <Group justify="space-between">
          <div>
            <Text c="white" fw={700}>
              {user?.name}
            </Text>
            <Text c="gray.5" size="xs">
              Shift since {new Date(shift.openedAt).toLocaleTimeString("en-KE", { hour: "numeric", minute: "2-digit" })}
            </Text>
            <Button variant="light" color={customer ? "amber" : "tessera"} c={customer ? "amber.4" : "gray.3"} size="compact-sm" mt={6} leftSection={<IconUser size={14} />} onClick={() => setPickingCustomer(true)}>
              {customer ? `${customer.name}${customer.isWholesale ? " · wholesale" : ""}` : "Walk-in customer"}
            </Button>
            {quick.length > 0 && (
              <Group gap={6} mt={8}>
                {quick.includes("hold") && (
                  <Button size="compact-sm" variant="default" leftSection={<IconPlayerPause size={14} />} disabled={lines.length === 0 || !isOnline} onClick={() => setParking(true)}>
                    Hold
                  </Button>
                )}
                {quick.includes("discount") && (
                  <Button size="compact-sm" variant="default" leftSection={<IconDiscount size={14} />} disabled={lines.length === 0} onClick={editLastLine}>
                    Discount
                  </Button>
                )}
                {quick.includes("returns") && (
                  <Button size="compact-sm" variant="default" leftSection={<IconReceiptRefund size={14} />} disabled={!isOnline} onClick={() => setReturning(true)}>
                    Returns
                  </Button>
                )}
                {quick.includes("price_check") && (
                  <Button size="compact-sm" variant="default" leftSection={<IconTag size={14} />} disabled={!isOnline} onClick={() => setCheckingPrice(true)}>
                    Price check
                  </Button>
                )}
              </Group>
            )}
          </div>
          <Menu position="bottom-end">
            <Menu.Target>
              <ActionIcon variant="subtle" color="gray" size="lg" aria-label="Till menu">
                <IconDots size={20} />
              </ActionIcon>
            </Menu.Target>
            <Menu.Dropdown>
              <Menu.Item leftSection={<IconReceiptRefund size={16} />} disabled={!isOnline} onClick={() => setReturning(true)}>
                Return / reprint{!isOnline && " (needs connection)"}
              </Menu.Item>
              <Menu.Item leftSection={<IconPlayerPause size={16} />} disabled={lines.length === 0 || !isOnline} onClick={() => setParking(true)}>
                Park sale
              </Menu.Item>
              <Menu.Item leftSection={<IconPlayerPause size={16} />} disabled={parked.length === 0 || !isOnline} onClick={() => setRecalling(true)}>
                Recall parked ({parked.length})
              </Menu.Item>
              <Menu.Item leftSection={<IconTrash size={16} />} disabled={lines.length === 0} onClick={() => void clearCart()}>
                Clear sale
              </Menu.Item>
              <Menu.Divider />
              <Menu.Item leftSection={<IconBuildingBank size={16} />} disabled={!isOnline} onClick={() => setDropping(true)}>
                Cash drop to safe{!isOnline && " (needs connection)"}
              </Menu.Item>
              <Menu.Item leftSection={<IconLock size={16} />} disabled={!isOnline} onClick={() => void lockScreen("manual")}>
                Lock screen (keeps the sale)
              </Menu.Item>
              <Menu.Item leftSection={<IconUsers size={16} />} disabled={lines.length > 0 || !isOnline} onClick={() => void lock()}>
                Switch cashier
              </Menu.Item>
              <Menu.Item leftSection={<IconLogout size={16} />} disabled={lines.length > 0 || !isOnline || offline.unsynced > 0} onClick={() => setEnding(true)}>
                End shift{offline.unsynced > 0 ? " (offline sales still sending)" : !isOnline ? " (needs connection)" : ""}
              </Menu.Item>
            </Menu.Dropdown>
          </Menu>
        </Group>

        <ScrollArea className={classes.cartLines} type="auto">
          {lines.length === 0 ? (
            <Text c="gray.6" ta="center" mt="xl">
              Cart is empty
            </Text>
          ) : (
            <Stack gap={8}>
              {priced.map((line) => (
                <div key={lineKey(line)} className={classes.cartLine}>
                  <UnstyledButton onClick={() => setEditing(line)} style={{ flex: 1, minWidth: 0 }}>
                    <Text c="white" fw={600} size="sm" truncate>
                      {lineName(line)}
                    </Text>
                    <Text c="gray.5" size="xs">
                      {formatKes(line.unitPriceCents)} each
                      {line.discountCents > 0 && ` · −${formatKes(line.discountCents)}`}
                      {line.approvedBy && ` · approved by ${line.approvedBy}`}
                    </Text>
                    {(line.promotionCents ?? 0) > 0 && (
                      <Text c="amber.4" size="xs" truncate>
                        {line.promotionName} · −{formatKes(line.promotionCents)}
                      </Text>
                    )}
                  </UnstyledButton>
                  <Group gap={4} wrap="nowrap">
                    <ActionIcon variant="default" size="md" aria-label="One less" onClick={() => changeQty(line, -1)}>
                      <IconMinus size={14} />
                    </ActionIcon>
                    <Text c="white" fw={700} w={28} ta="center">
                      {line.quantity}
                    </Text>
                    <ActionIcon variant="default" size="md" aria-label="One more" onClick={() => changeQty(line, 1)}>
                      <IconPlus size={14} />
                    </ActionIcon>
                  </Group>
                  <Text c="white" fw={700} w={96} ta="right">
                    {formatKes(lineTotal(line))}
                  </Text>
                </div>
              ))}
            </Stack>
          )}
        </ScrollArea>

        <Stack gap={4} className={classes.cartTotals}>
          <Group justify="space-between">
            <Text c="gray.5" size="sm">
              {totals.items} {totals.items === 1 ? "item" : "items"}
            </Text>
            <Text c="gray.4" size="sm">
              {formatKes(totals.subtotal)}
            </Text>
          </Group>
          {totals.discount > 0 && (
            <Group justify="space-between">
              <Text c="gray.5" size="sm">
                {totals.promotions > 0 ? "Discounts & promotions" : "Discounts"}
              </Text>
              <Text c="gray.4" size="sm">
                −{formatKes(totals.discount)}
              </Text>
            </Group>
          )}
          <Group justify="space-between" align="baseline">
            <Text c="white" fw={600}>
              Total (VAT incl.)
            </Text>
            <Text c="white" className="tessera-display" fz={36}>
              {formatKes(totals.total)}
            </Text>
          </Group>
        </Stack>

        {parked.length > 0 && lines.length === 0 && (
          <Button variant="light" color="tessera" leftSection={<IconPlayerPause size={16} />} onClick={() => setRecalling(true)}>
            {parked.length} parked {parked.length === 1 ? "sale" : "sales"} — recall
          </Button>
        )}
        <Button
          size="xl"
          fullWidth
          color="amber.5"
          c={brand.navy}
          disabled={lines.length === 0}
          onClick={startPayment}
        >
          Pay {lines.length > 0 && formatKes(totals.total)}
        </Button>
      </aside>

      {editing && (
        <LineEditModal
          line={editing}
          canDiscount={canDiscount}
          discountLimitPercent={discountLimitPercent}
          onClose={() => {
            setEditing(null);
            refocus();
          }}
          onSave={(line) => void saveLine(line)}
          onRemove={() => void removeLine(editing).then((removed) => removed && setEditing(null))}
        />
      )}

      {paying && (
        <TenderModal
          totalCents={totals.total}
          mpesaMode={policy.mpesaMode}
          mpesaDemo={policy.mpesaDemo}
          methods={policy.paymentMethods}
          splitAllowed={policy.splitAllowed}
          cashRoundingCents={policy.cashRoundingCents}
          stkPush={policy.stkPush}
          customer={customer}           offline={!isOnline}
          pending={payPending}
          serverError={payError}
          onClose={() => setPaying(false)}
          onPay={(t, pin) => void pay(t, pin)}
        />
      )}

      {completed && (
        <Modal
          opened
          onClose={() => {
            setCompleted(null);
            refocus();
          }}
          title={`Sale ${completed.number} complete`}
          centered
        >
          <Stack>
            <ChangeDue sale={completed} />
            <div className={classes.receiptPreview}>
              <Receipt sale={completed} />
            </div>
            <Group grow>
              <Button variant="default" leftSection={<IconPrinter size={18} />} onClick={() => setPrinting({ sale: completed, copy: false })}>
                Print receipt
              </Button>
              <Button
                data-autofocus
                onClick={() => {
                  setCompleted(null);
                  refocus();
                }}
              >
                Next customer
              </Button>
            </Group>
          </Stack>
        </Modal>
      )}

      {returning && (
        <ReturnModal
          returnWindowDays={returnWindowDays}
          refundNeedsApproval={policy.refundNeedsApproval}
          requestApproval={requestApproval}
          onClose={() => {
            setReturning(false);
            refocus();
          }}
          onReprint={(sale) => setPrinting({ sale, copy: true })}
          onReturned={(sale, refund) => {
            setReturning(false);
            // A sale on account is refunded to the account first; only the rest comes from the drawer.
            const toAccount = sale.returns.at(-1)?.toAccountCents ?? 0;
            const cash = refund - toAccount;
            const message = [cash > 0 ? `Give the customer ${formatKes(cash)} from the drawer.` : null, toAccount > 0 ? `${formatKes(toAccount)} went back to their account.` : null].filter(Boolean).join(" ");
            notifications.show({ color: "green", title: `Return on ${sale.number} recorded`, message, autoClose: false });
            refocus();
          }}
        />
      )}

      {parking && <ParkModal totalCents={totals.total} onClose={() => setParking(false)} onPark={park} />}
      {recalling && <RecallModal parked={parked} cartHasItems={lines.length > 0} onClose={() => setRecalling(false)} onRecall={recall} />}
      {dropping && (
        <CashDropModal
          shift={shift}
          requestApproval={requestApproval}
          onClose={() => setDropping(false)}
          onDropped={() => {
            setDropping(false);
            refocus();
          }}
        />
      )}
      {ending && !closed && <EndShiftModal shift={shift} blind={policy.blindCashUp} onClose={() => setEnding(false)} onClosed={setClosed} />}
      {closed && <ShiftSummaryModal shift={closed} onDone={() => void lock()} />}
      {pickingCustomer && (
        <CustomerPicker current={customer} localCustomers={isOnline ? null : (offline.snapshot?.customers ?? [])} onClose={() => setPickingCustomer(false)} onPick={chooseCustomer} />
      )}
      {checkingPrice && (
        <PriceCheckModal
          onClose={() => {
            setCheckingPrice(false);
            refocus();
          }}
        />
      )}
      {approvalModal}
      {locked && user && (
        <LockScreen
          context={context}
          user={user}
          onUnlocked={() => {
            setLocked(false);
            refocus();
          }}
        />
      )}
      {printing && <ReceiptPrint sale={printing.sale} copy={printing.copy} />}
    </div>
  );
}

/** Which manager approval the server asked for (and has not had yet), if any. */
function approvalNeeded(e: unknown, approvals: { stock?: string; credit?: string }): { key: "stock" | "credit"; action: "below_zero" | "credit"; message: string } | null {
  if (!(e instanceof ApiError)) return null;
  if (e.formErrors.stockApproval && !approvals.stock) return { key: "stock", action: "below_zero", message: e.formErrors.stockApproval };
  if (e.formErrors.creditApproval && !approvals.credit) return { key: "credit", action: "credit", message: e.formErrors.creditApproval };
  return null;
}

function ChangeDue({ sale }: { sale: Sale }) {
  const change = sale.tenders.find((t) => t.method === "cash")?.changeCents ?? 0;
  const unverified = sale.tenders.some((t) => t.status === "unverified");

  return (
    <Stack gap={4} align="center">
      <Text c="dimmed" size="sm">
        {change > 0 ? "Change to give" : "Paid in full"}
      </Text>
      <Text className="tessera-display" fz={44} c={change > 0 ? "green.7" : undefined}>
        {formatKes(change > 0 ? change : sale.totalCents)}
      </Text>
      {unverified && (
        <Text size="xs" c="dimmed" ta="center">
          Check the M-PESA / card confirmation before the customer leaves.
        </Text>
      )}
    </Stack>
  );
}
