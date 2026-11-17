import { Head } from '@inertiajs/react';
import { useState } from 'react';
import SystemHealthController from '@/actions/App/Http/Controllers/SystemHealthController';
import {
    EnergyFooter,
    EnergyHeader,
    EnergyMetric,
    EnergyNotice,
} from '@/components/energy-workspace';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Observation = { name: string; state: string; detail: string };
type Check = Observation & { id: string };
type Snapshot = {
    startedAt: string;
    checkedAt: string;
    checks: Check[];
    observations: Observation[];
};

const readiness = [
    ['Hosting on-premise or approved cloud', 'City ICT', 'Decision pending'],
    [
        'DPO assessment and real-data authorization',
        'Data Protection Officer / sponsor',
        'Required',
    ],
    ['Encrypted backup / restore drill', 'City ICT', 'Unverified'],
    ['External application / server monitoring', 'City ICT', 'Unverified'],
    [
        'Model lifecycle and version audit',
        'Energy Manager',
        'Versioned runs implemented; operational review required',
    ],
];

export default function SystemHealth() {
    const [snapshot, setSnapshot] = useState<Snapshot | null>(null);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');
    async function runChecks() {
        if (pending) return;
        setPending(true);
        setSnapshot(null);
        setError('');
        try {
            const response = await fetch(SystemHealthController.checks.url(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok)
                throw new Error(
                    response.status === 403
                        ? 'Your Admin access changed. Reload the page.'
                        : response.status === 429
                          ? 'Check limit reached. Wait a minute before retrying.'
                          : 'Health checks could not be loaded. Retry when the application is reachable.',
                );
            const result = await response.json();
            if (
                !Array.isArray(result.checks) ||
                !Array.isArray(result.observations) ||
                typeof result.checkedAt !== 'string'
            )
                throw new Error(
                    'Unexpected check response. Sign in again and retry.',
                );
            setSnapshot(result);
        } catch (error) {
            setError(
                error instanceof Error
                    ? error.message
                    : 'Health checks could not be loaded. Retry.',
            );
        } finally {
            setPending(false);
        }
    }
    return (
        <>
            <Head title="System Health" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="System Health"
                    description="Deployment, monitoring, backups and measured application diagnostics."
                    actions={
                        <Button onClick={runChecks} disabled={pending}>
                            {pending ? 'Checking…' : 'Run checks'}
                        </Button>
                    }
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <EnergyMetric
                        title="Production hosting"
                        value="Undecided"
                        caption="City ICT decision required"
                    />
                    <EnergyMetric
                        title="HTTPS / firewall"
                        value="Unverified"
                        caption="Infrastructure evidence required"
                    />
                    <EnergyMetric
                        title="Encrypted backups"
                        value="Unverified"
                        caption="Restore drill not recorded"
                    />
                    <EnergyMetric
                        title="Queue / scheduler"
                        value="Unverified"
                        caption="No monitored heartbeat"
                    />
                </div>
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Deployment readiness
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div
                            className="overflow-x-auto focus-visible:ring-2 focus-visible:ring-ring"
                            tabIndex={0}
                            role="region"
                            aria-label="Deployment readiness table"
                        >
                            <table className="w-full min-w-[700px] text-left text-xs">
                                <thead className="bg-secondary text-[11px] text-primary">
                                    <tr>
                                        {[
                                            'Control',
                                            'Intended responsibility',
                                            'Current status',
                                        ].map((title) => (
                                            <th
                                                key={title}
                                                className="px-3 py-3 font-semibold"
                                            >
                                                {title}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {readiness.map((row) => (
                                        <tr key={row[0]} className="border-b">
                                            {row.map((cell) => (
                                                <td
                                                    key={cell}
                                                    className="max-w-72 px-3 py-4"
                                                >
                                                    {cell}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
                <Card
                    className="min-w-0 rounded-[13px] shadow-none"
                    aria-busy={pending}
                >
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            On-demand application checks
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-xs text-muted-foreground">
                            Checks run only when requested. Database reads,
                            local directory permissions and a Python
                            import/estimator smoke check are point-in-time
                            observations, not proof of production readiness. The
                            Python check has a 5-second limit. No bills,
                            forecasts, email or queue jobs are created.
                        </p>
                        <InputError role="alert" message={error} />
                        <div
                            role="status"
                            className="text-xs text-muted-foreground"
                        >
                            {pending
                                ? 'Running application diagnostics…'
                                : snapshot
                                  ? `Checked ${new Date(snapshot.checkedAt).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })} · Asia/Manila. Refresh by running checks again.`
                                  : 'Not checked in this view.'}
                        </div>
                        {snapshot && (
                            <div className="space-y-3">
                                {[
                                    ...snapshot.checks,
                                    ...snapshot.observations,
                                ].map((item) => (
                                    <div
                                        key={item.name}
                                        className="rounded-[9px] border p-4 text-xs"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <h3 className="font-semibold">
                                                {item.name}
                                            </h3>
                                            <Badge
                                                variant={
                                                    item.state === 'Failed'
                                                        ? 'destructive'
                                                        : 'secondary'
                                                }
                                            >
                                                {item.state}
                                            </Badge>
                                        </div>
                                        <p className="mt-2 leading-5 text-muted-foreground">
                                            {item.detail}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
                <EnergyNotice title="Public hosting restriction" warning>
                    Confidential Meralco billing data should not be placed on
                    free public hosting. Use only sample or anonymized content
                    for capstone demonstration. Hosting, DPO authorization,
                    backups and operational acceptance remain City Hall
                    decisions. These checks do not approve deployment or enable
                    C5 rule generation.
                </EnergyNotice>
                <EnergyFooter />
            </div>
        </>
    );
}
