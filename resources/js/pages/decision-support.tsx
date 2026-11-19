import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import ActionTrackerController from '@/actions/App/Http/Controllers/ActionTrackerController';
import DecisionSupportController from '@/actions/App/Http/Controllers/DecisionSupportController';
import SolarScenarioController from '@/actions/App/Http/Controllers/SolarScenarioController';
import BillingEvidenceController from '@/actions/App/Http/Controllers/BillingEvidenceController';
import DssConfigurationController from '@/actions/App/Http/Controllers/DssConfigurationController';
import ConservationCatalogue from '@/components/conservation-catalogue';
import DecisionSupportHistory from '@/components/decision-support-history';
import DecisionSupportInformation from '@/components/decision-support-information';
import HistoricalEffectiveRates from '@/components/historical-effective-rates';
import {
    EnergyFilters,
    EnergyFooter,
    EnergyHeader,
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
import type {
    ConservationCatalogueData,
    DecisionReadiness,
    ExploratoryHistory,
    EnergyBuilding,
    ForecastComparison,
    HistoricalRates,
} from '@/types';

type Recommendation = {
    id: number;
    version: number;
    ruleCode: string;
    subjectKey: string;
    title: string;
    targetMonth: string;
    forecastId: number;
    priority: string | null;
    message: string | null;
    evidence: Record<string, unknown> | null;
    sourceCurrent: boolean;
    policyCurrent: boolean;
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

// Evidence varies by rule. Preserve the supplied values and units, including zero.
function EvidenceValues({ value }: { value: unknown }) {
    if (value === null || value === undefined) return <>Unavailable</>;
    if (typeof value === 'boolean') return <>{value ? 'Yes' : 'No'}</>;
    if (Array.isArray(value))
        return (
            <ul className="list-disc pl-4">
                {value.map((part, i) => (
                    <li key={i}>
                        <EvidenceValues value={part} />
                    </li>
                ))}
            </ul>
        );
    if (typeof value === 'string' || typeof value === 'number')
        return <>{value}</>;
    if (typeof value !== 'object') return <>Unavailable</>;
    return (
        <dl className="space-y-1">
            {Object.entries(value).map(([key, part]) => (
                <div key={key} className="break-words">
                    <dt className="font-semibold first-letter:uppercase">
                        {key
                            .replace(/([a-z])([A-Z])/g, '$1 $2')
                            .replaceAll('_', ' ')
                            .replace(/\bkwh\b/gi, 'kWh')
                            .replace(/\bkw\b/gi, 'kW')
                            .replace(/\bphp\b/gi, 'PHP')}
                    </dt>
                    <dd>
                        <EvidenceValues value={part} />
                    </dd>
                </div>
            ))}
        </dl>
    );
}

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
    const page = usePage();
    const {
        buildings,
        selectedBuildingCode,
        latestVerifiedPeriod,
        comparison,
        readiness,
        exploratoryHistory,
        conservationCatalogue,
        historicalRates,
        recommendations,
        buildingId,
        evaluation,
        errors,
        owners,
        today,
        operationResult,
        dssAccess,
    } = page.props as unknown as {
        buildings: EnergyBuilding[];
        selectedBuildingCode: string | null;
        latestVerifiedPeriod: string | null;
        comparison: ForecastComparison | null;
        readiness: DecisionReadiness | null;
        exploratoryHistory: ExploratoryHistory | null;
        conservationCatalogue: ConservationCatalogueData | null;
        historicalRates: HistoricalRates | null;
        buildingId: number | null;
        evaluation: {
            available: boolean;
            canEvaluate: boolean;
            tariffActive: boolean;
            reason: string;
        };
        errors?: { evaluation?: string };
        recommendations: {
            data: Recommendation[];
            prev_page_url: string | null;
            next_page_url: string | null;
            total: number;
        };
        owners: Owner[];
        today: string;
        operationResult: string | null;
        dssAccess: { configuration: boolean; billingEvidence: boolean };
    };
    const [evaluating, setEvaluating] = useState(false);
    const runEvaluation = () => {
        if (
            !buildingId ||
            evaluating ||
            !evaluation.canEvaluate ||
            !evaluation.available
        )
            return;
        setEvaluating(true);
        router.post(
            DecisionSupportController.evaluate.url(buildingId),
            {},
            {
                preserveScroll: true,
                onFinish: () => setEvaluating(false),
            },
        );
    };
    const query = { building: selectedBuildingCode };
    const requested = new URL(
        page.url,
        'http://inertia.local',
    ).searchParams.get('view');
    const view =
        requested === 'usage' && exploratoryHistory
            ? 'usage'
            : requested === 'catalogue' && conservationCatalogue
              ? 'catalogue'
              : requested === 'rates' && historicalRates
                ? 'rates'
                : null;
    const modules = page.props.auth.user?.accessibleModules ?? [];
    const filters = (
        <EnergyFilters
            buildings={buildings}
            selected={selectedBuildingCode}
            latest={latestVerifiedPeriod}
            onChange={(building) =>
                router.get(
                    DecisionSupportController.index.url({
                        query: { building, view },
                    }),
                )
            }
        />
    );
    const detailLink = (target: string) =>
        DecisionSupportController.index({ query: { ...query, view: target } });

    if (view) {
        const titles = {
            usage: 'Explore Usage',
            catalogue: 'Conservation catalogue',
            rates: 'Historical billing rates',
        };
        const descriptions = {
            usage: 'Explore verified consumption and billed demand.',
            catalogue: 'Proposed conservation topics for manual review.',
            rates: 'Read-only rates from verified bills.',
        };
        return (
            <>
                <Head title={titles[view]} />
                <div className="flex min-w-0 flex-col gap-5 px-4 py-6 sm:px-7">
                    <Link
                        className="self-start text-sm font-semibold text-primary underline"
                        href={DecisionSupportController.index({ query })}
                    >
                        Back to Decision Support
                    </Link>
                    <EnergyHeader
                        title={titles[view]}
                        description={descriptions[view]}
                    />
                    {filters}
                    {view === 'usage' && (
                        <DecisionSupportHistory history={exploratoryHistory} />
                    )}
                    {view === 'catalogue' && (
                        <ConservationCatalogue
                            catalogue={conservationCatalogue}
                            comparison={comparison}
                            history={exploratoryHistory}
                        />
                    )}
                    {view === 'rates' && (
                        <HistoricalEffectiveRates history={historicalRates} />
                    )}
                    <EnergyFooter />
                </div>
            </>
        );
    }
    return (
        <>
            <Head title="Decision Support" />
            <div className="flex min-w-0 flex-1 flex-col gap-5 px-4 pt-6 pb-10 sm:px-7">
                <EnergyHeader
                    title="Decision Support"
                    description="Review this building’s monthly evidence and recommendations."
                />
                {filters}
                {operationResult && (
                    <p role="status" className="text-sm text-primary">
                        {operationResult}
                    </p>
                )}
                <DecisionSupportInformation
                    comparison={comparison}
                    readiness={readiness}
                    showCost={historicalRates !== null}
                    tariffActive={evaluation.tariffActive}
                />
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <CardTitle className="text-[17px]">
                                <h2>Explainable recommendations</h2>
                            </CardTitle>
                            {evaluation.canEvaluate && (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={runEvaluation}
                                    disabled={
                                        evaluating || !evaluation.available
                                    }
                                >
                                    {evaluating
                                        ? 'Evaluating…'
                                        : 'Run evaluation'}
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        {!evaluation.available && (
                            <p className="mb-3 text-sm">{evaluation.reason}</p>
                        )}
                        {errors?.evaluation && (
                            <p
                                role="alert"
                                className="mb-3 text-sm text-destructive"
                            >
                                {errors.evaluation}
                            </p>
                        )}
                        {recommendations.data.length === 0 ? (
                            evaluation.available && (
                                <p className="text-sm">
                                    No evaluated recommendations for this
                                    building.
                                    {evaluation.canEvaluate &&
                                        ' Run evaluation to check the activated rules.'}
                                </p>
                            )
                        ) : (
                            <div
                                className="overflow-x-auto focus-visible:ring-2 focus-visible:ring-ring"
                                role="region"
                                aria-label="Recommendation review table"
                                tabIndex={0}
                            >
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
                                        {recommendations.data.map((item) => (
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
                                                        {item.subjectKey ===
                                                        'building'
                                                            ? 'Building'
                                                            : item.subjectKey
                                                                  .replace(
                                                                      'meter:',
                                                                      'Meter #',
                                                                  )
                                                                  .replace(
                                                                      'catalogue:',
                                                                      'Catalogue ',
                                                                  )}{' '}
                                                        ·{' '}
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
                                                            {!item.policyCurrent && (
                                                                <p className="font-semibold">
                                                                    Historical
                                                                    evidence ·
                                                                    not current
                                                                    advice
                                                                </p>
                                                            )}
                                                            <p>
                                                                {item.message}
                                                            </p>
                                                            <div className="mt-2">
                                                                <EvidenceValues
                                                                    value={
                                                                        item.evidence
                                                                    }
                                                                />
                                                            </div>
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
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
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
                <nav
                    aria-label="Follow-up and tools"
                    className="flex flex-wrap items-center gap-3"
                >
                    {modules.includes('action_tracker') && (
                        <Button asChild>
                            <Link
                                href={ActionTrackerController.index({ query })}
                            >
                                Review actions
                            </Link>
                        </Button>
                    )}
                    {exploratoryHistory && (
                        <Button asChild variant="outline">
                            <Link href={detailLink('usage')}>
                                Explore Usage
                            </Link>
                        </Button>
                    )}
                    {conservationCatalogue && (
                        <Button asChild variant="outline">
                            <Link href={detailLink('catalogue')}>
                                Conservation catalogue
                            </Link>
                        </Button>
                    )}
                    {historicalRates && (
                        <Button asChild variant="outline">
                            <Link href={detailLink('rates')}>
                                Historical billing rates
                            </Link>
                        </Button>
                    )}
                    {modules.includes('solar_scenarios') && (
                        <Button asChild variant="outline">
                            <Link
                                href={SolarScenarioController.index({ query })}
                            >
                                Solar &amp; Scenarios
                            </Link>
                        </Button>
                    )}
                </nav>
                {(dssAccess.configuration || dssAccess.billingEvidence) && (
                    <details className="text-sm">
                        <summary className="cursor-pointer font-semibold text-primary">
                            Advanced workflows
                        </summary>
                        <nav
                            aria-label="Advanced workflows"
                            className="mt-3 flex flex-wrap gap-4 text-primary underline"
                        >
                            {dssAccess.billingEvidence && (
                                <Link
                                    href={BillingEvidenceController.index({
                                        query,
                                    })}
                                >
                                    Billing evidence
                                </Link>
                            )}
                            {dssAccess.configuration && (
                                <Link
                                    href={DssConfigurationController.index({
                                        query,
                                    })}
                                >
                                    Configuration review
                                </Link>
                            )}
                        </nav>
                    </details>
                )}
                <EnergyFooter />
            </div>
        </>
    );
}
