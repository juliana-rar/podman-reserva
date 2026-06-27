<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppFooter from '@/components/AppFooter.vue';
import AppNavbar from '@/components/AppNavbar.vue';
import { useI18n } from '@/lib/i18n';
import '../../css/reserva/navbar.css';
import '../../css/reserva/welcome.css';

interface Post {
    id: number;
    title: string;
    slug: string;
    body: string;
    summary: string | null;
    cover_url: string | null;
    image_urls: string[];
    tags: { id: number; name: string; color: string }[];
    created_at: string;
    author: { id: number; name: string } | null;
}

interface Slide {
    id: number;
    url: string;
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
    category: { id: number; name: string; url: string | null; image_urls: string[] } | null;
    options: ServiceOption[];
}

// Element seleccionable: un servei sense opcions, o una opció concreta d'un servei.
interface Bookable {
    key: string;
    service: Service;
    option: ServiceOption | null;
}

// Bloc d'un servei dins d'una categoria: el servei i els seus elements (opcions o ell mateix).
interface ServiceBlock {
    service: Service;
    hasOptions: boolean;
    items: Bookable[];
}

interface Review {
    id: number;
    rating: number;
    review: string | null;
    review_images: string[] | null;
    review_image_urls: string[];
    user: { id: number; name: string } | null;
    service: { id: number; name: string } | null;
    employee: { id: number; name: string } | null;
    slot: { id: number; starts_at: string } | null;
}

interface TeamMember {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    work_urls: string[];
    work_captions: string[];
}

