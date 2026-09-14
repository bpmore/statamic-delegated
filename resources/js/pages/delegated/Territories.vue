<script setup>
import { computed, getCurrentInstance, ref, watch } from 'vue';
import { toast } from '@statamic/cms/api';
import { Head } from '@statamic/cms/inertia';
import AuditLogModal from '../../components/AuditLogModal.vue';
import {
    Badge,
    Button,
    ConfirmationModal,
    Description,
    Dropdown,
    DropdownItem,
    DropdownMenu,
    EmptyStateItem,
    EmptyStateMenu,
    Field,
    Header,
    Input,
    Modal,
    Select,
    Table,
    TableCell,
    TableColumn,
    TableColumns,
    TableRow,
    TableRows,
    Text,
} from '@statamic/cms/ui';

/**
 * Site administration of territories (Phase 4.1).
 *
 * The map of the site as a super user draws it: which branch, collection or
 * term each department owns, how territories nest, and who is in each one.
 * Every write goes to a JSON endpoint behind `manage territories` and comes
 * back with the whole list, so what is shown is always what is stored.
 */
const props = defineProps({
    territories: { type: Array, required: true },
    sites: { type: Array, required: true },
    scopeTypes: { type: Array, required: true },
    collections: { type: Array, required: true },
    taxonomies: { type: Array, required: true },
    users: { type: Array, required: true },
    groups: { type: Array, required: true },
    urls: { type: Object, required: true },
});

// Statamic 6 puts its HTTP client on the Vue app: it carries the CSRF token
// and the control panel's error handling.
const { $axios } = getCurrentInstance().appContext.config.globalProperties;

const auditOpen = ref(false);

const territories = ref(props.territories);

const scopeLabel = (type) => props.scopeTypes.find((t) => t.value === type)?.label ?? type;
const siteName = (handle) => props.sites.find((s) => s.handle === handle)?.name ?? handle;

// ------------------------------------------------------------ territory form

const blank = () => ({
    name: '',
    slug: '',
    site: props.sites[0]?.handle ?? 'default',
    scope_type: 'structure_branch',
    scope_ref: '',
    parent_id: null,
});

const editing = ref(null); // null = closed, {} = creating, {id,...} = editing
const form = ref(blank());
const errors = ref({});
const saving = ref(false);
const slugTouched = ref(false);

const formOpen = computed({
    get: () => editing.value !== null,
    set: (open) => { if (!open) editing.value = null; },
});

const parentOptions = computed(() =>
    territories.value
        .filter((t) => t.site === form.value.site && t.id !== editing.value?.id)
        .map((t) => ({ value: t.id, label: `${t.name} (${t.scope_ref})` })),
);

const scopeHint = computed(() => props.scopeTypes.find((t) => t.value === form.value.scope_type)?.hint ?? '');

watch(() => form.value.name, (name) => {
    if (!slugTouched.value && !editing.value?.id) {
        form.value.slug = name.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }
});

function openCreate() {
    form.value = blank();
    errors.value = {};
    slugTouched.value = false;
    editing.value = {};
}

function openEdit(territory) {
    form.value = {
        name: territory.name,
        slug: territory.slug,
        site: territory.site,
        scope_type: territory.scope_type,
        scope_ref: territory.scope_ref,
        parent_id: territory.parent_id,
    };
    errors.value = {};
    slugTouched.value = true;
    editing.value = territory;
}

async function save() {
    saving.value = true;
    errors.value = {};

    try {
        const { data } = editing.value?.id
            ? await $axios.patch(editing.value.urls.update, form.value)
            : await $axios.post(props.urls.store, form.value);

        territories.value = data.territories;
        toast.success(editing.value?.id ? 'Territory saved' : 'Territory created');
        editing.value = null;
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors ?? {};
        } else {
            toast.error(e.response?.data?.message ?? 'Something went wrong');
        }
    } finally {
        saving.value = false;
    }
}

// ------------------------------------------------------------ delete

const deleting = ref(null);

async function destroy() {
    try {
        const { data } = await $axios.delete(deleting.value.urls.destroy);
        territories.value = data.territories;
        toast.success('Territory deleted');
    } catch (e) {
        toast.error(e.response?.data?.message ?? 'Something went wrong');
    } finally {
        deleting.value = null;
    }
}

// ------------------------------------------------------------ members

const membersOf = ref(null);
const membersOpen = computed({
    get: () => membersOf.value !== null,
    set: (open) => { if (!open) membersOf.value = null; },
});

