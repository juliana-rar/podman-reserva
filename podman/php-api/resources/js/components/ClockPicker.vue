<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import '../../css/reserva/clock.css';

const props = withDefaults(
    defineProps<{
        /** Hora seleccionada en format "HH:MM" (24h). */
        modelValue: string;
        /** Hores permeses (dins de l'horari), en format "HH:MM". */
        highlightTimes?: string[];
        /** Si és cert, les hores fora de `highlightTimes` queden deshabilitades. */
        restrict?: boolean;
    }>(),
    {
        highlightTimes: () => [],
        restrict: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const pad = (n: number) => String(n).padStart(2, '0');

const parsed = computed(() => {
    const match = (props.modelValue || '').match(/^(\d{1,2}):(\d{2})$/);

    if (!match) {
        return { hour: null as number | null, minute: 0 };
    }

    return { hour: Number(match[1]), minute: Number(match[2]) };
});

const selectedHour = computed(() => parsed.value.hour);
const minute = computed(() => parsed.value.minute);

// Cert només quan hi ha una hora triada: així el rellotge surt net per defecte.
const hasSelection = computed(() => selectedHour.value !== null);

// Meridià (AM/PM) per traduir els 12 números del rellotge a hores 0-23.
const meridiem = ref<'AM' | 'PM'>('AM');

watch(
    selectedHour,
    (hour) => {
        if (hour !== null) {
            meridiem.value = hour >= 12 ? 'PM' : 'AM';
        }
    },
    { immediate: true },
);

const availableHours = computed(
    () => new Set(props.highlightTimes.map((time) => Number(time.split(':')[0]))),
);

const numbers = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

// Marques de quart i mitja que es mostren a l'anell interior del rellotge.
const quarters = [
    { min: 0, angle: 0 },
    { min: 15, angle: 90 },
    { min: 30, angle: 180 },
    { min: 45, angle: 270 },
];

function toHour24(displayNumber: number, period: 'AM' | 'PM'): number {
    if (period === 'AM') {
        return displayNumber === 12 ? 0 : displayNumber;
    }

    return displayNumber === 12 ? 12 : displayNumber + 12;
}

function commit(hour24: number, min: number): void {
    emit('update:modelValue', `${pad(hour24)}:${pad(min)}`);
}

function pickNumber(displayNumber: number): void {
    const hour24 = toHour24(displayNumber, meridiem.value);
    commit(hour24, minute.value);
}

function setMeridiem(period: 'AM' | 'PM'): void {
    meridiem.value = period;

    if (selectedHour.value !== null) {
        const displayNumber = ((selectedHour.value + 11) % 12) + 1;
        commit(toHour24(displayNumber, period), minute.value);
    }
}

// Triar quart/mitja només té sentit quan ja hi ha una hora seleccionada.
function setMinute(min: number): void {
    if (selectedHour.value === null) {
        return;
    }

    commit(selectedHour.value, min);
}

function isSelected(displayNumber: number): boolean {
    return selectedHour.value !== null && toHour24(displayNumber, meridiem.value) === selectedHour.value;
}

function isAvailable(displayNumber: number): boolean {
    return availableHours.value.has(toHour24(displayNumber, meridiem.value));
}

function isDisabled(displayNumber: number): boolean {
    return props.restrict && !isAvailable(displayNumber);
}

// Manecilla d'hores: angle segons l'hora i un petit avanç pels minuts.
const hourHandStyle = computed(() => {
    const hour = selectedHour.value ?? 0;
    const angle = (hour % 12) * 30 + minute.value * 0.5;

    return { transform: `rotate(${angle}deg)` };
});

// Manecilla de minuts: 6° per minut.
const minHandStyle = computed(() => ({ transform: `rotate(${minute.value * 6}deg)` }));

const readout = computed(() =>
    selectedHour.value === null ? '--:--' : `${pad(selectedHour.value)}:${pad(minute.value)}`,
);
</script>

<template>
    <div class="rsv-clock">
        <div class="rsv-clock-face">
            <div class="rsv-clock-hours">
                <button
                    v-for="n in numbers"
                    :key="n"
                    type="button"
                    class="rsv-hour"
                    :style="{ '--a': `${n * 30}deg` }"
                    :class="{ 'is-selected': isSelected(n) }"
                    :disabled="isDisabled(n)"
                    @click="pickNumber(n)"
                >
                    {{ n }}
                </button>
            </div>

            <div class="rsv-clock-minutes">
                <button
                    v-for="q in quarters"
                    :key="q.min"
                    type="button"
                    class="rsv-minute"
                    :style="{ '--a': `${q.angle}deg` }"
                    :class="{ 'is-selected': hasSelection && minute === q.min }"
                    :disabled="!hasSelection"
                    @click="setMinute(q.min)"
                >
                    {{ pad(q.min) }}
                </button>
            </div>

            <div v-if="hasSelection" class="rsv-hand rsv-hand-hour" :style="hourHandStyle"></div>
            <div v-if="hasSelection" class="rsv-hand rsv-hand-min" :style="minHandStyle"></div>
            <div class="rsv-pivot"></div>
        </div>

        <div class="rsv-clock-ctrls">
            <div class="rsv-readout">{{ readout }}</div>
            <div class="rsv-meridiem">
                <button type="button" :class="{ 'is-active': meridiem === 'AM' }" @click="setMeridiem('AM')">
                    AM
                </button>
                <button type="button" :class="{ 'is-active': meridiem === 'PM' }" @click="setMeridiem('PM')">
                    PM
                </button>
            </div>
        </div>
    </div>
</template>