const props = defineProps<{
    posts: Post[];
    slides: Slide[];
    services: Service[];
    reviews: Review[];
    employees: TeamMember[];
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const { t, localeTag } = useI18n();

// Data d'una opinió (dia de la cita).
function reviewDate(iso: string): string {
    return new Date(iso).toLocaleDateString(localeTag(), { day: 'numeric', month: 'long', year: 'numeric' });
}

// Durada en minuts mostrada com a "1 h 30 min" / "45 min".
function formatDuration(total: number): string {
    const h = Math.floor(total / 60);
    const m = total % 60;
    if (h && m) return `${h} ${t('srv.hours')} ${m} ${t('srv.minutes')}`;
    if (h) return `${h} ${t('srv.hours')}`;
    return `${m} ${t('srv.minutes')}`;
}

// Bloc d'un servei: ell mateix (si no té opcions) o un element per opció.
function blockFor(service: Service): ServiceBlock {
    const hasOptions = service.options.length > 0;
    const items: Bookable[] = hasOptions
        ? service.options.map((o) => ({ key: `s${service.id}o${o.id}`, service, option: o }))
        : [{ key: `s${service.id}`, service, option: null }];
    return { service, hasOptions, items };
}

// Camps de presentació d'un element (l'opció mana sobre el servei).
function itemName(item: Bookable): string {
    return item.option ? item.option.name : item.service.name;
}
function itemDescription(item: Bookable): string | null {
    return item.option ? item.option.description : item.service.description;
}
function itemUrl(item: Bookable): string | null {
    return (item.option && item.option.url) || item.service.url;
}
function itemImages(item: Bookable): string[] {
    if (item.option && item.option.image_urls.length) return item.option.image_urls;
    return item.service.image_urls;
}

// Preu i durada d'un element: els de l'opció si en té (>0), si no els del servei.
function itemMeta(item: Bookable): string {
    const price = item.option && Number(item.option.price) > 0 ? item.option.price : item.service.price;
    const duration = item.option && item.option.duration_minutes > 0 ? item.option.duration_minutes : item.service.duration_minutes;
    const parts: string[] = [];
    if (Number(price) > 0) parts.push(`${price} €`);
    if (duration > 0) parts.push(formatDuration(duration));
    return parts.join(' · ');
}

// Enllaç a /reservar amb el servei (i l'opció, si n'hi ha) preseleccionats.
function itemHref(item: Bookable): string {
    return item.option
        ? `/reservar?service=${item.service.id}&option=${item.option.id}`
        : `/reservar?service=${item.service.id}`;
}

// Serveis agrupats per categoria (igual que a /reservar); els sense categoria al final.
const serviceGroups = computed(() => {
    const byCat = new Map<number, { id: number | null; name: string; url: string | null; image_urls: string[]; services: ServiceBlock[] }>();
    const uncategorized: ServiceBlock[] = [];
    for (const s of props.services) {
        const block = blockFor(s);
        if (s.category) {
            const group = byCat.get(s.category.id);
            if (group) {
                group.services.push(block);
            } else {
                byCat.set(s.category.id, {
                    id: s.category.id,
                    name: s.category.name,
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
        groups.push({ id: null, name: t('srv.noCategory'), url: null, image_urls: [], services: uncategorized });
    }
    return groups;
});

// --- Cercador de serveis: desplaça el carrusel fins al servei trobat ---
const serviceSearch = ref('');
const highlightId = ref<number | null>(null);
let highlightTimer: ReturnType<typeof setTimeout> | undefined;

function findAndScroll(): void {
    const query = serviceSearch.value.trim().toLowerCase();
    if (!query) {
        highlightId.value = null;
        return;
    }
    const match = props.services.find(
        (s) => s.name.toLowerCase().includes(query) || s.options.some((o) => o.name.toLowerCase().includes(query)),
    );
    if (!match) {
        return;
    }
    const el = document.getElementById(`home-svc-${match.id}`);
    if (!el) {
        return;
    }
    el.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    highlightId.value = match.id;
    clearTimeout(highlightTimer);
    highlightTimer = setTimeout(() => (highlightId.value = null), 1600);
}

// --- Carrusel cinematogràfic d'imatges (centre gran, laterals petits, bucle infinit) ---
// Amplada de cada diapositiva en % del viewport; com més petita, més «peek» laterals.
const slideItemPct = ref(50);
const slideCount = computed(() => props.slides.length);
const slideUseCarousel = computed(() => slideCount.value > 1);

// Buffer triple per al bucle infinit; recentrem en silenci als extrems.
const slideDisplay = computed(() =>
    slideUseCarousel.value ? [...props.slides, ...props.slides, ...props.slides] : props.slides,
);

// Posició (dins de slideDisplay) de la diapositiva que ocupa el centre de l'escenari.
const slideIndex = ref(props.slides.length);
const slideAnimate = ref(true);
let slideLocked = false;
let slideTimer: ReturnType<typeof setInterval> | undefined;

// Desplaça la pista perquè el centre de la diapositiva activa caigui al centre del viewport.
const slideTrackStyle = computed(() => {
    if (!slideUseCarousel.value) {
        return { transform: 'none', transition: 'none' };
    }
    const offset = 50 - (slideIndex.value + 0.5) * slideItemPct.value;
    return {
        transform: `translateX(${offset}%)`,
        transition: slideAnimate.value ? `transform ${SLIDE_MS}ms ease` : 'none',
    };
});

function slideRecenter(delta: number): void {
    slideAnimate.value = false;
    slideIndex.value += delta;
    requestAnimationFrame(() => requestAnimationFrame(() => (slideAnimate.value = true)));
}

function slideGoTo(target: number): void {
    if (slideLocked || !slideUseCarousel.value) {
        return;
    }
    slideLocked = true;
    slideIndex.value = target;

    window.setTimeout(() => {
        if (slideIndex.value >= 2 * slideCount.value) {
            slideRecenter(-slideCount.value);
        } else if (slideIndex.value < slideCount.value) {
            slideRecenter(slideCount.value);
        }
        slideLocked = false;
    }, SLIDE_MS);
}

const slidePrev = () => slideGoTo(slideIndex.value - 1);
const slideNext = () => slideGoTo(slideIndex.value + 1);

// Clic en una diapositiva: si és lateral, la porta al centre; si ja és la central, obre el visor.
function slideClick(displayPos: number, realIndex: number): void {
    if (slideUseCarousel.value && displayPos !== slideIndex.value) {
        slideGoTo(displayPos);
    } else {
        openSlides(realIndex);
    }
}

function pauseAuto(): void {
    clearInterval(slideTimer);
}

function restartAuto(): void {
    clearInterval(slideTimer);
    if (slideUseCarousel.value) {
        slideTimer = setInterval(slideNext, 4500);
    }
}

// --- Visor d'imatges en gran (una imatge a la vegada, amb fletxes i comptador) ---
const lightbox = ref<string[]>([]);
const lightboxIndex = ref(0);
const lightboxCaptions = ref<string[]>([]);

// Peu de foto de la imatge que s'està veient (buit si no en té).
const lightboxCaption = computed(() => lightboxCaptions.value[lightboxIndex.value] ?? '');

// Obre la galeria amb totes les imatges (com a /reservar). Accepta el cover de
// reserva com a alternativa si encara no s'ha carregat la galeria.
function openImages(urls: string[], fallback?: string | null): void {
    const images = urls.length ? urls : fallback ? [fallback] : [];
    if (images.length) {
        lightbox.value = images;
        lightboxCaptions.value = [];
        lightboxIndex.value = 0;
    }
}

// Obre el visor amb totes les diapositives, començant per la que s'ha clicat.
function openSlides(start: number): void {
    if (!props.slides.length) {
        return;
    }
    lightbox.value = props.slides.map((s) => s.url);
    lightboxCaptions.value = [];
    lightboxIndex.value = start;
}

function closeLightbox(): void {
    lightbox.value = [];
    lightboxCaptions.value = [];
    lightboxIndex.value = 0;
}

function lightboxPrev(): void {
    lightboxIndex.value = (lightboxIndex.value - 1 + lightbox.value.length) % lightbox.value.length;
}

function lightboxNext(): void {
    lightboxIndex.value = (lightboxIndex.value + 1) % lightbox.value.length;
}

function onKeydown(event: KeyboardEvent): void {
    if (!lightbox.value.length) {
        return;
    }
    if (event.key === 'Escape') {
        closeLightbox();
    } else if (event.key === 'ArrowLeft') {
        lightboxPrev();
    } else if (event.key === 'ArrowRight') {
        lightboxNext();
    }
}

// --- Carrusel infinit (3 visibles, responsive) ---
const SLIDE_MS = 450;
const perView = ref(3);

function updatePerView(): void {
    const w = window.innerWidth;
    perView.value = w < 540 ? 1 : w < 820 ? 2 : w < 1100 ? 3 : 4;
    // Diapositiva central més o menys ampla segons l'amplada; deixa veure els laterals.
    slideItemPct.value = w < 640 ? 84 : w < 1024 ? 62 : 50;
}

const count = computed(() => props.posts.length);
const useCarousel = computed(() => count.value > perView.value);

// Buffer triple per al bucle infinit; recentrem en silenci als extrems.
const display = computed(() =>
    useCarousel.value ? [...props.posts, ...props.posts, ...props.posts] : props.posts,
);

const index = ref(props.posts.length);
const animate = ref(true);
let locked = false;

const trackStyle = computed(() => ({
    transform: useCarousel.value ? `translateX(calc(${-index.value} * 100% / ${perView.value}))` : 'none',
    transition: animate.value ? `transform ${SLIDE_MS}ms ease` : 'none',
}));

function recenter(delta: number): void {
    animate.value = false;
    index.value += delta;
    requestAnimationFrame(() => requestAnimationFrame(() => (animate.value = true)));
}

function step(direction: number): void {
    if (locked || !useCarousel.value) {
        return;
    }
    locked = true;
    index.value += direction;

    window.setTimeout(() => {
        if (index.value >= 2 * count.value) {
            recenter(-count.value);
        } else if (index.value < count.value) {
            recenter(count.value);
        }
        locked = false;
    }, SLIDE_MS);
}

const prev = () => step(-1);
const next = () => step(1);

// --- Mini-carrusel d'obres dins de cada targeta d'empleat (autoavanç sincronitzat) ---
// Imatges de la targeta a part de la portada (la primera obra fa d'imatge de l'empleat).
function teamExtra(member: TeamMember): string[] {
    return member.work_urls.slice(1);
}

onMounted(() => {
    updatePerView();
    window.addEventListener('resize', updatePerView);
    window.addEventListener('keydown', onKeydown);
    restartAuto();
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', updatePerView);
    window.removeEventListener('keydown', onKeydown);
    clearInterval(slideTimer);
    clearTimeout(highlightTimer);
});
</script>

<template>
    <Head title="Reserva d'hores">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>

    <div id="rsv-welcome">
        <AppNavbar />

        <section>
            <h1>{{ t('welcome.heroA') }} <span>{{ t('welcome.heroB') }}</span></h1>
            <p>{{ t('welcome.heroText') }}</p>
            <Link :href="user ? '/reservar' : '/login'">
                {{ user ? t('welcome.ctaUser') : t('welcome.ctaGuest') }}
            </Link>

            <div
                v-if="slides.length"
                class="rsv-slides"
                :class="{ 'is-single': !slideUseCarousel }"
                :style="{ '--w': slideItemPct }"
                @mouseenter="pauseAuto"
                @mouseleave="restartAuto"
            >
                <button v-if="slideUseCarousel" type="button" class="rsv-slide-nav" aria-label="Anterior" @click="slidePrev">
                    ‹
                </button>
                <div class="rsv-slide-viewport">
                    <div class="rsv-slide-track" :class="{ 'is-static': !slideUseCarousel }" :style="slideTrackStyle">
                        <div
                            v-for="(s, i) in slideDisplay"
                            :key="i"
                            class="rsv-slide-item"
                            :class="{ 'is-center': slideUseCarousel && i === slideIndex }"
                        >
                            <img :src="s.url" alt="" @click="slideClick(i, i % slideCount)" />
                        </div>
                    </div>
                </div>
                <button v-if="slideUseCarousel" type="button" class="rsv-slide-nav" aria-label="Següent" @click="slideNext">
                    ›
                </button>
            </div>
        </section>

        <section class="rsv-services" aria-labelledby="rsv-services-title">
            <h2 id="rsv-services-title">{{ t('welcome.services') }}</h2>
            <div v-if="serviceGroups.length" class="rsv-svc-search">
                <span class="rsv-svc-search-icon">🔍</span>
                <input
                    v-model="serviceSearch"
                    type="search"
                    :placeholder="t('welcome.searchService')"
                    @input="findAndScroll"
                    @keydown.enter.prevent="findAndScroll"
                />
            </div>
            <div v-if="serviceGroups.length" class="rsv-service-scroller">
                <div class="rsv-service-groups">
                    <div v-for="group in serviceGroups" :key="group.id ?? 'none'" class="rsv-service-group">
                        <span v-if="group.name" class="rsv-service-cat">
                            <img
                                v-if="group.url"
                                :src="group.url"
                                alt=""
                                class="rsv-service-cat-img"
                                :title="t('res.zoomImg')"
                                @click="openImages(group.image_urls, group.url)"
                            />
                            {{ group.name }}
                        </span>
                        <div
                            v-for="block in group.services"
                            :id="`home-svc-${block.service.id}`"
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
                                    @click="openImages(block.service.image_urls, block.service.url)"
                                />
                                {{ block.service.name }}
                            </span>
                            <div class="rsv-service-chips">
                                <Link
                                    v-for="item in block.items"
                                    :key="item.key"
                                    :href="itemHref(item)"
                                    :class="{ 'has-img': itemUrl(item) }"
                                >
                                    <img
                                        v-if="itemUrl(item)"
                                        :src="itemUrl(item) ?? undefined"
                                        alt=""
                                        class="rsv-service-thumb"
                                        :title="t('res.zoomImg')"
                                        @click.prevent.stop="openImages(itemImages(item), itemUrl(item))"
                                    />
                                    <span class="rsv-service-info">
                                        <span class="rsv-service-name">{{ itemName(item) }}</span>
                                        <small v-if="itemMeta(item)" class="rsv-service-meta">{{ itemMeta(item) }}</small>
                                        <small v-if="itemDescription(item)" class="rsv-service-desc">{{ itemDescription(item) }}</small>
                                    </span>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="rsv-empty">{{ t('welcome.servicesEmpty') }}</div>
        </section>

        <section v-if="employees.length" class="rsv-team" aria-labelledby="rsv-team-title">
            <h2 id="rsv-team-title">{{ t('welcome.team') }}</h2>
            <p class="rsv-team-sub">{{ t('welcome.teamText') }}</p>
            <div class="rsv-team-grid">
                <Link
                    v-for="member in employees"
                    :key="member.id"
                    :href="`/equip/${member.slug}`"
                    class="rsv-team-card"
                >
                    <div class="rsv-team-cover">
                        <img :src="member.work_urls[0]" alt="" />
                        <span class="rsv-team-cover-grad"></span>
                        <span v-if="member.work_urls.length > 1" class="rsv-team-count">
                            +{{ member.work_urls.length - 1 }}
                        </span>
                        <span class="rsv-team-name">{{ member.name }}</span>
                    </div>
                    <div class="rsv-team-body">
                        <p v-if="member.description" class="rsv-team-bio">{{ member.description }}</p>
                        <div v-if="teamExtra(member).length" class="rsv-team-gallery">
                            <img v-for="(url, i) in teamExtra(member)" :key="i" :src="url" alt="" />
                        </div>
                    </div>
                </Link>
            </div>
        </section>

        <section class="rsv-news">
            <h2>{{ t('welcome.novetats') }}</h2>
            <div v-if="posts.length" class="rsv-carousel" :style="{ '--per': perView }">
                <button v-if="useCarousel" type="button" class="rsv-arrow" aria-label="Anterior" @click="prev">
                    ‹
                </button>
                <div class="rsv-viewport">
                    <div class="rsv-track" :class="{ 'is-static': !useCarousel }" :style="trackStyle">
                        <div v-for="(post, i) in display" :key="i" class="rsv-slide">
                            <Link :href="`/posts/${post.slug}`" class="rsv-card">
                                <img v-if="post.cover_url" :src="post.cover_url" alt="" class="rsv-cover" />
                                <h3 class="rsv-title">{{ post.title }}</h3>
                                <div v-if="post.tags?.length" class="rsv-tags">
                                    <span
                                        v-for="tag in post.tags"
                                        :key="tag.id"
                                        :style="{ backgroundColor: tag.color + '22', color: tag.color }"
                                    >{{ tag.name }}</span>
                                </div>
                                <p v-if="post.summary" class="rsv-body">{{ post.summary }}</p>
                            </Link>
                        </div>
                    </div>
                </div>
                <button v-if="useCarousel" type="button" class="rsv-arrow" aria-label="Següent" @click="next">
                    ›
                </button>
            </div>
            <div v-else class="rsv-empty">{{ t('welcome.empty') }}</div>
        </section>

        <section v-if="reviews.length" class="rsv-reviews" aria-labelledby="rsv-reviews-title">
            <h2 id="rsv-reviews-title">{{ t('welcome.reviews') }}</h2>
            <div class="rsv-rev-grid">
                <div v-for="review in reviews" :key="review.id" class="rsv-rev-card">
                    <div class="rsv-rev-stars" :aria-label="`${review.rating}/5`">
                        <span v-for="n in 5" :key="n" :class="{ on: n <= review.rating }">★</span>
                    </div>

                    <span class="rsv-rev-author">{{ review.user?.name }}</span>

                    <div class="rsv-rev-fields">
                        <span v-if="review.service">
                            <span class="rsv-rev-lbl">{{ t('rev.byService') }}:</span> {{ review.service.name }}
                        </span>
                        <span v-if="review.employee">
                            <span class="rsv-rev-lbl">{{ t('rev.byEmployee') }}:</span> {{ review.employee.name }}
                        </span>
                        <span v-if="review.slot" class="rsv-rev-date">📅 {{ reviewDate(review.slot.starts_at) }}</span>
                    </div>

                    <p v-if="review.review" class="rsv-rev-text">{{ review.review }}</p>

                    <div v-if="review.review_image_urls.length" class="rsv-rev-imgs">
                        <img
                            v-for="(url, i) in review.review_image_urls"
                            :key="i"
                            :src="url"
                            alt=""
                            :title="t('res.zoomImg')"
                            @click="openImages(review.review_image_urls)"
                        />
                    </div>
                </div>
            </div>
        </section>

        <AppFooter />

        <Teleport to="body">
            <transition name="rsv-lb">
                <div v-if="lightbox.length" class="rsv-lightbox" @click="closeLightbox">
                    <button type="button" class="rsv-lb-close" aria-label="Tancar" @click="closeLightbox">×</button>
                    <button
                        v-if="lightbox.length > 1"
                        type="button"
                        class="rsv-lb-nav rsv-lb-prev"
                        aria-label="Anterior"
                        @click.stop="lightboxPrev"
                    >
                        ‹
                    </button>
                    <figure class="rsv-lb-stage" @click.stop>
                        <img :src="lightbox[lightboxIndex]" alt="" />
                        <figcaption v-if="lightboxCaption" class="rsv-lb-caption">{{ lightboxCaption }}</figcaption>
                    </figure>
                    <button
                        v-if="lightbox.length > 1"
                        type="button"
                        class="rsv-lb-nav rsv-lb-next"
                        aria-label="Següent"
                        @click.stop="lightboxNext"
                    >
                        ›
                    </button>
                    <span v-if="lightbox.length > 1" class="rsv-lb-counter">
                        {{ lightboxIndex + 1 }} / {{ lightbox.length }}
                    </span>
                </div>
            </transition>
        </Teleport>
    </div>
</template>
