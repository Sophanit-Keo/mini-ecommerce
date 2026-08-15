import {
  useMutation,
  useQuery,
  type UseMutationOptions,
  type UseQueryOptions,
} from '@tanstack/react-query';

export type ApiIdentifier = string;
type ApiPathParameter = string | number;

export interface User {
  id: ApiIdentifier;
  email: string;
  fullName: string;
  /** Compatibility alias used by the existing mobile UI. */
  name: string;
  phone: string | null;
  role: string;
}

export interface Category {
  id: ApiIdentifier;
  name: string;
  slug?: string;
  image: string;
  productCount: number;
}

export interface Product {
  id: ApiIdentifier;
  name: string;
  slug?: string;
  brand?: string | null;
  categoryId?: ApiIdentifier | null;
  category: Category;
  description: string;
  price: number;
  originalPrice?: number | null;
  unit: string;
  image: string;
  images?: Array<{ id?: ApiIdentifier; url: string; altText?: string | null }>;
  inStock: boolean;
  availableQuantity?: number;
  rating: number;
  reviewCount: number;
  isFlashSale: boolean;
}

export interface CartItem {
  id: ApiIdentifier;
  productId: ApiIdentifier;
  product: Product;
  quantity: number;
  unitPriceSnapshot?: number;
  lineTotal?: number;
  substitutionPreference?: string;
  note?: string | null;
}

export interface Address {
  id: ApiIdentifier;
  label: string;
  recipientName?: string;
  phone?: string | null;
  line1: string;
  line2?: string | null;
  city: string;
  region?: string | null;
  postalCode?: string | null;
  countryCode?: string;
  latitude?: string | null;
  longitude?: string | null;
  deliveryNotes?: string | null;
  isDefault: boolean;
  createdAt?: string | null;
  /** Compatibility aliases used by the existing mobile form. */
  street: string;
  state: string;
  zipCode: string;
}

export interface DeliverySlot {
  id: ApiIdentifier;
  startsAt: string;
  endsAt: string;
  timezone: string;
  fee: number;
  capacity: number;
  bookedCount: number;
  remainingCapacity: number;
}

export interface OrderItem {
  id?: ApiIdentifier;
  productId: ApiIdentifier;
  name: string;
  image: string;
  unit: string;
  price: number;
  quantity: number;
  lineTotal: number;
}

export interface Order {
  id: ApiIdentifier;
  orderNumber: string;
  /** Compatibility alias used by existing UI. */
  invoiceNumber: string;
  status: string;
  paymentStatus?: string;
  paymentMethod: string;
  currency: string;
  address: { street: string; city: string; state?: string; zipCode?: string };
  customer: { name: string; email: string };
  issuedAt: string;
  deliveryFee: number;
  discount: number;
  subtotal: number;
  total: number;
  createdAt: string;
  placedAt: string;
  items: OrderItem[];
  timeline: Array<{ id: number; status: string; description: string; timestamp: string; occurredAt?: string; note?: string | null }>;
}

export interface Notification {
  id: ApiIdentifier;
  type: string;
  data: Record<string, unknown>;
  title: string;
  body: string;
  readAt: string | null;
  isRead: boolean;
  createdAt: string;
}

export type DeviceTokenInputPlatform = 'ios' | 'android';

export interface ProductPage {
  data: Product[];
  page?: { currentPage: number; lastPage: number; total: number };
}

interface ApiProblem {
  type?: string;
  title?: string;
  detail?: string;
  status?: number;
  errors?: Record<string, string[]>;
}

export class ApiError extends Error {
  readonly status: number;
  readonly problem?: ApiProblem;

  constructor(status: number, problem?: ApiProblem) {
    super(problem?.detail || problem?.title || `HTTP ${status} request failed`);
    this.name = 'ApiError';
    this.status = status;
    this.problem = problem;
  }
}

let apiBaseUrl = '';
let authTokenGetter: (() => string | null) | null = null;

