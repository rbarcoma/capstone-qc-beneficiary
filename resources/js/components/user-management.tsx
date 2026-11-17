import { router, usePage } from '@inertiajs/react';
import { Copy, Eye, EyeOff, Plus } from 'lucide-react';
import {
    type FormEvent,
    type MouseEvent,
    type ReactNode,
    useEffect,
    useRef,
    useState,
} from 'react';
import { toast } from 'sonner';
import UsersAccessController from '@/actions/App/Http/Controllers/UsersAccessController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { store as confirmPassword } from '@/routes/password/confirm';
import type {
    AccountHistory,
    UserAccount,
    UsersAccessData,
    UserRole,
} from '@/types';

type FailureFields = Record<string, string>;
type MutationResult = {
    user: UserAccount;
    message: string;
    temporaryPassword?: string;
};
type RequestSpec = {
    url: string;
    method: string;
    body: Record<string, unknown>;
};
type Mutate = (spec: RequestSpec) => Promise<MutationResult | null>;

class ApiFailure extends Error {
    constructor(
        message: string,
        public fields: FailureFields = {},
        public status = 0,
    ) {
        super(message);
    }
}

async function jsonRequest<T>(
    url: string,
    method = 'GET',
    body?: Record<string, unknown>,
    signal?: AbortSignal,
): Promise<T> {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const fields = Object.fromEntries(
            Object.entries(data.errors ?? {}).map(([key, value]) => [
                key,
                Array.isArray(value) ? String(value[0]) : String(value),
            ]),
        );
        throw new ApiFailure(
            data.message ?? 'Request failed. Refresh your access or retry.',
            fields,
            response.status,
        );
    }
    return data as T;
}

function Status({ user }: { user: UserAccount }) {
    return (
        <Badge
            variant="outline"
            className={
                !user.isActive
                    ? 'text-muted-foreground'
                    : user.setupRequired
                      ? 'bg-[#fff5e3] text-primary'
                      : 'bg-secondary text-primary'
            }
        >
            {!user.isActive
                ? 'Deactivated'
                : user.setupExpired
                  ? 'Setup expired'
                  : user.setupRequired
                    ? 'Awaiting setup'
                    : 'Active'}
        </Badge>
    );
}

function FieldError({ name, errors }: { name: string; errors: FailureFields }) {
    return (
        <div
            id={`account-${name}-error`}
            role={errors[name] ? 'alert' : undefined}
        >
            <InputError message={errors[name]} />
        </div>
    );
}

