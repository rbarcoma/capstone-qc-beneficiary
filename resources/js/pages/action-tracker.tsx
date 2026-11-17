import { Head, Link, useForm, usePage } from '@inertiajs/react';
import ActionTrackerController from '@/actions/App/Http/Controllers/ActionTrackerController';
import DecisionSupportController from '@/actions/App/Http/Controllers/DecisionSupportController';
import { ActionFollowUp } from '@/components/action-follow-up';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { EnergyBuilding, TrackedAction } from '@/types';

type Filters = { building: string; status: string };
function ActionFilters({
    filters,
    buildings,
}: {
    filters: Filters;
    buildings: EnergyBuilding[];
}) {
    const form = useForm(filters);
    return (
        <form
            className="flex flex-wrap items-end gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.get(ActionTrackerController.index.url(), {
                    preserveScroll: true,
                });
            }}
        >
            <div className="space-y-1.5">
                <Label htmlFor="tracker-building">Building</Label>
                <Select
                    value={form.data.building || 'all'}
                    onValueChange={(value) =>
                        form.setData('building', value === 'all' ? '' : value)
                    }
                >
                    <SelectTrigger
                        id="tracker-building"
                        className="h-10 w-[241px] bg-card"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">
                            All accessible buildings
                        </SelectItem>
                        {buildings.map((building) => (
                            <SelectItem key={building.id} value={building.code}>
                                {building.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="tracker-status">Status</Label>
                <Select
                    value={form.data.status || 'all'}
                    onValueChange={(value) =>
                        form.setData('status', value === 'all' ? '' : value)
                    }
                >
                    <SelectTrigger
                        id="tracker-status"
                        className="h-10 w-[185px] bg-card"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All statuses</SelectItem>
                        <SelectItem value="open">Open</SelectItem>
                        <SelectItem value="completed">Completed</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <Button type="submit" disabled={form.processing}>
                {form.processing ? 'Applying…' : 'Apply filters'}
            </Button>
            <Button variant="outline" asChild>
                <Link href={ActionTrackerController.index()}>
                    Clear filters
                </Link>
            </Button>
            {Object.entries(form.errors).map(([key, message]) => (
                <InputError
                    key={key}
                    role="alert"
                    message={message}
                    className="w-full"
                />
            ))}
        </form>
    );
}

export default function ActionTracker() {
    const { buildings, filters, metrics, actions, operationResult } = usePage()
        .props as unknown as {
        buildings: EnergyBuilding[];
        filters: Filters;
        metrics: { tracked: number; open: number; completed: number };
        actions: {
            data: TrackedAction[];
            current_page: number;
            last_page: number;
            prev_page_url: string | null;
            next_page_url: string | null;
        };
        operationResult: string | null;
    };
    return (
        <>
            <Head title="Action Tracker" />
            <div className="flex flex-1 flex-col gap-6 px-7 pt-6 pb-10">
                <EnergyHeader
                    title="Action Tracker"
                    description="Actions accepted by an Energy Manager, with ownership, due date, outcome and follow-up."
                    actions={
                        <Button asChild>
                            <Link
                                href={DecisionSupportController.index({
                                    query: {
                                        building: filters.building || null,
                                    },
                                })}
                            >
                                Review DSS recommendations
                            </Link>
                        </Button>
                    }
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <EnergyMetric
                        title="Tracked actions"
                        value={metrics.tracked}
                        caption="Accessible actions matching filters"
                    />
                    <EnergyMetric
                        title="Open actions"
                        value={metrics.open}
                        caption="Matching filters"
                    />
                    <EnergyMetric
                        title="Completed"
                        value={metrics.completed}
                        caption="Reported completion, not measured savings"
                    />
                    <EnergyMetric
                        title="Overdue"
                        value="To be configured"
                        caption="Timing policy not defined"
                    />
                </div>
                <ActionFilters
                    key={JSON.stringify(filters)}
                    filters={filters}
                    buildings={buildings}
                />
                {operationResult && (
                    <p role="status" className="text-sm text-primary">
                        {operationResult}
                    </p>
                )}
                <Card className="min-w-0 rounded-[13px] shadow-none">
                    <CardHeader>
                        <CardTitle className="text-[17px]">
                            Implementation and follow-up
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div
                            className="overflow-x-auto focus-visible:ring-2 focus-visible:ring-ring"
                            tabIndex={0}
                            role="region"
                            aria-label="Accepted action table"
                        >
                            <table className="w-full min-w-[850px] text-left text-xs">
                                <thead className="bg-secondary text-[11px] text-primary">
                                    <tr>
                                        {[
                                            'Source rule',
                                            'Assigned owner',
                                            'Due date',
                                            'Status',
                                            'Next step',
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
                                    {actions.data.map((action) => (
                                        <tr
                                            key={action.id}
                                            className="border-b align-top"
                                        >
                                            <td className="max-w-64 space-y-2 px-3 py-4">
                                                <Badge variant="secondary">
                                                    {action.ruleCode}
                                                </Badge>
                                                <p className="font-semibold break-words">
                                                    {action.title}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {action.buildingName}
                                                    <br />
                                                    Accepted source: run #
                                                    {action.forecastId} ·{' '}
                                                    {action.targetMonth.slice(
                                                        0,
                                                        7,
                                                    )}
                                                </p>
                                                {action.outcome && (
                                                    <details>
                                                        <summary className="cursor-pointer text-primary">
                                                            Latest reported
                                                            outcome
                                                        </summary>
                                                        <p className="mt-2 break-words whitespace-pre-wrap">
                                                            {action.outcome}
                                                        </p>
                                                    </details>
                                                )}
                                            </td>
                                            <td className="px-3 py-4">
                                                {action.ownerName}
                                            </td>
                                            <td className="px-3 py-4">
                                                {action.dueDate}
                                            </td>
                                            <td className="px-3 py-4">
                                                <Badge
                                                    variant="secondary"
                                                    className="capitalize"
                                                >
                                                    {action.status}
                                                </Badge>
                                                {action.completedAt && (
                                                    <p className="mt-2 text-[11px] text-muted-foreground">
                                                        Completed{' '}
                                                        {new Date(
                                                            action.completedAt,
                                                        ).toLocaleDateString(
                                                            'en-PH',
                                                            {
                                                                timeZone:
                                                                    'Asia/Manila',
                                                            },
                                                        )}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="max-w-64 px-3 py-4">
                                                <ActionFollowUp
                                                    key={action.id}
                                                    action={action}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {actions.data.length === 0 && (
                            <p className="px-3 py-6 text-xs text-muted-foreground">
                                No accepted actions match these filters. Actions
                                are created only through an evaluated,
                                policy-authorized recommendation acceptance.
                            </p>
                        )}
                        <nav
                            aria-label="Tracked action pages"
                            className="mt-4 flex flex-wrap items-center gap-3 text-xs"
                        >
                            <p className="text-muted-foreground">
                                Page {actions.current_page} of{' '}
                                {actions.last_page}
                            </p>
                            {actions.prev_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={actions.prev_page_url}>
                                        Previous
                                    </Link>
                                </Button>
                            )}
                            {actions.next_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={actions.next_page_url}>
                                        Next
                                    </Link>
                                </Button>
                            )}
                        </nav>
                    </CardContent>
                </Card>
                <EnergyNotice title="Follow-through is measured">
                    Keep evidence of the action and actual consumption after
                    implementation. The system does not automatically attribute
                    energy savings. Energy Managers record follow-up and
                    completion of accepted actions; source references identify
                    the historical acceptance, not current advice. Tracking
                    retained actions does not generate new recommendations or
                    enable the disabled rule policy.
                </EnergyNotice>
                <EnergyFooter />
            </div>
        </>
    );
}
