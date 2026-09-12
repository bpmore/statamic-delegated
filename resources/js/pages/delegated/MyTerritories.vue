<script setup>
import { computed, getCurrentInstance, ref } from 'vue';
import { toast } from '@statamic/cms/api';
import { Head } from '@statamic/cms/inertia';
import AuditLogModal from '../../components/AuditLogModal.vue';
import {
    Badge,
    Button,
    Checkbox,
    Description,
    Field,
    Header,
    Input,
    Modal,
    Panel,
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
 * Territory admin self-service (Phase 4.2).
 *
 * A department head's view of their own people: the territories they
 * administer, who is in each, and the means to add and remove members and
 * set the roles they themselves hold. Everything the server refuses under
 * the six invariants comes back as a 403 that names the rule, and is shown
 * as it is — the screen never pretends a refusal was a success.
 */
const props = defineProps({
    territories: { type: Array, required: true },
    grantableRoles: { type: Array, required: true },
    levels: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { $axios } = getCurrentInstance().appContext.config.globalProperties;

const territories = ref(props.territories);

// ------------------------------------------------------------ activity

const auditing = ref(null);
const auditOpen = computed({ get: () => auditing.value !== null, set: (o) => { if (!o) auditing.value = null; } });

const roleTitle = (handle) => props.grantableRoles.find((r) => r.handle === handle)?.title ?? handle;

function refused(e, fallback = 'Something went wrong') {
    if (e.response?.status === 403 && e.response.data?.message) {
        toast.error(e.response.data.message);
        return {};
    }
    if (e.response?.status === 422) {
        return e.response.data.errors ?? {};
    }
    toast.error(e.response?.data?.message ?? fallback);
    return {};
}

// ------------------------------------------------------------ rename

const renaming = ref(null);
const newName = ref('');
const renameErrors = ref({});
const renameOpen = computed({ get: () => renaming.value !== null, set: (o) => { if (!o) renaming.value = null; } });

function openRename(territory) {
    newName.value = territory.name;
    renameErrors.value = {};
    renaming.value = territory;
}

async function rename() {
    try {
        const { data } = await $axios.patch(renaming.value.urls.update, { name: newName.value });
        territories.value = data.territories;
        toast.success('Territory renamed');
        renaming.value = null;
    } catch (e) {
        renameErrors.value = refused(e);
    }
}

// ------------------------------------------------------------ add member

const addingTo = ref(null);
const addOpen = computed({ get: () => addingTo.value !== null, set: (o) => { if (!o) addingTo.value = null; } });
const newMember = ref({ subject_type: 'user', subject: '', level: 'member', roles: [] });
const addErrors = ref({});
const adding = ref(false);

function openAdd(territory) {
    newMember.value = { subject_type: 'user', subject: '', level: 'member', roles: [] };
    addErrors.value = {};
    addingTo.value = territory;
}

function toggleRole(list, handle, on) {
    const i = list.indexOf(handle);
    if (on && i === -1) list.push(handle);
    if (!on && i !== -1) list.splice(i, 1);
}

async function addMember() {
    adding.value = true;
    addErrors.value = {};

    try {
        const payload = { ...newMember.value };
        if (payload.subject_type === 'group') delete payload.roles;
        const { data } = await $axios.post(addingTo.value.urls.members, payload);
        territories.value = data.territories;
        toast.success('Member added');
        addingTo.value = null;
    } catch (e) {
        addErrors.value = refused(e);
    } finally {
        adding.value = false;
    }
}

async function removeMember(territory, member) {
    try {
        const { data } = await $axios.delete(territory.urls.members, { data: { subject_type: member.type, subject_id: member.id } });
        territories.value = data.territories;
        toast.success('Member removed');
    } catch (e) {
        refused(e);
    }
}

// ------------------------------------------------------------ roles

const editingRoles = ref(null); // { territory, member }
const roleSelection = ref([]);
const rolesOpen = computed({ get: () => editingRoles.value !== null, set: (o) => { if (!o) editingRoles.value = null; } });

function openRoles(territory, member) {
    roleSelection.value = [...member.roles];
    editingRoles.value = { territory, member };
}

async function saveRoles() {
    const { territory, member } = editingRoles.value;

    try {
        const { data } = await $axios.patch(territory.urls.roles.replace('__user__', member.id), { roles: roleSelection.value });
        territories.value = data.territories;
        toast.success('Roles saved');
        editingRoles.value = null;
    } catch (e) {
        refused(e);
    }
}
</script>

<template>
    <Head title="My territories" />

    <Header title="My territories" icon="users" />

    <Text class="mb-4">
        The parts of the site you administer, and the people in them. You can add and remove members
        and give them any of your own roles. You cannot change what a territory covers, and you cannot
        reach anyone outside it.
    </Text>

    <div class="space-y-6">
        <Panel v-for="territory in territories" :key="territory.id" :heading="territory.name" :subheading="`${territory.scope_type.replace('_', ' ')} · ${territory.scope_ref}`">
            <div class="flex justify-end gap-2 mb-3">
                <Button text="Activity" variant="ghost" size="sm" icon="history" @click="auditing = territory" />
                <Button text="Rename" variant="ghost" size="sm" icon="pencil" @click="openRename(territory)" />
                <Button text="Add member" variant="primary" size="sm" icon="plus" @click="openAdd(territory)" />
            </div>

            <Table v-if="territory.members.length">
                <TableColumns>
                    <TableColumn>Who</TableColumn>
                    <TableColumn>Level</TableColumn>
                    <TableColumn>Roles</TableColumn>
                    <TableColumn />
                </TableColumns>
                <TableRows>
                    <TableRow v-for="member in territory.members" :key="member.subject">
                        <TableCell>
                            <Badge :text="member.type" size="sm" class="mr-2" />
                            {{ member.label }}
                            <Badge v-if="member.self" text="you" size="sm" color="blue" class="ml-2" />
                        </TableCell>
                        <TableCell>
                            <Badge :text="member.level" size="sm" :color="member.level === 'admin' ? 'purple' : 'gray'" />
                        </TableCell>
                        <TableCell>
                            <template v-if="member.type === 'user'">
                                <Badge v-for="r in member.roles" :key="r" :text="roleTitle(r)" size="sm" class="mr-1" />
                                <Badge v-for="r in member.lockedRoles" :key="r" :text="r" size="sm" color="gray" icon="lock" class="mr-1" />
                                <Description v-if="!member.roles.length && !member.lockedRoles.length" text="No roles" />
                            </template>
                        </TableCell>
                        <TableCell class="text-right whitespace-nowrap">
                            <Button
                                v-if="member.type === 'user' && member.editable"
                                text="Roles"
                                variant="ghost"
                                size="sm"
                                @click="openRoles(territory, member)"
                            />
                            <Button
                                v-if="member.editable && !member.self"
                                text="Remove"
                                variant="ghost"
                                size="sm"
                                @click="removeMember(territory, member)"
                            />
                            <Badge v-if="!member.editable" text="read-only" size="sm" color="gray" />
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
            <Text v-else>Nobody is assigned yet.</Text>
        </Panel>
    </div>

    <!-- Activity -->
    <AuditLogModal v-model:open="auditOpen" :title="auditing ? `Activity in ${auditing.name}` : 'Activity'" :url="auditing?.urls.audit" />

    <!-- Rename -->
    <Modal v-model:open="renameOpen" title="Rename territory">
        <form @submit.prevent="rename">
            <Field label="Name" :errors="renameErrors.name" instructions="Only the name. What the territory covers is set by a site administrator." required>
                <Input v-model="newName" />
            </Field>
        </form>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button text="Cancel" variant="ghost" @click="renaming = null" />
                <Button text="Save" variant="primary" @click="rename" />
            </div>
        </template>
    </Modal>

    <!-- Add member -->
    <Modal v-model:open="addOpen" :title="addingTo ? `Add to ${addingTo.name}` : 'Add member'">
        <form class="space-y-4" @submit.prevent="addMember">
            <Field label="Add" :errors="addErrors.subject_type">
                <Select v-model="newMember.subject_type" :options="[{ value: 'user', label: 'A user, by email address' }, { value: 'group', label: 'A user group, by handle' }]" />
            </Field>
            <Field
                :label="newMember.subject_type === 'user' ? 'Email address' : 'Group handle'"
                :errors="addErrors.subject"
                :instructions="newMember.subject_type === 'user' ? 'The colleague must already have an account.' : ''"
                required
            >
                <Input v-model="newMember.subject" :placeholder="newMember.subject_type === 'user' ? 'colleague@example.org' : 'cardiology-editors'" />
            </Field>
            <Field label="Level" :errors="addErrors.level" :instructions="levels.find((l) => l.value === newMember.level)?.hint">
                <Select v-model="newMember.level" :options="levels" />
            </Field>
            <Field v-if="newMember.subject_type === 'user' && grantableRoles.length" label="Roles" :errors="addErrors.roles" instructions="Only roles you hold yourself can be given.">
                <div class="space-y-2">
                    <Checkbox
                        solo
                        v-for="role in grantableRoles"
                        :key="role.handle"
                        :label="role.title"
                        :model-value="newMember.roles.includes(role.handle)"
                        @update:model-value="(on) => toggleRole(newMember.roles, role.handle, on)"
                    />
                </div>
            </Field>
        </form>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button text="Cancel" variant="ghost" @click="addingTo = null" />
                <Button text="Add" variant="primary" :loading="adding" :disabled="!newMember.subject" @click="addMember" />
            </div>
        </template>
    </Modal>

    <!-- Roles -->
    <Modal v-model:open="rolesOpen" :title="editingRoles ? `Roles for ${editingRoles.member.label}` : 'Roles'">
        <div v-if="editingRoles" class="space-y-4">
            <div class="space-y-2">
                <Checkbox
                    solo
                    v-for="role in grantableRoles"
                    :key="role.handle"
                    :label="role.title"
                    :model-value="roleSelection.includes(role.handle)"
                    @update:model-value="(on) => toggleRole(roleSelection, role.handle, on)"
                />
            </div>
            <Description v-if="editingRoles.member.lockedRoles.length" :text="`Kept as they are, because you do not hold them: ${editingRoles.member.lockedRoles.join(', ')}`" />
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button text="Cancel" variant="ghost" @click="editingRoles = null" />
                <Button text="Save" variant="primary" @click="saveRoles" />
            </div>
        </template>
    </Modal>
</template>
