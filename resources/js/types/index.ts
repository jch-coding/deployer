export type * from './auth';
export type * from './navigation';
export type * from './ui';

import { type Client } from '@/types/clients/client';
import type { Auth } from './auth';

export type SharedData = {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    current_client: Client | null;
    flash?: {
        success?: string | null;
        error?: string | null;
    };
    [key: string]: unknown;
};
