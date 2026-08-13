/**
 * Order status presentation and lifecycle predicates.
 *
 * STATUS_COLOR was previously duplicated byte-for-byte between the orders list
 * and the order detail screen.
 *
 * The statuses themselves come from the API contract — see the `status` enum in
 * lib/api-spec/openapi.yaml.
 */
export const STATUS_COLOR: Record<string, string> = {
  pending: '#FBBF24',
  confirmed: '#3B82F6',
  preparing: '#8B5CF6',
  ready: '#06B6D4',
  out_for_delivery: '#F97316',
  delivered: '#22C55E',
  completed: '#16A34A',
  cancelled: '#EF4444',
  rejected: '#DC2626',
};

export const STATUS_LABEL: Record<string, string> = {
  pending: 'Pending',
  confirmed: 'Confirmed',
  preparing: 'Preparing',
  ready: 'Ready for Pickup',
  out_for_delivery: 'Out for Delivery',
  delivered: 'Delivered',
  completed: 'Completed',
  cancelled: 'Cancelled',
  rejected: 'Rejected',
};

/**
 * `delivered` is deliberately NOT terminal — the customer can still confirm
 * receipt from `delivered` (see useCompleteOrder), so polling must continue
 * until the order reaches `completed` (or is halted).
 */
export const TERMINAL_STATUSES = ['completed', 'cancelled', 'rejected'];

/** Statuses where the seller stopped the order dead — nothing further happens. */
export const HALTED_STATUSES = ['cancelled', 'rejected'];

/** Must match the server's rule in artifacts/api-server/src/routes/v1/orders.ts. */
export const CANCELLABLE_STATUSES = ['pending', 'confirmed'];

/** The normal happy-path progression a seller advances an order through. */
export const ORDER_LADDER = [
  'pending',
  'confirmed',
  'preparing',
  'ready',
  'out_for_delivery',
  'delivered',
  'completed',
];

export function isCancellable(status: string): boolean {
  return CANCELLABLE_STATUSES.includes(status);
}

export function isTerminal(status: string): boolean {
  return TERMINAL_STATUSES.includes(status);
}
