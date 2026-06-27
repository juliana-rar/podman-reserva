<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import Calendar from '@/components/Calendar.vue';
import ClockPicker from '@/components/ClockPicker.vue';
import { useI18n } from '@/lib/i18n';
import '../../css/reserva/booking.css';

interface Slot {
    id: number;
    starts_at: string;
    notes: string | null;
}

// Reserva ja existent: inici "YYYY-MM-DD HH:MM", empleat i durada en minuts.
interface ReservedSlot {
    start: string;
    employee_id: number | null;
    minutes: number;
}

interface BusinessHour {
    weekday: number; // 0 = dilluns … 6 = diumenge
    closed: boolean;
    opens: string | null;
    closes: string | null;
}

interface ServiceOption {
    id: number;
    name: string;
    price: string;
    duration_minutes: number;
    description: string | null;
    url: string | null;
    image_urls: string[];
}

interface Service {
    id: number;
    name: string;
    price: string;
    duration_minutes: number;
    description: string | null;
    url: string | null;
    image_urls: string[];
    category: { id: number; name: string; description: string | null; url: string | null; image_urls: string[] } | null;
    options: ServiceOption[];
}

interface Employee {
    id: number;
    name: string;
    url: string | null;
    service_ids: number[];
    option_ids: number[];
}

interface Reservation {
    id: number;
    note: string | null;
    slot: Slot;
    service: Service | null;
}

interface Product {
    id: number;
    name: string;
    price: string;
    quantity: number;
    url: string | null;
}

interface StockCategory {
    id: number;
    name: string;
    products: Product[];
}

const props = defineProps<{
    businessHours: BusinessHour[];
    reservedTimes: ReservedSlot[];
    slotMinutes: number;
    myReservations: Reservation[];
    services: Service[];
    employees: Employee[];
    stockCategories: StockCategory[];
    uncategorizedStock: Product[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reservar', href: '/reservar' }],
    },
});

const { t, localeTag } = useI18n();
const pad = (n: number) => String(n).padStart(2, '0');

// Empleat escollit (null = qualsevol). Filtra els serveis als que fa aquell empleat.
const employeeId = ref<number | null>(null);

const visibleServices = computed(() => {
    if (employeeId.value === null) return [];
    const emp = props.employees.find((e) => e.id === employeeId.value);
    if (!emp) return [];
    const ids = new Set(emp.service_ids);
    return props.services.filter((s) => ids.has(s.id));
});

// Element seleccionable: o bé un servei sense opcions, o bé una opció concreta d'un servei.
interface Bookable {
    key: string;
    service: Service;
    option: ServiceOption | null;
}

// Bloc d'un servei dins d'una categoria: el servei i els seus elements seleccionables (opcions o ell mateix).
interface ServiceBlock {
    service: Service;
    hasOptions: boolean;
    items: Bookable[];
}

function blockFor(service: Service): ServiceBlock {
    const hasOptions = service.options.length > 0;
    const items: Bookable[] = hasOptions
        ? service.options.map((o) => ({ key: `s${service.id}o${o.id}`, service, option: o }))
        : [{ key: `s${service.id}`, service, option: null }];
    return { service, hasOptions, items };
}

// Serveis agrupats per categoria; el grup sense categoria va al final (sense títol).
const serviceGroups = computed(() => {
    const byCat = new Map<
        number,
        { id: number | null; name: string; description: string | null; url: string | null; image_urls: string[]; services: ServiceBlock[] }
    >();
    const uncategorized: ServiceBlock[] = [];
    for (const s of visibleServices.value) {
        const block = blockFor(s);
        if (s.category) {
            const group = byCat.get(s.category.id);
            if (group) {
                group.services.push(block);
            } else {
                byCat.set(s.category.id, {
                    id: s.category.id,
                    name: s.category.name,
                    description: s.category.description,
                    url: s.category.url,
                    image_urls: s.category.image_urls,
                    services: [block],
                });
            }
        } else {
            uncategorized.push(block);
        }
    }
    const groups = [...byCat.values()].sort((a, b) => a.name.localeCompare(b.name));
    if (uncategorized.length) {
        groups.push({ id: null, name: '', description: null, url: null, image_urls: [], services: uncategorized });
    }
    return groups;
});