const current = computed(() => territories.value.find((t) => t.id === membersOf.value?.id) ?? null);

const newMember = ref({ subject_type: 'user', subject_id: null, level: 'member' });
const memberErrors = ref({});
const addingMember = ref(false);

const subjectOptions = computed(() =>
    newMember.value.subject_type === 'user'
        ? props.users.map((u) => ({ value: u.id, label: u.label }))
        : props.groups.map((g) => ({ value: g.handle, label: g.title })),
);

watch(() => newMember.value.subject_type, () => { newMember.value.subject_id = null; });

function openMembers(territory) {
    newMember.value = { subject_type: 'user', subject_id: null, level: 'member' };
    memberErrors.value = {};
    membersOf.value = territory;
}

async function refresh() {
    const { data } = await $axios.get(props.urls.index, { headers: { Accept: 'application/json' } });
    territories.value = data.territories;
}

async function addMember() {
    addingMember.value = true;
    memberErrors.value = {};

    try {
        await $axios.post(current.value.urls.assignments, newMember.value);
        await refresh();
        toast.success('Member added');
        newMember.value = { ...newMember.value, subject_id: null };
    } catch (e) {
        if (e.response?.status === 422) {
            memberErrors.value = e.response.data.errors ?? {};
        } else {
            toast.error(e.response?.data?.message ?? 'Something went wrong');
        }
    } finally {
        addingMember.value = false;
    }
}

async function removeMember(assignment) {
    try {
        await $axios.delete(current.value.urls.assignments, { data: { subject_type: assignment.type, subject_id: assignment.id } });
        await refresh();
        toast.success('Member removed');
    } catch (e) {
        toast.error(e.response?.data?.message ?? 'Something went wrong');
    }
}
</script>

