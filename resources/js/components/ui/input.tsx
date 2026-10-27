import type { InputHTMLAttributes } from 'react';

type InputProps = InputHTMLAttributes<HTMLInputElement>;

export function Input({ className = '', ...props }: InputProps) {
    return <input data-slot="input" className={`ui-input ${className}`.trim()} {...props} />;
}