// Camps de presentació d'un bookable (l'opció mana sobre el servei per nom/descripció/imatge).
function itemName(item: Bookable): string {
    return item.option ? item.option.name : item.service.name;
}
function itemDescription(item: Bookable): string | null {
    return item.option ? item.option.description : item.service.description;
}
function itemUrl(item: Bookable): string | null {
    return (item.option && item.option.url) || item.service.url;
}
// Galeria completa d'un bookable: la de l'opció si en té, si no la del servei.
function itemImages(item: Bookable): string[] {
    if (item.option && item.option.image_urls.length) {
        return item.option.image_urls;
    }
    return item.service.image_urls;
}

// Preu i durada d'un bookable: els de l'opció si en té (>0), si no els del servei.
function itemMeta(item: Bookable): string {
    const price = item.option && Number(item.option.price) > 0 ? item.option.price : item.service.price;
    const duration = item.option && item.option.duration_minutes > 0 ? item.option.duration_minutes : item.service.duration_minutes;
    const parts: string[] = [];
    if (Number(price) > 0) parts.push(`${price} €`);
    if (duration > 0) parts.push(formatDuration(duration));
    return parts.join(' · ');
}
function isItemActive(item: Bookable): boolean {
    return serviceId.value === item.service.id && optionId.value === (item.option ? item.option.id : null);
}
function selectItem(item: Bookable): void {
    serviceId.value = item.service.id;
    optionId.value = item.option ? item.option.id : null;
}

// Cercador de serveis: desplaça el carrusel fins al servei trobat i el ressalta.
const serviceSearch = ref('');
const highlightId = ref<number | null>(null);
let highlightTimer: ReturnType<typeof setTimeout> | undefined;

function findAndScroll(): void {
    const query = serviceSearch.value.trim().toLowerCase();
    if (!query) {
        highlightId.value = null;
        return;
    }
    const match = visibleServices.value.find(
        (s) => s.name.toLowerCase().includes(query) || s.options.some((o) => o.name.toLowerCase().includes(query)),
    );
    if (!match) {
        return;
    }
    const el = document.getElementById(`rsv-svc-${match.id}`);
    if (!el) {
        return;
    }
    el.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    highlightId.value = match.id;
    clearTimeout(highlightTimer);
    highlightTimer = setTimeout(() => (highlightId.value = null), 1600);
}

// Mostra una durada en minuts com a "1 h 30 min" / "45 min".
function formatDuration(total: number): string {
    const h = Math.floor(total / 60);
    const m = total % 60;
    if (h && m) return `${h} ${t('srv.hours')} ${m} ${t('srv.minutes')}`;
    if (h) return `${h} ${t('srv.hours')}`;
    return `${m} ${t('srv.minutes')}`;
}

// Línia de preu i durada del servei (omet el que sigui 0).
function serviceMeta(s: Service): string {
    const parts: string[] = [];
    if (Number(s.price) > 0) parts.push(`${s.price} €`);
    if (s.duration_minutes > 0) parts.push(formatDuration(s.duration_minutes));
    return parts.join(' · ');
}

// Visor de galeria ampliada: la vista petita mostra la portada; al clicar es veuen
// totes les imatges alhora en graella (sense fletxes).
const galleryImages = ref<string[]>([]);

function openGallery(urls: string[]): void {
    if (!urls.length) return;
    galleryImages.value = urls;
}

function closeGallery(): void {
    galleryImages.value = [];
}

// Tancar el visor amb la tecla Escape.
function onGalleryKey(event: KeyboardEvent): void {
    if (galleryImages.value.length && event.key === 'Escape') {
        closeGallery();
    }
}

onMounted(() => window.addEventListener('keydown', onGalleryKey));
onUnmounted(() => {
    window.removeEventListener('keydown', onGalleryKey);
    clearTimeout(highlightTimer);
});

