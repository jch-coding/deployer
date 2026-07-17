import { Head, usePage } from '@inertiajs/react';
import { Braces, ExternalLink, Loader2, Play, Search } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { csrfHeaders } from '@/lib/csrf';
import AppLayout from '@/layouts/app-layout';
import ScopeContextPicker from '@/components/central/ScopeContextPicker';
import type {
    CentralScopeCacheMeta,
    CentralScopeGroupsCacheMeta,
} from '@/components/central/CentralScopeRefreshButtons';
import { index as clientsIndex } from '@/routes/clients';
import { execute as centralApiExecute, index as centralApiIndex } from '@/routes/central-api';
import type {
    CentralApiDeviceOption,
    CentralApiExecuteResponse,
    CentralApiOperation,
    CentralApiParameter,
    CentralApiScopeOption,
    CentralApiTag,
} from '@/types/central-api';
import type { BreadcrumbItem, SharedData } from '@/types';

type ExplorerProps = {
    tags: CentralApiTag[];
    operations_by_tag: Record<string, CentralApiOperation[]>;
    device_options: CentralApiDeviceOption[];
    scope_sites: CentralApiScopeOption[];
    scope_groups: CentralApiScopeOption[];
    scope_site_collections: CentralApiScopeOption[];
    scope_sites_error: string | null;
    scope_groups_error: string | null;
    scope_site_collections_error: string | null;
    central_sites_cache: CentralScopeCacheMeta;
    central_groups_cache: CentralScopeGroupsCacheMeta;
    device_function_options: string[];
    base_url_display: string;
    docs_url: string;
} & SharedData;

function defaultParamValue(parameter: CentralApiParameter): string {
    const schemaDefault = parameter.schema?.default;

    if (schemaDefault !== undefined && schemaDefault !== null) {
        return String(schemaDefault);
    }

    if (parameter.name === 'limit') {
        return '100';
    }

    if (parameter.name === 'offset') {
        return '0';
    }

    return '';
}

const DEVICE_FUNCTION_NONE = '__none__';

function deviceFunctionSelectOptions(
    options: string[],
    currentValue: string,
): string[] {
    const trimmed = currentValue.trim();

    if (trimmed !== '' && !options.includes(trimmed)) {
        return [...options, trimmed];
    }

    return options;
}

function buildInitialParams(operation: CentralApiOperation | null): Record<string, string> {
    if (!operation) {
        return {};
    }

    const values: Record<string, string> = {};

    for (const parameter of operation.parameters) {
        values[parameter.name] = defaultParamValue(parameter);
    }

    return values;
}

function buildInitialBody(operation: CentralApiOperation | null): string {
    if (!operation?.requires_body) {
        return '';
    }

    return '{\n  \n}';
}

function statusBadgeVariant(status: number | null): 'default' | 'destructive' | 'secondary' {
    if (status === null || status === 0) {
        return 'secondary';
    }

    if (status >= 200 && status < 300) {
        return 'default';
    }

    return 'destructive';
}

function formatStatusLabel(status: number | null, ok: boolean): string {
    if (status === null || status === 0) {
        return ok ? 'OK' : 'Failed';
    }

    return String(status);
}

