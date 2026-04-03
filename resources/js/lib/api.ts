export class ApiError extends Error {
    status: number;
    errors: Record<string, string[]> | null;

    constructor(message: string, status: number, errors: Record<string, string[]> | null = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
    }
}

type JsonValue = string | number | boolean | null | JsonValue[] | { [key: string]: JsonValue };

type ApiRequestOptions = Omit<RequestInit, 'body'> & {
    body?: JsonValue | FormData;
};

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const firstErrorMessage = (errors: Record<string, string[]> | null | undefined) => {
    if (!errors) {
        return null;
    }

    for (const key of Object.keys(errors)) {
        const value = errors[key];

        if (Array.isArray(value) && value[0]) {
            return value[0];
        }
    }

    return null;
};

export async function apiRequest<T>(url: string, options: ApiRequestOptions = {}): Promise<T> {
    const headers = new Headers(options.headers ?? {});
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const token = csrfToken();
    if (token) {
        headers.set('X-CSRF-TOKEN', token);
    }

    let body: BodyInit | undefined;
    if (options.body instanceof FormData) {
        body = options.body;
    } else if (options.body !== undefined) {
        headers.set('Content-Type', 'application/json');
        body = JSON.stringify(options.body);
    }

    const response = await fetch(url, {
        ...options,
        body,
        headers,
        credentials: 'same-origin',
    });

    const contentType = response.headers.get('content-type') ?? '';
    const payload = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : await response.text().catch(() => null);

    if (!response.ok) {
        const errors = typeof payload === 'object' && payload !== null && 'errors' in payload
            ? (payload.errors as Record<string, string[]>)
            : null;

        const message = typeof payload === 'object' && payload !== null && 'message' in payload
            ? String(payload.message)
            : firstErrorMessage(errors)
                ?? 'Terjadi kesalahan saat memproses permintaan.';

        throw new ApiError(message, response.status, errors);
    }

    return payload as T;
}
