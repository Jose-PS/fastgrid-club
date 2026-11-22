import { describe, expect, it, vi } from 'vitest';
import { copyText } from './clipboard';

describe('copyText', () => {
    it('writes the text and reports success', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);

        await expect(copyText('FastGrid | Race', { writeText })).resolves.toBe(
            true,
        );
        expect(writeText).toHaveBeenCalledWith('FastGrid | Race');
    });

    it('reports failure when the clipboard is unavailable', async () => {
        await expect(copyText('name', undefined)).resolves.toBe(false);
    });

    it('reports failure when the clipboard rejects the write', async () => {
        const writeText = vi.fn().mockRejectedValue(new Error('denied'));

        await expect(copyText('name', { writeText })).resolves.toBe(false);
    });
});
