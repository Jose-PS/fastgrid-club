import {
    fieldErrorClass,
    sectionClass,
    sectionTitleClass,
} from '@/components/account-styles';
import { CarCombobox } from '@/components/proposals/car-combobox';
import { useT } from '@/hooks/use-t';
import type { OverrideTabContext } from './context';

export function CarsTab({ ctx }: { ctx: OverrideTabContext }) {
    const t = useT();
    const { data, errors, options, set } = ctx;

    return (
        <section aria-labelledby="override-cars" className={sectionClass}>
            <div className="flex flex-wrap items-baseline gap-x-3">
                <h2 id="override-cars" className={sectionTitleClass}>
                    {t('admin.override.cars')}
                </h2>
                <span className="mono text-xs text-muted">
                    {data.car_ids.length}
                </span>
            </div>
            <CarCombobox
                id="override-car-select"
                labelId="override-cars"
                cars={options.cars}
                selectedIds={data.car_ids}
                invalid={Boolean(errors.car_ids)}
                onChange={(car_ids) => set({ car_ids })}
            />
            {errors.car_ids ? (
                <p role="alert" className={fieldErrorClass}>
                    {errors.car_ids}
                </p>
            ) : null}
        </section>
    );
}
