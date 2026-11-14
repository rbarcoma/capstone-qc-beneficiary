import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import VerificationController from '@/actions/App/Http/Controllers/VerificationController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { MonthlyRecordRow } from '@/types';

type Revision = {
    id: number;
    reason: string;
    actor_id: number | null;
    created_at: string;
    before: Record<string, string | number | null>;
    after: Record<string, string | number | null>;
};
type History = { data: Revision[]; next_page_url: string | null };
const fields = [
    { key: 'kwh', label: 'Energy kWh', step: '0.001' },
    { key: 'billed_demand_kw', label: 'Billed demand kW', step: '0.001' },
    { key: 'cost_php', label: 'Cost PHP', step: '0.01' },
    { key: 'power_factor', label: 'Power factor', step: '0.0001' },
] as const;

export function MonthlyRecordActions({ record }: { record: MonthlyRecordRow }) {
    const [mode, setMode] = useState<'verify' | 'correct' | 'history' | null>(
        null,
    );
    const [history, setHistory] = useState<History | null>(null);
    const [historyError, setHistoryError] = useState('');
    const verification = useForm({
        version: record.version,
        confirmed_against_bill: false,
    });
    const correction = useForm({
        month: record.month,
        version: record.version,
        kwh: record.kwh,
        billed_demand_kw: record.billedDemandKw ?? '',
        cost_php: record.costPhp ?? '',
        power_factor: record.powerFactor ?? '',
        notes: record.notes ?? '',
        reason: '',
    });

    const loadHistory = async (url: string) => {
        setHistory(null);
        setHistoryError('');
        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok)
                throw new Error('Unable to load correction history.');
            setHistory((await response.json()) as History);
        } catch {
            setHistoryError(
                'Unable to load correction history. Refresh and try again.',
            );
        }
    };

    const open = (nextMode: 'verify' | 'correct' | 'history') => {
        verification.clearErrors();
        correction.clearErrors();
        verification.setData({
            version: record.version,
            confirmed_against_bill: false,
        });
        correction.setData({
            month: record.month,
            version: record.version,
            kwh: record.kwh,
            billed_demand_kw: record.billedDemandKw ?? '',
            cost_php: record.costPhp ?? '',
            power_factor: record.powerFactor ?? '',
            notes: record.notes ?? '',
            reason: '',
        });
        setMode(nextMode);
        if (nextMode === 'history')
            void loadHistory(VerificationController.history.url(record.id));
    };

    return record.canCorrect || record.canVerify ? (
        <>
            <div className="mt-2 flex flex-wrap gap-2">
                {record.verificationStatus === 'draft' && (
                    <Button
                        size="sm"
                        disabled={!record.canVerify}
                        title={
                            !record.canVerify
                                ? 'A different person must verify the latest entered values.'
                                : undefined
                        }
                        onClick={() => open('verify')}
                    >
                        Review &amp; verify
                    </Button>
                )}
                {record.canCorrect && (
                    <>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => open('correct')}
                        >
                            Correct
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => open('history')}
                        >
                            History
                        </Button>
                    </>
                )}
            </div>
            <Dialog
                open={mode !== null}
                onOpenChange={(value) => {
                    if (
                        !value &&
                        !verification.processing &&
                        !correction.processing
                    )
                        setMode(null);
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {mode === 'verify'
                                ? 'Verify monthly record'
                                : mode === 'correct'
                                  ? 'Record a correction'
                                  : 'Correction history'}
                        </DialogTitle>
                        <DialogDescription>
                            {record.month.slice(0, 7)} · {record.meterLabel}.
                            Compare values with the original billing statement.
                        </DialogDescription>
                    </DialogHeader>
                    {mode === 'verify' && (
                        <form
                            id={`verify-${record.id}`}
                            className="space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                verification.post(
                                    VerificationController.verify.url(
                                        record.id,
                                    ),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setMode(null);
                                            toast.success(
                                                'Monthly record independently verified.',
                                            );
                                        },
                                    },
                                );
                            }}
                        >
                            <dl className="grid grid-cols-2 gap-2 text-sm">
                                <dt>Energy kWh</dt>
                                <dd>{record.kwh}</dd>
                                <dt>Billed demand kW</dt>
                                <dd>
                                    {record.billedDemandKw ?? 'Not provided'}
                                </dd>
                                <dt>Cost PHP</dt>
                                <dd>{record.costPhp ?? 'Not provided'}</dd>
                                <dt>Power factor</dt>
                                <dd>{record.powerFactor ?? 'Not provided'}</dd>
                                <dt>Notes</dt>
                                <dd className="break-words">
                                    {record.notes ?? 'None'}
                                </dd>
                            </dl>
                            <div className="flex items-start gap-2">
                                <Checkbox
                                    id={`confirmed-${record.id}`}
                                    checked={
                                        verification.data.confirmed_against_bill
                                    }
                                    onCheckedChange={(value) =>
                                        verification.setData(
                                            'confirmed_against_bill',
                                            value === true,
                                        )
                                    }
                                />
                                <Label htmlFor={`confirmed-${record.id}`}>
                                    I checked these values against the original
                                    bill.
                                </Label>
                            </div>
                            <Errors errors={verification.errors} />
                        </form>
                    )}
                    {mode === 'correct' && (
                        <form
                            id={`correct-${record.id}`}
                            className="space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                correction.patch(
                                    VerificationController.correct.url(
                                        record.id,
                                    ),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setMode(null);
                                            toast.success(
                                                'Correction recorded. Independent verification is required again.',
                                            );
                                        },
                                    },
                                );
                            }}
                        >
                            <p className="text-xs text-muted-foreground">
                                Meter assignment remains fixed. Previous values
                                are retained. The record returns to Draft and a
                                different person must verify the latest values.
                            </p>
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor={`correction-month-${record.id}`}
                                >
                                    Billing month
                                </Label>
                                <Input
                                    id={`correction-month-${record.id}`}
                                    type="month"
                                    required
                                    value={correction.data.month.slice(0, 7)}
                                    onChange={(event) =>
                                        correction.setData(
                                            'month',
                                            event.target.value
                                                ? `${event.target.value}-01`
                                                : '',
                                        )
                                    }
                                />
                            </div>
                            {fields.map((field) => (
                                <div key={field.key} className="space-y-1.5">
                                    <Label
                                        htmlFor={`correction-${record.id}-${field.key}`}
                                    >
                                        {field.label}
                                    </Label>
                                    <Input
                                        id={`correction-${record.id}-${field.key}`}
                                        type="number"
                                        min="0"
                                        step={field.step}
                                        max={
                                            field.key === 'power_factor'
                                                ? '1'
                                                : undefined
                                        }
                                        required={field.key === 'kwh'}
                                        value={correction.data[field.key]}
                                        onChange={(event) =>
                                            correction.setData(
                                                field.key,
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            ))}
                            <Label htmlFor={`notes-${record.id}`}>Notes</Label>
                            <Input
                                id={`notes-${record.id}`}
                                value={correction.data.notes}
                                onChange={(event) =>
                                    correction.setData(
                                        'notes',
                                        event.target.value,
                                    )
                                }
                            />
                            <Label htmlFor={`reason-${record.id}`}>
                                Reason for correction
                            </Label>
                            <Input
                                id={`reason-${record.id}`}
                                required
                                maxLength={2000}
                                value={correction.data.reason}
                                onChange={(event) =>
                                    correction.setData(
                                        'reason',
                                        event.target.value,
                                    )
                                }
                            />
                            <Errors errors={correction.errors} />
                        </form>
                    )}
                    {mode === 'history' && (
                        <div className="space-y-3">
                            {historyError ? (
                                <InputError
                                    role="alert"
                                    message={historyError}
                                />
                            ) : !history ? (
                                <p role="status">Loading correction history…</p>
                            ) : (
                                <>
                                    {history.data.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            No recorded corrections.
                                        </p>
                                    )}
                                    {history.data.map((revision) => (
                                        <article
                                            key={revision.id}
                                            className="rounded-lg border p-3 text-xs"
                                        >
                                            <p className="font-semibold">
                                                {revision.reason}
                                            </p>
                                            <p className="my-2 text-muted-foreground">
                                                {new Date(
                                                    revision.created_at,
                                                ).toLocaleString()}{' '}
                                                · actor #
                                                {revision.actor_id ??
                                                    'removed user'}
                                            </p>
                                            <dl className="space-y-1">
                                                {[
                                                    'kwh',
                                                    'billed_demand_kw',
                                                    'cost_php',
                                                    'power_factor',
                                                    'notes',
                                                    'verification_status',
                                                    'month',
                                                ].map((field) => (
                                                    <div
                                                        key={field}
                                                        className="break-words"
                                                    >
                                                        <dt className="inline font-semibold">
                                                            {field}:{' '}
                                                        </dt>
                                                        <dd className="inline">
                                                            {String(
                                                                revision.before[
                                                                    field
                                                                ] ?? '—',
                                                            )}{' '}
                                                            →{' '}
                                                            {String(
                                                                revision.after[
                                                                    field
                                                                ] ?? '—',
                                                            )}
                                                        </dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        </article>
                                    ))}
                                    {history.next_page_url && (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                void loadHistory(
                                                    history.next_page_url!,
                                                )
                                            }
                                        >
                                            Older corrections
                                        </Button>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={
                                verification.processing || correction.processing
                            }
                            onClick={() => setMode(null)}
                        >
                            Close
                        </Button>
                        {mode === 'verify' && (
                            <Button
                                type="submit"
                                form={`verify-${record.id}`}
                                disabled={
                                    !verification.data.confirmed_against_bill ||
                                    verification.processing
                                }
                            >
                                Confirm verification
                            </Button>
                        )}
                        {mode === 'correct' && (
                            <Button
                                type="submit"
                                form={`correct-${record.id}`}
                                disabled={correction.processing}
                            >
                                Save correction
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    ) : null;
}

function Errors({ errors }: { errors: Record<string, string> }) {
    return Object.keys(errors).length > 0 ? (
        <div role="alert" className="space-y-1">
            {Object.entries(errors).map(([key, message]) => (
                <InputError key={key} message={message} />
            ))}
        </div>
    ) : null;
}
