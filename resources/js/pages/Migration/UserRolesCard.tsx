import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type {
    AccessList,
    AccessListEndpoint,
    AccessListService,
    NetDestination,
    NetService,
    UserRole,
} from '@/pages/Migration/migration-types';

type UserRolesCardProps = {
    userRoles: UserRole[];
};

function formatEndpoint(endpoint: AccessListEndpoint): string {
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

function formatService(service: AccessListService): string {
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
                <div className="text-amber-600 mt-1 text-xs">Unresolved alias</div>
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
                <div className="text-amber-600 mt-1 text-xs">Unresolved service</div>
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
            </CardContent>
        </Card>
    );
}
