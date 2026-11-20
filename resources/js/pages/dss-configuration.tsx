import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import DssConfigurationController from '@/actions/App/Http/Controllers/DssConfigurationController';
import DecisionSupportController from '@/actions/App/Http/Controllers/DecisionSupportController';
import SolarScenarioController from '@/actions/App/Http/Controllers/SolarScenarioController';
import {
    EnergyFilters,
    EnergyHeader,
    EnergyNotice,
} from '@/components/energy-workspace';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EnergyBuilding } from '@/types';

type Kind = 'tariff' | 'rule' | 'catalogue' | 'review';
type Item = {
    id: number;
    version: number;
    stateVersion: number;
    kind: Kind;
    subjectKey: string;
    payload: Record<string, string | number | null>;
    contentHash: string;
    status: string;
    sourceCurrent: boolean;
    authorId: number;
    reviewerId: number | null;
    validFrom: string | null;
    validTo: string | null;
    sourceConfirmation: { reference: string; actorId: number } | null;
    operations: string[];
    sourceSnapshot: Record<string, unknown>;
    history: {
        actor_id: number;
        event: string;
        reference: string;
        state_version: number;
        created_at: string;
    }[];
};
type Props = {
    buildings: EnergyBuilding[];
    selectedBuildingCode: string | null;
    buildingId: number | null;
    kind: Kind;
    subject: string;
    previousVersion: number;
    canDraft: boolean;
    measures: Record<string, string[]>;
    latestVerifiedPeriod: string | null;
    catalogues: { id: number; version: number }[];
    items: {
        data: Item[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    result: string | null;
};
const selectClass =
    'h-10 w-full rounded-[9px] border border-input bg-background px-3 text-sm';
const names: Record<Kind, string> = {
    tariff: 'Tariff proposal',
    rule: 'R1–R7 rule configuration',
    catalogue: 'R5 catalogue definition',
    review: 'R5 evidence review',
};
const fields: Record<Kind, [string, string][]> = {
    tariff: [
        ['rate_php_per_kwh', 'Proposed rate (PHP/import-kWh)'],
        ['source_reference', 'Tariff source reference'],
        ['source_document_hash', 'Source document SHA-256'],
        ['included_charges', 'Included charges'],
        ['excluded_charges', 'Excluded charges'],
    ],
    rule: [
        ['threshold', 'Proposed threshold (measure units)'],
        [
            'reference_method',
            'Reference method, units and missing-data treatment',
        ],
    ],
    catalogue: [
        ['conditions', 'Proposed conditions and required evidence'],
        ['limitations', 'Limitations'],
        ['responsible_office', 'Proposed responsible office'],
    ],
    review: [
        ['scope', 'Specific building/equipment/period scope'],
        [
            'operational_evidence',
            'Operational evidence references and unresolved requirements',
        ],
        ['responsible_office', 'Proposed responsible office'],
    ],
};
const payloadLabels: Record<string, string> = {
    title: 'Title',
    rationale: 'Rationale and outstanding decisions',
    rate_php_per_kwh: 'Proposed rate (PHP per import kWh)',
    source_reference: 'Tariff source reference',
    source_document_hash: 'Source document SHA-256',
    included_charges: 'Charges included in the proposal',
    excluded_charges: 'Charges excluded from the proposal',
    threshold: 'Proposed threshold (measure units)',
    reference_method: 'Reference method, units and missing-data treatment',
    conditions: 'Proposed conditions and required evidence',
    limitations: 'Limitations',
    responsible_office: 'Proposed responsible office',
    scope: 'Specific building, equipment and period scope',
    operational_evidence:
        'Operational evidence references and unresolved requirements',
    tariff_basis: 'Tariff basis',
    mode: 'Rule treatment',
    measure: 'Measure',
    operator: 'Comparison operator',
    priority: 'Priority',
    catalogue_configuration_id: 'Reviewed catalogue version',
};
const fieldHints: Record<string, string> = {
    source_document_hash:
        '64 lowercase hexadecimal characters (SHA-256). Required for the source binding.',
    rate_php_per_kwh:
        'Up to 8 decimal places. The rate is stored exactly as entered and never copied into future rates.',
    threshold:
        'Up to 6 decimal places. The unit follows the selected rule measure.',
    valid_from:
        'First billing month this proposal would apply to. Not an activation.',
    valid_to: 'Last billing month. Leave blank for open-ended.',
    operational_evidence:
        'Cite only operational sources. Synthetic fixtures must be labelled and stay isolated.',
};
const statusLabels: Record<string, string> = {
    draft: 'Draft',
    submitted: 'Submitted for review',
    approved: 'Approved review — inactive',
    rejected: 'Rejected',
    revoked: 'Approval revoked',
};
const eventLabels: Record<string, string> = {
    drafted: 'Draft created',
    submitted: 'Submitted for independent review',
    approved: 'Approved review (inactive)',
    rejected: 'Rejected',
    source_confirmed: 'Tariff source confirmed',
    revoked: 'Approval revoked',
};
const operationLabels: Record<string, string> = {
    submit: 'Submit for independent review',
    confirm_source: 'Confirm tariff source only',
    approve: 'Approve review — keep inactive',
    reject: 'Reject version',
    revoke: 'Revoke review approval',
};

function Review({ item }: { item: Item }) {
    const form = useForm({ reference: '' });
    return (
        <section
            className="space-y-3 rounded-[9px] border p-4"
            aria-label={`${item.subjectKey} version ${item.version}`}
        >
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="font-semibold">
                    {item.subjectKey} · version {item.version} ·{' '}
                    {String(item.payload.title)}
                </h3>
                <Badge variant="secondary">
                    {statusLabels[item.status] ?? item.status} · operationally
                    inactive
                </Badge>
            </div>
            <p>
                Author #{item.authorId}; reviewer{' '}
                {item.reviewerId === null
                    ? 'not yet assigned'
                    : `#${item.reviewerId}`}
                . Effective billing months:{' '}
                {item.validFrom?.slice(0, 7) ?? 'not specified'}–
                {item.validTo?.slice(0, 7) ?? 'not specified'}.
            </p>
            <p className="text-muted-foreground">
                Source/scope:{' '}
                {item.sourceCurrent
                    ? 'current'
                    : 'changed — a new review is required'}
                . Review approval is not City Hall policy activation, and only
                the author can submit this draft.
            </p>
            {item.sourceConfirmation && (
                <p>
                    Budget Officer #{item.sourceConfirmation.actorId} confirmed
                    source: {item.sourceConfirmation.reference}
                </p>
            )}
            <details className="rounded-[9px] bg-secondary p-3">
                <summary className="cursor-pointer font-semibold text-primary">
                    Technical details — version {item.version} content and
                    provenance
                </summary>
                <dl className="mt-3 space-y-2">
                    {Object.entries(item.payload).map(([key, value]) => (
                        <div key={key} className="break-words">
                            <dt className="font-semibold">
                                {payloadLabels[key] ?? key.replaceAll('_', ' ')}
                            </dt>
                            <dd>
                                {value === null || value === ''
                                    ? 'Not specified'
                                    : String(value)}
                            </dd>
                        </div>
                    ))}
                </dl>
                <pre className="mt-3 text-[11px] break-all whitespace-pre-wrap">
                    {JSON.stringify(item.sourceSnapshot, null, 2)}
                </pre>
            </details>
            {item.operations.length > 0 ? (
                <form
                    className="space-y-3"
                    onSubmit={(event) => event.preventDefault()}
                >
                    <div>
                        <Label htmlFor={`reference-${item.id}`}>
                            Review / confirmation reference and reason
                        </Label>
                        <textarea
                            id={`reference-${item.id}`}
                            className="min-h-24 w-full rounded-md border bg-background p-3 text-sm"
                            value={form.data.reference}
                            onChange={(event) =>
                                form.setData('reference', event.target.value)
                            }
                            required
                            maxLength={2000}
                        />
                    </div>
                    {Object.entries(form.errors).map(([key, error]) => (
                        <p
                            key={key}
                            role="alert"
                            className="break-words text-destructive"
                        >
                            {error}
                        </p>
                    ))}
                    <div className="flex flex-wrap gap-2">
                        {item.operations.map((operation) => (
                            <Button
                                key={operation}
                                type="button"
                                variant="outline"
                                disabled={
                                    form.processing ||
                                    !form.data.reference.trim()
                                }
                                onClick={() => {
                                    form.transform((data) => ({
                                        ...data,
                                        operation,
                                        state_version: item.stateVersion,
                                        content_hash: item.contentHash,
                                    }));
                                    form.post(
                                        DssConfigurationController.transition.url(
                                            item.id,
                                        ),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => form.reset(),
                                        },
                                    );
                                }}
                            >
                                {operationLabels[operation] ?? operation}
                            </Button>
                        ))}
                    </div>
                </form>
            ) : (
                <p>
                    No review operation is available to your account for this
                    version. Submitting is limited to the author, and authors
                    cannot approve their own versions.
                </p>
            )}
            <details>
                <summary className="cursor-pointer text-primary">
                    Immutable review history ({item.history.length})
                </summary>
                <ol className="mt-2 space-y-2">
                    {item.history.map((entry) => (
                        <li key={entry.state_version} className="break-words">
                            State version {entry.state_version} ·{' '}
                            {eventLabels[entry.event] ?? entry.event} · actor #
                            {entry.actor_id}: {entry.reference}
                        </li>
                    ))}
                </ol>
            </details>
        </section>
    );
}

function Draft({ props }: { props: Props }) {
    const { kind, subject, buildingId, previousVersion, measures, catalogues } =
        props;
    const payloadFields = [
        'title',
        'rationale',
        ...fields[kind].map(([key]) => key),
        'tariff_basis',
        'mode',
        'measure',
        'operator',
        'priority',
        'catalogue_configuration_id',
    ];
    const form = useForm({
        valid_from: '',
        valid_to: '',
        previous_version: previousVersion,
        payload: Object.fromEntries(
            payloadFields.map((key) => [key, '']),
        ) as Record<string, string>,
    });
    const set = (key: string, value: string) =>
        form.setData('payload', { ...form.data.payload, [key]: value });
    const select = (
        key: string,
        label: string,
        options: [string, string][],
    ) => (
        <div key={key}>
            <Label htmlFor={`draft-${key}`}>{label}</Label>
            <select
                id={`draft-${key}`}
                className={selectClass}
                value={form.data.payload[key]}
                onChange={(event) => set(key, event.target.value)}
            >
                <option value="">Not specified</option>
                {options.map(([value, text]) => (
                    <option key={value} value={value}>
                        {text}
                    </option>
                ))}
            </select>
        </div>
    );
    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                if (!buildingId) return;
                form.transform((data) => ({
                    ...data,
                    kind,
                    subject_key: subject,
                    valid_from: data.valid_from
                        ? `${data.valid_from}-01`
                        : null,
                    valid_to: data.valid_to ? `${data.valid_to}-01` : null,
                }));
                form.post(DssConfigurationController.store.url(buildingId), {
                    preserveScroll: true,
                    onSuccess: (page) =>
                        form.setData(
                            'previous_version',
                            Number(page.props.previousVersion),
                        ),
                });
            }}
        >
            <fieldset className="space-y-3 rounded-[9px] border p-4">
                <legend className="px-1 font-semibold">
                    1. Proposal identity
                </legend>
                <div>
                    <Label htmlFor="draft-title">Title</Label>
                    <Input
                        id="draft-title"
                        value={form.data.payload.title}
                        onChange={(event) => set('title', event.target.value)}
                        required
                        maxLength={255}
                    />
                </div>
                <div>
                    <Label htmlFor="draft-rationale">
                        Rationale and outstanding decisions
                    </Label>
                    <textarea
                        id="draft-rationale"
                        className="min-h-24 w-full rounded-md border bg-background p-3 text-sm"
                        value={form.data.payload.rationale}
                        onChange={(event) =>
                            set('rationale', event.target.value)
                        }
                        required
                        maxLength={4000}
                    />
                </div>
            </fieldset>
            <fieldset className="space-y-3 rounded-[9px] border p-4">
                <legend className="px-1 font-semibold">
                    2. Effective billing months
                </legend>
                <p className="text-[11px] text-muted-foreground">
                    Proposals are review records, not operational policy. Leave
                    a month blank when it is not yet decided.
                </p>
                <div className="grid gap-4 sm:grid-cols-2">
                    {(['valid_from', 'valid_to'] as const).map((key) => (
                        <div key={key}>
                            <Label htmlFor={`draft-${key}`}>
                                {key === 'valid_from'
                                    ? 'Effective billing month from'
                                    : 'Effective billing month through'}
                            </Label>
                            <Input
                                id={`draft-${key}`}
                                type="month"
                                value={form.data[key]}
                                onChange={(event) =>
                                    form.setData(key, event.target.value)
                                }
                                aria-describedby={`draft-${key}-hint`}
                            />
                            <p
                                id={`draft-${key}-hint`}
                                className="mt-1 text-[11px] text-muted-foreground"
                            >
                                {fieldHints[key]}
                            </p>
                        </div>
                    ))}
                </div>
            </fieldset>
            <fieldset className="space-y-3 rounded-[9px] border p-4">
                <legend className="px-1 font-semibold">
                    3. Proposal values
                </legend>
                {kind === 'tariff' &&
                    select('tariff_basis', 'Tariff proposal basis', [
                        ['energy_only', 'Specified energy components only'],
                        [
                            'blended_planning',
                            'Explicit blended planning assumption — not a utility tariff',
                        ],
                    ])}
                {kind === 'rule' && (
                    <>
                        {select('mode', 'Proposed rule treatment', [
                            ['information_only', 'Information only'],
                            [
                                'conditional_proposal',
                                'Conditional policy proposal — disabled',
                            ],
                        ])}
                        {select(
                            'measure',
                            'Rule measure',
                            (measures[subject] ?? []).map((value) => [
                                value,
                                value.replaceAll('_', ' '),
                            ]),
                        )}
                        {select('operator', 'Proposed comparison operator', [
                            ['gt', '>'],
                            ['gte', '≥'],
                            ['lt', '<'],
                            ['lte', '≤'],
                            ['abs_gt', 'Absolute magnitude >'],
                        ])}
                        {select('priority', 'Proposed priority — no default', [
                            ['low', 'Low proposal'],
                            ['medium', 'Medium proposal'],
                            ['high', 'High proposal'],
                        ])}
                    </>
                )}
                {kind === 'review' &&
                    select(
                        'catalogue_configuration_id',
                        'Independently reviewed catalogue version',
                        catalogues.map((item) => [
                            String(item.id),
                            `Configuration #${item.id}, version ${item.version}`,
                        ]),
                    )}
                {fields[kind].map(([key, label]) => (
                    <div key={key}>
                        <Label htmlFor={`draft-${key}`}>{label}</Label>
                        {[
                            'rate_php_per_kwh',
                            'threshold',
                            'source_document_hash',
                        ].includes(key) ? (
                            <Input
                                id={`draft-${key}`}
                                value={form.data.payload[key]}
                                onChange={(event) =>
                                    set(key, event.target.value)
                                }
                                inputMode={
                                    key === 'source_document_hash'
                                        ? 'text'
                                        : 'decimal'
                                }
                                aria-describedby={
                                    fieldHints[key]
                                        ? `draft-${key}-hint`
                                        : undefined
                                }
                            />
                        ) : (
                            <textarea
                                id={`draft-${key}`}
                                className="min-h-24 w-full rounded-md border bg-background p-3 text-sm"
                                value={form.data.payload[key]}
                                onChange={(event) =>
                                    set(key, event.target.value)
                                }
                                maxLength={
                                    key === 'operational_evidence' ? 6000 : 4000
                                }
                                aria-describedby={
                                    fieldHints[key]
                                        ? `draft-${key}-hint`
                                        : undefined
                                }
                            />
                        )}
                        {fieldHints[key] && (
                            <p
                                id={`draft-${key}-hint`}
                                className="mt-1 text-[11px] text-muted-foreground"
                            >
                                {fieldHints[key]}
                            </p>
                        )}
                    </div>
                ))}
            </fieldset>
            {Object.entries(form.errors).map(([key, error]) => (
                <p
                    key={key}
                    role="alert"
                    className="break-words text-destructive"
                >
                    {key}: {error}
                </p>
            ))}
            <Button disabled={!buildingId || form.processing}>
                Save new immutable draft
            </Button>
            <p className="text-muted-foreground">
                Only the author may submit this draft for independent review.
                All policy values start blank. Saving or approving review never
                activates tariffs, classifications, priorities, alerts or
                recommendations. R5 reviews do not create Action Tracker items.
            </p>
        </form>
    );
}

