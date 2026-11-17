import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import ActionTrackerController from '@/actions/App/Http/Controllers/ActionTrackerController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import type { ActionHistoryPage, ActionStatus, TrackedAction } from '@/types';

function ActionHistory({
    action,
    onClose,
    onRestoreFocus,
}: {
    action: TrackedAction;
    onClose: () => void;
    onRestoreFocus: () => void;
}) {
    const [url, setUrl] = useState(
        ActionTrackerController.history.url(action.id),
    );
    const [history, setHistory] = useState<ActionHistoryPage | null>(null);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error('Action history unavailable');
                return response.json() as Promise<ActionHistoryPage>;
            })
            .then((data) => {
                if (!controller.signal.aborted) setHistory(data);
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setError(
                        'Unable to load action history. Refresh your access or retry.',
                    );
            });
        return () => controller.abort();
    }, [url, attempt]);
    const changePage = (next: string) => {
        setHistory(null);
        setError('');
        setUrl(next);
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                className="max-h-[85vh] overflow-y-auto sm:max-w-2xl"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    onRestoreFocus();
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        Action #{action.id} follow-up history
                    </DialogTitle>
                    <DialogDescription>
                        Retained status and reported outcomes. Actor names
                        reflect current accounts. These reports do not establish
                        energy savings.
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
                ) : !history ? (
                    <div role="status">
                        <p className="text-sm">Loading follow-up history…</p>
                        <Skeleton className="mt-3 h-24 w-full" />
                    </div>
                ) : (
                    <div className="space-y-4 text-sm">
                        {history.data.length === 0 ? (
                            <p>
                                No follow-up recorded yet. The accepted source
                                decision and original assignment remain
                                retained.
                            </p>
                        ) : (
                            <ol className="space-y-4">
                                {history.data.map((entry) => (
                                    <li
                                        key={entry.id}
                                        className="space-y-2 rounded-lg border p-4"
                                    >
                                        <p className="font-semibold capitalize">
                                            {entry.before.status} →{' '}
                                            {entry.after.status}
                                        </p>
                                        <p className="break-words whitespace-pre-wrap">
                                            {entry.after.outcome}
                                        </p>
                                        <p className="text-xs text-[#52647e] dark:text-muted-foreground">
                                            Version {entry.version} →{' '}
                                            {entry.version + 1} ·{' '}
                                            {entry.actorName}
                                            {entry.actorId !== null
                                                ? ` (#${entry.actorId})`
                                                : ''}
                                            <br />
                                            {entry.createdAt
                                                ? new Date(
                                                      entry.createdAt,
                                                  ).toLocaleString('en-PH', {
                                                      timeZone: 'Asia/Manila',
                                                  })
                                                : 'Time not recorded'}{' '}
                                            (Asia/Manila)
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        )}
                        <nav
                            aria-label="Action history pages"
                            className="flex gap-3"
                        >
                            {history.prev_page_url && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        changePage(history.prev_page_url!)
                                    }
                                >
                                    Previous
                                </Button>
                            )}
                            {history.next_page_url && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        changePage(history.next_page_url!)
                                    }
                                >
                                    Next
                                </Button>
                            )}
                        </nav>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

export function ActionFollowUp({ action }: { action: TrackedAction }) {
    const [mode, setMode] = useState<'update' | 'history' | null>(null);
    const updateTrigger = useRef<HTMLButtonElement>(null);
    const historyTrigger = useRef<HTMLButtonElement>(null);
    const form = useForm({
        version: action.version,
        status: 'open' as ActionStatus,
        outcome: '',
    });
    return (
        <>
            <div className="flex flex-wrap gap-2">
                {action.canUpdate && (
                    <Button
                        ref={updateTrigger}
                        size="sm"
                        onClick={() => {
                            form.clearErrors();
                            form.setData({
                                version: action.version,
                                status: 'open',
                                outcome: '',
                            });
                            setMode('update');
                        }}
                    >
                        Record follow-up
                    </Button>
                )}
                <Button
                    ref={historyTrigger}
                    variant="outline"
                    size="sm"
                    onClick={() => setMode('history')}
                >
                    History
                </Button>
            </div>
            {action.status === 'completed' && (
                <p className="mt-2 text-[11px] text-muted-foreground">
                    Completed record retained
                </p>
            )}
            {mode === 'history' && (
                <ActionHistory
                    action={action}
                    onClose={() => setMode(null)}
                    onRestoreFocus={() => historyTrigger.current?.focus()}
                />
            )}
            <Dialog
                open={mode === 'update'}
                onOpenChange={(open) => {
                    if (!open && !form.processing) setMode(null);
                }}
            >
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        (
                            updateTrigger.current ?? historyTrigger.current
                        )?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            Action #{action.id}: Record follow-up
                        </DialogTitle>
                        <DialogDescription>
                            Energy Manager review of {action.title}. Follow-up
                            is retained with actor and timestamp. Completion is
                            final in this lifecycle.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.patch(
                                ActionTrackerController.update.url(action.id),
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setMode(null);
                                        form.reset();
                                        toast.success(
                                            'Action follow-up recorded and audited.',
                                        );
                                    },
                                },
                            );
                        }}
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor={`action-status-${action.id}`}>
                                Status after review
                            </Label>
                            <select
                                id={`action-status-${action.id}`}
                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                value={form.data.status}
                                onChange={(event) =>
                                    form.setData(
                                        'status',
                                        event.target.value === 'completed'
                                            ? 'completed'
                                            : 'open',
                                    )
                                }
                            >
                                <option value="open">
                                    Open — record follow-up
                                </option>
                                <option value="completed">
                                    Completed — record outcome
                                </option>
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor={`action-outcome-${action.id}`}>
                                Reported outcome / follow-up evidence
                            </Label>
                            <textarea
                                id={`action-outcome-${action.id}`}
                                className="min-h-32 w-full rounded-md border bg-background p-3 text-sm"
                                required
                                maxLength={4000}
                                value={form.data.outcome}
                                onChange={(event) =>
                                    form.setData('outcome', event.target.value)
                                }
                            />
                            <p className="text-xs text-[#52647e] dark:text-muted-foreground">
                                Describe the work and supporting observations.
                                This is a human report, not verified or
                                automatically attributed energy savings.
                            </p>
                        </div>
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
                                onClick={() => setMode(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing
                                    ? 'Recording…'
                                    : 'Save follow-up'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
