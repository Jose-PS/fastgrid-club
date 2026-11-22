import { CarLabel } from '@/components/car-label';
import { useT } from '@/hooks/use-t';

export type WeekConfiguration = {
    track: string;
    lengthM: number | null;
    cars: string[];
};

export function WeekConfiguration({
    configuration,
}: {
    configuration: WeekConfiguration | null;
}) {
    const t = useT();

    return (
        <div className="grid gap-5">
            <div className="min-w-0 rounded-md border border-border bg-surface p-4.5">
                <h3 className="mb-4 text-xs font-semibold tracking-[0.07em] text-muted uppercase">
                    {t('week.config')}
                </h3>
                {configuration ? (
                    <dl className="m-0 grid gap-4">
                        <div>
                            <dt className="mb-1 text-xs font-semibold tracking-[0.07em] text-muted uppercase">
                                {t('week.circuit')}
                            </dt>
                            <dd className="m-0 font-display text-[28px] leading-[1.15] font-bold tracking-[-0.02em]">
                                {configuration.track}
                            </dd>
                        </div>
                        <div>
                            <dt className="mb-1 text-xs font-semibold tracking-[0.07em] text-muted uppercase">
                                {t('week.cars')}
                            </dt>
                            <dd className="m-0 flex flex-wrap gap-1.5 font-medium">
                                {configuration.cars.map((car) => (
                                    <CarLabel
                                        key={car}
                                        name={car}
                                        className="rounded-sm border border-border-strong px-2 py-0.5 text-sm"
                                    />
                                ))}
                            </dd>
                        </div>
                    </dl>
                ) : (
                    <p className="font-display text-xl font-bold">
                        {t('cal.tba')}
                    </p>
                )}
            </div>
        </div>
    );
}
