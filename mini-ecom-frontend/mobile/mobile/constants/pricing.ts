/**
 * Pricing rules mirrored from the server.
 *
 * The server recomputes every total when the order is created
 * (artifacts/api-server/src/routes/v1/orders.ts) and is the source of truth.
 * These values exist only so the UI can preview the same number.
 *
 * They were previously declared twice under two different names
 * (`VALID_PROMO` in the cart screen, `PROMO_DISCOUNTS` in checkout), and the
 * two screens disagreed on whether the delivery fee applies to an empty cart.
 */
export const PROMO_CODES: Record<string, number> = {
  FRESH10: 0.1,
  SAVE5: 0.05,
};

export const DELIVERY_FEE = 2.99;

export function getDiscountRate(promoCode: string | null | undefined): number {
  if (!promoCode) return 0;
  return PROMO_CODES[promoCode.trim().toUpperCase()] ?? 0;
}

export function isValidPromoCode(promoCode: string): boolean {
  return getDiscountRate(promoCode) > 0;
}

/**
 * Mirrors the server: subtotal - discount + delivery fee.
 * An empty cart has no delivery fee — nothing is being delivered.
 */
export function calcOrderTotals(subtotal: number, promoCode: string | null | undefined, itemCount: number) {
  const discount = subtotal * getDiscountRate(promoCode);
  const deliveryFee = itemCount > 0 ? DELIVERY_FEE : 0;
  return { subtotal, discount, deliveryFee, total: subtotal - discount + deliveryFee };
}
