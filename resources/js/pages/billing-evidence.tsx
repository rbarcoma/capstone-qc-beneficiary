import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import BillingEvidenceController from '@/actions/App/Http/Controllers/BillingEvidenceController';
import DecisionSupportController from '@/actions/App/Http/Controllers/DecisionSupportController';
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

type RecordRow = {
    id: number;
    meter_id: number;
    month: string;
    kwh: string;
    version: number;
    verification_status: string;
};
type Component = {
    position: number;
    parent_position: string | number | null;
    is_subtotal: boolean;
    category: string;
    label: string;
    basis_unit: string;
    quantity: string | null;
    printed_rate: string | null;
    amount_php: string;
    source_page: number | string;
};
type EvidenceInput = Record<string, string | number[] | Component[] | null> & {
    components: Component[];
    source_pages: number[];
};
type Bill = {
    id: number;
    version: number;
    recordVersion: number;
    status: string;
    authorId: number;
    reviewerId: number | null;
    sourceState: string;
    canVerify: boolean;
    reconciliation: {
        valid: boolean;
        reason: string | null;
        currentResidual: string | null;
        statementResidual: string | null;
    };
    input: EvidenceInput;
};
type Props = {
    buildings: EnergyBuilding[];
    selectedBuildingCode: string | null;
    records: {
        data: RecordRow[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    selectedRecord: RecordRow | null;
    latestEvidenceVersion: number;
    services: { id: number; meter_id: number; utility_account_id: number }[];
    accounts: { id: number; label: string }[];
    meters: { id: number; label: string }[];
    evidence: {
        data: Bill[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    categories: string[];
    canMap: boolean;
    syntheticAllowed: boolean;
    result: string | null;
};
const selectClass =
    'h-10 w-full rounded-[9px] border border-input bg-background px-3 text-sm';
const fields: [string, string][] = [
    ['period_start', 'Billing period start'],
    ['period_end', 'Billing period end'],
    ['bill_date', 'Bill date'],
    ['source_document', 'Private source document reference'],
    ['document_hash', 'Source document SHA-256'],
    ['source_pages', 'Source PDF pages (comma-separated)'],
    ['import_kwh', 'Import energy (kWh)'],
    ['export_kwh', 'Export energy (kWh)'],
    ['renewable_kwh', 'Renewable generation (kWh, optional)'],
    ['own_use_kwh', 'Own-use energy (kWh, optional)'],
    ['energy_amount_php', 'Total Energy Amount E (PHP)'],
    ['export_credit_php', 'Signed export credit X (PHP)'],
    ['other_charges_php', 'Separate deposits / non-service items O (PHP)'],
    ['applied_credits_php', 'Separate applied account credits A (PHP)'],
    ['current_charges_php', 'Charges for this billing period C (PHP)'],
    ['previous_balance_php', 'Previous balance B (PHP)'],
    ['total_due_php', 'Total Amount Due T (PHP)'],
    ['revision_reason', 'Reason for this evidence version'],
];
const blankComponent = (position: number): Component => ({
    position,
    parent_position: '',
    is_subtotal: false,
    category: '',
    label: '',
    basis_unit: '',
    quantity: '',
    printed_rate: '',
    amount_php: '',
    source_page: '',
});

function EvidenceForm({ props }: { props: Props }) {
    const record = props.selectedRecord;
    const form = useForm({
        utility_service_id: '',
        expected_record_version: record?.version ?? 0,
        previous_evidence_version: props.latestEvidenceVersion,
        authorized_source: false,
        origin: '',
        values: Object.fromEntries(fields.map(([key]) => [key, ''])) as Record<
            string,
            string
        >,
        components: [blankComponent(1)],
    });
    const update = (index: number, changes: Partial<Component>) =>
        form.setData(
            'components',
            form.data.components.map((component, i) =>
                i === index ? { ...component, ...changes } : component,
            ),
        );
    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                if (!record) return;
                form.transform((data) => ({
                    ...data.values,
                    utility_service_id: data.utility_service_id,
                    expected_record_version: data.expected_record_version,
                    previous_evidence_version: data.previous_evidence_version,
                    authorized_source: data.authorized_source,
                    origin: data.origin,
                    source_pages: data.values.source_pages
                        .split(',')
                        .map((value) => value.trim()),
                    components: data.components,
                }));
                form.post(BillingEvidenceController.store.url(record.id), {
                    preserveScroll: true,
                    onSuccess: (page) =>
                        form.setData(
                            'previous_evidence_version',
                            Number(page.props.latestEvidenceVersion),
                        ),
                });
            }}
        >
            {props.evidence.data.length > 0 && (
                <div>
                    <Label htmlFor="revision-source">
                        Start a new revision from an existing version
                    </Label>
                    <select
                        id="revision-source"
                        className={selectClass}
                        defaultValue=""
                        onChange={(event) => {
                            const bill = props.evidence.data.find(
                                (item) =>
                                    item.id === Number(event.target.value),
                            );
                            if (!bill) return;
                            const values = Object.fromEntries(
                                fields.map(([key]) => [
                                    key,
                                    key === 'source_pages'
                                        ? bill.input.source_pages.join(', ')
                                        : key === 'revision_reason'
                                          ? ''
                                          : typeof bill.input[key] === 'string'
                                            ? [
                                                  'period_start',
                                                  'period_end',
                                                  'bill_date',
                                              ].includes(key)
                                                ? String(bill.input[key]).slice(
                                                      0,
                                                      10,
                                                  )
                                                : String(bill.input[key])
                                            : '',
                                ]),
                            );
                            form.setData({
                                ...form.data,
                                values,
                                origin:
                                    typeof bill.input.origin === 'string'
                                        ? bill.input.origin
                                        : '',
                                components: bill.input.components,
                                expected_record_version: record?.version ?? 0,
                                previous_evidence_version:
                                    props.latestEvidenceVersion,
                            });
                        }}
                    >
                        <option value="">Blank new version</option>
                        {props.evidence.data.map((bill) => (
                            <option key={bill.id} value={bill.id}>
                                Copy version {bill.version} — original stays
                                retained
                            </option>
                        ))}
                    </select>
                </div>
            )}
            <div>
                <Label htmlFor="evidence-service">
                    Explicit service association
                </Label>
                <select
                    id="evidence-service"
                    className={selectClass}
                    value={form.data.utility_service_id}
                    onChange={(event) =>
                        form.setData('utility_service_id', event.target.value)
                    }
                    required
                >
                    <option value="">Choose a documented association</option>
                    {props.services
                        .filter(
                            (service) => service.meter_id === record?.meter_id,
                        )
                        .map((service) => (
                            <option key={service.id} value={service.id}>
                                Service #{service.id} · account #
                                {service.utility_account_id} · meter #
                                {service.meter_id}
                            </option>
                        ))}
                </select>
            </div>
            <div>
                <Label htmlFor="evidence-origin">Evidence origin</Label>
                <select
                    id="evidence-origin"
                    className={selectClass}
                    value={form.data.origin}
                    onChange={(event) =>
                        form.setData('origin', event.target.value)
                    }
                    required
                >
                    <option value="">Choose source type</option>
                    <option value="operational">
                        Authorized operational source
                    </option>
                    {props.syntheticAllowed && (
                        <option value="synthetic">
                            Isolated synthetic testing fixture
                        </option>
                    )}
                </select>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                {fields.map(([key, label]) => (
                    <div key={key} className="min-w-0">
                        <Label htmlFor={`evidence-${key}`}>{label}</Label>
                        {key === 'revision_reason' ? (
                            <textarea
                                id={`evidence-${key}`}
                                className="min-h-24 w-full rounded-md border bg-background p-3 text-sm"
                                value={form.data.values[key]}
                                onChange={(event) =>
                                    form.setData('values', {
                                        ...form.data.values,
                                        [key]: event.target.value,
                                    })
                                }
                                required
                            />
                        ) : (
                            <Input
                                id={`evidence-${key}`}
                                type={
                                    [
                                        'period_start',
                                        'period_end',
                                        'bill_date',
                                    ].includes(key)
                                        ? 'date'
                                        : 'text'
                                }
                                value={form.data.values[key]}
                                onChange={(event) =>
                                    form.setData('values', {
                                        ...form.data.values,
                                        [key]: event.target.value,
                                    })
                                }
                                inputMode={
                                    key.endsWith('_php') || key.endsWith('_kwh')
                                        ? 'decimal'
                                        : 'text'
                                }
                            />
                        )}
                    </div>
                ))}
            </div>
            <fieldset className="space-y-4">
                <legend className="font-semibold">
                    Classified bill components — subtotals are not counted twice
                </legend>
                {form.data.components.map((component, index) => (
                    <div
                        key={index}
                        className="space-y-3 rounded-[9px] border p-3"
                    >
                        <h3 className="font-semibold">
                            Component {component.position}
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor={`component-${index}-category`}>
                                    Component {component.position} category
                                </Label>
                                <select
                                    id={`component-${index}-category`}
                                    className={selectClass}
                                    value={component.category}
                                    onChange={(event) =>
                                        update(index, {
                                            category: event.target.value,
                                        })
                                    }
                                    required
                                >
                                    <option value="">
                                        Choose classification
                                    </option>
                                    {props.categories.map((value) => (
                                        <option key={value}>{value}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor={`component-${index}-unit`}>
                                    Component {component.position} quantity
                                    basis
                                </Label>
                                <select
                                    id={`component-${index}-unit`}
                                    className={selectClass}
                                    value={component.basis_unit}
                                    onChange={(event) =>
                                        update(index, {
                                            basis_unit: event.target.value,
                                        })
                                    }
                                    required
                                >
                                    <option value="">Choose unit</option>
                                    {[
                                        'kWh',
                                        'kW',
                                        'customer_month',
                                        'PHP',
                                        'percent',
                                        'other',
                                    ].map((value) => (
                                        <option key={value}>{value}</option>
                                    ))}
                                </select>
                            </div>
                            {(
                                [
                                    ['label', 'Original component label'],
                                    ['amount_php', 'Signed amount (PHP)'],
                                    ['quantity', 'Printed quantity (optional)'],
                                    ['printed_rate', 'Printed rate (optional)'],
                                    ['source_page', 'Source PDF page'],
                                    [
                                        'parent_position',
                                        'Parent subtotal position (optional)',
                                    ],
                                ] as const
                            ).map(([key, label]) => (
                                <div key={key}>
                                    <Label
                                        htmlFor={`component-${index}-${key}`}
                                    >
                                        Component {component.position}: {label}
                                    </Label>
                                    <Input
                                        id={`component-${index}-${key}`}
                                        value={component[key] ?? ''}
                                        onChange={(event) =>
                                            update(index, {
                                                [key]: event.target.value,
                                            })
                                        }
                                        required={[
                                            'label',
                                            'amount_php',
                                            'source_page',
                                        ].includes(key)}
                                    />
                                </div>
                            ))}
                        </div>
                        <label className="flex items-start gap-2">
                            <input
                                type="checkbox"
                                checked={component.is_subtotal}
                                onChange={(event) =>
                                    update(index, {
                                        is_subtotal: event.target.checked,
                                    })
                                }
                            />
                            This row is a subtotal of its direct children.
                        </label>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.data.components.length === 1}
                            onClick={() =>
                                form.setData(
                                    'components',
                                    form.data.components.filter(
                                        (_, i) => i !== index,
                                    ),
                                )
                            }
                        >
                            Remove component {component.position}
                        </Button>
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    disabled={form.data.components.length >= 250}
                    onClick={() =>
                        form.setData('components', [
                            ...form.data.components,
                            blankComponent(
                                Math.max(
                                    ...form.data.components.map(
                                        (item) => item.position,
                                    ),
                                ) + 1,
                            ),
                        ])
                    }
                >
                    Add component
                </Button>
            </fieldset>
            <label className="flex items-start gap-2">
                <input
                    type="checkbox"
                    checked={form.data.authorized_source}
                    onChange={(event) =>
                        form.setData('authorized_source', event.target.checked)
                    }
                    required
                />
                I am authorized to enter this source and have checked that this
                record's kWh represents the bill's import energy. This does not
                verify the evidence.
            </label>
            {Object.entries(form.errors).map(([key, error]) => (
                <p
                    role="alert"
                    key={key}
                    className="break-words text-destructive"
                >
                    {key}: {error}
                </p>
            ))}
            <Button disabled={!record || form.processing}>
                Save billing evidence draft
            </Button>
        </form>
    );
}

function Verify({ bill }: { bill: Bill }) {
    const form = useForm({
        version: bill.version,
        confirmed_against_bill: false,
    });
    return (
        <section className="space-y-3 rounded-[9px] border p-4">
            <h3 className="font-semibold">
                Evidence #{bill.id} · version {bill.version} · record version{' '}
                {bill.recordVersion}
            </h3>
            <Badge variant="secondary">{bill.status}</Badge>
            <p>
                Source: {bill.sourceState}. Author #{bill.authorId}; reviewer{' '}
                {bill.reviewerId === null
                    ? 'not yet verified'
                    : `#${bill.reviewerId}`}
                .
            </p>
            <p>
                Reconciliation:{' '}
                {bill.reconciliation.valid
                    ? 'Exact cent reconciliation passed'
                    : bill.reconciliation.reason}
                . Current-period residual:{' '}
                {bill.reconciliation.currentResidual ?? 'unavailable'};
                statement residual:{' '}
                {bill.reconciliation.statementResidual ?? 'unavailable'}.
            </p>
            <details className="rounded-[9px] bg-secondary p-3">
                <summary className="cursor-pointer text-primary">
                    Review all source values and components
                </summary>
                <pre className="mt-3 text-[11px] break-all whitespace-pre-wrap">
                    {JSON.stringify(bill.input, null, 2)}
                </pre>
            </details>
            {bill.canVerify && (
                <form
                    className="space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(
                            BillingEvidenceController.verify.url(bill.id),
                            { preserveScroll: true },
                        );
                    }}
                >
                    <label className="flex items-start gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.confirmed_against_bill}
                            onChange={(event) =>
                                form.setData(
                                    'confirmed_against_bill',
                                    event.target.checked,
                                )
                            }
                            required
                        />
                        I independently checked this exact evidence version
                        against its source bill and financial classifications.
                    </label>
                    {Object.entries(form.errors).map(([key, error]) => (
                        <p key={key} role="alert" className="text-destructive">
                            {error}
                        </p>
                    ))}
                    <Button disabled={form.processing}>
                        Independently verify evidence version {bill.version}
                    </Button>
                </form>
            )}
        </section>
    );
}

function ServiceAssociation({ props }: { props: Props }) {
    const form = useForm({
        meter_id: '',
        utility_account_id: '',
        provider: '',
        account_reference: '',
        service_reference: '',
        physical_meter_reference: '',
        mapping_reference: '',
        authorized_source: false,
    });
    return (
        <details className="rounded-[9px] border p-4">
            <summary className="cursor-pointer font-semibold text-primary">
                Admin: add an explicit account/service association
            </summary>
            <form
                className="mt-4 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(BillingEvidenceController.mapService.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <div>
                    <Label htmlFor="mapping-meter">Existing meter</Label>
                    <select
                        id="mapping-meter"
                        className={selectClass}
                        value={form.data.meter_id}
                        onChange={(event) =>
                            form.setData('meter_id', event.target.value)
                        }
                        required
                    >
                        <option value="">Choose existing meter</option>
                        {props.meters.map((meter) => (
                            <option key={meter.id} value={meter.id}>
                                {meter.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <Label htmlFor="mapping-account">Customer account</Label>
                    <select
                        id="mapping-account"
                        className={selectClass}
                        value={form.data.utility_account_id}
                        onChange={(event) =>
                            form.setData(
                                'utility_account_id',
                                event.target.value,
                            )
                        }
                    >
                        <option value="">
                            Register explicitly supplied customer account
                        </option>
                        {props.accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.label}
                            </option>
                        ))}
                    </select>
                </div>
                {(
                    [
                        'provider',
                        'account_reference',
                        'service_reference',
                        'physical_meter_reference',
                        'mapping_reference',
                    ] as const
                )
                    .filter(
                        (key) =>
                            !form.data.utility_account_id ||
                            !['provider', 'account_reference'].includes(key),
                    )
                    .map((key) => (
                        <div key={key}>
                            <Label htmlFor={`mapping-${key}`}>
                                {
                                    {
                                        provider: 'Utility provider',
                                        account_reference:
                                            'Customer account reference (restricted)',
                                        service_reference:
                                            'Distinct service reference (restricted)',
                                        physical_meter_reference:
                                            'Physical meter reference (optional)',
                                        mapping_reference:
                                            'Documented mapping reference / reason',
                                    }[key]
                                }
                            </Label>
                            <Input
                                id={`mapping-${key}`}
                                value={form.data[key]}
                                onChange={(event) =>
                                    form.setData(key, event.target.value)
                                }
                                required={key !== 'physical_meter_reference'}
                            />
                        </div>
                    ))}
                <label className="flex items-start gap-2">
                    <input
                        type="checkbox"
                        checked={form.data.authorized_source}
                        onChange={(event) =>
                            form.setData(
                                'authorized_source',
                                event.target.checked,
                            )
                        }
                        required
                    />
                    I am authorized and have verified this explicit association.
                    Do not infer it from legacy meter references.
                </label>
                {Object.entries(form.errors).map(([key, error]) => (
                    <p key={key} role="alert" className="text-destructive">
                        {key}: {error}
                    </p>
                ))}
                <Button disabled={form.processing}>
                    Record explicit service association
                </Button>
            </form>
        </details>
    );
}

export default function BillingEvidence() {
    const props = usePage().props as unknown as Props;
    return (
        <>
            <Head title="Billing evidence" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Billing evidence"
                    description="Retained source versions, exact reconciliation and independent verification."
                    actions={
                        <Button asChild variant="outline">
                            <Link
                                href={DecisionSupportController.index({
                                    query: {
                                        building: props.selectedBuildingCode,
                                    },
                                })}
                            >
                                Decision Support
                            </Link>
                        </Button>
                    }
                />
                <EnergyNotice title="Historical evidence, separate from monthly-bill verification">
                    This workspace never changes monthly kWh, cost, meter
                    identities or existing verification. Source corrections
                    require new evidence and independent review. Enter only
                    authorized sources; no confidential PDF import or automatic
                    meter mapping is performed. Source files are referenced, not
                    uploaded or publicly served.
                </EnergyNotice>
                <EnergyFilters
                    buildings={props.buildings}
                    selected={props.selectedBuildingCode}
                    latest={null}
                    onChange={(building) =>
                        router.get(BillingEvidenceController.index.url(), {
                            building,
                        })
                    }
                />
                <div>
                    <Label htmlFor="evidence-record">
                        Monthly record to document
                    </Label>
                    <select
                        id="evidence-record"
                        className={selectClass}
                        value={props.selectedRecord?.id ?? ''}
                        onChange={(event) =>
                            router.get(BillingEvidenceController.index.url(), {
                                building: props.selectedBuildingCode,
                                record: event.target.value,
                            })
                        }
                    >
                        <option value="">Choose record</option>
                        {props.records.data.map((record) => (
                            <option key={record.id} value={record.id}>
                                {record.month.slice(0, 7)} · meter #
                                {record.meter_id} · record #{record.id} v
                                {record.version} · {record.verification_status}
                            </option>
                        ))}
                    </select>
                    <div className="mt-2 flex gap-3">
                        {props.records.prev_page_url && (
                            <Link
                                className="text-primary underline"
                                href={props.records.prev_page_url}
                            >
                                Previous records
                            </Link>
                        )}
                        {props.records.next_page_url && (
                            <Link
                                className="text-primary underline"
                                href={props.records.next_page_url}
                            >
                                More records
                            </Link>
                        )}
                    </div>
                </div>
                {props.result && (
                    <p role="status" className="text-sm text-primary">
                        {props.result}
                    </p>
                )}
                {props.canMap && <ServiceAssociation props={props} />}
                <div className="grid min-w-0 gap-6 xl:grid-cols-2">
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                <h2>New retained evidence version</h2>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-xs leading-relaxed">
                            {props.selectedRecord ? (
                                <EvidenceForm
                                    key={props.selectedRecord.id}
                                    props={props}
                                />
                            ) : (
                                <p>
                                    No monthly record is available. Use the
                                    existing Data Management workflow first.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card className="min-w-0 rounded-[13px] shadow-none">
                        <CardHeader>
                            <CardTitle className="text-[17px]">
                                <h2>
                                    Evidence versions and independent checks
                                </h2>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-xs leading-relaxed">
                            {props.evidence.data.length === 0 && (
                                <p>
                                    No billing evidence has been attached to
                                    this record. Existing cost values are not
                                    reinterpreted.
                                </p>
                            )}
                            {props.evidence.data.map((bill) => (
                                <Verify key={bill.id} bill={bill} />
                            ))}
                            <div className="flex gap-3">
                                {props.evidence.prev_page_url && (
                                    <Link
                                        className="underline"
                                        href={props.evidence.prev_page_url}
                                    >
                                        Previous evidence versions
                                    </Link>
                                )}
                                {props.evidence.next_page_url && (
                                    <Link
                                        className="underline"
                                        href={props.evidence.next_page_url}
                                    >
                                        More evidence versions
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
