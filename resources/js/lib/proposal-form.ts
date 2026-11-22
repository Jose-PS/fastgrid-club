export const MIN_CARS = 1;

export function toggleCar(selected: number[], carId: number): number[] {
    if (selected.includes(carId)) {
        return selected.filter((id) => id !== carId);
    }

    return [...selected, carId];
}

export function hasValidCarCount(selected: number[]): boolean {
    return selected.length >= MIN_CARS;
}

export function votePercentage(votes: number, maxVotes: number): number {
    if (maxVotes <= 0) {
        return 0;
    }

    return Math.round((votes / maxVotes) * 100);
}