<template>
    <Head title="Territories" />

    <Header title="Territories" icon="users">
        <template #actions>
            <Button variant="ghost" text="Activity" icon="history" @click="auditOpen = true" />
            <Button variant="primary" text="New territory" icon="plus" @click="openCreate" />
        </template>
    </Header>

    <Text v-if="territories.length" class="mb-4">
        A territory is a branch of the site, a collection, or a taxonomy term that a department owns.
        Its members see and edit what is inside it and nothing else; its admins manage its members.
    </Text>

    <EmptyStateMenu
        v-if="!territories.length"
        heading="No territories yet"
        description="Nobody but super users can see any content until a territory exists and people are assigned to it."
    >
        <EmptyStateItem heading="Create the first territory" description="A branch such as /cardiology, a whole collection, or a term." icon="plus" @click="openCreate" />
    </EmptyStateMenu>

    <Table v-else>
        <TableColumns>
            <TableColumn>Territory</TableColumn>
            <TableColumn>Site</TableColumn>
            <TableColumn>Scope</TableColumn>
            <TableColumn>Parent</TableColumn>
            <TableColumn>Members</TableColumn>
            <TableColumn />
        </TableColumns>
        <TableRows>
            <TableRow v-for="territory in territories" :key="territory.id">
                <TableCell>
                    <div class="font-medium">{{ territory.name }}</div>
                    <Description :text="territory.slug" />
                </TableCell>
                <TableCell>{{ siteName(territory.site) }}</TableCell>
                <TableCell>
                    <Badge :text="scopeLabel(territory.scope_type)" size="sm" class="mr-2" />
                    <code>{{ territory.scope_ref }}</code>
                </TableCell>
                <TableCell>{{ territory.parent_name ?? '—' }}</TableCell>
                <TableCell>
                    <Button variant="ghost" size="sm" :text="`${territory.assignments.length} assigned`" icon="users" @click="openMembers(territory)" />
                </TableCell>
                <TableCell class="text-right">
                    <Dropdown>
                        <DropdownMenu>
                            <DropdownItem text="Edit" icon="pencil" @click="openEdit(territory)" />
                            <DropdownItem text="Members" icon="users" @click="openMembers(territory)" />
                            <DropdownItem text="Delete" icon="trash" variant="destructive" @click="deleting = territory" />
                        </DropdownMenu>
                    </Dropdown>
                </TableCell>
            </TableRow>
        </TableRows>
    </Table>

    <!-- Create / edit -->
    <Modal v-model:open="formOpen" :title="editing?.id ? 'Edit territory' : 'New territory'">
        <form class="space-y-4" @submit.prevent="save">
            <Field label="Name" :errors="errors.name" required>
                <Input v-model="form.name" placeholder="Cardiology" />
            </Field>
            <Field label="Slug" :errors="errors.slug" instructions="Used in URLs and the audit log. Unique per site." required>
                <Input v-model="form.slug" placeholder="cardiology" @update:model-value="slugTouched = true" />
            </Field>
            <Field label="Site" :errors="errors.site" required>
                <Select v-model="form.site" :options="sites" option-value="handle" option-label="name" />
            </Field>
            <Field label="Scope" :errors="errors.scope_type" required>
                <Select v-model="form.scope_type" :options="scopeTypes" option-value="value" option-label="label" />
            </Field>
            <Field
                :label="form.scope_type === 'structure_branch' ? 'Branch URI' : form.scope_type === 'collection' ? 'Collection' : 'Term'"
                :errors="errors.scope_ref"
                :instructions="scopeHint"
                required
            >
                <Select
                    v-if="form.scope_type === 'collection'"
                    v-model="form.scope_ref"
                    :options="collections"
                    option-value="handle"
                    option-label="title"
                    placeholder="Choose a collection"
                />
                <Input
                    v-else
                    v-model="form.scope_ref"
                    :placeholder="form.scope_type === 'structure_branch' ? '/cardiology' : 'departments::cardiology'"
                />
            </Field>
            <Field label="Parent territory" :errors="errors.parent_id" instructions="Optional. Members and admins of the parent are members and admins of this territory too.">
                <Select v-model="form.parent_id" :options="parentOptions" placeholder="None" clearable />
            </Field>
        </form>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button text="Cancel" variant="ghost" @click="editing = null" />
                <Button :text="editing?.id ? 'Save' : 'Create'" variant="primary" :loading="saving" @click="save" />
            </div>
        </template>
    </Modal>

    <!-- Members -->
    <Modal v-model:open="membersOpen" :title="current ? `Members of ${current.name}` : 'Members'">
        <div v-if="current" class="space-y-6">
            <Table v-if="current.assignments.length">
                <TableColumns>
                    <TableColumn>Who</TableColumn>
                    <TableColumn>Level</TableColumn>
                    <TableColumn />
                </TableColumns>
                <TableRows>
                    <TableRow v-for="assignment in current.assignments" :key="assignment.subject">
                        <TableCell>
                            <Badge :text="assignment.type" size="sm" class="mr-2" />
                            {{ assignment.label }}
                        </TableCell>
                        <TableCell>
                            <Badge :text="assignment.level" size="sm" :color="assignment.level === 'admin' ? 'purple' : 'gray'" />
                        </TableCell>
                        <TableCell class="text-right">
                            <Button variant="ghost" size="sm" icon="trash" icon-only text="Remove" @click="removeMember(assignment)" />
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
            <Text v-else>Nobody is assigned yet.</Text>

            <form class="space-y-4" @submit.prevent="addMember">
                <Field label="Add" :errors="memberErrors.subject_type">
                    <Select v-model="newMember.subject_type" :options="[{ value: 'user', label: 'A user' }, { value: 'group', label: 'A user group' }]" />
                </Field>
                <Field :label="newMember.subject_type === 'user' ? 'User' : 'Group'" :errors="memberErrors.subject_id">
                    <Select v-model="newMember.subject_id" :options="subjectOptions" placeholder="Choose…" />
                </Field>
                <Field label="Level" :errors="memberErrors.level" instructions="A member works on the content. An admin also adds and removes members, and nothing else.">
                    <Select v-model="newMember.level" :options="[{ value: 'member', label: 'Member' }, { value: 'admin', label: 'Admin' }]" />
                </Field>
                <div class="flex justify-end">
                    <Button text="Add" variant="primary" :loading="addingMember" :disabled="!newMember.subject_id" @click="addMember" />
                </div>
            </form>
        </div>
    </Modal>

    <ConfirmationModal
        :open="deleting !== null"
        :title="deleting ? `Delete ${deleting.name}?` : ''"
        button-text="Delete"
        danger
        @update:open="(open) => { if (!open) deleting = null; }"
        @confirm="destroy"
    >
        Its assignments go with it. Territories nested under it become top-level territories.
        The content itself is not touched, but nobody but a super user will be able to reach it until it is covered again.
    </ConfirmationModal>

    <AuditLogModal v-model:open="auditOpen" title="Activity across all territories" :url="urls.audit" />
</template>
