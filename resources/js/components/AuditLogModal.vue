<script setup>
import { computed, getCurrentInstance, ref, watch } from 'vue';
import { Badge, Button, Description, Modal, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows, Text } from '@statamic/cms/ui';

/**
 * The audit log as a modal: given a URL, fetches and lists it. The server
 * has already decided what the viewer may see and whom it may name, so this
 * only lays the rows out. Refusals are marked; before/after are shown as
 * "key: was → now" lines.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, default: 'Activity' },
    url: { type: String, default: null },
});

const emit = defineEmits(['update:open']);

const { $axios } = getCurrentInstance().appContext.config.globalProperties;

const entries = ref([]);
const loading = ref(false);
const error = ref(null);

const isOpen = computed({ get: () => props.open, set: (o) => emit('update:open', o) });

watch(
    () => [props.open, props.url],
    async ([open, url]) => {
        if (!open || !url) return;
        loading.value = true;
        error.value = null;
        try {
            const { data } = await $axios.get(url);
            entries.value = data.entries;
        } catch (e) {
            error.value = e.response?.data?.message ?? 'Could not load the log';
            entries.value = [];
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

const isRefusal = (entry) => entry.action.startsWith('refused.');

function describe(entry) {
    const verb = entry.action.replace('refused.', '').replace(/[._]/g, ' ');
    return isRefusal(entry) ? `refused: ${verb}` : verb;
}

function changes(entry) {
    const keys = new Set([...Object.keys(entry.before ?? {}), ...Object.keys(entry.after ?? {})]);
    return [...keys]
        .filter((k) => !(isRefusal(entry) && k === 'message'))
        .map((k) => ({ key: k, before: show(entry.before?.[k]), after: show(entry.after?.[k]) }));
}

function show(value) {
    if (value === undefined || value === null) return null;
    if (Array.isArray(value)) return value.length ? value.join(', ') : '(none)';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

const when = (iso) => new Date(iso).toLocaleString();
</script>

<template>
    <Modal v-model:open="isOpen" :title="title" size="lg">
        <Text v-if="loading">Loading…</Text>
        <Text v-else-if="error">{{ error }}</Text>
        <Text v-else-if="!entries.length">Nothing has happened yet.</Text>
        <div v-else class="overflow-x-auto">
            <Table>
                <TableColumns>
                    <TableColumn>When</TableColumn>
                    <TableColumn>Who</TableColumn>
                    <TableColumn>What</TableColumn>
                    <TableColumn>Change</TableColumn>
                </TableColumns>
                <TableRows>
                    <TableRow v-for="entry in entries" :key="entry.id">
                        <TableCell class="whitespace-nowrap align-top">{{ when(entry.at) }}</TableCell>
                        <TableCell class="align-top">{{ entry.actor.label }}</TableCell>
                        <TableCell class="align-top">
                            <Badge :text="describe(entry)" size="sm" :color="isRefusal(entry) ? 'red' : 'gray'" />
                            <div class="mt-1">
                                {{ entry.target.label }}
                                <Description v-if="entry.territory" :text="`in ${entry.territory.name}`" />
                            </div>
                        </TableCell>
                        <TableCell class="align-top">
                            <div v-for="c in changes(entry)" :key="c.key" class="text-sm">
                                <span class="font-medium">{{ c.key }}</span>:
                                <template v-if="c.before !== null && c.after !== null">{{ c.before }} → {{ c.after }}</template>
                                <template v-else-if="c.after !== null">{{ c.after }}</template>
                                <template v-else>{{ c.before }} (removed)</template>
                            </div>
                            <Description v-if="isRefusal(entry) && entry.after?.message" :text="entry.after.message" />
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </div>
        <template #footer>
            <div class="flex justify-end">
                <Button text="Close" variant="ghost" @click="isOpen = false" />
            </div>
        </template>
    </Modal>
</template>
