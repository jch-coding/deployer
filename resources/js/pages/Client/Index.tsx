type Client = {
    name: string;
    client_id: string;
    client_secret: string;
    customer_id: string;
}

type ClientIndexProps = {
    clients: Client[];
}
export default function Index({ clients }: ClientIndexProps ) {
    return (
        <div>
            <h1>Clients</h1>
            <ul>
                {clients.map(client => (
                    <li key={client.client_id}>{client.name}</li>
                ))}
            </ul>
        </div>
    )
}
