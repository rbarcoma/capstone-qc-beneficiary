import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import ForecastController from '@/actions/App/Http/Controllers/ForecastController';
import VerificationController from '@/actions/App/Http/Controllers/VerificationController';
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
import { Label } from '@/components/ui/label';

type Notice = {
    key: string;
    kind: 'verification' | 'forecast';
    title: string;
    buildingCode: string;
    buildingName: string;
    month: string;
    sourceId: number;
    sourceVersion: number | null;
    sourceTime: string;
    readAt: string | null;
};
type Page = {
    filters: { state: string };
    metrics: { unread: number; verification: number; forecasts: number };
    notices: {
        data: Notice[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    operationResult: string | null;
};

export default function Notifications() {
    const { filters, metrics, notices, operationResult } = usePage()
        .props as unknown as Page;
    const form = useForm<{ keys: string[] }>({ keys: [] });
    const unread = notices.data
        .filter((item) => item.readAt === null)
        .map((item) => item.key);
    function markRead(keys: string[]) {
        form.transform(() => ({ keys }));
        form.post(NotificationController.markRead.url(), {
            preserveScroll: true,
        });
    }
    return (
        <>
            <Head title="Notifications" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Notifications"
                    description="Current operational notices for your role, with personal read state."
                    actions={
                        <Button
                            onClick={() => markRead(unread)}
                            disabled={unread.length === 0 || form.processing}
                        >
                            {form.processing ? 'Saving…' : 'Mark page read'}
                        </Button>
                    }
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <EnergyMetric
                        title="Unread alerts"
                        value={metrics.unread}
                        caption="Current notices for your role"
                    />
                    <EnergyMetric
                        title="Awaiting verification"
                        value={metrics.verification}
                        caption="Energy Manager recipients"
                    />
                    <EnergyMetric
                        title="Forecast ready"
                        value={metrics.forecasts}
                        caption="Energy Manager + Budget Officer"
                    />
                    <EnergyMetric
                        title="Error breach"
                        value="Unconfigured"
                        caption="Tolerance policy required"
                    />
                </div>
                <div className="flex flex-wrap items-end gap-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="notice-state">Read state</Label>
                        <select
                            id="notice-state"
                            value={filters.state}
                            onChange={(event) =>
                                router.get(
                                    NotificationController.index.url(),
                                    { state: event.target.value },
                                    { preserveScroll: true },
                                )
                            }
                            className="h-10 rounded-[9px] border border-input bg-card px-3 text-sm"
                        >
                            <option value="">All current notices</option>
                            <option value="unread">Unread</option>
                            <option value="read">Read</option>
                        </select>
                    </div>
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.reload({
                                onSuccess: () => form.clearErrors(),
                            })
                        }
                    >
                        Refresh inbox
                    </Button>
                </div>
                {Object.entries(form.errors).map(([key, message]) => (
                    <InputError key={key} message={message} role="alert" />
                ))}
                {operationResult && (
                    <p role="status" className="text-sm text-primary">
                        {operationResult}
                    </p>
                )}
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            In-app alert inbox
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div
                            className="overflow-x-auto focus-visible:ring-2 focus-visible:ring-ring"
                            tabIndex={0}
                            role="region"
                            aria-label="Notification inbox table"
                        >
                            <table className="w-full min-w-[700px] text-left text-xs">
                                <thead className="bg-secondary text-[11px] text-primary">
                                    <tr>
                                        {[
                                            'Source timestamp',
                                            'Event',
                                            'State',
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
                                    {notices.data.map((item) => (
                                        <tr
                                            key={item.key}
                                            className="border-b align-top"
                                        >
                                            <td className="px-3 py-4">
                                                {new Date(
                                                    item.sourceTime,
                                                ).toLocaleString('en-PH', {
                                                    timeZone: 'Asia/Manila',
                                                })}
                                                <p className="mt-1 text-muted-foreground">
                                                    Asia/Manila · source time
                                                </p>
                                            </td>
                                            <td className="space-y-2 px-3 py-4">
                                                <p className="font-semibold">
                                                    {item.title}
                                                </p>
                                                <p>
                                                    {item.buildingName} ·{' '}
                                                    {item.month.slice(0, 7)}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {item.kind ===
                                                    'verification'
                                                        ? `Record #${item.sourceId} · version ${item.sourceVersion}. A different person must verify.`
                                                        : `Current issued run #${item.sourceId}.`}
                                                </p>
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={
                                                            item.kind ===
                                                            'verification'
                                                                ? VerificationController.index(
                                                                      {
                                                                          query: {
                                                                              building:
                                                                                  item.buildingCode,
                                                                          },
                                                                      },
                                                                  )
                                                                : ForecastController.index(
                                                                      {
                                                                          query: {
                                                                              building:
                                                                                  item.buildingCode,
                                                                          },
                                                                      },
                                                                  )
                                                        }
                                                    >
                                                        Open source
                                                    </Link>
                                                </Button>
                                            </td>
                                            <td className="space-y-2 px-3 py-4">
                                                <Badge variant="secondary">
                                                    {item.readAt
                                                        ? 'Read'
                                                        : 'Unread'}
                                                </Badge>
                                                {!item.readAt && (
                                                    <div>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                markRead([
                                                                    item.key,
                                                                ])
                                                            }
                                                            disabled={
                                                                form.processing
                                                            }
                                                        >
                                                            Mark read
                                                        </Button>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {notices.data.length === 0 && (
                            <p className="py-6 text-xs text-muted-foreground">
                                No current notices match this view. Only Energy
                                Managers receive draft-verification notices;
                                Energy Managers and Budget Officers receive
                                current issued-forecast notices. Other alert
                                types remain unconfigured.
                            </p>
                        )}
                        <nav
                            aria-label="Notification pages"
                            className="mt-4 flex flex-wrap items-center gap-3 text-xs"
                        >
                            <p className="text-muted-foreground">
                                Page {notices.current_page} of{' '}
                                {notices.last_page}
                            </p>
                            {notices.prev_page_url && (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={notices.prev_page_url}>
                                        Previous
                                    </Link>
                                </Button>
                            )}
                            {notices.next_page_url && (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={notices.next_page_url}>
                                        Next
                                    </Link>
                                </Button>
                            )}
                        </nav>
                    </CardContent>
                </Card>
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Notification rules and availability
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div
                            className="overflow-x-auto focus-visible:ring-2 focus-visible:ring-ring"
                            tabIndex={0}
                            role="region"
                            aria-label="Notification rule availability table"
                        >
                            <table className="w-full min-w-[700px] text-left text-xs">
                                <thead className="bg-secondary text-[11px] text-primary">
                                    <tr>
                                        {[
                                            'Event',
                                            'Recipient',
                                            'Delivery / availability',
                                        ].map((title) => (
                                            <th
                                                className="px-3 py-3 font-semibold"
                                                key={title}
                                            >
                                                {title}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {[
                                        [
                                            'New/corrected month awaiting verification',
                                            'Energy Manager',
                                            'Current in-app notice; email unconfigured',
                                        ],
                                        [
                                            'Forecast ready',
                                            'Energy Manager, Budget Officer',
                                            'Current in-app notice; email unconfigured',
                                        ],
                                        [
                                            'High-priority recommendation',
                                            'Energy Manager',
                                            'Disabled: C5 policy not approved',
                                        ],
                                        [
                                            'Overdue monthly upload',
                                            'Facilities, Energy Manager',
                                            'Unconfigured: due-date policy required',
                                        ],
                                        [
                                            'Error above tolerance for 3 months',
                                            'Energy Manager, Admin',
                                            'Unconfigured: tolerance contract required',
                                        ],
                                    ].map((row) => (
                                        <tr className="border-b" key={row[0]}>
                                            {row.map((cell) => (
                                                <td
                                                    className="px-3 py-4"
                                                    key={cell}
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
                <EnergyNotice title="In-app source state; email is not configured">
                    The inbox is refreshed from current sources, not a
                    historical delivery ledger. Verified drafts and superseded
                    forecasts disappear; a corrected record version becomes a
                    new unread notice. Marking read only records your personal
                    acknowledgment, never verifies a bill or accepts advice.
                    SMTP, background delivery and scheduled reminders remain
                    unconfigured. C5 rule generation stays disabled.
                </EnergyNotice>
                <EnergyFooter />
            </div>
        </>
    );
}
