import type {
    AccessListEndpoint,
    AccessListRule,
    AccessListService,
    UserRole,
} from '@/pages/Migration/migration-types';

export function formatEndpoint(endpoint: AccessListEndpoint): string {
    switch (endpoint.type) {
        case 'user':
        case 'any':
            return endpoint.type;
        case 'host':
            return `host ${endpoint.value ?? ''}`;
        case 'alias':
            return `alias ${endpoint.value ?? ''}`;
        case 'network':
            return `network ${endpoint.value ?? ''} ${endpoint.subnet ?? ''}`.trim();
        default:
            return endpoint.type;
    }
}

export function formatService(service: AccessListService): string {
    switch (service.type) {
        case 'any':
            return 'any';
        case 'tcp':
        case 'udp':
            return [service.type, ...(service.ports ?? [])].join(' ');
        case 'svc':
            return service.name ?? 'svc';
        case 'app':
            return `app ${service.name ?? ''}`.trim();
        case 'other':
            return service.raw ?? 'other';
        default:
            return service.type;
    }
}

export function formatRuleLine(rule: AccessListRule): string {
    const base = [
        formatEndpoint(rule.source),
        formatEndpoint(rule.destination),
        formatService(rule.service),
        rule.action,
    ].join(' ');

    return rule.other.trim() !== '' ? `${base} ${rule.other.trim()}` : base;
}

export type SharedRule = {
    key: string;
    display: string;
    roleNames: string[];
};

export function aggregateSharedRules(
    userRoles: UserRole[],
    selectedRoleNames: Set<string>,
): SharedRule[] {
    const byKey = new Map<string, Set<string>>();

    for (const role of userRoles) {
        if (!selectedRoleNames.has(role.name)) {
            continue;
        }

        for (const accessList of role.access_lists) {
            for (const rule of accessList.rules) {
                const key = formatRuleLine(rule);
                const roles = byKey.get(key) ?? new Set<string>();
                roles.add(role.name);
                byKey.set(key, roles);
            }
        }
    }

    return Array.from(byKey.entries())
        .map(([key, roles]) => ({
            key,
            display: key,
            roleNames: Array.from(roles).sort((a, b) => a.localeCompare(b)),
        }))
        .sort((a, b) => a.key.localeCompare(b.key));
}

export type RuleGroup = {
    id: string;
    name: string;
    ruleKeys: string[];
};

export function pruneRuleGroups(groups: RuleGroup[], validKeys: Set<string>): RuleGroup[] {
    return groups.map((group) => ({
        ...group,
        ruleKeys: group.ruleKeys.filter((key) => validKeys.has(key)),
    }));
}

export function moveArrayItem<T>(items: T[], fromIndex: number, toIndex: number): T[] {
    if (
        fromIndex < 0 ||
        toIndex < 0 ||
        fromIndex >= items.length ||
        toIndex >= items.length ||
        fromIndex === toIndex
    ) {
        return items;
    }

    const next = [...items];
    const [item] = next.splice(fromIndex, 1);
    next.splice(toIndex, 0, item);

    return next;
}
