import { Form, Head } from '@inertiajs/react';
import TemporaryPasswordController from '@/actions/App/Http/Controllers/TemporaryPasswordController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function PasswordSetup() {
    return (
        <>
            <Head title="Replace temporary password" />
            <p className="mb-5 text-sm text-muted-foreground">
                Use at least 12 characters with uppercase and lowercase letters,
                a number and a symbol. Application access is locked until this
                step is completed.
            </p>
            <Form
                {...TemporaryPasswordController.update.form()}
                resetOnSuccess={[
                    'current_password',
                    'password',
                    'password_confirmation',
                ]}
            >
                {({ processing, errors }) => (
                    <div className="space-y-5">
                        {(
                            [
                                'current_password',
                                'password',
                                'password_confirmation',
                            ] as const
                        ).map((field, index) => (
                            <div className="grid gap-2" key={field}>
                                <Label htmlFor={field}>
                                    {
                                        [
                                            'Temporary password',
                                            'New password',
                                            'Confirm new password',
                                        ][index]
                                    }
                                </Label>
                                <PasswordInput
                                    id={field}
                                    name={field}
                                    autoComplete={
                                        index === 0
                                            ? 'current-password'
                                            : 'new-password'
                                    }
                                    autoFocus={index === 0}
                                    required
                                    aria-invalid={!!errors[field]}
                                    aria-describedby={
                                        errors[field]
                                            ? `${field}-error`
                                            : undefined
                                    }
                                />
                                <div
                                    id={`${field}-error`}
                                    role={errors[field] ? 'alert' : undefined}
                                >
                                    <InputError message={errors[field]} />
                                </div>
                            </div>
                        ))}
                        <Button className="w-full" disabled={processing}>
                            {processing && <Spinner />}Replace password and sign
                            in again
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

PasswordSetup.layout = {
    title: 'Replace temporary password',
    description: 'Set a private password before using QC Energy DSS.',
};
