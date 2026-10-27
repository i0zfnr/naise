import type { ButtonHTMLAttributes } from 'react';

type ButtonVariant = 'primary' | 'secondary' | 'outline' | 'ghost';

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: ButtonVariant;
};

export function Button({ className = '', variant = 'primary', type = 'button', ...props }: ButtonProps) {
    return (
        <button
            data-slot="button"
            type={type}
            className={`ui-button ui-button--${variant} ${className}`.trim()}
            {...props}
        />
    );
}
