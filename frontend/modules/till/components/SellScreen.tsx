"use client";

import { ActionIcon, Badge, Button, Group, Loader, Menu, Modal, ScrollArea, Stack, Text, TextInput, UnstyledButton } from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconBarcode, IconDots, IconUser, IconGlassFull, IconLock, IconLogout, IconMinus, IconPlayerPause, IconPlus, IconPrinter, IconReceiptRefund, IconSearch, IconTrash } from "@tabler/icons-react";
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, salesApi } from "@/api";
import { brand } from "@/app/theme";
import Receipt, { ReceiptPrint } from "@/modules/sales/components/Receipt";
import { type CartLine, cartTotals, fromParked, lineKey, lineName, lineTotal, looksLikeBarcode, needsApproval, newClientId, newLine, repriceForCustomer, sameLine, toParked, toPayload } from "@/modules/till/cart";
import CustomerPicker from "@/modules/till/components/CustomerPicker";
import type { TillCustomer } from "@/types/customers";
import { useApprovalPrompt } from "@/modules/till/components/ApprovalModal";
import { EndShiftModal, ShiftSummaryModal } from "@/modules/till/components/EndShift";
import LineEditModal from "@/modules/till/components/LineEditModal";
import { ParkModal, RecallModal } from "@/modules/till/components/ParkedSales";
import ReturnModal from "@/modules/till/components/ReturnModal";
import TenderModal from "@/modules/till/components/TenderModal";
import TillHeader from "@/modules/till/components/TillHeader";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { logout } from "@/store/slices/authSlice";
import type { ParkedSale, Sale, SaleUnit, TenderPayload, TillItem } from "@/types/sales";
import type { Shift, TillContext } from "@/types/till";
import { formatKes } from "@/utils/money";
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
  const { discountLimitPercent, voidApprovalThresholdCents, returnWindowDays } = context.policy;
  const { requestApproval, approvalModal } = useApprovalPrompt();

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
  const [parked, setParked] = useState<ParkedSale[]>([]);
  const [parking, setParking] = useState(false);
  const [recalling, setRecalling] = useState(false);
  const searchRef = useRef<HTMLInputElement>(null);

  const totals = cartTotals(lines);
  const refocus = () => window.setTimeout(() => searchRef.current?.focus(), 0);

  // Live search as the cashier types (scanner input is handled on Enter instead).
  useEffect(() => {
    const term = query.trim();
    if (term.length < 2 || looksLikeBarcode(term)) {
      setResults([]); // eslint-disable-line react-hooks/set-state-in-effect
      return;
    }
    let active = true;
    setSearching(true);
    const timer = window.setTimeout(() => {
      salesApi
        .searchItems(term)
        .then((items) => active && setResults(items))
        .catch(() => active && setResults([]))
        .finally(() => active && setSearching(false));
    }, 250);
    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [query]);

  const loadParked = useCallback(() => {
    salesApi
      .parked()
      .then(setParked)
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    loadParked();
  }, [loadParked]);

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
      try {
        const found = await salesApi.scan(term);
        addItem(found.item, found.units);
        if (found.packName) notifications.show({ color: "tessera", message: `${found.packName}: ${found.units} bottles added` });
      } catch (e) {
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
    let token: string | null = null;
    if (value > voidApprovalThresholdCents) {
      const approval = await requestApproval("void", `${lineName(line)}, ${formatKes(value)}.`);
      if (!approval) return false;
      token = approval.token;
    }
    try {
      await salesApi.logVoid(line.variantId, line.quantity, value, null, token);
    } catch (e) {
      notifications.show({ color: "red", message: e instanceof Error ? e.message : "Could not remove the item." });
      return false;
    }
    setLines((current) => current.filter((l) => !sameLine(l, line)));
    refocus();
    return true;
  };

  const clearCart = async () => {
    for (const line of lines) {
      if (!(await removeLine(line))) return;
    }
  };

  const saveLine = async (updated: CartLine) => {
    let line = updated;
    const needed = needsApproval(line, discountLimitPercent);
    if (needed && !line.approvalToken) {
      const approval = await requestApproval(needed, `${lineName(line)}: ${formatKes(lineTotal(line))}.`);
      if (!approval) return;
      line = { ...line, approvalToken: approval.token, approvedBy: approval.approver.name };
    }
    setLines((current) => current.map((l) => (sameLine(l, line) ? line : l)));
    setEditing(null);
    refocus();
  };

  const pay = async (tenders: TenderPayload[], customerPin: string | null) => {
    setPayPending(true);
    setPayError(null);
    try {
      const sale = await salesApi.completeSale({ clientId, customerId: customer?.id ?? null, customerPin, lines: lines.map(toPayload), tenders });
      setPaying(false);
      setCompleted(sale);
      setLines([]);
      setCustomer(null);
      setClientId(newClientId());
    } catch (e) {
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
    const restored = recalled.lines.map((l) => fromParked(l, discountLimitPercent));
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

  const lock = async () => {
    await dispatch(logout());
    onEnded();
  };

  return (
    <div className={classes.sell}>
      <section className={classes.sellMain}>
        <TillHeader context={context} />

        <TextInput
          ref={searchRef}
          size="xl"
          radius="md"
          autoFocus
          placeholder="Scan a barcode or type a name / SKU"
          leftSection={looksLikeBarcode(query) ? <IconBarcode size={22} /> : <IconSearch size={22} />}
          rightSection={searching ? <Loader size="sm" color="tessera.4" /> : null}
          value={query}
          onChange={(e) => setQuery(e.currentTarget.value)}
          onKeyDown={(e) => e.key === "Enter" && void onEnter()}
          classNames={{ input: classes.searchInput }}
        />

        <ScrollArea className={classes.results} type="auto">
          {results.length > 0 ? (
            <div className={classes.itemGrid}>
              {results.map((item) => (
                <div key={item.variantId} className={classes.item} data-disabled={item.priceCents == null || undefined}>
                  <UnstyledButton className={classes.itemMain} onClick={() => addItem(item)}>
                    <Text c="white" fw={600} lineClamp={2}>
                      {item.displayName}
                    </Text>
                    <Text c="gray.5" size="xs" ff="monospace">
                      {item.sku}
                    </Text>
                    <Group justify="space-between" mt="auto">
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
            <Button variant="subtle" color={customer ? "amber.4" : "gray"} size="compact-sm" px={0} mt={4} leftSection={<IconUser size={14} />} onClick={() => setPickingCustomer(true)}>
              {customer ? `${customer.name}${customer.isWholesale ? " · wholesale" : ""}` : "Walk-in customer"}
            </Button>
          </div>
          <Menu position="bottom-end">
            <Menu.Target>
              <ActionIcon variant="subtle" color="gray" size="lg" aria-label="Till menu">
                <IconDots size={20} />
              </ActionIcon>
            </Menu.Target>
            <Menu.Dropdown>
              <Menu.Item leftSection={<IconReceiptRefund size={16} />} onClick={() => setReturning(true)}>
                Return / reprint
              </Menu.Item>
              <Menu.Item leftSection={<IconPlayerPause size={16} />} disabled={lines.length === 0} onClick={() => setParking(true)}>
                Park sale
              </Menu.Item>
              <Menu.Item leftSection={<IconPlayerPause size={16} />} disabled={parked.length === 0} onClick={() => setRecalling(true)}>
                Recall parked ({parked.length})
              </Menu.Item>
              <Menu.Item leftSection={<IconTrash size={16} />} disabled={lines.length === 0} onClick={() => void clearCart()}>
                Clear sale
              </Menu.Item>
              <Menu.Divider />
              <Menu.Item leftSection={<IconLock size={16} />} disabled={lines.length > 0} onClick={() => void lock()}>
                Lock till
              </Menu.Item>
              <Menu.Item leftSection={<IconLogout size={16} />} disabled={lines.length > 0} onClick={() => setEnding(true)}>
                End shift
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
              {lines.map((line) => (
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
                Discounts
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
          onClick={() => {
            setPayError(null);
            setPaying(true);
          }}
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

      {paying && <TenderModal totalCents={totals.total} mpesaMode={context.policy.mpesaMode} mpesaDemo={context.policy.mpesaDemo} customer={customer} pending={payPending} serverError={payError} onClose={() => setPaying(false)} onPay={(t, pin) => void pay(t, pin)} />}

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
          requestApproval={requestApproval}
          onClose={() => {
            setReturning(false);
            refocus();
          }}
          onReprint={(sale) => setPrinting({ sale, copy: true })}
          onReturned={(sale, refund) => {
            setReturning(false);
            notifications.show({ color: "green", title: `Return on ${sale.number} recorded`, message: `Give the customer ${formatKes(refund)} from the drawer.`, autoClose: false });
            refocus();
          }}
        />
      )}

      {parking && <ParkModal totalCents={totals.total} onClose={() => setParking(false)} onPark={park} />}
      {recalling && <RecallModal parked={parked} cartHasItems={lines.length > 0} onClose={() => setRecalling(false)} onRecall={recall} />}
      {ending && !closed && <EndShiftModal shift={shift} onClose={() => setEnding(false)} onClosed={setClosed} />}
      {closed && <ShiftSummaryModal shift={closed} onDone={() => void lock()} />}
      {pickingCustomer && <CustomerPicker current={customer} onClose={() => setPickingCustomer(false)} onPick={chooseCustomer} />}
      {approvalModal}
      {printing && <ReceiptPrint sale={printing.sale} copy={printing.copy} />}
    </div>
  );
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
