import type { FileObject } from '@/api/server/files/queries';

export const PROTECTED_FILE_TOOLTIP = 'This file is protected and cannot be modified, moved, deleted or downloaded.';

export const PROTECTED_FILE_UPLOAD_ERROR = 'The following files are protected and were not uploaded: ';

/** Names of the protected entries in a listing. */
export const protectedFileNames = (files: readonly FileObject[]): ReadonlySet<string> =>
    new Set(files.filter((file) => file.attributes.is_protected).map((file) => file.attributes.name));

export type ProtectedUploadPartition = { skipped: File[]; uploadable: File[] };

/** Splits uploads into the protected names that must be skipped and the files that may upload. */
export const partitionProtected = (
    files: readonly File[],
    protectedNames: ReadonlySet<string>
): ProtectedUploadPartition => {
    const skipped = files.filter((file) => protectedNames.has(file.name));
    const uploadable = files.filter((file) => !protectedNames.has(file.name));

    return { skipped, uploadable };
};
