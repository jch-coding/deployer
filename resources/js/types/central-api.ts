export type CentralApiParameter = {
    in: string;
    name: string;
    required: boolean;
    description: string | null;
    schema: {
        type?: string;
        default?: string | number | boolean;
    };
};

export type CentralApiOperation = {
    operation_id: string;
    method: string;
    path: string;
    summary: string | null;
    description: string | null;
    tags: string[];
    parameters: CentralApiParameter[];
    requires_body: boolean;
    reference_url: string | null;
};

export type CentralApiTag = {
    name: string;
    description: string | null;
};

export type CentralApiDeviceOption = {
    id: number;
    serial: string;
    name: string;
    scope_id: string | null;
    device_function: string;
};

export type CentralApiExecuteResponse = {
    ok: boolean;
    status: number | null;
    duration_ms: number;
    headers: Record<string, string>;
    body: unknown;
    request_url: string | null;
    error: string | null;
};
