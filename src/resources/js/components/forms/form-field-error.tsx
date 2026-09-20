type FormFieldErrorProps = {
    id: string;
    message?: string;
};

export function FormFieldError({ id, message }: FormFieldErrorProps) {
    if (!message) return null;

    return (
        <p id={id} className="text-sm text-[var(--text-danger)]" role="alert">
            {message}
        </p>
    );
}
