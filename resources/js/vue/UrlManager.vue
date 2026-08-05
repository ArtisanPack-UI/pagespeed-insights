<script lang="ts">
/**
 * Vue monitored URL manager.
 *
 * The Vue half of `\ArtisanPackUI\PageSpeedInsights\Livewire\UrlManager`.
 *
 * Rows contributed through `ap.pageSpeed.registerUrls` are listed because they
 * cost quota on every cycle exactly like a stored row does, but they are not
 * editable here: they are owned by whichever package registered them, and the
 * next request would rebuild them from the filter whatever this screen did.
 */

/**
 * Props for `UrlManager`.
 */
export interface UrlManagerProps {
    /** Path the package's routes are mounted under. @defaultValue '/pagespeed' */
    endpointBase?: string
    /** Fetch instance override, useful for SSR and for tests. */
    fetchImpl?: typeof fetch
    /** CSRF token for the writing endpoints. Read from the document when omitted. */
    csrfToken?: string | null
}
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Badge, Button, Card, Input, Loading, Table, type TableColumn } from '@artisanpack-ui/vue'
import { PageSpeedInsightsError } from '../shared/client'
import { formatTimestamp } from '../shared/labels'
import {
    URLS_MAX,
    createPsiUrl,
    deletePsiUrl,
    fetchPsiUrls,
    type PsiMonitoredUrl,
    type PsiMonitoredUrlsData,
} from '../shared/urls'
import { usePsiResource } from './use-psi-resource'
import TitledAlert from './TitledAlert.vue'

/**
 * What the manager is currently telling the operator.
 */
interface StatusMessage {
    color: 'info' | 'success' | 'warning' | 'error'
    title: string
    message: string
}

const COLUMNS: TableColumn[] = [
    { key: 'url', label: 'URL' },
    { key: 'strategies', label: 'Tested on' },
    { key: 'frequency', label: 'Tested' },
    { key: 'actions', label: 'Actions', align: 'right' },
]

const props = withDefaults( defineProps<UrlManagerProps>(), {
    endpointBase: undefined,
    fetchImpl: undefined,
    csrfToken: undefined,
} )

const { data, error, loading, reload } = usePsiResource<PsiMonitoredUrlsData>(
    () => [ props.endpointBase, props.fetchImpl ],
    ( signal ) =>
        fetchPsiUrls( {
            endpointBase: props.endpointBase,
            fetchImpl: props.fetchImpl,
            signal,
        } ),
)

const newUrl = ref<string>( '' )
const newLabel = ref<string>( '' )
const status = ref<StatusMessage | null>( null )
const pendingRemovalId = ref<number | null>( null )
const busy = ref<boolean>( false )

const rows = computed( () => data.value?.urls ?? [] )
const total = computed( () => data.value?.total ?? 0 )

async function add(): Promise<void> {
    busy.value = true
    status.value = null

    try {
        const label = newLabel.value.trim()
        const stored = await createPsiUrl(
            newUrl.value,
            label ? { label } : {},
            {
                endpointBase: props.endpointBase,
                fetchImpl: props.fetchImpl,
                csrfToken: props.csrfToken,
            },
        )

        newUrl.value = ''
        newLabel.value = ''
        status.value = {
            color: 'success',
            title: 'URL added',
            message: `${ stored.url } is now monitored.`,
        }
        reload()
    } catch ( thrown: unknown ) {
        status.value = {
            color: 'error',
            title: 'That URL could not be added',
            message: thrown instanceof PageSpeedInsightsError ? thrown.message : String( thrown ),
        }
    } finally {
        busy.value = false
    }
}

async function remove( id: number ): Promise<void> {
    busy.value = true
    status.value = null

    try {
        await deletePsiUrl( id, {
            endpointBase: props.endpointBase,
            fetchImpl: props.fetchImpl,
            csrfToken: props.csrfToken,
        } )

        pendingRemovalId.value = null
        status.value = {
            color: 'success',
            title: 'URL removed',
            message: 'It is no longer monitored. Its stored results were kept.',
        }
        reload()
    } catch ( thrown: unknown ) {
        status.value = {
            color: 'error',
            title: 'That URL could not be removed',
            message: thrown instanceof PageSpeedInsightsError ? thrown.message : String( thrown ),
        }
    } finally {
        busy.value = false
    }
}

/**
 * One row, back in the shape the endpoint sent it.
 *
 * The library's `Table` types its rows as `Record<string, unknown>`, which
 * loses what a monitored URL is on the way through the cell slot.
 */
function monitored( row: Record<string, unknown> ): PsiMonitoredUrl {
    return row as PsiMonitoredUrl
}