export function setBaseUrl(value: string): void {
  const normalized = value.trim().replace(/\/+$/, '');
  if (!/^https?:\/\//i.test(normalized)) {
    throw new Error('The API base URL must begin with http:// or https://.');
  }
  apiBaseUrl = normalized.replace(/\/v1$/i, '');
}

export function setAuthTokenGetter(getter: () => string | null): void {
  authTokenGetter = getter;
}

function resolveApiUrl(path: string, params?: Record<string, unknown>): string {
  if (!apiBaseUrl) {
    throw new Error('API is not configured. Set EXPO_PUBLIC_API_BASE_URL before starting the mobile app.');
  }
  const url = new URL(`/v1${path}`, `${apiBaseUrl}/`);
  for (const [key, value] of Object.entries(params ?? {})) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
  }
  return url.toString();
}

async function request<T>(
  path: string,
  options: {
    method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
    data?: unknown;
    params?: Record<string, unknown>;
    headers?: Record<string, string>;
    signal?: AbortSignal;
  } = {},
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', ...options.headers };
  const token = authTokenGetter?.();
  if (token) headers.Authorization = `Bearer ${token}`;

  const hasBody = options.data !== undefined;
  if (hasBody) headers['Content-Type'] = 'application/json';

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 20_000);
  const signal = options.signal ?? controller.signal;

  try {
    const response = await fetch(resolveApiUrl(path, options.params), {
      method: options.method ?? 'GET',
      headers,
      body: hasBody ? JSON.stringify(options.data) : undefined,
      signal,
    });
    if (response.status === 204) return undefined as T;

    const contentType = response.headers.get('content-type') ?? '';
    const payload: unknown = contentType.includes('application/json')
      ? await response.json()
      : await response.text();

    if (!response.ok) {
      throw new ApiError(response.status, typeof payload === 'object' && payload !== null ? payload as ApiProblem : undefined);
    }
    return unwrap<T>(payload);
  } catch (error) {
    if (error instanceof ApiError) throw error;
    if (error instanceof Error && error.name === 'AbortError') {
      throw new Error('The API request timed out. Check your connection and try again.');
    }
    throw error instanceof Error ? error : new Error('The API request failed.');
  } finally {
    clearTimeout(timeout);
  }
}

function unwrap<T>(payload: unknown): T {
  if (typeof payload === 'object' && payload !== null && 'data' in payload) {
    return (payload as { data: T }).data;
  }
  return payload as T;
}

