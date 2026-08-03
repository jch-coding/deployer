import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

import type { RadioProfile } from './migration-types';

type RadioProfilesCardProps = {
    radioProfiles: RadioProfile[];
};

function bandLabel(band: string): string {
    switch (band) {
        case 'a':
            return '5 GHz (a)';
        case 'g':
            return '2.4 GHz (g)';
        default:
            return band;
    }
}

export default function RadioProfilesCard({ radioProfiles }: RadioProfilesCardProps) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Radio Profiles</CardTitle>
                <CardDescription>
                    RF radio profiles referenced by AP groups from `show ap database long`, with
                    EIRP settings from running-config.
                </CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                {radioProfiles.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No radio profiles found for the AP groups in this dump.
                    </p>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="px-2 py-2 font-medium">AP Group</th>
                                <th className="px-2 py-2 font-medium">Profile</th>
                                <th className="px-2 py-2 font-medium">Band</th>
                                <th className="px-2 py-2 font-medium">EIRP Min</th>
                                <th className="px-2 py-2 font-medium">EIRP Max</th>
                            </tr>
                        </thead>
                        <tbody>
                            {radioProfiles.map((profile) => (
                                <tr
                                    key={`${profile.ap_group}-${profile.band}-${profile.profile_name}`}
                                    className="border-b"
                                >
                                    <td className="px-2 py-2 font-mono text-xs">
                                        {profile.ap_group}
                                    </td>
                                    <td className="px-2 py-2 font-mono text-xs">
                                        {profile.profile_name}
                                    </td>
                                    <td className="px-2 py-2">{bandLabel(profile.band)}</td>
                                    <td className="px-2 py-2">
                                        {profile.eirp_min ?? '—'}
                                    </td>
                                    <td className="px-2 py-2">
                                        {profile.eirp_max ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </CardContent>
        </Card>
    );
}