export default function Explorer() {
    const {
        current_client,
        tags,
        operations_by_tag,
        device_options,
        scope_sites,
        scope_groups,
        scope_site_collections,
        scope_sites_error,
        scope_groups_error,
        scope_site_collections_error,
        central_sites_cache,
        central_groups_cache,
        device_function_options,
        base_url_display,
        docs_url,
    } = usePage<ExplorerProps>().props;

    const allOperations = useMemo(
        () => Object.values(operations_by_tag).flat(),
        [operations_by_tag],
    );

    const [search, setSearch] = useState('');
    const [selectedOperationId, setSelectedOperationId] = useState<string | null>(
        allOperations[0]?.operation_id ?? null,
    );
    const [paramValues, setParamValues] = useState<Record<string, string>>(() =>
        buildInitialParams(allOperations[0] ?? null),
    );
    const [requestBody, setRequestBody] = useState<string>(() =>
        buildInitialBody(allOperations[0] ?? null),
    );
    const [bodyError, setBodyError] = useState<string | null>(null);
    const [selectedDeviceId, setSelectedDeviceId] = useState<string>('');
    const [isExecuting, setIsExecuting] = useState(false);
    const [response, setResponse] = useState<CentralApiExecuteResponse | null>(null);

    const selectedOperation = useMemo(
        () => allOperations.find((op) => op.operation_id === selectedOperationId) ?? null,
        [allOperations, selectedOperationId],
    );

    const operationHasScopeId = useMemo(
        () =>
            selectedOperation?.parameters.some((parameter) => parameter.name === 'scope-id') ??
            false,
        [selectedOperation],
    );

    const filteredOperationsByTag = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') {
            return operations_by_tag;
        }

        const filtered: Record<string, CentralApiOperation[]> = {};

        for (const [tag, operations] of Object.entries(operations_by_tag)) {
            const matches = operations.filter((operation) => {
                const haystack = [
                    operation.operation_id,
                    operation.summary,
                    operation.description,
                    operation.path,
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();

                return haystack.includes(needle);
            });

            if (matches.length > 0) {
                filtered[tag] = matches;
            }
        }

        return filtered;
    }, [operations_by_tag, search]);

    const selectOperation = useCallback((operation: CentralApiOperation) => {
        setSelectedOperationId(operation.operation_id);
        setParamValues(buildInitialParams(operation));
        setRequestBody(buildInitialBody(operation));
        setBodyError(null);
        setResponse(null);
    }, []);

    const selectedDevice = useMemo(
        () => device_options.find((d) => String(d.id) === selectedDeviceId) ?? null,
        [device_options, selectedDeviceId],
    );

    const applyDeviceContext = useCallback(() => {
        if (!selectedDevice) {
            return;
        }

        setParamValues((prev) => ({
            ...prev,
            serial: selectedDevice.serial,
            'scope-id': selectedDevice.scope_id ?? '',
            'device-function': selectedDevice.device_function,
            'view-type': 'LOCAL',
            'object-type': 'LOCAL',
        }));
    }, [selectedDevice]);

    const applyScopeContext = useCallback((scopeId: string) => {
        setParamValues((prev) => ({
            ...prev,
            'scope-id': scopeId,
        }));
    }, []);

    const executeRequest = useCallback(async () => {
        if (!selectedOperation) {
            return;
        }

        setIsExecuting(true);
        setResponse(null);
        setBodyError(null);

        const query: Record<string, string> = {};

        for (const [key, value] of Object.entries(paramValues)) {
            if (value.trim() !== '') {
                query[key] = value.trim();
            }
        }

        let parsedBody: Record<string, unknown> | undefined;

        if (selectedOperation.requires_body) {
            const trimmedBody = requestBody.trim();

            if (trimmedBody === '') {
                setBodyError('Request body is required for this operation.');
                setIsExecuting(false);

                return;
            }

            try {
                const decoded = JSON.parse(trimmedBody) as unknown;

                if (decoded === null || typeof decoded !== 'object' || Array.isArray(decoded)) {
                    setBodyError('Request body must be a JSON object.');
                    setIsExecuting(false);

                    return;
                }

                parsedBody = decoded as Record<string, unknown>;
            } catch {
                setBodyError('Request body must be valid JSON.');
                setIsExecuting(false);

                return;
            }
        }

        try {
            const res = await fetch(centralApiExecute.url(), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    operation_id: selectedOperation.operation_id,
                    query,
                    body: parsedBody,
                }),
            });

            const parsed = (await res.json()) as CentralApiExecuteResponse & {
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (typeof parsed.ok !== 'boolean') {
                const validationMessage =
                    parsed.message ??
                    (parsed.errors
                        ? Object.values(parsed.errors).flat().join(' ')
                        : 'Request failed.');

                setResponse({
                    ok: false,
                    status: res.status,
                    duration_ms: 0,
                    headers: {},
                    body: parsed,
                    request_url: null,
                    error: validationMessage,
                });
            } else {
                setResponse(parsed);
            }
        } catch {
            setResponse({
                ok: false,
                status: 0,
                duration_ms: 0,
                headers: {},
                body: { message: 'Could not read the server response.' },
                request_url: null,
                error: 'Could not read the server response.',
            });
        } finally {
            setIsExecuting(false);
        }
    }, [paramValues, requestBody, selectedOperation]);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
        {
            title: 'Central API',
            href: centralApiIndex().url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Central API" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Central API Explorer</h1>
                        <p className="text-muted-foreground text-sm">
                            Test New Central Configuration API endpoints using credentials for{' '}
                            <span className="font-medium text-foreground">{current_client?.name}</span>.
                        </p>
                        <p className="text-muted-foreground mt-1 font-mono text-xs">{base_url_display}</p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <a href={docs_url} target="_blank" rel="noreferrer">
                            Developer Hub
                            <ExternalLink className="ml-2 size-3.5" />
                        </a>
                    </Button>
                </div>

                <div className="grid min-h-[32rem] gap-4 lg:grid-cols-12">
                    <div className="flex flex-col gap-2 lg:col-span-3">
                        <div className="relative">
                            <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                            <Input
                                className="pl-9"
                                placeholder="Search endpoints…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                        <div className="h-[28rem] overflow-y-auto rounded-md border">
                            <div className="space-y-3 p-2">
                                {tags.map((tag) => {
                                    const operations = filteredOperationsByTag[tag.name];

                                    if (!operations?.length) {
                                        return null;
                                    }

                                    return (
                                        <div key={tag.name}>
                                            <p className="text-muted-foreground px-2 py-1 text-xs font-semibold uppercase">
                                                {tag.name}
                                            </p>
                                            <ul className="space-y-0.5">
                                                {operations.map((operation) => {
                                                    const isSelected =
                                                        operation.operation_id === selectedOperationId;

                                                    return (
                                                        <li key={operation.operation_id}>
                                                            <button
                                                                type="button"
                                                                onClick={() => selectOperation(operation)}
                                                                className={`w-full rounded-md px-2 py-1.5 text-left text-sm transition-colors ${
                                                                    isSelected
                                                                        ? 'bg-primary text-primary-foreground'
                                                                        : 'hover:bg-muted'
                                                                }`}
                                                            >
                                                                <span className="font-mono text-xs uppercase opacity-80">
                                                                    {operation.method}
                                                                </span>
                                                                <span className="mt-0.5 block truncate">
                                                                    {operation.summary ?? operation.operation_id}
                                                                </span>
                                                            </button>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-col gap-4 lg:col-span-5">
                        {selectedOperation ? (
                            <>
                                <div className="rounded-md border p-4">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="outline">{selectedOperation.method}</Badge>
                                        <code className="text-xs break-all">{selectedOperation.path}</code>
                                    </div>
                                    <h2 className="mt-2 font-medium">
                                        {selectedOperation.summary ?? selectedOperation.operation_id}
                                    </h2>
                                    {selectedOperation.description && (
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            {selectedOperation.description}
                                        </p>
                                    )}
                                    {selectedOperation.reference_url && (
                                        <a
                                            href={selectedOperation.reference_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-primary mt-2 inline-flex items-center text-xs hover:underline"
                                        >
                                            Official reference
                                            <ExternalLink className="ml-1 size-3" />
                                        </a>
                                    )}
                                </div>

                                <div className="rounded-md border p-4">
                                    <h3 className="mb-3 text-sm font-medium">Device context</h3>
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                        <div className="flex-1 space-y-1">
                                            <Label htmlFor="device-select">Saved device</Label>
                                            <select
                                                id="device-select"
                                                className="border-input h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs"
                                                value={selectedDeviceId}
                                                onChange={(e) => setSelectedDeviceId(e.target.value)}
                                            >
                                                <option value="">Select a device…</option>
                                                {device_options.map((device) => (
                                                    <option key={device.id} value={String(device.id)}>
                                                        {device.name} ({device.serial})
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            disabled={!selectedDevice}
                                            onClick={applyDeviceContext}
                                        >
                                            Apply device context
                                        </Button>
                                    </div>
                                </div>

                                {operationHasScopeId && (
                                    <ScopeContextPicker
                                        scopeSites={scope_sites}
                                        scopeGroups={scope_groups}
                                        scopeSiteCollections={scope_site_collections}
                                        scopeSitesError={scope_sites_error}
                                        scopeGroupsError={scope_groups_error}
                                        scopeSiteCollectionsError={scope_site_collections_error}
                                        centralSitesCache={central_sites_cache}
                                        centralGroupsCache={central_groups_cache}
                                        onApply={applyScopeContext}
                                    />
                                )}

                                <div className="rounded-md border p-4">
                                    <h3 className="mb-3 text-sm font-medium">Parameters</h3>
                                    {selectedOperation.parameters.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">No parameters.</p>
                                    ) : (
                                        <div className="space-y-3">
                                            {selectedOperation.parameters.map((parameter) => (
                                                <div key={parameter.name} className="space-y-1">
                                                    <Label htmlFor={`param-${parameter.name}`}>
                                                        {parameter.name}
                                                        {parameter.in === 'path' && (
                                                            <span className="text-muted-foreground ml-1 text-xs">
                                                                (path)
                                                            </span>
                                                        )}
                                                        {parameter.required && (
                                                            <span className="text-destructive ml-1">*</span>
                                                        )}
                                                    </Label>
                                                    {parameter.description && (
                                                        <p className="text-muted-foreground text-xs">
                                                            {parameter.description}
                                                        </p>
                                                    )}
                                                    {parameter.name === 'device-function' ? (
                                                        <Select
                                                            value={
                                                                (paramValues[parameter.name] ?? '') === ''
                                                                    ? DEVICE_FUNCTION_NONE
                                                                    : (paramValues[parameter.name] ?? '')
                                                            }
                                                            onValueChange={(value) =>
                                                                setParamValues((prev) => ({
                                                                    ...prev,
                                                                    [parameter.name]:
                                                                        value === DEVICE_FUNCTION_NONE
                                                                            ? ''
                                                                            : value,
                                                                }))
                                                            }
                                                        >
                                                            <SelectTrigger
                                                                id={`param-${parameter.name}`}
                                                                className="w-full"
                                                            >
                                                                <SelectValue placeholder="Select device function…" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {!parameter.required && (
                                                                    <SelectItem value={DEVICE_FUNCTION_NONE}>
                                                                        None
                                                                    </SelectItem>
                                                                )}
                                                                {deviceFunctionSelectOptions(
                                                                    device_function_options,
                                                                    paramValues[parameter.name] ?? '',
                                                                ).map((option) => (
                                                                    <SelectItem key={option} value={option}>
                                                                        {option}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    ) : (
                                                        <Input
                                                            id={`param-${parameter.name}`}
                                                            value={paramValues[parameter.name] ?? ''}
                                                            onChange={(e) =>
                                                                setParamValues((prev) => ({
                                                                    ...prev,
                                                                    [parameter.name]: e.target.value,
                                                                }))
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                {selectedOperation.requires_body && (
                                    <div className="rounded-md border p-4">
                                        <h3 className="mb-3 text-sm font-medium">Request body</h3>
                                        <textarea
                                            id="request-body"
                                            className="border-input min-h-40 w-full rounded-md border bg-transparent px-3 py-2 font-mono text-xs shadow-xs"
                                            value={requestBody}
                                            onChange={(e) => {
                                                setRequestBody(e.target.value);
                                                setBodyError(null);
                                            }}
                                            spellCheck={false}
                                        />
                                        {bodyError && (
                                            <p className="text-destructive mt-2 text-sm">{bodyError}</p>
                                        )}
                                    </div>
                                )}

                                <div className="rounded-md border p-4">
                                    <Button
                                        type="button"
                                        onClick={() => void executeRequest()}
                                        disabled={isExecuting}
                                    >
                                        {isExecuting ? (
                                            <Loader2 className="size-4 animate-spin" />
                                        ) : (
                                            <Play className="size-4" />
                                        )}
                                        Send request
                                    </Button>
                                </div>
                            </>
                        ) : (
                            <div className="text-muted-foreground flex h-full items-center justify-center rounded-md border p-8 text-sm">
                                Select an endpoint from the list.
                            </div>
                        )}
                    </div>

                    <div className="flex flex-col gap-2 lg:col-span-4">
                        <h3 className="text-sm font-medium">Response</h3>
                        <div className="min-h-[28rem] rounded-md border">
                            {!response ? (
                                <div className="text-muted-foreground flex h-full items-center justify-center p-6 text-sm">
                                    <Braces className="mr-2 size-4 opacity-50" />
                                    Run a request to see the response.
                                </div>
                            ) : (
                                <div className="flex h-full flex-col gap-3 p-4">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant={statusBadgeVariant(response.status)}>
                                            {formatStatusLabel(response.status, response.ok)}
                                        </Badge>
                                        <span className="text-muted-foreground text-xs">
                                            {response.duration_ms} ms
                                        </span>
                                    </div>
                                    {response.request_url && (
                                        <p className="font-mono text-xs break-all text-muted-foreground">
                                            {response.request_url}
                                        </p>
                                    )}
                                    {!response.ok && (
                                        <p className="text-destructive text-sm">
                                            {response.error ??
                                                (typeof response.body === 'object' &&
                                                response.body !== null &&
                                                'message' in response.body &&
                                                typeof (response.body as { message?: string }).message ===
                                                    'string'
                                                    ? (response.body as { message: string }).message
                                                    : 'Request failed.')}
                                        </p>
                                    )}
                                    <div className="max-h-[20rem] flex-1 overflow-auto">
                                        <pre className="text-xs whitespace-pre-wrap">
                                            {JSON.stringify(response.body, null, 2)}
                                        </pre>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
