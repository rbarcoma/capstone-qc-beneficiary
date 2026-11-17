import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AuditLogController from '@/actions/App/Http/Controllers/AuditLogController';
import {
    EnergyFooter,
    EnergyHeader,
    EnergyMetric,
    EnergyNotice,
} from '@/components/energy-workspace';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';

type Actor = { id: number; name: string };
type AuditEntry = {
    id: number;
    event: string;
    createdAt: string | null;
    actor: Actor | null;
    monthlyRecordId: number | null;
    forecastId: number | null;
};
type AuditDetail = AuditEntry & {
    reason: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
};
type Filters = { event: string; actor: string; from: string; to: string };
type AuditPage = {
    filters: Filters;
    eventOptions: string[];
    actors: Actor[];
    metrics: { entries: number; corrections: number; downloads: number };
    entries: {
        data: AuditEntry[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

function auditTime(value: string | null): string {
    return value
        ? new Date(value).toLocaleString('en-PH', {
              timeZone: 'Asia/Manila',
              dateStyle: 'medium',
              timeStyle: 'medium',
          })
        : 'Time not recorded';
}

function AuditFilters({
    filters,
    events,
    actors,
}: {
    filters: Filters;
    events: string[];
    actors: Actor[];
}) {
    const form = useForm(filters);
    return (
        <form
            className="flex flex-wrap items-end gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.get(AuditLogController.index.url(), {
                    preserveScroll: true,
                });
            }}
        >
            <div className="space-y-1.5">
                <Label htmlFor="audit-event">Recorded event</Label>
                <Select
                    value={form.data.event || 'all'}
                    onValueChange={(value) =>
                        form.setData('event', value === 'all' ? '' : value)
                    }
                >
                    <SelectTrigger
                        id="audit-event"
                        className="h-10 w-[245px] bg-card"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All events</SelectItem>
                        {events.map((event) => (
                            <SelectItem key={event} value={event}>
                                {event}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="audit-actor">Actor</Label>
                <Select
                    value={form.data.actor || 'all'}
                    onValueChange={(value) =>
                        form.setData('actor', value === 'all' ? '' : value)
                    }
                >
                    <SelectTrigger
                        id="audit-actor"
                        className="h-10 w-[245px] bg-card"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All actors</SelectItem>
                        <SelectItem value="unattributed">
                            System / former user
                        </SelectItem>
                        {actors.map((actor) => (
                            <SelectItem key={actor.id} value={String(actor.id)}>
                                {actor.name} (#{actor.id})
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="audit-from">From (Asia/Manila)</Label>
                <Input
                    id="audit-from"
                    type="date"
                    min="1900-01-01"
                    max="9999-12-31"
                    className="h-10 w-[185px] bg-card"
                    value={form.data.from}
                    onChange={(event) =>
                        form.setData('from', event.target.value)
                    }
                />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="audit-to">Through (Asia/Manila)</Label>
                <Input
                    id="audit-to"
                    type="date"
                    min={form.data.from || '1900-01-01'}
                    max="9999-12-31"
                    className="h-10 w-[185px] bg-card"
                    value={form.data.to}
                    onChange={(event) => form.setData('to', event.target.value)}
                />
            </div>
            <Button type="submit" disabled={form.processing}>
                {form.processing ? 'Applying…' : 'Apply filters'}
            </Button>
            <Button variant="outline" asChild>
                <Link href={AuditLogController.index()}>Clear filters</Link>
            </Button>
            {Object.entries(form.errors).map(([key, message]) => (
                <InputError
                    className="w-full"
                    role="alert"
                    key={key}
                    message={message}
                />
            ))}
        </form>
    );
}

function AuditDetails({
    id,
    onClose,
    onRestoreFocus,
}: {
    id: number;
    onClose: () => void;
    onRestoreFocus: () => void;
}) {
    const [detail, setDetail] = useState<AuditDetail | null>(null);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        fetch(AuditLogController.show.url(id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error('Unable to load audit entry.');
                return response.json() as Promise<AuditDetail>;
            })
            .then((data) => {
                if (!controller.signal.aborted) setDetail(data);
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setError(
                        'Unable to load this entry. Your access may have changed.',
                    );
            });
        return () => controller.abort();
    }, [id, attempt]);
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                className="max-h-[85vh] overflow-y-auto sm:max-w-4xl"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    onRestoreFocus();
                }}
            >
                <DialogHeader>
                    <DialogTitle>Audit entry #{id}</DialogTitle>
                    <DialogDescription>
                        Stored before/after evidence. Values are historical
                        snapshots, not current operational advice.
                    </DialogDescription>
                </DialogHeader>
                {error ? (
                    <div role="alert" className="space-y-3">
                        <p>{error}</p>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setError('');
                                setAttempt((value) => value + 1);
                            }}
                        >
                            Retry
                        </Button>
                    </div>
                ) : !detail ? (
                    <div role="status" className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Loading audit evidence…
                        </p>
                        <Skeleton className="h-24 w-full" />
                    </div>
                ) : (
                    <div className="min-w-0 space-y-4 text-sm">
                        <dl className="grid gap-2 break-words sm:grid-cols-2">
                            <div>
                                <dt className="font-semibold">
                                    Recorded event
                                </dt>
                                <dd>{detail.event}</dd>
                            </div>
                            <div>
                                <dt className="font-semibold">
                                    Time (Asia/Manila)
                                </dt>
                                <dd>{auditTime(detail.createdAt)}</dd>
                            </div>
                            <div>
                                <dt className="font-semibold">Actor</dt>
                                <dd>
                                    {detail.actor
                                        ? `${detail.actor.name} (#${detail.actor.id})`
                                        : 'System / former user'}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-semibold">References</dt>
                                <dd>
                                    {detail.monthlyRecordId
                                        ? `Monthly record #${detail.monthlyRecordId}`
                                        : ''}
                                    {detail.monthlyRecordId && detail.forecastId
                                        ? ' · '
                                        : ''}
                                    {detail.forecastId
                                        ? `Forecast #${detail.forecastId}`
                                        : ''}
                                    {!detail.monthlyRecordId &&
                                    !detail.forecastId
                                        ? 'See stored snapshots'
                                        : ''}
                                </dd>
                            </div>
                        </dl>
                        <div>
                            <p className="font-semibold">Reason / context</p>
                            <p className="mt-1 break-words whitespace-pre-wrap">
                                {detail.reason ??
                                    'No reason recorded for this event.'}
                            </p>
                        </div>
                        <div className="grid min-w-0 gap-4 sm:grid-cols-2">
                            {(['before', 'after'] as const).map((field) => (
                                <section
                                    key={field}
                                    className="min-w-0 space-y-2"
                                >
                                    <h3 className="font-semibold capitalize">
                                        {field}
                                    </h3>
                                    <pre className="max-h-96 overflow-auto rounded-lg bg-secondary p-3 text-xs break-all whitespace-pre-wrap">
                                        {detail[field] === null
                                            ? 'No snapshot recorded'
                                            : JSON.stringify(
                                                  detail[field],
                                                  null,
                                                  2,
                                              )}
                                    </pre>
                                </section>
                            ))}
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

export default function AuditLogs() {
    const { filters, eventOptions, actors, metrics, entries } = usePage()
        .props as unknown as AuditPage;
    const [selected, setSelected] = useState<number | null>(null);
    const entryTrigger = useRef<HTMLButtonElement | null>(null);
    return (
        <>
            <Head title="Audit Logs" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Audit Logs"
                    description="Accountability for recorded uploads, verifications, corrections, forecast issuance and decisions."
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <EnergyMetric
                        title="Audit entries"
                        value={metrics.entries}
                        caption="Stored events matching filters"
                    />
                    <EnergyMetric
                        title="Corrections"
                        value={metrics.corrections}
                        caption="Recorded events matching filters"
                    />
                    <EnergyMetric
                        title="Report downloads"
                        value={metrics.downloads}
                        caption="Prepared exports matching filters"
                    />
                    <EnergyMetric title="Audit access" value="Admin only" />
                </div>
                <AuditFilters
                    key={JSON.stringify(filters)}
                    filters={filters}
                    events={eventOptions}
                    actors={actors}
                />
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Activity history
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div
                            className="overflow-x-auto focus-visible:ring-2 focus-visible:ring-ring"
                            tabIndex={0}
                            role="region"
                            aria-label="Audit activity table"
                        >
                            <table className="w-full min-w-[700px] text-left text-xs">
                                <thead className="bg-secondary text-[11px] text-primary">
                                    <tr>
                                        <th className="px-3 py-3 font-semibold">
                                            Time (Asia/Manila)
                                        </th>
                                        <th className="px-3 py-3 font-semibold">
                                            Actor
                                        </th>
                                        <th className="px-3 py-3 font-semibold">
                                            Recorded event
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.data.map((entry) => (
                                        <tr
                                            key={entry.id}
                                            className="border-b align-top"
                                        >
                                            <td className="px-3 py-4">
                                                <time
                                                    dateTime={
                                                        entry.createdAt ??
                                                        undefined
                                                    }
                                                >
                                                    {auditTime(entry.createdAt)}
                                                </time>
                                                <p className="mt-1 text-muted-foreground">
                                                    Entry #{entry.id}
                                                </p>
                                            </td>
                                            <td className="px-3 py-4">
                                                {entry.actor ? (
                                                    <>
                                                        {entry.actor.name}
                                                        <p className="mt-1 text-muted-foreground">
                                                            User #
                                                            {entry.actor.id}
                                                        </p>
                                                    </>
                                                ) : (
                                                    'System / former user'
                                                )}
                                            </td>
                                            <td className="px-3 py-4">
                                                <p className="font-semibold break-all">
                                                    {entry.event}
                                                </p>
                                                <p className="mt-1 text-muted-foreground">
                                                    {entry.monthlyRecordId
                                                        ? `Monthly record #${entry.monthlyRecordId}`
                                                        : ''}
                                                    {entry.forecastId
                                                        ? ` Forecast #${entry.forecastId}`
                                                        : ''}
                                                </p>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="mt-2"
                                                    onClick={(event) => {
                                                        entryTrigger.current =
                                                            event.currentTarget;
                                                        setSelected(entry.id);
                                                    }}
                                                >
                                                    View entry #{entry.id}
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {entries.data.length === 0 && (
                            <p className="px-3 py-6 text-xs text-muted-foreground">
                                No audit entries match these filters. Only
                                recorded events appear here.
                            </p>
                        )}
                        <nav
                            aria-label="Audit history pages"
                            className="mt-4 flex flex-wrap items-center gap-3 text-xs"
                        >
                            <p className="text-muted-foreground">
                                Page {entries.current_page} of{' '}
                                {entries.last_page}
                            </p>
                            {entries.prev_page_url && (
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={entries.prev_page_url}>
                                        Previous
                                    </Link>
                                </Button>
                            )}
                            {entries.next_page_url && (
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={entries.next_page_url}>
                                        Next
                                    </Link>
                                </Button>
                            )}
                        </nav>
                    </CardContent>
                </Card>
                <EnergyNotice title="Production retention must be approved">
                    Keep before/after values for corrections and access history;
                    consult City Hall records policy, ICT and the Data
                    Protection Officer. Actor names reflect current accounts;
                    historical roles were not recorded. System events and
                    removed actors share the unattributed label. Coverage
                    reflects implemented event writers, not all system activity.
                </EnergyNotice>
                <EnergyFooter />
            </div>
            {selected !== null && (
                <AuditDetails
                    key={selected}
                    id={selected}
                    onClose={() => setSelected(null)}
                    onRestoreFocus={() => entryTrigger.current?.focus()}
                />
            )}
        </>
    );
}
