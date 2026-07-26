import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    ChevronRight,
    Plus,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AccessList,
    AccessListEndpoint,
    AccessListService,
    NetDestination,
    NetService,
    UserRole,
} from '@/pages/Migration/migration-types';
import {
    aggregateSharedRules,
    formatEndpoint,
    formatService,
    moveArrayItem,
    pruneRuleGroups,
    type RuleGroup,
} from '@/pages/Migration/user-role-mapping';

type UserRolesCardProps = {
    userRoles: UserRole[];
};

const UNASSIGNED_GROUP_VALUE = '__unassigned__';

function formatNetDestination(alias: NetDestination): string {
    const parts: string[] = [];

    if (alias.invert) {
        parts.push('invert');
    }

    for (const entry of alias.entries) {
        if (entry.type === 'network') {
            parts.push(`network ${entry.value ?? ''} ${entry.subnet ?? ''}`.trim());
        } else if (entry.type === 'host') {
            parts.push(entry.value ? `host ${entry.value}` : 'host');
        } else {
            parts.push(`name ${entry.value ?? ''}`.trim());
        }
    }

    return parts.join(', ') || '(empty)';
}

function formatNetService(service: NetService): string {
    const parts = [service.protocol, ...service.values].filter(Boolean);

    if (service.alg) {
        parts.push(`ALG ${service.alg}`);
    }

    return parts.join(' ') || '(empty)';
}

function EndpointCell({ endpoint }: { endpoint: AccessListEndpoint }) {
    const resolved = endpoint.type === 'alias' ? endpoint.resolved : undefined;

    return (
        <div>
            <div className="font-mono text-xs">{formatEndpoint(endpoint)}</div>
            {endpoint.type === 'alias' && resolved && (
                <div className="text-muted-foreground mt-1 text-xs">
                    → {formatNetDestination(resolved)}
                </div>
            )}
            {endpoint.type === 'alias' && resolved === null && (
                <div className="mt-1 text-xs text-amber-600">Unresolved alias</div>
            )}
        </div>
    );
}

function ServiceCell({ service }: { service: AccessListService }) {
    const resolved = service.type === 'svc' ? service.resolved : undefined;

    return (
        <div>
            <div className="font-mono text-xs">{formatService(service)}</div>
            {service.type === 'svc' && resolved && (
                <div className="text-muted-foreground mt-1 text-xs">
                    → {formatNetService(resolved)}
                </div>
            )}
            {service.type === 'svc' && resolved === null && (
                <div className="mt-1 text-xs text-amber-600">Unresolved service</div>
            )}
        </div>
    );
}

