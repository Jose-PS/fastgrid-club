import {
    alertClass,
    sectionClass,
    sectionTitleClass,
} from '@/components/account-styles';
import { TrackCombobox } from '@/components/proposals/track-combobox';
import { useT } from '@/hooks/use-t';
import type { OverrideForm, OverrideMode } from '@/lib/server-override-form';
import type { OverrideTabContext } from './context';
import { Choice, Field } from './fields';

export function EventTab({ ctx }: { ctx: OverrideTabContext }) {
    const t = useT();
    const { data, errors, options, label, set, practiceMissing } = ctx;
    const enums = options.enums;

    return (
        <section aria-labelledby="override-event" className={sectionClass}>
            <h2 id="override-event" className={sectionTitleClass}>
                {t('admin.override.sectionEvent')}
            </h2>
            <div className="grid gap-4 sm:grid-cols-2">
                <Choice
                    id="override-mode"
                    label={t('admin.override.mode')}
                    value={data.mode}
                    values={enums.mode}
                    labelFor={(value) => label('admin.override.mode_', value)}
                    error={errors.mode}
                    onChange={(value) => set({ mode: value as OverrideMode })}
                />
                <Field
                    label={t('admin.override.track')}
                    htmlFor="override-track"
                    error={errors.track_id}
                >
                    <TrackCombobox
                        id="override-track"
                        tracks={options.tracks}
                        value={data.track_id}
                        invalid={Boolean(errors.track_id)}
                        onChange={(track_id) => set({ track_id })}
                    />
                </Field>
                <Choice
                    id="override-weather"
                    label={t('admin.override.weather')}
                    value={data.weather}
                    values={enums.weather}
                    labelFor={(value) =>
                        label('admin.schedule.weather_', value)
                    }
                    error={errors.weather}
                    onChange={(weather) => set({ weather })}
                />
                <Choice
                    id="override-behaviour"
                    label={t('admin.override.weatherBehaviour')}
                    value={data.weather_behaviour}
                    values={enums.weather_behaviour}
                    labelFor={(value) =>
                        label('admin.override.behaviour_', value)
                    }
                    error={errors.weather_behaviour}
                    onChange={(value) =>
                        set({
                            weather_behaviour:
                                value as OverrideForm['weather_behaviour'],
                        })
                    }
                />
                <Choice
                    id="override-grip"
                    label={t('admin.override.grip')}
                    value={data.initial_grip}
                    values={enums.initial_grip}
                    labelFor={(value) => label('admin.override.grip_', value)}
                    error={errors.initial_grip}
                    onChange={(value) =>
                        set({
                            initial_grip: value as OverrideForm['initial_grip'],
                        })
                    }
                />
            </div>
            {practiceMissing ? (
                <p role="alert" className={alertClass}>
                    {t('admin.override.errTrackPractice')}
                </p>
            ) : null}
        </section>
    );
}