function dayKeyOf(iso: string): string {
    const d = new Date(iso);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function fullLabel(iso: string): string {
    return new Date(iso).toLocaleString(localeTag(), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const todayKey = dayKeyOf(new Date().toISOString());

// Dia de la setmana (0 = dilluns … 6 = diumenge) d'una clau YYYY-MM-DD.
function weekdayOf(dayKey: string): number {
    return (new Date(dayKey + 'T00:00:00').getDay() + 6) % 7;
}

// Horari d'atenció configurat per al dia indicat (o null si no n'hi ha).
function hoursOf(dayKey: string): BusinessHour | null {
    return props.businessHours.find((h) => h.weekday === weekdayOf(dayKey)) ?? null;
}

// "HH:MM[:SS]" → minuts des de mitjanit, i a la inversa.
function toMinutes(value: string): number {
    const [h, m] = value.split(':');
    return Number(h) * 60 + Number(m);
}
function fromMinutes(min: number): string {
    return `${pad(Math.floor(min / 60))}:${pad(min % 60)}`;
}

// Dies oberts dins una finestra de 120 dies (per ressaltar-los al calendari).
const availableDayKeys = computed(() => {
    const keys: string[] = [];
    const start = new Date(todayKey + 'T00:00:00');
    for (let i = 0; i < 120; i++) {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const key = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        const h = hoursOf(key);
        if (h && !h.closed && h.opens && h.closes) {
            keys.push(key);
        }
    }
    return keys;
});

const selectedDay = ref('');
const effectiveDay = computed(() => selectedDay.value || availableDayKeys.value[0] || todayKey);

const now = new Date();

// Durada (min) del servei/opció triat; per defecte, un pas de franja.
const selectedDurationMinutes = computed(() => {
    const opt = selectedOption.value;
    if (opt && opt.duration_minutes > 0) {
        return opt.duration_minutes;
    }
    const svc = selectedService.value;
    if (svc && svc.duration_minutes > 0) {
        return svc.duration_minutes;
    }
    return props.slotMinutes;
});

// Trams ja ocupats del dia efectiu per l'empleat triat: [inici, fi) en minuts.
// Cada reserva bloqueja tota la seva durada, no només l'hora d'inici.
const occupiedIntervals = computed<Array<[number, number]>>(() => {
    const intervals: Array<[number, number]> = [];
    for (const reserved of props.reservedTimes) {
        const [day, hm] = reserved.start.split(' ');
        if (day !== effectiveDay.value) {
            continue;
        }
        if (employeeId.value !== null && reserved.employee_id !== employeeId.value) {
            continue;
        }
        const start = toMinutes(hm);
        const minutes = reserved.minutes > 0 ? reserved.minutes : props.slotMinutes;
        intervals.push([start, start + minutes]);
    }
    return intervals;
});

// Un servei de durada `duration` que comença a `start` xoca amb algun tram ocupat?
function overlapsOccupied(start: number, duration: number): boolean {
    const end = start + duration;
    return occupiedIntervals.value.some(([s, e]) => start < e && s < end);
}

// Hores del dia on hi cap tot el servei triat sense solapar-se amb cap reserva.
const dayTimes = computed<string[]>(() => {
    const h = hoursOf(effectiveDay.value);
    if (!h || h.closed || !h.opens || !h.closes) {
        return [];
    }
    const step = props.slotMinutes;
    const open = toMinutes(h.opens);
    const close = toMinutes(h.closes);
    const duration = selectedDurationMinutes.value;
    const times: string[] = [];
    for (let m = open; m + duration <= close; m += step) {
        if (!overlapsOccupied(m, duration)) {
            times.push(fromMinutes(m));
        }
    }
    return times;
});

// Una hora del dia d'avui que ja ha passat (es mostra però no es pot triar).
function isPastSlot(hm: string): boolean {
    return effectiveDay.value === todayKey && toMinutes(hm) <= now.getHours() * 60 + now.getMinutes();
}

// El dia està tancat si té configuració però marcada com a tancada.
const dayClosed = computed(() => {
    const h = hoursOf(effectiveDay.value);
    return !h || h.closed || !h.opens || !h.closes;
});

const time = ref('');
const note = ref('');
const serviceId = ref<number | null>(null);
const optionId = ref<number | null>(null);

// L'hora triada (qualsevol minut) és vàlida si és dins l'horari, lliure i futura.
const isTimeAvailable = computed(() => {
    if (!/^\d{1,2}:\d{2}$/.test(time.value)) {
        return false;
    }
    const h = hoursOf(effectiveDay.value);
    if (!h || h.closed || !h.opens || !h.closes) {
        return false;
    }
    const m = toMinutes(time.value);
    const duration = selectedDurationMinutes.value;
    // Tot el servei ha de cabre dins de l'horari.
    if (m < toMinutes(h.opens) || m + duration > toMinutes(h.closes)) {
        return false;
    }
    if (overlapsOccupied(m, duration)) {
        return false;
    }
    if (effectiveDay.value === todayKey && m <= now.getHours() * 60 + now.getMinutes()) {
        return false;
    }
    return true;
});

const canReserve = computed(
    () => isTimeAvailable.value && employeeId.value !== null && serviceId.value !== null,
);

// Usuari autenticat que fa la reserva (per mostrar-ne el contacte al resum).
const page = usePage();
const authUser = computed(() => page.props.auth.user);

// Resum de la selecció per al modal de confirmació.
const selectedEmployee = computed(() => props.employees.find((e) => e.id === employeeId.value) ?? null);
const selectedService = computed(() => props.services.find((s) => s.id === serviceId.value) ?? null);
const selectedOption = computed(
    () => selectedService.value?.options.find((o) => o.id === optionId.value) ?? null,
);

// Preu del servei escollit: el de l'opció si en té (>0), si no el del servei.
const selectedServicePrice = computed(() => {
    const optionPrice = selectedOption.value ? Number(selectedOption.value.price) : 0;
    if (optionPrice > 0) {
        return optionPrice;
    }
    return selectedService.value ? Number(selectedService.value.price) : 0;
});

// --- Productes d'stock opcionals («vols comprar algun producte per després?») ---
// Quantitat triada per article (id -> unitats). Absència o 0 = no seleccionat.
const productQty = ref<Record<number, number>>({});

// Tots els grups de productes a mostrar: categories + un grup final «sense categoria».
const productGroups = computed(() => {
    const groups = props.stockCategories.map((c) => ({ id: c.id, name: c.name, products: c.products }));
    if (props.uncategorizedStock.length) {
        groups.push({ id: -1, name: t('res.shopNoCategory'), products: props.uncategorizedStock });
    }
    return groups;
});

const hasProducts = computed(() => productGroups.value.some((g) => g.products.length));

function qtyOf(product: Product): number {
    return productQty.value[product.id] ?? 0;
}

function setQty(product: Product, value: number): void {
    const clamped = Math.max(0, Math.min(value, product.quantity));
    if (clamped === 0) {
        delete productQty.value[product.id];
    } else {
        productQty.value[product.id] = clamped;
    }
}

// Articles triats (quantitat > 0), aplanats des de tots els grups.
const selectedProducts = computed(() =>
    productGroups.value
        .flatMap((g) => g.products)
        .filter((p) => qtyOf(p) > 0)
        .map((p) => ({ product: p, quantity: qtyOf(p) })),
);

const productsTotal = computed(() =>
    selectedProducts.value.reduce((sum, item) => sum + Number(item.product.price) * item.quantity, 0),
);

const currencyFmt = computed(() => new Intl.NumberFormat(localeTag(), { style: 'currency', currency: 'EUR' }));
function money(n: number): string {
    return currencyFmt.value.format(n);
}

// Modal de confirmació de la reserva.
const showConfirm = ref(false);

function openConfirm(): void {
    if (!canReserve.value) {
        return;
    }
    showConfirm.value = true;
}

function closeConfirm(): void {
    showConfirm.value = false;
}

watch(effectiveDay, () => {
    time.value = '';
});

// En canviar d'empleat es reinicia la selecció de servei/opció.
watch(employeeId, () => {
    serviceId.value = null;
    optionId.value = null;
});

// Preselecció des de la presentació: /reservar?service=ID(&option=ID) selecciona el
// servei (i l'opció, si s'indica) i, automàticament, el primer empleat que el fa.
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const requested = Number(params.get('service'));
    if (!requested) {
        return;
    }
    const requestedOption = Number(params.get('option')) || null;
    const service = props.services.find((s) => s.id === requested);
    const employee = props.employees.find((e) => e.service_ids.includes(requested));
    if (!service || !employee) {
        return;
    }
    // En triar empleat, el watch reinicia servei/opció; per això els fixem al tick següent.
    employeeId.value = employee.id;
    nextTick(() => {
        serviceId.value = requested;
        if (requestedOption && service.options.some((o) => o.id === requestedOption)) {
            optionId.value = requestedOption;
        }
    });
});

const dayLabel = computed(() =>
    new Date(effectiveDay.value + 'T00:00:00').toLocaleDateString(localeTag(), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }),
);

