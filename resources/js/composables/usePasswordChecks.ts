import type { Ref } from 'vue';
import { computed } from 'vue';

export interface PasswordRequirement {
    key: string;
    label: string;
}

/**
 * Los requisitos de contraseña (PasswordValidationRules::passwordRequirements)
 * marcados en vivo mientras se escribe. "uncompromised" no se puede revisar
 * en el navegador (lo hace el servidor contra filtraciones): queda en null.
 */
export function usePasswordChecks(
    password: Ref<string>,
    requirements: Ref<PasswordRequirement[]> | PasswordRequirement[],
) {
    const list = computed(() =>
        Array.isArray(requirements) ? requirements : requirements.value,
    );

    const checks = computed(() => {
        const value = password.value;

        return list.value.map((requirement) => {
            const met = ((): boolean | null => {
                switch (requirement.key) {
                    case 'length8':
                        return value.length >= 8;
                    case 'length12':
                        return value.length >= 12;
                    case 'mixedCase':
                        return (
                            /[a-záéíóúñ]/.test(value) &&
                            /[A-ZÁÉÍÓÚÑ]/.test(value)
                        );
                    case 'numbers':
                        return /\d/.test(value);
                    case 'symbols':
                        return /[^\p{L}\d\s]/u.test(value);
                    default:
                        return null;
                }
            })();

            return { ...requirement, met };
        });
    });

    /** Lo que el navegador sí puede comprobar ya se cumple. */
    const allMet = computed(() =>
        checks.value.every((check) => check.met !== false),
    );

    return { checks, allMet };
}
