export type User = {
    id: number;
    name: string;
    is_admin: boolean;
};

export type Auth = {
    user: User | null;
};