export default function DssConfiguration() {
    const props = usePage().props as unknown as Props;
    const { buildings, selectedBuildingCode, kind, subject, items, canDraft } =
        props;
    const navigate = (changes: Record<string, string>) =>
        router.get(DssConfigurationController.index.url(), {
            building: selectedBuildingCode,
            kind,
            subject,
            ...changes,
        });
    return (
        <>
            <Head title="DSS configuration review" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="DSS configuration review"
                    description="Versioned proposals, independent review and source confirmation."
                    actions={
                        <Button asChild variant="outline">
                            <Link
                                href={DecisionSupportController.index({
                                    query: { building: selectedBuildingCode },
                                })}
                            >
                                Decision Support
                            </Link>
                        </Button>
                    }
                />
                <EnergyNotice
                    title="Review workspace — operational policy disabled"
                    warning
                >
                    Review approval records a software workflow decision, not
                    City Hall operational authorization. No configuration here
                    activates a future tariff, rule, warning, alert or
                    actionable recommendation. Historical effective rates are
                    never copied into future rates.
                </EnergyNotice>
                <EnergyFilters
                    buildings={buildings}
                    selected={selectedBuildingCode}
                    latest={props.latestVerifiedPeriod}
                    onChange={(building) => navigate({ building })}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="configuration-kind">Review type</Label>
                        <select
                            id="configuration-kind"
                            className={selectClass}
                            value={kind}
                            onChange={(event) =>
                                navigate({
                                    kind: event.target.value,
                                    subject:
                                        event.target.value === 'tariff'
                                            ? 'R1'
                                            : event.target.value === 'rule'
                                              ? 'R2'
                                              : 'R5-01',
                                })
                            }
                        >
                            {(canDraft ? Object.keys(names) : ['tariff']).map(
                                (value) => (
                                    <option key={value} value={value}>
                                        {names[value as Kind]}
                                    </option>
                                ),
                            )}
                        </select>
                    </div>
                    <div>
                        <Label htmlFor="configuration-subject">
                            Rule / catalogue subject
                        </Label>
                        <select
                            id="configuration-subject"
                            className={selectClass}
                            value={subject}
                            onChange={(event) =>
                                navigate({ subject: event.target.value })
                            }
                        >
                            {(kind === 'tariff'
                                ? ['R1']
                                : kind === 'rule'
                                  ? Object.keys(props.measures)
                                  : [
                                        'R5-01',
                                        'R5-02',
                                        'R5-03',
                                        'R5-04',
                                        'R5-05',
                                        'R5-06',
                                        'R5-07',
                                    ]
                            ).map((value) => (
                                <option key={value}>{value}</option>
                            ))}
                        </select>
                    </div>
                </div>
                {props.result && (
                    <p role="status" className="text-sm text-primary">
                        {props.result}
                    </p>
                )}
                <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                <h2>{names[kind]}</h2>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-xs leading-relaxed">
                            {canDraft ? (
                                <Draft
                                    key={`${selectedBuildingCode}-${kind}-${subject}`}
                                    props={props}
                                />
                            ) : (
                                <p>
                                    Budget Officer access is limited to
                                    tariff-source confirmation. You cannot draft
                                    configurations, approve policy reviews or
                                    decide recommendations.
                                </p>
                            )}
                            <p>
                                <Link
                                    className="text-primary underline"
                                    href={SolarScenarioController.index({
                                        query: {
                                            building: selectedBuildingCode,
                                        },
                                    })}
                                >
                                    Open existing explicit-input R6 screening
                                </Link>
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                <h2>Versions and independent review</h2>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-xs leading-relaxed">
                            {items.data.length === 0 && (
                                <p>
                                    No versions have been proposed for this
                                    subject.
                                </p>
                            )}
                            {items.data.map((item) => (
                                <Review key={item.id} item={item} />
                            ))}
                            <div className="flex gap-3">
                                {items.prev_page_url && (
                                    <Link
                                        className="underline"
                                        href={items.prev_page_url}
                                    >
                                        Previous versions
                                    </Link>
                                )}
                                {items.next_page_url && (
                                    <Link
                                        className="underline"
                                        href={items.next_page_url}
                                    >
                                        More versions
                                    </Link>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
