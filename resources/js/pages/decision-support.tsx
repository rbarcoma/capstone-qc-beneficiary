import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import DecisionSupportController from '@/actions/App/Http/Controllers/DecisionSupportController';
import SolarScenarioController from '@/actions/App/Http/Controllers/SolarScenarioController';
import {
    EnergyFilters,
    EnergyFooter,
    EnergyHeader,
    EnergyMetric,
    EnergyNotice,
} from '@/components/energy-workspace';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EnergyBuilding } from '@/types';

type Recommendation = {
    id: number;
    version: number;
    ruleCode: string;
    title: string;
    targetMonth: string;
    forecastId: number;
    priority: string | null;
    message: string | null;
    evidence: Record<string, unknown> | null;
    sourceCurrent: boolean;
    decision: string;
    evaluationStatus: string;
    canDecide: boolean;
    blockedReason: string | null;
    history: {
        id: number;
        actor_id: number | null;
        from_state: string;
        to_state: string;
        note: string;
        recommendation_version: number;
        created_at: string;
    }[];
    action: {
        id: number;
        ownerName: string;
        dueDate: string;
        status: string;
    } | null;
};
type Owner = { id: number; name: string; role: string };

function RecommendationReview({
    item,
    owners,
    today,
}: {
    item: Recommendation;
    owners: Owner[];
    today: string;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        version: item.version,
        decision: 'deferred',
        note: '',
        owner_id: '',
        due_date: '',
    });
    return (
        <>
            <p className="capitalize">{item.decision}</p>
            {item.blockedReason && (
                <p className="mt-1 max-w-60 text-[11px] text-muted-foreground">
                    {item.blockedReason}
                </p>
            )}
            {item.canDecide && (
                <Button
                    size="sm"
                    variant="outline"
                    className="mt-2"
                    onClick={() => {
                        form.setData('version', item.version);
                        form.clearErrors();
                        setOpen(true);
                    }}
                >
                    Review decision
                </Button>
            )}
            {item.action && (
                <p className="mt-2 text-xs">
                    Action #{item.action.id} · {item.action.ownerName}
                    <br />
                    Due {item.action.dueDate} · {item.action.status}
                </p>
            )}
            {item.history.length > 0 && (
                <details className="mt-3 max-w-64">
                    <summary className="cursor-pointer text-primary">
                        Decision history ({item.history.length})
                    </summary>
                    <ol className="mt-2 space-y-3">
                        {item.history.map((entry) => (
                            <li key={entry.id} className="break-words">
                                <p className="font-semibold">
                                    {entry.from_state} → {entry.to_state}
                                </p>
                                <p>{entry.note}</p>
                                <p className="text-[11px] text-muted-foreground">
                                    Actor #{entry.actor_id ?? 'former user'} ·
                                    version {entry.recommendation_version}
                                    <br />
                                    {new Date(
                                        entry.created_at,
                                    ).toLocaleString()}
                                </p>
                            </li>
                        ))}
                    </ol>
                </details>
            )}
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!form.processing) setOpen(value);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Review {item.ruleCode}: {item.title}
                        </DialogTitle>
                        <DialogDescription>
                            Review the supporting evidence before deciding.
                            Acceptance requires an owner and due date. Accepted
                            and rejected decisions are final.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(
                                DecisionSupportController.decide.url(item.id),
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setOpen(false);
                                        form.reset();
                                        toast.success(
                                            'Decision recorded and audited.',
                                        );
                                    },
                                },
                            );
                        }}
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor={`decision-${item.id}`}>
                                Decision
                            </Label>
                            <select
                                id={`decision-${item.id}`}
                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                value={form.data.decision}
                                onChange={(event) =>
                                    form.setData('decision', event.target.value)
                                }
                            >
                                <option value="deferred">Defer</option>
                                <option value="accepted">Accept</option>
                                <option value="rejected">Reject</option>
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor={`note-${item.id}`}>
                                Decision reason
                            </Label>
                            <textarea
                                id={`note-${item.id}`}
                                required
                                maxLength={2000}
                                className="min-h-24 w-full rounded-md border bg-background p-3 text-sm"
                                value={form.data.note}
                                onChange={(event) =>
                                    form.setData('note', event.target.value)
                                }
                            />
                        </div>
                        {form.data.decision === 'accepted' && (
                            <>
                                <div className="space-y-1.5">
                                    <Label htmlFor={`owner-${item.id}`}>
                                        Action owner
                                    </Label>
                                    <select
                                        id={`owner-${item.id}`}
                                        required
                                        className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                        value={form.data.owner_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'owner_id',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="">
                                            Choose an owner
                                        </option>
                                        {owners
                                            .filter(
                                                (owner) =>
                                                    !(
                                                        owner.role ===
                                                            'facilities_personnel' &&
                                                        item.ruleCode === 'R1'
                                                    ),
                                            )
                                            .map((owner) => (
                                                <option
                                                    key={owner.id}
                                                    value={owner.id}
                                                >
                                                    {owner.name}
                                                </option>
                                            ))}
                                    </select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor={`due-${item.id}`}>
                                        Due date (Asia/Manila)
                                    </Label>
                                    <Input
                                        id={`due-${item.id}`}
                                        type="date"
                                        required
                                        min={today}
                                        value={form.data.due_date}
                                        onChange={(event) =>
                                            form.setData(
                                                'due_date',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </>
                        )}
                        {Object.entries(form.errors).map(([key, message]) => (
                            <InputError
                                key={key}
                                role="alert"
                                message={message}
                            />
                        ))}
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing
                                    ? 'Recording…'
                                    : 'Record decision'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

export default function DecisionSupport() {
    const {
        buildings,
        selectedBuildingCode,
        latestVerifiedPeriod,
        currentForecastId,
        policyAvailable,
        recommendations,
        owners,
        counts,
        today,
        operationResult,
    } = usePage().props as unknown as {
        buildings: EnergyBuilding[];
        selectedBuildingCode: string | null;
        latestVerifiedPeriod: string | null;
        currentForecastId: number | null;
        policyAvailable: boolean;
        recommendations: {
            data: Recommendation[];
            prev_page_url: string | null;
            next_page_url: string | null;
            total: number;
        };
        owners: Owner[];
        counts: { evaluated: number; high: number; accepted: number };
        today: string;
        operationResult: string | null;
    };
    return (
        <>
            <Head title="Decision Support" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Decision Support"
                    description="Transparent rule evidence, management decisions and conservation actions."
                    actions={
                        <Button variant="outline" asChild>
                            <Link
                                href={SolarScenarioController.index({
                                    query: { building: selectedBuildingCode },
                                })}
                            >
                                Solar &amp; Scenarios
                            </Link>
                        </Button>
                    }
                />
                <EnergyFilters
                    buildings={buildings}
                    selected={selectedBuildingCode}
                    latest={latestVerifiedPeriod}
                    onChange={(building) =>
                        router.get(DecisionSupportController.index.url(), {
                            building,
                        })
                    }
                />
                {!policyAvailable && (
                    <EnergyNotice title="Rule policy unavailable" warning>
                        The R1–R7 mapping and numeric trigger contract require
                        reconciliation (C5). Rule generation and decisions are
                        unavailable until a reviewed policy evaluator exists. No
                        priority or tariff is inferred.
                    </EnergyNotice>
                )}
                {!currentForecastId && (
                    <EnergyNotice title="No current issued forecast">
                        Recommendations require a current, eligible issued
                        forecast and source-matched evaluated evidence.
                        Historical decisions remain retained.
                    </EnergyNotice>
                )}
                {operationResult && (
                    <p role="status" className="text-sm text-primary">
                        {operationResult}
                    </p>
                )}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <EnergyMetric
                        title="Evaluated rules"
                        value={counts.evaluated}
                        caption="Current source and policy"
                    />
                    <EnergyMetric
                        title="High-priority"
                        value={counts.high}
                        caption="Evaluated policy results only"
                    />
                    <EnergyMetric
                        title="Accepted"
                        value={counts.accepted}
                        caption="Current source and policy"
                    />
                    <EnergyMetric
                        title="Solar assessment"
                        value="Screening only"
                    />
                </div>
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Explainable recommendations
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[780px] text-left text-xs">
                                <thead className="bg-secondary text-[11px] text-primary">
                                    <tr>
                                        {[
                                            'Rule / topic',
                                            'Priority',
                                            'Evidence',
                                            'Decision / review',
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
                                    {recommendations.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="px-3 py-6 text-muted-foreground"
                                            >
                                                No evaluated recommendations
                                                available for this building. A
                                                data-readiness notice is not a
                                                fired rule.
                                            </td>
                                        </tr>
                                    ) : (
                                        recommendations.data.map((item) => (
                                            <tr
                                                key={item.id}
                                                className="border-b align-top"
                                            >
                                                <td className="w-1/5 px-3 py-4">
                                                    <Badge variant="secondary">
                                                        {item.ruleCode}
                                                    </Badge>
                                                    <p className="mt-2 font-semibold">
                                                        {item.title}
                                                    </p>
                                                    <p className="mt-1 text-muted-foreground">
                                                        Run #{item.forecastId} ·{' '}
                                                        {item.targetMonth.slice(
                                                            0,
                                                            7,
                                                        )}
                                                    </p>
                                                </td>
                                                <td className="px-3 py-4 capitalize">
                                                    {item.priority ??
                                                        'Not assessed'}
                                                </td>
                                                <td className="max-w-md px-3 py-4">
                                                    {item.sourceCurrent ? (
                                                        <>
                                                            <p>
                                                                {item.message}
                                                            </p>
                                                            <details className="mt-2">
                                                                <summary className="cursor-pointer text-primary">
                                                                    Supporting
                                                                    numbers
                                                                </summary>
                                                                <pre className="mt-2 max-w-full rounded-md bg-secondary p-3 text-[11px] break-all whitespace-pre-wrap">
                                                                    {JSON.stringify(
                                                                        item.evidence,
                                                                        null,
                                                                        2,
                                                                    )}
                                                                </pre>
                                                            </details>
                                                        </>
                                                    ) : (
                                                        <p className="text-muted-foreground">
                                                            Source superseded.
                                                            Historical evidence
                                                            is retained,
                                                            excluded from
                                                            current advice.
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-3 py-4">
                                                    <RecommendationReview
                                                        key={`${item.id}-${item.version}`}
                                                        item={item}
                                                        owners={owners}
                                                        today={today}
                                                    />
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {(recommendations.prev_page_url ||
                            recommendations.next_page_url) && (
                            <nav
                                aria-label="Recommendations pages"
                                className="mt-4 flex gap-4 text-xs"
                            >
                                {recommendations.prev_page_url && (
                                    <Link href={recommendations.prev_page_url}>
                                        Previous
                                    </Link>
                                )}
                                {recommendations.next_page_url && (
                                    <Link href={recommendations.next_page_url}>
                                        Next
                                    </Link>
                                )}
                            </nav>
                        )}
                    </CardContent>
                </Card>
                <EnergyNotice title="Forecasts estimate; rules explain">
                    Every recommendation must show the supporting numbers. The
                    Energy Manager accepts, rejects or defers. Acceptance
                    creates one action with an explicit owner and due date; no
                    DSS outcome is automatically a procurement decision.
                </EnergyNotice>
                <EnergyFooter />
            </div>
        </>
    );
}
