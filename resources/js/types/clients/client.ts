type Client = {
    name: string;
    client_id: string;
    client_secret: string;
    customer_id: string;
    id: number;
    base_url: typeof base_urls[number];
    classic_client_id?: string | null;
    classic_client_secret?: string | null;
    classic_username?: string | null;
    classic_password?: string | null;
    has_classic_refresh_token?: boolean;
    has_classic_access_token?: boolean;
    classic_expires_in?: string | null;
    classic_webhook_url?: string;
    has_classic_webhook_secret?: boolean;
    classic_webhook_wid?: string | null;
}

const base_urls = ["ae1", "au1", "ca1", "de1", "de2", "de3", "gb1", "in", "jp1", "us1", "us2", "us4", "us5", "us6"] as const

export { type Client }
