type Client = {
    name: string;
    client_id: string;
    client_secret: string;
    customer_id: string;
    id: number;
    base_url: typeof base_urls[number];
}

const base_urls = ["ae1", "au1", "ca1", "de1", "de2", "de3", "gb1", "in", "jp1", "us1", "us2", "us4", "us5", "us6"] as const

export { type Client }