// A /reservar només es mostren les reserves futures (encara no fetes); les ja fetes
// viuen a la pàgina /reserves, accessible des del menú.
const nowMs = Date.now();
const upcomingReservations = computed(() =>
    props.myReservations.filter((r) => new Date(r.slot.starts_at).getTime() >= nowMs),
);

const message = ref<{ type: 'ok' | 'err'; text: string } | null>(null);
let messageTimer: ReturnType<typeof setTimeout> | undefined;

function showMessage(type: 'ok' | 'err', text: string): void {
    message.value = { type, text };
    clearTimeout(messageTimer);
    messageTimer = setTimeout(() => (message.value = null), 6000);
}

function reserve(): void {
    if (!canReserve.value) {
        return;
    }

    const label = `${dayLabel.value} ${t('res.at')} ${time.value}`;

    router.post(
        '/reservas',
        {
            starts_at: `${effectiveDay.value} ${time.value}`,
            service_id: serviceId.value,
            service_option_id: optionId.value,
            employee_id: employeeId.value,
            note: note.value,
            products: selectedProducts.value.map((item) => ({
                stock_id: item.product.id,
                quantity: item.quantity,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showConfirm.value = false;
                showMessage('ok', `${t('res.confirmed')} ${label}`);
                time.value = '';
                note.value = '';
                productQty.value = {};
            },
            onError: () => {
                showMessage('err', t('res.err'));
            },
        },
    );
}

// Cancel·lació amb motiu obligatori (modal).
const cancelId = ref<number | null>(null);
const cancelReason = ref('');

function openCancel(id: number): void {
    cancelId.value = id;
    cancelReason.value = '';
}

function closeCancel(): void {
    cancelId.value = null;
}

function confirmCancel(): void {
    if (cancelId.value === null || cancelReason.value.trim() === '') {
        return;
    }
    router.delete(`/reservas/${cancelId.value}`, {
        data: { reason: cancelReason.value },
        preserveScroll: true,
        onSuccess: () => {
            cancelId.value = null;
            cancelReason.value = '';
        },
    });
}
</script>

<template>
    <Head title="Reservar" />

    <div id="rsv-booking">
        <header>
            <h1>{{ t('res.title') }}</h1>
            <p>{{ t('res.subtitle') }}</p>
            <transition name="rsv-fade">
                <p v-if="message" class="rsv-confirm" :class="message.type">
                    {{ message.text }}
                </p>
            </transition>

            <div v-if="employees.length" class="rsv-service-pick">
                <span class="rsv-service-label">{{ t('res.employee') }} *</span>
                <div class="rsv-service-chips">
                    <button
                        v-for="e in employees"
                        :key="e.id"
                        type="button"
                        :class="{ 'is-active': employeeId === e.id, 'has-img': e.url }"
                        @click="employeeId = e.id"
                    >
                        <img
                            v-if="e.url"
                            :src="e.url"
                            alt=""
                            class="rsv-emp-img"
                            :title="t('res.zoomImg')"
                            @click.stop="openGallery(e.url ? [e.url] : [])"
                        />
                        <span>{{ e.name }}</span>
                    </button>
                </div>
            </div>

            <div v-if="services.length" class="rsv-service-pick">
                <div class="rsv-service-head">
                    <span class="rsv-service-label">{{ t('res.service') }} *</span>
                    <div v-if="employeeId !== null && serviceGroups.length" class="rsv-service-search">
                        <span class="rsv-service-search-icon">🔍</span>
                        <input
                            v-model="serviceSearch"
                            type="search"
                            :placeholder="t('welcome.searchService')"
                            @input="findAndScroll"
                            @keydown.enter.prevent="findAndScroll"
                        />
                    </div>
                </div>
                <p v-if="employeeId === null" class="rsv-service-hint">{{ t('res.pickEmployeeFirst') }}</p>
                <p v-else-if="!serviceGroups.length" class="rsv-service-hint">{{ t('res.employeeNoServices') }}</p>
                <template v-else>
                    <div class="rsv-service-scroller">
                    <div class="rsv-service-groups">
                        <div v-for="group in serviceGroups" :key="group.id ?? 'none'" class="rsv-service-group">
                        <span v-if="group.name" class="rsv-service-cat">
                            <img
                                v-if="group.url"
                                :src="group.url"
                                alt=""
                                class="rsv-service-cat-img"
                                :title="t('res.zoomImg')"
                                @click="openGallery(group.image_urls)"
                            />
                            {{ group.name }}
                        </span>
                        <small v-if="group.description" class="rsv-service-catdesc">{{ group.description }}</small>
                        <div
                            v-for="block in group.services"
                            :id="`rsv-svc-${block.service.id}`"
                            :key="block.service.id"
                            class="rsv-service-svcblock"
                            :class="{ 'is-found': highlightId === block.service.id }"
                        >
                            <span v-if="block.hasOptions" class="rsv-service-svcname">
                                <img
                                    v-if="block.service.url"
                                    :src="block.service.url"
                                    alt=""
                                    class="rsv-service-svcimg"
                                    :title="t('res.zoomImg')"
                                    @click="openGallery(block.service.image_urls)"
                                />
                                {{ block.service.name }}
                            </span>
                            <div class="rsv-service-chips">
                                <button
                                    v-for="item in block.items"
                                    :key="item.key"
                                    type="button"
                                    :class="{ 'is-active': isItemActive(item), 'has-img': itemUrl(item) }"
                                    @click="selectItem(item)"
                                >
                                    <img
                                        v-if="itemUrl(item)"
                                        :src="itemUrl(item) ?? undefined"
                                        alt=""
                                        class="rsv-service-thumb"
                                        :title="t('res.zoomImg')"
                                        @click.stop="openGallery(itemImages(item))"
                                    />
                                    <span class="rsv-service-info">
                                        <span class="rsv-service-name">{{ itemName(item) }}</span>
                                        <small v-if="itemMeta(item)" class="rsv-service-meta">{{ itemMeta(item) }}</small>
                                        <small v-if="itemDescription(item)" class="rsv-service-desc">{{ itemDescription(item) }}</small>
                                    </span>
                                </button>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
                </template>
            </div>
        </header>

        <section class="rsv-book-layout" :class="{ 'has-shop': hasProducts }">
            <aside v-if="hasProducts" class="rsv-shop">
                <h2 class="rsv-shop-title">{{ t('res.shopTitle') }}</h2>
                <div class="rsv-shop-list">
                    <div v-for="group in productGroups" :key="group.id" class="rsv-shop-group">
                        <h3 class="rsv-shop-cat">{{ group.name }}</h3>
                        <div
                            v-for="product in group.products"
                            :key="product.id"
                            class="rsv-shop-item"
                            :class="{ 'is-on': qtyOf(product) > 0 }"
                        >
                            <img
                                v-if="product.url"
                                :src="product.url"
                                alt=""
                                class="rsv-shop-thumb"
                                :title="t('res.zoomImg')"
                                @click="openGallery(product.url ? [product.url] : [])"
                            />
                            <div class="rsv-shop-info">
                                <span class="rsv-shop-name">{{ product.name }}</span>
                                <span class="rsv-shop-meta">{{ money(Number(product.price)) }} · {{ t('res.shopLeft') }}</span>
                            </div>
                            <div class="rsv-shop-qty">
                                <button type="button" aria-label="−" :disabled="qtyOf(product) === 0" @click="setQty(product, qtyOf(product) - 1)">−</button>
                                <span>{{ qtyOf(product) }}</span>
                                <button type="button" aria-label="+" :disabled="qtyOf(product) >= product.quantity" @click="setQty(product, qtyOf(product) + 1)">+</button>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            <div class="rsv-book-main">
                <div class="rsv-pickers">
                    <div class="rsv-pick-col">
                        <h2>{{ t('res.triaDia') }}</h2>
                        <Calendar
                            v-model="selectedDay"
                            :highlight-dates="availableDayKeys"
                            :min-date="todayKey"
                        />
                        <h3 class="rsv-hours-title">{{ t('res.horesDisp') }}</h3>
                        <div v-if="dayTimes.length" class="rsv-hours-grid">
                            <button
                                v-for="entry in dayTimes"
                                :key="entry"
                                type="button"
                                :disabled="isPastSlot(entry)"
                                :class="{ 'is-active': entry === time, 'is-past': isPastSlot(entry) }"
                                @click="time = entry"
                            >
                                {{ entry }}
                            </button>
                        </div>
                        <div v-else class="rsv-empty">{{ dayClosed ? t('res.closedDay') : t('res.capHora') }}</div>
                    </div>

                    <div class="rsv-pick-col">
                        <h2 class="rsv-clock-title">{{ t('res.triaHora') }}</h2>
                        <ClockPicker v-model="time" />
                        <div class="rsv-time-input">
                            <label for="time-input">{{ t('res.oEscriu') }}</label>
                            <input id="time-input" v-model="time" type="time" step="60" />
                        </div>
                    </div>
                </div>

                <div class="rsv-note">
                    <label for="note">{{ t('res.note') }}</label>
                    <textarea
                        id="note"
                        v-model="note"
                        :placeholder="t('res.notePlaceholder')"
                        maxlength="1000"
                    ></textarea>
                </div>
                <button type="button" class="rsv-book-btn" :disabled="!canReserve" @click="openConfirm">
                    {{ isTimeAvailable ? `${t('res.book')} ${dayLabel} ${t('res.at')} ${time}` : t('res.pickAvailable') }}
                </button>
            </div>
        </section>

        <section>
            <h2>{{ t('res.meves') }}</h2>
            <div v-if="upcomingReservations.length">
                <div v-for="reservation in upcomingReservations" :key="reservation.id">
                    <div class="rsv-res-info">
                        <span>{{ fullLabel(reservation.slot.starts_at) }}</span>
                        <span v-if="reservation.service" class="rsv-res-note">🔖 {{ reservation.service.name }}</span>
                        <span v-if="reservation.note" class="rsv-res-note">💬 {{ reservation.note }}</span>
                    </div>
                    <button type="button" @click="openCancel(reservation.id)">{{ t('res.cancelar') }}</button>
                </div>
            </div>
            <div v-else class="rsv-empty">{{ t('res.capReserva') }}</div>
        </section>

        <Teleport to="body">
            <transition name="rsv-fade">
                <div v-if="galleryImages.length" class="rsv-img-overlay" @click.self="closeGallery">
                    <div class="rsv-img-gallery" :class="{ 'is-single': galleryImages.length === 1 }">
                        <img v-for="(url, i) in galleryImages" :key="i" :src="url" alt="" class="rsv-img-zoom" />
                    </div>
                    <button type="button" class="rsv-img-close" aria-label="×" @click="closeGallery">×</button>
                </div>
            </transition>
        </Teleport>

        <Teleport to="body">
            <transition name="rsv-fade">
                <div v-if="showConfirm" class="rsv-cancel-overlay" @click.self="closeConfirm">
                    <div class="rsv-cancel-modal">
                        <h3>{{ t('res.confirmTitle') }}</h3>
                        <ul class="rsv-summary">
                            <li>
                                <span class="rsv-summary-k">{{ t('res.when') }}</span>
                                <span class="rsv-summary-v">{{ dayLabel }} · {{ time }}</span>
                            </li>
                            <li>
                                <span class="rsv-summary-k">{{ t('res.employee') }}</span>
                                <span class="rsv-summary-v">{{ selectedEmployee?.name }}</span>
                            </li>
                            <li>
                                <span class="rsv-summary-k">{{ t('res.email') }}</span>
                                <span class="rsv-summary-v">{{ authUser.email }}</span>
                            </li>
                            <li>
                                <span class="rsv-summary-k">{{ t('res.phone') }}</span>
                                <span class="rsv-summary-v">{{ authUser.phone || t('res.noPhone') }}</span>
                            </li>
                            <li>
                                <span class="rsv-summary-k">{{ t('res.service') }}</span>
                                <span class="rsv-summary-v">{{ selectedService?.name }}<template v-if="selectedOption"> · {{ selectedOption.name }}</template></span>
                            </li>
                            <li v-if="note.trim()">
                                <span class="rsv-summary-k">{{ t('res.note') }}</span>
                                <span class="rsv-summary-v">{{ note }}</span>
                            </li>
                            <li v-if="selectedProducts.length" class="rsv-summary-products">
                                <span class="rsv-summary-k">{{ t('res.shopSummary') }}</span>
                                <span class="rsv-summary-v">
                                    <span v-for="item in selectedProducts" :key="item.product.id" class="rsv-summary-prod">
                                        {{ item.quantity }}× {{ item.product.name }}
                                    </span>
                                </span>
                            </li>
                        </ul>

                        <div class="rsv-summary-money">
                            <div class="rsv-money-row">
                                <span>{{ t('res.serviceType') }}</span>
                                <span>{{ money(selectedServicePrice) }}</span>
                            </div>
                            <div v-if="selectedProducts.length" class="rsv-money-row">
                                <span>{{ t('res.shopSummary') }}</span>
                                <span>{{ money(productsTotal) }}</span>
                            </div>
                            <div class="rsv-money-row rsv-money-total">
                                <span>{{ t('res.total') }}</span>
                                <span>{{ money(selectedServicePrice + productsTotal) }}</span>
                            </div>
                        </div>

                        <div class="rsv-cancel-actions">
                            <button type="button" class="rsv-cancel-back" @click="closeConfirm">{{ t('res.cancelBack') }}</button>
                            <button type="button" class="rsv-cancel-go rsv-confirm-go" :disabled="!canReserve" @click="reserve">
                                {{ t('res.confirmBook') }}
                            </button>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <Teleport to="body">
            <transition name="rsv-fade">
                <div v-if="cancelId !== null" class="rsv-cancel-overlay" @click.self="closeCancel">
                    <div class="rsv-cancel-modal">
                        <h3>{{ t('res.cancelTitle') }}</h3>
                        <label for="cancel-reason">{{ t('res.cancelReason') }} *</label>
                        <textarea
                            id="cancel-reason"
                            v-model="cancelReason"
                            maxlength="1000"
                            :placeholder="t('res.cancelReasonPh')"
                        ></textarea>
                        <div class="rsv-cancel-actions">
                            <button type="button" class="rsv-cancel-back" @click="closeCancel">{{ t('res.cancelBack') }}</button>
                            <button type="button" class="rsv-cancel-go" :disabled="cancelReason.trim() === ''" @click="confirmCancel">
                                {{ t('res.cancelConfirm') }}
                            </button>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>
    </div>
</template>
