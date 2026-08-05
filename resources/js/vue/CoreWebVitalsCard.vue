<script lang="ts">
/**
 * Vue Core Web Vitals card.
 *
 * The Vue half of `\ArtisanPackUI\PageSpeedInsights\Livewire\CoreWebVitalsCard`.
 * "Not enough field data" and "showing origin-level data" are two different
 * states with two different messages: showing origin-level numbers as if they
 * were page-level numbers misrepresents the page.
 */

import type { PsiStrategy } from '../shared/client'

/**
 * Props for `CoreWebVitalsCard`.
 */
export interface CoreWebVitalsCardProps {
    /** The URL to read. Must be one this installation monitors. */
    url: string
    /** The form factor. CrUX collects phone and desktop separately. @defaultValue 'mobile' */
    strategy?: PsiStrategy
    /** Path the package's routes are mounted under. @defaultValue '/pagespeed' */
    endpointBase?: string
    /** Fetch instance override, useful for SSR and for tests. */
    fetchImpl?: typeof fetch
}
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Badge, Card, Loading, Stat } from '@artisanpack-ui/vue'
import {
    bandColor,
    bandLabel,
    formatTimestamp,
    formatVital,
    strategyLabel,
    vitalAbbreviation,
    vitalLabel,
} from '../shared/labels'
import {
    VITALS_STATE_EMPTY,
    VITALS_STATE_FAILED,
    VITALS_STATE_NO_FIELD_DATA,
    VITALS_STATE_ORIGIN_LEVEL,
    fetchPsiCoreWebVitals,
    type PsiCoreWebVitalsData,
} from '../shared/core-web-vitals'
import { usePsiResource } from './use-psi-resource'
import { usePsiResultStored } from './use-psi-result-stored'
import TitledAlert from './TitledAlert.vue'

/**
 * The band colour for a vital's number.
 *
 * The library's Vue `Stat` takes no colour prop, so the class is aimed at the
 * value through the root — written out in full, because a class name assembled
 * as `text-${ color }` never appears in the source Tailwind scans.
 */
const STAT_VALUE_CLASSES: Record<string, string> = {
    success: '[&_.stat-value]:text-success',
    warning: '[&_.stat-value]:text-warning',
    error: '[&_.stat-value]:text-error',
}

const props = withDefaults( defineProps<CoreWebVitalsCardProps>(), {
    strategy: 'mobile',
    endpointBase: undefined,
    fetchImpl: undefined,
} )

const { data, error, loading, reload } = usePsiResource<PsiCoreWebVitalsData>(
    () => [ props.url, props.strategy, props.endpointBase, props.fetchImpl ],
    ( signal ) =>
        fetchPsiCoreWebVitals( {
            url: props.url,
            strategy: props.strategy,
            endpointBase: props.endpointBase,
            fetchImpl: props.fetchImpl,
            signal,
        } ),
)

// CrUX field data belongs to the form factor it was requested with, so a
// desktop run says nothing about the mobile card. Matched on both, like the
// Livewire card's listener.
usePsiResultStored( ( event ) => {
    if ( event.url === props.url && event.strategy === props.strategy ) {
        reload()
    }
} )

const state = computed( () => data.value?.state ?? VITALS_STATE_EMPTY )
const originLevel = computed( () => VITALS_STATE_ORIGIN_LEVEL === state.value )

// The page-level set is preferred when there is one. When the only data
// describes the origin, that is what is rendered — labelled as what it is,
// because knowing the site is slow is better than knowing nothing.
const fieldData = computed( () => data.value?.page ?? data.value?.origin ?? null )
const dataSubject = computed( () => fieldData.value?.id ?? null )
const fetchedAt = computed( () => formatTimestamp( data.value?.result?.fetchedAt ) )
const percentile = computed( () => data.value?.percentile ?? 75 )

/**
 * One tile per vital.
 *
 * A metric CrUX had no measurement for shows an em dash rather than a zero.
 */
const vitals = computed( () =>
    ( fieldData.value?.vitals ?? [] ).map( ( vital ) => {
        const available = null !== vital.value
        const color = available ? bandColor( vital.band ) : undefined

        return {
            metric: vital.metric,
            title: vitalAbbreviation( vital.metric ),
            fullName: vitalLabel( vital.metric ),
            value: available ? formatVital( vital.metric, vital.value ) ?? '—' : '—',
            description: available ? bandLabel( vital.band ) ?? undefined : 'No data',
            className: available
                ? color
                    ? STAT_VALUE_CLASSES[ color ]
                    : undefined
                : 'ap-psi-vital--unavailable opacity-60',
        }
    } ),
)
</script>

<template>
    <div class="ap-psi-cwv-card">
        <Card title="Core Web Vitals" :subtitle="url">
            <template #menu>
                <Badge :value="strategyLabel( strategy )" />
            </template>

            <TitledAlert
                v-if="error"
                color="error"
                title="This field data could not be loaded"
                :description="error.message"
            />

            <p v-else-if="loading && null === data" class="ap-psi-cwv-card__loading flex items-center gap-2">
                <Loading size="sm" />
                <span>Loading field data…</span>
            </p>

            <p v-else-if="VITALS_STATE_EMPTY === state" class="ap-psi-cwv-card__empty">
                No PageSpeed test has run for this URL yet.
            </p>

            <TitledAlert
                v-else-if="VITALS_STATE_FAILED === state"
                color="error"
                title="The last PageSpeed run failed"
                :description="data?.result?.errorMessage"
            />

            <TitledAlert
                v-else-if="VITALS_STATE_NO_FIELD_DATA === state"
                color="info"
                title="Not enough field data"
                description="The Chrome UX Report has no real-user measurements for this page, and none for the site as a whole either. This is normal for a page with low traffic — it is not an error, and there is nothing to fix."
            />

            <template v-else>
                <TitledAlert
                    v-if="originLevel"
                    color="warning"
                    title="Showing site-wide data"
                    :description="
                        dataSubject
                            ? `The Chrome UX Report has too little traffic for this page on its own, so these numbers describe ${ dataSubject } as a whole rather than this page.`
                            : 'The Chrome UX Report has too little traffic for this page on its own, so these numbers describe the site as a whole rather than this page.'
                    "
                    class-name="mb-4"
                />

                <div class="ap-psi-cwv-card__vitals grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div
                        v-for="vital in vitals"
                        :key="`psi-vital-${ vital.metric }`"
                        class="ap-psi-vital"
                        :title="vital.fullName"
                    >
                        <Stat
                            :title="vital.title"
                            :value="vital.value"
                            :description="vital.description"
                            :class-name="vital.className"
                        />
                    </div>
                </div>

                <p class="ap-psi-cwv-card__percentile text-xs opacity-70 mt-4">
                    Real-user data from the Chrome UX Report, reported at the {{ percentile }}th
                    percentile.
                </p>

                <p v-if="fetchedAt" class="ap-psi-cwv-card__timestamp text-xs opacity-70">
                    Last tested {{ fetchedAt }}
                </p>
            </template>
        </Card>
    </div>
</template>