/**
 * When a row was last tested, or the fact that it never has been.
 */
function lastTested( row: Record<string, unknown> ): string {
    const value = monitored( row ).lastTestedAt

    return value ? `Last tested ${ formatTimestamp( value ) }` : 'Never tested'
}
</script>

<template>
    <div class="ap-psi-urls">
        <Card
            title="Monitored URLs"
            :subtitle="1 === total ? '1 URL monitored' : `${ total } URLs monitored`"
        >
            <TitledAlert
                v-if="status"
                :color="status.color"
                :title="status.title"
                :description="status.message"
                class-name="mb-4"
            />

            <form class="ap-psi-urls__add flex flex-wrap items-start gap-2 mb-6" @submit.prevent="add">
                <Input
                    v-model="newUrl"
                    label="URL"
                    placeholder="https://example.com/pricing"
                    class="grow"
                />

                <Input v-model="newLabel" label="Label" placeholder="Optional" />

                <Button type="submit" color="primary" :disabled="busy" class="self-end">
                    Add URL
                </Button>
            </form>

            <TitledAlert
                v-if="error"
                color="error"
                title="The monitored set could not be loaded"
                :description="error.message"
            />

            <p v-else-if="loading && null === data" class="ap-psi-urls__loading flex items-center gap-2">
                <Loading size="sm" />
                <span>Loading monitored URLs…</span>
            </p>

            <p v-else-if="0 === rows.length" class="ap-psi-urls__empty">
                No URLs are monitored yet. Add one above, or import the ones your sitemap lists.
            </p>

            <template v-else>
                <Table :columns="COLUMNS" :rows="rows" class-name="ap-psi-urls__table">
                    <template #cell-url="{ row }">
                        <div class="ap-psi-url">
                            <span v-if="monitored( row ).label" class="ap-psi-url__label font-medium">
                                {{ monitored( row ).label }}
                            </span>

                            <p class="ap-psi-url__address text-xs opacity-70 break-all">
                                {{ monitored( row ).url }}
                            </p>

                            <div class="ap-psi-url__badges flex flex-wrap items-center gap-1 mt-1">
                                <Badge :value="monitored( row ).source" ghost size="sm" />

                                <Badge
                                    v-if="! monitored( row ).isActive"
                                    value="Paused"
                                    color="warning"
                                    size="sm"
                                />
                            </div>
                        </div>
                    </template>

                    <template #cell-strategies="{ row }">
                        <span class="ap-psi-url__strategies text-xs">
                            {{ monitored( row ).strategies.join( ', ' ) }}
                        </span>
                    </template>

                    <template #cell-frequency="{ row }">
                        <div class="ap-psi-url__frequency">
                            <span class="text-sm">{{ monitored( row ).frequency }}</span>

                            <p class="ap-psi-url__last-tested text-xs opacity-70 mt-1">
                                {{ lastTested( row ) }}
                            </p>
                        </div>
                    </template>

                    <template #cell-actions="{ row }">
                        <div
                            class="ap-psi-url__actions flex flex-wrap items-center justify-end gap-2"
                        >
                            <span
                                v-if="! monitored( row ).editable || null === monitored( row ).id"
                                class="text-xs opacity-70"
                            >
                                Registered by another package
                            </span>

                            <!--
                                Two-step because there is no undo for the
                                operator's intent, even though the history
                                survives: a URL that vanishes from the list
                                mid-click is a URL somebody has to remember the
                                address of.
                            -->
                            <template v-else-if="pendingRemovalId === monitored( row ).id">
                                <span class="text-xs">Stop monitoring this URL?</span>

                                <Button
                                    color="error"
                                    size="sm"
                                    :disabled="busy"
                                    @click="remove( monitored( row ).id as number )"
                                >
                                    Remove
                                </Button>

                                <Button color="ghost" size="sm" @click="pendingRemovalId = null">
                                    Cancel
                                </Button>
                            </template>

                            <Button
                                v-else
                                color="ghost"
                                size="sm"
                                class="text-error"
                                @click="pendingRemovalId = monitored( row ).id"
                            >
                                Remove
                            </Button>
                        </div>
                    </template>
                </Table>

                <!--
                    Said out loud. A list that quietly shows a prefix of the
                    monitored set is a list an operator will trust to be all of
                    it.
                -->
                <TitledAlert
                    v-if="data?.truncated"
                    color="info"
                    :title="`Showing the first ${ URLS_MAX } URLs`"
                    :description="`This installation monitors ${ total } URLs, and this table lists the first ${ URLS_MAX }. Use the console commands to work with the rest.`"
                    class-name="mt-4"
                />
            </template>
        </Card>
    </div>
</template>
