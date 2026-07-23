export type TaskLaunchCategoryId =
    | 'licensing'
    | 'preprovisioning'
    | 'interfaces'
    | 'highAvailability'
    | 'siteGroup'
    | 'misc';

export type TaskLaunchCategory = {
    id: TaskLaunchCategoryId;
    label: string;
};

export type TaskLaunchSearchable = {
    task_type: string;
    friendly_name: string;
    friendly_description: string;
    required_columns: string[];
};

export const TASK_LAUNCH_CATEGORIES: TaskLaunchCategory[] = [
    { id: 'licensing', label: 'Licensing' },
    { id: 'preprovisioning', label: 'Preprovisioning' },
    { id: 'interfaces', label: 'Interfaces' },
    { id: 'highAvailability', label: 'High Availability' },
    { id: 'siteGroup', label: 'Site/Group' },
    { id: 'misc', label: 'Misc.' },
];

const TASK_TYPE_TO_CATEGORY: Record<string, TaskLaunchCategoryId> = {
    ASSIGN_SUBSCRIPTION: 'licensing',
    UNASSIGN_SUBSCRIPTION: 'licensing',
    ADD_DEVICES_TO_GREENLAKE_INVENTORY: 'licensing',
    ADD_TAGS_TO_GREENLAKE_DEVICES: 'licensing',
    ADD_LOCATION_TO_GREENLAKE_DEVICES: 'licensing',
    ASSIGN_SERVICE_TO_GREENLAKE_DEVICES: 'licensing',
    EXPORT_MAC_ADDRESSES_TO_CENTRAL: 'misc',
    PREPROVISION_DEVICE_TO_GROUP: 'preprovisioning',
    ASSIGN_DEVICE_FUNCTION: 'preprovisioning',
    CONFIGURE_ALL_INTERFACE: 'interfaces',
    CONFIGURE_ETHERNET_INTERFACE: 'interfaces',
    CONFIGURE_LAG_INTERFACE: 'interfaces',
    CONFIGURE_VLAN_INTERFACE: 'interfaces',
    CONFIGURE_MIRROR_SESSION: 'interfaces',
    CREATE_VSF_PROFILE: 'highAvailability',
    CREATE_VSX_PROFILE: 'highAvailability',
    ASSOCIATE_DEVICE_TO_SITE: 'siteGroup',
    MOVE_DEVICE_TO_GROUP: 'siteGroup',
    ASSOCIATE_SITE_AND_NAME: 'siteGroup',
    UPDATE_SYSTEM_INFO: 'misc',
    REMOVE_VSF_PROFILE_LOCAL_OVERRIDES: 'misc',
    ADD_VLANS_TO_DEVICE_GROUP: 'misc',
    RELAUNCH_FAILED_CRITICAL_CONFIG: 'misc',
};

const TASK_SEARCH_ALIASES: Record<string, string[]> = {
    ASSIGN_SUBSCRIPTION: ['license', 'subscription', 'greenlake'],
    UNASSIGN_SUBSCRIPTION: ['license', 'subscription', 'greenlake', 'remove'],
    ADD_DEVICES_TO_GREENLAKE_INVENTORY: [
        'inventory',
        'greenlake',
        'add devices',
        'mac',
        'onboard',
    ],
    ADD_TAGS_TO_GREENLAKE_DEVICES: [
        'tags',
        'greenlake tags',
        'inventory',
        'greenlake',
    ],
    ADD_LOCATION_TO_GREENLAKE_DEVICES: [
        'location',
        'greenlake location',
        'inventory',
        'greenlake',
    ],
    ASSIGN_SERVICE_TO_GREENLAKE_DEVICES: [
        'service',
        'application',
        'greenlake service',
        'assign service',
        'inventory',
        'greenlake',
    ],
    EXPORT_MAC_ADDRESSES_TO_CENTRAL: [
        'mac',
        'central',
        'nac',
        'tags',
        'registration',
        'export',
        'import',
    ],
    PREPROVISION_DEVICE_TO_GROUP: ['preprovision', 'group'],
    ASSIGN_DEVICE_FUNCTION: ['function', 'persona'],
    CONFIGURE_ALL_INTERFACE: ['lag', 'ethernet', 'vlan', 'svi', 'interface'],
    CONFIGURE_ETHERNET_INTERFACE: ['ethernet', 'physical', 'port', 'interface'],
    CONFIGURE_LAG_INTERFACE: ['lag', 'portchannel', 'aggregate', 'interface'],
    CONFIGURE_VLAN_INTERFACE: ['svi', 'vlan', 'layer3', 'interface'],
    CONFIGURE_MIRROR_SESSION: ['mirror', 'span', 'darktrace'],
    CREATE_VSF_PROFILE: ['vsf', 'stack', 'autostack'],
    CREATE_VSX_PROFILE: ['vsx', 'pair', 'keepalive'],
    ASSOCIATE_DEVICE_TO_SITE: ['site', 'associate'],
    MOVE_DEVICE_TO_GROUP: ['group', 'move'],
    ASSOCIATE_SITE_AND_NAME: ['site', 'name', 'associate'],
    UPDATE_SYSTEM_INFO: ['name', 'rename'],
    REMOVE_VSF_PROFILE_LOCAL_OVERRIDES: ['vsf', 'override', 'local'],
    ADD_VLANS_TO_DEVICE_GROUP: ['vlan', 'whse', 'template'],
    RELAUNCH_FAILED_CRITICAL_CONFIG: ['relaunch', 'retry', 'critical', 'failed'],
};

const CATEGORY_BY_ID = Object.fromEntries(
    TASK_LAUNCH_CATEGORIES.map((category) => [category.id, category]),
) as Record<TaskLaunchCategoryId, TaskLaunchCategory>;

export function getTaskLaunchCategory(taskType: string): TaskLaunchCategoryId {
    return TASK_TYPE_TO_CATEGORY[taskType] ?? 'misc';
}

function requiredColumnsSearchText(columns: string[]): string {
    return columns.length > 0 ? columns.join(', ') : 'No required columns';
}

export function taskMatchesSearch(
    task: TaskLaunchSearchable,
    query: string,
): boolean {
    const q = query.trim().toLowerCase();
    if (q === '') {
        return true;
    }

    const category = CATEGORY_BY_ID[getTaskLaunchCategory(task.task_type)];
    const haystack = [
        task.friendly_name,
        task.friendly_description,
        requiredColumnsSearchText(task.required_columns),
        category.label,
        ...(TASK_SEARCH_ALIASES[task.task_type] ?? []),
    ]
        .join(' ')
        .toLowerCase();

    return haystack.includes(q);
}

export function groupTasksByLaunchCategory<T extends TaskLaunchSearchable>(
    tasks: T[],
): Record<TaskLaunchCategoryId, T[]> {
    const grouped = Object.fromEntries(
        TASK_LAUNCH_CATEGORIES.map((category) => [category.id, [] as T[]]),
    ) as Record<TaskLaunchCategoryId, T[]>;

    for (const task of tasks) {
        grouped[getTaskLaunchCategory(task.task_type)].push(task);
    }

    return grouped;
}
