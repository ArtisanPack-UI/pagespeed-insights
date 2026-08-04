/**
 * The Vue components, and the shared fetch layer they are built on.
 *
 * All five mirror a Livewire component of the same name — and a React
 * component of the same name — and read the same JSON endpoints, so a Vue
 * dashboard, a React dashboard, and a Livewire dashboard describing the same
 * stored run do not disagree about what it says.
 *
 * The components import the library from `@artisanpack-ui/vue` rather than
 * from its `/layout`, `/display`, and `/form` subpaths, which is where the
 * React half imports its own. The published Vue package declares those
 * subpaths but ships no declaration file for any of them, so importing through
 * one gives up the component props' types — and losing them is a worse trade
 * than importing a barrel a bundler will tree-shake anyway.
 */

export { default as ScoreCard, SCORE_CARD_POLL_MS } from './ScoreCard.vue'
export type { ScoreCardProps } from './ScoreCard.vue'

export { default as CoreWebVitalsCard } from './CoreWebVitalsCard.vue'
export type { CoreWebVitalsCardProps } from './CoreWebVitalsCard.vue'

export { default as OpportunitiesTable } from './OpportunitiesTable.vue'
export type { OpportunitiesTableProps } from './OpportunitiesTable.vue'

export { default as TrendChart } from './TrendChart.vue'
export type { TrendChartProps } from './TrendChart.vue'

export { default as UrlManager } from './UrlManager.vue'
export type { UrlManagerProps } from './UrlManager.vue'

export { default as TitledAlert } from './TitledAlert.vue'
export type { TitledAlertProps } from './TitledAlert.vue'

export { usePsiResource } from './use-psi-resource'
export type { PsiResource } from './use-psi-resource'

export { usePsiResultStored } from './use-psi-result-stored'

export * from '../shared'
