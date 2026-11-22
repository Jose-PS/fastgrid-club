import { sectionClass, sectionTitleClass } from '@/components/account-styles';
import { Input } from '@/components/ui/input';
import { useT } from '@/hooks/use-t';
import type { OverrideForm } from '@/lib/server-override-form';
import type { OverrideTabContext } from './context';
import { Check, Choice, Field, NumberField, PasswordField } from './fields';

export function ServerTab({ ctx }: { ctx: OverrideTabContext }) {
    const t = useT();
    const { data, errors, options, label, set, maxPlayers } = ctx;
    const enums = options.enums;

    return (
        <section aria-labelledby="override-server" className={sectionClass}>
            <h2 id="override-server" className={sectionTitleClass}>
                {t('admin.override.sectionServer')}
            </h2>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label={t('admin.override.serverName')}
                    htmlFor="override-name"
                    error={errors.server_name}
                >
                    <Input
                        id="override-name"
                        maxLength={120}
                        value={data.server_name}
                        aria-invalid={errors.server_name ? true : undefined}
                        onChange={(event) =>
                            set({ server_name: event.target.value })
                        }
                    />
                </Field>
                <NumberField
                    id="override-players"
                    label={t('admin.override.maxPlayers')}
                    min={1}
                    max={maxPlayers}
                    step={1}
                    value={data.max_players}
                    hint={t('admin.override.maxPlayersHint', {
                        max: maxPlayers,
                    })}
                    error={errors.max_players}
                    onChange={(max_players) => set({ max_players })}
                />
                <Choice
                    id="override-type"
                    label={t('admin.override.serverType')}
                    value={data.server_type}
                    values={enums.server_type}
                    labelFor={(value) =>
                        label('admin.override.serverType_', value)
                    }
                    error={errors.server_type}
                    onChange={(value) =>
                        set({
                            server_type: value as OverrideForm['server_type'],
                        })
                    }
                />
                <Choice
                    id="override-tuning"
                    label={t('admin.override.tuning')}
                    value={data.tuning_type}
                    values={enums.tuning_type}
                    labelFor={(value) => label('admin.override.tuning_', value)}
                    error={errors.tuning_type}
                    onChange={(value) =>
                        set({
                            tuning_type: value as OverrideForm['tuning_type'],
                        })
                    }
                />
            </div>
            <Check
                id="override-cycle"
                label={t('admin.override.cycle')}
                checked={data.cycle_enabled}
                onChange={(cycle_enabled) => set({ cycle_enabled })}
            />
            <div className="grid gap-4 lg:grid-cols-3">
                <PasswordField
                    id="override-driver-password"
                    label={t('admin.override.driverPassword')}
                    value={data.driver_password}
                    cleared={data.clear_driver_password}
                    hint={t('admin.override.privateWarning')}
                    error={errors.driver_password}
                    onChange={(driver_password) => set({ driver_password })}
                    onClear={(clear_driver_password) =>
                        set({ clear_driver_password })
                    }
                />
                <PasswordField
                    id="override-admin-password"
                    label={t('admin.override.adminPassword')}
                    value={data.admin_password}
                    cleared={data.clear_admin_password}
                    error={errors.admin_password}
                    onChange={(admin_password) => set({ admin_password })}
                    onClear={(clear_admin_password) =>
                        set({ clear_admin_password })
                    }
                />
                <PasswordField
                    id="override-spectator-password"
                    label={t('admin.override.spectatorPassword')}
                    value={data.spectator_password}
                    cleared={data.clear_spectator_password}
                    error={errors.spectator_password}
                    onChange={(spectator_password) =>
                        set({ spectator_password })
                    }
                    onClear={(clear_spectator_password) =>
                        set({ clear_spectator_password })
                    }
                />
            </div>
        </section>
    );
}
