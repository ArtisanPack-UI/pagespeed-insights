<script lang="ts">
/**
 * The title-and-description alert every card in this package uses.
 *
 * The library's `Alert` takes a default slot rather than a title and a
 * description, and every state message in this package is both, so the pairing
 * is written once here instead of five times.
 */

/**
 * Props for `TitledAlert`.
 */
export interface TitledAlertProps {
    /** The headline. */
    title: string
    /** The explanation below it. */
    description?: string | null
    /** DaisyUI colour variant. @defaultValue 'info' */
    color?: 'info' | 'success' | 'warning' | 'error'
    /** Additional classes on the alert. */
    className?: string
}
</script>

<script setup lang="ts">
import { Alert } from '@artisanpack-ui/vue'

withDefaults( defineProps<TitledAlertProps>(), {
    description: null,
    color: 'info',
    className: undefined,
} )
</script>

<template>
    <Alert :color="color" :class-name="className">
        <div>
            <p class="font-medium">{{ title }}</p>
            <p v-if="description" class="text-sm opacity-80">{{ description }}</p>
        </div>
    </Alert>
</template>