function numberValue(value: unknown, fallback = 0): number {
  const parsed = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function mapCategory(raw: any): Category {
  return {
    id: String(raw.id),
    name: raw.name ?? '',
    slug: raw.slug,
    image: raw.imageUrl ?? raw.image ?? '',
    productCount: numberValue(raw.productCount),
  };
}

function mapProduct(raw: any): Product {
  return {
    id: String(raw.id),
    name: raw.name ?? '',
    slug: raw.slug,
    brand: raw.brand ?? null,
    categoryId: raw.categoryId ?? raw.category?.id ?? null,
    category: raw.category ? mapCategory(raw.category) : { id: String(raw.categoryId ?? ''), name: '', image: '', productCount: 0 },
    description: raw.description ?? '',
    price: numberValue(raw.effectivePrice ?? raw.price),
    originalPrice: raw.compareAtPrice == null ? null : numberValue(raw.compareAtPrice),
    unit: raw.unitLabel ?? raw.unit ?? '',
    image: raw.primaryImageUrl ?? raw.image ?? '',
    images: Array.isArray(raw.images) ? raw.images.map((image: any) => ({ id: image.id, url: image.url, altText: image.altText ?? null })) : [],
    inStock: Boolean(raw.inStock),
    availableQuantity: raw.availableQuantity == null ? undefined : numberValue(raw.availableQuantity),
    // Ratings and flash-sale flags are not supplied by the backend yet. Keep stable defaults
    // so the UI does not invent a sale or a review score.
    rating: numberValue(raw.rating),
    reviewCount: numberValue(raw.reviewCount),
    isFlashSale: Boolean(raw.isFlashSale),
  };
}

function mapAddress(raw: any): Address {
  const line1 = raw.line1 ?? raw.street ?? '';
  const region = raw.region ?? raw.state ?? '';
  const postalCode = raw.postalCode ?? raw.zipCode ?? '';
  return {
    ...raw,
    id: String(raw.id),
    label: raw.label ?? 'Address',
    line1,
    city: raw.city ?? '',
    region,
    postalCode,
    isDefault: Boolean(raw.isDefault),
    street: line1,
    state: region,
    zipCode: postalCode,
  };
}

function mapOrderItem(raw: any): OrderItem {
  return {
    id: raw.id,
    productId: String(raw.productId ?? raw.product?.id ?? ''),
    name: raw.name ?? raw.product?.name ?? '',
    image: raw.image ?? raw.product?.primaryImageUrl ?? raw.product?.image ?? '',
    unit: raw.unitLabel ?? raw.product?.unitLabel ?? raw.product?.unit ?? '',
    price: numberValue(raw.unitPrice ?? raw.unitPriceSnapshot ?? raw.price),
    quantity: numberValue(raw.quantity),
    lineTotal: numberValue(raw.lineTotal),
  };
}

function mapOrder(raw: any): Order {
  const snapshot = raw.deliveryAddressSnapshot ?? {};
  return {
    id: String(raw.id),
    orderNumber: raw.orderNumber ?? String(raw.id),
    invoiceNumber: raw.orderNumber ?? String(raw.id),
    status: raw.status ?? 'pending_payment',
    paymentStatus: raw.paymentStatus,
    paymentMethod: raw.paymentMethod ?? 'cash_on_delivery',
    currency: raw.currency ?? 'USD',
    address: {
      street: snapshot.line1 ?? '',
      city: snapshot.city ?? '',
      state: snapshot.region ?? '',
      zipCode: snapshot.postalCode ?? '',
    },
    customer: {
      name: snapshot.recipientName ?? 'Customer',
      email: snapshot.email ?? '',
    },
    issuedAt: raw.placedAt ?? raw.createdAt ?? new Date().toISOString(),
    deliveryFee: numberValue(raw.deliveryFee),
    discount: numberValue(raw.discountTotal ?? raw.discount),
    subtotal: numberValue(raw.subtotalFinal ?? raw.subtotalEstimated ?? raw.subtotal),
    total: numberValue(raw.totalFinal ?? raw.totalEstimated ?? raw.total),
    createdAt: raw.placedAt ?? raw.createdAt ?? new Date().toISOString(),
    placedAt: raw.placedAt ?? raw.createdAt ?? new Date().toISOString(),
    items: Array.isArray(raw.items) ? raw.items.map(mapOrderItem) : [],
    timeline: Array.isArray(raw.statusHistory)
      ? raw.statusHistory.map((entry: any, index: number) => ({
          id: index,
          status: entry.status,
          description: entry.description ?? entry.note ?? entry.status,
          timestamp: entry.createdAt ?? entry.occurredAt ?? raw.placedAt ?? new Date().toISOString(),
          occurredAt: entry.createdAt ?? entry.occurredAt,
          note: entry.note ?? null,
        }))
      : [],
  };
}

function mapNotification(raw: any): Notification {
  const data = raw.data && typeof raw.data === 'object' ? raw.data : {};
  return {
    id: String(raw.id),
    type: raw.type ?? 'system',
    data,
    title: String((data as any).title ?? raw.title ?? 'Grocerly update'),
    body: String((data as any).body ?? raw.body ?? ''),
    readAt: raw.readAt ?? null,
    isRead: Boolean(raw.readAt),
    createdAt: raw.createdAt ?? new Date().toISOString(),
  };
}

type QueryOptions<T, TError = Error> = Omit<UseQueryOptions<T, TError, T, readonly unknown[]>, 'queryKey' | 'queryFn'>;
type MutationOptions<TData, TVariables> = Omit<UseMutationOptions<TData, Error, TVariables>, 'mutationFn'>;

function hookOptions<T, TError = Error>(input: any): QueryOptions<T, TError> {
  const value = input?.query ?? {};
  const recognized = ['enabled', 'staleTime', 'gcTime', 'refetchInterval', 'refetchOnMount', 'refetchOnWindowFocus', 'retry'];
  return Object.fromEntries(Object.entries(value).filter(([key]) => recognized.includes(key))) as QueryOptions<T, TError>;
}

function requestParams(input: any): Record<string, unknown> {
  const direct = { ...(input ?? {}) };
  delete direct.query;
  delete direct.request;
  const nested = input?.request?.params ?? {};
  const query = input?.query ?? {};
  const parameterNames = ['q', 'categoryId', 'sort', 'limit', 'cursor', 'page', 'perPage', 'unreadOnly'];
  for (const key of parameterNames) {
    if (query[key] !== undefined) direct[key] = query[key];
  }
  return { ...direct, ...nested };
}

export function getListCartQueryKey() { return ['cart'] as const; }
export function getListOrdersQueryKey() { return ['orders'] as const; }
export function getListNotificationsQueryKey() { return ['notifications'] as const; }
export function getGetOrderQueryKey(id: ApiPathParameter) { return ['orders', id] as const; }

function mapUser(raw: any): User {
  return {
    id: String(raw.id),
    email: raw.email ?? '',
    fullName: raw.fullName ?? raw.name ?? '',
    name: raw.fullName ?? raw.name ?? '',
    phone: raw.phone ?? null,
    role: raw.role ?? 'customer',
  };
}

export async function authLogin(data: { email: string; password: string }) {
  const result = await request<any>('/auth/login', { method: 'POST', data });
  return { ...result, user: mapUser(result.user) } as { accessToken: string; refreshToken: string; expiresIn: number; user: User };
}

export async function authRegister(data: { name: string; email: string; password: string }) {
  const payload = { fullName: data.name, email: data.email, password: data.password, passwordConfirmation: data.password };
  const result = await request<any>('/auth/register', { method: 'POST', data: payload });
  return { ...result, user: mapUser(result.user) } as { accessToken: string; refreshToken: string; expiresIn: number; user: User };
}

export function useListProducts(input: any = {}) {
  const params = requestParams(input);
  const key = ['products', params] as const;
  return useQuery<ProductPage, Error>({
    queryKey: key,
    queryFn: async () => {
      const payload = await request<any>('/products', { params });
      const page = payload?.page;
      const rows = Array.isArray(payload) ? payload : payload?.data ?? [];
      return { data: rows.map(mapProduct), page } as ProductPage;
    },
    ...hookOptions(input),
  });
}

export function useGetProduct(id: ApiPathParameter, input: any = {}) {
  return useQuery<Product, Error>({
    queryKey: ['products', id] as const,
    queryFn: async () => mapProduct(await request<any>(`/products/${encodeURIComponent(String(id))}`)),
    enabled: Boolean(id) && hookOptions(input).enabled !== false,
    ...hookOptions(input),
  });
}

export function useGetProductSubstitutes(id: ApiPathParameter, input: any = {}) {
  return useQuery<Product[], Error>({
    queryKey: ['products', id, 'substitutes'] as const,
    queryFn: async () => {
      const payload = await request<any>(`/products/${encodeURIComponent(String(id))}/substitutes`, { params: requestParams(input) });
      const rows = Array.isArray(payload) ? payload : payload?.data ?? [];
      return rows.map(mapProduct) as Product[];
    },
    enabled: Boolean(id) && hookOptions(input).enabled !== false,
    ...hookOptions(input),
  });
}

export function useListCategories(input: any = {}) {
  return useQuery<Category[], Error>({
    queryKey: ['categories'] as const,
    queryFn: async () => {
      const payload = await request<any>('/categories');
      const rows = Array.isArray(payload) ? payload : payload?.data ?? [];
      return rows.map(mapCategory) as Category[];
    },
    ...hookOptions(input),
  });
}

export function useGetCategory(id: ApiPathParameter, input: any = {}) {
  return useQuery<Category, Error>({
    queryKey: ['categories', id] as const,
    queryFn: async () => mapCategory(await request<any>(`/categories/${encodeURIComponent(String(id))}`)),
    enabled: Boolean(id) && hookOptions(input).enabled !== false,
    ...hookOptions(input),
  });
}

export function useListCart(input: any = {}) {
  return useQuery<CartItem[], Error>({
    queryKey: getListCartQueryKey(),
    queryFn: async () => {
      const cart = await request<any>('/cart');
      return (cart.items ?? []).map((item: any) => ({
        ...item,
        id: String(item.id),
        productId: String(item.productId),
        product: mapProduct(item.product),
        quantity: numberValue(item.quantity),
        unitPriceSnapshot: numberValue(item.unitPriceSnapshot),
        lineTotal: numberValue(item.lineTotal),
      })) as CartItem[];
    },
    ...hookOptions(input),
  });
}

export function useAddCartItem(input: { mutation?: MutationOptions<CartItem, any> } = {}) {
  return useMutation({
    mutationFn: async ({ data }: any) => {
      const item = await request<any>('/cart/items', { method: 'POST', data });
      return { ...item, product: mapProduct(item.product), quantity: numberValue(item.quantity) } as CartItem;
    },
    ...input.mutation,
  });
}

export function useUpdateCartItem(input: { mutation?: MutationOptions<CartItem, any> } = {}) {
  return useMutation({
    mutationFn: async ({ id, data }: any) => {
      const item = await request<any>(`/cart/items/${encodeURIComponent(String(id))}`, { method: 'PATCH', data });
      return { ...item, product: mapProduct(item.product), quantity: numberValue(item.quantity) } as CartItem;
    },
    ...input.mutation,
  });
}

export function useRemoveCartItem(input: { mutation?: MutationOptions<void, any> } = {}) {
  return useMutation({
    mutationFn: ({ id }: any) => request<void>(`/cart/items/${encodeURIComponent(String(id))}`, { method: 'DELETE' }),
    ...input.mutation,
  });
}

export function useClearCart(input: { mutation?: MutationOptions<void, void> } = {}) {
  return useMutation({
    mutationFn: async () => {
      const cart = await request<any>('/cart');
      await Promise.all((cart.items ?? []).map((item: any) => request<void>(`/cart/items/${encodeURIComponent(String(item.id))}`, { method: 'DELETE' })));
    },
    ...input.mutation,
  });
}

export function useListAddresses(input: any = {}) {
  return useQuery<Address[], Error>({
    queryKey: ['addresses'] as const,
    queryFn: async () => {
      const payload = await request<any>('/addresses');
      const rows = Array.isArray(payload) ? payload : payload?.data ?? [];
      return rows.map(mapAddress) as Address[];
    },
    ...hookOptions(input),
  });
}

export function useCreateAddress(input: { mutation?: MutationOptions<Address, any> } = {}) {
  return useMutation({
    mutationFn: async ({ data }: any) => {
      const payload = {
        label: data.label,
        recipientName: data.recipientName ?? data.label,
        phone: data.phone ?? '',
        line1: data.line1 ?? data.street,
        line2: data.line2 ?? null,
        city: data.city,
        region: data.region ?? data.state ?? null,
        postalCode: data.postalCode ?? data.zipCode ?? null,
        countryCode: data.countryCode ?? 'KH',
        latitude: data.latitude ?? null,
        longitude: data.longitude ?? null,
        deliveryNotes: data.deliveryNotes ?? null,
        isDefault: Boolean(data.isDefault),
      };
      return mapAddress(await request<any>('/addresses', { method: 'POST', data: payload }));
    },
    ...input.mutation,
  });
}

export function useListDeliverySlots(input: any = {}) {
  return useQuery<DeliverySlot[], Error>({
    queryKey: ['delivery-slots'] as const,
    queryFn: async () => {
      const payload = await request<any>('/delivery-slots');
      const rows = Array.isArray(payload) ? payload : payload?.data ?? [];
      return rows.map((slot: any) => ({ ...slot, id: String(slot.id), fee: numberValue(slot.fee), capacity: numberValue(slot.capacity), bookedCount: numberValue(slot.bookedCount), remainingCapacity: numberValue(slot.remainingCapacity) })) as DeliverySlot[];
    },
    ...hookOptions(input),
  });
}

export async function createCheckoutQuote(data: { addressId: ApiIdentifier; deliverySlotId: ApiIdentifier; paymentMethod: string }) {
  return request<any>('/checkout/quote', { method: 'POST', data });
}

export function useCreateOrder(input: { mutation?: MutationOptions<Order, any>; request?: { headers?: Record<string, string> } } = {}) {
  return useMutation({
    mutationFn: async ({ data }: any) => mapOrder(await request<any>('/orders', {
      method: 'POST',
      data,
      headers: input.request?.headers,
    })),
    ...input.mutation,
  });
}

export function useListOrders(input: any = {}) {
  return useQuery<Order[], Error>({
    queryKey: getListOrdersQueryKey(),
    queryFn: async () => {
      const payload = await request<any>('/orders', { params: requestParams(input) });
      const rows = Array.isArray(payload) ? payload : payload?.data ?? [];
      return rows.map(mapOrder) as Order[];
    },
    ...hookOptions(input),
  });
}

export function useGetOrder(id: ApiPathParameter, input: any = {}) {
  return useQuery<Order, ApiError>({
    queryKey: getGetOrderQueryKey(id),
    queryFn: async () => mapOrder(await request<any>(`/orders/${encodeURIComponent(String(id))}`)),
    enabled: Boolean(id) && hookOptions<Order, ApiError>(input).enabled !== false,
    ...hookOptions<Order, ApiError>(input),
  });
}

export function useGetOrderInvoice(id: ApiPathParameter, input: any = {}) {
  return useGetOrder(id, input);
}

export function useCancelOrder(input: { mutation?: MutationOptions<Order, any> } = {}) {
  return useMutation({
    mutationFn: async ({ id, data }: any) => mapOrder(await request<any>(`/orders/${encodeURIComponent(String(id))}/cancel`, { method: 'POST', data })),
    ...input.mutation,
  });
}

/** The customer-facing backend does not expose completion; this is retained as a safe no-op export for legacy UI. */
export function useCompleteOrder(input: { mutation?: MutationOptions<void, any> } = {}) {
  return useMutation({
    mutationFn: async () => { throw new Error('Orders are completed by delivery operations, not from the customer mobile app.'); },
    ...input.mutation,
  });
}

export function useListNotifications(input: any = {}) {
  return useQuery<Notification[], Error>({
    queryKey: getListNotificationsQueryKey(),
    queryFn: async () => {
      const payload = await request<any>('/notifications', { params: requestParams(input) });
      const rows = Array.isArray(payload) ? payload : payload?.data ?? [];
      return rows.map(mapNotification) as Notification[];
    },
    ...hookOptions(input),
  });
}

export function useMarkNotificationRead(input: { mutation?: MutationOptions<Notification, any> } = {}) {
  return useMutation({
    mutationFn: async ({ id }: any) => mapNotification(await request<any>(`/notifications/${encodeURIComponent(String(id))}/read`, { method: 'POST' })),
    ...input.mutation,
  });
}

export function useRegisterDevice(input: { mutation?: MutationOptions<void, any> } = {}) {
  return useMutation({
    mutationFn: ({ data }: any) => request<void>('/device-tokens', { method: 'POST', data }),
    ...input.mutation,
  });
}

export function useUnregisterDevice(input: { mutation?: MutationOptions<void, any> } = {}) {
  return useMutation({
    mutationFn: ({ data }: any) => request<void>('/device-tokens', { method: 'DELETE', data }),
    ...input.mutation,
  });
}