function AccessListRules({ accessList }: { accessList: AccessList }) {
    if (accessList.rules.length === 0) {
        return (
            <p className="text-muted-foreground px-2 py-2 text-xs">
                No IPv4 session rules
                {accessList.warnings.length > 0 ? ` (${accessList.warnings.join('; ')})` : ''}.
            </p>
        );
    }

    return (
        <div className="mt-2 space-y-2">
            {accessList.warnings.length > 0 && (
                <ul className="list-inside list-disc text-xs text-amber-600">
                    {accessList.warnings.map((warning) => (
                        <li key={warning}>{warning}</li>
                    ))}
                </ul>
            )}
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left">
                        <th className="px-2 py-1 font-medium">Source</th>
                        <th className="px-2 py-1 font-medium">Destination</th>
                        <th className="px-2 py-1 font-medium">Service</th>
                        <th className="px-2 py-1 font-medium">Action</th>
                        <th className="px-2 py-1 font-medium">Other</th>
                    </tr>
                </thead>
                <tbody>
                    {accessList.rules.map((rule, index) => (
                        <tr
                            key={`${accessList.name}-${index}-${rule.action}`}
                            className="border-b align-top"
                        >
                            <td className="px-2 py-2">
                                <EndpointCell endpoint={rule.source} />
                            </td>
                            <td className="px-2 py-2">
                                <EndpointCell endpoint={rule.destination} />
                            </td>
                            <td className="px-2 py-2">
                                <ServiceCell service={rule.service} />
                            </td>
                            <td className="px-2 py-2 font-mono text-xs">{rule.action}</td>
                            <td className="text-muted-foreground px-2 py-2 font-mono text-xs">
                                {rule.other || '—'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function CentralRoleMappingSection({ userRoles }: { userRoles: UserRole[] }) {
    const [selectedRoleNames, setSelectedRoleNames] = useState<Set<string>>(new Set());
    const [groups, setGroups] = useState<RuleGroup[]>([]);

    const sharedRules = useMemo(
        () => aggregateSharedRules(userRoles, selectedRoleNames),
        [userRoles, selectedRoleNames],
    );

    const validKeys = useMemo(
        () => new Set(sharedRules.map((rule) => rule.key)),
        [sharedRules],
    );

    const prunedGroups = useMemo(
        () => pruneRuleGroups(groups, validKeys),
        [groups, validKeys],
    );

    useEffect(() => {
        setGroups((current) => {
            const pruned = pruneRuleGroups(current, validKeys);
            const unchanged =
                pruned.length === current.length &&
                pruned.every(
                    (group, index) =>
                        group.ruleKeys.length === current[index].ruleKeys.length &&
                        group.ruleKeys.every(
                            (key, keyIndex) => key === current[index].ruleKeys[keyIndex],
                        ),
                );

            return unchanged ? current : pruned;
        });
    }, [validKeys]);

    const groupIdByRuleKey = useMemo(() => {
        const map = new Map<string, string>();

        for (const group of prunedGroups) {
            for (const key of group.ruleKeys) {
                map.set(key, group.id);
            }
        }

        return map;
    }, [prunedGroups]);

    const selectedCount = selectedRoleNames.size;
    const allSelected = userRoles.length > 0 && selectedCount === userRoles.length;

    const toggleRole = (roleName: string) => {
        setSelectedRoleNames((current) => {
            const next = new Set(current);
            if (next.has(roleName)) {
                next.delete(roleName);
            } else {
                next.add(roleName);
            }

            return next;
        });
    };

    const selectAllRoles = () => {
        setSelectedRoleNames(new Set(userRoles.map((role) => role.name)));
    };

    const clearRoles = () => {
        setSelectedRoleNames(new Set());
    };

    const addGroup = () => {
        setGroups((current) => [
            ...pruneRuleGroups(current, validKeys),
            {
                id: crypto.randomUUID(),
                name: `Group ${current.length + 1}`,
                ruleKeys: [],
            },
        ]);
    };

    const renameGroup = (groupId: string, name: string) => {
        setGroups((current) =>
            pruneRuleGroups(current, validKeys).map((group) =>
                group.id === groupId ? { ...group, name } : group,
            ),
        );
    };

    const deleteGroup = (groupId: string) => {
        setGroups((current) =>
            pruneRuleGroups(current, validKeys).filter((group) => group.id !== groupId),
        );
    };

    const moveGroup = (groupId: string, direction: -1 | 1) => {
        setGroups((current) => {
            const pruned = pruneRuleGroups(current, validKeys);
            const index = pruned.findIndex((group) => group.id === groupId);

            return moveArrayItem(pruned, index, index + direction);
        });
    };

    const assignRuleToGroup = (ruleKey: string, groupId: string | null) => {
        setGroups((current) => {
            const pruned = pruneRuleGroups(current, validKeys).map((group) => ({
                ...group,
                ruleKeys: group.ruleKeys.filter((key) => key !== ruleKey),
            }));

            if (groupId === null) {
                return pruned;
            }

            return pruned.map((group) =>
                group.id === groupId
                    ? { ...group, ruleKeys: [...group.ruleKeys, ruleKey] }
                    : group,
            );
        });
    };

    const moveRuleInGroup = (groupId: string, ruleKey: string, direction: -1 | 1) => {
        setGroups((current) =>
            pruneRuleGroups(current, validKeys).map((group) => {
                if (group.id !== groupId) {
                    return group;
                }

                const index = group.ruleKeys.indexOf(ruleKey);

                return {
                    ...group,
                    ruleKeys: moveArrayItem(group.ruleKeys, index, index + direction),
                };
            }),
        );
    };

    return (
        <div className="mt-8 flex flex-col gap-4 border-t pt-6">
            <div>
                <h3 className="text-sm font-medium">New Central Role Mapping</h3>
                <p className="text-muted-foreground mt-1 text-xs">
                    Select roles to compare shared access-list rules, then group matching lines
                    into named, ordered Central policy sets.
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button type="button" variant="outline" size="sm">
                            Select roles
                            <Badge variant="secondary" className="ml-1">
                                {selectedCount}
                            </Badge>
                            <ChevronDown className="size-3" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="max-h-72 w-64 overflow-y-auto">
                        <DropdownMenuLabel>Parsed roles</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={(event) => {
                                event.preventDefault();
                                selectAllRoles();
                            }}
                        >
                            Select all
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={(event) => {
                                event.preventDefault();
                                clearRoles();
                            }}
                        >
                            Clear
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        {userRoles.length === 0 && (
                            <DropdownMenuItem disabled>No roles available</DropdownMenuItem>
                        )}
                        {userRoles.map((role) => (
                            <DropdownMenuCheckboxItem
                                key={role.name}
                                checked={selectedRoleNames.has(role.name)}
                                onCheckedChange={() => toggleRole(role.name)}
                                onSelect={(event) => event.preventDefault()}
                            >
                                {role.name}
                            </DropdownMenuCheckboxItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>

                {allSelected && (
                    <span className="text-muted-foreground text-xs">All roles selected</span>
                )}
            </div>

            {selectedCount === 0 ? (
                <p className="text-muted-foreground text-sm">
                    Choose one or more roles to list their access-list rules.
                </p>
            ) : (
                <>
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="px-2 py-2 font-medium">Rule</th>
                                    <th className="px-2 py-2 font-medium">Roles</th>
                                    <th className="w-48 px-2 py-2 font-medium">Group</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sharedRules.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={3}
                                            className="text-muted-foreground px-2 py-6 text-center"
                                        >
                                            Selected roles have no IPv4 session rules.
                                        </td>
                                    </tr>
                                )}
                                {sharedRules.map((rule) => {
                                    const assignedGroupId =
                                        groupIdByRuleKey.get(rule.key) ?? UNASSIGNED_GROUP_VALUE;

                                    return (
                                        <tr key={rule.key} className="border-b align-top">
                                            <td className="px-2 py-2 font-mono text-xs">
                                                {rule.display}
                                            </td>
                                            <td className="px-2 py-2">
                                                <div className="flex flex-wrap gap-1">
                                                    {rule.roleNames.map((roleName) => (
                                                        <Badge
                                                            key={roleName}
                                                            variant="secondary"
                                                        >
                                                            {roleName}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="px-2 py-2">
                                                <Select
                                                    value={assignedGroupId}
                                                    onValueChange={(value) =>
                                                        assignRuleToGroup(
                                                            rule.key,
                                                            value === UNASSIGNED_GROUP_VALUE
                                                                ? null
                                                                : value,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger className="h-8">
                                                        <SelectValue placeholder="Unassigned" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value={UNASSIGNED_GROUP_VALUE}>
                                                            Unassigned
                                                        </SelectItem>
                                                        {prunedGroups.map((group) => (
                                                            <SelectItem
                                                                key={group.id}
                                                                value={group.id}
                                                            >
                                                                {group.name || 'Untitled group'}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex items-center justify-between gap-2">
                        <Label className="text-sm font-medium">Rule groups</Label>
                        <Button type="button" variant="outline" size="sm" onClick={addGroup}>
                            <Plus className="size-4" />
                            Add group
                        </Button>
                    </div>

                    {prunedGroups.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No groups yet. Add a group, then assign rules from the table above.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-3">
                            {prunedGroups.map((group, groupIndex) => (
                                <div
                                    key={group.id}
                                    className="bg-muted/30 rounded-md border p-3"
                                >
                                    <div className="mb-3 flex flex-wrap items-center gap-2">
                                        <Input
                                            value={group.name}
                                            onChange={(event) =>
                                                renameGroup(group.id, event.target.value)
                                            }
                                            className="h-8 max-w-xs"
                                            aria-label="Group name"
                                        />
                                        <div className="flex items-center gap-1">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                disabled={groupIndex === 0}
                                                onClick={() => moveGroup(group.id, -1)}
                                                aria-label="Move group up"
                                            >
                                                <ArrowUp className="size-4" />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                disabled={groupIndex === prunedGroups.length - 1}
                                                onClick={() => moveGroup(group.id, 1)}
                                                aria-label="Move group down"
                                            >
                                                <ArrowDown className="size-4" />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => deleteGroup(group.id)}
                                                aria-label="Delete group"
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    </div>

                                    {group.ruleKeys.length === 0 ? (
                                        <p className="text-muted-foreground text-xs">
                                            No rules assigned.
                                        </p>
                                    ) : (
                                        <ul className="flex flex-col gap-1">
                                            {group.ruleKeys.map((ruleKey, ruleIndex) => (
                                                <li
                                                    key={ruleKey}
                                                    className="bg-background flex items-start justify-between gap-2 rounded border px-2 py-1.5"
                                                >
                                                    <span className="font-mono text-xs">
                                                        {ruleKey}
                                                    </span>
                                                    <div className="flex shrink-0 items-center gap-0.5">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-7 w-7 p-0"
                                                            disabled={ruleIndex === 0}
                                                            onClick={() =>
                                                                moveRuleInGroup(
                                                                    group.id,
                                                                    ruleKey,
                                                                    -1,
                                                                )
                                                            }
                                                            aria-label="Move rule up"
                                                        >
                                                            <ArrowUp className="size-3" />
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-7 w-7 p-0"
                                                            disabled={
                                                                ruleIndex ===
                                                                group.ruleKeys.length - 1
                                                            }
                                                            onClick={() =>
                                                                moveRuleInGroup(
                                                                    group.id,
                                                                    ruleKey,
                                                                    1,
                                                                )
                                                            }
                                                            aria-label="Move rule down"
                                                        >
                                                            <ArrowDown className="size-3" />
                                                        </Button>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

export default function UserRolesCard({ userRoles }: UserRolesCardProps) {
    const [expandedRoles, setExpandedRoles] = useState<Record<string, boolean>>({});
    const [expandedAcls, setExpandedAcls] = useState<Record<string, boolean>>({});

    const toggleRole = (roleName: string) => {
        setExpandedRoles((current) => ({
            ...current,
            [roleName]: !current[roleName],
        }));
    };

    const toggleAcl = (key: string) => {
        setExpandedAcls((current) => ({
            ...current,
            [key]: !current[key],
        }));
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>User roles</CardTitle>
                <CardDescription>
                    Session access-lists from `user-role` blocks (skipping global-sacl and
                    apprf-*-sacl), with alias and netservice references resolved.
                </CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left">
                            <th className="px-2 py-2 font-medium">Role</th>
                            <th className="px-2 py-2 font-medium">Access-lists</th>
                            <th className="px-2 py-2 font-medium">Warnings</th>
                        </tr>
                    </thead>
                    <tbody>
                        {userRoles.length === 0 && (
                            <tr>
                                <td
                                    colSpan={3}
                                    className="text-muted-foreground px-2 py-6 text-center"
                                >
                                    No user roles found in the uploaded config.
                                </td>
                            </tr>
                        )}
                        {userRoles.map((role) => {
                            const roleExpanded = Boolean(expandedRoles[role.name]);

                            return (
                                <tr key={role.name} className="border-b align-top">
                                    <td className="px-2 py-2">
                                        <button
                                            type="button"
                                            className="inline-flex items-center gap-1 text-left text-sm font-medium"
                                            onClick={() => toggleRole(role.name)}
                                        >
                                            {roleExpanded ? (
                                                <ChevronDown className="size-3 shrink-0" />
                                            ) : (
                                                <ChevronRight className="size-3 shrink-0" />
                                            )}
                                            {role.name}
                                        </button>
                                        {roleExpanded && (
                                            <div className="mt-3 ml-4 space-y-2">
                                                {role.access_lists.length === 0 && (
                                                    <p className="text-muted-foreground text-xs">
                                                        No access-lists beyond the first two
                                                        session entries.
                                                    </p>
                                                )}
                                                {role.access_lists.map((accessList) => {
                                                    const aclKey = `${role.name}:${accessList.name}`;
                                                    const aclExpanded = Boolean(
                                                        expandedAcls[aclKey],
                                                    );

                                                    return (
                                                        <div key={aclKey}>
                                                            <button
                                                                type="button"
                                                                className="text-muted-foreground inline-flex items-center gap-1 text-xs"
                                                                onClick={() => toggleAcl(aclKey)}
                                                            >
                                                                {aclExpanded ? (
                                                                    <ChevronDown className="size-3" />
                                                                ) : (
                                                                    <ChevronRight className="size-3" />
                                                                )}
                                                                {accessList.name}
                                                                <span className="text-muted-foreground">
                                                                    ({accessList.rules.length}{' '}
                                                                    {accessList.rules.length === 1
                                                                        ? 'rule'
                                                                        : 'rules'}
                                                                    )
                                                                </span>
                                                            </button>
                                                            {aclExpanded && (
                                                                <AccessListRules
                                                                    accessList={accessList}
                                                                />
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-2 py-2 text-xs">
                                        {role.access_lists.length === 0
                                            ? '—'
                                            : role.access_lists.map((acl) => acl.name).join(', ')}
                                    </td>
                                    <td className="px-2 py-2">
                                        {role.warnings.length === 0 ? (
                                            <span className="text-muted-foreground text-xs">—</span>
                                        ) : (
                                            <ul className="list-inside list-disc text-xs text-amber-600">
                                                {role.warnings.map((warning) => (
                                                    <li key={warning}>{warning}</li>
                                                ))}
                                            </ul>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>

                <CentralRoleMappingSection userRoles={userRoles} />
            </CardContent>
        </Card>
    );
}