function AccountForm({
    data,
    user,
    mutate,
    done,
    close,
    restore,
}: {
    data: UsersAccessData;
    user?: UserAccount;
    mutate: Mutate;
    done: (result: MutationResult) => void;
    close: () => void;
    restore: () => void;
}) {
    const { auth } = usePage().props;
    const self = user?.id === auth.user.id;
    const [values, setValues] = useState({
        name: user?.name ?? '',
        email: user?.email ?? '',
        role: user?.role ?? ('viewer' as UserRole),
        building_ids: user?.buildingIds ?? [],
        restricted_modules: user?.restrictedModules ?? [],
        reason: '',
    });
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<FailureFields>({});
    const [failure, setFailure] = useState('');
    const restricted = data.roleOptions.find(
        (role) => role.value === values.role,
    )?.buildingRestricted;
    const roleChanged = !!user && user.role !== values.role;
    const roleLabel =
        data.roleOptions.find((role) => role.value === values.role)?.label ??
        values.role;
    const setRole = (role: UserRole) => {
        const newlyAllowed = user ? data.moduleCatalogue
            .filter(
                (module) =>
                    module.roles.includes(role) &&
                    !module.roles.includes(values.role) &&
                    !module.requiredFor.includes(role),
            )
            .map((module) => module.key) : [];
        const keep = [
            ...new Set([...values.restricted_modules, ...newlyAllowed]),
        ].filter(
            (key) =>
                !data.moduleCatalogue
                    .find((module) => module.key === key)
                    ?.requiredFor.includes(role),
        );
        setValues({
            ...values,
            role,
            building_ids: [],
            restricted_modules: keep,
        });
    };
    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setErrors({});
        setFailure('');
        try {
            const result = await mutate({
                url: user
                    ? UsersAccessController.update.url(user.id)
                    : UsersAccessController.store.url(),
                method: user ? 'PATCH' : 'POST',
                body: {
                    ...values,
                    building_ids: restricted ? values.building_ids : [],
                    ...(user ? { version: user.version } : {}),
                },
            });
            if (result) done(result);
        } catch (error) {
            setErrors(error instanceof ApiFailure ? error.fields : {});
            setFailure(
                error instanceof Error
                    ? error.message
                    : 'No success confirmed. Review the account before retrying.',
            );
        } finally {
            setBusy(false);
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) close();
            }}
        >
            <DialogContent
                className="flex max-h-[90dvh] flex-col gap-4 overflow-hidden rounded-[13px] bg-card p-0 sm:max-w-[840px] [&_[data-slot=input]]:h-[42px] [&_[data-slot=input]]:rounded-[9px] [&_[data-slot=label]]:text-xs [&_[data-slot=label]]:font-semibold [&_[data-slot=dialog-footer]_button]:h-11 [&_[data-slot=dialog-footer]_button]:rounded-[9px]"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    restore();
                }}
                onEscapeKeyDown={(event) => {
                    if (busy) event.preventDefault();
                }}
            >
                <DialogHeader className="px-4 pt-6 text-left sm:px-6">
                    <DialogTitle className="pr-6 text-2xl leading-7 break-words text-primary">
                        {user ? `Edit user · ${user.name}` : 'Add user'}
                    </DialogTitle>
                    <DialogDescription className="text-xs">
                        {user
                            ? 'Review identity and access changes before saving.'
                            : 'Create an account and review its effective access. Fields marked * are required.'}
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={submit}
                    className="flex min-h-0 flex-col"
                    noValidate
                >
                    <div className="min-h-0 space-y-4 overflow-y-auto px-4 pb-4 sm:px-6">
                        {failure && (
                            <div
                                role="alert"
                                className="rounded-[9px] border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                            >
                                <p className="font-semibold">
                                    Unable to save this account
                                </p>
                                <p>{failure}</p>
                                {errors.version && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="mt-2"
                                        onClick={close}
                                    >
                                        Close and refresh details
                                    </Button>
                                )}
                            </div>
                        )}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(['name', 'email'] as const).map((field) => (
                                <div className="space-y-1.5" key={field}>
                                    <Label htmlFor={`account-${field}`}>
                                        {field === 'name'
                                            ? 'Full name *'
                                            : 'Email address *'}
                                    </Label>
                                    <Input
                                        id={`account-${field}`}
                                        type={
                                            field === 'email' ? 'email' : 'text'
                                        }
                                        value={values[field]}
                                        onChange={(event) =>
                                            setValues({
                                                ...values,
                                                [field]: event.target.value,
                                            })
                                        }
                                        maxLength={255}
                                        autoComplete="off"
                                        autoFocus={field === 'name'}
                                        aria-invalid={!!errors[field]}
                                        aria-describedby={`account-${field}-error`}
                                        required
                                    />
                                    <FieldError name={field} errors={errors} />
                                </div>
                            ))}
                            <div className="space-y-1.5">
                                <Label htmlFor="account-role">Role *</Label>
                                <select
                                    id="account-role"
                                    className="h-10 w-full rounded-[9px] border border-input bg-card px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                                    value={values.role}
                                    onChange={(event) =>
                                        setRole(event.target.value as UserRole)
                                    }
                                    disabled={self}
                                    aria-describedby="account-role-error"
                                >
                                    {data.roleOptions.map((role) => (
                                        <option
                                            value={role.value}
                                            key={role.value}
                                        >
                                            {role.label}
                                        </option>
                                    ))}
                                </select>
                                <FieldError name="role" errors={errors} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="account-state">
                                    Account state
                                </Label>
                                <Input
                                    id="account-state"
                                    readOnly
                                    className="bg-secondary"
                                    value={
                                        user
                                            ? !user.isActive
                                                ? 'Deactivated'
                                                : user.setupRequired
                                                  ? 'Password setup required'
                                                  : 'Active'
                                            : 'Password setup required'
                                    }
                                />
                            </div>
                        </div>
                        <fieldset className="space-y-2">
                            <legend className="text-xs font-semibold">
                                {restricted
                                    ? 'Assigned buildings'
                                    : 'Building scope (role-defined)'}
                            </legend>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {data.buildings.map((building) => (
                                    <label
                                        key={building.id}
                                        className="flex min-h-11 items-center gap-3 text-sm"
                                    >
                                        <Checkbox
                                            checked={
                                                !restricted ||
                                                values.building_ids.includes(
                                                    building.id,
                                                )
                                            }
                                            disabled={!restricted || self}
                                            onCheckedChange={(checked) =>
                                                setValues({
                                                    ...values,
                                                    building_ids: checked
                                                        ? [
                                                              ...values.building_ids,
                                                              building.id,
                                                          ]
                                                        : values.building_ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  building.id,
                                                          ),
                                                })
                                            }
                                        />
                                        <span>
                                            {building.name}
                                            <span className="block text-xs text-muted-foreground">
                                                {restricted
                                                    ? values.building_ids.includes(
                                                          building.id,
                                                      )
                                                        ? 'Assigned'
                                                        : 'Not assigned'
                                                    : 'Included · all-building role'}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            {data.buildings.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No buildings are registered.
                                </p>
                            )}
                            <p className="text-xs text-muted-foreground">
                                {restricted
                                    ? 'Only selected buildings are accessible. No selection means no building access.'
                                    : 'This role has all-building access. Selected-building restrictions apply to Facilities only.'}
                            </p>
                            <FieldError name="building_ids" errors={errors} />
                        </fieldset>
                        {roleChanged && (
                            <div
                                role="status"
                                className="rounded-[9px] bg-[#fff5e3] p-3 text-xs text-primary"
                            >
                                Role changes may expand building scope, cost
                                visibility and operations. Newly available
                                modules start restricted; review each selection.
                                C5 remains disabled.
                            </div>
                        )}
                        <div className="rounded-[9px] bg-secondary p-3 text-xs">
                            <p className="font-semibold text-primary">
                                Module permissions · role ceiling: {roleLabel}
                            </p>
                            <p className="mt-1 text-muted-foreground">
                                Uncheck to restrict. Locked modules cannot be
                                granted. Role, building, cost and workflow
                                checks still apply. Personal profile/security
                                and logout remain available.
                            </p>
                        </div>
                        {!self && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setValues({
                                        ...values,
                                        restricted_modules: [],
                                    })
                                }
                            >
                                Reset to role defaults
                            </Button>
                        )}
                        {self && (
                            <p className="text-xs text-muted-foreground">
                                Another Admin must change your role or module
                                access.
                            </p>
                        )}
                        <fieldset>
                            <legend className="sr-only">
                                Module permissions
                            </legend>
                            <div className="grid gap-x-6 sm:grid-cols-2">
                                {data.moduleCatalogue.map((module) => {
                                    const allowed = module.roles.includes(
                                        values.role,
                                    );
                                    const essential =
                                        module.requiredFor.includes(
                                            values.role,
                                        );
                                    return (
                                        <label
                                            key={module.key}
                                            className={`flex min-h-12 items-center gap-3 py-1.5 text-xs ${!allowed ? 'text-muted-foreground' : ''}`}
                                        >
                                            <Checkbox
                                                checked={
                                                    allowed &&
                                                    (essential ||
                                                        !values.restricted_modules.includes(
                                                            module.key,
                                                        ))
                                                }
                                                disabled={
                                                    !allowed ||
                                                    essential ||
                                                    self
                                                }
                                                onCheckedChange={(checked) =>
                                                    setValues({
                                                        ...values,
                                                        restricted_modules:
                                                            checked
                                                                ? values.restricted_modules.filter(
                                                                      (key) =>
                                                                          key !==
                                                                          module.key,
                                                                  )
                                                                : [
                                                                      ...values.restricted_modules,
                                                                      module.key,
                                                                  ],
                                                    })
                                                }
                                            />
                                            <span>
                                                {module.label}
                                                <span className="block text-[11px] leading-[17px] text-muted-foreground">
                                                    {essential
                                                        ? 'Required · baseline access'
                                                        : !allowed
                                                          ? module.roles
                                                                .length === 0
                                                              ? module.description
                                                              : 'Locked · not permitted by role'
                                                          : values.restricted_modules.includes(
                                                                  module.key,
                                                              )
                                                            ? 'Restricted for this user'
                                                            : module.description}
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                            <FieldError
                                name="restricted_modules"
                                errors={errors}
                            />
                        </fieldset>
                        <div className="space-y-2 rounded-[9px] bg-[#fff5e3] p-3 text-xs">
                            <p className="font-semibold text-primary">
                                Access summary
                            </p>
                            <p>
                                {roleLabel} ·{' '}
                                {restricted
                                    ? `${values.building_ids.length} assigned building(s)`
                                    : 'All buildings'}{' '}
                                · {values.restricted_modules.length} module
                                restriction(s).
                            </p>
                            {user && (
                                <p className="text-muted-foreground">
                                    Module changes:{' '}
                                    {data.moduleCatalogue
                                        .filter(
                                            (module) =>
                                                module.roles.includes(
                                                    values.role,
                                                ) &&
                                                values.restricted_modules.includes(
                                                    module.key,
                                                ) !==
                                                    user.restrictedModules.includes(
                                                        module.key,
                                                    ),
                                        )
                                        .map(
                                            (module) =>
                                                `${module.label} → ${values.restricted_modules.includes(module.key) ? 'restricted' : 'allowed'}`,
                                        )
                                        .join('; ') || 'None'}
                                    . Access changes revoke sessions; historical
                                    records remain attributed.
                                </p>
                            )}
                            <Label htmlFor="account-reason">
                                Reason {user ? '*' : '(optional)'}
                            </Label>
                            <Input
                                id="account-reason"
                                className="bg-card"
                                value={values.reason}
                                onChange={(event) =>
                                    setValues({
                                        ...values,
                                        reason: event.target.value,
                                    })
                                }
                                maxLength={1000}
                                required={!!user}
                                aria-describedby="account-reason-error"
                            />
                            <FieldError name="reason" errors={errors} />
                        </div>
                    </div>
                    <DialogFooter className="shrink-0 border-t bg-card px-4 py-4 sm:px-6">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={close}
                            disabled={busy}
                        >
                            Cancel
                        </Button>
                        <Button disabled={busy}>
                            {busy && <Spinner />}
                            {busy
                                ? 'Saving…'
                                : user
                                  ? 'Save changes'
                                  : 'Create user'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AccountDetails({
    user,
    data,
    close,
    edit,
    change,
    restore,
}: {
    user: UserAccount;
    data: UsersAccessData;
    close: () => void;
    edit: (user: UserAccount) => void;
    change: (kind: 'status' | 'password', user: UserAccount) => void;
    restore: () => void;
}) {
    const { auth } = usePage().props;
    const [detail, setDetail] = useState<{
        user: UserAccount;
        history: AccountHistory;
    } | null>(null);
    const [url, setUrl] = useState(UsersAccessController.show.url(user.id));
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        jsonRequest<{ user: UserAccount; history: AccountHistory }>(
            url,
            'GET',
            undefined,
            controller.signal,
        )
            .then(setDetail)
            .catch(() => {
                if (!controller.signal.aborted)
                    setError(
                        'Unable to load account details. Refresh your access or retry.',
                    );
            });
        return () => controller.abort();
    }, [url, retry]);
    const current = detail?.user;
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) close();
            }}
        >
            <DialogContent
                className="max-h-[90dvh] overflow-y-auto rounded-[13px] bg-card sm:max-w-[720px] [&_[data-slot=dialog-footer]_button]:h-11"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    restore();
                }}
            >
                <DialogHeader className="text-left">
                    <DialogTitle className="break-words pr-5 text-2xl text-primary">
                        User details{current ? ` · ${current.name}` : ''}
                    </DialogTitle>
                    <DialogDescription>
                        Read-only account and effective-access snapshot.
                    </DialogDescription>
                </DialogHeader>
                {error ? (
                    <div role="alert">
                        <p>{error}</p>
                        <Button
                            className="mt-3"
                            variant="outline"
                            onClick={() => {
                                setError('');
                                setRetry((value) => value + 1);
                            }}
                        >
                            Retry
                        </Button>
                    </div>
                ) : !current || !detail ? (
                    <div role="status">
                        Loading account…
                        <Skeleton className="mt-3 h-32" />
                    </div>
                ) : (
                    <>
                        <div className="space-y-2 rounded-[9px] bg-secondary p-4 text-sm break-words">
                            <p className="font-semibold">{current.name}</p>
                            <p>{current.email}</p>
                            <p>
                                {current.roleLabel} · <Status user={current} />
                            </p>
                            <p>
                                Email:{' '}
                                {current.emailVerified
                                    ? 'Verified'
                                    : 'Verification pending'}
                            </p>
                            <p>
                                Building scope:{' '}
                                {current.role === 'facilities_personnel'
                                    ? current.buildingNames.join(', ') ||
                                      'None assigned'
                                    : 'All buildings'}
                            </p>
                            {current.temporaryPasswordExpiresAt && (
                                <p>
                                    Temporary credential expiry:{' '}
                                    {new Date(
                                        current.temporaryPasswordExpiresAt,
                                    ).toLocaleString()}
                                </p>
                            )}
                        </div>
                        <h3 className="font-semibold text-primary">
                            Effective module access
                        </h3>
                        <ul className="grid gap-2 text-xs sm:grid-cols-2">
                            {data.moduleCatalogue.map((module) => (
                                <li key={module.key}>
                                    {module.label}:{' '}
                                    <span className="text-muted-foreground">
                                        {!module.roles.includes(current.role)
                                            ? module.roles.length
                                                ? 'Role-locked'
                                                : 'Unavailable'
                                            : current.restrictedModules.includes(
                                                    module.key,
                                                )
                                              ? 'Restricted'
                                              : 'Allowed within role'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="text-xs text-muted-foreground">
                            Inactive accounts cannot sign in. Accounts awaiting
                            password setup cannot access modules. C5 decisions
                            and unimplemented settings remain unavailable.
                        </p>
                        <section className="space-y-3 rounded-[9px] bg-[#fff5e3] p-4">
                            <h3 className="font-semibold text-primary">
                                Access history
                            </h3>
                            {detail.history.data.length === 0 ? (
                                <p className="text-sm">
                                    No account-management events recorded yet.
                                </p>
                            ) : (
                                detail.history.data.map((entry) => (
                                    <article
                                        key={entry.id}
                                        className="space-y-1 border-b pb-3 text-xs last:border-0"
                                    >
                                        <p className="font-semibold">
                                            {entry.event.replace(/[._]/g, ' ')}
                                        </p>
                                        <p>
                                            {entry.actorName} ·{' '}
                                            {new Date(
                                                entry.createdAt,
                                            ).toLocaleString()}
                                        </p>
                                        {entry.reason && (
                                            <p className="break-words">
                                                {entry.reason}
                                            </p>
                                        )}
                                        <details>
                                            <summary className="cursor-pointer">
                                                View retained change evidence
                                            </summary>
                                            <pre className="mt-2 max-h-48 overflow-auto rounded border bg-card p-2 break-words whitespace-pre-wrap">
                                                {JSON.stringify(
                                                    {
                                                        before: entry.before,
                                                        after: entry.after,
                                                    },
                                                    null,
                                                    2,
                                                )}
                                            </pre>
                                        </details>
                                    </article>
                                ))
                            )}
                            <nav
                                aria-label="Account history pages"
                                className="flex gap-3"
                            >
                                {[
                                    detail.history.prev_page_url,
                                    detail.history.next_page_url,
                                ].map(
                                    (link, index) =>
                                        link && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                key={link}
                                                onClick={() => {
                                                    setDetail(null);
                                                    setUrl(link);
                                                }}
                                            >
                                                {index === 0
                                                    ? 'Previous'
                                                    : 'Next'}
                                            </Button>
                                        ),
                                )}
                            </nav>
                        </section>
                        <DialogFooter className="flex-wrap">
                            <Button variant="outline" onClick={close}>
                                Close
                            </Button>
                            <Button onClick={() => edit(current)}>
                                Edit user
                            </Button>
                            <Button
                                variant="outline"
                                disabled={current.id === auth.user.id}
                                onClick={() => change('status', current)}
                            >
                                {current.isActive ? 'Deactivate' : 'Reactivate'}
                            </Button>
                            {current.id !== auth.user.id &&
                                current.isActive && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            change('password', current)
                                        }
                                    >
                                        Issue temporary password
                                    </Button>
                                )}
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function AccountConfirmation({
    user,
    kind,
    mutate,
    done,
    close,
    restore,
}: {
    user: UserAccount;
    kind: 'status' | 'password';
    mutate: Mutate;
    done: (result: MutationResult) => void;
    close: () => void;
    restore: () => void;
}) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fields, setFields] = useState<FailureFields>({});
    const title =
        kind === 'password'
            ? 'Issue temporary password'
            : user.isActive
              ? 'Deactivate user'
              : 'Reactivate user';
    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError('');
        setFields({});
        try {
            const result = await mutate({
                url:
                    kind === 'password'
                        ? UsersAccessController.temporaryPassword.url(user.id)
                        : UsersAccessController.status.url(user.id),
                method: kind === 'password' ? 'POST' : 'PATCH',
                body: {
                    version: user.version,
                    reason,
                    ...(kind === 'status' ? { is_active: !user.isActive } : {}),
                },
            });
            if (result) done(result);
        } catch (failure) {
            setFields(failure instanceof ApiFailure ? failure.fields : {});
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'No success confirmed. Refresh and review the account.',
            );
        } finally {
            setBusy(false);
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) close();
            }}
        >
            <DialogContent
                className="max-h-[90dvh] overflow-y-auto rounded-[13px] bg-card sm:max-w-[560px]"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    restore();
                }}
            >
                <DialogHeader className="text-left">
                    <DialogTitle className="pr-5 text-2xl text-primary">
                        {title}?
                    </DialogTitle>
                    <DialogDescription>
                        {user.name} · {user.email}
                        <br />
                        {user.roleLabel} ·{' '}
                        {user.role === 'facilities_personnel'
                            ? user.buildingNames.join(', ') || 'No buildings'
                            : 'All buildings'}
                    </DialogDescription>
                </DialogHeader>
                <p className="text-sm text-muted-foreground">
                    {kind === 'password'
                        ? 'Current credentials and sessions become invalid. A replacement password will be shown once and must be changed before module access.'
                        : user.isActive
                          ? 'Sign-in is blocked and sessions are revoked. Billing attribution, decisions and action history are retained.'
                          : 'Restore sign-in with the reviewed role and restrictions. Old sessions remain invalid; a fresh sign-in is required.'}
                </p>
                <form className="space-y-4" onSubmit={submit}>
                    {error && (
                        <div role="alert" className="text-sm text-destructive">
                            {fields.user ?? fields.version ?? error}
                        </div>
                    )}
                    <Label htmlFor="account-confirm-reason">Reason *</Label>
                    <textarea
                        id="account-confirm-reason"
                        className="min-h-20 w-full rounded-[9px] border border-input bg-card p-3 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        required
                        maxLength={1000}
                        aria-describedby="account-confirm-reason-error"
                    />
                    <div
                        id="account-confirm-reason-error"
                        role={fields.reason ? 'alert' : undefined}
                    >
                        <InputError message={fields.reason} />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Self-deactivation and removing the last usable Admin are
                        prohibited. Do not include passwords in the reason.
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={close}
                            disabled={busy}
                        >
                            Cancel
                        </Button>
                        <Button disabled={busy}>
                            {busy && <Spinner />}
                            {busy ? 'Saving…' : title}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function UserManagement({
    data,
    children,
}: {
    data: UsersAccessData;
    children?: ReactNode;
}) {
    const { auth } = usePage().props;
    const [modal, setModal] = useState<{
        kind: 'create' | 'edit' | 'view' | 'status' | 'password';
        user?: UserAccount;
    } | null>(null);
    const [credential, setCredential] = useState<MutationResult | null>(null);
    const [reveal, setReveal] = useState(false);
    const [filters, setFilters] = useState(data.filters);
    const [loading, setLoading] = useState(false);
    const [listError, setListError] = useState('');
    const [success, setSuccess] = useState('');
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [confirmBusy, setConfirmBusy] = useState(false);
    const [password, setPassword] = useState('');
    const [confirmError, setConfirmError] = useState('');
    const pending = useRef<{
        spec: RequestSpec;
        resolve: (result: MutationResult | null) => void;
        reject: (error: unknown) => void;
    } | null>(null);
    const opener = useRef<HTMLElement | null>(null);
    const add = useRef<HTMLButtonElement>(null);
    const restore = () =>
        (opener.current?.isConnected ? opener.current : add.current)?.focus();
    const open = (
        kind: NonNullable<typeof modal>['kind'],
        event: MouseEvent<HTMLElement>,
        user?: UserAccount,
    ) => {
        opener.current = event.currentTarget;
        setModal({ kind, user });
    };
    useEffect(() => {
        const clear = () => {
            setCredential(null);
            setPassword('');
        };
        window.addEventListener('pagehide', clear);
        return () => window.removeEventListener('pagehide', clear);
    }, []);
    const mutate: Mutate = async (spec) => {
        try {
            return await jsonRequest<MutationResult>(
                spec.url,
                spec.method,
                spec.body,
            );
        } catch (error) {
            if (error instanceof ApiFailure && error.status === 423) {
                setConfirmError('');
                setConfirmOpen(true);
                return new Promise((resolve, reject) => {
                    pending.current = { spec, resolve, reject };
                });
            }
            throw error;
        }
    };
    const complete = (result: MutationResult) => {
        setModal(null);
        setSuccess(result.message);
        toast.success(result.message);
        if (result.temporaryPassword) {
            setReveal(false);
            setCredential(result);
        }
        router.reload({ only: ['users', 'counts', 'auth'] });
    };
    const navigate = (page?: number, reset = false) => {
        const values = reset
            ? { search: '', role: '', status: '', building: '' }
            : filters;
        if (reset) setFilters(values);
        setListError('');
        router.get(
            UsersAccessController.index.url(),
            { ...values, ...(page ? { page } : {}) },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
                onError: () =>
                    setListError(
                        'Unable to load users. Check filters and retry.',
                    ),
            },
        );
    };
    const actions = (user: UserAccount) => (
        <div className="flex flex-wrap gap-2">
            <Button
                variant="outline"
                size="sm"
                onClick={(event) => open('view', event, user)}
            >
                View
            </Button>
            <Button
                variant="outline"
                size="sm"
                onClick={(event) => open('edit', event, user)}
            >
                Edit
            </Button>
            <Button
                variant="outline"
                size="sm"
                disabled={user.id === auth.user.id}
                title={
                    user.id === auth.user.id
                        ? 'Use another Admin to change your status'
                        : undefined
                }
                onClick={(event) => open('status', event, user)}
            >
                {user.id === auth.user.id
                    ? 'Your account'
                    : user.isActive
                      ? 'Deactivate'
                      : 'Reactivate'}
            </Button>
        </div>
    );
    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="text-[11px] font-bold tracking-[0.06em] text-[#2e61a1] uppercase">
                        QC Energy Intelligence • Workspace
                    </p>
                    <h1 className="mt-1.5 text-[29px] leading-9 font-semibold text-[#1c2b40]">
                        Users &amp; Access
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Manage accounts, role-limited access and assigned
                        buildings.
                    </p>
                </div>
                <Button ref={add} onClick={(event) => open('create', event)}>
                    <Plus className="size-4" />
                    Add user
                </Button>
            </div>
            {children}
            {success && (
                <div
                    role="status"
                    className="rounded-[9px] bg-secondary p-4 text-sm text-primary"
                >
                    ✓ {success}
                </div>
            )}
            <section
                className="space-y-4 rounded-[13px] border bg-card p-4 sm:p-5"
                aria-labelledby="accounts-title"
            >
                <h2 id="accounts-title" className="text-[17px] font-semibold">
                    User accounts
                </h2>
                <p className="text-xs text-muted-foreground">
                    {data.counts.total} accounts · {data.counts.active} active ·{' '}
                    {data.counts.setupRequired} awaiting setup ·{' '}
                    {data.counts.deactivated} deactivated
                </p>
                <form
                    className="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr_1fr_auto]"
                    onSubmit={(event) => {
                        event.preventDefault();
                        navigate();
                    }}
                >
                    <div className="space-y-1">
                        <Label htmlFor="user-search">
                            Search name or email
                        </Label>
                        <Input
                            id="user-search"
                            value={filters.search}
                            onChange={(event) =>
                                setFilters({
                                    ...filters,
                                    search: event.target.value,
                                })
                            }
                            maxLength={255}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="user-role-filter">Role</Label>
                        <select
                            id="user-role-filter"
                            className="h-10 w-full rounded-[9px] border border-input bg-card px-3 text-sm"
                            value={filters.role}
                            onChange={(event) =>
                                setFilters({
                                    ...filters,
                                    role: event.target.value,
                                })
                            }
                        >
                            <option value="">All roles</option>
                            {data.roleOptions.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="user-status-filter">Status</Label>
                        <select
                            id="user-status-filter"
                            className="h-10 w-full rounded-[9px] border border-input bg-card px-3 text-sm"
                            value={filters.status}
                            onChange={(event) =>
                                setFilters({
                                    ...filters,
                                    status: event.target.value,
                                })
                            }
                        >
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="setup_required">
                                Awaiting setup / expired
                            </option>
                            <option value="deactivated">Deactivated</option>
                        </select>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="user-building-filter">Building</Label>
                        <select
                            id="user-building-filter"
                            className="h-10 w-full rounded-[9px] border border-input bg-card px-3 text-sm"
                            value={filters.building}
                            onChange={(event) =>
                                setFilters({
                                    ...filters,
                                    building: event.target.value,
                                })
                            }
                        >
                            <option value="">All buildings</option>
                            {data.buildings.map((building) => (
                                <option key={building.id} value={building.id}>
                                    {building.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex gap-2">
                        <Button type="submit" disabled={loading}>
                            Apply
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => navigate(undefined, true)}
                        >
                            Reset
                        </Button>
                    </div>
                </form>
                {listError ? (
                    <div role="alert">
                        <p className="text-destructive">{listError}</p>
                        <Button variant="outline" onClick={() => navigate()}>
                            Retry
                        </Button>
                    </div>
                ) : loading ? (
                    <div role="status">
                        <p className="text-sm">Loading users…</p>
                        <Skeleton className="mt-3 h-36" />
                    </div>
                ) : data.users.data.length === 0 ? (
                    <div className="space-y-3 py-8">
                        <p>No users match these filters.</p>
                        <Button
                            variant="outline"
                            onClick={() => navigate(undefined, true)}
                        >
                            Clear filters
                        </Button>
                    </div>
                ) : (
                    <>
                        <div className="hidden md:block">
                            <Table aria-label="User accounts">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name / Email</TableHead>
                                        <TableHead>Role</TableHead>
                                        <TableHead>Building scope</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.users.data.map((user) => (
                                        <TableRow key={user.id}>
                                            <TableCell className="max-w-64 break-words whitespace-normal">
                                                <p>{user.name}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {user.email}
                                                </p>
                                            </TableCell>
                                            <TableCell>
                                                {user.roleLabel}
                                            </TableCell>
                                            <TableCell>
                                                {user.role ===
                                                'facilities_personnel'
                                                    ? user.buildingNames.join(
                                                          ', ',
                                                      ) || 'None assigned'
                                                    : 'All buildings'}
                                            </TableCell>
                                            <TableCell>
                                                <Status user={user} />
                                            </TableCell>
                                            <TableCell>
                                                {actions(user)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <div className="space-y-3 md:hidden">
                            {data.users.data.map((user) => (
                                <article
                                    key={user.id}
                                    className="space-y-3 rounded-[9px] bg-secondary p-3 text-sm"
                                >
                                    <p className="font-semibold text-primary">
                                        {user.name}
                                    </p>
                                    <p className="break-words">{user.email}</p>
                                    <p>
                                        {user.roleLabel} ·{' '}
                                        {user.role === 'facilities_personnel'
                                            ? user.buildingNames.join(', ') ||
                                              'None assigned'
                                            : 'All buildings'}
                                    </p>
                                    <Status user={user} />
                                    {actions(user)}
                                </article>
                            ))}
                        </div>
                    </>
                )}
                <nav
                    className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground"
                    aria-label="User account pages"
                >
                    <p>
                        Showing {data.users.from ?? 0}–{data.users.to ?? 0} of{' '}
                        {data.users.total} users · Page{' '}
                        {data.users.current_page} of {data.users.last_page}
                    </p>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={loading || !data.users.prev_page_url}
                            onClick={() =>
                                navigate(data.users.current_page - 1)
                            }
                        >
                            Previous
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={loading || !data.users.next_page_url}
                            onClick={() =>
                                navigate(data.users.current_page + 1)
                            }
                        >
                            Next
                        </Button>
                    </div>
                </nav>
            </section>
            {(modal?.kind === 'create' || modal?.kind === 'edit') && (
                <AccountForm
                    key={`${modal.kind}-${modal.user?.id ?? 'new'}`}
                    data={data}
                    user={modal.user}
                    mutate={mutate}
                    done={complete}
                    close={() => {
                        setModal(null);
                        router.reload({ only: ['users', 'counts', 'auth'] });
                    }}
                    restore={restore}
                />
            )}
            {modal?.kind === 'view' && modal.user && (
                <AccountDetails
                    user={modal.user}
                    data={data}
                    close={() => setModal(null)}
                    edit={(user) => setModal({ kind: 'edit', user })}
                    change={(kind, user) => setModal({ kind, user })}
                    restore={restore}
                />
            )}
            {(modal?.kind === 'status' || modal?.kind === 'password') &&
                modal.user && (
                    <AccountConfirmation
                        user={modal.user}
                        kind={modal.kind}
                        mutate={mutate}
                        done={complete}
                        close={() => setModal(null)}
                        restore={restore}
                    />
                )}
            <Dialog
                open={confirmOpen}
                onOpenChange={(value) => {
                    if (!value && !confirmBusy) {
                        setConfirmOpen(false);
                        setPassword('');
                        pending.current?.resolve(null);
                        pending.current = null;
                    }
                }}
            >
                <DialogContent className="bg-card">
                    <DialogHeader>
                        <DialogTitle>Confirm Admin password</DialogTitle>
                        <DialogDescription>
                            Recent password confirmation is required for account
                            changes. Your form entries are retained.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={async (event) => {
                            event.preventDefault();
                            setConfirmBusy(true);
                            setConfirmError('');
                            try {
                                await jsonRequest(
                                    confirmPassword.url(),
                                    'POST',
                                    { password },
                                );
                                setPassword('');
                                const work = pending.current;
                                if (work) {
                                    try {
                                        work.resolve(
                                            await jsonRequest<MutationResult>(
                                                work.spec.url,
                                                work.spec.method,
                                                work.spec.body,
                                            ),
                                        );
                                    } catch (error) {
                                        work.reject(error);
                                    }
                                    pending.current = null;
                                }
                                setConfirmOpen(false);
                            } catch {
                                setConfirmError(
                                    'Password confirmation failed. Check your password and access, then retry.',
                                );
                            } finally {
                                setConfirmBusy(false);
                            }
                        }}
                    >
                        <Label htmlFor="admin-confirm-password">
                            Current Admin password
                        </Label>
                        <PasswordInput
                            id="admin-confirm-password"
                            value={password}
                            onChange={(event) =>
                                setPassword(event.target.value)
                            }
                            autoComplete="current-password"
                            autoFocus
                            required
                        />
                        <p
                            role={confirmError ? 'alert' : undefined}
                            className="text-sm text-destructive"
                        >
                            {confirmError}
                        </p>
                        <Button disabled={confirmBusy}>
                            {confirmBusy && <Spinner />}Confirm and continue
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
            <Dialog
                open={!!credential}
                onOpenChange={(value) => {
                    if (!value) {
                        setCredential(null);
                        setReveal(false);
                    }
                }}
            >
                <DialogContent
                    className="bg-card"
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        restore();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            Temporary password · shown once
                        </DialogTitle>
                        <DialogDescription>
                            {credential?.user.email}. Share only through an
                            approved private channel. The password is not saved
                            in session flash or audit logs.
                        </DialogDescription>
                    </DialogHeader>
                    <p className="text-sm">
                        Expires in {data.temporaryPasswordMinutes} minutes. The
                        user must replace it before module access; expired
                        credentials need Admin renewal.
                    </p>
                    <Label htmlFor="issued-password">Temporary password</Label>
                    <div className="flex gap-2">
                        <Input
                            id="issued-password"
                            readOnly
                            autoComplete="off"
                            type={reveal ? 'text' : 'password'}
                            value={credential?.temporaryPassword ?? ''}
                        />
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label={
                                reveal
                                    ? 'Hide temporary password'
                                    : 'Show temporary password'
                            }
                            onClick={() => setReveal((value) => !value)}
                        >
                            {reveal ? <EyeOff /> : <Eye />}
                        </Button>
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Copy temporary password"
                            onClick={() => {
                                navigator.clipboard
                                    .writeText(
                                        credential?.temporaryPassword ?? '',
                                    )
                                    .then(() =>
                                        toast.success(
                                            'Copied. Handle the clipboard securely.',
                                        ),
                                    )
                                    .catch(() =>
                                        toast.error(
                                            'Copy unavailable. Reveal and copy the password manually.',
                                        ),
                                    );
                            }}
                        >
                            <Copy />
                        </Button>
                    </div>
                    <Button
                        onClick={() => {
                            setCredential(null);
                            setReveal(false);
                        }}
                    >
                        Close and clear password
                    </Button>
                </DialogContent>
            </Dialog>
        </>
    );
}
