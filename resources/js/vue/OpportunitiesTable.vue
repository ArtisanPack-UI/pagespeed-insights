<script lang="ts">
/**
 * Vue Lighthouse opportunities table.
 *
 * The Vue half of `\ArtisanPackUI\PageSpeedInsights\Livewire\OpportunitiesTable`.
 * An empty list is three different pieces of news — performance was never
 * measured, performance was measured and came back clean, or the run failed
 * outright — and only one of them is good.
 */

import type { PsiStrategy } from '../shared/client'

/**
 * Props for `OpportunitiesTable`.
 */
export interface OpportunitiesTableProps {
    /** The URL to read. Must be one this installation monitors. */
    url: string
    /** The form factor. @defaultValue 'mobile' */
    strategy?: PsiStrategy
    /** Path the package's routes are mounted under. @defaultValue '/pagespeed' */
    endpointBase?: string
    /** Fetch instance override, useful for SSR and for tests. */
    fetchImpl?: typeof fetch
}
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Badge, Card, Loading, Table, type TableColumn } from '@artisanpack-ui/vue'
import { bandColor, formatSavings, formatTimestamp, strategyLabel } from '../shared/labels'
import {
    OPPORTUNITIES_STATE_EMPTY,
    OPPORTUNITIES_STATE_FAILED,
    OPPORTUNITIES_STATE_NONE,
    OPPORTUNITIES_STATE_NOT_MEASURED,
    fetchPsiOpportunities,
    type PsiOpportunitiesData,
    type PsiOpportunity,
} from '../shared/opportunities'
import { usePsiResource } from './use-psi-resource'
import { usePsiResultStored } from './use-psi-result-stored'
import TitledAlert from './TitledAlert.vue'

const COLUMNS: TableColumn[] = [
    { key: 'title', label: 'Opportunity' },
    { key: 'savingsMs', label: 'Estimated saving', align: 'right' },
    { key: 'score', label: 'Audit score', align: 'right' },
]

const props = withDefaults( defineProps<OpportunitiesTableProps>(), {
    strategy: 'mobile',
    endpointBase: undefined,
    fetchImpl: undefined,
} )

const { data, error, loading, reload } = usePsiResource<PsiOpportunitiesData>(
    () => [ props.url, props.strategy, props.endpointBase, props.fetchImpl ],
    ( signal ) =>
        fetchPsiOpportunities( {
            url: props.url,
            strategy: props.strategy,
            endpointBase: props.endpointBase,
            fetchImpl: props.fetchImpl,
            signal,
        } ),
)

// Audits are per form factor, so both have to match — like the Livewire
// table's listener.
usePsiResultStored( ( event ) => {
    if ( event.url === props.url && event.strategy === props.strategy ) {
        reload()
    }
} )

const state = computed( () => data.value?.state ?? OPPORTUNITIES_STATE_EMPTY )
const fetchedAt = computed( () => formatTimestamp( data.value?.result?.fetchedAt ) )
const rows = computed( () => data.value?.opportunities ?? [] )

/**
 * One row, back in the shape the endpoint sent it.
 *
 * The library's `Table` types its rows as `Record<string, unknown>`, which
 * loses what an opportunity is on the way through the cell slot.
 */
function opportunity( row: Record<string, unknown> ): PsiOpportunity {
    return row as PsiOpportunity
}

/**
 * The saving a row is worth, or null when Lighthouse offered no estimate.
 *
 * No estimate is not an estimate of zero.
 */
function savingsOf( row: Record<string, unknown> ): string | null {
    return formatSavings( opportunity( row ).savingsMs )
}
</script>

<template>
    <div class="ap-psi-opportunities">
        <Card title="Opportunities" :subtitle="url">
            <template #menu>
                <Badge :value="strategyLabel( strategy )" />
            </template>

            <TitledAlert
                v-if="error"
                color="error"
                title="These opportunities could not be loaded"
                :description="error.message"
            />

            <p v-else-if="loading && null === data" class="ap-psi-opportunities__loading flex items-center gap-2">
                <Loading size="sm" />
                <span>Loading opportunities…</span>
            </p>

            <p v-else-if="OPPORTUNITIES_STATE_EMPTY === state" class="ap-psi-opportunities__empty">
                No PageSpeed test has run for this URL yet.
            </p>

            <TitledAlert
                v-else-if="OPPORTUNITIES_STATE_FAILED === state"
                color="error"
                title="The last PageSpeed run failed"
                :description="data?.result?.errorMessage"
            />

            <!--
                Nothing was found because nothing was looked for. Rendering this
                as "no opportunities" would report a clean page on the strength
                of a test that never examined it.
            -->
            <TitledAlert
                v-else-if="OPPORTUNITIES_STATE_NOT_MEASURED === state"
                color="warning"
                title="Performance was not measured"
                description="This run did not request the performance category, so Lighthouse collected no opportunity audits. There is nothing to report rather than nothing to fix."
            />

            <TitledAlert
                v-else-if="OPPORTUNITIES_STATE_NONE === state"
                color="success"
                title="No opportunities found"
                description="Lighthouse measured this page and found nothing above its own reporting threshold worth changing."
            />

            <template v-else>
                <Table :columns="COLUMNS" :rows="rows" class-name="ap-psi-opportunities__table">
                    <template #cell-title="{ row }">
                        <div class="ap-psi-opportunity">
                            <span class="ap-psi-opportunity__title font-medium">
                                {{ opportunity( row ).title }}
                            </span>

                            <p
                                v-if="opportunity( row ).description"
                                class="ap-psi-opportunity__description text-xs opacity-70"
                            >
                                {{ opportunity( row ).description }}
                            </p>
                        </div>
                    </template>

                    <template #cell-savingsMs="{ row }">
                        <div class="ap-psi-opportunity__savings text-end">
                            <span
                                v-if="savingsOf( row )"
                                class="ap-psi-opportunity__savings-value font-medium"
                            >
                                {{ savingsOf( row ) }}
                            </span>
                            <span v-else class="ap-psi-opportunity__savings-value opacity-60">—</span>

                            <p
                                v-if="opportunity( row ).displayValue"
                                class="ap-psi-opportunity__display-value text-xs opacity-70"
                            >
                                {{ opportunity( row ).displayValue }}
                            </p>
                        </div>
                    </template>

                    <template #cell-score="{ row }">
                        <div class="ap-psi-opportunity__score text-end">
                            <span v-if="null === opportunity( row ).score" class="opacity-60">
                                Unscored
                            </span>
                            <Badge
                                v-else
                                :value="String( opportunity( row ).score )"
                                :color="bandColor( opportunity( row ).band )"
                            />
                        </div>
                    </template>
                </Table>

                <p class="ap-psi-opportunities__note text-xs opacity-70 mt-4">
                    Savings are Lighthouse&apos;s own estimates of what fixing each item would buy,
                    and are not additive.
                </p>
            </template>

            <p v-if="fetchedAt" class="ap-psi-opportunities__timestamp text-xs opacity-70">
                Last tested {{ fetchedAt }}
            </p>
        </Card>
    </div>
</template>
